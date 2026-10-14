import SwiftUI
import SafariServices

struct DashboardView: View {
    @EnvironmentObject var api: API
    @State private var data: DashboardData?
    @State private var error: String?
    @State private var showingVoice = false
    @State private var confirmingSignOut = false
    /// Offline entries is on screen: its link stays while it is, or emptying the queue (Retry
    /// now, Discard, or a background sync) would remove the link and pop the screen mid-status.
    @State private var offlineEntriesOpen = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    if let error { ErrorBanner(message: error) }
                    if let data {
                        // As on the website, a figure the account has no permission for is left
                        // out (the server sends 0 for it, which would read as a fact).
                        LazyVGrid(columns: [GridItem(.flexible()), GridItem(.flexible())], spacing: 12) {
                            if api.can("clients") {
                                StatTile(number: "\(data.activeClients)", label: "Active training")
                                StatTile(number: "\(data.pendingClients)", label: "Pending assessment")
                            }
                            if api.can("referrals") {
                                StatTile(number: "\(data.newReferrals)", label: "New referrals")
                            }
                            if api.can("billing") {
                                // As on the website: the amount also counts unbilled mileage and
                                // expenses, and covers only the caseload of a caseload-scoped account.
                                StatTile(number: String(format: "%.2f", data.unbilledHours),
                                         label: String(format: "Unbilled hours %@ ($%.2f with mileage and expenses)",
                                                       api.scoped ? "on your caseload" : "across all tracks", data.unbilledAmount))
                            }
                        }

                        if api.can("clients") {
                            Text("Expired or expiring within 30 days")
                                .font(.headline)
                                .padding(.top, 8)
                                .accessibilityAddTraits(.isHeader)
                            if data.expiring.isEmpty {
                                Text("No authorizations have expired or expire in the next 30 days.")
                                    .foregroundStyle(Color.secondaryText)
                            } else {
                                ForEach(data.expiring) { track in
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(track.client).font(.body.weight(.semibold))
                                        // The words say which it is: the lapsed ones are not shown by colour.
                                        Text("\(track.title) — \(track.isExpired == true ? "expired" : "expires") \(formatDate(track.expirationDate))")
                                            .font(.callout)
                                            .foregroundStyle(Color.secondaryText)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .padding(12)
                                    .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
                                    .accessibilityElement(children: .combine)
                                }
                                // The list holds the 10 soonest; say so when there are more.
                                if let total = data.expiringTotal, total > data.expiring.count {
                                    Text("Showing the \(data.expiring.count) soonest of \(total). Compliance lists them all.")
                                        .font(.callout).foregroundStyle(Color.secondaryText)
                                } else if data.expiringTotal == nil && data.expiring.count >= 10 {
                                    Text("Showing the \(data.expiring.count) soonest. Compliance lists them all.")
                                        .font(.callout).foregroundStyle(Color.secondaryText)
                                }
                            }
                        }
                        if !data.recentReferrals.isEmpty {
                            Text("Recent referrals").font(.headline).padding(.top, 8)
                                .accessibilityAddTraits(.isHeader)
                            ForEach(data.recentReferrals) { referral in
                                VStack(alignment: .leading, spacing: 2) {
                                    Text("\(referral.clientName) — \(referral.reason)").font(.callout.weight(.medium))
                                    Text("\(referral.status) · \(formatDateTime(referral.createdAt))")
                                        .font(.caption).foregroundStyle(Color.secondaryText)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(10)
                                .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
                                .accessibilityElement(children: .combine)
                            }
                        }
                        if !data.recentNotes.isEmpty {
                            Text("Latest notes").font(.headline).padding(.top, 8)
                                .accessibilityAddTraits(.isHeader)
                            ForEach(data.recentNotes) { note in
                                let row = VStack(alignment: .leading, spacing: 2) {
                                    Text("\(note.clientName): \(note.subject)").font(.callout.weight(.medium))
                                    Text("\(note.noteType) · \(formatDateTime(note.createdAt))")
                                        .font(.caption).foregroundStyle(Color.secondaryText)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(10)
                                .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
                                // Opening a client record needs the clients permission.
                                if api.can("clients") {
                                    NavigationLink(value: note.clientId) { row }
                                        .buttonStyle(.plain)
                                } else {
                                    row
                                }
                            }
                        }
                    } else if error == nil {
                        ProgressView().frame(maxWidth: .infinity).padding(40)
                    }

                    Text("More tools").font(.headline).padding(.top, 12)
                        .accessibilityAddTraits(.isHeader)
                    // Entries kept on this phone (no signal, or refused by the server): see, retry,
                    // copy or discard them.
                    if api.pendingSync > 0 || offlineEntriesOpen {
                        MoreToolLink(title: api.pendingSync > 0 ? "Offline entries (\(api.pendingSync))" : "Offline entries (none waiting)",
                                     subtitle: "Kept on this phone until they upload",
                                     systemImage: "icloud.and.arrow.up") {
                            OfflineEntriesView()
                                .onAppear { offlineEntriesOpen = true }
                                .onDisappear { offlineEntriesOpen = false }
                        }
                    }
                    MoreToolLink(title: "Action center",
                                 subtitle: api.can("clients") && api.aiHas("briefing") ? "Priorities and AI case briefings" : "Priorities",
                                 systemImage: "checklist") { WorkflowActionsView() }
                    // AT services offers only modules the user holds a permission for, so the link
                    // is shown only when at least one of them is available.
                    if api.can("clients") || api.can("equipment") || api.can("schedule") || api.can("admin") || api.can("billing") {
                        MoreToolLink(title: "AT services", subtitle: "Assistive technology services", systemImage: "accessibility") { ATServicesView() }
                    }
                    // Compliance and the voice assistant read client records (the server's
                    // 'clients' permission), like the other client tools below.
                    if api.can("clients") { MoreToolLink(title: "Compliance", systemImage: "checklist") { ComplianceView() } }
                    if api.can("clients") { MoreToolLink(title: "Device follow-ups", systemImage: "arrow.triangle.2.circlepath") { FollowupsView() } }
                    if api.can("clients") { MoreToolLink(title: "Messages", systemImage: "bubble.left.and.bubble.right") { MessagesView() } }
                    if api.can("clients") { MoreToolLink(title: "Incidents & safety", systemImage: "exclamationmark.triangle") { IncidentsView() } }
                    if api.can("analytics") { MoreToolLink(title: "Staff utilization", systemImage: "chart.bar.xaxis") { UtilizationView() } }
                    if api.can("procurement") { MoreToolLink(title: "Device procurement", systemImage: "shippingbox.and.arrow.backward") { ProcurementView() } }
                    if api.can("programs") { MoreToolLink(title: "Pre-ETS programs", subtitle: "Pre-employment transition services", systemImage: "person.3.sequence") { ProgramsView() } }
                    if api.can("clients") { MoreToolLink(title: "Worksite accommodations", systemImage: "building.2") { AccommodationsView() } }
                    if api.can("schedule") { MoreToolLink(title: "Interpreter / transport bookings", systemImage: "hands.sparkles") { BookingsView() } }
                    if api.can("billing") { MoreToolLink(title: "Grants & contracts", systemImage: "doc.text.magnifyingglass") { ContractsView() } }
                    MoreToolLink(title: "Knowledge base", systemImage: "book") { KnowledgeView() }
                    MoreToolLink(title: "Help and glossary", subtitle: "What the abbreviations and terms mean", systemImage: "questionmark.circle") { GlossaryView() }
                    if api.aiEnabled { MoreToolLink(title: "AI tools", systemImage: "sparkles") { AIToolsView() } }
                    if api.aiHas("voice") && api.can("clients") {
                        Button { showingVoice = true } label: {
                            HStack {
                                Label("Voice assistant", systemImage: "mic.fill")
                                Spacer()
                                Image(systemName: "chevron.right").font(.caption).foregroundStyle(.tertiary)
                                    .accessibilityHidden(true)
                            }
                            .padding(12).frame(maxWidth: .infinity, alignment: .leading)
                            .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
                        }.buttonStyle(.plain)
                    }
                }
                .padding()
            }
            .navigationTitle(api.userName.isEmpty ? "Dashboard" : "Hi, \(api.userName.components(separatedBy: " ").first ?? "")")
            .navigationDestination(for: Int.self) { id in ClientDetailView(clientId: id) }
            .toolbar {
                if api.aiHas("voice") && api.can("clients") {
                    Button { showingVoice = true } label: { Label("Voice assistant", systemImage: "mic.fill") }
                }
                if api.can("clients") {
                    NavigationLink { SearchView() } label: {
                        Label("Search", systemImage: "magnifyingglass")
                    }
                }
                Button("Sign out") { confirmingSignOut = true }
            }
            // One stray activation used to sign out on the spot.
            .confirmationDialog(api.pendingSync > 0
                                    ? "Sign out? \(api.pendingSync) offline entr\(api.pendingSync == 1 ? "y is" : "ies are") still waiting to sync and will stay on this phone."
                                    : "Sign out of Case Management System?",
                                isPresented: $confirmingSignOut, titleVisibility: .visible) {
                Button("Sign out", role: .destructive) { api.signOut() }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Unsaved note and hours drafts on this phone are discarded.")
            }
            .sheet(isPresented: $showingVoice) { VoiceView() }
            .refreshable { await load() }
            .task { await load() }
        }
    }

    private func load() async {
        do {
            error = nil
            data = try await api.call("dashboard")
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct MoreToolLink<Destination: View>: View {
    let title: String
    /// A short line under the title, such as what an abbreviation stands for.
    var subtitle: String? = nil
    let systemImage: String
    @ViewBuilder let destination: () -> Destination
    var body: some View {
        NavigationLink { destination() } label: {
            HStack {
                Label {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(title)
                        if let subtitle {
                            Text(subtitle).font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                } icon: {
                    Image(systemName: systemImage)
                }
                Spacer()
                // Decorative: the row is already a link that opens a screen.
                Image(systemName: "chevron.right").font(.caption).foregroundStyle(.tertiary)
                    .accessibilityHidden(true)
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
        }
        .buttonStyle(.plain)
    }
}

struct StatTile: View {
    let number: String
    let label: String
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(number)
                .font(.title.bold().monospacedDigit())
                .foregroundStyle(.primary)
            Text(label)
                .font(.caption)
                .foregroundStyle(Color.secondaryText)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(14)
        .background(.quaternary, in: RoundedRectangle(cornerRadius: 12))
        .accessibilityElement(children: .combine)
    }
}

/// Entries kept on this phone because there was no signal, or because the server refused them:
/// the signed-in account's own only, each with what it says and what the server said, to retry,
/// copy (to enter it on the website) or discard.
struct OfflineEntriesView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    /// Presented as a sheet (from the sync notice), so it needs its own Done button.
    var inSheet = false
    @State private var entries: [API.PendingCall] = []
    @State private var error: String?
    @State private var busy = false
    @State private var status: String?
    @State private var discarding: API.PendingCall?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            Section {
                Button { Task { await retry() } } label: {
                    Label(busy ? "Retrying…" : "Retry now", systemImage: "arrow.clockwise")
                        .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy || entries.isEmpty)
                .accessibilityLabel("Retry now")
                .accessibilityValue(busy ? "Uploading" : "")
                .accessibilityHint("Tries again to upload every entry listed here.")
                if let status {
                    Text(status).foregroundStyle(Color.secondaryText)
                }
            } footer: {
                Text("Entries stay on this phone until the server accepts them. Only your own entries are listed.")
                    .foregroundStyle(Color.secondaryText)
            }
            if entries.isEmpty && error == nil {
                Text("No offline entries are waiting on this phone.")
            }
            ForEach(entries) { entry in
                Section {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(entry.kind + (entry.savedAt.map { ", kept on this phone " + $0.formatted(date: .abbreviated, time: .shortened) } ?? ""))
                            .foregroundStyle(Color.secondaryText)
                        if let why = entry.lastError {
                            Label("Not accepted: \(why)", systemImage: "exclamationmark.triangle.fill")
                                .foregroundStyle(Color.alertRed)
                        } else {
                            Text("Waiting to upload")
                        }
                    }
                    .accessibilityElement(children: .combine)
                    // Not selectable: the system Copy would put the client record on the clipboard
                    // with no expiry and offer it to other devices. Copy text below is the way.
                    Text(entry.readableText)
                        .fixedSize(horizontal: false, vertical: true)
                    Button { copy(entry) } label: {
                        Label("Copy text", systemImage: "doc.on.doc")
                            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    }
                    .accessibilityLabel("Copy text of \(entry.label)")
                    Button(role: .destructive) { discarding = entry } label: {
                        Label("Discard", systemImage: "trash")
                            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    }
                    .accessibilityLabel("Discard \(entry.label)")
                } header: {
                    Text(entry.label)
                        .font(.headline)
                        .foregroundStyle(.primary)
                        .textCase(nil)
                }
            }
        }
        .navigationTitle("Offline entries")
        .toolbar {
            if inSheet {
                ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } }
            }
        }
        .confirmationDialog("Discard this offline entry?",
                            isPresented: Binding(get: { discarding != nil }, set: { if !$0 { discarding = nil } }),
                            titleVisibility: .visible, presenting: discarding) { entry in
            Button("Discard “\(entry.label)”", role: .destructive) { discard(entry) }
            Button("Keep it", role: .cancel) {}
        } message: { entry in
            Text("“\(entry.label)” has not reached the server. Discarding deletes it from this phone and it cannot be recovered. Copy the text first if you still need it.")
        }
        .task { load() }
        .onChange(of: api.pendingSync) { _ in refreshAfterSync() }
        .onChange(of: api.syncError) { _ in refreshAfterSync() }
    }

    /// The queue changed outside Retry now (an upload started by returning to the app): the list
    /// is reloaded, and a status said earlier is brought up to date instead of left stale.
    private func refreshAfterSync() {
        load()
        if status != nil && !busy { status = outcome() }
    }

    /// What the list holds after an upload.
    private func outcome() -> String {
        let refused = entries.filter { $0.lastError != nil }.count
        if entries.isEmpty {
            return "All offline entries uploaded."
        } else if refused > 0 {
            return "\(entries.count) still on this phone; the server did not accept \(refused). The reason is under each one."
        } else {
            return "\(entries.count) still on this phone; they could not upload yet. Check your connection and try again."
        }
    }

    private func load() {
        do {
            entries = try api.ownQueuedEntries()
            if error != nil { error = nil }
        } catch {
            entries = []
            showFormError("Could not read the offline entries on this phone. They have not been discarded.", in: &self.error)
        }
    }

    private func retry() async {
        busy = true
        status = nil
        // Waits for an upload that is already running (returning to the app starts one), so
        // this reports how that went.
        await api.flushQueue()
        load()
        busy = false
        let message = outcome()
        status = message
        announce(message)
    }

    /// The copy stays on this phone (not Handoff) and is cleared from the clipboard after
    /// 10 minutes, or at sign-out: it is a client record.
    private func copy(_ entry: API.PendingCall) {
        api.copyProtected(entry.readableText)
        announce("Copied “\(entry.label)”. The copy is cleared from the clipboard in 10 minutes.")
    }

    private func discard(_ entry: API.PendingCall) {
        // An earlier upload status no longer applies (an emptied list was discarded, not uploaded).
        status = nil
        do {
            try api.discardQueued(entry.id)
            load()
            announce("Discarded “\(entry.label)”.")
        } catch {
            showFormError("Could not discard “\(entry.label)”. It is still saved on this phone.", in: &self.error)
        }
    }
}

