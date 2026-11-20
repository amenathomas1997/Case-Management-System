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
static_check(project_yml.count('MARKETING_VERSION: "4.37.0"') == 2 and project_yml.count('CURRENT_PROJECT_VERSION: "68"') == 2,
             'the app and widget are version 4.37.0 (68)')

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

# 4.36.0: the daily workspace and safety in the iPhone app (plan-4360, IPHONE parts of R1-R26;
# static). Each check reads the request the app sends and the reply keys it reads, and that the
# server files send and read the same keys.
def php(path):
    p = root / 'public' / path
    return p.read_text() if p.exists() else ''


def no_hint(text):
    return '.accessibilityHint(' not in text


def php_case(text, name):
    """One `case 'name':` block of a PHP switch, up to the next case at the same indentation."""
    m = re.search(r"\n([ \t]*)case '%s':" % re.escape(name), text)
    if not m:
        return ''
    rest = text[m.end():]
    nxt = re.search(r"\n%s(?:case '|default:)" % re.escape(m.group(1)), rest)
    return text[m.start():m.end() + (nxt.start() if nxt else len(rest))]


def php_function(text, name):
    """The body of a PHP function, from its signature to the closing brace at the start of a line."""
    i = text.find('function %s(' % name)
    return text[i:text.index('\n}\n', i) + 3] if i >= 0 else ''


def swift_func(text, signature):
    """A Swift function from its signature to its closing brace at the signature's indentation."""
    i = text.index(signature)
    indent = text[text.rindex('\n', 0, i) + 1:i]
    indent = indent[:len(indent) - len(indent.lstrip())]
    return text[i:text.index('\n' + indent + '}\n', i) + len(indent) + 2]


def announced_once(fn):
    """The function says its result once: one announce call (errors go through showFormError)."""
    return len(re.findall(r'\bannounce(?:AfterDismiss)?\(', fn)) == 1


def outside_one_stop(fn, element_open, buttons):
    """`buttons` sit after the block that carries .accessibilityElement (the row's one stop), at
    the same nesting level, and none is inside it (read from the indentation, not text order)."""
    lines = fn.split('\n')
    start = next(i for i, l in enumerate(lines) if element_open in l)
    indent = len(lines[start]) - len(lines[start].lstrip())
    close = next(i for i in range(start + 1, len(lines)) if lines[i].strip() == '}' and len(lines[i]) - len(lines[i].lstrip()) == indent)
    inner = '\n'.join(lines[start + 1:close])
    chain_end = close + 1
    while chain_end < len(lines) and (lines[chain_end].strip().startswith('.') or lines[chain_end].strip().startswith('//')
                                      or len(lines[chain_end]) - len(lines[chain_end].lstrip()) > indent):
        chain_end += 1
    chain = '\n'.join(lines[close:chain_end])
    after = '\n'.join(lines[chain_end:])
    return ('.accessibilityElement(' in chain and 'Button(' not in inner
            and all(b in after for b in buttons))


def model_keys(text, struct):
    """The CodingKeys cases of a top-level struct (private or not), or its stored `let`/`var`
    names when it has no CodingKeys (a synthesized Decodable)."""
    m = re.search(r'\n(?:private )?struct %s: [^\n]*\{\n(.*?)\n\}\n' % re.escape(struct), text, re.S)
    if not m:
        return set()
    body = m.group(1)
    ck = re.search(r'enum CodingKeys: String, CodingKey \{\s*((?:case [^\n]*\n?\s*)+)\}', body)
    if ck:
        return set(k.strip() for k in re.sub(r'\bcase\b', ',', ck.group(1)).replace('\n', ' ').split(',') if k.strip())
    return set(re.findall(r'(?:let|var) (\w+):', body))


api_account_php = php('inc/api_account.php')
api_436_php = php('inc/api_436.php')
records_privacy_php = php('inc/records_privacy.php')
account_swift = swift_sources['AccountView.swift']
incidents_swift = swift_sources['IncidentsView.swift']
dashboard_swift = swift_sources['DashboardView.swift']
tasks_swift = swift_sources['TasksView.swift']
alerts_swift = swift_sources['NotificationsView.swift']
messages_swift = swift_sources['MessagesView.swift']
followups_swift = swift_sources['FollowupsView.swift']
compliance_swift = swift_sources['ComplianceView.swift']
login_swift = swift_sources['LoginView.swift']
agreements_swift = swift_sources['AgreementsView.swift']
training_swift = swift_sources['TrainingView.swift']
search_swift = swift_sources['SearchView.swift']

# R1: phones signed in to the app.
phones = between(account_swift, '@ViewBuilder private func phonesSection(', '} header: { sectionHeader("Phones signed in to the app") }\n        }\n    }')
device_action = swift_func(account_swift, 'private func deviceAction(_ device: AppDevice, lost: Bool) async {')
lost_sheet = between(account_swift, 'private struct ReportLostSheet: View {', '\n}\n')
lost_report = swift_func(lost_sheet, 'private func report() async {')
device_case = php_case(api_account_php, 'device_action')
devices_fn = php_function(api_436_php + api_account_php, 'api_account_devices')
static_check('try await api.call("app_devices")' in account_swift
             and 'var body: [String: Any] = ["device_id": device.id, "lost": lost]' in device_action and 'password' not in device_action
             and 'body["idem_key"] = deviceIdem.key(for: body)' in device_action
             and 'try await api.call("device_action", method: "POST", body: body)' in device_action
             and 'deviceAction(device, lost: false)' in account_swift and 'deviceAction(device, lost: true)' not in account_swift
             and model_keys(account_swift, 'AppDevice') >= {'id', 'name', 'text', 'thisPhone', 'canSignOut', 'canReportLost'}
             and sends(devices_fn, 'id', 'name', 'text', 'this_phone', 'can_sign_out', 'can_report_lost')
             and sends(php_case(api_account_php, 'app_devices'), 'intro', 'devices') and sends(device_case, 'lost', 'message', 'devices')
             and 'app_device_action(' in device_case and 'function app_device_line(' in records_privacy_php,
             'R1: app_devices and device_action are sent and read as those actions answer; plain sign-out sends no password')
static_check('var body: [String: Any] = ["device_id": device.id, "lost": true]' in lost_report
             and lost_report.index('body["idem_key"] = idem.key(for: body)') < lost_report.index('body["password"] = password')
             and lost_report.index('body["password"] = password') < lost_report.index('password = ""') < lost_report.index('try await api.call("device_action"')
             and '"Enter your password to confirm this change. Nothing was changed."' in lost_report
             and '@AppStorage' not in lost_sheet and 'DraftStore' not in lost_sheet
             and 'try await api.call("device_action", method: "POST", body: body, refusal:' in lost_report
             and 'refusedFields["password"] ?? message' in lost_report and 'field: refusedFields["password"] != nil ? "Your password" : nil' in lost_report
             and 'SecureCaptionedField(label: "Your password (required)", text: $password, content: .password)' in lost_sheet
             and '.errorField("Your password")' in lost_sheet and no_hint(lost_sheet)
             and re.search(r"if \(\$lost\) \{\s*\$pwErr = api_account_reauth\(\$userId, \$input\['password'\]", device_case)
             and "'password' => $pwErr" in device_case,
             'R1: Report lost and wipe sends the account password (never kept, not in the retry key), and its refusal is said on that field')
static_check(re.search(r'canSignOut = !thisPhone && ', account_swift) and re.search(r'canReportLost = !thisPhone && ', account_swift)
             and device_case.index("$devId === (int)$tok['id']") < device_case.index('app_device_action(')
             and 'This is the phone you are using. Use Sign out to sign out of it.' in device_case
             and "'can_sign_out' => !$this_phone" in devices_fn and "'can_report_lost' => !$this_phone" in devices_fn,
             'R1: the phone in use is never offered Sign out or Report lost (app and server), and the server refuses it before acting')
static_check(all(t in account_swift for t in ('"Phones signed in to the app"', 'Button("Sign out \\(device.name)")',
                                              'Button("Report lost and wipe \\(device.name)", role: .destructive)',
                                              '"Sign \\($0.name) out of the app?"', 'Button("Sign it out", role: .destructive)',
                                              '"Whoever uses it has to sign in again. Entries not yet sent from it stay on it."',
                                              'Text("Report \\(device.name) lost?")', 'Text(busy ? "Reporting…" : "Report lost and wipe")',
                                              'Entries not yet sent are lost.', '"Not signed in to the mobile app on any phone."',
                                              'Text("Phones signed in to the app: \\(s.appSessions)")',
                                              'Button("Report a privacy incident for \\(lostDeviceName)")',
                                              'PrivacyIncidentPrefill(kind: "Lost or stolen device", what: "\\(lostDeviceName) was lost or stolen.")'))
             and no_hint(phones) and '.accessibilityActions' not in phones
             and outside_one_stop(phones, 'VStack(alignment: .leading, spacing: 2) {', ['Button("Sign out \\(device.name)")', 'Button("Report lost and wipe \\(device.name)"']),
             'R1: the phones section reads as the website (the count on an older server); each phone\'s buttons are outside its one stop, none rotor-only')
static_check(announced_once(device_action) and 'announceAfterDismiss(message)' in account_swift and 'announce(' not in lost_report,
             'R1: a phone\'s sign-out or lost report is said once')

# R2: report a privacy incident.
privacy_sheet = between(incidents_swift, 'struct PrivacyIncidentSheet: View {', '\n}\n')
privacy_save = between(privacy_sheet, 'private func save() async {', '\n    }\n')
static_check('try await api.call("privacy_incident_choices")' in privacy_sheet
             and all(k in privacy_save for k in ('"kind": kind', '"discovered_on": APIDate.day(discovered)',
                                                  '"occurred_on": knowsOccurred ? APIDate.day(occurred) : ""', '"client_id": clientId ?? 0',
                                                  '"description": description', '"info_involved": involved', 'body["idem_key"] = idem.key(for: body)'))
             and 'try await api.call("report_privacy_incident", method: "POST", body: body, refusal:' in privacy_save
             and all(k in privacy_save for k in ('"kind": "Kind"', '"discovered_on": "Date discovered"', '"occurred_on": "Date it happened"',
                                                  '"client_id": "Client affected"', '"description": "What happened"', '"info_involved": "Information involved"'))
             and "'report_privacy_incident' => 'clients'" in api_436_php
             and sends(php_case(api_436_php, 'privacy_incident_choices'), 'kinds', 'clients')
             and sends(php_case(api_436_php, 'report_privacy_incident'), 'notice_deadline', 'message')
             and all("'%s'" % k in php_case(api_436_php, 'report_privacy_incident') for k in ('kind', 'discovered_on', 'occurred_on', 'description', 'info_involved', 'client_id'))
             and 'privacy_incident_report(' in php('privacy_incidents.php'),
             'R2: the privacy incident report sends the website\'s fields and ties each refusal to its field')
static_check(all(t in incidents_swift for t in ('"Report a privacy incident"', '"Kind (required)"', 'Text("Choose a kind").tag("")',
                                                '"Date discovered (required)"', '"Date it happened, if known"', 'title: "Client affected, if one", noneLabel: "None"',
                                                'label: "What happened (required)"', 'label: "Information involved"', '"Report incident"',
                                                'An administrator assesses it.', '"Choose what kind of incident it is."', '"Describe what happened."',
                                                '"What happened is too long (5,000 characters maximum)."',
                                                '"Keep the information involved to 500 characters or fewer."'))
             and no_hint(privacy_sheet) and "'Choose what kind of incident it is.'" in records_privacy_php
             and 'if hasTypedText { confirmingDiscard = true } else { dismiss() }' in privacy_sheet
             and '.interactiveDismissDisabled(busy || hasTypedText)' in privacy_sheet
             and '"Discard this privacy incident report? What you typed will be lost."' in privacy_sheet,
             'R2: the privacy incident form has the website\'s fields, order and sentences, with no hint')

