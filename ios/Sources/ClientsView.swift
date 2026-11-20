import SwiftUI

/// The client list's filters as chosen on this phone (4.35.0), as clients.php's filter form.
/// `who` is nil until a caseload is chosen here: the server then uses the remembered one.
struct ClientListQuery: Equatable {
    var status = ""
    var who: String?
    var type = ""
    var agency = ""
    var school = ""
    var office = 0

    /// Any filter other than the caseload is set (a search needs the server, as these do).
    var narrows: Bool { !status.isEmpty || !type.isEmpty || !agency.isEmpty || !school.trimmingCharacters(in: .whitespaces).isEmpty || office > 0 }
}

struct ClientsView: View {
    @EnvironmentObject var api: API
    @State private var clients: [ClientRow] = []
    /// How many clients matched in all (4.34.0), for "first 200 of N".
    @State private var total: Int?
    @State private var search = ""
    @State private var error: String?
    @State private var loaded = false
    /// Set while the list is the copy kept on this phone (the server could not be reached):
    /// when it was saved.
    @State private var offlineSince: Date?
    @State private var showingNewClient = false
    /// The result of the last client save, shown here; announced once its sheet has gone.
    @State private var confirmation: String?
    @State private var sheetResult: String?
    /// 4.35.0: the filters chosen, and the choices and caseload in use the server sent (nil
    /// from an older server, which hides the Filters button).
    @State private var query = ClientListQuery()
    @State private var filters: ClientListFilters?
    @State private var showingFilters = false
    /// Said once the list has reloaded after Apply filters.
    @State private var announceCount = false

    /// The query clients is sent (only the filters that are set).
    static func clientsQuery(_ f: ClientListQuery, search: String) -> [String: String] {
        var q: [String: String] = ["with_filters": "1"]
        if !search.isEmpty { q["q"] = search }
        if !f.status.isEmpty { q["status"] = f.status }
        if let who = f.who { q["who"] = who }
        if !f.type.isEmpty { q["type"] = f.type }
        if !f.agency.isEmpty { q["agency"] = f.agency }
        let school = f.school.trimmingCharacters(in: .whitespaces)
        if !school.isEmpty { q["school"] = school }
        if f.office > 0 { q["office"] = String(f.office) }
        return q
    }

    /// How many filters are on: the caseload counts when it is not everyone's (a caseload-only
    /// account always sees only its own, which is no filter of its own).
    static func filtersOnCount(_ f: ClientListQuery, who: String, scoped: Bool) -> Int {
        // The caseload counts whenever it is not Everyone's clients (""), as on the Android app.
        [!f.status.isEmpty, !who.isEmpty, !f.type.isEmpty, !f.agency.isEmpty,
         !f.school.trimmingCharacters(in: .whitespaces).isEmpty, f.office > 0].filter { $0 }.count
    }

    /// The list's heading and its "first 200" line, in the website's words.
    static func clientsHeading(shown: Int, total: Int?, filtered: Bool) -> (heading: String?, more: String?) {
        let all = total ?? shown
        let heading: String? = filtered ? "Clients matching the filters (\(all))" : nil
        guard all > shown else { return (heading, nil) }
        // clients.php's sentence, word for word (its count with thousands separators).
        return (heading, "Showing the first \(shown) of \(websiteNumber(all)) matching clients, in name order. Search by name or narrow the filters to find the rest.")
    }

    /// A count as the website prints it (number_format: 1,234).
    static func websiteNumber(_ n: Int) -> String {
        let f = NumberFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.numberStyle = .decimal
        f.groupingSeparator = ","
        f.usesGroupingSeparator = true
        f.groupingSize = 3
        return f.string(from: NSNumber(value: n)) ?? String(n)
    }

    private var filtersOn: Int { Self.filtersOnCount(query, who: query.who ?? filters?.who ?? "", scoped: api.scoped) }

    var body: some View {
        NavigationStack {
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
                if let confirmation {
                    Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
                }
                if let offlineSince { OfflineNotice(savedAt: offlineSince).listRowSeparator(.hidden) }
                // 4.35.0: the website's filters, beside the search field.
                if filters != nil {
                    Button { showingFilters = true } label: {
                        Label(filtersOn > 0 ? "Filters, \(filtersOn) on" : "Filters", systemImage: "line.3.horizontal.decrease.circle")
                            .frame(minHeight: 44, alignment: .leading)
                            .contentShape(Rectangle())
                    }
                    .disabled(offlineSince != nil)
                }
                let wording = Self.clientsHeading(shown: clients.count, total: total, filtered: filtersOn > 0)
                if loaded, let heading = wording.heading {
                    Text(heading).font(.headline).accessibilityAddTraits(.isHeader)
                }
                if !loaded && error == nil {
                    ProgressView("Loading clients…")
                } else if loaded && clients.isEmpty && error == nil {
                    // "Still loading", "failed" and "nothing matched" each say so.
                    Text(!search.isEmpty ? "No clients found for “\(search)”."
                         : filtersOn > 0 ? "No clients match the filters."
                         : (api.scoped ? "No clients on your caseload." : "No clients yet."))
                        .foregroundStyle(Color.secondaryText)
                }
                ForEach(clients) { client in
                    NavigationLink(value: client.id) {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(client.name).font(.body.weight(.semibold))
                            Text(client.status).font(.callout).foregroundStyle(Color.secondaryText)
                        }
                    }
                }
                // The server sends at most 200 clients; it says how many matched (4.34.0).
                if let more = wording.more {
                    Text(more).font(.callout).foregroundStyle(Color.secondaryText)
                } else if total == nil && clients.count >= 200 {
                    Text("Showing the first 200 clients. Search by name to find others.")
                        .font(.callout).foregroundStyle(Color.secondaryText)
                }
            }
            .navigationTitle("Clients")
            .navigationDestination(for: Int.self) { id in
                ClientDetailView(clientId: id)
            }
            .toolbar {
                Button { confirmation = nil; showingNewClient = true } label: {
                    Label("Add client", systemImage: "person.badge.plus")
                }
                .disabled(offlineSince != nil)
            }
            .sheet(isPresented: $showingNewClient, onDismiss: announceSheetResult) {
                ClientEditSheet(existing: nil) { _, message in
                    confirmation = message; sheetResult = message
                    Task { await load() }
                }
            }
            .sheet(isPresented: $showingFilters, onDismiss: {
                guard announceCount else { return }
                Task { await load(announceFilters: true) }
            }) {
                if let filters {
                    ClientFiltersSheet(choices: filters, current: query, currentWho: query.who ?? filters.who) { chosen in
                        query = chosen
                        announceCount = true
                    }
                }
            }
            .searchable(text: $search, prompt: "Search clients")
            .onChange(of: search) { _ in Task { await searchChanged() } }
            .refreshable { confirmation = nil; await load() }
            .task { await load() }
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    /// Waits for a pause in typing, so each keystroke doesn't send a request (or an announcement).
    private func searchChanged() async {
        let q = search
        try? await Task.sleep(nanoseconds: 350_000_000)
        guard q == search else { return }
        await load(announceResult: !q.isEmpty)
    }

    private func load(announceResult: Bool = false, announceFilters: Bool = false) async {
        let q = search
        let f = query
        announceCount = false
        do {
            error = nil
            // With no signal, the list as last sent by the server is shown (a search or a filter
            // needs the server, so it is not answered from the saved copy).
            let response: ClientsResponse
            let savedAt: Date?
            if q.isEmpty && !f.narrows {
                // The caseload chosen here has its own saved copy, so with no signal it still shows.
                (response, savedAt) = try await api.callCached("clients", query: Self.clientsQuery(f, search: ""),
                                                               cacheKey: f.who.map { "clients-who-\($0)" } ?? "clients")
            } else {
                response = try await api.call("clients", query: Self.clientsQuery(f, search: q))
                savedAt = nil
            }
            guard q == search, f == query else { return } // a newer search or filter superseded this request
            clients = response.clients
            total = response.total
            if let sent = response.filters { filters = sent }
            loaded = true
            offlineSince = savedAt
            // Focus stays in the search field, so the outcome of a search is spoken.
            if announceResult {
                announce(clients.isEmpty ? "No clients found." : "\(clients.count) client\(clients.count == 1 ? "" : "s") found.")
            } else if announceFilters {
                let n = total ?? clients.count
                announce(filtersOn > 0 ? "\(n) client\(n == 1 ? "" : "s") match the filters." : "Filters cleared. \(n) client\(n == 1 ? "" : "s").")
            }
        } catch {
            guard q == search else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// The client list's filters (4.35.0), as clients.php's form: Case status, Caseload, Client
/// type, Funding agency, School and Office. A changed caseload is remembered for the website
/// too (clients_filter).
struct ClientFiltersSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let choices: ClientListFilters
    let current: ClientListQuery
    /// The caseload in use when the sheet opened.
    let currentWho: String
    let onApply: (ClientListQuery) -> Void
    @State private var status = ""
    @State private var who = ""
    @State private var type = ""
    @State private var agency = ""
    @State private var school = ""
    @State private var office = 0
    @State private var busy = false
    @State private var error: String?

    init(choices: ClientListFilters, current: ClientListQuery, currentWho: String, onApply: @escaping (ClientListQuery) -> Void) {
        self.choices = choices
        self.current = current
        self.currentWho = currentWho
        self.onApply = onApply
        _status = State(initialValue: current.status)
        _who = State(initialValue: currentWho)
        _type = State(initialValue: current.type)
        _agency = State(initialValue: current.agency)
        _school = State(initialValue: current.school)
        _office = State(initialValue: current.office)
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Picker("Case status", selection: $status) {
                    Text("All statuses").tag("")
                    ForEach(choices.statuses, id: \.self) { Text($0).tag($0) }
                }
                if !choices.whoChoices.isEmpty {
                    Picker("Caseload", selection: $who) {
                        ForEach(choices.whoChoices, id: \.value) { Text($0.label).tag($0.value) }
                    }
                    .errorField("Caseload")
                }
                Picker("Client type", selection: $type) {
                    Text("All types").tag("")
                    ForEach(choices.types, id: \.self) { Text($0).tag($0) }
                }
                Picker("Funding agency", selection: $agency) {
                    Text("All agencies").tag("")
                    ForEach(choices.agencies, id: \.self) { Text($0).tag($0) }
                }
                CaptionedField(label: "School (contains)", text: $school)
                if !choices.offices.isEmpty {
                    Picker("Office", selection: $office) {
                        Text("All offices").tag(0)
                        ForEach(choices.offices, id: \.id) { Text($0.name).tag($0.id) }
                    }
                }
                Button(busy ? "Applying…" : "Apply filters") { Task { await apply() } }
                    .disabled(busy)
                Button("Clear filters") {
                    status = ""; type = ""; agency = ""; school = ""; office = 0
                    // Everyone's clients, when it is offered (a caseload-only account keeps its own).
                    if choices.whoChoices.contains(where: { $0.value == "" }) { who = "" }
                    Task { await apply() }
                }
                .disabled(busy)
            }
            .navigationTitle("Filter clients")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
            }
        }
    }

    private func apply() async {
        var chosen = ClientListQuery(status: status, who: current.who, type: type, agency: agency, school: school, office: office)
        if !choices.whoChoices.isEmpty && who != currentWho {
            // Remembered for the website's list too, as its filter form does (clients_filter).
            busy = true; error = nil
            defer { busy = false }
            var refusedFields: [String: String] = [:]
            do {
                let _: OKResponse = try await api.call("clients_filter", body: ["who": who], refusal: { reply in
                    refusedFields = (reply["errors"] as? [String: String]) ?? [:]
                })
            } catch {
                showFormError(refusedFields["who"] ?? error.localizedDescription, in: &self.error,
                              field: refusedFields["who"] != nil ? "Caseload" : nil)
                return
            }
            chosen.who = who
        }
        onApply(chosen)
        dismiss()
    }
}

