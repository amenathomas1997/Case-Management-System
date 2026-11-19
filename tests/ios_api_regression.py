"""Run the production API queue logic on macOS with isolated storage/network adapters."""
from pathlib import Path
import re
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]

# Static checks on public/api.php for rules the app relies on (no database needed).
api_php = (root / 'public/api.php').read_text()


def api_case(name):
    """Source of one `case 'name':` block of api.php's action switch."""
    start = api_php.index("\n    case '%s':" % name)
    following = re.search(r"\n    (?:case '|default:)", api_php[start + 1:])
    return api_php[start:start + 1 + following.start()] if following else api_php[start:]


def static_check(ok, label):
    if not ok:
        raise SystemExit('FAIL: ' + label)
    print('PASS: ' + label)


no_rate = re.compile(r"hourly_rate.*?<= 0\)\s*\{?\s*fail\('This track has no hourly rate\.[^']*', 422\)", re.S)
choices = api_case('client_choices')
static_check(re.search(r"\$choicesRefOnly = !can\('clients'\) && !can\('schedule'\) && !can\('equipment'\) && !can\('procurement'\)", choices)
             and re.search(r"if \(\$choicesRefOnly\) \{.*?r\.status = 'Converted'", choices, re.S),
             'client_choices gives a referrals-only account only clients converted from referrals')
static_check(re.search(r"if \(\$cid <= 0\) fail\([^;]*, 422\);\s*api_require_client\(\$cid, \$userId\)", api_case('ai_generate_note')),
             'ai_generate_note refuses a missing client_id (needed to redact that client) with a 422')
static_check(no_rate.search(api_case('log_hours')), 'log_hours refuses a track with no hourly rate with a 422')
static_check(no_rate.search(api_case('complete_session')), 'complete_session refuses a track with no hourly rate with a 422')
too_big = re.search(r"\(int\)\(\$_SERVER\['CONTENT_LENGTH'\] \?\? 0\) > \$postMax\) \{\s*fail\([^;]*, 413\)", api_php)
static_check(too_big and too_big.start() < api_php.index("file_get_contents('php://input')"),
             'api.php answers 413 when the request is larger than post_max_size, before reading it')
draft = api_case('ai_draft')
static_check("'truncated' => !empty($res['truncated'])" in draft and not re.search(r",\s*900\s*,", draft),
             'ai_draft reports a truncated draft and sizes its output budget instead of a fixed 900 tokens')
log_hours = api_case('log_hours')
static_check('$sigPath ? ($signedNameIn' not in log_hours,
             'log_hours keeps a typed signed name even when no signature is drawn')
static_check(re.search(r"fail\('RSA category this visit: .*?is not offered on this track\..*?, 422\)", log_hours, re.S),
             'log_hours refuses an RSA category the track does not offer with a 422, naming the field')
static_check("fail('Not found.', 404)" not in api_php,
             'api.php never answers a bare "Not found." 404 (the message names what was not found)')
static_check('### ' not in api_case('ai_kb'),
             'ai_kb sends article context without markdown headings')

# 4.30.0 accessibility rules for the iPhone app's SwiftUI (static, no simulator needed).
swift_sources = {p.name: p.read_text() for p in sorted((root / 'ios/Sources').glob('*.swift'))}


def modifier_chain(lines, i):
    """The line at i and the modifier (or comment) lines that follow it."""
    chain = [lines[i]]
    for line in lines[i + 1:i + 14]:
        stripped = line.strip()
        if stripped.startswith('.') or stripped.startswith('//'):
            chain.append(line)
        else:
            break
    return chain


missing_on_brand = []
for name, text in swift_sources.items():
    lines = text.split('\n')
    for i, line in enumerate(lines):
        if '.buttonStyle(.borderedProminent)' in line and not any('.onBrandLabel()' in c for c in modifier_chain(lines, i)):
            missing_on_brand.append('%s:%d' % (name, i + 1))
static_check(not missing_on_brand,
             'every .borderedProminent button sets .onBrandLabel() (dark text on the dark appearance\'s light-blue fill)'
             + ('' if not missing_on_brand else ': missing at ' + ', '.join(missing_on_brand)))
helpers = swift_sources['AccessibilityHelpers.swift']
adjustable = helpers[helpers.index('func voiceOverAdjustable'):helpers.index('enum VoiceOverRows')]
static_check('accessibilityElement(children: VoiceOverRows.children)' in adjustable and 'accessibilityHint' not in adjustable,
             'a stepper is one adjustable element only while VoiceOver runs (Voice Control keeps + and -), with no hint repeating "adjustable"')
static_check(not any(re.search(r'withAnimation \{ proxy\.scrollTo\((errorRow|[A-Za-z.]*[Ee]rror)', t) for t in swift_sources.values()),
             'scrolling to an error honours Reduce Motion')
static_check(not any('Swipe up or down for actions.' in t for t in swift_sources.values()),
             'no row hint repeats the "Actions available" VoiceOver already says')
static_check(not any('.pickerStyle(.segmented)' in t for n, t in swift_sources.items() if n != 'AccessibilityHelpers.swift'),
             'segmented pickers become menus at the accessibility text sizes (SegmentedUnlessLargeText)')
app_swift = swift_sources['CaseManagementApp.swift']
show_error = app_swift[app_swift.index('@MainActor func showFormError(_ message: String'):app_swift.index('/// showFormError for a message marked')]
static_check('formErrorClaimed != serial' in show_error,
             'showFormError does not announce an error a screen reads out by focusing its banner or field')
focusing = swift_sources['ClientEditSheet.swift']
static_check('claimFormErrorFocus(message)' in focusing and 'FormFieldID(name: field.lowercased())' in focusing,
             'ErrorFocusForm claims the error and moves VoiceOver to the field it is about')
for name in ('TrainingView.swift', 'AgreementsView.swift'):
    text = swift_sources[name]
    static_check('announce(error' not in text and 'announce(self.error' not in text and re.search(r'announce\([^)]*\)\s*\n?\s*dismiss\(\)', text) is None,
                 name + ' uses showFormError for errors and announceAfterDismiss for results said as it closes')

# 4.31.0: confirming a session leads straight to its case note (static, no simulator needed).
schedule_swift = swift_sources['ScheduleView.swift']
complete_sheet = schedule_swift[schedule_swift.index('struct CompleteSessionSheet: View {'):schedule_swift.index('private struct SigningStep')]
static_check('Session note' not in complete_sheet and '$note' not in complete_sheet,
             'the Complete session sheet has no session note box (the full case note opens next)')
static_check('session_note' not in schedule_swift,
             'the Schedule never sends session_note with complete_session')