# R3: mobile number and alert emails.
mobile = between(account_swift, '@ViewBuilder private func mobileSection(', '} header: { sectionHeader("How alerts reach you") }')
alert_save = between(account_swift, 'private func saveAlertSettings(_ body: [String: Any]) async {', '\n    }\n')
static_check('saveAlertSettings(["mobile": mobileText.trimmingCharacters(in: .whitespacesAndNewlines),' in mobile
             and '"alert_sms": s.smsEnabled == true && alertSmsOn])' in mobile
             and 'saveAlertSettings(["alert_email": alertEmailChoice])' in mobile
             and 'try await api.call("save_alert_settings", method: "POST", body: body, refusal:' in alert_save
             and 'refusedFields["mobile"] != nil ? "Mobile number"' in alert_save and 'refusedFields["alert_email"] != nil ? "Email me about alerts"' in alert_save
             and model_keys(account_swift, 'AccountSummary') >= {'mobile', 'alertSms', 'smsEnabled', 'alertEmail', 'alertEmailChoices', 'calendar', 'meetings'}
             and sends(php_function(api_account_php, 'api_account_alert_fields'), 'mobile', 'alert_sms', 'sms_enabled', 'alert_email')
             and "'alert_email_choices' =>" in php_case(api_account_php, 'account')
             and sends(php_case(api_account_php, 'save_alert_settings'), 'message', 'mobile', 'alert_sms', 'alert_email')
             and "array_key_exists('mobile', $input)" in php_case(api_account_php, 'save_alert_settings')
             and "array_key_exists('alert_email', $input)" in php_case(api_account_php, 'save_alert_settings')
             and "'save_alert_settings'" in api_account_php
             and 'api_account_reauth(' not in between(api_account_php, "case 'save_alert_settings': {", "\n    }\n")
             and 'account_mobile_save(' in php('account.php') and 'account_alert_email_save(' in php('account.php'),
             'R3: save_alert_settings sends only the part saved, with no password, and its refusals are tied to the field')
load_fn = swift_func(account_swift, 'private func load() async {')
static_check('if !alertFieldsSeeded {' in load_fn and 'if body["mobile"] != nil {' in alert_save
             and 'if body["alert_email"] != nil { alertEmailChoice' in alert_save and announced_once(alert_save)
             and 'if let mobileMessage { Text(mobileMessage) }' in account_swift and 'if let alertEmailMessage { Text(alertEmailMessage) }' in account_swift,
             'R3: a reload never replaces what is being typed; a save resets only its own part, and its result is shown and said once')
static_check(all(t in mobile for t in ('"Your mobile number"', 'label: "Mobile number (optional; for urgent alerts by text and test texts)"',
                                       '"Text me when an urgent alert comes in (at most one text every 15 minutes, never with client details)"',
                                       '"Save mobile number"', 'Picker("Email me about alerts"', '"Save alert emails"', '.textContentType(.telephoneNumber)'))
             and '("now", "Urgent ones at once, and daily"), ("daily", "Once a day"), ("off", "Never")' in account_swift
             and no_hint(mobile) and '.onChange' not in mobile,
             'R3: the mobile and alert sections use the website\'s labels, a quiet picker and no hint')

# R5: calendar and online meetings, read-only.
connections = between(account_swift, '@ViewBuilder private func connectionsSection(', '} header: { sectionHeader("Calendar and online meetings") }')
body_order = between(account_swift, 'accountSection(summary)\n', 'passkeySection(summary)')
static_check([l.strip() for l in body_order.split('\n') if l.strip() and not l.strip().startswith('//')][1:] ==
             ['passwordSection(summary)', 'twoStepSection(summary)', 'mobileSection(summary)', 'alertEmailSection(summary)',
              'if summary.canSchedule { calendarSection(summary) }', 'connectionsSection(summary)', 'availabilitySection',
              'phonesSection(summary)', 'passkeySection(summary)'],
             'Parity: My account sections follow account.php top to bottom')
static_check('let rows = (s.meetings ?? []).map(\\.text) + (s.calendar.map { [$0.text] } ?? [])' in connections
             and '"account.php#calendar-h"' in connections and '"account.php#meetings-h"' in connections and 'hint: nil' in connections
             and 'text' in model_keys(account_swift, 'AccountCalendarStatus') and 'text' in model_keys(account_swift, 'AccountMeetingStatus')
             and sends(php('inc/account_actions.php'), 'calendar', 'meetings', 'text', 'provider', 'state')
             and 'account_connections(' in php('account.php'),
             'R5: Calendar and online meetings shows the server\'s sentences and links to the website')

# R6: My availability.
availability = between(account_swift, 'struct AvailabilityView: View {', '\n}\n')
avail_add = between(availability, 'private func add() async {', '\n    }\n')
static_check('try await api.call("availability")' in availability
             and '["weekday": weekday, "from": hhmm(from), "to": hhmm(to), "mode": mode,' in avail_add
             and '"location": inPerson ? location.trimmingCharacters(in: .whitespacesAndNewlines) : ""' in avail_add
             and 'body["idem_key"] = idem.key(for: body)' in avail_add and 'f.dateFormat = "HH:mm"' in availability
             and 'try await api.call("add_availability", method: "POST", body: body, refusal:' in avail_add
             and '["weekday": "Day", "from": "From", "to": "To", "location": "Where"]' in avail_add
             and 'try await api.call("remove_availability", method: "POST", body: ["id": block.id])' in availability
             and sends(php_case(api_436_php, 'availability'), 'intro', 'hours', 'days')
             and sends(php_case(api_436_php, 'add_availability'), 'message', 'hours') and sends(php_case(api_436_php, 'remove_availability'), 'message', 'hours')
             and sends(php_function(api_436_php, 'api_436_hours'), 'id', 'text')
             and all("'%s'" % k in php_case(api_436_php, 'add_availability') for k in ('weekday', 'from', 'to', 'mode', 'location'))
             and "'availability_add_for(" not in availability and 'availability_add_for(' in php('availability.php') and 'availability_remove(' in php('availability.php'),
             'R6: availability, add_availability (HH:mm times, retry key) and remove_availability are sent as the server reads them')
static_check(all(t in availability for t in ('Picker("Day"', 'DatePicker("From"', 'DatePicker("To"', 'Picker("How"', 'Text("Online").tag("online")',
                                             'Text("In person").tag("in_person")', 'label: "Where, for in person (required)"', '"Add these hours"',
                                             'AvailabilityView.time(9)', 'AvailabilityView.time(12)', 'if let result { Section { Text(result) } }',
                                             '"Hours (\\(hours.count))"', 'Text("None yet.")', 'Button("Remove \\(block.text)", role: .destructive)',
                                             '"Remove \\($0.text)?"', 'Button("Keep them", role: .cancel)', '"Sessions already booked in them stay booked."',
                                             '.navigationTitle("My availability")'))
             and 'NavigationLink { AvailabilityView() } label: { Text("My availability") }' in account_swift and no_hint(availability),
             'R6: My availability has the website\'s fields, steps and confirmation')
static_check(announced_once(swift_func(availability, 'private func add() async {')) and announced_once(swift_func(availability, 'private func remove(_ block: AvailabilityBlock) async {'))
             and 'hhmm(from)' in avail_add and 'from = ' not in avail_add,
             'R6: adding or removing hours is said once and shown; From and To stay as chosen after adding')

# R7: sign-in help.
api_auth_at = api_php.index('Authentication for everything else') if 'Authentication for everything else' in api_php else -1
static_check('parts.queryItems = [URLQueryItem(name: "action", value: "sign_in_options")]' in login_swift
             and 'struct SignInOptions: Decodable { let ok: Bool; let microsoft: Bool }' in login_swift
             and 'microsoftOnWebsite = options?.ok == true && options?.microsoft == true' in login_swift
             and 'Authorization' not in swift_func(login_swift, 'private func loadSignInOptions() async {')
             and '"Signed in with Microsoft on the website? Set a password with Forgot your password first."' in login_swift
             and "'sign_in_options'" in api_php and 0 <= api_php.index("'sign_in_options'") < api_auth_at
             and "'microsoft' =>" in api_php
             and '"This server does not offer privacy incident reports from the app yet."' in incidents_swift
             and '"This server does not offer My availability in the app yet. Use My availability on the website."' in account_swift
             and all(t in tasks_swift for t in ('"overdue": "overdue"', '"today": "due today"', '"week": "due in the next 7 days"', '"none": "with no due date"'))
             and '.task(id: serverAddress) {' in login_swift and 'API.apiURL(fromAddress: serverAddress)' in login_swift,
             'R7: the sign-in step asks sign_in_options (before the token check) and tells a Microsoft user how to get a password')

# R9-R12: dashboard.
# The dashboard case adds its 4.36.0 parts from api_dashboard_436() (api.php), read here with it.
dashboard_case = api_case('dashboard') + (between(api_php, 'function api_dashboard_436(', '\n}\n')
                                          if 'function api_dashboard_436(' in api_php and 'api_dashboard_436(' in api_case('dashboard') else '')
static_check(model_keys(api_swift, 'DashboardData') >= {'today', 'todayHeading', 'tasks', 'weekText', 'agreementsNeeded', 'trainingNeeded', 'credentialsDue'}
             and model_keys(api_swift, 'DashboardTodayItem') >= {'kind', 'count', 'text', 'sessions'}
             and model_keys(api_swift, 'DashboardTasks') >= {'items', 'total', 'heading'} and 'line' in model_keys(api_swift, 'DashboardTask')
             and 'clientId' in model_keys(api_swift, 'ExpiringTrack')
             and sends(dashboard_case, 'today', 'today_heading', 'tasks', 'week_text', 'agreements_needed', 'training_needed', 'credentials_due',
                       'kind', 'count', 'text', 'sessions', 'items', 'total', 'heading', 'line', 'name', 'words')
             and 'c.id AS client_id' in dashboard_case
             and 'dashboard_today(' in dashboard_case and 'dashboard_my_tasks(' in dashboard_case
             and 'dashboard_today(' in php('index.php') and 'dashboard_my_tasks(' in php('index.php'),
             'R9-R12: the dashboard reads today, today_heading, tasks, week_text, the banners and client_id as the server sends them')
static_check(all(t in dashboard_swift for t in ('TasksView(due: "overdue")', 'TasksView(due: "today")', 'case "sessions":', 'case "messages":',
                                                'case "followups":', 'case "portal_requests":', 'Text("Nothing due today.")', '"Open the task list"',
                                                'data.weekText', 'credentialsDue', 'NavigationLink { AgreementsView() } label: { dashboardRow("My agreements") }',
                                                'NavigationLink { TrainingView() } label: { dashboardRow("My training") }',
                                                '"A credential to renew"', '"\\(creds.count) credentials to renew"', '"Please read it and answer the questions."',
                                                '"An agreement to sign"', '"\\(n) agreements to sign"', '"Please read and sign it."', '"Please read and sign them."',
                                                '"Training to take"', '"\\(n) trainings to take"', 'list.map { $0.name + ", " + $0.words }.joined(separator: "; ") + "."',
                                                'if let clientId = track.clientId, clientId > 0 {'))
             and not re.search(r'NavigationLink \{ AgreementsView\(\) \} label: \{\s*Text\("Client records open once you finish', dashboard_swift),
             'R9-R12: Today, My tasks, Your week and the banners open their screens; the hold banner is words plus two links; expiring rows open the client')
index_php = php('index.php')
static_check(all(t in index_php for t in ("'A credential to renew'", "' credentials to renew'", "'Training to take'", "' trainings to take'",
                                          'Please read it and answer the questions.', "'An agreement to sign'", "' agreements to sign'", 'Please read and sign')),
             'R11: the banner headings and sentences are index.php\'s')

# R13-R15: tasks.
row_buttons = between(tasks_swift, '@ViewBuilder private func rowButtonList(_ task: TaskItem) -> some View {', '\n    }\n')
rotor_part = between(tasks_swift, '.accessibilityActions {\n                if !queuedTasks.contains(task.id) {', '\n            }\n')
bulk = between(tasks_swift, 'struct BulkCompleteSheet: View {', '\n}\n')
static_check('if wantsDue != "any" { query["due"] = wantsDue }' in tasks_swift and 'Picker("Filter: due date", selection: $due)' in tasks_swift
             and all(l in tasks_swift for l in ('("any", "Any due date")', '("overdue", "Overdue")', '("today", "Due today (open)")',
                                                 '("week", "Next 7 days (open)")', '("none", "No due date")'))
             and '"Showing the first 300 matches. Narrow your filters to find more specific tasks."' in tasks_swift
             and '"No tasks match these filters."' in tasks_swift
             and all("'%s'" % v in php('inc/task_filters.php') for v in ('overdue', 'today', 'week', 'none')),
             'R13: the due filter sends the website\'s values (task_filters) with its labels and sentences')
row_fn = swift_func(tasks_swift, '@ViewBuilder private func row(_ task: TaskItem) -> some View {')
static_check(all('Button("%s"' % b in row_buttons for b in ('Open client record', 'Open referral', 'Edit', 'Delete'))
             and outside_one_stop(row_fn, '            VStack(alignment: .leading, spacing: 4) {', ['rowButtons(task)'])
             and 'Button("Open referral")' in rotor_part and 'Button("Open client record")' in rotor_part
             and tasks_swift.index('rowButtons(task)') > tasks_swift.index('.accessibilityActions {\n                if !queuedTasks'),
             'R14: Open client record, Open referral, Edit and Delete are visible buttons outside the row\'s one stop (and on the rotor)')