struct ClientDetailView: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    let clientId: Int
    /// A note to put VoiceOver on once the record has loaded (opened from Documentation review).
    var focusNoteId: Int? = nil
    @State private var detail: ClientDetail?
    /// When the record shown is the copy kept on this phone (no signal), nil when it is live.
    @State private var offlineSince: Date?
    @State private var showingVoice = false
    @AccessibilityFocusState private var focusedNote: Int?
    @State private var error: String?
    @State private var showingNoteSheet = false
    @State private var editingNote: Note?
    /// A locked note being corrected with an addendum (3.0.36).
    @State private var addendumNote: Note?
    @State private var showingEditClient = false
    @State private var showingUpload = false
    @State private var showingAISummary = false
    @State private var showingLogHours = false
    /// Bumped on every refresh after the first load, so the sections that load on their own
    /// (assessments, homework, documents) reload too — after an upload, a note with homework,
    /// or pull-to-refresh.
    @State private var sectionReload = 0
    /// Notes older than the newest 50, fetched on request.
    @State private var olderNotes: [Note] = []
    @State private var loadingOlderNotes = false
    /// Why "Load older notes" failed, shown by that button: the screen's banner is the list's
    /// first row, which is not drawn (or heard) while the person is down at the button.
    @State private var olderNotesError: String?
    /// Whether the client's email is confirmed (clients.email_verified; nil from a server
    /// that does not report it), for the edit form.
    @State private var emailVerified: Bool?
    /// Bumped by every reload of the record: an older-notes page fetched against the list as
    /// it was before is dropped instead of being appended at the wrong place.
    @State private var notesGeneration = 0
    /// Reloads of the record still running. "Load older notes" waits for them: a page added
    /// meanwhile would be announced and then replaced by the reload's own list.
    @State private var recordLoads = 0
    /// The result of the last client edit, shown here; announced once its sheet has gone.
    @State private var confirmation: String?
    @State private var sheetResult: String?
    /// Notes shown in full rather than as a six-line preview.
    @State private var expandedNotes: Set<Int> = []
    @State private var showingAddTrack = false
    @State private var showingConsent = false
    @State private var showingReleaseRequest = false
    /// Asking before a new portal link is emailed (3.0.40).
    @State private var confirmingPortalLink = false
    @State private var sendingPortalLink = false
    /// The guardian consent as the website's client page shows it (request_consent); nil until
    /// loaded, or from a server that does not have it.
    @State private var consent: ConsentStatusResponse?
    @Environment(\.dynamicTypeSize) private var typeSize

    private var shownNotes: [Note] { (detail?.notes ?? []) + olderNotes }

    private var notesHeader: String {
        let shown = shownNotes.count
        if let total = detail?.notesTotal, total > shown { return "Notes (latest \(shown) of \(total))" }
        if detail?.notesTotal == nil && shown >= 50 { return "Notes (latest \(shown))" }
        return "Notes (\(shown))"
    }

    /// The address with directions, the school, and the counselor with a call link (4.0.0),
    /// kept out of the body so it type-checks quickly.
    @ViewBuilder
    private func contactExtras(_ c: ClientInfo) -> some View {
            // The address opens Maps for directions (4.0.0).
            if let address = c.address, !address.isEmpty {
                if let url = mapsURL(address) {
                    Link(destination: url) { LabeledContent("Address, directions", value: address) }
                } else {
                    LabeledContent("Address", value: address)
                }
            }
            if let school = c.school, !school.isEmpty {
                LabeledContent("School", value: school)
            }
            if let counselor = c.counselorName, !counselor.isEmpty {
                LabeledContent("Vocational rehabilitation (VR) counselor", value: counselor)
            }
            if let cPhone = c.counselorPhone, !cPhone.isEmpty {
                if let url = telURL(cPhone) {
                    Link(destination: url) { LabeledContent("Counselor phone", value: cPhone) }
                } else {
                    LabeledContent("Counselor phone", value: cPhone)
                }
            }
    }

    /// The client type (with the school for Pre-ETS) and date of birth (4.34.0), as the website shows them.
    @ViewBuilder
    private func clientTypeAndBirth(_ c: ClientInfo) -> some View {
        if let type = c.clientType, !type.isEmpty {
            let school = (c.school ?? "").trimmingCharacters(in: .whitespaces)
            LabeledContent("Client type", value: type == "Pre-ETS" && !school.isEmpty ? "\(type) · \(school)" : type)
        }
        if let dob = c.dob, !dob.isEmpty {
            LabeledContent("Date of birth", value: formatDate(dob))
        }
    }

    /// "On Hold: waiting for the authorization. Expected back Dec 1, 2026." (4.35.0), as the
    /// website's overview; nil when neither the reason nor the date is recorded.
    static func onHoldLine(reason: String?, until: String?) -> String? {
        let r = (reason ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        let u = (until ?? "").trimmingCharacters(in: .whitespaces)
        guard !r.isEmpty || !u.isEmpty else { return nil }
        var line = r.isEmpty ? "On Hold." : "On Hold: \(r)\(r.hasSuffix(".") ? "" : ".")"
        if !u.isEmpty { line += " Expected back \(formatDate(u))." }
        return line
    }

    /// "Low vision, since age 12" (4.35.0); nil when neither is recorded.
    static func visionText(status: String?, onset: Int?) -> String? {
        let s = (status ?? "").trimmingCharacters(in: .whitespaces)
        switch (s.isEmpty, onset) {
        case (true, nil): return nil
        case (false, nil): return s
        case (true, let age?): return "Since age \(age)"
        case (false, let age?): return "\(s), since age \(age)"
        }
    }

    /// The rest of the website's overview (4.35.0), in its order: VR case number, IPE goal and
    /// date, counselor agency, guardian email, vision, attendance and client since.
    @ViewBuilder
    private func overviewExtras(_ detail: ClientDetail) -> some View {
        let c = detail.client
        if let v = c.vrCaseNumber, !v.isEmpty { LabeledContent("VR case number", value: v) }
        if let g = c.ipeGoal, !g.isEmpty { LabeledContent("IPE goal", value: g) }
        if let d = c.ipeDate, !d.isEmpty { LabeledContent("IPE date", value: formatDate(d)) }
        if let a = c.counselorAgency, !a.isEmpty { LabeledContent("Counselor agency", value: a) }
        if let gEmail = c.guardianEmail, !gEmail.isEmpty {
            if let url = mailtoURL(gEmail) {
                Link(destination: url) { LabeledContent("Guardian email", value: gEmail) }
            } else {
                LabeledContent("Guardian email", value: gEmail)
            }
        }
        if let vision = Self.visionText(status: detail.extras.visionStatus, onset: detail.extras.visionOnsetAge) {
            LabeledContent("Vision", value: vision)
        }
        if let attendance = detail.extras.attendance, attendance.any {
            LabeledContent("Attendance", value: attendance.text)
        }
        if let since = detail.extras.createdAt, !since.isEmpty {
            LabeledContent("Client since", value: formatDate(since))
        }
    }

    /// Where the client lives and their office (4.25.0), as the website's client page shows them.
    @ViewBuilder
    private func placeAndOffice(_ detail: ClientDetail) -> some View {
        if let code = detail.client.state, !code.isEmpty {
            LabeledContent("State", value: usStates.first { $0.code == code }?.name ?? code)
        }
        if let zone = detail.client.timeZone, !zone.isEmpty {
            LabeledContent("Time zone", value: timeZoneName(zone))
        }
        if let office = detail.extras.office, !office.isEmpty {
            LabeledContent("Office", value: office)
        }
    }

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let offlineSince { OfflineNotice(savedAt: offlineSince).listRowSeparator(.hidden) }
            if let confirmation {
                Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
            }
            if let detail {
                Section("Contact") {
                    if detail.client.status == "On Hold",
                       let hold = Self.onHoldLine(reason: detail.extras.onHoldReason, until: detail.extras.onHoldUntil) {
                        Text(hold).font(.callout.weight(.semibold))
                    }
                    LabeledContent("Status", value: detail.client.status)
                    clientTypeAndBirth(detail.client)
                    placeAndOffice(detail)
                    if let phone = detail.client.phone, !phone.isEmpty {
                        // A stored number that can't be dialled is still shown, as plain text.
                        if let url = telURL(phone) {
                            Link(destination: url) { LabeledContent("Phone", value: phone) }
                        } else {
                            LabeledContent("Phone", value: phone)
                        }
                    }
                    if let email = detail.client.email, !email.isEmpty {
                        // As with the phone: an address that can't form a mailto: link is still shown.
                        if let url = mailtoURL(email) {
                            Link(destination: url) { LabeledContent("Email", value: email) }
                        } else {
                            LabeledContent("Email", value: email)
                        }
                    }
                    contactExtras(detail.client)
                    if let counselorEmail = detail.client.counselorEmail, !counselorEmail.isEmpty {
                        if let url = mailtoURL(counselorEmail) {
                            Link(destination: url) { LabeledContent("Counselor email", value: counselorEmail) }
                        } else {
                            LabeledContent("Counselor email", value: counselorEmail)
                        }
                    }
                    if let guardian = detail.client.guardianName, !guardian.isEmpty {
                        LabeledContent("Guardian", value: guardian)
                    }
                    if let gPhone = detail.client.guardianPhone, !gPhone.isEmpty {
                        if let url = telURL(gPhone) {
                            Link(destination: url) { LabeledContent("Guardian phone", value: gPhone) }
                        } else {
                            LabeledContent("Guardian phone", value: gPhone)
                        }
                    }
                    overviewExtras(detail)
                    // The organisation's own fields (4.25.0), answered ones only.
                    ForEach(Array(detail.extras.customFields.enumerated()), id: \.offset) { _, field in
                        LabeledContent(field.label, value: field.value)
                    }
                }

                if hasAccessNeeds(detail.client) {
                    Section("Access needs") {
                        if detail.client.a11yScreenReader == true { Label("Uses a screen reader", systemImage: "eye.trianglebadge.exclamationmark") }
                        if let f = detail.client.a11yFormat, !f.isEmpty { LabeledContent("Preferred format", value: f) }
                        if let t = detail.client.a11yPrimaryTech, !t.isEmpty { LabeledContent("Primary assistive technology", value: t) }
                        if let c = detail.client.a11yContact, !c.isEmpty { LabeledContent("Preferred contact", value: c) }
                        if let n = detail.client.a11yNotes, !n.isEmpty { Text(n).font(.callout) }
                    }
                }

                // 4.35.0: after closure, as the website's client page.
                if let after = detail.afterClosure {
                    AfterClosureSection(clientId: clientId, clientName: detail.client.name, state: after) { message in
                        confirmation = message
                        Task { await reload() }
                    }
                }

                if let c = consent?.consent {
                    Section("Guardian consent") {
                        Text(c.summary)
                        if c.canRequest {
                            Button { confirmation = nil; showingConsent = true } label: {
                                Label(c.requestLabel, systemImage: "envelope")
                            }
                        }
                    }
                }

                Section("Client portal") {
                    Button {
                        confirmingPortalLink = true
                    } label: {
                        Label(sendingPortalLink ? "Sending…" : "Email a new portal link", systemImage: "link")
                    }
                    .disabled(sendingPortalLink || (detail.client.email ?? "").isEmpty)
                    .confirmationDialog("Email a new portal link to \(detail.client.email ?? "the client")? Any earlier link stops working.",
                                        isPresented: $confirmingPortalLink, titleVisibility: .visible) {
                        Button("Email portal link") { Task { await sendPortalLink() } }
                        Button("Cancel", role: .cancel) {}
                    }
                }

                Section("Releases of information") {
                    if detail.releases.isEmpty {
                        Text("No release of information on file.").font(.callout)
                    } else {
                        ForEach(detail.releases) { r in Text(r.summary).font(.callout) }
                    }
                    // Only on a server that has it (4.25.0).
                    if detail.extras.supports425 {
                        Button { confirmation = nil; showingReleaseRequest = true } label: {
                            Label("Request a release", systemImage: "envelope")
                        }
                    }
                }

                if detail.extras.supports425 {
                Section("Documents to sign") {
                    NavigationLink {
                        SignaturesView(clientId: clientId, client: detail.client) { Task { await reload() } }
                    } label: {
                        let waiting = detail.extras.signaturesWaiting
                        Label(waiting > 0 ? "Documents to sign, \(waiting) waiting for a signature" : "Documents to sign",
                              systemImage: "signature")
                    }
                }
                }

                Section("Progress tools") {
                    NavigationLink { ScoredAssessmentsView(clientId: clientId) } label: { Label("Scored assessments", systemImage: "list.number") }
                    NavigationLink { CompetencyView(clientId: clientId) } label: { Label("Curriculum & competency", systemImage: "graduationcap") }
                    NavigationLink { ClientHistoryView(clientId: clientId, clientName: detail.client.name) } label: {
                        Label("Status history", systemImage: "clock.arrow.circlepath")
                    }
                }

                if api.aiEnabled && (api.aiHas("recommend") || api.aiHas("intake") || api.aiHas("progress")) {
                    Section("AI tools") {
                        if api.aiHas("recommend") { NavigationLink { AIRecommendView(clientId: clientId) } label: { Label("Assistive technology recommendations", systemImage: "lightbulb") } }
                        if api.aiHas("intake") { NavigationLink { AIIntakeView(clientId: clientId) { Task { await load() } } } label: { Label("Guided intake", systemImage: "wand.and.stars") } }
                        if api.aiHas("progress") { NavigationLink { AIProgressReviewView(clientId: clientId) } label: { Label("Progress review", systemImage: "chart.bar.doc.horizontal") } }
                    }
                }

                if !detail.tracks.isEmpty {
                    Section("Training tracks") {
                        ForEach(detail.tracks) { track in
                            NavigationLink {
                                TrackDetailView(track: track, clientName: detail.client.name)
                            } label: {
                                VStack(alignment: .leading, spacing: 6) {
                                    Text(track.title).font(.body.weight(.semibold))
                                    Text("Authorization \(track.authNumber) · \(track.status)")
                                        .font(.callout).foregroundStyle(Color.secondaryText)
                                    AuthProgressBar(hoursWorked: track.hoursWorked,
                                                    authorizedHours: track.authorizedHours,
                                                    hoursRemaining: track.hoursRemaining,
                                                    pctUsed: track.pctUsed)
                                }
                                .padding(.vertical, 2)
                            }
                        }
                    }
                }

                AssessmentsSection(clientId: clientId, reloadToken: sectionReload)

                HomeworkSection(clientId: clientId, tracks: detail.tracks, reloadToken: sectionReload)
                DocumentsSection(clientId: clientId, reloadToken: sectionReload, sharing: detail.extras.supports425)

                Section(notesHeader) {
                    ForEach(shownNotes) { note in
                        let open = expandedNotes.contains(note.id)
                        VStack(alignment: .leading, spacing: 3) {
                            HStack(alignment: .top) {
                                // The note's text is one element; its buttons stay separate ones.
                                VStack(alignment: .leading, spacing: 3) {
                                    Text(note.subject).font(.body.weight(.semibold))
                                    Text("\(note.noteType) · \(formatDateTime(note.createdAt)) · \(note.author ?? "System")")
                                        .font(.caption).foregroundStyle(Color.secondaryText)
                                    HStack(spacing: 6) {
                                        if let fmt = note.noteFormat, fmt != "free" {
                                            NoteFormatBadge(format: fmt)
                                        }
                                        if let tt = note.trackTitle, !tt.isEmpty {
                                            Label(tt, systemImage: "point.topleft.down.curvedto.point.bottomright.up")
                                                .font(.caption2).foregroundStyle(Color.secondaryText).labelStyle(.titleAndIcon)
                                        }
                                    }
                                    if let updated = note.updatedAt {
                                        // Built in steps: as one expression it was slow to type-check.
                                        let editedBy: String = note.editor.map { " by \($0)" } ?? ""
                                        let edited: String = "Edited \(formatDateTime(updated))\(editedBy)"
                                        Text(edited)
                                            .font(.caption).foregroundStyle(Color.secondaryText).italic()
                                    }
                                    // A preview that opens in full (VoiceOver reads the whole note either way).
                                    Text(note.body).font(.callout).lineLimit(open ? nil : 6)
                                }
                                .accessibilityElement(children: .combine)
                                Spacer()
                                if note.canEdit {
                                    Button {
                                        editingNote = note
                                    } label: {
                                        Image(systemName: "pencil")
                                            .frame(minWidth: 44, minHeight: 44)
                                            .contentShape(Rectangle())
                                    }
                                    .buttonStyle(.borderless)
                                    .accessibilityLabel("Edit note: \(note.subject)")
                                } else if note.locked {
                                    Button {
                                        addendumNote = note
                                    } label: {
                                        Image(systemName: "text.badge.plus")
                                            .frame(minWidth: 44, minHeight: 44)
                                            .contentShape(Rectangle())
                                    }
                                    .buttonStyle(.borderless)
                                    .accessibilityLabel("Add addendum to note: \(note.subject)")
                                }
                            }
                            if mayTruncate(note.body, lines: 6, accessibilitySize: typeSize.isAccessibilitySize) {
                                NoteExpandButton(subject: note.subject, open: open) {
                                    if open { expandedNotes.remove(note.id) } else { expandedNotes.insert(note.id) }
                                }
                            }
                        }
                        .padding(.vertical, 2)
                        // VoiceOver: the whole note is one stop (its full text, whatever the preview
                        // shows); Edit and the preview's Show full note are on the Actions rotor.
                        .accessibilityElement(children: VoiceOverRows.children)
                        .accessibilityLabel(noteSummary(note))
                        .accessibilityFocused($focusedNote, equals: note.id)
                        .accessibilityActions {
                            if note.canEdit {
                                Button("Edit note") { editingNote = note }
                            } else if note.locked {
                                Button("Add addendum") { addendumNote = note }
                            }
                            if mayTruncate(note.body, lines: 6, accessibilitySize: typeSize.isAccessibilitySize) {
                                Button(open ? "Show less" : "Show full note") {
                                    if open { expandedNotes.remove(note.id) } else { expandedNotes.insert(note.id) }
                                }
                            }
                        }
                    }
                    // The record carries the newest 50 notes; older ones are fetched on request.
                    if let total = detail.notesTotal, shownNotes.count < total {
                        Button { Task { await loadOlderNotes() } } label: {
                            Label(loadingOlderNotes ? "Loading older notes…" : "Load older notes (\(total - shownNotes.count) more)",
                                  systemImage: "clock.arrow.circlepath")
                        }
                        .disabled(loadingOlderNotes || recordLoads > 0)
                        // Said by loadOlderNotes when it happens; shown here, next to the button.
                        if let olderNotesError { ErrorBanner(message: olderNotesError, announces: false) }
                    } else if detail.notesTotal == nil && shownNotes.count >= 50 {
                        Text("Showing the latest \(shownNotes.count) notes. Older notes are on the website.")
                            .font(.callout).foregroundStyle(Color.secondaryText)
                    }
                }
            } else if error == nil {
                ProgressView()
            }
        }
        .navigationTitle(detail?.client.name ?? "Client")
        .toolbar {
            // The assistant opened here knows which client is on screen ("read her latest note").
            if api.aiHas("voice") && api.can("clients") {
                Button { showingVoice = true } label: { Label("Voice assistant", systemImage: "mic.fill") }
                    .disabled(detail == nil)
            }
            Menu {
                Button { confirmation = nil; showingNoteSheet = true } label: {
                    Label("Add note", systemImage: "square.and.pencil")
                }
                Button { confirmation = nil; showingEditClient = true } label: {
                    Label("Edit client / status", systemImage: "person.crop.circle.badge.checkmark")
                }
                // The form needs the loaded record; before that it would open as an empty sheet.
                .disabled(detail == nil)
                Button { confirmation = nil; showingUpload = true } label: {
                    Label("Upload document", systemImage: "camera.on.rectangle")
                }
                // Logging hours reads the tracks list, which needs the clients permission (as on
                // Schedule). Opens with this client's only active track chosen, or offers just
                // their tracks.
                if api.can("clients") {
                    Button { confirmation = nil; showingLogHours = true } label: {
                        Label("Log hours", systemImage: "clock.badge.checkmark")
                    }
                    .disabled(detail == nil)
                }
                if api.aiHas("draft") {
                    Button { confirmation = nil; showingAISummary = true } label: {
                        Label("AI case summary", systemImage: "sparkles")
                    }
                }
                Button { confirmation = nil; showingAddTrack = true } label: {
                    Label("Add training track", systemImage: "plus.rectangle.on.rectangle")
                }
                .disabled(detail == nil)
                // Offered when the website's client page offers it: no current consent, one
                // expiring within 30 days, a withdrawn one, or a request still waiting.
                if consent?.consent.canRequest == true {
                    Button { confirmation = nil; showingConsent = true } label: {
                        Label("Send consent request", systemImage: "envelope")
                    }
                }
            } label: {
                Label("Actions", systemImage: "ellipsis.circle")
            }
        }
        // Each sheet hands back what to tell the person; it is shown here and said once the
        // sheet has closed (closing it moves VoiceOver focus, which cut off a message said inside).
        .sheet(isPresented: $showingAddTrack, onDismiss: announceSheetResult) {
            TrackEditSheet(trackId: nil, clientId: clientId, clientName: detail?.client.name ?? "this client") { message, _ in
                confirmation = message; sheetResult = message
                Task { await reload() }
            }
        }
        .sheet(isPresented: $showingConsent, onDismiss: announceSheetResult) {
            if let consent {
                ConsentRequestSheet(clientId: clientId, clientName: detail?.client.name ?? "this client", status: consent) { message in
                    confirmation = message; sheetResult = message
                    Task { await loadConsent() }
                }
            }
        }
        .sheet(isPresented: $showingReleaseRequest, onDismiss: announceSheetResult) {
            if let client = detail?.client {
                ReleaseRequestSheet(clientId: clientId, client: client) { message in
                    confirmation = message; sheetResult = message
                    Task { await reload() }
                }
            }
        }
        .sheet(isPresented: $showingAISummary, onDismiss: announceSheetResult) {
            AICaseSummarySheet(clientId: clientId) { message in
                confirmation = message; sheetResult = message
                Task { await reload() }
            }
        }
        .sheet(isPresented: $showingEditClient, onDismiss: announceSheetResult) {
            if let client = detail?.client {
                ClientEditSheet(existing: client, emailVerified: emailVerified, texting: detail?.texting, extras: detail?.extras) { _, message in
                    confirmation = message; sheetResult = message
                    Task { await reload() }
                }
            }
        }
        .sheet(isPresented: $showingUpload, onDismiss: announceSheetResult) {
            UploadDocumentSheet(clientId: clientId) { message in
                confirmation = message; sheetResult = message
                Task { await reload() }
            }
        }
        // The form says its own results and stays open for another entry; the record reloads
        // once it closes, so the tracks' hours are up to date.
        .sheet(isPresented: $showingLogHours, onDismiss: { Task { await reload() } }) {
            let active = (detail?.tracks ?? []).filter { $0.status == "Active" }
            LogHoursView(initialTrackId: active.count == 1 ? active[0].id : 0, clientId: clientId)
        }
        .sheet(isPresented: $showingNoteSheet, onDismiss: announceSheetResult) {
            AddNoteSheet(clientId: clientId, tracks: detail?.tracks ?? [], existing: nil) { message in
                confirmation = message; sheetResult = message
                Task { await reload() }
            }
        }
        .sheet(item: $editingNote, onDismiss: announceSheetResult) { note in
            AddNoteSheet(clientId: clientId, tracks: detail?.tracks ?? [], existing: note) { message in
                confirmation = message; sheetResult = message
                Task { await reload() }
            }
        }
        .sheet(item: $addendumNote, onDismiss: announceSheetResult) { note in
            AddNoteSheet(clientId: clientId, tracks: detail?.tracks ?? [], existing: nil, addendumTo: note) { message in
                confirmation = message; sheetResult = message
                Task { await reload() }
            }
        }
        .sheet(isPresented: $showingVoice) {
            VoiceView(context: detail.map { VoiceContext(clientId: clientId, clientName: $0.client.name) })
        }
        .refreshable { confirmation = nil; await reload(); await loadConsent() }
        .task { await load(); await loadConsent(); focusFlaggedNote() }
    }

    /// Opened from Documentation review: VoiceOver goes to the flagged note, or is told where it is.
    private func focusFlaggedNote() {
        guard let focusNoteId, detail != nil else { return }
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 600_000_000)   // after the list has drawn
            if shownNotes.contains(where: { $0.id == focusNoteId }) {
                focusedNote = focusNoteId
            } else {
                announce("The flagged note is older than the notes shown. Use Load older notes at the end of the Notes section.")
            }
        }
    }

    /// Email the client a new portal link (send_portal_link), as the website's client page does.
    private func sendPortalLink() async {
        sendingPortalLink = true
        defer { sendingPortalLink = false }
        error = nil
        do {
            var body: [String: Any] = ["client_id": clientId]
            body["idem_key"] = idem.key(for: body)
            let result: TaskSaveResult = try await api.call("send_portal_link", body: body)
            idem.answered()
            confirmation = result.message ?? "Portal link emailed."
            announce(confirmation ?? "")
        } catch {
            idem.failed(error)
            showFormError(IdemKey.message(for: error, what: "the portal link"), in: &self.error)
        }
    }

    /// The consent state for the Guardian consent section and the Send consent request action.
    /// A failure leaves both out (the record itself has its own error banner).
    private func loadConsent() async {
        consent = try? await api.call("request_consent", query: ["client_id": String(clientId)])
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    /// A note row as VoiceOver reads it: subject; type, date and author; format and track;
    /// when it was edited; then the whole text.
    private func noteSummary(_ note: Note) -> String {
        var parts = [note.subject, "\(note.noteType), \(formatDateTime(note.createdAt)), \(note.author ?? "System")"]
        if let fmt = note.noteFormat, fmt != "free" { parts.append("Format: \(noteFormatDescription(fmt))") }
        if let tt = note.trackTitle, !tt.isEmpty { parts.append("Track: \(tt)") }
        if let updated = note.updatedAt {
            let editedBy: String = note.editor.map { " by \($0)" } ?? ""
            parts.append("Edited \(formatDateTime(updated))\(editedBy)")
        }
        parts.append(note.body)
        return parts.joined(separator: ". ")
    }

    /// `olderDepth`: how many older notes to fetch again (default: as many as are open now).
    /// `reportsFailure`: false when the caller says a failure itself (Load older notes).
    private func load(olderDepth: Int? = nil, reportsFailure: Bool = true) async {
        recordLoads += 1
        defer { recordLoads -= 1 }
        notesGeneration += 1
        let generation = notesGeneration
        // Older notes the person opened stay open across a reload (after a note is saved or
        // edited, on returning to this screen): they are fetched again, so an edit shows.
        let depth = olderDepth ?? olderNotes.count
        // The account this load is for: another one signing in while it runs must not get this
        // client's record shown, or its name and tracks kept in that account's offline cache.
        let owner = api.userId
        do {
            error = nil
            // Kept on the phone (4.0.0), so a record opened before can be read with no signal.
            let (record, savedAt): (ClientRecord, Date?) = try await api.callCached("client", query: ["id": String(clientId)], cacheKey: "client-\(clientId)")
            offlineSince = savedAt
            let fresh = record.detail
            var older: [Note] = []
            var known = Set(fresh.notes.map(\.id))
            var offset = fresh.notes.count
            while older.count < depth {
                guard let page: ClientDetail = try? await api.call("client", query: ["id": String(clientId), "notes_offset": String(offset)]),
                      !page.notes.isEmpty else { break }     // what could not be fetched is offered again by the button
                offset += page.notes.count
                let added = page.notes.filter { !known.contains($0.id) }
                known.formUnion(added.map(\.id))
                older += added
            }
            guard generation == notesGeneration else { return }     // a newer reload replaced this one
            guard api.userId == owner, !api.token.isEmpty else { return }
            detail = fresh
            emailVerified = record.emailVerified
            // Kept on this phone, so Log hours can offer this client's tracks with no signal.
            TrackCache.saveClient(id: clientId, name: fresh.client.name, tracks: fresh.tracks, owner: owner)
            olderNotes = older
            olderNotesError = nil
            notesGeneration += 1    // an older-notes page still on its way was computed against the old list
        } catch {
            guard generation == notesGeneration else { return }
            // Said as well as shown: the banner is the list's first row, which is not drawn (or
            // heard) while the person is scrolled down the record.
            if reportsFailure { showFormError(error.localizedDescription, in: &self.error) }
            else { self.error = error.localizedDescription }
        }
    }

    /// A refresh of the whole screen: the record, and the sections that load on their own.
    private func reload() async {
        await load()
        sectionReload += 1
    }

    private func loadOlderNotes() async {
        guard !loadingOlderNotes, recordLoads == 0 else { return }
        loadingOlderNotes = true
        defer { loadingOlderNotes = false }
        let generation = notesGeneration
        olderNotesError = nil
        do {
            error = nil
            let page: ClientDetail = try await api.call("client", query: ["id": String(clientId), "notes_offset": String(shownNotes.count)])
            // The notes were reloaded meanwhile: this page's offset no longer fits the list.
            guard generation == notesGeneration else {
                announce("The notes were refreshed. Load older notes again if you need them.")
                return
            }
            // A note added or deleted elsewhere since the list loaded shifts every older page,
            // so a note at the old boundary would be skipped without a word: the list is
            // fetched again instead, with this page's worth of older notes included.
            if let total = page.notesTotal, let listed = detail?.notesTotal, total != listed {
                await load(olderDepth: olderNotes.count + max(page.notes.count, 1), reportsFailure: false)
                if let failure = error {
                    // Shown by the button and said, not left in the banner at the top, out of view.
                    error = nil
                    olderNotesFailed(failure)
                    return
                }
                announce("The notes changed since this screen opened, so the list was reloaded. It now shows \(shownNotes.count) of \(detail?.notesTotal ?? total) notes.")
                return
            }
            // A note added meanwhile shifts the pages by one: never list a note twice.
            let known = Set(shownNotes.map(\.id))
            let added = page.notes.filter { !known.contains($0.id) }
            olderNotes += added
            announce(added.isEmpty ? "No older notes." : "\(added.count) older note\(added.count == 1 ? "" : "s") loaded.")
        } catch {
            olderNotesFailed(error.localizedDescription)
        }
    }

    /// A failed "Load older notes": shown under the button and said once (the banner there
    /// does not announce itself, so it is not heard twice).
    private func olderNotesFailed(_ reason: String) {
        let message = "Could not load older notes. \(reason)"
        olderNotesError = message
        announce(message)
    }
}

