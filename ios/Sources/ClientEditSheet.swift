import SwiftUI

/// The states and territories a client can live in (the server's US_STATES, 4.19.0): code and name.
let usStates: [(code: String, name: String)] = [
    ("AL", "Alabama"),
    ("AK", "Alaska"),
    ("AZ", "Arizona"),
    ("AR", "Arkansas"),
    ("CA", "California"),
    ("CO", "Colorado"),
    ("CT", "Connecticut"),
    ("DE", "Delaware"),
    ("DC", "District of Columbia"),
    ("FL", "Florida"),
    ("GA", "Georgia"),
    ("HI", "Hawaii"),
    ("ID", "Idaho"),
    ("IL", "Illinois"),
    ("IN", "Indiana"),
    ("IA", "Iowa"),
    ("KS", "Kansas"),
    ("KY", "Kentucky"),
    ("LA", "Louisiana"),
    ("ME", "Maine"),
    ("MD", "Maryland"),
    ("MA", "Massachusetts"),
    ("MI", "Michigan"),
    ("MN", "Minnesota"),
    ("MS", "Mississippi"),
    ("MO", "Missouri"),
    ("MT", "Montana"),
    ("NE", "Nebraska"),
    ("NV", "Nevada"),
    ("NH", "New Hampshire"),
    ("NJ", "New Jersey"),
    ("NM", "New Mexico"),
    ("NY", "New York"),
    ("NC", "North Carolina"),
    ("ND", "North Dakota"),
    ("OH", "Ohio"),
    ("OK", "Oklahoma"),
    ("OR", "Oregon"),
    ("PA", "Pennsylvania"),
    ("RI", "Rhode Island"),
    ("SC", "South Carolina"),
    ("SD", "South Dakota"),
    ("TN", "Tennessee"),
    ("TX", "Texas"),
    ("UT", "Utah"),
    ("VT", "Vermont"),
    ("VA", "Virginia"),
    ("WA", "Washington"),
    ("WV", "West Virginia"),
    ("WI", "Wisconsin"),
    ("WY", "Wyoming"),
    ("AS", "American Samoa"),
    ("GU", "Guam"),
    ("MP", "Northern Mariana Islands"),
    ("PR", "Puerto Rico"),
    ("VI", "U.S. Virgin Islands"),
]