static_check('noteAfterSheet = request' in schedule_swift and 'noteAfterUnlock = request' in schedule_swift
             and re.search(r'if let request = try await complete\(session\) \{.*?openNote\(request\)', schedule_swift, re.S),
             'after Complete without signature, a signed completion (once unlocked) and the no-track confirmation, the case note opens')
static_check('SessionNoteSheet(request: request)' in schedule_swift and 'Notes to write' in schedule_swift
             and 'focusedNote = t.sessionId' in schedule_swift,
             'the Schedule lists Notes to write and, when the editor is left unsaved, puts VoiceOver on that session\'s row')
clients_swift = swift_sources['ClientsView.swift']
add_note = clients_swift[clients_swift.index('struct AddNoteSheet: View {'):clients_swift.index('// MARK: - Parity helpers')]
static_check(re.search(r'var initialSubject: String\? = nil\s*\n\s*var sessionId: Int\? = nil', add_note)
             and 'if let sessionId { payload["session_id"] = sessionId }' in add_note,
             'AddNoteSheet takes initialSubject and sessionId (defaulted, so existing callers are unchanged) and sends session_id with add_note')
static_check(add_note.index('payload["session_id"]') < add_note.index('try api.enqueue("add_note", body: payload'),
             'a session\'s note kept offline is queued with its session_id')
static_check('.accessibilityFocused($focusedField, equals: 0)' in add_note and '.accessibilityFocused($focusedField, equals: 100 + i)' in add_note
             and '.accessibilitySpeechQueueAnnouncement: true' in add_note,
             'a session\'s note puts VoiceOver on the first field to write, then says where it is (queued, not cutting off the field)')
# 4.33.0: Generate structured note works from rough notes typed or dictated straight into the note.
static_check(re.search(r'private var generatorSource: String \{.*?\(\[body_\] \+ sections\)', add_note, re.S)
             and 'let source = generatorSource' in add_note and '"text": source' in add_note,
             'Generate structured note works from the raw-notes box or, when it is empty, from the note as typed')
gen_button = re.search(r'Label\(genBusy \? "Generating[^"]*" : "Generate structured note", systemImage: "wand.and.stars"\)\s*\}(\.disabled\([^)]*\))', add_note)
static_check(gen_button is not None and gen_button.group(1) == '.disabled(genBusy)',
             'the Generate structured note button is dimmed only while generating, never for an empty raw-notes box')
static_check('showFormError("Raw notes:' in add_note and add_note.index('showFormError("Raw notes:') < add_note.index('"text": source')
             and '.errorField("Raw notes")' in add_note,
             'Generate with nothing to work from says so at the Raw notes field instead of sending nothing')
session_notes = swift_sources['SessionNotes.swift']
static_check('try? await api.callCached("notes_to_write"' in session_notes and 'guard api.can("clients")' in session_notes,
             'notes_to_write is optional: an older server (Unknown action) or no clients permission hides Notes to write')
static_check('body["idem_key"] = key' in schedule_swift and 'if !IdemKey.keeps(error) { completionKeys[session.id] = nil }' in schedule_swift
             and 'completionKeys[session.id] = UUID().uuidString' in schedule_swift,
             'complete_session sends one retry key per Complete session sheet, kept after no answer or "still being processed"')
static_check(re.search(r'if case APIError\.server = error, api\.can\("clients"\),\s*let item = notesShown\.first\(where: \{ \$0\.sessionId == session\.id \}\).*?return request', schedule_swift, re.S),
             'a retry answered "already closed" opens that session\'s case note from Notes to write instead of only an error')
static_check('Button("Cancel") { dismiss() }' in session_notes and 'tracksTimeout' in session_notes and 'if tracks == nil && !Task.isCancelled { tracks = [] }' in session_notes,
             'opening a session\'s case note can be cancelled and opens without the track list after a short timeout')
dashboard_swift = swift_sources['DashboardView.swift']
static_check('notesToWrite = await loadNotesToWrite(api)' in dashboard_swift and 'NoteToWriteRow(item: item)' in dashboard_swift,
             'the Dashboard shows Notes to write with Write it now')
project_yml = (root / 'ios/project.yml').read_text()
static_check(project_yml.count('MARKETING_VERSION: "4.34.0"') == 2 and project_yml.count('CURRENT_PROJECT_VERSION: "65"') == 2,
             'the app and widget are version 4.34.0 (65)')

# 4.34.0: the iPhone app behaves as the website does (PARITY-AUDIT-2026-11-19, plan P1-P43; static).
api_swift = swift_sources['API.swift']
add_note = clients_swift[clients_swift.index('struct AddNoteSheet: View {'):clients_swift.index('// MARK: - Parity helpers')]
static_check(re.search(r'refusal: \{ reply in[^}]*reply\["locked"\]', add_note) and 'Your changes are in the addendum form below.' in add_note
             and 'becomeAddendum(to:' in add_note and 'payload["addendum_to"] = parent.id' in add_note,
             'P1: an edit refused because the note locked becomes an addendum with the typed text kept, as the website does')
static_check('func addendumSubjectFor' in clients_swift and 'subject.hasPrefix("Addendum: ") ? subject' in clients_swift,
             'P1: an addendum\'s subject is "Addendum: <subject>", never doubled')
free_text_msg = ('Free text is chosen as the note format, but the structured sections still hold text that a free-text note does not keep. '
                 'Move anything you need from the sections into the narrative box and clear them, or choose a structured format again.')
static_check(free_text_msg in clients_swift and 'sectionsLost(format: noteFormat, sections: sections)' in add_note
             and 'showFormError(freeTextSectionsMessage, in: &error, field: "Note")' in add_note,
             'P2: free text with section text is refused with the website\'s words, tied to the note, before anything is sent')
static_check('payload["sec\\(i + 1)"] = text' in add_note, 'P2: a free-text note sends sections that hold text, so the server check applies too')
edit_sheet = swift_sources['ClientEditSheet.swift']
client_save = edit_sheet[edit_sheet.index('    private func save() async {'):edit_sheet.index('    /// The form field a save_client refusal is about')]
client_body = client_save[client_save.index('var body: [String: Any] = ['):client_save.index('if existing == nil {\n                // 4.34.0: a possible duplicate')]
static_check(all('"%s": %s' % (k, v) in client_body for k, v in (('dob', 'dob'), ('ipe_date', 'ipeDate'), ('ipe_amended_on', 'ipeAmendedOn')))
             and 'if clientType == "Pre-ETS" { body["school"] = school }' in client_body
             and '(required for On Hold)' in edit_sheet
             and 'Say why the client is going On Hold (for example, waiting for the authorization).' in edit_sheet,
             'P3: the client form sends date of birth, school, IPE dates and requires a reason for On Hold, as the website')