/// The client record, with whether the client's email is confirmed (clients.email_verified:
/// false while an address from the public referral form waits for staff to confirm it; nil
/// from a server that does not report it).
private struct ClientRecord: Decodable {
    let detail: ClientDetail
    let emailVerified: Bool?
    private enum Keys: String, CodingKey { case client }
    private enum ClientKeys: String, CodingKey { case emailVerified }
    init(from d: Decoder) throws {
        detail = try ClientDetail(from: d)
        let client = try? d.container(keyedBy: Keys.self).nestedContainer(keyedBy: ClientKeys.self, forKey: .client)
        if let flag = try? client?.decodeIfPresent(Bool.self, forKey: .emailVerified) { emailVerified = flag }
        else if let number = try? client?.decodeIfPresent(Int.self, forKey: .emailVerified) { emailVerified = number != 0 }
        else { emailVerified = nil }
    }
}

struct AddNoteSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    @Environment(\.dynamicTypeSize) private var typeSize
    let clientId: Int
    var tracks: [Track] = []
    var initialTrackId = 0
    let existing: Note?
    /// A new note written as an addendum to this (locked) note.
    var addendumTo: Note? = nil
    /// The note being edited locked before the edit was saved (4.34.0): the form became an
    /// addendum to it, keeping what was typed, as the website does.
    @State private var lockedParent: Note?
    /// The note being edited (nil once it became an addendum).
    private var editNote: Note? { lockedParent == nil ? existing : nil }
    /// The note a new addendum is to: the locked edit's note, or the one it opened for.
    private var addendumParent: Note? { lockedParent ?? addendumTo }
    /// 4.31.0: the case note for a completed session, opened straight after the completion. The
    /// subject it starts with ("Session on Oct 20"), the session it is linked to (sent with
    /// add_note as session_id), the line saying where the person is ("Case note for Jane Doe,
    /// session on Oct 20", shown at the top), and what is said as it opens.
    var initialSubject: String? = nil
    var sessionId: Int? = nil
    var sessionContext: String? = nil
    var arrivalAnnouncement: String? = nil
    /// The way out without saving (4.35.0: "Skip for now" while a group's notes are walked through).
    var cancelLabel = "Cancel"
    /// The result to tell the person. The presenter shows it and says it once the sheet has
    /// gone: closing the sheet moves VoiceOver focus, which cut off a message said from here.
    let onSaved: (String) -> Void

    private var noteTypes: [String] { FormOptions.list("note_types", ["Case Note", "Phone Call", "Progress Report", "Monthly Summary", "Intake", "Addendum"]) }
    @State private var noteType = "Case Note"
    @State private var noteFormat = "free"
    @State private var subject = ""
    @State private var body_ = ""
    @State private var sections = ["", "", "", ""]
    /// The note as last loaded (4.29.0): `existing`, or the one Reload fetched after a conflict.
    /// Its note_version is sent with update_note, so an edit never replaces a newer save.
    @State private var loadedNote: Note?
    /// The edit was refused because someone else saved the note since it was opened.
    @State private var noteConflict = false
    @State private var reloadingNote = false
    /// What was typed before Reload replaced it with the saved note, shown so it can be redone.
    @State private var typedBeforeReload: String?
    @State private var attachTrackId = 0          // 0 = none
    @State private var busy = false
    @State private var aiBusy = false
    @State private var error: String?
    /// One key for this note, sent on every try: a retry after a lost reply is recognised by
    /// the server instead of saving the note twice.
    @State private var idemKey = UUID().uuidString
    /// What the first try of a new note sent, to tell whether a deduplicated retry lost edits.
    @State private var firstTryContent: [String]?

    // Generator state
    @State private var messy = ""
    @State private var genBusy = false
    @State private var questions: [String] = []
    @State private var homework: [GenHomework] = []
    @State private var hwTrackId = 0              // track homework attaches to
    /// The person's own note text from before the AI clean-up, so it can be put back.
    @State private var beforeCleanup: String?
    @State private var askingDiscard = false
    /// A new note's text was put back from this phone's draft when the sheet opened.
    @State private var restoredDraft = false
    /// The "Generate from messy notes" group, folded away until opened.
    @State private var showingGenerator = false

    /// A new note's unsaved text is kept on this phone as it is typed (DraftStore), so a forced
    /// sign-in or the app being closed does not lose it. Cleared once it is saved, queued
    /// offline or discarded.
    private var draftKey: String {
        if let sessionId { return "note-\(clientId)-session-\(sessionId)" }
        return addendumParent.map { "note-\(clientId)-addendum-\($0.id)" } ?? "note-\(clientId)"
    }

    private func saveDraft() {
        guard editNote == nil else { return }
        var fields = ["subject": subject, "body": body_,
                      "sec1": sections[0], "sec2": sections[1], "sec3": sections[2], "sec4": sections[3]]
        // The type and format travel only with text: a draft with nothing typed is removed.
        // A session note's own subject is not something typed: alone it keeps no draft.
        if fields.contains(where: { !$0.value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                                     && !($0.key == "subject" && $0.value == initialSubject) }) {
            fields["note_type"] = noteType
            fields["note_format"] = noteFormat
        }
        DraftStore.save(fields, key: draftKey, owner: api.userId)
    }

    private func restoreDraft() {
        guard existing == nil, subject.isEmpty, body_.isEmpty, sections.allSatisfy({ $0.isEmpty }),
              let draft = DraftStore.load(key: draftKey, owner: api.userId) else { return }
        if let t = draft["note_type"], noteTypes.contains(t) { noteType = t }
        if let f = draft["note_format"], f == "free" || noteFormatLabels[f] != nil { noteFormat = f }
        subject = draft["subject"] ?? ""
        body_ = draft["body"] ?? ""
        sections = [draft["sec1"] ?? "", draft["sec2"] ?? "", draft["sec3"] ?? "", draft["sec4"] ?? ""]
        restoredDraft = true
        // Said after the sheet has opened: its arrival moves VoiceOver focus.
        announceAfterDismiss("Your unsaved note for this client was restored.")
    }

    private func clearDraft() {
        if editNote == nil { DraftStore.clear(key: draftKey, owner: api.userId) }
    }

    private var activeTracks: [Track] { tracks.filter { $0.status == "Active" } }
    /// The tracks the note can be attached to: the active ones, and the one it opened with (a
    /// session's track that has since ended is still offered, already chosen).
    private var attachableTracks: [Track] { tracks.filter { $0.status == "Active" || ($0.id == initialTrackId && initialTrackId > 0) } }
    /// VoiceOver is put here as a session's note opens: the note, or its first section.
    /// (0 the note, 100 + n section n.)
    @AccessibilityFocusState private var focusedField: Int?
    private var labels: [String] { noteFormatLabels[noteFormat] ?? [] }
    private var structured: Bool { noteFormat != "free" }

    /// Anything typed, dictated or generated that closing the sheet would throw away.
    private var hasChanges: Bool {
        if let opened = editNote.map({ loadedNote ?? $0 }) {
            let existing = opened
            let saved = existing.sections?.count == 4 ? existing.sections! : ["", "", "", ""]
            // Every field update_note saves, against what onAppear filled in from the note.
            return subject != existing.subject || body_ != existing.body || sections != saved
                || noteType != existing.noteType || noteFormat != (existing.noteFormat ?? "free")
                || attachTrackId != (existing.trackId ?? 0)
        }
        return (!subject.isEmpty && subject != addendumSubject && subject != initialSubject) || !body_.isEmpty || sections.contains { !$0.isEmpty } || !messy.isEmpty || !homework.isEmpty
    }

    /// The subject an addendum starts with.
    private var addendumSubject: String { addendumParent.map { String(addendumSubjectFor($0.subject).prefix(255)) } ?? "" }

    private func cleanup() async {
        aiBusy = true; error = nil
        let sent = body_
        do {
            // client_id lets the server redact this client's own name, address and other
            // identifiers before the text goes to the AI provider.
            let r: AIDraftResponse = try await api.call("ai_draft", body: ["type": "note", "text": sent, "client_id": clientId])
            if r.truncated == true {
                // A cut-off clean-up would silently drop the end of the note: keep the person's text.
                showFormError("This note is too long to clean up in one go. Your text was kept. Split it into parts and try again.", in: &error)
            } else if body_ == sent {
                // Text typed or dictated while the request ran is never replaced.
                // The first original is kept: a second clean-up must not make a draft "the original".
                if beforeCleanup == nil { beforeCleanup = sent }
                body_ = r.text
                announce("Note cleaned up. Review it before saving. Restore my original text puts back what you wrote.")
            } else {
                showFormError("Note: it changed while the AI was working, so the clean-up was not applied. Tap Clean up with AI again to use it.", in: &error)
            }
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        aiBusy = false
    }

    /// What the generator works from: the raw-notes box, or, when it is empty, the note as
    /// typed so far (the Note or Narrative box, then each section), as the website's Generate
    /// does. Rough notes typed or dictated straight into the note need no second box (4.33.0:
    /// the button was dimmed until the raw-notes box had text, so Generate did nothing for them).
    private var generatorSource: String {
        let raw = messy.trimmingCharacters(in: .whitespacesAndNewlines)
        if !raw.isEmpty { return raw }
        return ([body_] + sections).map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }
            .filter { !$0.isEmpty }.joined(separator: "\n")
    }

    /// Convert messy notes (pasted, or typed into the note itself) into the chosen structured format.
    private func generate() async {
        let source = generatorSource
        guard !source.isEmpty else {
            showFormError("Raw notes: type or dictate your rough notes here, or in the note's own boxes above, then tap Generate structured note.", in: &error)
            return
        }
        genBusy = true; error = nil
        let sentSections = sections
        let wasFree = !structured
        do {
            let fmt = structured ? noteFormat : "BIRP"
            let r: GenerateNoteResponse = try await api.call("ai_generate_note", body: [
                "client_id": clientId, "note_format": fmt, "text": source,
            ])
            // Section text typed or dictated while the request ran is never replaced.
            guard sections == sentSections else {
                showFormError("The \(noteFormat) sections changed while the draft was being generated, so it was not applied. Tap Generate structured note again to replace them.", in: &error)
                genBusy = false
                return
            }
            noteFormat = r.format
            for i in 0..<4 {
                let drafted = i < r.sections.count ? r.sections[i] : ""
                // A section the AI left empty keeps what was typed in it, as on the website.
                sections[i] = drafted.isEmpty ? sentSections[i] : drafted
            }
            questions = r.questions
            homework = r.homework
            if hwTrackId == 0 { hwTrackId = activeTracks.first?.id ?? 0 }
            // The draft fills in higher up the form with nothing moving: say what arrived.
            var summary = "\(r.format) sections drafted."
            if wasFree && structured && !body_.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                summary += " The text you typed in Note is kept as the narrative, saved after the sections."
            }
            if !r.questions.isEmpty { summary += " \(r.questions.count) thing\(r.questions.count == 1 ? "" : "s") to double-check." }
            if !r.homework.isEmpty { summary += " \(r.homework.count) homework item\(r.homework.count == 1 ? "" : "s") found." }
            announce(summary + " Review before saving.")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        genBusy = false
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if noteConflict {
                    // 4.29.0: someone else saved this note since it was opened. What was typed
                    // stays in the form until Reload brings in their version.
                    Section {
                        Button {
                            Task { await reloadNote() }
                        } label: {
                            Text(reloadingNote ? "Reloading…" : "Reload the note as saved now")
                                .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                        }
                        .disabled(reloadingNote || busy)
                        .accessibilityHint("Replaces the form with their version. What you typed is then shown below it, to make your changes again.")
                    }
                }
                if let typedBeforeReload {
                    Section("What you typed (not saved)") {
                        Text(typedBeforeReload).font(.callout).textSelection(.enabled)
                        // Kept on this phone only (no Universal Clipboard) and for ten minutes.
                        Button("Copy what I typed") {
                            UIPasteboard.general.setItems([["public.utf8-plain-text": typedBeforeReload]],
                                                          options: [.localOnly: true, .expirationDate: Date().addingTimeInterval(600)])
                            announce("Copied.")
                        }
                    }
                }
                if editNote != nil {
                    Text("Edits are recorded on the note and in the audit log.")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                } else if restoredDraft {
                    Text("Your unsaved note from earlier was restored. Cancel, then Discard, removes it.")
                        .font(.callout)
                }
                if let sessionContext, editNote == nil {
                    // Where the person is, as the first thing on the screen (4.31.0).
                    Text(sessionContext)
                        .font(.headline)
                        .accessibilityAddTraits(.isHeader)
                }
                Section {
                    Picker("Type", selection: $noteType) { ForEach(noteTypes, id: \.self) { Text($0) } }
                    // Each format with its sections spelled out, as on the website.
                    Picker("Format", selection: $noteFormat) {
                        Text("Free text").tag("free")
                        ForEach(["BIRP", "PAIP", "SOAP"], id: \.self) { Text(noteFormatDescription($0)).tag($0) }
                    }
                    if !attachableTracks.isEmpty {
                        Picker("Attach to track", selection: $attachTrackId) {
                            Text("None").tag(0)
                            ForEach(attachableTracks) { Text($0.title).tag($0.id) }
                        }
                    }
                    CaptionedField(label: "Subject (required)", text: $subject, prompt: "Subject", errorName: "Subject")
                }

                // Free text with section text still in the sections (4.34.0): they are shown, so
                // they can be moved into the note and cleared, as the website refuses to drop them.
                if structured || sectionsHoldText {
                    Section(structured ? "\(noteFormat) sections (fill in at least one)" : "Structured sections (not kept with free text: clear them)") {
                        ForEach(0..<4, id: \.self) { i in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(i < labels.count ? labels[i] : "Section \(i + 1)")
                                    .font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
                                TextField(i < labels.count ? labels[i] : "", text: $sections[i], axis: .vertical)
                                    .lineLimit(2...8)
                                    .accessibilityFocused($focusedField, equals: 100 + i)
                            }
                        }
                    }
                    // A new note keeps its narrative box, as on the website: text typed in it
                    // (before the format changed, or before Generate) is saved after the
                    // sections under "Narrative", never dropped. An edit keeps the note's own
                    // narrative on the server, so it is not shown here.
                    if structured && editNote == nil {
                        Section("Narrative (optional)") {
                            TextField("Narrative", text: $body_, axis: .vertical).lineLimit(3...12)
                            Text("Saved after the sections under “Narrative”. Clear it if the sections already cover it.")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                }
                if !structured {
                    Section("Note (required)") {
                        TextField("Note", text: $body_, axis: .vertical).lineLimit(5...12)
                            .errorField("Note")
                            .accessibilityFocused($focusedField, equals: 0)
                        if api.aiHas("draft") && !body_.isEmpty {
                            Button { Task { await cleanup() } } label: {
                                Label(aiBusy ? "Drafting…" : "Clean up with AI", systemImage: "sparkles")
                            }.disabled(aiBusy)
                        }
                        // The clean-up replaces the text: what was typed or dictated can be put back.
                        if let original = beforeCleanup {
                            Button {
                                body_ = original
                                beforeCleanup = nil
                                announce("Your original text is back in the note.")
                            } label: {
                                Label("Restore my original text", systemImage: "arrow.uturn.backward")
                            }.disabled(aiBusy)
                        }
                    }
                }

                // Generator: paste messy notes → structured draft. After the note itself, folded
                // away until wanted, so Subject leads straight to the note's text.
                if editNote == nil && api.aiHas("draft") {
                    Section {
                        DisclosureGroup(isExpanded: $showingGenerator) {
                            Text("Paste or dictate your raw session notes here, or leave this box empty to use what you typed in the note. The assistant drafts the \(structured ? noteFormat : "BIRP") sections above, asks about anything missing, and pulls out homework. This can take up to a minute.")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                            TextField("Raw notes", text: $messy, axis: .vertical).lineLimit(3...10)
                                .errorField("Raw notes")
                            // Never dimmed for an empty box: generate() says what it needs instead.
                            Button { Task { await generate() } } label: {
                                Label(genBusy ? "Generating… this can take up to a minute" : "Generate structured note", systemImage: "wand.and.stars")
                            }.disabled(genBusy)
                        } label: {
                            Label("Generate from messy notes", systemImage: "wand.and.stars")
                        }
                        .accessibilityHint("Shows a box for raw notes the assistant turns into a structured note.")
                    }
                }

                if !questions.isEmpty {
                    Section {
                        ForEach(questions, id: \.self) { q in
                            Label(q, systemImage: "questionmark.circle").font(.callout)
                        }
                    } header: { Text("Before you save — double-check") }
                }

                if !homework.isEmpty {
                    Section {
                        if activeTracks.isEmpty {
                            // So no Assign switch or due date is offered: nothing would be assigned.
                            Text("Homework needs an active training track; none found, so it won't be saved. Copy anything you need into the note.")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        } else {
                            Picker("Add homework to track", selection: $hwTrackId) {
                                ForEach(activeTracks) { Text($0.title).tag($0.id) }
                            }
                        }
                        ForEach($homework) { $hw in
                            let number = (homework.firstIndex { $0.id == hw.id } ?? 0) + 1
                            let named = hw.title.isEmpty ? "homework \(number), untitled" : "homework \(number): \(hw.title)"
                            // At the accessibility text sizes the switch goes below the title, so the
                            // title field is not squeezed to a few letters beside it.
                            let rowLayout = typeSize.isAccessibilitySize
                                ? AnyLayout(VStackLayout(alignment: .leading, spacing: 4))
                                : AnyLayout(HStackLayout())
                            VStack(alignment: .leading, spacing: 4) {
                                // The title is its own field (inside a switch's label it could not be
                                // edited with VoiceOver), and the switch says what it does.
                                rowLayout {
                                    CaptionedField(label: "Homework \(number) title", text: $hw.title)
                                    if !activeTracks.isEmpty {
                                        Toggle("Assign", isOn: $hw.include)
                                            .fixedSize()
                                            .accessibilityLabel("Assign \(named)")
                                            .accessibilityHint("Adds it to the note and the client portal when you save.")
                                            .accessibilityInputLabels(["Assign", "Assign homework \(number)"])
                                    }
                                }
                                if hw.include || activeTracks.isEmpty {
                                    CaptionedField(label: "Homework \(number) instructions", text: $hw.instructions, lines: 1...4)
                                        .font(.callout)
                                    // A switch and a date picker: a mistyped due date used to be dropped unannounced.
                                    // Numbered, so each item's switch and picker have their own names.
                                    if !activeTracks.isEmpty {
                                        OptionalDayField(title: "Homework \(number) due date", text: $hw.due)
                                            .font(.callout)
                                            .errorField("Homework \(number) due date")
                                    }
                                }
                            }
                        }
                    } header: { Text("Homework → client portal") }
                }
            }
            .navigationTitle(editNote != nil ? "Edit note" : (addendumParent != nil ? "Add addendum" : (sessionId != nil ? "Case note" : "Add note")))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(cancelLabel) { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing subject or text: save() says which field is needed.
                    Button("Save") { Task { await save() } }.disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy,
                                 question: editNote == nil ? "Discard this note?" : "Discard your changes to this note?",
                                 asking: $askingDiscard) { clearDraft(); dismiss() }
            .onChange(of: subject) { _ in saveDraft() }
            .onChange(of: body_) { _ in saveDraft() }
            .onChange(of: sections) { _ in saveDraft() }
            .onChange(of: noteFormat) { _ in saveDraft() }
            .onChange(of: noteType) { _ in saveDraft() }
            .onAppear {
                restoreDraft()
                if let existing, subject.isEmpty, body_.isEmpty, sections.allSatisfy({ $0.isEmpty }) {
                    noteType = existing.noteType
                    noteFormat = existing.noteFormat ?? "free"
                    subject = existing.subject
                    body_ = existing.body
                    attachTrackId = existing.trackId ?? 0
                    if let s = existing.sections, s.count == 4 { sections = s }
                }
                if let addendumTo, existing == nil {
                    noteType = "Addendum"
                    if subject.isEmpty { subject = addendumSubject }
                    if attachTrackId == 0 { attachTrackId = addendumTo.trackId ?? 0 }
                }
                if existing == nil && initialTrackId > 0 {
                    if attachTrackId == 0 { attachTrackId = initialTrackId }
                    // Homework goes only to an active track (one the picker offers).
                    if activeTracks.contains(where: { $0.id == initialTrackId }) { hwTrackId = initialTrackId }
                }
                if hwTrackId == 0 { hwTrackId = activeTracks.first?.id ?? 0 }
                if existing == nil, let initialSubject, subject.isEmpty { subject = String(initialSubject.prefix(255)) }
                if existing == nil, sessionId != nil { landOnFirstField() }
            }
        }
    }

    /// A session's note (4.31.0): once the sheet has settled, VoiceOver is put on the first
    /// thing to write, then told where it is (queued, so the field's own name is not cut off).
    private func landOnFirstField() {
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 700_000_000)
            focusedField = structured ? 100 : 0
            guard let message = arrivalAnnouncement ?? sessionContext, !message.isEmpty else { return }
            try? await Task.sleep(nanoseconds: 300_000_000)
            UIAccessibility.post(notification: .announcement,
                                 argument: NSAttributedString(string: message, attributes: [.accessibilitySpeechQueueAnnouncement: true]))
        }
    }

    private func save() async {
        if subject.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Subject: enter a subject for the note.", in: &error)
            return
        }
        let hasText = structured
            ? sections.contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
            : !body_.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        guard hasText else {
            showFormError(structured ? "\(noteFormat) sections: fill in at least one section." : "Note: write the note before saving.", in: &error)
            return
        }
        // 4.34.0: as the website, free text is not saved while the sections still hold text
        // a free-text note would drop (it used to be dropped and "Note saved" said).
        if sectionsLost(format: noteFormat, sections: sections) {
            showFormError(freeTextSectionsMessage, in: &error, field: "Note")
            return
        }
        busy = true; error = nil
        var payload: [String: Any] = ["note_type": noteType, "note_format": noteFormat, "subject": subject]
        if structured {
            payload["sec1"] = sections[0]; payload["sec2"] = sections[1]
            payload["sec3"] = sections[2]; payload["sec4"] = sections[3]
            // A new note's narrative is saved after the sections (assemble_note), as on the
            // website. An edit leaves body out, so the server keeps the note's own narrative
            // part (body_ holds the whole assembled note there).
            if existing == nil && !body_.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                payload["body"] = body_
            }
        } else {
            payload["body"] = body_
            // Sent whenever they hold text, so the server's own check applies too.
            for (i, text) in sections.enumerated() where !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                payload["sec\(i + 1)"] = text
            }
        }
        payload["track_id"] = attachTrackId
        do {
            if let existing = editNote {
                payload["id"] = existing.id
                // 4.29.0: the version the editor opened (or reloaded); none from an older server.
                if let version = (loadedNote ?? existing).noteVersion, !version.isEmpty { payload["note_version"] = version }
                var refusedConflict = false
                var refusedLocked = false
                do {
                    let _: OKResponse = try await api.call("update_note", body: payload, refusal: { reply in
                        refusedConflict = (reply["conflict"] as? Bool) == true
                        refusedLocked = (reply["locked"] as? Bool) == true
                    })
                } catch where refusedLocked {
                    // 4.34.0: the note locked while it was open. As on the website, what was
                    // typed moves into an addendum to it instead of being lost.
                    becomeAddendum(to: loadedNote ?? existing)
                    showFormError(error.localizedDescription + " Your changes are in the addendum form below.", in: &self.error)
                    busy = false
                    return
                } catch where refusedConflict {
                    noteConflict = true
                    showFormError(error.localizedDescription + " What you typed is still in the form.", in: &self.error)
                    busy = false
                    return
                }
                noteConflict = false
            } else {
                payload["client_id"] = clientId
                payload["idem_key"] = idemKey
                // 4.31.0: the completed session this note is for (kept with an offline save too).
                if let sessionId { payload["session_id"] = sessionId }
                if let parent = addendumParent { payload["addendum_to"] = parent.id }
                let kept = homework.filter { $0.include && !$0.title.isEmpty }
                // The server numbers the items it was sent (ticked and titled); each sent item's
                // row number on this form, so its refusal is tied to the right row.
                let sentRows = sentHomeworkRows(homework)
                // Only to an active track the picker offers: the sheet says homework is not
                // saved otherwise (opened from a completed track, for example).
                if !kept.isEmpty && activeTracks.contains(where: { $0.id == hwTrackId }) {
                    payload["homework_track_id"] = hwTrackId
                    payload["homework"] = kept.map { ["title": $0.title, "instructions": $0.instructions, "due": $0.due] }
                }
                let content = [noteType, noteFormat, subject, body_] + sections
                if firstTryContent == nil { firstTryContent = content }
                var refusedFields: [String: String] = [:]
                do {
                    let result: SaveAck
                    do {
                        result = try await api.call("add_note", body: payload, refusal: { refusedFields = ($0["errors"] as? [String: String]) ?? [:] })
                    } catch APIError.server(let message) where !refusedFields.isEmpty {
                        // Tied to the field it is about (4.34.0): a homework due date, or the note.
                        if let key = refusedFields.keys.sorted().first(where: { $0.hasPrefix("hw_due_") }),
                           let n = Int(key.dropFirst("hw_due_".count)), n >= 1, n <= sentRows.count {
                            let row = sentRows[n - 1]
                            let text = refusedFields[key] ?? message
                            let detail = text.range(of: ": ").map { String(text[$0.upperBound...]) } ?? text
                            showFormError("Homework \(row) due date: " + detail, in: &self.error, field: "Homework \(row) due date")
                        } else if let text = refusedFields["note_content"] {
                            showFormError(text, in: &self.error, field: "Note")
                        } else {
                            showFormError(message, in: &self.error)
                        }
                        busy = false
                        return
                    }
                    if result.deduplicated == true {
                        // An earlier try was saved although its reply was lost.
                        let message = firstTryContent == content
                            ? "This note was already saved, so it was not added twice."
                            : "This note was already saved from an earlier try, so it was not added twice. Changes made after that try are not in it; edit the saved note to add them."
                        clearDraft(); onSaved(message); dismiss(); return
                    }
                    // 4.34.0: the homework added and the session warning, as the website says them.
                    clearDraft(); onSaved(noteSavedMessage(result)); dismiss(); return
                } catch APIError.network {
                    // No signal: keep the note on the phone and sync later. Homework
                    // is included in the payload, so it syncs with the note.
                    try api.enqueue("add_note", body: payload, label: "Note: \(subject)")
                    clearDraft()
                    onSaved("Note saved offline — will sync when connected."); dismiss(); return
                }
            }
            clearDraft()
            onSaved(editNote == nil ? "Note saved." : "Note updated."); dismiss()
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }

    /// Whether any structured section holds text.
    private var sectionsHoldText: Bool { sections.contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty } }

    /// The edit's note locked (4.34.0): the form becomes an addendum to it, keeping the text.
    private func becomeAddendum(to note: Note) {
        lockedParent = note
        noteConflict = false
        typedBeforeReload = nil
        noteType = "Addendum"
        subject = String(addendumSubjectFor(note.subject).prefix(255))
        // A structured edit's body holds the whole saved note (it is not shown or sent): the
        // addendum keeps the sections typed, not a copy of the note it is added to.
        if structured && body_ == note.body { body_ = "" }
        attachTrackId = note.trackId ?? attachTrackId
        saveDraft()
    }

    /// After a conflict (4.29.0): loads the note as saved now into the form, and keeps what was
    /// typed on screen below it, so the changes can be made again on the newer version.
    private func reloadNote() async {
        guard let existing else { return }
        reloadingNote = true
        defer { reloadingNote = false }
        do {
            let detail: ClientDetail = try await api.call("client", query: ["id": String(clientId)])
            guard let now = detail.notes.first(where: { $0.id == existing.id }) else {
                throw APIError.server("The note is not among the client's notes the app loads, so it could not be reloaded here. Close this form and open the note again.")
            }
            let typed = structured
                ? zip(labels + Array(repeating: "", count: 4), sections).filter { !$0.1.isEmpty }.map { "\($0.0.isEmpty ? "Section" : $0.0): \($0.1)" }.joined(separator: "\n\n")
                : body_
            typedBeforeReload = "Subject: \(subject)\n\n" + typed
            noteType = now.noteType
            noteFormat = now.noteFormat ?? "free"
            subject = now.subject
            body_ = now.body
            attachTrackId = now.trackId ?? 0
            sections = now.sections?.count == 4 ? now.sections! : ["", "", "", ""]
            loadedNote = now
            noteConflict = false
            // Said as news, not as an error (4.29.0).
            self.error = nil
            announce("Reloaded. The form now shows the note as saved by the other person; what you typed is shown in What you typed, near the top. Make your changes again, then save.")
        } catch {
            showFormError("Could not reload the note. \(error.localizedDescription) What you typed is still in the form.", in: &self.error)
        }
    }
}