static_check('try await api.call("delete_task", method: "POST", body: ["id": task.id])' in tasks_swift
             and '"Delete the task “\\($0.title)” permanently?"' in tasks_swift and 'Button("Keep it", role: .cancel)' in tasks_swift
             and 'var body: [String: Any] = ["task_ids": chosenIds]' in bulk
             and 'private var chosenIds: [Int] { tasks.map(\\.id).filter { chosen.contains($0) } }' in bulk
             and '@State private var tasks: [TaskItem]' in bulk and '_tasks = State(initialValue: tasks)' in bulk
             and 'bulkTasks = openShown' in tasks_swift and 'BulkCompleteSheet(tasks: bulkTasks,' in tasks_swift
             and 'tasks = await reload()' in bulk and 'if tasks.isEmpty { Text("No open tasks.") }' in bulk
             and 'body["idem_key"] = idem.key(for: body)' in bulk and 'try await api.call("complete_tasks", method: "POST", body: body)' in bulk
             and '"Complete the chosen tasks (\\(chosenIds.count))"' in bulk and '"Nothing was completed: choose at least one open task."' in bulk
             and 'Text("Open tasks")' in bulk and '.navigationTitle("Complete several tasks")' in bulk
             and 'canDelete' in tasks_swift and sends(php_case(api_parity_php, 'tasks'), 'can_delete')
             and sends(php_case(api_436_php, 'complete_tasks'), 'completed', 'message') and "$input['task_ids']" in php_case(api_436_php, 'complete_tasks')
             and "$input['id']" in php_case(api_436_php, 'delete_task')
             and announced_once(swift_func(tasks_swift, 'private func delete(_ task: TaskItem) async {'))
             and re.search(r"API_ARRAY_INPUT_KEYS[^;]*'task_ids'", api_php)
             and 'task_delete(' in php('tasks.php') and 'tasks_bulk_done(' in php('tasks.php'),
             'R15: delete_task (asked first) and complete_tasks (task_ids, retry key) are sent as the server reads them; the choices are fixed while the sheet is open')

# R16-R17: alerts.
static_check(all(t in alerts_swift for t in ('case "followups.php":', 'case "schedule.php":', 'return .thread(id, query["channel"] == "counselor" ? "counselor" : "client")',
                                             'return .tasks(query["due"] ?? "any")', 'case .tasks(let due): TasksView(due: due)',
                                             'return .referrals', 'case .referrals: ReferralsView()')),
             'R16: alerts open follow-ups, the schedule, one conversation and the filtered task list, as Android does')
static_check('try await api.call("mark_notifications_unread", method: "POST",' in alerts_swift
             and 'body: ["ids": offer.undo.ids, "stamp": offer.undo.stamp]' in alerts_swift
             and 'undo' in model_keys(alerts_swift, 'AlertsMarked') and 'just marked read as unread again' in alerts_swift
             and 'Date().timeIntervalSince(madeAt) < AlertsUndoOffer.lifetime' in alerts_swift
             and sends(php_case(api_parity_php, 'mark_notifications_read'), 'undo', 'ids', 'stamp', 'count')
             and sends(php_case(api_parity_php, 'mark_notifications_unread'), 'restored', 'message')
             and "$input['stamp']" in php_case(api_parity_php, 'mark_notifications_unread') and "$input['ids']" in php_case(api_parity_php, 'mark_notifications_unread')
             and 'alerts_mark_read(' in php('notifications.php') and 'alerts_undo(' in php('notifications.php'),
             'R17: the undo sends the batch\'s ids and stamp from the server\'s undo, for 15 minutes')
static_check('static let lifetime: TimeInterval = 15 * 60' in alerts_swift and '.task(id: undoOffer?.madeAt) {' in alerts_swift
             and 'try? await Task.sleep(nanoseconds: UInt64(offer.secondsLeft * 1_000_000_000))' in alerts_swift
             and 'if phase == .active, let offer = undoOffer, !offer.stillOffered { undoOffer = nil }' in alerts_swift
             and 'if let undoOffer, undoOffer.stillOffered {' in alerts_swift
             and announced_once(swift_func(alerts_swift, 'private func undoMarkRead(_ offer: AlertsUndoOffer) async {')),
             'R17: the undo button goes at 15 minutes (a timer, and a check on returning to the app); its result is said once')

# R18-R19: messages.
static_check({'lastBody', 'lastSenderLabel'} <= model_keys(messages_swift, 'MessageThreadSummary')
             and {'total', 'unreadTotal'} <= model_keys(messages_swift, 'MessageThreadSummaries')
             and 'unread first, then the most recent' in messages_swift and '"\\(unreadTotal) unread."' in messages_swift
             and sends(api_case('message_threads'), 'last_body', 'last_sender_label', 'total', 'unread_total')
             and '$totalUnread ?> unread.' in php('messages.php'),
             'R18: the conversation list reads the preview and totals the server sends')
static_check('noticed' in model_keys(messages_swift, 'PostMessageResponse')
             and '" They were emailed that a message is waiting."' in messages_swift
             and 'counselorUnconfirmed' in api_swift and 'r.counselorUnconfirmed == true' in messages_swift
             and sends(api_case('post_message'), 'noticed') and sends(api_case('message_thread'), 'counselor_unconfirmed', 'counselor_unconfirmed_text'),
             'R19: a sent message says they were emailed; the counselor-unconfirmed sentence shows before typing')

# R20: device follow-ups.
static_check(all(t in followups_swift for t in ('"Due now"', '"Upcoming"', '"Flagged abandoned"', 'answered yes or no', 'f.outcome',
                                                '"Recently recorded (\\(d.recent.count))"', '"\\(f.clientName), \\(f.equipmentName), \\(f.intervalDays)-day: \\(outcome)"'))
             and {'clientReported', 'outcome'} <= model_keys(api_swift, 'Followup')
             and 'let dueTotal: Int?' in api_swift and 'let upcomingTotal: Int?' in api_swift
             and sends(api_case('followups'), 'due_total', 'upcoming_total', 'client_reported', 'outcome')
             and re.search(r'followup_outcome_(?:text|html)\(', php('followups.php'))
             and 'function followup_outcome_text(' in php('inc/device_followups.php'),
             'R20: the follow-up tiles and Recently recorded rows use the website\'s words and the server\'s totals and outcomes')

# R22-R23: compliance.
compliance_case = api_case('compliance')
compliance_php_inc = php('inc/compliance_data.php')
static_check(all(t in compliance_swift for t in ('"Releases of information (', '"Privacy notice not recorded (', '"Overdue equipment loans (',
                                                 '"Device follow-ups due (', '"Contracts at risk (', '"Equipment service due (', '"Consents to renew',
                                                 '"Minors and Pre-ETS students with no guardian consent requested (', '"Expiring, expired or withdrawn (',
                                                 '"No release or consent on file (', '"Progress reports due (',
                                                 '"Nothing needs attention right now."', '"All caught up."', '"No service due."', '"No follow-ups are due."',
                                                 '"No loans are overdue."', '"No consents need attention."', '"No releases need attention."',
                                                 'expired > 0 ? ", \\(expired) expired" : ""'))
             and all(compliance_swift.index(a) < compliance_swift.index(b) for a, b in (
                 ('"Authorizations at risk', '"Progress reports due'), ('"Progress reports due', '"Equipment service due'),
                 ('"Equipment service due', '"Device follow-ups due'), ('"Device follow-ups due', '"Overdue equipment loans'),
                 ('"Overdue equipment loans', '"Contracts at risk'), ('consentsSection\n', '"Releases of information'),
                 ('"Releases of information', '"Privacy notice not recorded')))
             and 'compliance_data(' in php('compliance.php') and ('compliance_data(' in compliance_case or 'compliance_api_reply(' in compliance_case)
             and 'track_rollup(' not in compliance_case
             and all("'%s' =>" % k in compliance_php_inc or "$out['%s'] =" % k in compliance_php_inc for k in ('followups_due', 'followups_due_total', 'loans_overdue', 'contracts_risk', 'consents', 'releases_risk',
                       'no_release', 'minors_no_consent', 'no_notice', 'counts', 'validity_days', 'client_id', 'funds_used', 'authorized_funds', 'text')),
             'R22: Compliance shows every website section in its order, from the keys the server sends; the API holds no copy')
static_check('    struct ComplianceRows<Row: Decodable>: Decodable {' in between(compliance_swift, 'struct ComplianceResponse: Decodable {', '\n}\n')
             and 'if let row = try? list.decode(Row.self) { out.append(row) } else { _ = try? list.decode(Skip.self) }' in compliance_swift
             and all('ComplianceRows<%s>' % t in compliance_swift for t in ('ComplianceAuth', 'ComplianceReport', 'ComplianceFollowupDue', 'ComplianceLoanOverdue',
                                                                             'ComplianceContractRisk', 'ConsentDueItem', 'ComplianceReleaseRisk', 'ComplianceClientRow'))
             and '"Showing the first \\(fu.count) of \\(total) checks due, oldest first."' in compliance_swift
             and "Send the renewal from the client's page." in compliance_swift
             and 'checks due, oldest first.' in php('compliance.php') and "Send the renewal from the client's page." in php('compliance.php'),
             'R22: each compliance section decodes row by row (one odd row never blanks the screen); the cut-off and consents lines are compliance.php\'s')
static_check('struct TrackByIdView: View {' in search_swift and 'private struct TrackByIdView' not in search_swift
             and 'TrackByIdView(trackId: trackId, clientId: clientId)' in compliance_swift
             and 'EquipmentDetailView(itemId: l.id, itemName: l.name)' in compliance_swift and 'ContractDetail(id: c.id' in compliance_swift
             and 'ClientDetailView(clientId: clientId)' in compliance_swift
             and '"Funds: \\(money(used)) of \\(money(authorized)) (\\(pct)%\\(pct >= 100 ? ", used up" : "")), \\(money(remaining)) remaining"' in compliance_swift,
             'R23: compliance rows open their track, item, contract or client; the funds line is the website\'s')

# R24-R26: agreements, training, credentials.
static_check('c["corrections_text"] as? String' in agreements_swift
             and re.search(r'if let sid = a\["signature_id"\] as\? Int \{\s*NavigationLink\("Read your signed copy"\)', agreements_swift)
             and "'corrections_text' =>" in php('inc/api_more.php') and 'staff_agreement_corrections_text(' in php('agreements.php'),
             'R24: the signed copy is offered whenever there is a signature, with the corrections note')
static_check('($0["state"] as? String) != "done"' in training_swift,
             'R25: "N of M to take" counts every module not done, as the website')
cred_save = between(training_swift, 'private func save() async {\n        let name = type', '\n    }\n')
static_check(all(k in cred_save for k in ('"type": type', '"other_name": type == "Other" ? name : ""', '"issued_on": issuedOn', '"expires_on": expiresOn',
                                          '"notes": notes.trimmingCharacters(in: .whitespacesAndNewlines)', 'if id == 0 { body["idem_key"] = idem.key(for: body) }',
                                          'try await api.call("save_credential", method: "POST", body: body, refusal:'))
             and 'label: "Notes, optional"' in training_swift
             and all(t in training_swift for t in ('"Date issued: enter the date issued as a real date, not in the future."',
                                                   '"Expires on: enter the expiry date as a real date, or leave it empty if it does not expire."',
                                                   '"Expires on: the expiry date is before the date issued."'))
             and '["type": "Credential", "other_name": "Its name", "issued_on": "Date issued",' in training_swift
             and all("'%s'" % k in php('inc/staff_credentials.php') for k in ('type', 'other_name', 'issued_on', 'expires_on', 'number', 'issuer', 'notes'))
             and 'staff_credential_input(' in php('credentials.php') and 'staff_credential_input(' in php('inc/api_more.php'),
             'R26: a credential sends type, other_name and notes with a retry key, checks dates as the website, and ties refusals to fields')