/// The website's help glossary, in the app: what the abbreviations and terms used on these
/// screens mean.
struct GlossaryView: View {
    private static let terms: [(term: String, meaning: String)] = [
        ("AT — assistive technology", "Tools or software that help a person perform an activity."),
        ("Authorization", "Approval for a service, often with an allowed number of hours or a spending limit."),
        ("BIRP note", "A note format in four parts: Behavior, Intervention, Response, Plan."),
        ("Caseload", "The clients assigned to a staff member."),
        ("Consent", "A person's agreement to the activity described in a form."),
        ("Deliverable", "A specific item or result required by a contract."),
        ("IPE — individualized plan for employment", "A plan describing an employment goal and the services intended to help reach it."),
        ("NPS — Net Promoter Score", "A survey measure based on how likely people say they are to recommend a service."),
        ("OTP — one-time password", "A temporary sign-in code. You can paste it into the code field."),
        ("PAIP note", "A note format in four parts: Purpose, Assessment, Intervention, Plan."),
        ("Passkey", "A sign-in method that uses your device to approve access instead of typing an account password."),
        ("Pre-ETS — pre-employment transition services", "Services that help students with disabilities prepare for work and further education."),
        ("ROI — release of information", "In consent records, permission to share the information described in the release."),
        ("RSA — Rehabilitation Services Administration", "The agency name used in the app's service categories and reporting."),
        ("SMART goal", "A goal that is specific, measurable, achievable, relevant, and time-bound."),
        ("SOAP note", "A note format in four parts: Subjective, Objective, Assessment, Plan."),
        ("Telepractice", "A session provided remotely, such as through a video call."),
        ("Unbilled hours", "Hours logged on a track that are not on an invoice yet. The Dashboard's dollar amount beside them also counts mileage and expenses not invoiced yet."),
        ("VR — vocational rehabilitation", "Services intended to help people with disabilities prepare for, find, or keep work."),
        ("VRC — vocational rehabilitation counselor", "A counselor who helps plan and coordinate vocational rehabilitation services."),
    ]

