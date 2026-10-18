"""Decode synthetic API responses using the app's actual Decodable models."""
from pathlib import Path
import re
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
models = ['import Foundation\n']
for path in (root / 'ios/Sources').glob('*.swift'):
    source = path.read_text()
    for match in re.finditer(r'\bstruct\s+\w+\s*:\s*[^\n{]*\bDecodable\b[^\n{]*\{', source):
        depth = 1
        end = match.end()
        while depth:
            if source[end] == '{': depth += 1
            elif source[end] == '}': depth -= 1
            end += 1
        models.append(source[match.start():end])
models.append(r'''
@main struct ModelTests {
    static func main() throws {
        let fixtures = try JSONSerialization.jsonObject(with: Data(contentsOf: URL(fileURLWithPath: CommandLine.arguments[1]))) as! [String: Any]
        let decoder = JSONDecoder(); decoder.keyDecodingStrategy = .convertFromSnakeCase
        func verify<T: Decodable>(_ type: T.Type, _ name: String) throws {
            let data = try JSONSerialization.data(withJSONObject: fixtures[name]!)
            _ = try decoder.decode(type, from: data)
            print("PASS: iOS decodes populated " + name + " response")
        }
        // A captured response whose decoded values must also be right: the tolerant
        // decoders turn a renamed or retyped key into a default instead of an error.
        func values<T: Decodable>(_ type: T.Type, _ name: String, _ label: String, _ ok: (T) -> Bool) throws {
            let data = try JSONSerialization.data(withJSONObject: fixtures[name]!)
            let value = try decoder.decode(type, from: data)
            guard ok(value) else { fatalError("Decoded values are wrong: " + label) }
            print("PASS: iOS decodes " + label)
        }
        // Replies to writes, shaped as public/api.php sends them, and a device
        // request with every date filled in. These need no database.
        func literal<T: Decodable>(_ type: T.Type, _ json: String, _ label: String, _ ok: (T) -> Bool) throws {
            let value = try decoder.decode(type, from: Data(json.utf8))
            guard ok(value) else { fatalError("Decoded values are wrong: " + label) }
            print("PASS: iOS decodes " + label)
        }
        // The 4.25.0 screens, from tests/v425_cases.php (a run of its own): the decoded values must be right.
        if fixtures["client425"] != nil {
            try values(ClientDetail.self, "client425", "a client with custom fields to edit, offices and device counts") {
                !$0.extras.customFieldsEdit.isEmpty && $0.extras.customFieldsEdit.contains { $0.type == "choice" && !$0.options.isEmpty }
            }
            try values(ThreadResponse.self, "message_thread425", "a message carrying a file") { $0.messages.contains { $0.documentId != nil && $0.documentTitle != nil } }
            try values(DocumentsResponse.self, "documents425", "documents shared with the client and the counselor") { $0.documents.contains { $0.portalShared && $0.counselorShared } }
            try values(SignaturesResponse.self, "signatures425", "documents to sign, one signed in person with a drawn signature") {
                $0.signatures.contains { $0.status == "Signed" && $0.method == "In person" && $0.hasDrawnSignature } && $0.kinds["services"] != nil
            }
            try values(SigningDocumentResponse.self, "signature425", "a document to sign with its words") { !$0.signature.body.isEmpty && !$0.signature.title.isEmpty }
            try verify(ReleaseChoices.self, "release_choices425")
            try verify(ReferralDeadlines.self, "referral_deadlines425")
            try values(NewClientOptions.self, "form_options425", "the new-client form's custom fields") { !$0.customFields.isEmpty }
            return
        }
        try literal(PostMessageResponse.self, #"{"ok":true,"id":12,"delivered":false,"warning":"Message saved, but this client has no active portal link, so they cannot read it yet."}"#,
                    "a message reply with a delivery warning") { $0.delivered == false && $0.warning != nil }
        try literal(PostMessageResponse.self, #"{"ok":true,"id":13,"delivered":1,"warning":null}"#,
                    "a delivered message reply (numeric flag, no warning)") { $0.delivered == true && $0.warning == nil }
        try literal(ReservationResult.self, #"{"ok":true,"status":"Waitlist"}"#, "a reservation reply") { $0.status == "Waitlist" }
        try literal(ReservationResult.self, #"{"ok":true,"status":7}"#, "a reservation reply with an unexpected status") { $0.status == nil }
        try literal(CompetencySaveResponse.self, #"{"ok":true,"saved":3}"#, "a competency save reply") { $0.ok && $0.saved == 3 }
        try literal(DeviceRequestResponse.self, #"""
            {"ok":true,"request":{"id":4,"client_id":2,"requested_by":1,"device_name":"Synthetic braille display","category":"Braille Display",
             "justification":"Synthetic","status":"Delivered","funding_source":"State VR","auth_number":"AUTH-4","authorized_amount":1250.5,
             "authorized_at":"2026-02-01","selected_quote_id":9,"po_number":"PO-77","ordered_at":"2026-02-03","delivered_at":"2026-02-10",
             "trained_at":null,"warranty_until":"2028-02-10","serial":"SN-1","notes":null,"created_at":"2026-01-20 10:00:00",
             "updated_at":"2026-02-10 09:00:00","client_name":"Synthetic client"},
             "quotes":[{"id":9,"vendor":"Synthetic vendor","amount":1250.5,"quote_ref":"Q-1","notes":null}]}
            """#, "a device request with authorization, order and warranty dates") {
            $0.request.authorizedAt == "2026-02-01" && $0.request.orderedAt == "2026-02-03" && $0.request.warrantyUntil == "2028-02-10" && $0.quotes.count == 1
        }
        try verify(ContractsResponse.self, "contracts")
        try verify(ContractResponse.self, "contract")
        try verify(BookingsResponse.self, "bookings")
        try verify(DeviceRowsResponse.self, "device_requests")
        try verify(DeviceRequestResponse.self, "device_request")
        try verify(EquipmentDetailResponse.self, "equipment_detail")
        try verify(MessageThreadsResponse.self, "message_threads")
        try verify(ScoredListResponse.self, "scored_assessments")
        try verify(ScoredDetailResponse.self, "scored_assessment")
        try verify(InstrumentItemsResponse.self, "instrument_items")
        try verify(ClientDetail.self, "client")
        // The core screens: client list, track picker, schedule and the signed-in profile.
        try verify(ClientsResponse.self, "clients")
        try verify(TracksResponse.self, "tracks")
        try verify(ScheduleResponse.self, "schedule")
        try verify(MeResponse.self, "me")
        // The home screen (its own tolerant decoder) and the other list screens.
        try verify(DashboardData.self, "dashboard")
        try values(FollowupsResponse.self, "followups", "a device follow-up that is due, with its date, interval and device") {
            $0.due.contains { !$0.dueDate.isEmpty && $0.intervalDays > 0 && !$0.equipmentName.isEmpty && !$0.clientName.isEmpty }
        }
        try values(IncidentsResponse.self, "incidents", "an incident the signed-in administrator can edit, with its date and severity") {
            $0.incidents.contains { $0.canEdit && !$0.incidentDate.isEmpty && !$0.severity.isEmpty }
        }
        try verify(UtilizationResponse.self, "utilization")
        try verify(ComplianceResponse.self, "compliance")
        try values(CounselorsResponse.self, "counselors", "a confirmed counselor whose portal link is active") {
            $0.counselors.contains { $0.portalActive && !$0.portalExpired && $0.clients >= 1 }
        }
        try verify(WorksiteRowsResponse.self, "worksite_assessments")
        try verify(TrackExpensesResponse.self, "expenses")
        if fixtures["at_list"] != nil {
            try verify(ATCatalogResponse.self, "at_catalog")
            try verify(ATListResponse.self, "at_list")
            try verify(ATDetailResponse.self, "at_detail")
            try verify(ATMatchesResponse.self, "at_matches")
            try verify(ATProfitResponse.self, "at_profitability")
            try verify(ATIssueChoices.self, "at_issue_choices")
            try verify(HomeworkResponse.self, "homework")
        }
        // The Action center and the AI case briefing (tests/workflow_cases.php).
        if fixtures["workflow_actions"] != nil {
            try values(WorkflowActionResponse.self, "workflow_actions", "the action center's items") {
                !$0.items.isEmpty && $0.items.allSatisfy { !$0.title.isEmpty && !$0.url.isEmpty && !$0.kind.isEmpty }
            }
        }
        if fixtures["workflow_briefing"] != nil {
            try values(BriefResponse.self, "workflow_briefing", "an AI case briefing whose every item cites a linked source") {
                !$0.items.isEmpty && $0.items.allSatisfy { !$0.sources.isEmpty && $0.sources.values.allSatisfy { !$0.url.isEmpty } }
            }
        }
    }
}
''')
with tempfile.TemporaryDirectory(prefix='cms-model-tests-') as tmp:
    path = Path(tmp)
    (path / 'models.swift').write_text('\n'.join(models))
    (path / 'fixtures.json').write_text(sys.stdin.read())
    subprocess.run(['swiftc','-module-cache-path',str(path / 'modules'),'-parse-as-library',str(path / 'models.swift'),'-o',str(path / 'tests')],check=True)
    subprocess.run([str(path / 'tests'),str(path / 'fixtures.json')],check=True)