struct ClientEditSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let existing: ClientInfo?          // nil = create new client
    /// The stored client email is unconfirmed (it came from the public referral form):
    /// clients.email_verified is false. Nothing is emailed to it until staff confirm it.
    private let emailUnconfirmed: Bool
    /// The saved client's id and the result to tell the user. The presenter shows it and
    /// announces it once the sheet has gone: closing the sheet moves VoiceOver focus, which
    /// would cut off an announcement made from here.
    let onSaved: (_ clientId: Int, _ message: String) -> Void

    @State private var name = ""
    @State private var phone = ""
    @State private var email = ""
    @State private var address = ""
    @State private var usState = ""
    /// Required for a new client and for one whose state is known (as the website); an older record with none can still be saved.
    private var stateRequired: Bool { existing == nil || !(existing?.state ?? "").isEmpty }
    @State private var status = "Pending Assessment"
    @State private var statusNote = ""
    /// Closing a case (3.0.35): why, and whether the person was employed at closure.
    @State private var closureReason = ""
    @State private var closureEmployed = ""
    @State private var closureEmployer = ""
    @State private var closureJobTitle = ""
    /// The job start date, when one is recorded.
    @State private var closureJobStartSet = false
    @State private var closureJobStart = Date()
    @State private var closureHours = ""
    @State private var closureWage = ""
    @State private var closureIndependence = ""
    /// Assistive technology at closure (4.25.0): device counts start from the device requests.
    @State private var closureAtRecommended = ""
    @State private var closureAtAcquired = ""
    @State private var closureAtInUse = ""
    @State private var closureAtNote = ""
    /// Office, custom fields and device counts from the record (4.25.0); nil for a new client.
    private let extras: ClientExtras?
    /// The office chosen (0 = no office; for a new client, -1 = the creator's own office, left unsent).
    @State private var officeId = 0
    /// For a new client: the fields and offices from form_options (empty from an older server).
    @State private var newClientFields: [CustomFieldEdit] = []
    @State private var newClientOffices: [OfficeChoice] = []
    /// Custom field answers by field id, as edited.
    @State private var customValues: [Int: String] = [:]
    @State private var counselorName = ""
    @State private var counselorAgency = ""
    @State private var counselorEmail = ""
    @State private var counselorPhone = ""
    @State private var guardianName = ""
    @State private var guardianPhone = ""
    @State private var guardianEmail = ""
    @State private var vrCaseNumber = ""
    @State private var ipeGoal = ""
    @State private var staff: [StaffMember] = []
    /// The staff list could not be loaded: said, with a retry, instead of a picker with no names.
    @State private var staffError: String?
    @State private var assignedTo = 0
    @State private var clientType = "VR"
    @State private var a11yScreenReader = false
    @State private var a11yFormat = ""
    @State private var a11yPrimaryTech = ""
    @State private var a11yContact = ""
    @State private var a11yNotes = ""
    /// Staff confirmed the unconfirmed client email with the client (sent as email_verified).
    @State private var confirmEmail = false
    /// Texts (4.17.0): the agreement as the record has it, and how it was given.
    private let texting: ClientTexting?
    @State private var smsConsent = false
    @State private var smsSource = "In person"
    private static let smsSources = ["In person", "By phone", "In writing", "Referral form", "Client portal"]
    @State private var busy = false
    @State private var error: String?

    /// The form is filled from the record before it is first drawn, so it is never shown blank
    /// (and can't be saved with defaults) while the staff list is still loading.
    /// `emailVerified`: the record's email_verified (nil from a server that does not report it).
    init(existing: ClientInfo?, emailVerified: Bool? = nil, texting: ClientTexting? = nil, extras: ClientExtras? = nil,
         onSaved: @escaping (_ clientId: Int, _ message: String) -> Void) {
        self.existing = existing
        self.onSaved = onSaved
        self.texting = texting
        self.extras = existing == nil ? nil : extras
        if existing != nil, let extras {
            _officeId = State(initialValue: extras.officeId ?? 0)
            _customValues = State(initialValue: Dictionary(extras.customFieldsEdit.map { ($0.id, $0.value) }, uniquingKeysWith: { a, _ in a }))
            // A new closure starts from the devices the requests record, as on the website.
            if extras.deviceCounts.recommended > 0 { _closureAtRecommended = State(initialValue: String(extras.deviceCounts.recommended)) }
            if extras.deviceCounts.acquired > 0 { _closureAtAcquired = State(initialValue: String(extras.deviceCounts.acquired)) }
        }
        _smsConsent = State(initialValue: texting?.agreed ?? false)
        _smsSource = State(initialValue: texting?.source ?? "In person")
        if existing == nil { _officeId = State(initialValue: -1) }
        emailUnconfirmed = emailVerified == false
            && !(existing?.email ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        guard let existing else { return }
        _name = State(initialValue: existing.name)
        _phone = State(initialValue: existing.phone ?? "")
        _email = State(initialValue: existing.email ?? "")
        _address = State(initialValue: existing.address ?? "")
        _usState = State(initialValue: existing.state ?? "")
        _status = State(initialValue: existing.status)
        _assignedTo = State(initialValue: existing.assignedTo ?? 0)
        _counselorName = State(initialValue: existing.counselorName ?? "")
        _counselorAgency = State(initialValue: existing.counselorAgency ?? "")
        _counselorEmail = State(initialValue: existing.counselorEmail ?? "")
        _counselorPhone = State(initialValue: existing.counselorPhone ?? "")
        _guardianName = State(initialValue: existing.guardianName ?? "")
        _guardianPhone = State(initialValue: existing.guardianPhone ?? "")
        _guardianEmail = State(initialValue: existing.guardianEmail ?? "")
        _vrCaseNumber = State(initialValue: existing.vrCaseNumber ?? "")
        _ipeGoal = State(initialValue: existing.ipeGoal ?? "")
        _clientType = State(initialValue: existing.clientType ?? "VR")
        _a11yScreenReader = State(initialValue: existing.a11yScreenReader ?? false)
        _a11yFormat = State(initialValue: existing.a11yFormat ?? "")
        _a11yPrimaryTech = State(initialValue: existing.a11yPrimaryTech ?? "")
        _a11yContact = State(initialValue: existing.a11yContact ?? "")
        _a11yNotes = State(initialValue: existing.a11yNotes ?? "")
    }

    static var closureReasons: [String] {
        FormOptions.list("closure_reasons", ["Employed", "Goals met", "Declined further services", "Unable to contact", "Moved away",
                                             "Health or medical", "Not eligible", "Transferred to another provider", "Other"])
    }
    private var statusChanged: Bool { existing != nil && existing?.status != status }

    /// The unconfirmed email is still the one in the field: it can be confirmed here. A different
    /// address typed by staff is confirmed by the server when saved.
    private var offersEmailConfirmation: Bool {
        emailUnconfirmed && email.trimmingCharacters(in: .whitespacesAndNewlines)
            == (existing?.email ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
    }

    /// Only staff with client access can be assigned a client (save_client refuses anyone else,
    /// as the website's list does); an older server that does not say keeps everyone offered.
    private var clientStaff: [StaffMember] { staff.filter { $0.canClients != false } }

    /// The row for a current assignee the list does not offer.
    private var currentAssigneeLabel: String {
        if staff.isEmpty { return "Current instructor" }
        if let member = staff.first(where: { $0.id == assignedTo }) { return "\(member.name) (current, no client access)" }
        return "Current instructor (inactive)"
    }

    /// Every field as text, to tell whether anything was changed since the form opened.
    private var values: [String] {
        [name, phone, email, address, status, statusNote, counselorName, counselorAgency,
         counselorEmail, counselorPhone, String(assignedTo), clientType, a11yScreenReader ? "1" : "0",
         a11yFormat, a11yPrimaryTech, a11yContact, a11yNotes, confirmEmail ? "1" : "0",
         guardianName, guardianPhone, guardianEmail, vrCaseNumber, ipeGoal, usState]
    }
    /// The same fields as the form opens with them (see init and the property defaults).
    private static func values(of c: ClientInfo?) -> [String] {
        [c?.name ?? "", c?.phone ?? "", c?.email ?? "", c?.address ?? "", c?.status ?? "Pending Assessment", "",
         c?.counselorName ?? "", c?.counselorAgency ?? "", c?.counselorEmail ?? "", c?.counselorPhone ?? "",
         String(c?.assignedTo ?? 0), c?.clientType ?? "VR", (c?.a11yScreenReader ?? false) ? "1" : "0",
         c?.a11yFormat ?? "", c?.a11yPrimaryTech ?? "", c?.a11yContact ?? "", c?.a11yNotes ?? "", "0",
         c?.guardianName ?? "", c?.guardianPhone ?? "", c?.guardianEmail ?? "", c?.vrCaseNumber ?? "", c?.ipeGoal ?? "", c?.state ?? ""]
    }
    private var originalCustom: [Int: String] {
        Dictionary(customFields.map { ($0.id, $0.value) }, uniquingKeysWith: { a, _ in a })
    }
    /// Custom answers changed in this form: only these are sent.
    private var changedCustom: [Int: String] {
        let original = originalCustom
        return customValues.filter { original[$0.key] != $0.value }
    }
    private var closureTyped: Bool {
        ![closureJobTitle, closureHours, closureWage, closureIndependence, closureAtInUse, closureAtNote].allSatisfy(\.isEmpty) || closureJobStartSet
    }
    private var hasChanges: Bool {
        values != Self.values(of: existing) || smsConsent != (texting?.agreed ?? false)
            || officeId != originalOffice || !changedCustom.isEmpty || closureTyped
    }
    private var offices: [OfficeChoice] { existing == nil ? newClientOffices : (extras?.offices ?? []) }
    private var customFields: [CustomFieldEdit] { existing == nil ? newClientFields : (extras?.customFieldsEdit ?? []) }
    private var originalOffice: Int { existing == nil ? -1 : (extras?.officeId ?? 0) }

    @State private var askingDiscard = false

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                // Each name stays visible above its field (a filled-in edit form showed bare values).
                Section("Client") {
                    CaptionedField(label: "Full name (required)", text: $name, prompt: "Full name")
                    CaptionedField(label: "Phone", text: $phone, keyboard: .phonePad)
                        // A new number needs the client's agreement again (the server clears it too).
                        .onChange(of: phone) { new in
                            if new.trimmingCharacters(in: .whitespaces) != (existing?.phone ?? "").trimmingCharacters(in: .whitespaces) { smsConsent = false }
                        }
                    // Texts (4.17.0), as the website's client form: after STOP, only the client's START turns them back on.
                    if texting?.optedOut == true {
                        Text(texting?.words ?? "")
                    } else if !phone.trimmingCharacters(in: .whitespaces).isEmpty {
                        Toggle("The client agreed to text messages at this number", isOn: $smsConsent)
                        if smsConsent {
                            Picker("How they agreed", selection: $smsSource) {
                                ForEach(Self.smsSources, id: \.self) { Text($0).tag($0) }
                            }
                        }
                    }
                    CaptionedField(label: "Email", text: $email, keyboard: .emailAddress).textInputAutocapitalization(.never)
                    // An address from the public referral form, not yet checked: said in words,
                    // with the confirmation beside it (as the counselor email on the website).
                    if offersEmailConfirmation {
                        VStack(alignment: .leading, spacing: 4) {
                            // The heading and its explanation as one stop.
                            VStack(alignment: .leading, spacing: 4) {
                                Text("Email not yet confirmed").font(.callout.weight(.semibold))
                                Text("This address came from the public referral form and has not been checked, so no session reminders, outcome survey or portal invitation go to it yet. Confirm it with the client first. Typing a different address also confirms the new one.")
                                    .font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            .accessibilityElement(children: .combine)
                            Toggle("Confirmed with the client", isOn: $confirmEmail)
                                .accessibilityHint("Confirms \(email.trimmingCharacters(in: .whitespacesAndNewlines)) as the client's email address when you save.")
                        }
                    }
                    CaptionedField(label: "Address", text: $address)
                    // Required (4.19.0): it sets the client's time zone for reminders and texts.
                    Picker(stateRequired ? "State (required)" : "State, not recorded yet", selection: $usState) {
                        Text("Choose a state").tag("")
                        ForEach(usStates, id: \.code) { Text($0.name).tag($0.code) }
                    }
                }
                Section("Case status") {
                    Picker("Status", selection: $status) {
                        // A status since removed from the list stays choosable on a record that has it.
                        ForEach(clientStatuses.contains(status) || status.isEmpty ? clientStatuses : clientStatuses + [status], id: \.self) { Text($0) }
                    }
                    if statusChanged {
                        CaptionedField(label: "Reason for status change", text: $statusNote)
                        Text("Recorded in the audit history.").font(.caption).foregroundStyle(Color.secondaryText)
                        // Said before Save: the email can't be called back once it has gone.
                        if status == "Closed - Rehabilitated" {
                            Label("Saving with this status emails the client an outcome survey, if they have a confirmed email address and were not sent one in the last 30 days.",
                                  systemImage: "envelope")
                                .font(.callout)
                        }
                        if status.hasPrefix("Closed") {
                            Picker("Why is the case closing?", selection: $closureReason) {
                                Text("Choose a reason").tag("")
                                ForEach(Self.closureReasons, id: \.self) { Text($0).tag($0) }
                            }
                            Picker("Employed at closure?", selection: $closureEmployed) {
                                Text("Not recorded").tag("")
                                Text("Yes").tag("yes")
                                Text("No").tag("no")
                            }
                            if closureEmployed == "yes" {
                                CaptionedField(label: "Employer", text: $closureEmployer)
                                CaptionedField(label: "Job title", text: $closureJobTitle)
                                Picker("Job start date", selection: $closureJobStartSet) {
                                    Text("Not recorded").tag(false)
                                    Text("Choose a date").tag(true)
                                }
                                if closureJobStartSet {
                                    DatePicker("Job start date", selection: $closureJobStart, displayedComponents: .date)
                                }
                                CaptionedField(label: "Hours a week", text: $closureHours, keyboard: .decimalPad)
                                CaptionedField(label: "Hourly wage (dollars)", text: $closureWage, keyboard: .decimalPad)
                            }
                            CaptionedField(label: "Independence outcome (for a goal without a job, such as reading mail or using a phone on their own)",
                                           text: $closureIndependence, lines: 1...4)
                            CaptionedField(label: "Devices recommended", text: $closureAtRecommended, keyboard: .numberPad)
                            CaptionedField(label: "Devices the client got", text: $closureAtAcquired, keyboard: .numberPad)
                            Picker("Using their devices at closure?", selection: $closureAtInUse) {
                                Text("Not recorded").tag("")
                                Text("Yes").tag("yes")
                                Text("No").tag("no")
                            }
                            CaptionedField(label: "Technology note (such as a device still on order, or why one is not used)", text: $closureAtNote)
                        }
                    }
                    // Offices (4.22.0): shown only when the organisation uses them.
                    if !offices.isEmpty {
                        Picker("Office", selection: $officeId) {
                            if existing == nil { Text("Your own office").tag(-1) }
                            Text("No office").tag(0)
                            // A closed office the record still has stays choosable.
                            if officeId > 0 && !offices.contains(where: { $0.id == officeId }) {
                                Text(extras?.office ?? "Current office").tag(officeId)
                            }
                            ForEach(offices) { Text($0.name).tag($0.id) }
                        }
                    }
                    if api.scoped {
                        // The server keeps a caseload-only account's clients assigned to it and
                        // ignores any other choice, so none is offered.
                        LabeledContent("Assigned instructor", value: "You")
                        Text("Your account works only its own caseload, so clients you add or edit stay assigned to you.")
                            .font(.caption).foregroundStyle(Color.secondaryText)
                    } else {
                        if let staffError {
                            ErrorBanner(message: staffError)
                            Button { Task { await loadStaff() } } label: {
                                Label("Try loading staff again", systemImage: "arrow.clockwise")
                            }
                        }
                        Picker("Assigned instructor", selection: $assignedTo) {
                            Text("Unassigned").tag(0)
                            // The current assignee stays selectable while the list loads, or when
                            // that person is no longer active.
                            if assignedTo != 0 && !clientStaff.contains(where: { $0.id == assignedTo }) {
                                Text(currentAssigneeLabel).tag(assignedTo)
                            }
                            ForEach(clientStaff) { member in Text(member.name).tag(member.id) }
                        }
                    }
                }
                Section("Vocational rehabilitation (VR) counselor") {
                    CaptionedField(label: "Counselor name", text: $counselorName)
                    CaptionedField(label: "Agency / district", text: $counselorAgency)
                    CaptionedField(label: "Counselor email", text: $counselorEmail, keyboard: .emailAddress).textInputAutocapitalization(.never)
                    CaptionedField(label: "Counselor phone", text: $counselorPhone, keyboard: .phonePad)
                }
                Section("Plan for employment (IPE)") {
                    CaptionedField(label: "VR case or participant number", text: $vrCaseNumber)
                    CaptionedField(label: "IPE employment goal", text: $ipeGoal)
                }
                Section("Parent or guardian") {
                    CaptionedField(label: "Guardian name", text: $guardianName)
                    CaptionedField(label: "Guardian phone", text: $guardianPhone, keyboard: .phonePad)
                    CaptionedField(label: "Guardian email", text: $guardianEmail, keyboard: .emailAddress).textInputAutocapitalization(.never)
                }
                if !customFields.isEmpty {
                    let fields = customFields
                    Section("More details") {
                        ForEach(fields) { field in
                            CustomFieldEditor(field: field, value: Binding(
                                get: { customValues[field.id] ?? "" },
                                set: { customValues[field.id] = $0 }))
                        }
                    }
                }
                Section("Case type") {
                    Picker("Client type", selection: $clientType) { ForEach(clientTypes, id: \.self) { Text($0) } }
                }
                Section("Accessibility & access needs") {
                    Toggle("Uses a screen reader", isOn: $a11yScreenReader)
                    Picker("Preferred format", selection: $a11yFormat) {
                        Text("No preference").tag("")
                        ForEach(a11yFormats, id: \.self) { Text($0).tag($0) }
                    }
                    CaptionedField(label: "Primary assistive technology", text: $a11yPrimaryTech)
                    Picker("Preferred contact", selection: $a11yContact) {
                        // Told apart from the "No preference" choice below, as on the website.
                        Text("No preference recorded").tag("")
                        ForEach(a11yContacts, id: \.self) { Text($0).tag($0) }
                    }
                    CaptionedField(label: "Access notes", text: $a11yNotes, lines: 2...5)
                    // As on the website: the client reads (and can change) these on the portal.
                    Text("Shown to the client on their portal, where they can also change it. Keep internal remarks in case notes.")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                }
            }
            .navigationTitle(existing == nil ? "New client" : "Edit client")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing name: save() says which field is needed.
                    Button("Save") { Task { await save() } }
                        .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy,
                                 question: existing == nil ? "Discard this new client?" : "Discard your changes to this client?",
                                 asking: $askingDiscard) { dismiss() }
            .task { await loadStaff() }
            .task { await loadNewClientOptions() }
        }
    }

    /// The custom fields and offices a new client can have (form_options, 4.25.0); an older
    /// server, or a failure, leaves both out and the server keeps its defaults.
    private func loadNewClientOptions() async {
        guard existing == nil, let r: NewClientOptions = try? await api.call("form_options") else { return }
        newClientFields = r.customFields
        newClientOffices = r.offices
    }

    private func loadStaff() async {
        guard !api.scoped else { return }
        staffError = nil
        do {
            let response: StaffResponse = try await api.call("staff")
            staff = response.staff
        } catch {
            showFormError("Could not load the staff list, so an instructor can't be chosen. \(error.localizedDescription)", in: &staffError)
        }
    }

    private func save() async {
        guard !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            showFormError("Full name: enter the client's name before saving.", in: &error)
            return
        }
        busy = true; error = nil
        do {
            // Tolerant: once the server says ok, a missing id must not report the save as failed.
            struct SaveResult: Decodable {
                let clientId: Int
                /// True when saving re-opened an archived record (false from an older server).
                let unarchived: Bool
                /// Whether the outcome survey email went out: nil when none was due (or from an
                /// older server); false also when the client has no valid email address.
                let surveySent: Bool?
                private enum CodingKeys: String, CodingKey { case ok, clientId, unarchived, surveySent }
                init(from decoder: Decoder) throws {
                    let c = try decoder.container(keyedBy: CodingKeys.self)
                    guard (try? c.decode(Bool.self, forKey: .ok)) == true else {
                        throw DecodingError.dataCorruptedError(forKey: .ok, in: c, debugDescription: "not ok")
                    }
                    clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
                    unarchived = (try? c.decode(Bool.self, forKey: .unarchived)) ?? false
                    surveySent = try? c.decodeIfPresent(Bool.self, forKey: .surveySent)
                }
            }
            if usState.isEmpty && stateRequired {
                showFormError("Choose the state the client lives in. It sets their time zone for reminders and texts.", in: &error)
                return
            }
            // A new client's required fields are checked here, by name (an existing record sends only what changed).
            if existing == nil, let missing = customFields.first(where: { $0.required && (customValues[$0.id] ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) {
                showFormError("\(missing.label): this is required.", in: &error)
                return
            }
            if status.hasPrefix("Closed") && (existing?.status ?? "") != status && closureReason.isEmpty {
                showFormError("Choose why the case is closing.", in: &error)
                return
            }
            if status.hasPrefix("Closed") && (existing?.status ?? "") != status {
                for (value, what) in [(closureAtRecommended, "Devices recommended"), (closureAtAcquired, "Devices the client got")] {
                    let t = value.trimmingCharacters(in: .whitespaces)
                    if !t.isEmpty && (t.count > 2 || !t.allSatisfy(\.isASCII) || Int(t) == nil) {
                        showFormError("\(what): enter a whole number from 0 to 99, or leave it blank.", in: &error)
                        return
                    }
                }
            }
            var body: [String: Any] = [
                "id": existing?.id ?? 0,
                "name": name, "phone": phone, "email": email, "address": address,
                "status": status, "status_note": statusNote, "assigned_to": assignedTo,
                "counselor_name": counselorName, "counselor_agency": counselorAgency,
                "counselor_email": counselorEmail, "counselor_phone": counselorPhone,
                "guardian_name": guardianName, "guardian_phone": guardianPhone, "guardian_email": guardianEmail,
                "vr_case_number": vrCaseNumber, "ipe_goal": ipeGoal,
                "client_type": clientType, "a11y_screen_reader": a11yScreenReader ? 1 : 0,
                "a11y_format": a11yFormat, "a11y_primary_tech": a11yPrimaryTech,
                "a11y_contact": a11yContact, "a11y_notes": a11yNotes,
            ]
            if status.hasPrefix("Closed") && (existing?.status ?? "") != status {
                body["closure_reason"] = closureReason
                body["closure_note"] = statusNote
                body["closure_employed"] = closureEmployed
                body["closure_employer"] = closureEmployer
                if closureEmployed == "yes" {
                    body["closure_job_title"] = closureJobTitle
                    body["closure_job_start"] = closureJobStartSet ? APIDate.day(closureJobStart) : ""
                    body["closure_hours"] = closureHours.trimmingCharacters(in: .whitespaces)
                    body["closure_wage"] = closureWage.trimmingCharacters(in: .whitespaces)
                }
                body["closure_independence"] = closureIndependence
                body["closure_at_recommended"] = closureAtRecommended.trimmingCharacters(in: .whitespaces)
                body["closure_at_acquired"] = closureAtAcquired.trimmingCharacters(in: .whitespaces)
                body["closure_at_in_use"] = closureAtInUse
                body["closure_at_note"] = closureAtNote
            }
            // Sent only when changed: an unchanged office (even a closed one) stays as stored.
            // A new client's "Your own office" (-1) is left unsent: the server gives the creator's office.
            if !offices.isEmpty && officeId != originalOffice && officeId >= 0 {
                body["office_id"] = officeId == 0 ? NSNull() : officeId as Any
            }
            let customSent = changedCustom
            if !customSent.isEmpty {
                body["custom"] = Dictionary(uniqueKeysWithValues: customSent.map { (String($0.key), $0.value) })
            }
            // Sent only when staff turned the confirmation on; otherwise the server keeps the
            // stored state (and confirms an address staff typed themselves).
            let emailStaysUnconfirmed = offersEmailConfirmation && !confirmEmail
            if offersEmailConfirmation && confirmEmail { body["email_verified"] = true }
            // Sent only when chosen: a server before 4.19.0 ignores it, and an empty one is refused.
            if !usState.isEmpty { body["state"] = usState }
            if texting?.optedOut != true {
                body["sms_consent"] = smsConsent && !phone.trimmingCharacters(in: .whitespaces).isEmpty
                body["sms_consent_source"] = smsSource
            }
            let result: SaveResult = try await api.call("save_client", body: body)
            // Saving an archived record with an open status re-opens it, as on the website.
            var message = result.unarchived
                ? "Client saved. The archived record was re-opened and is back in the active client lists."
                : "Client saved."
            // Closing a case as rehabilitated emails the outcome survey; a failed send is said,
            // as on the website, so it can be retried. A client with no email address is not
            // surveyed, so that is not reported as a failure.
            if result.surveySent == true {
                message += " An outcome survey was emailed to the client."
            } else if result.surveySent == false && emailStaysUnconfirmed {
                message += " No outcome survey was emailed, because the client's email address is not yet confirmed."
            } else if result.surveySent == false && email.trimmingCharacters(in: .whitespaces).contains("@") {
                message += " The outcome survey email could not be sent. Close the case again later or check the email settings."
            }
            onSaved(result.clientId > 0 ? result.clientId : (existing?.id ?? 0), message)
            dismiss()
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }
}

// MARK: - Assessments

struct Assessment: Decodable, Identifiable {
    let id: Int
    let assessmentDate: String
    let primaryDisability: String?
    let primaryTech: String
    let hardwareDetails: String?
    let softwareVersion: String?
    let summary: String?
    let author: String?
    private enum CodingKeys: String, CodingKey { case id, assessmentDate, primaryDisability, primaryTech, hardwareDetails, softwareVersion, summary, author }
    // Tolerant decode so one null field doesn't hide the whole assessments section.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        assessmentDate = (try? c.decode(String.self, forKey: .assessmentDate)) ?? ""
        primaryDisability = try? c.decodeIfPresent(String.self, forKey: .primaryDisability)
        primaryTech = (try? c.decode(String.self, forKey: .primaryTech)) ?? ""
        hardwareDetails = try? c.decodeIfPresent(String.self, forKey: .hardwareDetails)
        softwareVersion = try? c.decodeIfPresent(String.self, forKey: .softwareVersion)
        summary = try? c.decodeIfPresent(String.self, forKey: .summary)
        author = try? c.decodeIfPresent(String.self, forKey: .author)
    }
}