    var body: some View {
        List {
            Section {
                ForEach(Self.terms, id: \.term) { item in
                    VStack(alignment: .leading, spacing: 4) {
                        Text(item.term).font(.body.weight(.semibold))
                            .accessibilityAddTraits(.isHeader)
                        Text(item.meaning).foregroundStyle(Color.secondaryText)
                    }
                    .padding(.vertical, 2)
                }
            } header: {
                Text("Common terms").font(.headline).foregroundStyle(.primary).textCase(nil)
            }
            Section {
                Link(destination: URL(string: "help.php", relativeTo: API.base)!.absoluteURL) {
                    Label("Help and glossary on the website", systemImage: "safari")
                        .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .accessibilityHint("Opens the website in Safari: filling in forms, reading and navigation, and keeping your work.")
            }
        }
        .navigationTitle("Help and glossary")
    }
}

// Workflow navigation shares the same permission-filtered API as the website.
private struct WorkflowActionResponse: Decodable { let items: [WorkflowAction] }
private struct WorkflowAction: Decodable { let kind: String; let title: String; let due: String?; let url: String }
private func workflowURL(_ path: String) -> URL? {
    guard let url = URL(string: path, relativeTo: API.base)?.absoluteURL,
          url.scheme == "https", url.host == API.base.host else { return nil }
    return url
}

/// A website page opened from a record link. It is shown inside the app, not in Safari: it stays
/// under the app lock and the app-switcher cover, closes with sign-out, is not kept in Safari's
/// history, and its website data is cleared whenever the account changes (RootView).
private struct WebPage: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}

