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
# 4.35.0: the check moved into inc/work_logs.php's visit_choice_problem(), shared with complete_session.
work_logs_php = (root / 'public/inc/work_logs.php').read_text()
static_check(re.search(r"fail\('RSA category this visit: .*?is not offered on this track\..*?, 422\)", log_hours, re.S)
             or (re.search(r"visit_choice_problem\([^;]*\)\)\s*fail_field\(\$visitProblem\[0\], \$visitProblem\[1\]\)", log_hours)
                 and re.search(r"\['rsa_category', 'RSA category this visit: .*?is not offered on this track\.", work_logs_php, re.S)),
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
static_check('Button(request.cancelLabel) { dismiss() }' in session_notes and 'var cancelLabel = "Cancel"' in session_notes and 'tracksTimeout' in session_notes and 'if tracks == nil && !Task.isCancelled { tracks = [] }' in session_notes,
             'opening a session\'s case note can be cancelled and opens without the track list after a short timeout')
dashboard_swift = swift_sources['DashboardView.swift']
static_check('notesToWrite = await loadNotesToWrite(api)' in dashboard_swift and 'NoteToWriteRow(item: item)' in dashboard_swift,
             'the Dashboard shows Notes to write with Write it now')
project_yml = (root / 'ios/project.yml').read_text()
static_check(project_yml.count('MARKETING_VERSION: "4.35.0"') == 2 and project_yml.count('CURRENT_PROJECT_VERSION: "66"') == 2,
             'the app and widget are version 4.35.0 (66)')

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
static_check('var total: Int?' in clients_response and 'Showing the first \\(shown) of \\(websiteNumber(all)) matching clients, in name order.' in clients_swift
             and 'clientsHeading(shown: clients.count, total: total' in clients_swift,
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

# 4.35.0: field casework in the iPhone app (plan-4350 Q1-Q13, IPHONE parts; static). Each check
# reads the request the app sends and the reply fields it reads, not comments.
api_swift = swift_sources['API.swift']
schedule_swift = swift_sources['ScheduleView.swift']
clients_swift = swift_sources['ClientsView.swift']
edit_sheet = swift_sources['ClientEditSheet.swift']
portal_swift = swift_sources['PortalRequestsView.swift']


def between(text, start, end):
    """The source from `start` through `end` (both must be there)."""
    a = text.index(start)
    b = text.index(end, a + len(start))
    return text[a:b + len(end)]


def coding_keys(text, struct, indent=''):
    """The CodingKeys cases of a struct (not of a type nested in it), as a set of names; `indent`
    is the struct's own indentation (a struct nested in another)."""
    body = between(text, '\n%sstruct %s: ' % (indent, struct), '\n%s}\n' % indent)
    m = re.search(r'\n%s    (?:private )?enum CodingKeys: String, CodingKey \{ case ([^}]*)\}' % indent, body)
    return set(k.strip() for k in m.group(1).replace('\n', ' ').split(',')) if m else set()


group_sheet = between(schedule_swift, 'struct GroupSessionSheet: View {', 'struct GroupAttendanceSheet: View {')
group_body = between(group_sheet, 'static func groupBody(', 'private func submit()')
group_submit = between(group_sheet, 'private func submit()', '\n    }\n}\n')
static_check('try await api.call("schedule_group", body: body, refusal: { reply in' in group_submit
             and all(k in group_body for k in ('"client_ids": clientIds', '"tracks": Dictionary(uniqueKeysWithValues: tracks.map { (String($0.key), $0.value) })',
                                                '"staff_id": staffId', '"date": APIDate.dayCentral(start)', '"time": APIDate.timeCentral(start)',
                                                '"duration": duration', '"location": location', '"notes": notes',
                                                '"is_remote": isRemote ? 1 : 0', '"meeting_url": meetingUrl'))
             and 'if approved, let warningsHash { body["authorization_ack_hash"] = warningsHash }' in group_submit
             and 'body["idem_key"] = idem.key(for: body)' in group_submit
             and all('reply["%s"]' % k in group_submit for k in ('errors', 'authorization_warnings', 'authorization_hash', 'duplicate')),
             'Q1: Schedule a group session sends schedule_group with the clients, each one\'s track, the time in the server\'s zone, the approval hash and a retry key')
static_check('let r: GroupChoicesResponse = try await api.call("group_choices")' in group_sheet
             and coding_keys(schedule_swift, 'GroupChoicesResponse') == {'clients', 'staff', 'defaultStaffId'}
             and coding_keys(schedule_swift, 'GroupChoiceClient') == {'id', 'name', 'tracks'}
             and 'staffId = r.defaultStaffId ??' in group_sheet
             and 'Label("Schedule a group session", systemImage: "person.3")' in schedule_swift
             and 'let _: GroupChoicesResponse = try await api.call("group_choices")' in schedule_swift,
             'Q1: the group form reads group_choices (clients with their tracks, instructors, the default one); the button shows only on a server that has it')
static_check('Toggle("I reviewed these warnings and approve this group booking despite them", isOn: $approved)' in group_sheet
             and 'warnings = refusedWarnings' in group_submit and 'warningsHash = refusedHash' in group_submit
             and re.search(r'if refusedDuplicate \{\s*onDone\(message\)\s*dismiss\(\)', group_submit)
             and 'if key.hasPrefix("track_"), let cid = Int(key.dropFirst(6))' in group_sheet and 'return "Training track for \\(c.name)"' in group_sheet
             and '.errorField("Training track for \\(client.name)")' in group_sheet and 'Toggle(label, isOn: on).errorField("Clients")' in group_sheet
             and '"clients": "Clients", "staff_id": "Instructor"' in group_sheet
             and 'showFormError(text, in: &self.error, field: fieldName(key))' in group_submit
             and '— no active training track, so no hours will be logged' in group_sheet,
             'Q1: warnings are approved by their hash, an already-booked group is information, and errors.clients / errors.track_<id> are tied to their fields')
attend_sheet = between(schedule_swift, 'struct GroupAttendanceSheet: View {', '\n}\n')
static_check('try await api.call("group_session", query: ["group_uid": groupUid])' in attend_sheet
             and {'startAt', 'started', 'canRecord', 'defaultHours', 'members'} <= coding_keys(schedule_swift, 'GroupSessionResponse')
             and {'sessionId', 'clientName', 'trackId', 'trackTitle'} <= coding_keys(schedule_swift, 'GroupSessionMember')
             and 'attended = Set(r.members.map(\\.sessionId))' in attend_sheet and 'hoursText = r.defaultHours' in attend_sheet
             and 'Label("Record attendance for the group", systemImage: "person.3.fill")' in schedule_swift
             and 'recordingGroup = GroupRef(id: uid)' in schedule_swift,
             'Q2: Record attendance for the group (a visible button) reads group_session: every member ticked, the website\'s hours prefilled')
attend_submit = between(attend_sheet, 'private func submit()', '\n    }\n')
static_check('try await api.call("complete_group", body: body, refusal: { reply in' in attend_submit
             and '"group_uid": groupUid' in attend_submit and '"listed": group.members.map(\\.sessionId)' in attend_submit
             and '"attended": group.members.map(\\.sessionId).filter { attended.contains($0) }' in attend_submit
             and '"hours": hours' in attend_submit and 'body["idem_key"] = idem.key(for: body)' in attend_submit
             and 'if let text = refusedFields["hours"] {\n                showFormError(text, in: &self.error, field: "Hours")' in attend_submit
             and {'message', 'notesToWrite'} <= coding_keys(schedule_swift, 'GroupCompleteReply')
             and 'onDone(r.message ??' in attend_submit and 'Self.notesWithMembers(r.notesToWrite, group: group))' in attend_submit,
             'Q2: complete_group sends who was listed and who attended with the hours and a retry key; errors.hours is tied; the reply\'s message and notes are read')
static_check('" will be recorded as No Show, with no hours logged."' in attend_sheet.replace('"\\(total - attended) will be recorded', '" will be recorded')
             and 'Record attendance for this group session? \\(attended) of \\(total) clients attended and will be marked Completed' in attend_sheet
             and 'logged to their training track and billed. ' in attend_sheet
             and '.confirmationDialog(confirmText, isPresented: $confirming' in attend_sheet
             and 'groupNoteAfterSheet = groupNoteRequest(0, lead: message)' in schedule_swift
             and re.search(r'private func afterGroupSheet\(\) \{.*?openNote\(request\)', schedule_swift, re.S),
             'Q2: recording asks with the website\'s sentence, and the first attended client\'s case note opens afterwards')
complete_sheet = between(schedule_swift, 'struct CompleteSessionSheet: View {', 'private struct SigningStep')
complete_fn = between(schedule_swift, '    private func complete(_ session: Session,', '\nstruct NewSessionSheet: View {')
static_check('if let goalId = details.goalId { body["goal_id"] = goalId }' in complete_fn
             and 'if let category = details.category { body["rsa_category"] = category }' in complete_fn
             and 'refusedFields = (reply["errors"] as? [String: String]) ?? [:]' in complete_fn
             and 'refusedFields["goal_id"] != nil ? "Goal worked on" : (refusedFields["rsa_category"] != nil ? "Service delivered" : nil)' in complete_fn
             and 'Picker("Goal worked on", selection: $goalId)' in complete_sheet and 'Picker("Service delivered", selection: $category)' in complete_sheet
             and '.errorField("Goal worked on")' in complete_sheet and '.errorField("Service delivered")' in complete_sheet
             and 'try? await api.call("goals", query: ["track_id": String(trackId)])' in complete_sheet
             and 'r.tracks.first(where: { $0.id == trackId })?.visitCategories' in complete_sheet
             and 'categories.count > 1 && !category.isEmpty ? category : nil' in complete_sheet
             and complete_sheet.count('catch let refusal as FieldRefusal') == 2,
             'Q3: completing a session offers Goal worked on and Service delivered as the website, sends goal_id / rsa_category, and ties their refusals')
row_fn = between(schedule_swift, '    private func sessionRow(_ session: Session) -> some View {', '    private struct CheckInResult')
# Drawn after the row's rotor, outside its one VoiceOver stop (review item 4).
visible = row_fn[row_fn.index("// 4.35.0: outside the row's one VoiceOver stop"):]
static_check(all(re.search(r'Button \{\s*%s\s*\} label: \{\s*Label\("%s"' % (re.escape(action), label), visible)
                 for action, label in (('movingSession = session', 'Move session'),
                                       ('cancellingMore = (session, "cancel_series")', 'Cancel the series'),
                                       ('cancellingMore = (session, "cancel_group")', 'Cancel the whole group')))
             and '.accessibilityLabel("Move \\(name)")' in visible and 'Cancel the whole group: the group \\(name)' in visible
             and 'Cancel this group session on \\(date) for every client in it? Each client is sent a calendar cancellation.' in schedule_swift
             and 'Cancel this session with \\(client) on \\(date) and every later session in its weekly series?' in schedule_swift
             and 'Button("Move session") { movingSession = session }' in row_fn[row_fn.index('.accessibilityActions {'):],
             'Q5: Move session, Cancel the series and Cancel the whole group are visible buttons naming the session (kept on the rotor too), asking with the website\'s words')
offer_sheet = between(portal_swift, 'struct OfferTimesSheet: View {', '\n}\n')
offer_submit = between(offer_sheet, 'private func submit()', '\n    }\n')
static_check('try await api.call("offer_times", query: ["id": String(requestId)])' in offer_sheet
             and coding_keys(portal_swift, 'OfferTimesForm') == {'request', 'canOffer', 'staff', 'defaults', 'lengths', 'keepsLength', 'tracks', 'offered'}
             and {'staffId', 'minutes', 'remote', 'location', 'trackId'} <= coding_keys(portal_swift, 'OfferDefaults', indent='    ')
             and 'try await api.call("offer_times", body: body, refusal: { reply in' in offer_submit
             and all(k in offer_submit for k in ('"id": requestId', '"times": sent.map(\\.time)', '"minutes": minutes', '"staff_id": staffId',
                                                  '"track_id": form.keepsLength ? 0 : trackId', '"remote": remote ? 1 : 0', '"location":'))
             and 'body["idem_key"] = idem.key(for: body)' in offer_submit
             and 'APIDate.dayCentral($0) + " " + APIDate.timeCentral($0)' in offer_sheet
             and 'static let timeFields = ["First time", "Second time", "Third time"]' in offer_sheet
             and 'showFormError(text, in: &self.error, field: Self.timeErrorField(key, sent: sent) ?? Self.fieldNames[key])' in offer_submit,
             'Q6: Offer times reads the offer form\'s defaults and sends up to three "Y-m-d H:i" times with length, instructor, track, remote, location and a retry key; errors tied')
static_check('Text("Offer these times")' in offer_sheet and 'They choose one in their portal.' in offer_sheet
             and 'Text("The session keeps its length.")' in offer_sheet
             and {'canOffer', 'offered'} <= coding_keys(portal_swift, 'PortalRequestItem')
             and 'canOffer = SessionNoteTarget.flag(c, .canOffer) ?? false' in portal_swift
             and 'if r.canOffer {' in portal_swift and '"Offer times" : "Offer different times"' in portal_swift,
             'Q6: an open appointment or reschedule request offers Offer times (a visible button) and lists the times offered')
assess_sheet = between(edit_sheet, 'struct AddAssessmentSheet: View {', '\n}\n')
static_check('try await api.call("add_assessment", body: body, refusal: { reply in' in assess_sheet
             and 'refusedFields = (reply["errors"] as? [String: String]) ?? [:]' in assess_sheet
             and '("assessment_date", "Assessment date")' in assess_sheet and '("hardware_details", "Hardware details")' in assess_sheet
             and '("software_version", "Software & version")' in assess_sheet and '("summary", "Assessment summary")' in assess_sheet
             and 'showFormError(first.flatMap { refusedFields[$0.key] } ?? message, in: &self.error, field: first?.field)' in assess_sheet
             and '.errorField("Assessment date")' in assess_sheet and 'errorName: "Hardware details"' in assess_sheet
             and 'var techTypes: [String]?' in between(edit_sheet, 'struct AssessmentsResponse: Decodable {', '\n}\n')
             and 'if let list = response.techTypes, !list.isEmpty { techChoices = list }' in edit_sheet,
             'Q7: Record a tech assessment ties add_assessment\'s errors to their fields and uses the server\'s technology list')
after_section = between(clients_swift, 'struct AfterClosureSection: View {', 'struct PhoneSurveySheet: View {')
phone_sheet = between(clients_swift, 'struct PhoneSurveySheet: View {', 'struct FollowupCheckSheet: View {')
fu_sheet = clients_swift[clients_swift.index('struct FollowupCheckSheet: View {'):]
static_check('afterClosure' in coding_keys(api_swift, 'ClientDetail')
             and coding_keys(api_swift, 'AfterClosureState') == {'surveys', 'followups', 'byPhone'}
             and {'id', 'intervalDays', 'dueOn', 'status', 'dueNow', 'text'} <= coding_keys(api_swift, 'ClosureFollowup')
             and 'if let after = detail.afterClosure {' in clients_swift
             and 'try await api.call("send_survey", body: body)' in after_section and 'body["idem_key"] = sendIdem.key(for: body)' in after_section
             and "Email the outcome survey to \\(client)?" in after_section and 'Email the survey (client prefers phone or post)' in after_section
             and 'if fu.status == "Pending" && fu.dueNow {' in after_section and 'Label("Record the \\(fu.label)"' in after_section
             and 'Label("Enter survey answers taken by phone"' in after_section,
             'Q9: the client record shows After closure from after_closure: Email the survey (asking first), answers by phone, and each due follow-up check')
static_check('try await api.call("phone_survey", body: body, refusal: { reply in' in phone_sheet
             and '["client_id": clientId, "satisfaction": sat, "goals_met": goals, "comments": comments]' in phone_sheet
             and 'if let text = refusedFields["satisfaction"] { showFormError(text, in: &self.error, field: "Satisfaction") }' in phone_sheet
             and 'else if let text = refusedFields["goals_met"] { showFormError(text, in: &self.error, field: "Goals met") }' in phone_sheet
             and 'try await api.call("closure_followup", body: body, refusal: { reply in' in fu_sheet
             and all(k in fu_sheet for k in ('"followup_id": followup.id', '"still_employed": employed', '"still_using_tech": usingTech',
                                              '"independent": independent', '"needs_service": needsService', '"notes": notes'))
             and fu_sheet.count('body["idem_key"] = idem.key(for: body)') == 1 and phone_sheet.count('body["idem_key"] = idem.key(for: body)') == 1
             and '" A re-referral task will be added."' in fu_sheet and 'Text("Not asked").tag("")' in fu_sheet,
             'Q9: phone survey answers and follow-up checks send the website\'s fields with retry keys; rating errors are tied')
client_save = between(edit_sheet, '    private func save() async {', '    /// The form field a save_client refusal is about')
static_check(all(k in client_save for k in ('body["preferred_language"] = language', 'body["vision_status"] = visionStatus',
                                            'body["vision_onset_age"] = onsetText.trimmingCharacters(in: .whitespaces)', 'body["on_hold_until"] ='))
             and 'if sends435 {' in client_save
             and {'preferredLanguage', 'visionStatus', 'visionOnsetAge', 'onHoldReason', 'onHoldUntil', 'createdAt', 'attendance'} <= coding_keys(api_swift, 'ClientExtras')
             and 'supports435 = c.contains(.preferredLanguage)' in api_swift
             and '"vision_onset_age": "Age when vision loss began", "on_hold_until": "Expected return date"' in edit_sheet
             and 'Picker("Preferred language (the client\'s portal and emails use it)", selection: $language)' in edit_sheet
             and 'Picker("Vision status", selection: $visionStatus)' in edit_sheet and 'Text("Not recorded").tag("")' in edit_sheet
             and 'Toggle("Expected return date (On Hold only, optional)", isOn: $holdUntilSet)' in edit_sheet
             and 'Age when vision loss began: enter a whole number of years from 0 to 120, or leave it blank.' in edit_sheet,
             'Q10: the client form offers language, vision status and onset age, and the On Hold return date, sends them only to a server that reports them, and ties their errors')
static_check(all('LabeledContent("%s"' % k in clients_swift for k in ('VR case number', 'IPE goal', 'IPE date', 'Counselor agency',
                                                                     'Guardian email', 'Vision', 'Attendance', 'Client since'))
             and 'Expected back \\(formatDate(u)).' in clients_swift
             and 'Self.onHoldLine(reason: detail.extras.onHoldReason, until: detail.extras.onHoldUntil)' in clients_swift
             and coding_keys(api_swift, 'ClientAttendance') == {'completed', 'noShow', 'cancelled'}
             and '\\(completed) completed, \\(noShow) no-show' in api_swift,
             'Q11: the client detail shows the website\'s overview: On Hold line, VR case, IPE, agency, guardian email, vision, attendance, client since')
clients_list = between(clients_swift, 'struct ClientListQuery: Equatable {', 'struct ClientDetailView: View {')
static_check('var q: [String: String] = ["with_filters": "1"]' in clients_list
             and all('q["%s"] =' % k in clients_list for k in ('status', 'who', 'type', 'agency', 'school', 'office'))
             and 'api.call("clients", query: Self.clientsQuery(f, search: q))' in clients_list
             and coding_keys(api_swift, 'ClientListFilters') == {'who', 'statuses', 'whoChoices', 'types', 'agencies', 'offices'}
             and 'filters = (try? c.decodeIfPresent(ClientListFilters.self, forKey: .filters)) ?? nil' in api_swift
             and 'try await api.call("clients_filter", body: ["who": who], refusal: { reply in' in clients_list
             and 'field: refusedFields["who"] != nil ? "Caseload" : nil' in clients_list
             and 'ForEach(choices.whoChoices, id: \\.value) { Text($0.label).tag($0.value) }' in clients_list
             and all('Picker("%s"' % k in clients_list for k in ('Case status', 'Caseload', 'Client type', 'Funding agency', 'Office'))
             and '"Filters, \\(filtersOn) on"' in clients_list and 'Clients matching the filters (\\(all))' in clients_list
             and 'matching clients, in name order. Search by name or narrow the filters to find the rest.' in clients_list,
             'Q13: the client list offers the website\'s filters, sends only those set with with_filters, remembers the caseload with clients_filter, and says the filtered count')
static_check('All clients (' not in clients_swift, 'Q13: the client list heading is never a hard-coded "All clients (N)"')

# 4.35.0 review round: each key the app reads must be one the server sends (read from the server
# files themselves), and the fixes of the independent review and the parity decisions.
api_435_php = (root / 'public/inc/api_435.php').read_text()
client_list_php = (root / 'public/inc/client_list.php').read_text()
outcome_php = (root / 'public/inc/outcome_followups.php').read_text()
client_fields_php = (root / 'public/inc/client_fields.php').read_text()
api_parity_php = (root / 'public/inc/api_parity.php').read_text()


def sends(php, *keys):
    """Every key is written as a reply key ('key' =>) in the server source."""
    return all("'%s' =>" % k in php for k in keys)


static_check(sends(api_435_php, 'clients', 'staff', 'default_staff_id', 'tracks', 'group_uid', 'sessions_created', 'no_track', 'invite_sent',
                   'authorization_warnings', 'authorization_hash', 'duplicate')
             and "'group_choices' =>" in api_435_php and "'schedule_group' =>" in api_435_php
             and re.search(r"\$input\['client_ids'\]", api_435_php) and re.search(r"\$input\['tracks'\]", api_435_php)
             and re.search(r"\$input\['authorization_ack_hash'\]", api_435_php),
             'Q1 (server): group_choices and schedule_group send and read the keys the app uses')
static_check(sends(api_435_php, 'start_at', 'started', 'instructor', 'can_record', 'default_hours', 'members', 'session_id', 'client_id',
                   'client_name', 'track_id', 'track_title', 'completed', 'message', 'notes_to_write', 'note_subject')
             and all(re.search(r"\$input\['%s'\]" % k, api_435_php) for k in ('group_uid', 'listed', 'attended', 'hours'))
             and "$_GET['group_uid']" in api_435_php,
             'Q2 (server): group_session and complete_group send and read the keys the app uses')
static_check(sends(api_435_php, 'request', 'can_offer', 'defaults', 'lengths', 'keeps_length', 'offered', 'staff_name', 'minutes', 'remote', 'location')
             and sends(api_parity_php, 'can_offer', 'offered')
             and "$timeErrs['time' . $n]" in api_435_php and 'foreach ([1, 2, 3] as $n)' in api_435_php
             and all(re.search(r"\$input\['%s'\]" % k, api_435_php) for k in ('times', 'staff_id', 'minutes', 'track_id', 'remote', 'location')),
             'Q6 (server): offer_times sends the form and reads the offer the app sends; its timeN is the Nth time sent')
static_check("'tech_types' => array_values(cms_list_items('tech_types'))" in api_php
             and sends(outcome_php, 'surveys', 'followups', 'by_phone', 'due_now', 'text')
             and "'after_closure' => after_closure_state(" in api_php
             and "'attendance' => $client['attendance']" in api_php
             and "['completed' => $attendance['done'], 'no_show' => $attendance['noshow'], 'cancelled' => $attendance['cancelled']]" in api_php
             and all(k in api_php for k in ('preferred_language, vision_status, vision_onset_age, on_hold_reason, on_hold_until, created_at FROM clients',))
             and "$errors['vision_onset_age']" in client_fields_php,
             'Q7/Q9/Q10/Q11 (server): tech_types, after_closure (surveys, followups, by_phone, due_now, text), attendance and the client fields are sent')
static_check(sends(api_435_php, 'who') and "'who' => 'Caseload: choose one of the listed caseloads.'" in api_435_php
             and sends(client_list_php, 'statuses', 'who_choices', 'types', 'agencies', 'offices', 'value', 'label')
             and "$clientsReply['filters'] = ['who' => $whoApi] + client_list_filters(" in api_php
             and "['', 'Everyone\\'s clients']" in client_list_php
             and "'notes' => 'Notes are too long (1,000 characters maximum).'" in api_435_php
             and "['satisfaction' => 'Satisfaction', 'goals_met' => 'Goals met']" in api_435_php,
             'Q9/Q13 (server): the client list filters, clients_filter and the follow-up notes error are what the app reads')

# Review item 1: time errors are tied to the field the time came from.
static_check('static func timeErrorField(_ key: String, sent: [(field: String, time: String)]) -> String? {' in offer_sheet
             and 'return n <= sent.count ? sent[n - 1].field : timeFields.first' in offer_sheet
             and '"times": sent.map(\\.time)' in offer_sheet
             and 'field: Self.timeErrorField(key, sent: sent) ?? Self.fieldNames[key]' in offer_sheet
             and '"time1": "First time"' not in offer_sheet,
             'Review 1: offer_times\'s timeN error is tied to the Nth time sent (Third time when only the first and third are offered)')
# Review item 2 and the walk-through of every note.
static_check('static func notesWithMembers(_ notes: [SessionNoteTarget], group: GroupSessionResponse) -> [SessionNoteTarget] {' in attend_sheet
             and 'trackId: n.trackId ?? m?.trackId' in attend_sheet and 'startAt: n.startAt ?? group.startAt' in attend_sheet
             and 'Self.notesWithMembers(r.notesToWrite, group: group)' in attend_sheet
             and '"Next: \\(name), \\(index + 1) of \\(total)."' in schedule_swift
             and 'cancelLabel: "Skip for now")' in schedule_swift
             and 'if groupNoteIndex + 1 < groupNotes.count {' in schedule_swift
             and 'Button(cancelLabel) { if hasChanges { askingDiscard = true } else { dismiss() } }' in clients_swift
             and 'cancelLabel: request.cancelLabel, onSaved: onSaved)' in swift_sources['SessionNotes.swift'],
             'Review 2: each note after a group is linked to the member\'s track and session, and every note is walked through ("Next: …, N of M", Skip for now)')
# Review item 3: Reload refreshes the 4.35.0 fields and orig carries them.
reload_fn = between(edit_sheet, '    private func reload() async {', '\n    }\n')
opened_fn = between(edit_sheet, 'private static func openedValues(of c: ClientInfo, extras: ClientExtras?)', '\n    }\n')
static_check(all(k in reload_fn for k in ('take(&language, opened435[0], freshLang)', 'take(&visionStatus, opened435[1], freshVision)',
                                          'take(&onsetText, opened435[2], freshOnset)', 'storedHoldUntil = freshUntil',
                                          'opened435 = [freshLang, freshVision, freshOnset, freshUntil]', 'baseExtras = ex'))
             and all('"%s":' % k in opened_fn for k in ('preferred_language', 'vision_status', 'vision_onset_age', 'on_hold_until'))
             and 'Self.openedValues(of: base, extras: baseExtras)' in edit_sheet,
             'Review 3: Reload after a conflict refreshes language, vision and the return date, and orig carries them')
# Review item 4: the new buttons are outside the row's one VoiceOver stop.
row_tail = row_fn[row_fn.index('.accessibilityActions {'):]
rotor = row_tail[:row_tail.index('// 4.35.0: outside the row\'s one VoiceOver stop')]
outside = row_tail[len(rotor):]
static_check(all('Label("%s"' % k in outside for k in ('Move session', 'Cancel the series', 'Cancel the whole group', 'Record attendance for the group'))
             and all('Button("%s")' % k in rotor for k in ('Move session', 'Cancel the whole group', 'Record attendance for the group'))
             and '.accessibilityElement(children: VoiceOverRows.children)' not in outside
             and row_fn.index('.accessibilityElement(children: VoiceOverRows.children)') < row_fn.index('let moreLayout'),
             'Review 4: Move session, Cancel the series, Cancel the whole group and Record attendance are reached by swiping (outside the row\'s one stop) and on the rotor')
portal_row = between(portal_swift, '    @ViewBuilder private func row(_ r: PortalRequestItem, statuses: [String]) -> some View {', '\n    }\n')
static_check(portal_row.index('.accessibilityElement(children: VoiceOverRows.children)') < portal_row.index('Label(r.offered.isEmpty ? "Offer times" : "Offer different times"')
             and 'Button(r.offered.isEmpty ? "Offer times" : "Offer different times") { offering = r }' in portal_row
             and '+ r.clientName + ", " + r.typeLabel.lowercased() + " request")' in portal_row,
             'Review 4: Offer times is reached by swiping (after the request\'s one stop), names the client, and stays on the rotor')
summary_stop = row_fn[:row_fn.index('.accessibilityElement(children: VoiceOverRows.children)')]
after_stop = row_fn[row_fn.index('.accessibilityActions {'):]
after_rotor = after_stop[after_stop.index("// 4.35.0: Check in and the outcomes are separate buttons"):]
static_check(all('Label("%s"' % k not in summary_stop for k in ('Check in', 'Mark completed', 'Cancel session', 'No-show'))
             and all(re.search(r'Button \{\s*%s\s*\} label: \{\s*Label\("%s"' % (re.escape(a), l), after_rotor)
                     for a, l in (('Task { await self.checkIn(session) }', 'Check in'), ('markCompleted(session)', 'Mark completed'),
                                  ('pendingOutcome = SessionOutcome(session: session, to: "Cancelled")', 'Cancel session'),
                                  ('pendingOutcome = SessionOutcome(session: session, to: "No Show")', 'No-show')))
             and all(k in after_rotor for k in ('.accessibilityLabel("Check in to \\(name)")', '.accessibilityLabel("Mark \\(name) completed")',
                                                '.accessibilityLabel("Cancel \\(name)")', '.accessibilityLabel("Mark \\(name) as a no-show")'))
             and all('Button("%s")' % k in after_stop[:after_stop.index("// 4.35.0: Check in")] for k in ('Check in', 'Mark completed', 'Cancel session', 'No-show'))
             and '.accessibilityAction { announceRowActions() }' in row_fn,
             'Review 4: Check in, Mark completed, Cancel session and No-show are buttons after the row\'s one VoiceOver stop, each naming the session, also on the rotor; the row\'s double-tap only says how to reach them')
after_links = after_stop[after_stop.index("// 4.35.0: the row's links, also after its one VoiceOver stop"):]
static_check(all(k not in summary_stop for k in ('Link("Join online"', 'Link(destination: url)', 'NavigationLink('))
             and 'Link("Join online", destination: url)' in after_links and '.accessibilityLabel("Join \\(name) online")' in after_links
             and 'Link(destination: url) { Text("\\(location), directions")' in after_links
             and '.accessibilityLabel("Directions to \\(location), for the \\(name)")' in after_links
             and 'NavigationLink(destination: ClientDetailView(clientId: cid))' in after_links
             and ".accessibilityLabel(\"Open \\(session.client)'s record, from the \\(name)\")" in after_links
             and all(k in after_stop[:after_stop.index("// 4.35.0: the row's links")] for k in ('Button("Join online")', 'Button("Directions to \\(location)")', "Button(\"Open \\(session.client)'s record\")")),
             'Review 4: Join online, Directions and Open the client\'s record are links after the row\'s one VoiceOver stop, each naming the session, and stay on the rotor')
# Review items 5-12.
static_check('cacheKey: f.who.map { "clients-who-\\($0)" } ?? "clients")' in clients_list and 'if q.isEmpty && !f.narrows {' in clients_list,
             'Review 5: a chosen caseload has its own saved copy, shown with no signal')
static_check('.interactiveDismissDisabled(busy || hasChanges)' in fu_sheet and '.interactiveDismissDisabled(busy || hasChanges)' in offer_sheet
             and '|| !Calendar.current.isDate(day, inSameDayAs: openedDay)' in group_sheet and 'from: time) != Calendar.current.dateComponents' in group_sheet,
             'Review 6: unsaved follow-up checks and offers are not lost to a swipe; a changed group date or time counts as a change')
static_check('notes.trimmingCharacters(in: .whitespacesAndNewlines).unicodeScalars.count > 1000' in fu_sheet
             and '"Notes are too long (1,000 characters maximum)."' in fu_sheet
             and 'if let text = refusedFields["notes"] { showFormError(text, in: &self.error, field: "Notes") }' in fu_sheet,
             'Review 7: follow-up notes are counted as the server counts them, with its sentence, and errors.notes is tied to Notes')
static_check('"^([0-9]+\\\\.?[0-9]*|\\\\.[0-9]+)$"' in attend_sheet and 'is_numeric($raw)' in (root / 'public/inc/session_group.php').read_text(),
             'Review 8: the group hours accept what the server\'s session_group_hours_input accepts (".5", "3.125")')
static_check('Calendar.current.startOfDay(for: APIDate.parseDay(APIDate.dayCentral(Date())) ?? Date())' in edit_sheet
             and 'in: Self.serverToday..., displayedComponents: .date' in edit_sheet,
             'Review 9: the earliest return date is the server\'s today')
static_check('FormOptions.has("vision_statuses") ? FormOptions.list("vision_statuses", []) : []' in edit_sheet
             and 'visionStatus.isEmpty || list.contains(visionStatus) ? list : list + [visionStatus]' in edit_sheet
             and 'static func has(_ name: String) -> Bool' in api_swift,
             'Review 10: vision choices wait for the organisation\'s list and always keep the client\'s current value')
probe_fn = between(schedule_swift, '    private func probeGroups() async {', '\n    }\n')
static_check('Self.supportsGroups(serverVersion: api.serverVersion)' in probe_fn
             and 'catch APIError.server(let message) where message == "Unknown action." {\n            groupSupported = false' in probe_fn
             and probe_fn.count('groupSupported = false') == 1
             and 'private var groupsOffered: Bool { groupSupported != false }' in schedule_swift
             and 'return major > 4 || (major == 4 && minor >= 35)' in schedule_swift,
             'Review 11: the group buttons hide only for a server before 4.35 (its release, or "Unknown action."), never after another failure')
static_check('showFormError(Self.warningsBanner, in: &self.error)' in group_submit
             and 'Check the warnings below, then turn on the approval and tap Schedule group session again.' in group_sheet
             and 'message + " To book it anyway' not in group_submit,
             'Review 12: the group warnings are read once, in their section; the banner points to them')
# Parity decisions.
static_check(re.search(r'Text\("Yes"\)\.tag\("yes"\)\s*Text\("No"\)\.tag\("no"\)\s*Text\("Not asked"\)\.tag\(""\)', fu_sheet)
             and 'Save the \\(label) for \\(client)? It cannot be recorded again.' in fu_sheet
             and 'Self.surveyQuestion(client: clientName, byPhone: state.byPhone)' in after_section
             and '(byPhone ? " They prefer phone or post' in after_section
             and '"Offer \\(times.count == 1 ? "this time" : "these times") to \\(client): \\(times.map(formatDateTime).joined(separator: "; "))? They choose one in their portal."' in offer_sheet
             and 'Button("Offer these times") { Task { await submit() } }' in offer_sheet and 'Button("Not yet", role: .cancel)' in offer_sheet,
             'Parity: follow-up answers Yes, No, Not asked; the follow-up, survey and offer questions as the Android app asks them')
clients_php = (root / 'public/clients.php').read_text()
static_check('matching clients, in name order. Search by name or narrow the filters to find the rest.' in clients_php
             and 'Showing the first \\(shown) of \\(websiteNumber(all)) matching clients, in name order. Search by name or narrow the filters to find the rest.' in clients_list
             and '[!f.status.isEmpty, !who.isEmpty,' in clients_list
             and 'weekly series? The client is sent a calendar cancellation.' in schedule_swift
             and "weekly series? The client is sent a calendar cancellation." in (root / 'public/schedule.php').read_text(),
             'Parity: the "first N" line is clients.php\'s sentence; the badge counts any caseload but Everyone\'s; cancel-series asks as the website')
schedule_php = (root / 'public/schedule.php').read_text()
static_check(all(label in schedule_php for label in ('Group session instructor', 'Group session date', 'Group session start time',
                                                    'Group session duration (hours)', 'Group session location', 'Group session notes',
                                                    'Group session is remote / telepractice', 'Schedule group session'))
             and all(label in group_sheet for label in ('Picker("Group session instructor"', 'DatePicker("Group session date (required)"',
                                                       'DatePicker("Group session start time (required)"', 'label: "Group session duration (hours) (required)"',
                                                       'label: "Group session location"', 'label: "Group session notes"',
                                                       'Toggle("Group session is remote / telepractice"', '"Schedule group session"'))
             and "old_in('g_time', '10:00')" in schedule_php and 'bySettingHour: 10, minute: 0' in group_sheet,
             'Parity: the group form uses schedule.php\'s labels, button and 10:00 start')
client_php = (root / 'public/client.php').read_text()
static_check(all(l in client_php for l in ('Primary focus / tech recommended', 'Assessment summary', 'Record a tech assessment', 'maxlength="2000"'))
             and 'Picker("Primary focus / tech recommended"' in assess_sheet and 'label: "Assessment summary"' in assess_sheet
             and 'Label("Record a tech assessment"' in edit_sheet
             and 'comments.unicodeScalars.count > 2000' in phone_sheet,
             'Parity: tech assessment labels are client.php\'s; phone survey comments are limited to 2000 characters as the website')
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