struct AssessmentsResponse: Decodable { let assessments: [Assessment] }

struct AssessmentsSection: View {
    @EnvironmentObject var api: API
    let clientId: Int
    /// Changed by the client screen when it refreshes, so this section reloads with it.
    var reloadToken = 0
    @State private var assessments: [Assessment] = []
    @State private var loaded = false
    @State private var loadError: String?
    @State private var retrying = false
    @State private var showingAdd = false
    /// The result of the record-assessment sheet, said once the sheet has closed.
    @State private var sheetResult: String?
    /// Assessments whose summary is shown in full rather than as a preview.
    @State private var expanded: Set<Int> = []
    @Environment(\.dynamicTypeSize) private var typeSize

    var body: some View {
        // No count and no "none recorded" until the list has actually arrived: a request that
        // is still running or failed must not read as "this client has no assessments".
        Section(loaded ? "Tech assessments (\(assessments.count))" : "Tech assessments") {
            if let loadError {
                ErrorBanner(message: "Could not load assessments. \(loadError)")
                // The same button stays (and keeps VoiceOver focus) while the retry runs.
                Button(retrying ? "Loading assessments…" : "Retry loading assessments") { Task { await load(retry: true) } }
                    .disabled(retrying)
            } else if !loaded {
                ProgressView("Loading assessments…")
            } else if assessments.isEmpty {
                Text("No assessments recorded.").foregroundStyle(Color.secondaryText)
            }
            ForEach(assessments) { a in
                let heading = "\(formatDate(a.assessmentDate)) — \(a.primaryTech)"
                VStack(alignment: .leading, spacing: 3) {
                    Text(heading).font(.body.weight(.medium))
                    if let disability = a.primaryDisability, !disability.isEmpty {
                        Text(disability).font(.callout).foregroundStyle(Color.secondaryText)
                    }
                    if let hardware = a.hardwareDetails, !hardware.isEmpty {
                        Text("Hardware: \(hardware)").font(.callout)
                    }
                    if let software = a.softwareVersion, !software.isEmpty {
                        Text("Software: \(software)").font(.callout)
                    }
                    if let summary = a.summary, !summary.isEmpty {
                        // A preview that opens in full: at large text sizes 4 lines held a few words.
                        let open = expanded.contains(a.id)
                        Text(summary).font(.callout).lineLimit(open ? nil : 4)
                        if mayTruncate(summary, lines: 4, accessibilitySize: typeSize.isAccessibilitySize) {
                            Button {
                                if open { expanded.remove(a.id) } else { expanded.insert(a.id) }
                            } label: {
                                Text(open ? "Show less" : "Show full summary")
                                    .frame(minHeight: 44).contentShape(Rectangle())
                            }
                            .font(.callout).buttonStyle(.borderless)
                            .accessibilityLabel(open ? "Show less of the \(heading) summary" : "Show full summary: \(heading)")
                            .accessibilityInputLabels(open ? ["Show less", "Show less of the \(heading) summary"]
                                                           : ["Show full summary", "Show full summary: \(heading)"])
                        }
                    }
                }
                .padding(.vertical, 2)
                // One VoiceOver stop per assessment, with the whole summary (never cut short);
                // Show full summary only changes what is drawn, so it is not needed here.
                .accessibilityElement(children: .ignore)
                .accessibilityLabel([heading, a.primaryDisability,
                                     a.hardwareDetails.map { "Hardware: \($0)" },
                                     a.softwareVersion.map { "Software: \($0)" },
                                     a.summary]
                    .compactMap { $0 }.filter { !$0.isEmpty && $0 != "Hardware: " && $0 != "Software: " }
                    .joined(separator: ", "))
            }
            Button { showingAdd = true } label: { Label("Record assessment", systemImage: "plus") }
        }
        .task(id: reloadToken) { await load() }
        .sheet(isPresented: $showingAdd, onDismiss: {
            if let message = sheetResult { announceAfterDismiss(message) }
            sheetResult = nil
        }) {
            AddAssessmentSheet(clientId: clientId) {
                sheetResult = "Assessment saved."
                Task { await load() }
            }
        }
    }