// MARK: - Parity helpers (accessibility profile + AI drafting)

/// The tel: link for a stored phone number. The field is free text: an extension ("x22",
/// "ext. 22", "#22") is dialled after a pause instead of being run onto the number.
/// A marker counts only after some digits and not inside a word, so "Text 555…" or
/// "Fax 555…" is a number, not an empty number with an extension.
/// Only the first phone-shaped run is dialled: a field holding two numbers or a note
/// ("512-555-1234 or 512-555-9876", "after 3pm 512-555-1234") never has every digit in it
/// joined into one, and one that holds no plausible number (7 to 15 digits) is not a link.
/// A Maps link for directions to an address (4.0.0); nil when it cannot form one.
func mapsURL(_ address: String) -> URL? {
    let trimmed = address.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !trimmed.isEmpty, let q = trimmed.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) else { return nil }
    return URL(string: "https://maps.apple.com/?daddr=" + q)
}

func telURL(_ phone: String) -> URL? {
    var main = Substring(phone)
    var ext = ""
    var from = phone.startIndex
    while let marker = phone.range(of: #"(?i)(?<![a-z])(ext(ension)?\.?|x|#)\s*(?=[0-9])"#,
                                   options: .regularExpression, range: from..<phone.endIndex) {
        if phone[..<marker.lowerBound].contains(where: { "0123456789".contains($0) }) {
            main = phone[..<marker.lowerBound]
            ext = phone[marker.upperBound...].filter { "0123456789".contains($0) }
            break
        }
        from = marker.upperBound
    }
    guard let run = main.range(of: #"\+?[0-9][0-9().\- ]{5,}[0-9]"#, options: .regularExpression) else { return nil }
    let number = main[run].filter { "0123456789+".contains($0) }
    guard (7...15).contains(number.filter { $0 != "+" }.count) else { return nil }
    // An extension after a second number belongs to that number, not to this one.
    if main[run.upperBound...].contains(where: { "0123456789".contains($0) }) { ext = "" }
    return URL(string: "tel:" + number + (ext.isEmpty ? "" : "," + ext))
}

/// A time zone in words ("Central Time"), or its identifier when the phone has no name for it.
func timeZoneName(_ identifier: String) -> String {
    TimeZone(identifier: identifier)?.localizedName(for: .generic, locale: .current) ?? identifier
}

func hasAccessNeeds(_ c: ClientInfo) -> Bool {
    (c.a11yScreenReader == true)
        || !(c.a11yFormat ?? "").isEmpty
        || !(c.a11yPrimaryTech ?? "").isEmpty
        || !(c.a11yContact ?? "").isEmpty
        || !(c.a11yNotes ?? "").isEmpty
}

/// A note format with its sections spelled out ("BIRP (Behavior, Intervention, Response, Plan)"),
/// as the website's format menu shows it: the acronym alone means little, and VoiceOver reads
/// "BIRP" as a word.
func noteFormatDescription(_ format: String) -> String {
    guard let labels = noteFormatLabels[format], !labels.isEmpty else { return format == "free" ? "Free text" : format }
    return "\(format) (\(labels.joined(separator: ", ")))"
}

/// Whether text may run past `lines` lines (a rough count: about 40 characters a line, far
/// fewer at the accessibility text sizes), so its row offers to show it in full. Offering it
/// for text that happens to fit does no harm.
func mayTruncate(_ text: String, lines: Int, accessibilitySize: Bool) -> Bool {
    let perLine = accessibilitySize ? 12 : 40
    return text.count > perLine * lines || text.filter { $0 == "\n" }.count >= lines
}

/// The small format badge on a note row, spoken with its expansion.
struct NoteFormatBadge: View {
    let format: String
    var body: some View {
        Text(format).font(.caption2.weight(.semibold))
            .padding(.horizontal, 6).padding(.vertical, 2)
            .background(Color.brand.opacity(0.15)).clipShape(Capsule())
            .accessibilityLabel("Format: \(noteFormatDescription(format))")
    }
}

/// "Show full note" / "Show less" under a note's preview: at large text sizes six lines hold a
/// few words, and a colleague's note has no Edit to read it through. A 44-point target, named
/// with the note's subject so the Buttons rotor and Voice Control tell notes apart.
struct NoteExpandButton: View {
    let subject: String
    let open: Bool
    let toggle: () -> Void
    var body: some View {
        let visible = open ? "Show less" : "Show full note"
        let name = open ? "Show less of note: \(subject)" : "Show full note: \(subject)"
        Button(action: toggle) {
            Text(visible).frame(minHeight: 44).contentShape(Rectangle())
        }
        .font(.callout).buttonStyle(.borderless)
        .accessibilityLabel(name)
        .accessibilityInputLabels([visible, name])
    }
}

struct AIDraftResponse: Decodable {
    let text: String
    /// True when the provider stopped at its output limit, so the draft is missing its end.
    let truncated: Bool?
}

/// The reply to a create that carries an idempotency key: `deduplicated` is true when an
/// earlier try with the same key had already been saved.
struct SaveAck: Decodable {
    let ok: Bool
    let deduplicated: Bool?
    /// add_note (4.34.0 apps read them): the session's warning, and homework items added.
    var warning: String?
    var homeworkCreated: Int?
}

/// "Note saved." with the homework added and the session warning, as the website says it.
func noteSavedMessage(_ result: SaveAck) -> String {
    var message = "Note saved."
    if let n = result.homeworkCreated, n > 0 {
        message += " \(n) homework item\(n == 1 ? "" : "s") added to the client portal."
    }
    if let warning = result.warning?.trimmingCharacters(in: .whitespacesAndNewlines), !warning.isEmpty {
        message += " " + warning
    }
    return message
}

/// The website's refusal when free text is chosen but the sections still hold text (4.34.0).
let freeTextSectionsMessage = "Free text is chosen as the note format, but the structured sections still hold text that a free-text note does not keep. Move anything you need from the sections into the narrative box and clear them, or choose a structured format again."

/// Whether saving would drop section text (free text chosen while a section holds text).
func sectionsLost(format: String, sections: [String]) -> Bool {
    format == "free" && sections.contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
}

/// The form's row number (1-based) of each homework item add_note is sent, in the order sent:
/// only ticked items with a title go, and the server numbers them among those (hw_due_N).
func sentHomeworkRows(_ items: [GenHomework]) -> [Int] {
    items.enumerated().filter { $0.element.include && !$0.element.title.isEmpty }.map { $0.offset + 1 }
}

/// "Addendum: <subject>", never "Addendum: Addendum: …".
func addendumSubjectFor(_ subject: String) -> String {
    subject.hasPrefix("Addendum: ") ? subject : "Addendum: " + subject
}

/// Generates an AI case summary for review, then lets the user save it as a note.
struct AICaseSummarySheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    /// The result to tell the person, said by the presenter once the sheet has gone.
    let onSaved: (String) -> Void
    @State private var draft = ""
    @State private var busy = true
    @State private var saving = false
    @State private var error: String?
    /// The provider stopped before the end of the summary.
    @State private var truncated = false
    /// One key for this summary note, sent on every try, so a retry after a lost reply is
    /// recognised by the server instead of saving the note twice.
    @State private var idemKey = UUID().uuidString
    /// The summary as the AI returned it, to tell whether the person has edited it since.
    @State private var generated = ""
    @State private var askingDiscard = false

    /// Edits to the summary that closing the sheet would throw away.
    private var hasChanges: Bool { !draft.isEmpty && draft != generated }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if busy {
                    HStack { ProgressView(); Text("Drafting summary…").foregroundStyle(Color.secondaryText) }
                } else {
                    Text("Review and edit before saving. The AI never saves on its own.")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                    if truncated {
                        Label(Self.truncatedMessage, systemImage: "exclamationmark.triangle")
                            .font(.callout)
                    }
                    TextField("Case summary", text: $draft, axis: .vertical).lineLimit(8...30)
                }
            }
            .navigationTitle("AI case summary")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(saving)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save as note") { Task { await saveNote() } }
                        .disabled(busy || saving || draft.isEmpty)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: saving,
                                 question: "Discard your edits to this summary?",
                                 asking: $askingDiscard) { dismiss() }
            .task { await generate() }
        }
    }

    private func generate() async {
        busy = true; error = nil
        do {
            let r: AIDraftResponse = try await api.call("ai_draft", body: ["type": "case", "client_id": clientId])
            draft = r.text
            generated = r.text
            truncated = r.truncated == true
            announce(truncated ? "Summary drafted. " + Self.truncatedMessage : "Summary drafted. Review and edit before saving.")
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
    static let truncatedMessage = "This summary is incomplete: the AI stopped before the end. Review it closely and add what is missing, or close and draft it again."

    private func saveNote() async {
        saving = true; error = nil
        let payload: [String: Any] = [
            "client_id": clientId, "note_type": "Case Note", "subject": "Case summary (AI draft, reviewed)",
            "body": draft, "idem_key": idemKey,
        ]
        do {
            let result: SaveAck = try await api.call("add_note", body: payload)
            onSaved(result.deduplicated == true
                    ? "This summary was already saved as a note from an earlier try, so it was not added twice."
                    : "Case summary saved as a note.")
            dismiss()
        } catch APIError.network {
            // No signal (or the reply was lost): keep it on the phone with the same key; the
            // server recognises a summary that did arrive and does not save it twice.
            do {
                try api.enqueue("add_note", body: payload, label: "Note: Case summary")
                onSaved("Case summary saved offline — will sync when connected."); dismiss()
            } catch {
                showFormError(error.localizedDescription, in: &self.error); saving = false
            }
        } catch { showFormError(error.localizedDescription, in: &self.error); saving = false }
    }
}

// MARK: - Status history and guardian consent (client.php's history tab and consent request)

struct StatusHistoryEntry: Decodable, Identifiable {
    let id: Int
    let changedAt: String
    let oldStatus: String?
    let newStatus: String
    let changedBy: String
    let note: String?
    private enum CodingKeys: String, CodingKey { case id, changedAt, oldStatus, newStatus, changedBy, note }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        changedAt = (try? c.decode(String.self, forKey: .changedAt)) ?? ""
        oldStatus = try? c.decodeIfPresent(String.self, forKey: .oldStatus)
        newStatus = (try? c.decode(String.self, forKey: .newStatus)) ?? ""
        changedBy = (try? c.decode(String.self, forKey: .changedBy)) ?? "System"
        note = try? c.decodeIfPresent(String.self, forKey: .note)
    }
}
struct StatusHistoryResponse: Decodable { let history: [StatusHistoryEntry] }