private struct InAppWebView: UIViewControllerRepresentable {
    let url: URL
    let onDone: @MainActor () -> Void

    func makeUIViewController(context: Context) -> SFSafariViewController {
        let page = SFSafariViewController(url: url)
        page.delegate = context.coordinator
        return page
    }

    func updateUIViewController(_ page: SFSafariViewController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(onDone: onDone) }

    final class Coordinator: NSObject, SFSafariViewControllerDelegate {
        let onDone: @MainActor () -> Void
        init(onDone: @escaping @MainActor () -> Void) { self.onDone = onDone }
        /// Its Done button: the sheet that holds it closes too.
        func safariViewControllerDidFinish(_ controller: SFSafariViewController) {
            MainActor.assumeIsolated { onDone() }
        }
    }
}

/// A record link: opens the page in InAppWebView and is announced as a link.
private struct WebPageLink<Content: View>: View {
    let url: URL
    @Binding var page: WebPage?
    @ViewBuilder let content: () -> Content
    var body: some View {
        Button { page = WebPage(url: url) } label: { content() }
            .accessibilityRemoveTraits(.isButton)
            .accessibilityAddTraits(.isLink)
    }
}

/// As on the website: a date before today (the server's day) is past due.
private func isOverdue(_ due: String) -> Bool {
    String(due.prefix(10)) < APIDate.dayCentral(Date())
}