# 4.37.0: services, equipment and reports in the iPhone app (plan-4370, IPHONE parts of S1-S11,
# S13-S19, S21-S26; static). Each check reads the request the app sends and the reply keys it
# decodes, and that the server files read and send the same keys; the page calls the shared
# helper the API uses; and the structure the owner's rules need (buttons outside a row's one
# stop, one announcement, confirmations that name the item, no hints in new views).
def struct_keys(text, name):
    """The decoded keys of a struct, one-line or not: its CodingKeys cases, or its stored
    let/var names at the struct's own level (nested types left out)."""
    m = re.search(r'\n(?:private )?struct %s: [^\n{]*\{' % re.escape(name), '\n' + text)
    if not m:
        return set()
    i = m.end() - 1   # in '\n' + text: index of '{' is one more in text
    src = '\n' + text
    depth, j = 0, i
    while True:
        if src[j] == '{':
            depth += 1
        elif src[j] == '}':
            depth -= 1
            if depth == 0:
                break
        j += 1
    body = src[i + 1:j]
    ck = re.search(r'enum CodingKeys: String, CodingKey \{\s*case ([^}]*)\}', body)
    if ck:
        return set(k.strip() for k in re.sub(r'\bcase\b', ',', ck.group(1)).replace('\n', ' ').split(',') if k.strip())
    flat, d = [], 0
    for ch in body:
        if ch == '{':
            d += 1
        elif ch == '}':
            d -= 1
        elif d == 0:
            flat.append(ch)
    return set(re.findall(r'\b(?:let|var) `?(\w+)`?\s*:', ''.join(flat)))


def snake(name):
    return re.sub(r'([A-Z])', lambda m: '_' + m.group(1).lower(), name)


def decodes(text, struct, *keys):
    """The struct decodes every key (camelCase, as convertFromSnakeCase gives them)."""
    return set(keys) <= struct_keys(text, struct)


def reads_input(php_src, *keys):
    """The server source names every key the app sends ($input['k'], $_GET['k'], or a key list
    or reader such as $in('k') the action reads its input through)."""
    return all("'%s'" % k in php_src for k in keys)


def titles_name_item(text, first_words):
    """Every confirmationDialog whose title starts with one of `first_words` interpolates the item."""
    found = re.findall(r'confirmationDialog\(\s*"([^"\n]*(?:\\\([^)]*\)[^"\n]*)*)"', text)
    picked = [f for f in found if any(f.startswith(w) for w in first_words)]
    return bool(picked) and all('\\(' in f for f in picked)


api_437_php = php('inc/api_437.php')
equipment_status_php = php('inc/equipment_status.php')


def act437(name):
    return php_case(api_437_php, name)


def perm437(action, perm):
    return ("'%s' => '%s'" % (action, perm)) in api_437_php


equipment_swift = swift_sources['EquipmentView.swift']
procurement_swift = swift_sources['ProcurementView.swift']
parity_swift = swift_sources['Parity437.swift']
eval_swift = swift_sources['EvalReportView.swift']
letters_swift = swift_sources['LettersView.swift']
accommodations_swift = swift_sources['AccommodationsView.swift']
programs_swift = swift_sources['ProgramsView.swift']
referrals_swift = swift_sources['ReferralsView.swift']
knowledge_swift = swift_sources['KnowledgeView.swift']
upload_swift = swift_sources['UploadDocumentSheet.swift']
documents_swift = swift_sources['DocumentsAndHomework.swift']
sign_swift = swift_sources['DocumentsToSign.swift']
at_swift = swift_sources['ATServicesView.swift']
new_views_437 = {'EvalReportView.swift': eval_swift, 'LettersView.swift': letters_swift, 'Parity437.swift': parity_swift}

static_check(all(no_hint(t) for t in new_views_437.values()),
             '4.37.0: the new views carry no accessibilityHint (hints sit inside the labels)')

# S1: equipment status.
status_section = swift_func(equipment_swift, '@ViewBuilder private func statusSection(_ d: EquipmentDetailResponse) -> some View {')
set_status = swift_func(equipment_swift, 'private func setStatus(_ to: String, confirmRetire: Bool) async {')
static_check(decodes(equipment_swift, 'EquipmentDetailResponse', 'onLoan', 'inWorkflow', 'holds', 'statusActions', 'retireText', 'manageable')
             and decodes(equipment_swift, 'EquipmentStatusReply', 'status', 'message')
             and 'try await api.call("set_equipment_status", method: "POST",' in set_status
             and 'body: ["equipment_id": itemId, "to": to, "confirm_retire": confirmRetire]' in set_status
             and announced_once(set_status) and 'showFormError(error.localizedDescription, in: &self.error)' in set_status
             and all(s in equipment_swift for s in ('case "Repair": return (to, "Mark in repair")', 'case "Available": return (to, "Back in service")',
                                                    'case "Retired": return (to, "Retire \\(name)")'))
             and 'if b.to == "Retired" { confirmingRetire = true } else { Task { await setStatus(b.to, confirmRetire: false) } }' in status_section
             and 'Button("Retire \\(data?.item.name ?? itemName)", role: .destructive) { Task { await setStatus("Retired", confirmRetire: true) } }' in equipment_swift
             and 'Button("Keep \\(data?.item.name ?? itemName) in service", role: .cancel) {}' in equipment_swift
             and titles_name_item(equipment_swift, ['Retire '])
             and 'A retired item can no longer be loaned out or reserved. It returns to service only through a Device reuse intake, which records its inspection, sanitizing and data wipe.' in equipment_swift
             and 'This item is retired. Start a Device reuse intake for it in AT services to put it back into service.' in status_section
             and 'ATReuseModuleView()' in status_section and 'module = r.modules.first { $0.id == "reuse" }' in at_swift
             and perm437('set_equipment_status', 'equipment') and reads_input(act437('set_equipment_status') + equipment_status_php, 'equipment_id', 'to', 'confirm_retire')
             and sends(act437('set_equipment_status'), 'status', 'message', 'item')
             and 'status_actions' in api_case('equipment_detail')
             and sends(api_case('equipment_detail') + equipment_status_php, 'on_loan', 'in_workflow', 'holds', 'status_actions', 'retire_text', 'manageable')
             and 'equipment_set_status(' in php('equipment.php') and 'equipment_retire_preview(' in php('equipment.php')
             and 'equipment_set_status(' in act437('set_equipment_status'),
             'S1: Mark in repair, Back in service and Retire (asked first, naming the item) send set_equipment_status as the server reads it; the page and the API share equipment_set_status()')
static_check('Section {\n                ForEach(buttons, id: \\.to) { b in' in status_section and '.accessibilityElement(' not in status_section
             and 'LabeledContent("Status", value: d.item.status)' in equipment_swift
             and equipment_swift.index('LabeledContent("Status", value: d.item.status)') < equipment_swift.index('statusSection(d)'),
             'S1: the status buttons are separate stops in a section after the status rows, none rotor-only')

# S2: retired items listed under Not available.
item_groups = between(equipment_swift, 'private var itemGroups:', '\n    }\n')
static_check('api.call("equipment", query: ["include_retired": "1"])' in equipment_swift
             and 'retired' in struct_keys(equipment_swift, 'EquipmentItem')
             and 'buckets[3] = buckets[3].filter { !$0.item.retired } + buckets[3].filter { $0.item.retired }' in item_groups
             and 'zip(["Overdue", "On loan", "Available", "Not available"], buckets)' in item_groups
             and '"\\(item.name), retired"' in equipment_swift and 'if item.retired { parts.append("retired") }' in equipment_swift
             and 'Text("Not scheduled (retired)")' in equipment_swift
             and '$0.status != "Retired"' in equipment_swift
             and reads_input(api_case('equipment'), 'include_retired') and "'retired' =>" in api_case('equipment') + equipment_status_php
             and 'equipment_list_status_sql(' in api_case('equipment') and 'equipment_list_status_sql(' in php('equipment.php'),
             'S2: the list asks for retired items, lists them last under "Not available" ending ", retired", and the reservation picker still leaves them out')

# S3: purchase date, useful life and AT type.
add_eq = between(equipment_swift, 'struct AddEquipmentSheet: View {', '\n}\n')
static_check(all(s in add_eq for s in ('Picker("AT Act type", selection: $atType)', 'Text("Guessed from the category").tag("")',
                                       'Toggle("Purchase date, if known", isOn: $knowsPurchaseDate)', '.errorField("Purchase date")',
                                       'label: "Useful life in months (optional; 1 to 600, default 36)"', 'errorName: "Useful life"',
                                       'refusal: { refused = refusalFields($0) }', '"useful_life_months": "Useful life"', '"purchase_date": "Purchase date"'))
             and add_eq.index('"Value in USD') < add_eq.index('"AT Act type"') < add_eq.index('"Purchase date, if known"') < add_eq.index('"Useful life in months')
             and 'var extras: [String: Any] = ["at_type": atType, "purchase_date": purchaseDate]' in equipment_swift
             and 'if !life.isEmpty { extras["useful_life_months"] = life }' in equipment_swift
             and 'FormOptions.list("at_types",' in equipment_swift
             and 'equipment_input(' in api_case('add_equipment') and 'equipment_input(' in php('equipment.php')
             and "'at_types'" in php('inc/api_more.php')
             and all("'%s'" % k in equipment_status_php for k in ('useful_life_months', 'purchase_date', 'at_type')),
             'S3: a new item sends the AT type, purchase date and useful life in the website\'s order, and ties the server\'s equipment_input() refusals to their fields')

# S4: device requests.
close_sheet = between(procurement_swift, 'struct DeviceCloseSheet: View {', '\n}\n')
close_submit = swift_func(close_sheet, 'private func submit() async {')
update_case = api_case('device_request_update')
static_check('notes' in struct_keys(procurement_swift, 'DeviceRequest') and 'Text("Note: \\(n)")' in procurement_swift
             and decodes(procurement_swift, 'DeviceUpdateReply', 'status', 'message', 'notes')
             and 'body: ["id": request.id, "op": "status", "to": to, "notes": reason]' in close_submit
             and 'if reason.unicodeScalars.count > 500 { showFormError("Reason: use 500 characters or fewer.", in: &error, field: "Reason"); return }' in close_submit
             and 'field: refused["notes"] != nil ? "Reason" : nil' in close_submit
             and 'label: "Reason (optional; up to 500 characters)"' in close_sheet and 'Text("It is closed until someone reopens it.")' in close_sheet
             and 'Button("Keep request open")' in close_sheet and '"Close \\(device) as \\(to == "Denied" ? "denied" : "cancelled")?"' in procurement_swift
             and 'reason = request.notes ?? ""' in close_sheet
             and 'Toggle("Also add to the loaner inventory", isOn: $addToInventory)' in procurement_swift
             and 'canEquipment ? ["add_to_inventory": addToInventory] : [:]' in procurement_swift
             and 'onDone(r.message)' in procurement_swift and 'sheetResult = message ?? act.savedMessage' in procurement_swift
             and 'action: "device_letter", query: ["id": String(id)], noun: "letter"' in procurement_swift
             and 'Change the justification on the request, then open the letter again.' in procurement_swift
             and 'Label("Share the \\(noun) (HTML file)"' in parity_swift and 'Label("Copy \\(noun)"' in parity_swift
             and decodes(parity_swift, 'BuiltDocumentReply', 'title', 'text', 'html', 'file', 'releaseWarning')
             and reads_input(update_case, 'add_to_inventory') and 'device_delivery_inventory(' in update_case and 'device_close_note(' in update_case
             and "'message' =>" in update_case
             and perm437('device_letter', 'procurement') and sends(act437('device_letter'), 'title', 'text', 'html', 'file')
             and 'device_delivery_inventory(' in php('procurement.php') and 'device_letter_doc(' in php('procurement.php'),
             'S4: deny and cancel send the reason (500 characters), delivery offers the loaner inventory with the equipment permission, and the justification letter is shared as the website\'s HTML')

# S5: Draft justification with AI.
new_request = between(procurement_swift, 'struct DeviceNewSheet: View {', '\n}\n')
draft_just = swift_func(new_request, 'private func draftJustification() async {')
static_check('Button(drafting ? "Drafting…" : "Draft justification with AI")' in new_request
             and 'api.aiHas("draft") && selectedClient != nil && !deviceName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty' in new_request
             and '"type": "justification", "client_id": cid, "device_name": deviceName' in draft_just and '"category": category, "text": justification' in draft_just
             and decodes(procurement_swift, 'JustificationDraftReply', 'text', 'draft', 'truncated', 'shortened')
             and 'Justification drafted. Review and edit it before creating the request.' in draft_just
             and 'justification = mergeDraft(justification, r.draft ?? r.text)' in draft_just and 'aiDraftIncomplete' in draft_just
             and 'justification =' not in draft_just.replace('justification = mergeDraft(justification, r.draft ?? r.text)', '')
             and '.accessibilityLabel("Justification of need")\n                        .disabled(drafting)' in new_request
             and len(re.findall(r'\bannounce\(', draft_just)) == 2 and 'announce("Drafting…")' in draft_just
             and 'Section("Justification of need")' in new_request
             and 'Justification of need: use 4,000 characters or fewer (you entered' in new_request
             and "'justification'" in api_case('ai_draft') and 'device_justification_ai(' in api_case('ai_draft')
             and 'device_justification_ai(' in php('procurement.php') and 'device_justification_ai(' in php('recommend.php')
             and 'device_request_input(' in api_case('add_device_request'),
             'S5: Draft justification with AI sends the typed fields as ai_draft justification, keeps typed text and says "Drafting…" and the result once; the website shares device_justification_ai()')