/// The case status history (audit trail): every status change, newest first.
struct ClientHistoryView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    let clientName: String
    @State private var history: [StatusHistoryEntry] = []
    @State private var loaded = false
    @State private var error: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if loaded {
                Section {
                    if history.isEmpty { Text("No status changes recorded.").foregroundStyle(Color.secondaryText) }
                    ForEach(history) { h in
                        let change = "\(h.oldStatus.map { "From \($0) to " } ?? "Set to ")\(h.newStatus)"
                        VStack(alignment: .leading, spacing: 3) {
                            Text(change).font(.body.weight(.semibold))
                            Text("\(formatDateTime(h.changedAt)) · by \(h.changedBy)").font(.caption).foregroundStyle(Color.secondaryText)
                            if let note = h.note { Text(note).font(.callout) }
                        }
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel("\(change). \(formatDateTime(h.changedAt)), by \(h.changedBy)\(h.note.map { ". \($0)" } ?? "")")
                    }
                } header: {
                    Text("Status changes (\(history.count))").font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                }
            } else if error == nil {
                ProgressView("Loading status history…")
            }
        }
        .navigationTitle("Status history")
        .refreshable { await load() }
        .task { await load() }
    }

    private func load() async {
        do {
            error = nil
            let r: StatusHistoryResponse = try await api.call("client_history", query: ["client_id": String(clientId)])
            history = r.history
            loaded = true
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ConsentState: Decodable {
    let state: String
    let signedName: String?
    let signedAt: String?
    let withdrawnAt: String?
    let expires: String?
    let pendingSentAt: String?
    let canRequest: Bool
    let requestLabel: String
    /// The unsigned request waiting now (4.34.0; 0 for none), sent back as consent_seen so a
    /// request a colleague has just sent is not replaced unseen; nil from an older server.
    var pendingId: Int?
}
struct ConsentStatusResponse: Decodable {
    let consent: ConsentState
    let guardianName: String?
    let guardianEmail: String?
}

extension ConsentState {
    /// As the client page words it.
    var summary: String {
        var parts: [String] = []
        switch state {
        case "Withdrawn": parts.append("Withdrawn \(formatDate(withdrawnAt)) (signed by \(signedName ?? "the guardian") on \(formatDate(signedAt)))")
        case "Expired": parts.append("Expired on \(formatDate(expires)) (signed by \(signedName ?? "the guardian") on \(formatDate(signedAt)))")
        case "Signed": parts.append("Signed by \(signedName ?? "the guardian") on \(formatDate(signedAt)), valid until \(formatDate(expires))")
        default: break
        }
        if let pendingSentAt { parts.append("Requested \(formatDate(pendingSentAt)), awaiting signature") }
        return parts.isEmpty ? "No guardian consent on file." : parts.joined(separator: ". ")
    }
}

/// Email the guardian consent form, as the client page does: any earlier unsigned consent link
/// stops working once the new one is sent. The guardian details typed are kept on this phone
/// until it is sent.
struct ConsentRequestSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let clientName: String
    let status: ConsentStatusResponse
    let onSent: (String) -> Void

    @State private var name = ""
    @State private var email = ""
    @State private var busy = false
    @State private var error: String?
    @State private var confirming = false
    @State private var askingDiscard = false
    @State private var started = false
    /// The consent as reloaded after a colleague's request arrived meanwhile (4.34.0).
    @State private var reloaded: ConsentStatusResponse?
    private var shown: ConsentStatusResponse { reloaded ?? status }
    /// A newer request arrived meanwhile and the consent has not been reloaded since.
    @State private var needsReload = false
    @State private var reloading = false

    /// Loads the consent as it is now; true when it worked (Send is allowed again).
    private func reloadConsent() async -> Bool {
        reloading = true
        defer { reloading = false }
        guard let now: ConsentStatusResponse = try? await api.call("request_consent", query: ["client_id": String(clientId)]) else { return false }
        reloaded = now
        needsReload = false
        return true
    }

    private var draftKey: String { "consent-\(clientId)" }
    private var hasChanges: Bool { name != (status.guardianName ?? "") || email != (status.guardianEmail ?? "") }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if needsReload {
                    Button {
                        Task {
                            if await reloadConsent() { error = nil; announce("Reloaded. " + shown.consent.summary) }
                            else { showFormError("The consent could still not be reloaded. Check your connection and try again.", in: &error) }
                        }
                    } label: { Text(reloading ? "Reloading…" : "Reload the consent as it is now") }
                    .disabled(reloading)
                }
                Section {
                    Text(shown.consent.summary)
                    Text("Sending a new request turns off any earlier consent link that has not been signed.").font(.callout)
                }
                Section("Guardian") {
                    // No name content type (4.27.0): AutoFill would offer the staff member's own name.
                    CaptionedField(label: "Guardian name (required)", text: $name, errorName: "Guardian name")
                    CaptionedField(label: "Guardian email (required)", text: $email, keyboard: .emailAddress, errorName: "Guardian email")
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                }
            }
            .navigationTitle(status.consent.requestLabel.contains("Resend") ? "Resend consent request" : "Send consent request")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }.disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Sending…" : "Email consent form") { check() }.disabled(busy || needsReload)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this consent request?", asking: $askingDiscard) {
                DraftStore.clear(key: draftKey, owner: api.userId); dismiss()
            }
            // As the website asks before sending.
            .confirmationDialog("Email the consent form to \(email.trimmingCharacters(in: .whitespaces))? Any earlier unsigned consent link stops working.",
                                isPresented: $confirming, titleVisibility: .visible) {
                Button("Email consent form") { Task { await send() } }
                Button("Cancel", role: .cancel) {}
            }
            .onAppear(perform: start)
            .onChange(of: name) { _ in saveDraft() }
            .onChange(of: email) { _ in saveDraft() }
        }
    }

    private func start() {
        guard !started else { return }
        started = true
        name = status.guardianName ?? ""
        email = status.guardianEmail ?? ""
        if let draft = DraftStore.load(key: draftKey, owner: api.userId) {
            name = draft["name"] ?? name
            email = draft["email"] ?? email
        }
    }

    private func saveDraft() {
        guard started else { return }
        if hasChanges { DraftStore.save(["name": name, "email": email], key: draftKey, owner: api.userId) }
        else { DraftStore.clear(key: draftKey, owner: api.userId) }
    }

    private func check() {
        if name.trimmingCharacters(in: .whitespaces).isEmpty { showFormError("Guardian name: enter the guardian's name.", in: &error); return }
        if !email.contains("@") { showFormError("Guardian email: enter the guardian's email address in the form name@example.com.", in: &error); return }
        confirming = true
    }

    private func send() async {
        busy = true; error = nil
        do {
            var body: [String: Any] = ["client_id": clientId, "guardian_name": name, "guardian_email": email]
            // 4.34.0: the waiting request this form was opened with; the server refuses to
            // replace a newer one (409 consent_changed) instead of turning it off unseen.
            if let seen = shown.consent.pendingId { body["consent_seen"] = seen }
            body["idem_key"] = idem.key(for: body)
            var changed = false
            var fields: [String: String] = [:]
            let r: TaskSaveResult
            do {
                r = try await api.call("request_consent", body: body, refusal: { reply in
                    changed = (reply["consent_changed"] as? Bool) == true
                    fields = (reply["errors"] as? [String: String]) ?? [:]
                })
            } catch APIError.server(let message) where changed || !fields.isEmpty {
                idem.failed(APIError.server(message))
                busy = false
                if changed {
                    // Someone sent a request meanwhile: the form shows it now, and nothing was sent.
                    // Until that reload works, Send stays off (it would replace the request unseen).
                    needsReload = true
                    if await reloadConsent() {
                        showFormError(message, in: &self.error)
                    } else {
                        showFormError(message + " The consent could not be reloaded, so sending stays off. Use Reload below, then check before sending.", in: &self.error)
                    }
                } else {
                    // The server's whole text in the summary; the first field it names is tied.
                    let field: String? = fields["guardian_name"] != nil ? "Guardian name" : (fields["guardian_email"] != nil ? "Guardian email" : nil)
                    showFormError(message, in: &self.error, field: field)
                }
                return
            }
            idem.answered()
            DraftStore.clear(key: draftKey, owner: api.userId)
            onSent(r.message ?? "Consent form emailed.")
            dismiss()
        } catch {
            idem.failed(error)
            busy = false; showFormError(IdemKey.message(for: error, what: "the consent request"), in: &self.error)
        }
    }
}