    private func load(retry: Bool = false) async {
        if retry {
            retrying = true
            announce("Loading assessments.")
        }
        defer { if retry { retrying = false } }
        do {
            let response: AssessmentsResponse = try await api.call("assessments", query: ["client_id": String(clientId)])
            assessments = response.assessments
            loaded = true
            loadError = nil
            if retry { announce("Assessments loaded.") }
        } catch {
            // A load replaced by a newer one, or the screen closing, is not a failure to report.
            guard !Task.isCancelled else { return }
            let message = error.localizedDescription
            // The banner speaks when its text changes; the same failure again is said here.
            if retry && loadError == message { announce("Could not load assessments. \(message)") }
            loadError = message
        }
    }
}

struct AddAssessmentSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let onSaved: () -> Void

    @State private var date = Date()
    @State private var disability = ""
    @State private var tech = "Screen Reader"
    @State private var hardware = ""
    @State private var software = ""
    @State private var summary = ""
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false

    private var hasChanges: Bool {
        !disability.isEmpty || tech != "Screen Reader" || !hardware.isEmpty || !software.isEmpty || !summary.isEmpty
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                DatePicker("Assessment date", selection: $date, displayedComponents: .date)
                CaptionedField(label: "Primary disability / needs", text: $disability)
                Picker("Primary tech recommended", selection: $tech) {
                    ForEach(techTypes, id: \.self) { Text($0) }
                }
                CaptionedField(label: "Hardware details", text: $hardware)
                CaptionedField(label: "Software & version", text: $software)
                CaptionedField(label: "Summary", text: $summary, lines: 4...10)
            }
            .navigationTitle("Record assessment")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await save() } }.disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this assessment?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }

    private func save() async {
        busy = true; error = nil
        do {
            let _: OKResponse = try await api.call("add_assessment", body: [
                "client_id": clientId, "assessment_date": APIDate.day(date),
                "primary_disability": disability, "primary_tech": tech,
                "hardware_details": hardware, "software_version": software, "summary": summary,
            ])
            // "Assessment saved." is said by the section once this sheet has closed.
            onSaved()
            dismiss()
        } catch APIError.network {
            // The request may have arrived: it isn't known whether the assessment was saved, and
            // saving it again would record it twice.
            showFormError("The connection dropped before the server answered, so it isn't known whether this assessment was saved. Check the client's assessments for \(date.formatted(date: .abbreviated, time: .omitted)) before saving it again.", in: &self.error)
            busy = false
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }
}