# S6: recommendations.
ai_swift = swift_sources['AIToolsView.swift']
recommend_view = between(ai_swift, 'struct AIRecommendView: View {', '\n}\n')
static_check('Label("Start a device request for \\(clientName.isEmpty ? "this client" : clientName)"' in recommend_view
             and 'if api.can("procurement")' in recommend_view
             and 'DeviceNewSheet(client: clientId, justification: startRequestPrefill(result ?? ""))' in recommend_view
             and 'Review the recommendations, then start a device request for any you want to pursue.' in ai_swift
             and 'guard scalars.count > 4000 else { return t }' in ai_swift and 'incomplete = r.truncated' in recommend_view
             and 'Create a device request on the website' not in ai_swift
             and 'recommend_context(' in api_case('ai_recommend') and 'Current AT:' not in api_case('ai_recommend')
             and 'recommend_context(' in php('recommend.php'),
             'S6: the recommendations offer "Start a device request for <client>" with the text prefilled (4,000 characters); the API uses recommend.php\'s recommend_context()')

# S7: the formal AT evaluation report.
eval_send = swift_func(eval_swift, 'private func send() async {')
eval_draft = swift_func(eval_swift, 'private func draft() async {')
static_check(all(s in eval_swift for s in ('try await api.call("eval_report", query: ["assessment_id": String(assessmentId)])',
                                           'try await api.call("ai_eval_draft", method: "POST"', '"sections": evalSectionsBody(texts)',
                                           'Button(drafting ? "Drafting…" : "Draft narrative with AI")',
                                           'Button(sending ? "Sending…" : "Email signed report to counselor")',
                                           'label: "Email to (required to email)"', 'Electronic signature: type your full name (\\(reply.signer ?? api.userName)) (required to email)',
                                           'Button("Email signed report") { Task { await send() } }', 'Text("It is signed by you and logged on the client\'s file.")',
                                           '"Email the signed evaluation report to \\(to.trimmingCharacters(in: .whitespacesAndNewlines))?"',
                                           '“Draft narrative with AI” fills only the sections above that are empty or still hold the prefilled text; a section you have written in is kept as it is. To have it drafted, clear it first. Review and edit every section before sending.',
                                           'Button(sharing ? "Preparing…" : "Share accessible HTML")', '"html": true]',
                                           '("background", "Background"), ("findings", "Evaluation findings")',
                                           '("recommendations", "Recommendations"), ("justification", "Justification of need")'))
             and 'try await api.call("send_eval_report", method: "POST", body: body, refusal: { refused = refusalFields($0) })' in eval_send
             and eval_send.index('body["idem_key"] = idem.key(for: body)') < eval_send.index('api.call("send_eval_report"')
             and 'onSent(r.message' in eval_send and 'announce(' not in eval_send and 'idem.failed(error)' in eval_send
             and len(re.findall(r'\bannounce\(', eval_draft)) == 2
             and decodes(eval_swift, 'EvalReportReply', 'assessment', 'sections', 'to', 'counselorVerified', 'signer', 'releaseWarning', 'ai')
             and decodes(eval_swift, 'EvalSection', 'key', 'label', 'text', 'default')
             and decodes(eval_swift, 'EvalDraftReply', 'sections', 'message', 'truncated')
             and perm437('eval_report', 'reports') and perm437('ai_eval_draft', 'reports') and perm437('send_eval_report', 'reports')
             and sends(act437('eval_report'), 'assessment', 'sections', 'to', 'counselor_verified', 'signer', 'release_warning', 'ai', 'html', 'file')
             and reads_input(act437('send_eval_report') + php_function(api_437_php, 'api_437_eval_posted') + php_function(api_437_php, 'api_437_eval_load'), 'assessment_id', 'sections', 'to', 'signature')
             and 'eval_send(' in act437('send_eval_report') and 'eval_ai_draft(' in act437('ai_eval_draft')
             and 'eval_ai_draft(' in php('eval_report.php') and 'eval_send(' in php('eval_report.php')
             and "'send_eval_report'" in api_php[api_php.index('const API_IDEM_ACTIONS'):api_php.index(';', api_php.index('const API_IDEM_ACTIONS'))],
             'S7: the evaluation report loads, drafts, emails (asked first, naming the recipient, with a retry key) and shares as eval_report.php; the page and the API share eval_send() and eval_ai_draft()')

# S8: worksite AI and the recommendation report.
worksite_draft = swift_func(accommodations_swift, 'private func draftRecommendations() async {')
static_check('Button(drafting ? "Drafting…" : "Draft recommendations with AI")' in accommodations_swift
             and 'try await api.call("ai_worksite", method: "POST", body: worksiteAIBody(' in worksite_draft
             and '["id": id, "job_title": jobTitle, "job_tasks": jobTasks, "barriers": barriers, "recommendations": recommendations]' in accommodations_swift
             and 'Recommendations drafted. Read and edit them, then save the assessment.' in worksite_draft
             and len(re.findall(r'\bannounce\(', worksite_draft)) == 2 and 'save_worksite' not in worksite_draft
             and 'NavigationLink("Recommendation report")' in accommodations_swift and 'action: "worksite_report", query: ["id": String(id)]' in accommodations_swift
             and 'Save the assessment first so the report includes your changes.' in accommodations_swift
             and decodes(accommodations_swift, 'WorksiteDraftReply', 'text', 'draft', 'truncated', 'message')
             and perm437('ai_worksite', 'clients') and perm437('worksite_report', 'clients')
             and sends(act437('worksite_report'), 'title', 'text', 'html', 'file', 'release_warning')
             and reads_input(act437('ai_worksite'), 'id', 'job_title', 'job_tasks', 'barriers', 'recommendations')
             and 'worksite_report_doc(' in php('accommodations.php') and 'worksite_report_doc(' in act437('worksite_report'),
             'S8: Draft recommendations with AI sends the typed fields and saves nothing; the recommendation report is the website\'s worksite_report_doc()')

# S9: goal suggestions per assessment; Format with AI.
assess_section = between(edit_sheet, 'struct AssessmentsSection: View {', '\nstruct AddAssessmentSheet: View {')
goals_sheet = between(edit_sheet, 'struct GoalSuggestionsSheet: View {', '\n}\n')
format_fn = swift_func(clients_swift, 'private func formatWithAI() async {')
static_check('Label("Suggest goals with AI for the assessment of \\(date)"' in assess_section and 'if api.aiHas("draft")' in assess_section
             and '["type": "goals", "assessment_id": assessmentId, "client_id": clientId]' in goals_sheet
             and all(s in goals_sheet for s in ('caption: "AI goal suggestions (draft)"', 'Copy any of these into a training track\'s goal form and edit as needed.',
                                                'Label("Copy suggestions"')) and 'api.copyProtected(text)' in goals_sheet
             and 'Label(formatBusy ? "Formatting…" : "Format with AI"' in clients_swift
             and 'noteFormatBody(clientId: clientId, format: noteFormat, body: body_, sections: sections)' in format_fn
             and '["type": "format", "client_id": clientId, "format": format, "body": body,' in parity_swift
             and 'showFormError("Type the session notes first, then format them.", in: &error, field: "Narrative")' in format_fn
             and 'sections = applyFormatted(sentSections, r.sections)' in format_fn and 'return drafted.isEmpty ? (i < old.count ? old[i] : "") : new[i]' in parity_swift
             and 'Formatted as \\(noteFormat) — review each section, then save.' in format_fn
             and decodes(parity_swift, 'NoteFormatReply', 'sections', 'message')
             and 'LIMIT 8' not in api_case('ai_draft') and 'assessment_goals_ai(' in api_case('ai_draft') and 'note_format_ai(' in api_case('ai_draft')
             and 'case_summary_context(' in php('client.php') and 'assessment_goals_ai(' in php('client.php'),
             'S9: each assessment has "Suggest goals with AI for the assessment of <date>"; the note editor\'s Format with AI fills only the sections the AI gives; the API uses client.php\'s helpers')

# S7/S9/S11 structure: the assessment buttons sit after the row's one stop, never inside it.
assess_row = swift_func(assess_section, '@ViewBuilder private func assessmentRow(_ a: Assessment) -> some View {')
assess_buttons = swift_func(assess_section, '@ViewBuilder private func assessmentButtons(_ a: Assessment) -> some View {')
static_check('.accessibilityElement(children: .ignore)' in assess_row
             and not any(s in assess_row for s in ('Evaluation report for', 'Suggest goals with AI', 'Download attachment'))
             and 'assessmentRow(a)\n                // 4.37.0: each assessment\'s buttons are rows of their own, after its one stop.\n                assessmentButtons(a)' in assess_section
             and all(s in assess_buttons for s in ('Evaluation report for the assessment of \\(date)', 'Suggest goals with AI for the assessment of \\(date)',
                                                    '"Download attachment: \\(name)"'))
             and 'if api.can("reports")' in assess_buttons,
             'S7, S9, S11: the evaluation report, goal suggestions and attachment buttons are rows after the assessment\'s one stop, each naming the assessment')

# S10: record progress per goal from the review.
progress_view = between(ai_swift, 'struct AIProgressReviewView: View {', '\n}\n')
static_check('Reviews recent notes and sessions against each active goal. Record any progress below.' in progress_view
             and 'Section("Record progress")' in progress_view and 'Button("Record progress for \\(String(g.text.prefix(60)))")' in progress_view
             and '"\\(g.text), \\(g.track ?? ""): \\(g.pct ?? 0)% (\\(g.status))"' in ai_swift
             and 'GoalProgressSheet(goal: goal)' in progress_view and 'announceAfterDismiss(recorded)' in progress_view
             and decodes(ai_swift, 'ReviewGoal', 'id', 'text', 'trackId', 'track', 'status', 'pct')
             and decodes(ai_swift, 'ProgressReviewReply', 'text', 'truncated', 'goals')
             and 'Incomplete: this review was cut short, so goals after the last one it mentions were not reviewed.' in ai_swift
             and "'goals' =>" in api_case('ai_progress') and 'goal_progress_record(' in api_case('update_goal')
             and 'goal_progress_record(' in php('progress_review.php')
             and 'onDone(stored.message ??' in swift_sources['TrackDetailView.swift'],
             'S10: the progress review lists each goal with "Record progress for <goal>", recorded through update_goal with the website\'s message')

# S11: assessment templates and attachments.
add_assess = between(edit_sheet, 'struct AddAssessmentSheet: View {', '\n}\n')
static_check('try? await api.call("assessment_templates")' in add_assess and 'Picker("Start from a template", selection: $templateId)' in add_assess
             and 'Text("None").tag(0)' in add_assess and 'if typedTemplateFields { pendingTemplate = chosen } else { applyTemplate(chosen, replacing: true) }' in add_assess
             and '"Replace what you typed with the template \\(pendingTemplate?.name ?? "")?"' in add_assess
             and 'Button("Replace")' in add_assess and 'Button("Keep mine", role: .cancel) { pendingTemplate = nil }' in add_assess
             and 'Label("Report attachment (optional; PDF, Word, text, or image, 15 MB max)"' in add_assess
             and 'Button("Remove attachment")' in add_assess
             and 'body["attachment"] = ["name": attachmentName, "data": attachmentData.base64EncodedString()]' in add_assess
             and add_assess.index('body["attachment"]') < add_assess.index('body["idem_key"] = idem.key(for: body)')
             and '("attachment", "Report attachment")' in add_assess
             and 'Attachment type not allowed. Use PDF, Word, text, or image files.' in edit_sheet and 'Attachment is too large (15 MB maximum).' in edit_sheet
             and 'try await api.call("download_assessment_attachment", query: ["id": String(assessmentId)])' in edit_sheet
             and decodes(edit_sheet, 'AssessmentTemplate', 'id', 'name', 'primaryTech', 'hardwareDetails', 'softwareVersion', 'summary')
             and decodes(edit_sheet, 'AssessmentAttachmentReply', 'name', 'mime', 'data') and 'attachmentName' in struct_keys(edit_sheet, 'Assessment')
             and perm437('assessment_templates', 'clients') and perm437('download_assessment_attachment', 'clients')
             and sends(act437('download_assessment_attachment'), 'name', 'mime', 'data') and "'templates' =>" in act437('assessment_templates')
             and "'attachment'" in api_php[api_php.index('const API_ARRAY_INPUT_KEYS'):api_php.index(';', api_php.index('const API_ARRAY_INPUT_KEYS'))]
             and 'tech_assessment_attachment_store(' in api_case('add_assessment') and 'tech_assessment_attachment_store(' in php('client.php')
             and "'attachment_name'" in api_case('assessments'),
             'S11: the assessment form starts from a template (asking before replacing typed text) and sends the attachment as the server reads it; rows download it')

