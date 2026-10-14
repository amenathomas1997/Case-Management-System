import SwiftUI
import QuickLook

// MARK: - Documents

struct ClientDocument: Decodable, Identifiable {
    let id: Int
    let category: String
    let title: String
    let origName: String
    let createdAt: String
}

struct DocumentsResponse: Decodable { let documents: [ClientDocument] }

/// The Documents section's download and preview. A List calls onDisappear on a section's
/// rows as soon as they scroll out of view, so the download is not tied to the rows: it runs
/// on while the person scrolls, and is cancelled only when this object is released with the
/// client screen (the running request holds no reference to it).
@MainActor
final class DocumentDownloader: ObservableObject {
    @Published private(set) var downloading = false
    @Published var previewURL: URL?
    @Published var error: String?
    private var previewDirectory: URL?
    private let running = RunningTask()

    /// Not main-actor bound, so the task can be cancelled from deinit.
    private final class RunningTask {
        var task: Task<Void, Never>?
        deinit { task?.cancel() }
    }

    func open(_ document: ClientDocument, api: API) {
        // One at a time: a second tap is said, not silently dropped.
        guard !downloading else {
            announce("A document is already downloading. Wait for it to open.")
            return
        }
        downloading = true; error = nil
        announce("Downloading \(document.title).")
        var components = URLComponents(url: API.base, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "action", value: "download_document"),
            URLQueryItem(name: "id", value: String(document.id)),
        ]
        let sessionToken = api.token
        var request = URLRequest(url: components.url!)
        API.identifyApp(&request)
        request.setValue("Bearer \(sessionToken)", forHTTPHeaderField: "Authorization")
        running.task = Task { [weak self] in
            let reply = try? await URLSession.shared.data(for: request)
            // The client screen closed meanwhile: the document is not written anywhere.
            guard let self, !Task.isCancelled else { return }
            self.finish(document, reply: reply, api: api, sessionToken: sessionToken)
        }
    }

    private func finish(_ document: ClientDocument, reply: (Data, URLResponse)?, api: API, sessionToken: String) {
        defer { downloading = false }
        // Signed out (or another account) meanwhile: nothing is written.
        guard api.token == sessionToken else { return }
        guard let reply else {
            showFormError("Could not download \(document.title). Check your connection and try again.", in: &error)
            return
        }
        let (data, response) = reply
        if (response as? HTTPURLResponse)?.statusCode == 401 {
            // The sign-in opens over the app; the download is not retried, so say so.
            showFormError("Your session ended. Sign in again, then open \(document.title) again.", in: &error)
            api.expireSession()
            return
        }
        guard (response as? HTTPURLResponse)?.statusCode == 200 else {
            // The server says why (the file is missing, the document is gone, or the client is
            // not on this caseload), so a lasting problem doesn't sound like one worth retrying.
            let reason = (try? JSONDecoder().decode(APIErrorResponse.self, from: data))?.error?
                .trimmingCharacters(in: .whitespacesAndNewlines)
            showFormError("Could not download \(document.title)." + ((reason?.isEmpty == false) ? " \(reason!)" : ""), in: &error)
            return
        }
        removePreview()
        do {
            let dir = PreviewStore.directory.appendingPathComponent(UUID().uuidString, isDirectory: true)
            try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
            previewDirectory = dir
            let filename = (document.origName as NSString).lastPathComponent
            let fileURL = dir.appendingPathComponent(filename.isEmpty || filename == "." || filename == ".." ? "document" : filename)
            try data.write(to: fileURL, options: [.atomic, .completeFileProtection])
            previewURL = fileURL
        } catch {
            removePreview()
            showFormError("Could not download \(document.title).", in: &self.error)
        }
    }

    func removePreview() {
        if let dir = previewDirectory { try? FileManager.default.removeItem(at: dir) }
        previewDirectory = nil
    }
}

struct DocumentsSection: View {
    @EnvironmentObject var api: API
    let clientId: Int
    /// Changed by the client screen when it refreshes (after an upload, pull-to-refresh), so
    /// this section reloads with it.
    var reloadToken = 0
    @State private var documents: [ClientDocument] = []
    @State private var loaded = false
    @StateObject private var downloader = DocumentDownloader()
    @State private var error: String?