// MARK: - Form accessibility shared by the client, note, track, schedule, homework and upload sheets

/// A text field whose name stays visible above it: in a Form the title is only a placeholder,
/// which disappears once the field holds text (always, in an edit form). The field carries the
/// same name for VoiceOver and Voice Control, so the caption is hidden from them.
struct CaptionedField: View {
    let label: String
    @Binding var text: String
    /// Shown in the empty field; the label when nil.
    var prompt: String? = nil
    var lines: ClosedRange<Int>? = nil
    /// Set on the field itself, so it never depends on a modifier reaching inside this view.
    var keyboard: UIKeyboardType = .default

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
                .accessibilityHidden(true)
            if let lines {
                TextField(label, text: $text, prompt: Text(prompt ?? label), axis: .vertical).lineLimit(lines)
                    .keyboardType(keyboard)
                    .accessibilityLabel(label)
            } else {
                TextField(label, text: $text, prompt: Text(prompt ?? label))
                    .keyboardType(keyboard)
                    // The field's name for VoiceOver and Voice Control, filled or empty (the
                    // 4.26.0 audit found these fields with no label of their own).
                    .accessibilityLabel(label)
            }
        }
    }
}

private enum FormErrorRow: Hashable { case banner }

/// A Form whose first row is its error banner. A Form is a lazy list: a banner inserted above
/// rows the person has scrolled past is never drawn, so it was never heard (a failed Save just
/// went quiet). When an error arrives this scrolls the banner into view, where ErrorBanner
/// announces itself, and moves VoiceOver onto it.
struct ErrorFocusForm<Content: View>: View {
    /// Whether the form is shown inside a NavigationStack (a sheet's own, or the screen it is
    /// pushed on): its pickers may then open their choices on a screen of their own at the
    /// accessibility text sizes (ReadablePickers). Every form says, so a new one cannot get a
    /// picker that opens nothing.
    let inNavigationStack: Bool
    let error: String?
    @ViewBuilder let content: Content
    @AccessibilityFocusState private var bannerFocused: Bool