# S13: the amendment request recorded and its reply.
track_swift = swift_sources['TrackDetailView.swift']
amend_view = between(track_swift, 'struct AmendmentView: View {', '\n}\n')
record_fn = swift_func(amend_view, 'private func recordRequest() async {')
reply_fn = swift_func(amend_view, 'private func recordReply(_ row: AmendmentHistoryRow) async {')
redraft = between(amend_view, '.confirmationDialog("Replace the letter with a new AI draft? Your edits to it will be lost.",', 'Button("Keep my letter", role: .cancel) {}')
static_check(all(s in amend_view for s in ('Section("Record the request as sent")',
                                           'After you send the letter, record it here. It is kept on the track as a case note, and a follow-up task reminds you if no reply comes.',
                                           'DatePicker("Date sent (required)"', 'label: "Sent to"', 'label: "Additional hours requested"',
                                           'label: "Additional dollars requested"', 'Toggle("New end date requested"',
                                           'Button(recording ? "Recording…" : "Record request as sent")', 'Section("Requests and replies (\\(rows.count))")',
                                           'Picker("Reply (required)"', 'Text("Choose the reply").tag("")', 'DatePicker("Date of the reply"',
                                           'Button(replying == row.id ? "Recording…" : "Record reply")', 'Button("Enter the approved change on the track")',
                                           'Section("AI draft")', 'Button("Use the AI draft as the letter")', 'Button("Restore my letter")',
                                           'amendRequest: change.requestId, reasonPrefill: change.reason'))
             and 'try await api.call("record_amendment", method: "POST", body: body, refusal:' in record_fn
             and record_fn.index('body["idem_key"] = recordIdem.key(for: body)') < record_fn.index('api.call("record_amendment"')
             and '"track_id": trackId, "request_id": row.id, "status": replyStatus[row.id] ?? ""' in reply_fn and 'api.call("amendment_reply", method: "POST"' in reply_fn
             and '["track_id": trackId, "requested_on": requestedOn, "sent_to":' in track_swift and '"new_end": newEnd, "letter": letter]' in track_swift
             and 'if edited { body["letter"] = letter }' in amend_view
             and re.search(r'if aiDraftText == nil \{\s*Button\("Replace with a new draft", role: \.destructive\)', redraft)
             and track_swift.count('Replace with a new draft') == 1
             and 'if let amendRequest, trackId != nil { body["amend_request"] = amendRequest }' in track_swift
             and decodes(swift_sources['API.swift'], 'AmendmentResponse', 'history', 'waitingText', 'replyStatuses', 'sentToDefault', 'today', 'aiDraftText')
             and decodes(swift_sources['API.swift'], 'AmendmentHistoryRow', 'id', 'requestedOn', 'status', 'text', 'awaiting', 'needsChange')
             and decodes(track_swift, 'AmendmentReplyResult', 'message', 'needsChange', 'reasonPrefill', 'requestId')
             and perm437('record_amendment', 'clients') and perm437('amendment_reply', 'clients')
             and reads_input(act437('record_amendment') + php('inc/amendments.php'), 'track_id', 'requested_on', 'sent_to', 'hours', 'amount', 'new_end', 'letter')
             and reads_input(act437('amendment_reply') + php('inc/amendments.php'), 'track_id', 'request_id', 'status', 'replied_on', 'note')
             and sends(act437('amendment_reply'), 'message', 'needs_change', 'reason_prefill', 'request_id')
             and sends(api_case('amendment'), 'history', 'waiting_text', 'reply_statuses', 'sent_to_default', 'today', 'ai_draft_text')
             and 'amendment_ai_draft(' in api_case('amendment')
             and 'amendment_ai_draft(' in php('amendment_request.php') and 'amendment_row_text(' in php('amendment_request.php')
             and "'record_amendment'" in api_php[api_php.index('const API_IDEM_ACTIONS'):api_php.index(';', api_php.index('const API_IDEM_ACTIONS'))],
             'S13: the request is recorded as sent (retry key) and its reply recorded; an edited letter keeps its words with the AI draft in a separate box ("Replace with a new draft" only for an older server); the approved change opens the track edit with amend_request')

# S14: letters.
letter_save = swift_func(letters_swift, 'private func save(_ how: String) async {')
letter_draft = swift_func(letters_swift, 'private func draft() async {')
static_check(all(s in letters_swift for s in ('try await api.call("letters", query: query)', 'Picker("Letter", selection: letterKey)',
                                              'Filled in from the record. Check it and change anything before saving; \\"[not on file]\\" marks details the record does not have.',
                                              'label: "Title (optional; blank uses the letter\'s name)"', 'Text("English").tag("en")', 'Text("Spanish").tag("es")',
                                              '"Language of the letter (this client prefers Spanish; the wording below is in English, so choose Spanish if you write it in Spanish)"',
                                              'label: "Letter (required)"', 'Button(drafting ? "Drafting…" : "Draft the letter with AI")',
                                              'Toggle("Let the client read it in their portal", isOn: $toPortal)',
                                              'Button(saving ? "Saving…" : "Save to the client\'s documents") { Task { await save("doc") } }',
                                              'Button("Keep as a case note") { Task { await save("note") } }', 'ShareLink(item: file) { Label("Share the letter"',
                                              'api.copyProtected(letterBody)', 'Label("Copy letter"', '.navigationTitle("Write a letter")',
                                              '"Language of the letter (the wording below is in English)"',
                                              '"Open the \\(name) instead? Your changes to this letter are lost."', 'Button("Open it", role: .destructive)',
                                              'Button("Keep my letter", role: .cancel)', 'Button("Use the AI draft as the letter")'))
             and '["client_id": clientId, "how": how, "key": key, "title": title.trimmingCharacters(in: .whitespacesAndNewlines), "body": body,' in letters_swift
             and 'ShareLink(item: letterBody' not in letters_swift
             and letters_swift.index('Toggle("Let the client read it in their portal"') < letters_swift.index('"Save to the client\'s documents"')
                 < letters_swift.index('Label("Copy letter"') < letters_swift.index('"Draft the letter with AI"')
             and '"lang": lang, "to_portal": how == "doc" && toPortal]' in letters_swift
             and 'try await api.call("save_letter", method: "POST", body: body, refusal:' in letter_save
             and letter_save.index('body["idem_key"] = idem.key(for: body)') < letter_save.index('api.call("save_letter"')
             and 'The letter is empty. Write the letter, or open it again to start from the filled-in wording.' in letter_save
             and '["client_id": clientId, "key": key, "body": letterBody, "edited": wasEdited]' in letter_draft and 'api.call("ai_letter", method: "POST"' in letter_draft
             and 'if r.separate == true || wasEdited {' in letter_draft and len(re.findall(r'\bannounce\(', letter_draft)) == 2
             and decodes(letters_swift, 'LettersReply', 'letters', 'key', 'title', 'body', 'lang', 'prefersSpanish', 'ai')
             and decodes(letters_swift, 'LetterDraftReply', 'text', 'separate', 'message', 'truncated')
             and decodes(letters_swift, 'LetterSaveReply', 'message', 'documentId')
             and 'LetterView(clientId: clientId' in clients_swift and 'Label("Write a letter", systemImage: "envelope.open")' in clients_swift
             and perm437('letters', 'clients') and perm437('ai_letter', 'clients') and perm437('save_letter', 'clients')
             and sends(act437('letters'), 'letters', 'key', 'title', 'body', 'lang', 'prefers_spanish', 'ai')
             and sends(act437('ai_letter'), 'text', 'separate', 'message', 'truncated')
             and reads_input(act437('save_letter') + php('inc/portal_history.php'), 'client_id', 'how', 'title', 'body', 'lang', 'to_portal')
             and 'letter_save(' in act437('save_letter') and 'letter_ai_draft(' in act437('ai_letter')
             and 'letter_ai_draft(' in php('letters.php') and 'letter_save(' in php('letters.php')
             and "'save_letter'" in api_php[api_php.index('const API_IDEM_ACTIONS'):api_php.index(';', api_php.index('const API_IDEM_ACTIONS'))],
             'S14: Write a letter opens the website\'s seven letters, drafts with AI (a separate box once edited), and saves to the documents or as a note with a retry key')

# S15: paper release, withdrawals and the privacy notice.
release_sheet = between(sign_swift, 'struct ReleaseRequestSheet: View {', '\n}\n')
static_check(all(s in release_sheet for s in ('Picker("How", selection: $method)', 'Text("Email it to sign").tag("Email")',
                                              'Text("Record a paper release").tag("Paper")', 'DatePicker("Date signed (paper releases only) (required)"',
                                              '"Record paper release"', 'if method == "Paper" { Task { await send() } } else { confirming = true }'))
             and 'method == "Paper" ? ["method": "Paper", "signed_on": signedOn] : ["method": "Email"]' in sign_swift
             and all(s in clients_swift for s in ('"withdraw_release", ["client_id": clientId, "release_id": r.id]',
                                                  '"withdraw_consent", ["client_id": clientId, "consent_id": id]',
                                                  '"record_privacy_notice", ["client_id": clientId, "how": privacyHow]',
                                                  'Button("Record consent withdrawn", role: .destructive) { confirmingConsentWithdraw = true }',
                                                  'Button("Record withdrawn: \\(r.recipientName)", role: .destructive)',
                                                  '"Record that the release for \\(withdrawingRelease?.recipientName ?? "this recipient") is withdrawn?"',
                                                  'Text("Nothing more is shared under it.")', 'Button("Record withdrawn", role: .destructive)',
                                                  '"Record that \\(name) has withdrawn the guardian consent signed on \\(date)?"',
                                                  'Text("The consent stops counting as current, and a new one can then be requested.")',
                                                  'Section("Privacy notice")', 'Picker("Privacy notice given", selection: $privacyHow)',
                                                  'Text("Choose how").tag("")', 'Button(recordsBusy ? "Recording…" : "Record notice given")'))
             and decodes(swift_sources['API.swift'], 'ClientRelease', 'text', 'canWithdraw', 'withdrawnAt')
             and decodes(swift_sources['API.swift'], 'ClientPrivacyNotice', 'givenAt', 'how', 'text')
             and {'privacyNotice', 'privacyNoticeMethods', 'portalLink'} <= coding_keys(swift_sources['API.swift'], 'ClientDetail')
             and 'signedId' in struct_keys(clients_swift, 'ConsentState')
             and reads_input(php('inc/api_425.php') + records_privacy_php, 'method', 'signed_on')
             and perm437('withdraw_release', 'clients') and perm437('withdraw_consent', 'clients') and perm437('record_privacy_notice', 'clients')
             and reads_input(act437('withdraw_consent'), 'client_id', 'consent_id') and reads_input(act437('withdraw_release'), 'client_id', 'release_id')
             and sends(api_case('client') + records_privacy_php, 'can_withdraw', 'withdrawn_at', 'privacy_notice', 'privacy_notice_methods')
             and "'signed_id' =>" in php('inc/api_parity.php')
             and 'consent_withdraw(' in php('client.php') and 'privacy_notice_record(' in php('client.php'),
             'S15: a paper release is recorded with its date, releases and the guardian consent are recorded withdrawn (asked first, naming them), and the privacy notice is recorded as the website does')
releases_block = between(clients_swift, 'ForEach(detail.releases) { r in', 'if detail.extras.supports425 {')
static_check('Text(r.text ?? r.summary).font(.callout)' in releases_block and '.accessibilityElement(' not in releases_block
             and '.accessibilityActions' not in releases_block,
             'S15: each release\'s withdraw button is a visible stop after its line, none rotor-only')