extension ClientRelease {
    /// One line for the client screen's list and for VoiceOver.
    var summary: String {
        var line = "\(recipientName), \(recipientType): \(status)"
        if status == "Current", let e = expiresOn { line += ", valid until \(formatDate(e))" }
        else if status == "Expired", let e = expiresOn { line += " on \(formatDate(e))" }
        return line + ". Covers \(infoScope)."
    }
}

// MARK: - After closure (4.35.0): the outcome survey and the 30, 60 and 90-day follow-up checks

/// The website's "After closure" card: the outcome surveys with Email the survey and the
/// answers taken by phone, then each follow-up check (recorded, due later, or due now).
struct AfterClosureSection: View {
    @EnvironmentObject var api: API
    let clientId: Int
    let clientName: String
    let state: AfterClosureState
    /// The server's message: shown and said by the record, which then reloads.
    let onDone: (String) -> Void
    @State private var askingSend = false
    @State private var sending = false
    @State private var sendIdem = IdemKey()
    @State private var showingPhone = false
    @State private var recording: ClosureFollowup?
    @State private var sheetResult: String?
    @State private var error: String?

    var body: some View {
        Section("After closure") {
            if let error { ErrorBanner(message: error, announces: false) }
            Text("Outcome survey").font(.headline).accessibilityAddTraits(.isHeader)
            if state.surveys.isEmpty {
                Text("No survey sent yet.").foregroundStyle(Color.secondaryText)
            }
            ForEach(state.surveys) { Text($0.text).font(.callout) }
            Button { askingSend = true } label: {
                Label(sending ? "Emailing the survey…" : (state.byPhone ? "Email the survey (client prefers phone or post)" : "Email the survey"),
                      systemImage: "envelope")
            }
            .disabled(sending)
            .confirmationDialog(Self.surveyQuestion(client: clientName, byPhone: state.byPhone), isPresented: $askingSend, titleVisibility: .visible) {
                Button("Email the survey") { Task { await sendSurvey() } }
                Button("Cancel", role: .cancel) {}
            }
            Button { showingPhone = true } label: {
                Label("Enter survey answers taken by phone", systemImage: "phone")
            }
            Text("Follow-up checks").font(.headline).accessibilityAddTraits(.isHeader)
            if state.followups.isEmpty {
                Text("None scheduled. Checks are scheduled at 30, 60 and 90 days when the closure is recorded.")
                    .foregroundStyle(Color.secondaryText)
            }
            ForEach(state.followups) { fu in
                Text(fu.text).font(.callout)
                if fu.status == "Pending" && fu.dueNow {
                    Button { recording = fu } label: {
                        Label("Record the \(fu.label)", systemImage: "checkmark.circle")
                    }
                }
            }
        }
        .sheet(isPresented: $showingPhone, onDismiss: sayResult) {
            PhoneSurveySheet(clientId: clientId, clientName: clientName) { message in sheetResult = message }
        }
        .sheet(item: $recording, onDismiss: sayResult) { fu in
            FollowupCheckSheet(clientId: clientId, clientName: clientName, followup: fu) { message in sheetResult = message }
        }
    }