static_check('LabeledContent("Date of birth"' in clients_swift and 'LabeledContent("Client type"' in clients_swift,
             'P3: the client screen shows date of birth and client type')
static_check('body["dup_check"] = 1' in edit_sheet and 'body["not_duplicate"] = 1' in edit_sheet
             and 'Toggle("This is a different person"' in edit_sheet and 'refusedDuplicate = (reply["duplicate"] as? Bool) == true' in edit_sheet
             and re.search(r'showFormError\(refusedFields\["name"\] \?\? error\.localizedDescription, in: &self\.error, field: "Full name"\)', client_save)
             and 'message += " " + advisory' in client_save and 'message += " " + warning' in client_save,
             'P4: a new client matching someone on file is refused until "This is a different person"; advisory and old warning said')
save_ack = clients_swift[clients_swift.index('struct SaveAck: Decodable {'):clients_swift.index('func noteSavedMessage')]
static_check(re.search(r'^\s*var warning: String\?$', save_ack, re.M) and re.search(r'^\s*var homeworkCreated: Int\?$', save_ack, re.M) and 'added to the client portal.' in clients_swift
             and 'onSaved(noteSavedMessage(result))' in add_note,
             'P5: "Note saved." says the homework added and the session warning')
static_check('body["consent_seen"] = seen' in clients_swift and 'var pendingId: Int?' in clients_swift
             and 'reply["consent_changed"]' in clients_swift
             and 'fields["guardian_name"] != nil ? "Guardian name" : (fields["guardian_email"] != nil ? "Guardian email" : nil)' in clients_swift,
             'P6: a consent request sends consent_seen, reloads after consent_changed, and ties guardian errors to their fields')
search_swift = swift_sources['SearchView.swift']
static_check('var capped: Bool?' in search_swift and '(first \\(items.count) shown, more match)' in search_swift
             and 'hit.kind == "goal", let trackId = hit.trackId' in search_swift and 'hit.kind == "invoice"' in search_swift,
             'P7: search groups say when more matched, a goal opens its track, an invoice is a plain row')
complete_sheet = schedule_swift[schedule_swift.index('struct CompleteSessionSheet: View {'):schedule_swift.index('private struct SigningStep')]
static_check('body["outcome"] = outcome.value' in schedule_swift and '"completed_logged"' in schedule_swift and '"completed_separate"' in schedule_swift
             and 'Completed — log hours as well' in complete_sheet and 'Completed, hours already logged' in complete_sheet
             and 'reply["logged_hours"]' in schedule_swift and 'signature: sig, signedName: name, entries: refusal.entries' in complete_sheet,
             'P9: completing sends the outcome and, when hours are logged that day, offers the website\'s two choices keeping the signature')
move_sheet = schedule_swift[schedule_swift.index('struct MoveSessionSheet: View {'):]
static_check('ErrorFocusForm(inNavigationStack: true, error: error)' in move_sheet and '.errorField("New start")' in move_sheet,
             'P9: the Move session sheet uses ErrorFocusForm with its date tied')
track_swift = swift_sources['TrackDetailView.swift']
static_check('body["billing_mode"] = billingMode' in track_swift and 'body["authorized_amount"]' in track_swift
             and 'rsaDefaults' in track_swift and 'r.rsaDefault(for: serviceType)' in track_swift and 'r.defaults?.billingMode' in track_swift
             and '"hourly_rate": "Hourly billing rate"' in track_swift and 'func moneyInput' in track_swift
             and 'showFormError(message, in: &self.error, field: Self.errorFields[key])' in track_swift
             and re.search(r'\.onChange\(of: serviceType\) \{ now in.*?rsaCategory = form\.rsaDefault\(for: now\)', track_swift, re.S),
             'P11: the track form offers Who pays and the dollar amount, starts from the website\'s defaults and ties the rate error')
static_check('refusedDuplicate = ($0["duplicate"] as? Bool) == true' in schedule_swift and 'refusedFields["date"]' in schedule_swift
             and 'field: "Starts"' in schedule_swift and '.errorField("Starts")' in schedule_swift,
             'P12: a session already booked is said as information; a series overlapping one is tied to the date')
static_check('if forSchedule, let bookable = row.bookable { return bookable }' in api_swift and 'var bookable: Bool?' in api_swift
             and 'clientPickerChoices(forSchedule: true)' in schedule_swift,
             'P13: the booking picker offers only clients the server marks bookable (no Waitlist), others unchanged')
static_check(re.search(r'if started \{\s*Button \{\s*pendingOutcome = SessionOutcome\(session: session, to: "No Show"\)', schedule_swift)
             and re.search(r'if started \{\s*Button\("No-show"\)', schedule_swift),
             'P15: Record a no-show is offered only once the session has started')
log_hours_swift = swift_sources['LogHoursView.swift']
static_check('step: 0.25' not in log_hours_swift and 'Stepper(' not in log_hours_swift
             and 'Hours (0 with miles for travel only' in log_hours_swift
             and 'Enter the hours worked, or write a session note to save the note on its own.' in log_hours_swift
             and 'result.reminder' in log_hours_swift,
             'P16: hours are typed with two decimals (0 with miles for travel, blank for a note only) and the homework reminder is said')
static_check('Recorded. Because the device is no longer in use, a case note and a follow-up task were created.' in swift_sources['FollowupsView.swift'],
             'P18: a "No" follow-up says what it created')
static_check('The task needs a title: say what needs doing.' in swift_sources['TasksView.swift'],
             'P20: the task title error is the website\'s')
static_check('reported by \\(who)' in swift_sources['IncidentsView.swift'], 'P22: each incident says who reported it')
static_check('"Adult": "client turned 18 on"' in swift_sources['ComplianceView.swift'],
             'P23: a guardian consent from before the client turned 18 is listed as the website lists it')
agreements_swift = swift_sources['AgreementsView.swift']
static_check('await api.refreshMe()' in agreements_swift and 'field: aboutName ? "Name" : nil' in agreements_swift
             and 'nameRefused = ((reply["errors"] as? [String: Any])?["signed_name"]) != nil' in agreements_swift
             and 'localizedCaseInsensitiveContains("name")' not in agreements_swift,
             'P24: signing the last agreement lifts the access hold at once')
account_swift = swift_sources['AccountView.swift']
static_check('Both work.' not in account_swift and 'Email codes keep working' not in account_swift
             and 'codes are no longer emailed.' in account_swift and 'Once it is on, sign-in codes are no longer emailed.' in account_swift,
             'P25: My Account says codes are no longer emailed once an authenticator is on')