    var body: some View {
        ScrollViewReader { proxy in
            Form {
                if let error {
                    ErrorBanner(message: error)
                        .id(FormErrorRow.banner)
                        .accessibilityFocused($bannerFocused)
                }
                content
            }
            .modifier(ReadablePickers(inNavigationStack: inNavigationStack))
            .onChange(of: error) { message in
                guard message != nil else { return }
                // After this update's layout, so the banner row exists to scroll to.
                DispatchQueue.main.async {
                    withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(FormErrorRow.banner, anchor: .top) }
                }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    bannerFocused = true
                }
            }
        }
    }
}

extension View {
    /// Swipe-down and the VoiceOver escape gesture (two-finger scrub) ask before throwing away
    /// what was typed, and nothing closes the sheet while a save is running (its result, or its
    /// error, would land on a sheet that is gone). The sheet's Cancel sets `asking` the same way.
    func discardChangesGuard(hasChanges: Bool, busy: Bool, question: String,
                             asking: Binding<Bool>, discard: @escaping () -> Void) -> some View {
        self
            .interactiveDismissDisabled(busy || hasChanges)
            .accessibilityAction(.escape) {
                if busy { announce("Still saving. Wait for it to finish.") }
                else if hasChanges { asking.wrappedValue = true }
                else { discard() }
            }
            .confirmationDialog(question, isPresented: asking, titleVisibility: .visible) {
                Button("Discard", role: .destructive, action: discard)
                Button("Keep editing", role: .cancel) {}
            }
    }
}