struct WorkflowActionsView: View {
    @EnvironmentObject var api: API
    @State private var items: [WorkflowAction] = []
    @State private var loaded = false
    @State private var error: String?
    @State private var webPage: WebPage?
    var body: some View {
        List {
            if let error { ErrorBanner(message: error) }
            // Only where the briefing AI feature is on: otherwise every step would end in "turned off".
            if api.can("clients") && api.aiHas("briefing") { NavigationLink("AI case briefing") { WorkflowBriefingView() } }
            if let url = workflowURL("workflows.php") {
                WebPageLink(url: url, page: $webPage) { Text("All workflow tools on the website") }
            }
            Text("Record links open the website inside the app, where you may need to sign in.").font(.caption).foregroundStyle(Color.secondaryText)
            if !loaded && error == nil { ProgressView("Loading action items…") }
            if loaded && items.isEmpty { Text("No action items found.") }
            ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                if let url = workflowURL(item.url) {
                    WebPageLink(url: url, page: $webPage) {
                        VStack(alignment: .leading) {
                            Text(item.title)
                            // Past due is said in words, as on the website.
                            Text(item.kind + (item.due.map { " · " + formatDate($0) + (isOverdue($0) ? " (overdue)" : "") } ?? ""))
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                }
            }
        }
        .navigationTitle("Action center")
        .task { await load() }
        .refreshable { await load() }
        .sheet(item: $webPage) { page in
            InAppWebView(url: page.url) { webPage = nil }.ignoresSafeArea()
        }
    }
    private func load() async {
        do { error = nil; let r: WorkflowActionResponse = try await api.call("workflow_actions"); items = r.items; loaded = true }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}
private struct BriefSource: Decodable { let label: String; let url: String }
private struct BriefItem: Decodable { let text: String; let type: String; let sources: [String: BriefSource] }
private struct BriefResponse: Decodable { let items: [BriefItem] }
struct WorkflowBriefingView: View {
    @EnvironmentObject var api: API
    @State private var selected: Int?
    @State private var adapt = false
    @State private var busy = false
    @State private var error: String?
    @State private var items: [BriefItem] = []
    @State private var webPage: WebPage?
    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            // A searchable list of every client the account may choose (closed ones too, as on
            // the website), not a menu of the first 200 names.
            NewRecordClientPicker(title: "Client", noneLabel: "Choose a client", includeClosed: true, selection: $selected)
                .disabled(busy)
            Toggle("Suggest homework adaptations", isOn: $adapt).disabled(busy)
            Button(busy ? "Drafting…" : "Generate briefing") { Task { await generate() } }
                .disabled(selected == nil || busy)
            Text("AI output is a draft. Verify each claim using the linked records. Nothing is saved or assigned by generating a briefing.")
                .font(.callout)
            ForEach(Array(items.enumerated()), id: \.offset) { index, item in
                let kind = item.type == "fact" ? "Record summary" : "Proposed next step"
                Section(kind) {
                    // Not selectable: the system Copy would keep these client facts on the
                    // clipboard with no expiry and offer them to other devices.
                    Text(item.text)
                    Button { copy(item.text, what: "\(kind.lowercased()) \(index + 1)") } label: {
                        Label("Copy text", systemImage: "doc.on.doc")
                    }
                    .accessibilityLabel("Copy text of \(kind.lowercased()) \(index + 1)")
                    ForEach(item.sources.keys.sorted(), id: \.self) { key in
                        if let source = item.sources[key], let url = workflowURL(source.url) {
                            WebPageLink(url: url, page: $webPage) { Text(source.label) }
                        }
                    }
                }
            }
        }
        .navigationTitle("AI case briefing")
        .onChange(of: selected) { _ in items = [] }
        .sheet(item: $webPage) { page in
            InAppWebView(url: page.url) { webPage = nil }.ignoresSafeArea()
        }
    }
    private func generate() async {
        guard !busy, let selected else { return }
        busy = true; error = nil; items = []
        defer { busy = false }
        do {
            let r: BriefResponse = try await api.call("workflow_briefing", body: ["client_id": selected, "adapt": adapt])
            items = r.items; announce("Briefing ready. Verify its claims against the linked records.")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    /// The copy stays on this phone (not Handoff) and is cleared from the clipboard after
    /// 10 minutes, or at sign-out: it is about a client.
    private func copy(_ text: String, what: String) {
        api.copyProtected(text)
        announce("Copied the \(what). The copy is cleared from the clipboard in 10 minutes.")
    }
}