ai_swift = swift_sources['AIToolsView.swift']
static_check('caseloadCut' in ai_swift and 'AINotice.ask(truncated: r.truncated, caseloadCut: r.caseloadCut)' in ai_swift
             and 'only part of the caseload fit in one question' in ai_swift,
             'P28: Ask your caseload says when the answer stopped early or only part of the caseload fit')
static_check('"Draft lesson plan"' in ai_swift and 'Save it as a curriculum on the website: AI tools › Curriculum generator.' in ai_swift
             and 'AINotice.curriculumIncomplete' in ai_swift,
             'P31: the curriculum generator drafts the website\'s lesson plan')
static_check('periodFrom' in ai_swift and 'Period: \\(formatDate(from)) to \\(formatDate(to)).' in ai_swift and 'AINotice.riskIncomplete' in ai_swift,
             'P32: risk flags say their period and when the list was cut off')
static_check('aiRedaction' in api_swift and 'Client identifiers are stripped before client data is sent to the provider.' in ai_swift
             and 'Identifier redaction is currently off.' in ai_swift,
             'P33: the AI tools say whether client identifiers are stripped')
static_check('AINotice.progressIncomplete' in ai_swift, 'P34: the progress review says when it stopped early')
report_sheet = track_swift[track_swift.index('struct SendReportSheet: View {'):track_swift.index('struct ReportFormResponse')]
static_check(all(k in report_sheet for k in ('"report_kind"', '"barriers"', '"recommendations"', '"report_intro"', '"report_sections"',
                                             '"report_form"', '"report_preview"', '"cc_self"', '"share_portal"', 'releaseWarning'))
             and 'appendDraftBelow(intro, r.text)' in report_sheet,
             'P35: the report form matches the website (kind, period, barriers, recommendations, copies, preview, AI drafts below typed text)')
static_check('Request reopened. Status updated to' in swift_sources['ProcurementView.swift'],
             'P36: reopening a device request says the step it returned to')
equipment_swift = swift_sources['EquipmentView.swift']
loan_sheet = equipment_swift[equipment_swift.index('struct LoanSheet: View {'):equipment_swift.index('var loanConditions')]
static_check('loanDueDays' in loan_sheet and 'value: 60' not in loan_sheet and 'Toggle("No return date"' in loan_sheet
             and 'noReturnDate ? "" :' in loan_sheet and 'FormOptions.saveNumbers(obj["numbers"] as? [String: Any] ?? [:])' in api_swift
             and 'FormOptions.number("loan_due_days", 60)' in api_swift and 'Condition when returned: Unchanged' in equipment_swift,
             'P37: a loan\'s return date follows the administrator\'s loan period, can be left blank, and returns offer the condition list')
static_check('b["needed_until"]' in equipment_swift and 'The item is now held for the client who has waited longest on its waitlist.' in equipment_swift,
             'P38: a reservation can say needed until, and a cancel says when the waitlist moved up')
invoices_swift = swift_sources['InvoicesView.swift']
static_check('"submitted_on"' in invoices_swift and 'body["idem_key"] = idem.key(for: body)' in invoices_swift
             and 'Its due date is set from the payment terms.' in invoices_swift
             and 'Status, balances, and marking drafts submitted' in dashboard_swift,
             'P41: marking an invoice submitted asks for the date sent, with a retry key, and says the due date')
static_check('30 days' not in dashboard_swift and 'authSoonDays' in dashboard_swift,
             'P42: the dashboard says the administrator\'s "ending soon" days, never a fixed 30')
clients_response = api_swift[api_swift.index('struct ClientsResponse: Decodable {'):api_swift.index('struct ClientInfo: Decodable')]
static_check('var total: Int?' in clients_response and 'Showing the first \\(clients.count) of \\(total) clients. Search to find others.' in clients_swift,
             'P43: the client list says "first 200 of N" from the server\'s total')
static_check('"hw_due_"' in add_note and 'let sentRows = sentHomeworkRows(homework)' in add_note and 'let row = sentRows[n - 1]' in add_note
             and 'field: "Homework \\(row) due date"' in add_note
             and 'items.enumerated().filter { $0.element.include && !$0.element.title.isEmpty }.map { $0.offset + 1 }' in clients_swift,
             'P43: a refused homework due date (numbered among the items sent) is tied to that item\'s row on the form')
static_check(re.search(r'else if let text = refusedFields\["note_content"\] \{\s*showFormError\(text, in: &self\.error, field: "Note"\)', add_note),
             'P2: the server\'s note_content refusal is tied to the note')
choose_fn = complete_sheet[complete_sheet.index('private func choose('):complete_sheet.index('var body: some View')]
static_check('try await onDone(details, choice.signature, choice.signedName, outcome)' in choose_fn and 'guard let now = checked() else { return }' in choose_fn
             and 'if let details, outcome.value != CompletionOutcome.alreadyLoggedValue {' in schedule_swift
             and 'Completed — log hours as well' in complete_sheet and 'Completed, log new hours' not in schedule_swift,
             'P9: a choice sends its outcome with the Hours and Miles checked again; "hours already logged" sends no hours or miles; the website\'s wording')
static_check('let aboutDate = refusedFields["start_at"] != nil || refusedFields["date"] != nil\n' in move_sheet,
             'P9: a Move refusal is tied to New start only when the server names the date')
static_check(re.search(r'let key = Self\.errorOrder\.first\(where: \{ refusedFields\[\$0\] != nil \}\)', track_swift)
             and 'Funding agency (set by Who pays)' in track_swift,
             'P11: several track errors show the whole server text with the first field tied; Funding agency is set by Who pays when not VR')
consent_sheet = clients_swift[clients_swift.index('struct ConsentRequestSheet: View {'):clients_swift.index('extension ClientRelease {')]
static_check('showFormError(message, in: &self.error, field: field)' in consent_sheet and '.disabled(busy || needsReload)' in consent_sheet
             and 'The consent could not be reloaded, so sending stays off.' in consent_sheet,
             'P6: guardian errors keep the whole server text with the first field tied; a failed reload after consent_changed keeps Send off')
static_check(re.search(r'let field = \[\("hours", "Hours"\), \("mileage", "Mileage"\), \("description", "Description"\)\]', log_hours_swift)
             and 'showFormError(error.localizedDescription, in: &self.error, field: field)' in log_hours_swift,
             'P16: several hours errors keep the whole server text with the first field tied')