    var body: some View {
        Section(loaded ? "Documents (\(documents.count))" : "Documents") {
            if let error { ErrorBanner(message: error) }
            if let downloadError = downloader.error { ErrorBanner(message: downloadError) }
            if !loaded && error == nil {
                // Always a row while loading, so the section is never just a heading.
                ProgressView("Loading documents…")
            } else if loaded && documents.isEmpty {
                Text("No documents on file.").foregroundStyle(Color.secondaryText)
            }
            ForEach(documents) { document in
                Button {
                    downloader.open(document, api: api)
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(document.title).font(.body.weight(.medium)).foregroundStyle(.primary)
                        // Resource documents are listed on the client's portal (as client.php says).
                        Text("\(document.category) · \(formatDateTime(document.createdAt))"
                             + (document.category == "Resource" ? " · Shared on the client portal" : ""))
                            .font(.caption).foregroundStyle(Color.secondaryText)
                    }
                }
            }
        }
        .task(id: reloadToken) { await load() }
        .quickLookPreview($downloader.previewURL)
        .onChange(of: downloader.previewURL) { url in
            if url == nil { downloader.removePreview() }
        }
        // A preview still open when this section goes away (sign-out, expired session) keeps
        // its file; PreviewStore.sweep() removes it at sign-out and at the next launch.
        // A download still running carries on (this also fires when the rows scroll out of
        // view); DocumentDownloader cancels it when the client screen itself closes.
        .onDisappear {
            if downloader.previewURL == nil { downloader.removePreview() }
        }
        .overlay { if downloader.downloading { ProgressView().accessibilityLabel("Downloading document") } }
    }

    private func load() async {
        do {
            let response: DocumentsResponse = try await api.call("documents", query: ["client_id": String(clientId)])
            documents = response.documents
            loaded = true
            error = nil
        } catch {
            // A load replaced by a newer one, or the screen closing, is not a failure to report.
            guard !Task.isCancelled else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

// MARK: - Homework

struct HomeworkItem: Decodable, Identifiable {
    let id: Int
    let title: String
    let instructions: String?
    let assignedAt: String
    let dueDate: String?
    let status: String
    let trackTitle: String
    let trackId: Int
    let editToken: String?
    /// When the client marked it done on the portal; it stays Assigned until staff verify it.
    let clientReportedAt: String?
}

extension HomeworkItem {
    /// "Client reported done Oct 8, 2026" for an Assigned item the client marked done.
    var clientReportText: String? {
        guard status == "Assigned", let reported = clientReportedAt, !reported.isEmpty else { return nil }
        return "Client reported done \(formatDate(reported))"
    }
}

struct HomeworkResponse: Decodable { let homework: [HomeworkItem] }

struct HomeworkSection: View {
    @EnvironmentObject var api: API
    let clientId: Int
    let tracks: [Track]
    /// Changed by the client screen when it refreshes (after a note with homework,
    /// pull-to-refresh), so this section reloads with it.
    var reloadToken = 0
    @State private var homework: [HomeworkItem] = []
    @State private var loaded = false
    @State private var showingAssign = false
    @State private var editing: HomeworkItem?
    @State private var confirmingComplete: HomeworkItem?
    @State private var error: String?
    /// The result of the assign or edit sheet, said once the sheet has closed.
    @State private var sheetResult: String?
    /// After Mark completed leaves with the reload, VoiceOver is put on that item's status.
    @AccessibilityFocusState private var focusedStatus: Int?

    var body: some View {
        Section("Homework") {
            if let error { ErrorBanner(message: error) }
            if !loaded && error == nil {
                // Always a row while loading, so the section never reads as "nothing assigned".
                ProgressView("Loading homework…")
            } else if loaded && homework.isEmpty {
                Text("No homework assigned.").foregroundStyle(Color.secondaryText)
            }
            ForEach(homework) { item in
                VStack(alignment: .leading, spacing: 3) {
                    HStack {
                        Text(item.title).font(.body.weight(.medium))
                        Spacer()
                        // The status is in words; the symbol's colour only decorates it.
                        HStack(spacing: 4) {
                            Image(systemName: item.status == "Completed" ? "checkmark.circle.fill" : "clock")
                                .foregroundStyle(item.status == "Completed" ? Color.brandTeal : Color.orange)
                                .accessibilityHidden(true)
                            Text(item.status).foregroundStyle(.primary)
                                .accessibilityFocused($focusedStatus, equals: item.id)
                        }
                        .font(.caption.weight(.semibold))
                    }
                    // In words (as the track page's badge): the status stays Assigned until verified.
                    if let report = item.clientReportText {
                        Text(report).font(.caption.weight(.semibold)).foregroundStyle(.primary)
                    }
                    if let instructions = item.instructions, !instructions.isEmpty {
                        Text(instructions).font(.callout).foregroundStyle(Color.secondaryText)
                    }
                    Text("\(item.trackTitle)\(item.dueDate != nil ? " · due \(formatDate(item.dueDate))" : "")")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                    // Named per item, so the Buttons rotor and Voice Control tell them apart; the
                    // visible words are a spoken name too (Voice Control). "With AI" only when the
                    // edit form offers AI drafting.
                    let editText = api.aiHas("draft") ? "Edit or revise with AI" : "Edit"
                    Button(editText) { editing = item }.font(.callout)
                        .accessibilityLabel("\(editText): \(item.title)")
                        .accessibilityInputLabels([editText, "Edit", "Edit \(item.title)"])
                    if item.status == "Assigned" {
                        // Completing can't be undone and takes it off the client portal: asked first.
                        Button("Mark completed") { confirmingComplete = item }
                            .font(.callout)
                            .buttonStyle(.bordered)
                            .controlSize(.large)   // at least 44 points tall
                            .padding(.top, 2)
                            .accessibilityLabel("Mark \(item.title) completed")
                            .accessibilityInputLabels(["Mark completed", "Mark \(item.title) completed"])
                            .confirmationDialog("Mark “\(item.title)” completed? It disappears from the client portal.",
                                                isPresented: Binding(get: { confirmingComplete?.id == item.id },
                                                                     set: { if !$0 { confirmingComplete = nil } }),
                                                titleVisibility: .visible) {
                                Button("Mark completed") { Task { await complete(item) } }
                                Button("Cancel", role: .cancel) {}
                            }
                    }
                }
                .padding(.vertical, 2)
            }
            if !tracks.filter({ $0.status == "Active" }).isEmpty {
                Button {
                    showingAssign = true
                } label: {
                    Label("Assign homework", systemImage: "plus")
                }
            }
        }
        .task(id: reloadToken) { await load() }
        // Reloaded however the edit sheet closes (saved or cancelled), so the next edit starts
        // from the current version and its edit token — not a stale one the server refuses.
        // A sheet's result is said once it has closed (closing it cut off a message said inside).
        .sheet(item: $editing, onDismiss: {
            announceSheetResult()
            Task { await load() }
        }) { item in
            AssignHomeworkSheet(tracks: tracks, item: item, clientId: clientId) { message in sheetResult = message }
        }
        .sheet(isPresented: $showingAssign, onDismiss: announceSheetResult) {
            AssignHomeworkSheet(tracks: tracks.filter { $0.status == "Active" }) { message in
                sheetResult = message
                Task { await load() }
            }
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    private func load() async {
        do {
            let response: HomeworkResponse = try await api.call("homework", query: ["client_id": String(clientId)])
            homework = response.homework
            loaded = true
            error = nil
        } catch {
            // A load replaced by a newer one, or the screen closing, is not a failure to report.
            guard !Task.isCancelled else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func complete(_ item: HomeworkItem) async {
        // Cleared first so a repeat of the same failure is shown (and announced) again.
        error = nil
        do {
            let _: OKResponse = try await api.call("complete_homework", body: ["id": item.id])
            // The reload takes away the Mark completed button VoiceOver was on: VoiceOver is put
            // on the item's status, then the result is said (said before, it was cut off).
            await load()
            try? await Task.sleep(nanoseconds: 150_000_000)   // let the reloaded rows appear first
            if homework.contains(where: { $0.id == item.id }) { focusedStatus = item.id }
            announceAfterDismiss("\(item.title) marked completed.")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct AssignHomeworkSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let tracks: [Track]
    var item: HomeworkItem? = nil
    /// The client the edited item belongs to, so the latest version can be fetched again.
    var clientId: Int = 0
    /// The result to tell the person, said by the section once the sheet has gone.
    let onDone: (String) -> Void

    /// The edit token of the version this form holds (refreshed by "Reload the latest version").
    @State private var editToken: String?
    /// "Client reported done …" for the version this form holds (refreshed with it).
    @State private var clientReport: String?
    @State private var selectedTrack: Int?
    @State private var title = ""
    @State private var instructions = ""
    @State private var hasDue = true
    @State private var dueDate = Calendar.current.date(byAdding: .day, value: 7, to: Date()) ?? Date()
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false
    /// The form's fields as it opened (after an edited item is filled in), to tell whether
    /// anything was changed.
    @State private var opened: [String]?

    private var values: [String] {
        [selectedTrack.map(String.init) ?? "", title, instructions, hasDue ? APIDate.day(dueDate) : ""]
    }
    /// The start of the server's refusal when the assignment changed since this form opened
    /// (at_homework_update: "This assignment changed. Reload it before saving.").
    private static let changedRefusal = "This assignment changed."
    private var hasChanges: Bool { opened.map { $0 != values } ?? false }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                // After the "This assignment changed" refusal, the current version can be loaded
                // here instead of only by leaving the client screen. Only then: it replaces what
                // was typed, so it is not offered after other errors, where it looked like a retry.
                if item != nil && clientId > 0 && error?.hasPrefix(Self.changedRefusal) == true {
                    Button("Reload the latest version") { Task { await reloadItem() } }
                        .disabled(busy)
                        .accessibilityHint("Replaces your changes with the saved version.")
                }
                // The client's report is kept by a due-date change, but cleared by a new title or
                // new instructions (at_homework_update): said before they are changed.
                if let clientReport {
                    Text("\(clientReport). Changing the title or instructions clears the client's report, so they would have to report it again; changing only the due date keeps it.")
                        .font(.callout)
                }
                Picker("Training track (required)", selection: $selectedTrack) {
                    Text("Choose…").tag(nil as Int?)
                    ForEach(tracks) { track in
                        Text(track.title).tag(track.id as Int?)
                    }
                }
                .disabled(item != nil)
                // Each name stays visible above its field once it holds text (a placeholder alone
                // disappears); the field carries the same name for VoiceOver, so the caption is hidden.
                CaptionedField(label: "Assignment title (required)", text: $title, prompt: "Assignment title")
                CaptionedField(label: "Instructions", text: $instructions, lines: 3...8)
                if api.aiHas("draft") {
                    ATAIDraftPanel(kind: "homework", targets: [ATDraftTarget(key: "instructions", label: "Instructions"), ATDraftTarget(key: "title", label: "Title")], context: ["track_id": selectedTrack.map(String.init) ?? "", "title": title, "instructions": instructions]) { key, text in
                        if key == "title" { title = text } else { instructions = text }
                    }
                }
                Toggle("Due date", isOn: $hasDue)
                if hasDue {
                    DatePicker("Due", selection: $dueDate, displayedComponents: .date)
                }
                Text("Shows on the client's portal page until completed.")
                    .font(.caption).foregroundStyle(Color.secondaryText)
            }
            .navigationTitle(item == nil ? "Assign homework" : "Edit homework")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing track or title: submit() says which is needed.
                    Button(item == nil ? "Assign" : "Save") { Task { await submit() } }
                        .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy,
                                 question: item == nil ? "Discard this homework?" : "Discard your changes to this homework?",
                                 asking: $askingDiscard) { dismiss() }
             .task {
                guard opened == nil else { return }
                if let item {
                    selectedTrack = item.trackId; title = item.title; instructions = item.instructions ?? ""; hasDue = item.dueDate != nil
                    if let date = item.dueDate.flatMap(APIDate.parseDay) { dueDate = date }
                    editToken = item.editToken
                    clientReport = item.clientReportText
                } else if tracks.count == 1 { selectedTrack = tracks.first?.id }
                opened = values
            }
        }
    }

    /// Replaces the form with the assignment as it is now on the server (and its edit token).
    private func reloadItem() async {
        guard let item else { return }
        busy = true; defer { busy = false }
        do {
            let response: HomeworkResponse = try await api.call("homework", query: ["client_id": String(clientId)])
            guard let fresh = response.homework.first(where: { $0.id == item.id }) else {
                showFormError("This assignment is no longer listed for the client.", in: &error)
                return
            }
            title = fresh.title
            instructions = fresh.instructions ?? ""
            hasDue = fresh.dueDate != nil
            if let date = fresh.dueDate.flatMap(APIDate.parseDay) { dueDate = date }
            editToken = fresh.editToken
            clientReport = fresh.clientReportText
            error = nil
            announce("Latest version loaded. Make your changes again, then save.")
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func submit() async {
        guard let trackId = selectedTrack else {
            showFormError("Training track: choose the track this homework belongs to.", in: &error)
            return
        }
        guard !title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            showFormError("Assignment title: enter a title for the homework.", in: &error)
            return
        }
        busy = true; error = nil
        do {
            var body: [String: Any] = ["track_id": trackId, "title": title, "instructions": instructions, "due_date": hasDue ? APIDate.day(dueDate) : ""]
            if let item { body["id"] = item.id; body["edit_token"] = editToken ?? item.editToken ?? "" }
            let _: OKResponse = try await api.call(item == nil ? "assign_homework" : "at_homework_update", body: body)
            // Said by the Homework section once this sheet has closed (closing it would cut it off).
            onDone(item == nil ? "Homework assigned." : "Homework updated.")
            dismiss()
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }
}