    /// The question before the survey is emailed; it says so when the client prefers phone or post.
    static func surveyQuestion(client: String, byPhone: Bool) -> String {
        "Email the outcome survey to \(client)?"
            + (byPhone ? " They prefer phone or post, so it may not be emailed; you can enter their answers by phone instead." : "")
    }

    private func sayResult() {
        guard let message = sheetResult else { return }
        sheetResult = nil
        onDone(message)
        announceAfterDismiss(message)
    }

    private func sendSurvey() async {
        sending = true
        defer { sending = false }
        error = nil
        var body: [String: Any] = ["client_id": clientId]
        body["idem_key"] = sendIdem.key(for: body)
        do {
            let r: TaskSaveResult = try await api.call("send_survey", body: body)
            sendIdem.answered()
            let message = r.message ?? "Outcome survey emailed."
            onDone(message)
            announce(message)
        } catch {
            sendIdem.failed(error)
            // Already emailed, or not emailed (no confirmed address, prefers phone or post): the
            // server's sentence says what to do, shown here and said once.
            let message = IdemKey.message(for: error, what: "the survey email")
            self.error = message
            announce(message)
        }
    }
}

/// Survey answers taken by phone (phone_survey, 4.35.0), as the website's form.
struct PhoneSurveySheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let clientName: String
    let onSaved: (String) -> Void
    @State private var satisfaction = ""
    @State private var goalsMet = ""
    @State private var comments = ""
    @State private var idem = IdemKey()
    @State private var busy = false
    @State private var error: String?

    /// A rating from 1 to 10, or nil.
    static func rating(_ raw: String) -> Int? {
        let t = raw.trimmingCharacters(in: .whitespaces)
        guard t.count <= 2, t.allSatisfy(\.isASCII), let n = Int(t), (1...10).contains(n) else { return nil }
        return n
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section("Outcome survey for \(clientName)") {
                    CaptionedField(label: "Satisfaction, 1 to 10 (required)", text: $satisfaction, keyboard: .numberPad, errorName: "Satisfaction")
                    CaptionedField(label: "Goals met, 1 to 10 (required)", text: $goalsMet, keyboard: .numberPad, errorName: "Goals met")
                    CaptionedField(label: "Comments (optional, up to 2,000 characters)", text: $comments, lines: 3...8, errorName: "Comments")
                }
            }
            .navigationTitle("Survey answers by phone")
            .navigationBarTitleDisplayMode(.inline)
            .interactiveDismissDisabled(busy || !satisfaction.isEmpty || !goalsMet.isEmpty || !comments.isEmpty)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Saving…" : "Save survey answers") { Task { await save() } }.disabled(busy)
                }
            }
        }
    }

    private func save() async {
        guard let sat = Self.rating(satisfaction) else {
            showFormError("Satisfaction: enter a rating from 1 to 10.", in: &error); return
        }
        guard let goals = Self.rating(goalsMet) else {
            showFormError("Goals met: enter a rating from 1 to 10.", in: &error); return
        }
        // The website's limit (its comments box holds 2000 characters).
        if comments.unicodeScalars.count > 2000 {
            showFormError("Comments: keep the comments to 2,000 characters or fewer.", in: &error); return
        }
        var body: [String: Any] = ["client_id": clientId, "satisfaction": sat, "goals_met": goals, "comments": comments]
        body["idem_key"] = idem.key(for: body)
        busy = true; error = nil
        defer { busy = false }
        var refusedFields: [String: String] = [:]
        do {
            let r: TaskSaveResult = try await api.call("phone_survey", body: body, refusal: { reply in
                refusedFields = (reply["errors"] as? [String: String]) ?? [:]
            })
            idem.answered()
            onSaved(r.message ?? "Survey answers recorded.")
            dismiss()
        } catch {
            idem.failed(error)
            if let text = refusedFields["satisfaction"] { showFormError(text, in: &self.error, field: "Satisfaction") }
            else if let text = refusedFields["goals_met"] { showFormError(text, in: &self.error, field: "Goals met") }
            else { showFormError(IdemKey.message(for: error, what: "the survey answers"), in: &self.error) }
        }
    }
}