static_check('if message.hasPrefix("IPE amendment date") { return "IPE amended on" }' in edit_sheet
             and 'message.hasPrefix("The date of birth")' in edit_sheet
             and re.search(r'\.onChange\(of: \[name, dob, phone, email\]\) \{ _ in\s*if duplicateRefused \{ duplicateRefused = false; notDuplicate = false \}', edit_sheet),
             'P3/P4: IPE amendment and date-of-birth refusals are tied to their fields; changing the details resets "This is a different person"')
static_check('Button { Task { await loadForm() } }' in report_sheet and '.disabled(busy || !formLoaded || formLoadFailed)' in report_sheet,
             'P35: a report form that fails to load (not an older server) offers Try again and sends nothing meanwhile')
static_check('status409 = (reply["changed"] as? Bool) == true' in invoices_swift and 'contains("no longer a draft")' not in invoices_swift
             and 'dateRefused = fields["submitted_on"] != nil' in invoices_swift,
             'P41: a draft changed meanwhile is told by changed:true; the date error by its key, the words only as a fallback')
reservation_sheet = equipment_swift[equipment_swift.index('struct ReservationSheet: View {'):]
static_check('if hasUntil && hasFrom && APIDate.day(until) < APIDate.day(from) {' in reservation_sheet and 'Needed until: choose the day' in reservation_sheet,
             'P38: Needed until before Needed from is refused on the phone, tied to Needed until')

source = (root / 'ios/Sources/API.swift').read_text()
source = source.split('extension Color {')[0]
source = source.replace('import SwiftUI', '').replace(': ObservableObject', '')
source = source.replace('@Published ', '')
source = re.sub(r'@AppStorage\("[^" ]+"\) ', '', source)
source = source.replace('URLSession.shared', 'testSession')
a = source.index('    private var queueURL: URL {')
b = source.index('    private func loadQueue()', a)
source = source[:a] + '    private var queueURL: URL { testQueueURL }\n' + source[b:]
# Do not inspect or migrate any real Application Support or Documents files.
source = source.replace('FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]', 'testQueueURL.deletingLastPathComponent().appendingPathComponent("legacy")')
# Nor any real Application Support folder (the lost-phone wipe removes Drafts and OfflineCache there).
source = source.replace('FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]', 'testQueueURL.deletingLastPathComponent().appendingPathComponent("support")')
source = source.replace('UserDefaults.standard', 'testDefaults')
prefix = r'''
import Foundation
let testDefaults = UserDefaults(suiteName: "cms-queue-tests-" + UUID().uuidString)!
let testQueueURL = URL(fileURLWithPath: ProcessInfo.processInfo.environment["CMS_QUEUE_TEST_PATH"]!)
enum Keychain {
    static func save(_ value: String, for key: String) {}
    static func load(_ key: String) -> String { "" }
}
final class StubProtocol: URLProtocol, @unchecked Sendable {
    static var status = 200
    static var count = 0
    static var json: String?            // a reply body other than the default ones
    static var urls: [String] = []      // every request's address, in order
    static var offline = false          // fail every request as with no signal
    static var bodies: [Data] = []      // every request's body, in order
    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
    override func startLoading() {
        Self.count += 1
        Self.urls.append(request.url?.absoluteString ?? "")
        if let body = request.httpBody {
            Self.bodies.append(body)
        } else if let stream = request.httpBodyStream {
            stream.open()
            var data = Data()
            var buffer = [UInt8](repeating: 0, count: 4096)
            while stream.hasBytesAvailable {
                let n = stream.read(&buffer, maxLength: buffer.count)
                if n <= 0 { break }
                data.append(buffer, count: n)
            }
            stream.close()
            Self.bodies.append(data)
        } else {
            Self.bodies.append(Data())
        }
        if Self.offline {
            client?.urlProtocol(self, didFailWithError: URLError(.notConnectedToInternet))
            return
        }
        let response = HTTPURLResponse(url: request.url!, statusCode: Self.status, httpVersion: nil, headerFields: nil)!
        client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
        let json = Self.json ?? (Self.status == 200 ? #"{"ok":true}"# : #"{"ok":false,"error":"Rejected for test"}"#)
        client?.urlProtocol(self, didLoad: Data(json.utf8))
        client?.urlProtocolDidFinishLoading(self)
    }
    override func stopLoading() {}
}
let testSession: URLSession = {
    let config = URLSessionConfiguration.ephemeral
    config.protocolClasses = [StubProtocol.self]
    return URLSession(configuration: config)
}()
'''
# The voice assistant's reading of a spoken answer to "shall I save this?", taken
# unchanged from VoiceAssistant.swift into a stand-in type (the real one needs the
# speech and audio frameworks), so it is checked as the app ships it.
voice = (root / 'ios/Sources/VoiceAssistant.swift').read_text()
c = voice.index('    nonisolated static func confirmAnswer(_ utterance: String) -> ConfirmAnswer {')
d = voice.index('\n    }\n', c) + len('\n    }\n')
# Stand-ins for the offline read cache and local notifications (API.swift only calls these
# members; the real types need UIKit and UserNotifications).
prefix += '''
enum OfflineCache {
    static func save(_ data: Data, key: String, owner: Int) {}
    static func load(key: String, owner: Int) -> (data: Data, savedAt: Date)? { nil }
    static func removeAll(owner: Int) {}
    static func remove(key: String, owner: Int) {}
}
final class LocalNotifications { static let shared = LocalNotifications(); func clearScheduled() {} }
'''
prefix += ('enum SpeechController {\n    enum ConfirmAnswer: Equatable { case yes, no, unclear }\n'
           + voice[c:d].replace('nonisolated static func', 'static func', 1) + '}\n')
