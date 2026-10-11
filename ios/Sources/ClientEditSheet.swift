import SwiftUI

struct ClientEditSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let existing: ClientInfo?          // nil = create new client
    /// The saved client's id and the result to tell the user. The presenter shows it and
    /// announces it once the sheet has gone: closing the sheet moves VoiceOver focus, which
    /// would cut off an announcement made from here.
    let onSaved: (_ clientId: Int, _ message: String) -> Void

    @State private var name = ""
    @State private var phone = ""
    @State private var email = ""
    @State private var address = ""
    @State private var status = "Pending Assessment"
    @State private var statusNote = ""
    @State private var counselorName = ""
    @State private var counselorAgency = ""
    @State private var counselorEmail = ""
    @State private var counselorPhone = ""
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
    @State private var busy = false
    @State private var error: String?

    /// The form is filled from the record before it is first drawn, so it is never shown blank
    /// (and can't be saved with defaults) while the staff list is still loading.
    init(existing: ClientInfo?, onSaved: @escaping (_ clientId: Int, _ message: String) -> Void) {
        self.existing = existing
        self.onSaved = onSaved
        guard let existing else { return }
        _name = State(initialValue: existing.name)
        _phone = State(initialValue: existing.phone ?? "")
        _email = State(initialValue: existing.email ?? "")
        _address = State(initialValue: existing.address ?? "")
        _status = State(initialValue: existing.status)
        _assignedTo = State(initialValue: existing.assignedTo ?? 0)
        _counselorName = State(initialValue: existing.counselorName ?? "")
        _counselorAgency = State(initialValue: existing.counselorAgency ?? "")
        _counselorEmail = State(initialValue: existing.counselorEmail ?? "")
        _counselorPhone = State(initialValue: existing.counselorPhone ?? "")
        _clientType = State(initialValue: existing.clientType ?? "VR")
        _a11yScreenReader = State(initialValue: existing.a11yScreenReader ?? false)
        _a11yFormat = State(initialValue: existing.a11yFormat ?? "")
        _a11yPrimaryTech = State(initialValue: existing.a11yPrimaryTech ?? "")
        _a11yContact = State(initialValue: existing.a11yContact ?? "")
        _a11yNotes = State(initialValue: existing.a11yNotes ?? "")
    }

    private var statusChanged: Bool { existing != nil && existing?.status != status }

    /// Every field as text, to tell whether anything was changed since the form opened.
    private var values: [String] {
        [name, phone, email, address, status, statusNote, counselorName, counselorAgency,
         counselorEmail, counselorPhone, String(assignedTo), clientType, a11yScreenReader ? "1" : "0",
         a11yFormat, a11yPrimaryTech, a11yContact, a11yNotes]
    }
    /// The same fields as the form opens with them (see init and the property defaults).
    private static func values(of c: ClientInfo?) -> [String] {
        [c?.name ?? "", c?.phone ?? "", c?.email ?? "", c?.address ?? "", c?.status ?? "Pending Assessment", "",
         c?.counselorName ?? "", c?.counselorAgency ?? "", c?.counselorEmail ?? "", c?.counselorPhone ?? "",
         String(c?.assignedTo ?? 0), c?.clientType ?? "VR", (c?.a11yScreenReader ?? false) ? "1" : "0",
         c?.a11yFormat ?? "", c?.a11yPrimaryTech ?? "", c?.a11yContact ?? "", c?.a11yNotes ?? ""]
    }
    private var hasChanges: Bool { values != Self.values(of: existing) }
    @State private var askingDiscard = false

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                // Each name stays visible above its field (a filled-in edit form showed bare values).
                Section("Client") {
                    CaptionedField(label: "Full name (required)", text: $name, prompt: "Full name")
                    CaptionedField(label: "Phone", text: $phone, keyboard: .phonePad)
                    CaptionedField(label: "Email", text: $email, keyboard: .emailAddress).textInputAutocapitalization(.never)
                    CaptionedField(label: "Address", text: $address)
                }
                Section("Case status") {
                    Picker("Status", selection: $status) {
                        ForEach(clientStatuses, id: \.self) { Text($0) }
                    }
                    if statusChanged {
                        CaptionedField(label: "Reason for status change", text: $statusNote)
                        Text("Recorded in the audit history.").font(.caption).foregroundStyle(Color.secondaryText)
                        // Said before Save: the email can't be called back once it has gone.
                        if status == "Closed - Rehabilitated" {
                            Label("Saving with this status emails the client an outcome survey, if they have an email address and were not sent one in the last 30 days.",
                                  systemImage: "envelope")
                                .font(.callout)
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
                            if assignedTo != 0 && !staff.contains(where: { $0.id == assignedTo }) {
                                Text(staff.isEmpty ? "Current instructor" : "Current instructor (inactive)").tag(assignedTo)
                            }
                            ForEach(staff) { member in Text(member.name).tag(member.id) }
                        }
                    }
                }
                Section("Vocational rehabilitation (VR) counselor") {
                    CaptionedField(label: "Counselor name", text: $counselorName)
                    CaptionedField(label: "Agency / district", text: $counselorAgency)
                    CaptionedField(label: "Counselor email", text: $counselorEmail, keyboard: .emailAddress).textInputAutocapitalization(.never)
                    CaptionedField(label: "Counselor phone", text: $counselorPhone, keyboard: .phonePad)
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
        }
    }

    private func loadStaff() async {
        guard !api.scoped else { return }
        staffError = nil
        do {
            let response: StaffResponse = try await api.call("staff")
            staff = response.staff
        } catch {
            staffError = "Could not load the staff list, so an instructor can't be chosen. \(error.localizedDescription)"
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
            let result: SaveResult = try await api.call("save_client", body: [
                "id": existing?.id ?? 0,
                "name": name, "phone": phone, "email": email, "address": address,
                "status": status, "status_note": statusNote, "assigned_to": assignedTo,
                "counselor_name": counselorName, "counselor_agency": counselorAgency,
                "counselor_email": counselorEmail, "counselor_phone": counselorPhone,
                "client_type": clientType, "a11y_screen_reader": a11yScreenReader ? 1 : 0,
                "a11y_format": a11yFormat, "a11y_primary_tech": a11yPrimaryTech,
                "a11y_contact": a11yContact, "a11y_notes": a11yNotes,
            ])
            // Saving an archived record with an open status re-opens it, as on the website.
            var message = result.unarchived
                ? "Client saved. The archived record was re-opened and is back in the active client lists."
                : "Client saved."
            // Closing a case as rehabilitated emails the outcome survey; a failed send is said,
            // as on the website, so it can be retried. A client with no email address is not
            // surveyed, so that is not reported as a failure.
            if result.surveySent == true {
                message += " An outcome survey was emailed to the client."
            } else if result.surveySent == false && email.trimmingCharacters(in: .whitespaces).contains("@") {
                message += " The outcome survey email could not be sent. Close the case again later or check the email settings."
            }
            onSaved(result.clientId > 0 ? result.clientId : (existing?.id ?? 0), message)
            dismiss()
        } catch {
            self.error = error.localizedDescription
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
            ErrorFocusForm(error: error) {
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
        } catch {
            self.error = error.localizedDescription
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
            } else {
                TextField(label, text: $text, prompt: Text(prompt ?? label))
                    .keyboardType(keyboard)
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
            .onChange(of: error) { message in
                guard message != nil else { return }
                // After this update's layout, so the banner row exists to scroll to.
                DispatchQueue.main.async {
                    withAnimation { proxy.scrollTo(FormErrorRow.banner, anchor: .top) }
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