/// A 30, 60 or 90-day follow-up check (closure_followup, 4.35.0), as the website's form:
/// four questions, each Not asked, Yes or No, and notes.
struct FollowupCheckSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let clientName: String
    let followup: ClosureFollowup
    let onSaved: (String) -> Void
    @State private var employed = ""
    @State private var usingTech = ""
    @State private var independent = ""
    @State private var needsService = ""
    @State private var notes = ""
    @State private var confirming = false
    @State private var idem = IdemKey()
    @State private var busy = false
    @State private var error: String?

    /// The question asked before saving, naming the check and the client.
    static func followupConfirm(_ label: String, client: String, needsService: Bool) -> String {
        "Save the \(label) for \(client)? It cannot be recorded again." + (needsService ? " A re-referral task will be added." : "")
    }

    private var hasChanges: Bool {
        ![employed, usingTech, independent, needsService].allSatisfy(\.isEmpty) || !notes.isEmpty
    }

    /// The server's rule and sentence: the notes trimmed, counted in characters (code points).
    static func notesProblem(_ notes: String) -> String? {
        notes.trimmingCharacters(in: .whitespacesAndNewlines).unicodeScalars.count > 1000
            ? "Notes are too long (1,000 characters maximum)." : nil
    }

    @ViewBuilder private func question(_ label: String, _ value: Binding<String>) -> some View {
        // The website's order: Yes, No, Not asked (the default).
        Picker(label, selection: value) {
            Text("Yes").tag("yes")
            Text("No").tag("no")
            Text("Not asked").tag("")
        }
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section("\(followup.label) for \(clientName), due \(formatDate(followup.dueOn))") {
                    question("Still employed?", $employed)
                    question("Still using the technology?", $usingTech)
                    question("Living or working independently?", $independent)
                    question("Needs more service?", $needsService)
                    CaptionedField(label: "Notes (optional, up to 1000 characters)", text: $notes, lines: 2...6, errorName: "Notes")
                }
                Button(busy ? "Saving…" : "Save the \(followup.label)") {
                    if let problem = Self.notesProblem(notes) {
                        showFormError(problem, in: &error, field: "Notes"); return
                    }
                    confirming = true
                }
                .disabled(busy)
            }
            .navigationTitle("Follow-up check")
            .navigationBarTitleDisplayMode(.inline)
            .interactiveDismissDisabled(busy || hasChanges)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
            }
            .confirmationDialog(Self.followupConfirm(followup.label, client: clientName, needsService: needsService == "yes"),
                                isPresented: $confirming, titleVisibility: .visible) {
                Button("Save the \(followup.label)") { Task { await save() } }
                Button("Not yet", role: .cancel) {}
            }
        }
    }

    private func save() async {
        var body: [String: Any] = [
            "client_id": clientId, "followup_id": followup.id,
            "still_employed": employed, "still_using_tech": usingTech,
            "independent": independent, "needs_service": needsService,
            "notes": notes,
        ]
        body["idem_key"] = idem.key(for: body)
        busy = true; error = nil
        defer { busy = false }
        var refusedFields: [String: String] = [:]
        do {
            let r: TaskSaveResult = try await api.call("closure_followup", body: body, refusal: { reply in
                refusedFields = (reply["errors"] as? [String: String]) ?? [:]
            })
            idem.answered()
            onSaved(r.message ?? (needsService == "yes" ? "Follow-up recorded. Because the client needs more service, a re-referral task was added." : "Follow-up recorded."))
            dismiss()
        } catch {
            idem.failed(error)
            if let text = refusedFields["notes"] { showFormError(text, in: &self.error, field: "Notes") }
            else { showFormError(IdemKey.message(for: error, what: "the follow-up check"), in: &self.error) }
        }
    }
}