suffix = r'''
@main struct QueueTests {
    @MainActor static func main() async throws {
        func check(_ ok: Bool, _ label: String) {
            guard ok else { fatalError(label) }
            print("PASS: " + label)
        }
        // A spoken answer counts as yes only when it is a short yes and nothing else: a
        // correction, a question, or our own question heard back is asked again.
        // The organisation's address (4.6.0): https only, never another host than the one typed.
        let addr: (String) -> String? = { API.apiURL(fromAddress: $0)?.absoluteString }
        check(addr("cms.example.org") == "https://cms.example.org/api.php" && addr("example.org/cms") == "https://example.org/cms/api.php"
              && addr("HTTPS://Example.ORG/cms/login.php") == "https://example.org/cms/api.php" && addr("example.org/cms/API.PHP") == "https://example.org/cms/api.php"
              && addr("example.org:8443") == "https://example.org:8443/api.php", "An organisation's address becomes its https API address")
        // 4.28.0: https's own port written out is the same server as no port.
        check(addr("cms.example.org:443") == "https://cms.example.org/api.php" && addr("https://CMS.example.org:443/cms/") == "https://cms.example.org/cms/api.php",
              "\":443\" typed with the address gives the same server address as without it")
        for bad in ["http://cms.example.org", "ftp://x.org", "https:/x.org", "http//x.org", "good.org@evil.com", "x.org\\@evil.com", "", "cms example org", "localhostish"] {
            check(addr(bad) == nil, "\"\(bad)\" is refused as an organisation's address")
        }
        for said in ["OK, make it three", "Correct the time", "Yes, Tuesday instead", "You said: Yes", "Say yes to save", "Is it okay?"] {
            check(SpeechController.confirmAnswer(said) == .unclear, "\"\(said)\" is not taken as a yes to save")
        }
        for said in ["Yes please", "Okay, go ahead", "That's correct"] {
            check(SpeechController.confirmAnswer(said) == .yes, "\"\(said)\" confirms the save")
        }
        for said in ["No, don't save it", "That's not correct"] {
            check(SpeechController.confirmAnswer(said) == .no, "\"\(said)\" cancels the save")
        }
        // 4.31.0: the case note opened after a completion, from a 4.31.0 server's reply or an older one's.
        let snake = JSONDecoder(); snake.keyDecodingStrategy = .convertFromSnakeCase
        let sessionJSON = #"{"id":41,"start_at":"2026-10-20 14:00:00","end_at":"2026-10-20 15:30:00","status":"Scheduled","track_id":7,"client":"Jane Doe","client_id":9}"#
        let scheduled = try snake.decode(Session.self, from: Data(sessionJSON.utf8))
        let newReply = try snake.decode(CompleteSessionReply.self, from: Data(#"{"ok":true,"hours_logged":1.5,"client_id":9,"client_name":"Jane Doe","track_id":7,"session_id":41,"session_date":"2026-10-20","note_subject":"Session on 10/20/2026","note_pending":true}"#.utf8))
        let fromNew = SessionNoteTarget.afterCompletion(newReply, session: scheduled)
        check(fromNew?.sessionId == 41 && fromNew?.clientId == 9 && fromNew?.trackId == 7 && fromNew?.noteSubject == "Session on 10/20/2026"
              && newReply.notePending == true && fromNew?.contextLine == "Case note for Jane Doe, session on 10/20/2026",
              "complete_session's 4.31.0 reply opens the note with its subject (the organisation's date format), track and session")
        let oldReply = try snake.decode(CompleteSessionReply.self, from: Data(#"{"ok":true,"hours_logged":1.5}"#.utf8))
        let fromOld = SessionNoteTarget.afterCompletion(oldReply, session: scheduled)
        check(fromOld?.noteSubject == "Session on Oct 20" && fromOld?.trackId == 7 && fromOld?.sessionId == 41 && fromOld?.clientName == "Jane Doe"
              && fromOld?.contextLine == "Case note for Jane Doe, session on Oct 20",
              "an older server's complete_session reply still opens the note, with \"Session on Oct 20\" worked out on the phone")
        let noClient = try snake.decode(Session.self, from: Data(#"{"id":42,"start_at":"2026-10-20 14:00:00","client":"X"}"#.utf8))
        check(SessionNoteTarget.afterCompletion(oldReply, session: noClient) == nil && SessionNoteTarget.fallbackSubject(day: "not a day") == "Session",
              "no note opens when the client is not known, and an unreadable day gives the subject \"Session\"")
        let noTrack = try snake.decode(CompleteSessionReply.self, from: Data(#"{"ok":true,"hours_logged":0,"client_id":9,"track_id":null,"session_id":"43"}"#.utf8))
        check(SessionNoteTarget.afterCompletion(noTrack, session: noClient)?.trackId == nil && noTrack.sessionId == 43,
              "a session with no track opens its note with no track chosen")
        let toWrite = try snake.decode(NotesToWriteResponse.self, from: Data(#"{"ok":true,"total":3,"notes":[{"session_id":41,"client_id":9,"client_name":"Jane Doe","track_id":7,"session_date":"2026-10-20","start_at":"2026-10-20 14:00:00","note_subject":"Session on Oct 20"},{"client_id":2},{"session_id":40,"client_id":"8","client_name":"Sam Lee","start_at":"2026-10-19 09:00:00"}]}"#.utf8))
        check(toWrite.notes.map(\.sessionId) == [41, 40] && toWrite.total == 3 && toWrite.notes[1].noteSubject == "Session on Oct 19" && toWrite.notes[1].clientId == 8,
              "notes_to_write is read row by row (an unreadable row is left out) with its total")
        let pending = try snake.decode(Session.self, from: Data(#"{"id":44,"status":"Completed","client":"J","note_pending":1}"#.utf8))
        check(pending.notePending == true && scheduled.notePending == nil, "a schedule session's note_pending is read when the server sends it")
        let api = API()
        let body: [String: Any] = ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic test"]
        do { try api.enqueue("add_note", body: body, label: "Test"); fatalError("Unverified enqueue accepted") }
        catch { check(true, "Offline writes require a verified account") }
        api.userId = 1; api.token = "test-one"
        try api.enqueue("add_note", body: body, label: "Test")
        try api.enqueue("add_note", body: body, label: "Test")
        check(api.pendingSync == 1, "Duplicate enqueue retains one stable entry")
        api.syncNoticeDismissed = true
        try api.enqueue("add_note", body: body, label: "Test")
        check(!api.syncNoticeDismissed, "A new offline save restores the dismissed sync notice")
        StubProtocol.status = 403
        await api.flushQueue()
        check(api.pendingSync == 1 && api.syncError != nil, "Rejected entries remain stored with a visible error")
        let attempts = StubProtocol.count
        api.userId = 2; api.token = "test-two"
        await api.flushQueue()
        check(StubProtocol.count == attempts, "Another account cannot replay the original user's entries")
        check(api.pendingSync == 0, "Another account's entries are not counted as pending for this one")
        api.userId = 1; api.token = "test-one"
        StubProtocol.status = 200
        await api.flushQueue()
        check(api.pendingSync == 0, "Confirmed success removes the queued entry")
        let legacy = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Legacy", owner: 0)
        var legacyJSON = try JSONSerialization.jsonObject(with: JSONEncoder().encode([legacy])) as! [[String: Any]]
        legacyJSON[0].removeValue(forKey: "owner")
        try JSONSerialization.data(withJSONObject: legacyJSON).write(to: testQueueURL)
        let beforeLegacy = StubProtocol.count
        await api.flushQueue()
        // An entry with no owner is never this account's to upload, so it is not
        // counted as pending; it stays in the queue file and is reported.
        let storedLegacy = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [[String: Any]]
        check(storedLegacy.count == 1 && api.pendingSync == 0 && api.syncError != nil && StubProtocol.count == beforeLegacy, "Unknown-owner entries remain stored without being uploaded")
        try Data("broken queue".utf8).write(to: testQueueURL)
        do { try api.enqueue("add_note", body: body, label: "Test"); fatalError("Corrupt queue overwritten") }
        catch { check(try String(contentsOf: testQueueURL, encoding: .utf8) == "broken queue", "Corrupt queue is preserved instead of silently replaced") }
        // A write that fails (the queue's folder is read-only, so the atomic write cannot
        // create its file) is returned to the form, and the stored queue is left as it was.
        // The queue reads back fine, so only the write can fail here.
        try Data("[]".utf8).write(to: testQueueURL)
        let queueFolder = testQueueURL.deletingLastPathComponent().path
        let pendingBeforeWrite = api.pendingSync
        try FileManager.default.setAttributes([.posixPermissions: 0o500], ofItemAtPath: queueFolder)
        var writeRefused = false
        do { try api.enqueue("add_note", body: body, label: "Test") } catch { writeRefused = true }
        try FileManager.default.setAttributes([.posixPermissions: 0o700], ofItemAtPath: queueFolder)
        let storedAfterWrite = try String(contentsOf: testQueueURL, encoding: .utf8)
        if getuid() == 0 {
            print("SKIPPED: a failed queue write cannot be simulated as root")
        } else {
            check(writeRefused && storedAfterWrite == "[]" && api.pendingSync == pendingBeforeWrite,
                  "A failed queue write is returned to the form and leaves the stored queue unchanged")
        }
        api.aiEnabled = true; api.aiFeatures = ["draft"]
        api.syncNoticeDismissed = true
        api.clearIdentity()
        check(!api.syncNoticeDismissed, "Signing out resets notice dismissal for the next account")
        check(api.userId == 0 && api.token.isEmpty && !api.aiEnabled && api.aiFeatures.isEmpty, "Sign-out clears identity and cached AI permissions")

        // A refused upload keeps its reason; the notice names the entry and is not re-set.
        try FileManager.default.removeItem(at: testQueueURL)
        let refusedId = UUID().uuidString
        api.userId = 2; api.token = "test-two"
        try api.enqueue("add_note", body: ["idem_key": refusedId, "client_id": 1, "subject": "Synthetic other"], label: "Other account note")
        api.userId = 1; api.token = "test-one"
        try api.enqueue("add_note", body: ["idem_key": refusedId, "client_id": 1, "subject": "Synthetic refused"], label: "Refused note")
        try api.enqueue("add_note", body: ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic second"], label: "Second note")
        StubProtocol.status = 403
        await api.flushQueue()
        let refusedEntries = try api.ownQueuedEntries()
        check(refusedEntries.count == 2 && refusedEntries.allSatisfy { $0.lastError == "Rejected for test" }, "A refused upload keeps the server's reason with the entry")
        check(api.syncError?.contains("Refused note") == true, "The sync notice names the refused entry")
        let firstNotice = api.syncError
        await api.flushQueue()
        let afterSecondFlush = try api.ownQueuedEntries()
        check(api.syncError == firstNotice && afterSecondFlush.count == 2, "The same refusal twice leaves the sync notice unchanged")
        try api.discardQueued(UUID(uuidString: refusedId)!)
        let afterDiscard = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [[String: Any]]
        let ownAfterDiscard = try api.ownQueuedEntries()
        check(afterDiscard.count == 2 && ownAfterDiscard.map(\.label) == ["Second note"]
              && afterDiscard.contains { ($0["label"] as? String) == "Other account note" },
              "Discarding an entry removes only the signed-in account's entry with that id")
        check(api.syncError?.contains("Second note") == true && api.syncError?.contains("Refused note") == false, "The sync notice follows the discard")

        // A refused session (401) asks for sign-in again without losing the account or its queue.
        StubProtocol.status = 401
        await api.flushQueue()
        let afterRefusedSession = try api.ownQueuedEntries()
        check(api.reauthRequired && api.userId == 1 && api.token == "test-one" && afterRefusedSession.count == 1,
              "A 401 during upload asks for sign-in again and keeps the account and its offline entries")
        // No await follows: the refresh-and-upload task started by a same-account sign-in never runs here.
        api.finishSignIn(token: "test-one-again", userId: 1, name: "Test")
        let afterSameSignIn = try api.ownQueuedEntries()
        check(!api.reauthRequired && api.userId == 1 && api.token == "test-one-again" && afterSameSignIn.count == 1,
              "The same account signing in again clears the sign-in request and keeps its offline entries")
        api.reauthRequired = true
        api.finishSignIn(token: "test-three", userId: 3, name: "Other")
        let afterSwitch = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [[String: Any]]
        check(!api.reauthRequired && api.userId == 3 && api.token == "test-three" && api.syncError == nil
              && api.pendingSync == 0 && afterSwitch.count == 2,
              "Another account signing in starts afresh and leaves the first account's entries stored")

        // 4.27.0: an old offline entry is sent first; only what is still here afterwards (refused,
        // or another account's) is removed once over 30 days old, and the notice names it.
        let fortyDays = Date().addingTimeInterval(-40 * 86400)
        let oldSent = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Old sendable note",
                                      owner: 3, savedAt: fortyDays)
        try JSONEncoder().encode([oldSent]).write(to: testQueueURL)
        StubProtocol.status = 200
        StubProtocol.urls = []
        await api.flushQueue()
        check(try api.ownQueuedEntries().isEmpty && StubProtocol.urls.filter { $0.contains("action=add_note") }.count == 1 && api.syncError == nil,
              "An offline entry over 30 days old is still sent when the server can be reached, not dropped first")
        let oldRefused = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Old note",
                                         owner: 3, savedAt: fortyDays)
        let otherOld = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Someone else's note",
                                       owner: 9, savedAt: fortyDays)
        try JSONEncoder().encode([oldRefused, otherOld]).write(to: testQueueURL)
        StubProtocol.status = 403
        StubProtocol.urls = []
        await api.flushQueue()
        let leftAfterExpiry = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [Any]
        check(leftAfterExpiry.isEmpty && StubProtocol.urls.filter { $0.contains("action=add_note") }.count == 1
              && api.syncError?.contains("Old note") == true && api.syncError?.contains("30 days") == true,
              "An offline entry over 30 days old that is still not accepted is removed after the try, and the notice says so")

        // 4.28.0: with no signal, a server fault (5xx), a 429 or a refused redirect the server was
        // never reached, so an old entry is kept, not expired.
        for (label, status, offline) in [("no signal", 200, true), ("a 503", 503, false), ("a 429", 429, false), ("a refused redirect", 302, false)] {
            let oldUnsent = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Old unsent note",
                                            owner: 3, savedAt: fortyDays)
            try JSONEncoder().encode([oldUnsent]).write(to: testQueueURL)
            StubProtocol.status = status
            StubProtocol.offline = offline
            StubProtocol.urls = []
            api.syncNoticeDismissed = true
            await api.flushQueue()
            StubProtocol.offline = false
            let kept = try api.ownQueuedEntries()
            check(kept.map(\.label) == ["Old unsent note"] && StubProtocol.urls.filter { $0.contains("action=add_note") }.count == 1
                  && api.syncError?.contains("30 days") != true,
                  "An offline entry over 30 days old is kept after an upload that got \(label) (the server was not reached)")
        }
        try FileManager.default.removeItem(at: testQueueURL)
        StubProtocol.status = 200

        // 4.27.0: a redirect is followed only to the same https host and port (none written is 443),
        // and a refused one is explained instead of "Unexpected response from the server."
        check(RedirectGuard.port(of: URL(string: "https://a.example.org/x")!) == RedirectGuard.port(of: URL(string: "https://a.example.org:443/y")!)
              && RedirectGuard.port(of: URL(string: "https://a.example.org:8443/x")!) != 443,
              "A redirect's port written or not counts as the same when it is https's own")

        // 4.27.0: changing the server address keeps the offline entries until a sign-in to a
        // really different server works; the same server however typed loses nothing.
        try api.enqueue("add_note", body: ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic kept"], label: "Kept note")
        check(api.entriesLostBySigningIn(to: API.apiURL(fromAddress: "HTTPS://Example.INVALID/")!) == 0,
              "The same server typed differently would remove no offline entries")
        api.switchServer(to: "other.example.org")
        check(api.token.isEmpty && FileManager.default.fileExists(atPath: testQueueURL.path)
              && api.entriesLostBySigningIn(to: API.apiURL(fromAddress: "other.example.org")!) == 1,
              "Changing the server address ends the session and keeps the offline entries before sign-in")
        api.finishSignIn(token: "test-four", userId: 4, name: "New server")
        check(((try? JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as? [Any]) ?? []).isEmpty,
              "Signing in to a different server removes the previous server's offline entries")

        // 4.29.0: "still being processed" (409 with processing true) is its own error, so the
        // screens keep their retry key; an offline entry that gets it is kept, not marked refused.
        StubProtocol.status = 409
        StubProtocol.json = #"{"ok":false,"processing":true,"error":"This is still being processed. Try again in a moment."}"#
        do { let _: OKResponse = try await api.call("add_note", body: ["idem_key": "x"]); fatalError("A processing reply was taken for an answer") }
        catch APIError.processing(let said) { check(said.contains("still being processed"), "A 409 with processing true is reported as still being processed (the retry key is kept)") }
        catch { fatalError("A processing reply gave \(error)") }
        try api.enqueue("add_note", body: ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic processing"], label: "Processing note")
        await api.flushQueue()
        let afterProcessing = try api.ownQueuedEntries()
        check(afterProcessing.count == 1 && afterProcessing[0].lastError == nil && api.syncError?.contains("still being processed") == true,
              "An offline entry answered \"still being processed\" stays queued with its key and is not marked refused")
        // 4.29.0: a queued note is sent with the time it was saved on the phone (recorded_at);
        // one kept longer than the server accepts goes without it.
        StubProtocol.json = nil
        StubProtocol.status = 200
        StubProtocol.bodies = []
        await api.flushQueue()
        let sentNote = StubProtocol.bodies.compactMap { try? JSONSerialization.jsonObject(with: $0) as? [String: Any] }
            .first { ($0["subject"] as? String) == "Synthetic processing" }
        let recordedAt = (sentNote?["recorded_at"] as? String).flatMap { ISO8601DateFormatter().date(from: $0) }
        let leftAfterNote = try api.ownQueuedEntries()
        check(recordedAt.map { abs($0.timeIntervalSince(afterProcessing[0].savedAt ?? .distantPast)) < 2 } == true && leftAfterNote.isEmpty,
              "A queued note is sent with recorded_at, the time it was saved on the phone")
        let eightDays = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: ["subject": "old"]), label: "Old",
                                        owner: 4, savedAt: Date().addingTimeInterval(-8 * 86400))
        let hoursEntry = API.PendingCall(action: "log_hours", body: try JSONSerialization.data(withJSONObject: ["hours": 1]), label: "Hours",
                                         owner: 4, savedAt: Date())
        check(API.uploadBody(eightDays) == eightDays.body && API.uploadBody(hoursEntry) == hoursEntry.body,
              "recorded_at is left out for a note older than the server accepts, and never added to other entries")

        StubProtocol.status = 302
        do { let _: OKResponse = try await api.call("me"); fatalError("A refused redirect was taken for an answer") }
        catch { check(error.localizedDescription == API.redirectRefused, "A refused redirect says the server's address may have changed") }
        StubProtocol.status = 200

        // 4.27.0: a 401 that says this phone was reported lost wipes it during an upload too,
        // and the server is told once the wipe is done.
        try api.enqueue("add_note", body: ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic wiped"], label: "Wiped note")
        StubProtocol.status = 401
        StubProtocol.json = #"{"ok":false,"wipe":true,"error":"This phone was reported lost."}"#
        StubProtocol.urls = []
        await api.flushQueue()
        try await Task.sleep(nanoseconds: 500_000_000)
        StubProtocol.json = nil
        check(api.userId == 0 && api.token.isEmpty && !api.reauthRequired && !FileManager.default.fileExists(atPath: testQueueURL.path)
              && api.sessionNotice?.contains("reported lost") == true,
              "A wipe answer during an upload removes the offline entries and signs out, instead of asking to sign in again")
        check(StubProtocol.urls.contains { $0.contains("action=wipe_done") && $0.hasPrefix("https://other.example.org/") },
              "After a wipe the app tells the server (wipe_done)")
    }
}
'''
with tempfile.TemporaryDirectory(prefix='cms-swift-tests-') as tmp:
    path = Path(tmp)
    swift = path / 'QueueTests.swift'
    swift.write_text(prefix + source + suffix)
    subprocess.run(['swiftc', '-module-cache-path', str(path / 'modules'), '-parse-as-library', str(swift), '-o', str(path / 'tests')], check=True)
    import os
    # The queue sits in a folder of its own, which the test makes read-only for a moment.
    (path / 'queue').mkdir()
    subprocess.run([str(path / 'tests')], env={**os.environ, 'CMS_QUEUE_TEST_PATH': str(path / 'queue' / 'queue.json')}, check=True)