# S16: documents to the trash, share on upload, no HEIC.
allowed = re.search(r'static let allowedExtensions = \[([^\]]*)\]', upload_swift).group(1)
upload_fn = swift_func(upload_swift, 'private func upload() async {')
trash_fn = swift_func(documents_swift, 'private func moveToTrash(_ document: ClientDocument) async {')
static_check('heic' not in allowed and 'static let pickTypes: [UTType] = importTypes + [.heic, .heif]' in upload_swift
             and 'UIImage(data: data)?.jpegData(compressionQuality: 0.9)' in upload_swift and '+ ".jpg"' in upload_swift
             and 'Toggle("Share it on the client\'s portal", isOn: $sharePortal)' in upload_swift
             and 'Toggle("Share it with their VR counselor on the counselor portal", isOn: $shareCounselor)' in upload_swift
             and '["share_portal": category == "Resource" || portal, "share_counselor": counselor]' in upload_swift
             and 'body.merge(share) { $1 }' in upload_fn and 'keyed.merge(share) { $1 }' in upload_fn
             and 'That file type is not allowed. Use PDF, Office, text, or image files.' in upload_swift
             and 'canDelete' in struct_keys(documents_swift, 'ClientDocument')
             and 'try await api.call("delete_document", method: "POST", body: ["id": document.id])' in trash_fn and announced_once(trash_fn)
             and 'Label(deleting == document.id ? "Moving to the trash…" : "Move \\(document.title) to the trash"' in documents_swift
             and 'confirmationDialog("Move the document \\"\\(trashing?.title ?? "")\\" to the trash?"' in documents_swift
             and 'Button("Move to trash", role: .destructive)' in documents_swift and 'Button("Keep it", role: .cancel)' in documents_swift
             and 'Text("An administrator can restore it for 30 days.")' in documents_swift
             and perm437('delete_document', 'clients') and 'document_delete(' in act437('delete_document')
             and reads_input(api_case('upload_document'), 'share_portal', 'share_counselor') and 'DOCUMENT_UPLOAD_EXTENSIONS' in api_case('upload_document')
             and "'heic'" not in api_case('upload_document') and "'can_delete' =>" in api_case('documents')
             and 'document_delete(' in php('client.php'),
             'S16: HEIC is not sent (a HEIC photo goes as a JPEG), uploads share as client.php\'s ticks, and a document moves to the trash after the website\'s question')

# S17: the portal link's state and turning it off.
static_check('if let link = detail.portalLink, !link.text.isEmpty { Text(link.text) }' in clients_swift
             and 'portalConfirmText(detail.portalLink, fallback:' in clients_swift and 'link?.confirmText' in clients_swift
             and 'if detail.portalLink?.state == "active" {' in clients_swift
             and '.confirmationDialog("Turn off \\(detail.client.name)\'s portal link?", isPresented: $confirmingPortalOff' in clients_swift
             and 'Button("Turn off portal link", role: .destructive) { Task { await revokePortalLink() } }' in clients_swift
             and 'Text("Every link already sent stops working.")' in clients_swift
             and 'await recordWrite("revoke_portal_link", ["client_id": clientId], place: "portal")' in clients_swift
             and decodes(swift_sources['API.swift'], 'PortalLinkState', 'state', 'until', 'text', 'confirmText')
             and perm437('revoke_portal_link', 'clients') and 'portal_link_revoke(' in act437('revoke_portal_link')
             and "'portal_link' =>" in api_case('client') and 'portal_link_sent_message(' in api_case('send_portal_link')
             and 'portal_link_confirm_text(' in php('client.php') + php('inc/client_notices.php'),
             'S17: the portal link\'s state and the website\'s confirmation words are shown, and "Turn off portal link" asks first, naming the client')
record_write = swift_func(clients_swift, 'private func recordWrite(_ action: String, _ body: [String: Any], place: String) async -> Bool {')
privacy_fn = swift_func(clients_swift, 'private func recordPrivacyNotice() async {')
static_check(announced_once(record_write) and 'showActionError(error.localizedDescription, at: place)' in record_write
             and 'showFormError' not in record_write and 'try await api.call(action, method: "POST", body: body)' in record_write
             and '.accessibilityFocused($actionErrorFocus, equals: place)' in clients_swift
             and all('actionErrorRow("%s")' % p in clients_swift for p in ('consent', 'portal', 'release', 'privacy'))
             and all('place: "%s")' % p in clients_swift for p in ('consent', 'portal', 'release', 'privacy'))
             and clients_swift.index('Picker("Privacy notice given"') < clients_swift.index('actionErrorRow("privacy")') < clients_swift.index('"Record notice given"')
             and 'if await recordWrite("record_privacy_notice", ["client_id": clientId, "how": privacyHow], place: "privacy") { privacyHow = "" }' in privacy_fn,
             'S15, S17: each record write says the server\'s sentence once; a refusal is shown and focused beside its action, and the privacy method is cleared only once recorded')

# S18: the review message and the timeline.
review_fn = swift_func(referrals_swift, 'private func review() async {')
timeline_view = between(clients_swift, 'struct ClientTimelineView: View {', '\n}\n')
static_check('let reply: MessageReply = try await api.call("review_referral", body: ["id": referralId])' in review_fn
             and 'confirmation = reply.message ?? "Marked as reviewed."' in review_fn
             and 'reply = try await api.call("client_timeline", query: ["client_id": String(clientId)])' in timeline_view
             and 'Section("Time in each status")' in timeline_view and 'Text("Events (\\(reply.total ?? reply.events.count)), newest first")' in timeline_view
             and 'ForEach(Array(timelineGroups(reply.events).enumerated()), id: \\.offset) { _, group in\n                    Section(group.month) {' in timeline_view
             and decodes(clients_swift, 'ClientTimelineReply', 'statusDays', 'events', 'total')
             and decodes(clients_swift, 'TimelineEvent', 'date', 'month', 'kind', 'text')
             and 'NavigationLink { ClientTimelineView(clientId: clientId, clientName: detail.client.name) }' in clients_swift
             and perm437('client_timeline', 'clients') and sends(act437('client_timeline'), 'status_days', 'events', 'total')
             and sends(api_case('review_referral'), 'changed', 'message') and 'referral_mark_reviewed(' in api_case('review_referral')
             and 'referral_mark_reviewed(' in php('referrals.php'),
             'S18: Mark as reviewed says the server\'s sentence; Timeline shows "Time in each status" and the events under month headings, read-only')

# S19: referral filters and the contact date.
ref_load = swift_func(referrals_swift, 'private func load(announcing: Bool = false) async {')
static_check(all(s in referrals_swift for s in ('Picker("Status", selection: $statusFilter)', 'Picker("Show", selection: $whoFilter)',
                                                '("open", "Open (New or Reviewed)"), ("converted", "Converted"), ("closed", "Closed without a client"), ("all", "All statuses")',
                                                '("all", "All referrals"), ("me", "Assigned to me"), ("unassigned", "Unassigned")',
                                                '["status": status, "who": who]', '@State private var statusFilter = "open"',
                                                'Showing the newest \\(shown) of \\(total) matching referrals. Narrow the filters to find older ones.'))
             and 'api.call("referrals", query: referralsQuery(status: statusFilter, who: whoFilter))' in ref_load
             and announced_once(ref_load) and 'if announcing {' in ref_load
             and 'the rest are on the website\'s Referrals page' not in referrals_swift
             and 'Text(heading).font(.headline)' in referrals_swift and '(\\(total ?? referrals.count))' not in referrals_swift
             and decodes(referrals_swift, 'ReferralRow', 'contactText', 'contactOverdue') and decodes(referrals_swift, 'ReferralsResponse', 'heading')
             and '.onChange(of: statusFilter)' in referrals_swift and '.pickerStyle(' not in referrals_swift
             and reads_input(api_case('referrals'), 'who') and sends(api_case('referrals'), 'heading', 'limit')
             and 'contact_text' in api_case('referrals') + php('inc/referral_request.php')
             and 'referral_who_sql(' in api_case('referrals') and 'referral_who_sql(' in php('referrals.php'),
             'S19: Referrals has the website\'s Status (open first) and Show filters, quiet, says the new count once, and shows each referral\'s contact date')

# S21: Pre-ETS cohorts.
new_cohort = between(programs_swift, 'struct CohortNewSheet: View {', '\n}\n')
cohort_change = swift_func(programs_swift, 'private func cohortChange(_ action: String, _ body: [String: Any]) async {')
static_check(all(s in new_cohort for s in ('label: "Cohort name (required)"', 'label: "School / district"', 'Picker("Coordinator", selection: $coordinatorId)',
                                           'Text("None").tag(0)', 'Toggle("Start date", isOn: $hasStart)', 'Toggle("End date", isOn: $hasEnd)',
                                           'label: "Notes (optional)"', 'Button("Create cohort")', 'try? await api.call("cohort_choices")',
                                           'coordinatorId: coordinators == nil ? nil : coordinatorId', 'Notes: use 2,000 characters or fewer.',
                                           'The end date cannot be before the start date.'))
             and 'if let coordinatorId { body["coordinator_id"] = coordinatorId }' in programs_swift
             and '["name": name, "school": school, "start_date": startDate, "end_date": endDate, "notes": notes]' in programs_swift
             and all(s in programs_swift for s in ('Button("Delete session: \\(s.activity), \\(formatDate(s.sessionDate))", role: .destructive) { deletingSession = s }',
                                                   '"Delete the session \\(activity), \\(date), and its attendance?"', 'Text("Its hours come out of the report.")',
                                                   'Button("Delete session", role: .destructive)', 'Button("Keep it", role: .cancel)',
                                                   'Button(active ? "Archive cohort" : "Reactivate cohort")', 'Text("Active cohorts").tag("active")',
                                                   'Text("Archived cohorts").tag("archived")', '"No archived cohorts." : "No active cohorts."',
                                                   'api.call("cohorts", query: ["show": show])', '"Coordinator: \\(n)"',
                                                   '["cohort_id": id, "session_id": s.id]', '["cohort_id": id, "active": active]'))
             and announced_once(cohort_change)
             and decodes(programs_swift, 'CohortSession', 'text', 'canDelete') and decodes(programs_swift, 'CohortDetailResponse', 'coordinatorName', 'notes', 'active')
             and decodes(programs_swift, 'CohortRow', 'coordinator', 'startDate', 'endDate')
             and perm437('cohort_choices', 'programs') and perm437('delete_cohort_session', 'programs') and perm437('set_cohort_active', 'programs')
             and reads_input(act437('delete_cohort_session') + php('inc/programs.php'), 'cohort_id', 'session_id') and reads_input(act437('set_cohort_active') + php('inc/programs.php'), 'cohort_id', 'active')
             and sends(act437('cohort_choices'), 'coordinators', 'activities')
             and 'cohort_input(' in api_case('add_cohort') and 'cohort_input(' in php('programs.php')
             and '$acts = [' not in api_case('log_cohort_session'),
             'S21: a new cohort has the coordinator, dates and notes; a logged session is deleted after the website\'s question; cohorts are archived or reactivated and listed by Show')
sessions_block = between(programs_swift, 'Section("Recent sessions") {', 'if let active = d.active {')
static_check('.accessibilityElement(children: .combine)\n                            // 4.37.0 (S21): a visible button after the session\'s one stop.\n                            if s.canDelete {' in sessions_block,
             'S21: each session\'s Delete button is its own stop after the session\'s one stop')

# S22: instructor matching.
matching = between(at_swift, 'struct ATMatchingView: View {', '\n}\n')
static_check(all(s in matching for s in ('label: "Expertise needed"', 'label: "Language / communication"', 'label: "Service area"',
                                         'DatePicker("Week starting", selection: $weekStarting, displayedComponents: .date)',
                                         'Picker("Delivery mode", selection: $modality)', '"date": APIDate.day(weekStarting)'))
             and 'estimated hours available of \\(atHoursText(m.capacityHours)) this week.' in at_swift
             and '"Matched: \\(m.matched.isEmpty ? "None specified" : m.matched). Missing: \\(m.missing.isEmpty ? "None" : m.missing)."' in at_swift
             and 'n == 0 ? "No available instructor profiles." : "\\(n) matching instructor\\(n == 1 ? "" : "s")"' in at_swift
             and announced_once(swift_func(matching, 'private func search() async {'))
             and 'Suggestions for the next 7 days' not in at_swift
             and "$need['date']" in php('inc/at_services.php') and "'capacity_hours'" in php('inc/at_services.php'),
             'S22: matching has the website\'s fields with Week starting (sent as date), its row sentences and its count, said once')