/// One of the organisation's own fields (4.25.0), drawn by its type. "" is not answered.
struct CustomFieldEditor: View {
    let field: CustomFieldEdit
    @Binding var value: String

    private var label: String { field.required ? "\(field.label) (required)" : field.label }
    private var hint: String? { field.hint.isEmpty ? nil : field.hint }

    var body: some View {
        switch field.type {
        case "textarea":
            captioned { TextField(label, text: $value, prompt: Text(field.label), axis: .vertical).lineLimit(2...6) }
        case "number":
            captioned { TextField(label, text: $value, prompt: Text(field.label)).keyboardType(.decimalPad) }
        case "date":
            Picker(label, selection: Binding(
                get: { !value.isEmpty },
                set: { value = $0 ? (value.isEmpty ? APIDate.day(Date()) : value) : "" })) {
                Text("Not answered").tag(false)
                Text("Choose a date").tag(true)
            }
            .accessibilityHint(hint ?? "")
            if !value.isEmpty {
                DatePicker(field.label, selection: Binding(
                    get: { APIDate.parseDay(value) ?? Date() },
                    set: { value = APIDate.day($0) }), displayedComponents: .date)
            }
        case "choice", "yesno":
            Picker(label, selection: $value) {
                Text("Not answered").tag("")
                // A stored answer since removed from the list stays choosable.
                ForEach(field.options.contains(value) || value.isEmpty ? field.options : field.options + [value], id: \.self) { Text($0).tag($0) }
            }
            .accessibilityHint(hint ?? "")
        default:
            captioned { TextField(label, text: $value, prompt: Text(field.label)) }
        }
    }

    /// The name stays visible above the field (as CaptionedField); the hint is read with the
    /// field, not as a stop of its own.
    private func captioned<F: View>(@ViewBuilder _ field: () -> F) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
                .accessibilityHidden(true)
            field().accessibilityHint(hint ?? "")
            if let hint {
                Text(hint).font(.caption).foregroundStyle(Color.secondaryText).accessibilityHidden(true)
            }
        }
    }
}