# S23: knowledge base general knowledge.
kb_view = between(ai_swift, 'struct KBAskView: View {', '\n}\n')
static_check('Toggle("If no article covers it, answer from general knowledge (marked as not agency procedure)", isOn: $general)' in kb_view
             and 'body: ["question": question, "general": general]' in kb_view and 'steps = (reply["steps"] as? [String]) ?? []' in kb_view
             and 'let message = ([error.localizedDescription] + steps).joined(separator: " ")' in kb_view
             and 'general ? "Answer (general knowledge, not from your articles)" : "Answer"' in ai_swift
             and 'showFormError("Type a question to ask.", in: &error, field: "Your question")' in kb_view
             and decodes(ai_swift, 'KBAnswerReply', 'text', 'sources', 'general')
             and reads_input(api_case('ai_kb'), 'general') and "'steps' =>" in api_case('ai_kb') and 'kb_general_answer(' in api_case('ai_kb')
             and 'kb_general_answer(' in php('kb_ask.php'),
             'S23: the knowledge-base assistant can answer from general knowledge (captioned so), and a question no article covers says the website\'s three steps as one message')

# S24: translate into another language.
translate_view = between(ai_swift, 'struct TranslateView: View {', '\n}\n')
static_check('static let other = "Other (type below)"' in translate_view and 'Text(Self.other).tag(Self.other)' in translate_view
             and 'label: "Other language (letters, spaces, hyphens, apostrophes, periods and parentheses; up to 40 characters)"' in translate_view
             and 'errorName: "Other language"' in translate_view and '.errorField("Text to translate")' in translate_view
             and 'choice == TranslateView.other ? typed.trimmingCharacters(in: .whitespacesAndNewlines) : choice' in ai_swift
             and all(s in ai_swift for s in ('"Paste the text to translate."', '"Type the language to translate into."',
                                              '"The language name can use only letters, spaces, hyphens, apostrophes, periods and parentheses (40 characters maximum)."',
                                              'content.unicodeScalars.count'))
             and 'translate_input_errors(' in api_case('ai_translate') and 'translate_input_errors(' in php('translate.php'),
             'S24: Translate offers "Other (type below)" with the typed language, checks as translate.php does and ties errors to their fields')

# S25: remediation for a client's preferred format.
remediate_view = between(ai_swift, 'struct RemediateView: View {', '\n}\n')
static_check(all(s in ai_swift for s in ('("plain", "Plain language (6th–8th grade)")', '("simplify", "Simplify (shorter sentences)")',
                                         '("largeprint", "Format for large print / low vision")', '("alt", "Alt text for an image (describe it below)")',
                                         '"Targeting the client\'s preferred format: \\(f)."', 'if let clientId, clientId > 0 { body["client_id"] = clientId }',
                                         '"Paste some content first."', 'Shorten the content to 20,000 characters or fewer (it has'))
             and 'remediateBody(mode: mode, content: content, clientId: clientId)' in remediate_view and '.errorField("Content")' in remediate_view
             and decodes(ai_swift, 'RemediateReply', 'text', 'truncated', 'format')
             and 'Label("Make a document accessible for \\(detail.client.name)"' in clients_swift
             and 'RemediateView(clientId: clientId, clientFormat: detail.client.a11yFormat)' in clients_swift
             and 'Provide content.' not in api_case('ai_remediate') and 'remediate_prompt(' in api_case('ai_remediate')
             and reads_input(api_case('ai_remediate'), 'client_id') and 'remediate_prompt(' in php('remediate.php'),
             'S25: remediation uses the website\'s modes and refusals, and from a client targets the client\'s preferred format')

# S26: knowledge base screens.
static_check(all(s in knowledge_swift for s in ('Text(kbMeta(category: a.category, updated: a.updatedAt, author: a.author))',
                                                'updated.flatMap { $0.isEmpty ? nil : "updated " + formatDate($0) }',
                                                '"Article (plain text; blank lines separate paragraphs; start a line with ## for a heading, - for a bullet, 1. for a numbered step; up to 60,000 characters)"',
                                                'Toggle("Publish to client portals (visible to clients)", isOn: $isPublic)',
                                                'Button("Delete permanently", role: .destructive)', '.confirmationDialog("Delete this article permanently?"',
                                                'Text("It does not go to the trash, so this cannot be undone.")',
                                                'Label("Ask the knowledge base", systemImage: "book.closed")', 'if api.aiHas("kb") {',
                                                '"No articles yet." : "No articles match your search."', 'Text("Shared with clients")'))
             and 'separator: " · "' in knowledge_swift,
             'S26: the knowledge base shows "<category> · updated <date> · <author>", the website\'s editor label, tick and delete words, and Ask the knowledge base')

# Confirmations in the new code name the item (S1, S7, S13, S15, S16, S17, S21).
static_check(titles_name_item(equipment_swift, ['Retire'])
             and titles_name_item(clients_swift, ['Record that the release', 'Turn off'])
             and 'confirmationDialog("Move the document \\"\\(trashing?.title ?? "")\\" to the trash?"' in documents_swift
             and 'confirmationDialog(evalConfirmTitle(to)' in eval_swift
             and 'confirmationDialog(consentWithdrawTitle(name: c.signedName ?? "the guardian", date: formatDate(c.signedAt))' in clients_swift
             and 'confirmationDialog(deleteSessionTitle(activity: deletingSession?.activity ?? "", date: formatDate(deletingSession?.sessionDate))' in programs_swift
             and 'confirmationDialog(switchLetterQuestion(name(pendingKey ?? ""))' in letters_swift,
             '4.37.0: every new confirmation names the item it acts on')

static_check('.confirmationDialog("Discard this letter?", isPresented: $askingDiscard, titleVisibility: .visible)' in letters_swift
             and 'Text("What you changed in this letter is not saved.")' in letters_swift
             and 'Button("Discard", role: .destructive) { dismiss() }' in letters_swift and 'Button("Keep editing", role: .cancel) {}' in letters_swift
             and 'Enter the date the request was sent (today or earlier).' in record_fn
             and record_fn.index('today or earlier') < record_fn.index('api.call("record_amendment"')
             and 'Enter the date of the reply (today or earlier).' in reply_fn
             and 'This photo is too large for the phone to convert. Save it as a JPEG first, or upload it from the website.' in upload_swift
             and 'That photo is larger than 25 MB, the most that can be uploaded.' in upload_swift,
             '4.37.0: the letter discard question, the amendment date sentences and the photo conversion message read as Android and the website')

# 4.37.0 review fixes, run for real: the app's own models and helpers are compiled and run on
# the server's reply shapes (no simulator needed).
def swift_decl(text, head):
    """A top-level Swift declaration starting with `head` ("struct X:", "func f("), braces matched."""
    i = text.index('\n' + head) + 1
    j = text.index('{', i)
    depth = 0
    while True:
        if text[j] == '{':
            depth += 1
        elif text[j] == '}':
            depth -= 1
            if depth == 0:
                return text[i:j + 1]
        j += 1


# Item 2 (edited letter): with no separate draft the typed letter and serverLetter are kept, so
# it still counts as edited; show(reply) is not reached on that path.
draft_ai = swift_func(amend_view, 'private func draftAI() async {')
edited_path = between(draft_ai, '} else if edited && recordsRequests {', '} else {')
static_check('let typed = letter, kept = serverLetter' in edited_path and 'letter = typed; serverLetter = kept' in edited_path
             and 'show(reply)' not in edited_path
             and draft_ai.index('if edited, let draft = reply.data.aiDraftText, !draft.isEmpty {') < draft_ai.index('} else if edited && recordsRequests {')
             and 'if editing || aiLetterKept { letter = typed; serverLetter = kept }' in amend_view,
             'S13: an edited letter whose AI draft did not come is kept and still counts as edited; a reload keeps a letter being edited or an AI letter')

run_src = 'import Foundation\n' + '\n'.join([
    swift_decl(swift_sources['API.swift'], 'struct LenientList:'),
    swift_decl(programs_swift, 'struct CohortMember:'),
    swift_decl(programs_swift, 'struct CohortSession:'),
    swift_decl(programs_swift, 'struct CohortInfo:'),
    swift_decl(programs_swift, 'struct CohortDetailResponse:'),
    swift_decl(eval_swift, 'struct EvalSection:'),
    swift_decl(eval_swift, 'func evalApplyDraft('),
    swift_decl(parity_swift, 'func mergeDraft('),
    swift_decl(parity_swift, 'func firstPlaceholder('),
    swift_decl(ai_swift, 'func startRequestPrefill('),
]) + r'''
func check(_ ok: Bool, _ label: String) { if !ok { print("FAIL: " + label); exit(1) }; print("PASS: " + label) }
let d = JSONDecoder(); d.keyDecodingStrategy = .convertFromSnakeCase
// Item 1: the cohort reply as api.php sends it: the raw row (active 1, dates as text) inside
// cohort, the coordinator, notes and a bool active beside it; one odd session row.
let cohortJSON = """
{"ok":true,"cohort":{"id":4,"name":"Synthetic Cohort","school":null,"coordinator_id":2,"start_date":"2026-09-01","end_date":null,"notes":"Synthetic note","active":1},
 "members":[{"id":7,"name":"Synthetic Client"}],
 "sessions":[{"id":9,"session_date":"2026-10-01","activity":"Workplace Readiness","topic":null,"duration_min":60,"present_n":"1","present_names":["Synthetic Client"],"absent_names":[],"text":"Oct 1, 2026, 60 min","can_delete":true},
             {"id":"odd"}],
 "available":[],"sessions_total":51,"total":51,"limit":50,"coordinator_name":"Synthetic Instructor","notes":"Synthetic note","active":true}
"""
let cohort = try! d.decode(CohortDetailResponse.self, from: Data(cohortJSON.utf8))
check(cohort.active == true && cohort.coordinatorName == "Synthetic Instructor" && cohort.notes == "Synthetic note"
      && cohort.cohort.startDate == "2026-09-01" && cohort.sessions.count == 1 && cohort.sessions[0].canDelete
      && cohort.sessions[0].presentN == 1 && cohort.sessionsTotal == 51,
      "S21: the cohort reply decodes as the server sends it (coordinator, notes and active beside the raw row; an odd session row skipped)")
let archived = try! d.decode(CohortDetailResponse.self, from: Data(#"{"cohort":{"id":4,"name":"X","active":"0"},"members":[],"sessions":[],"available":[],"active":0}"#.utf8))
check(archived.active == false, "S21: active sent as 0 reads as archived")
let older = try! d.decode(CohortDetailResponse.self, from: Data(#"{"cohort":{"id":4,"name":"X"},"members":[],"sessions":[],"available":[]}"#.utf8))
check(older.active == nil && older.coordinatorName == nil, "S21: a 4.36.0 cohort reply still decodes (no archive offered)")
// Item 4: drafts never cut or replace typed text.
check(mergeDraft("", "Draft") == "Draft" && mergeDraft("Typed text  \n", "Draft") == "Typed text\nDraft",
      "S5, S8: a draft fills an empty field, or goes below typed text after one line break")
let long = String(repeating: "x", count: 3990)
check(mergeDraft(long, String(repeating: "y", count: 50)).count == 3990 + 1 + 50, "S5: a merged justification is never cut (the save refuses over 4,000)")
let drafted = try! d.decode([EvalSection].self, from: Data(#"[{"key":"background","label":"Background","text":"AI background","default":false},{"key":"findings","label":"Evaluation findings","text":"AI findings","default":false},{"key":"recommendations","label":"Recommendations","text":"AI recs","default":false}]"#.utf8))
let applied = evalApplyDraft(current: ["background": "Prefilled background", "findings": "My own findings", "recommendations": ""],
                             defaults: ["background": "Prefilled background"], drafted: drafted)
check(applied["background"] == "AI background" && applied["findings"] == "My own findings" && applied["recommendations"] == "AI recs",
      "S7: the AI fills only empty or prefilled sections; a section written in keeps exactly what was typed")
check(firstPlaceholder("Dear [not on file], see [CLIENT].") == "[CLIENT]" && firstPlaceholder("Only [not on file] here.") == nil,
      "AI drafts: any bracketed tag but [not on file] is named as a placeholder")
let rec = startRequestPrefill(String(repeating: "a", count: 3000) + "\n" + String(repeating: "b", count: 2000))
check(rec.unicodeScalars.count == 3000 && !rec.contains("b"), "S6: the request prefill is at most 4,000 characters, cut at a line end")
'''
with tempfile.TemporaryDirectory(prefix='cms-437-') as tmp437:
    (Path(tmp437) / 'main.swift').write_text(run_src)
    subprocess.run(['swiftc', '-module-cache-path', str(Path(tmp437) / 'm'), str(Path(tmp437) / 'main.swift'), '-o', str(Path(tmp437) / 't')], check=True)
    run = subprocess.run([str(Path(tmp437) / 't')], capture_output=True, text=True)
    print(run.stdout, end='')
    static_check(run.returncode == 0, '4.37.0: the cohort decode, draft merges and placeholder rules run as the app runs them')

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
