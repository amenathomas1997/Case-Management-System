import SwiftUI

struct ClientsView: View {
    @EnvironmentObject var api: API
    @State private var clients: [ClientRow] = []
    @State private var search = ""
    @State private var error: String?
    @State private var loaded = false
    @State private var showingNewClient = false
    /// The result of the last client save, shown here; announced once its sheet has gone.
    @State private var confirmation: String?
    @State private var sheetResult: String?

    var body: some View {
        NavigationStack {
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
                if let confirmation {
                    Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
                }
                if !loaded && error == nil {
                    ProgressView("Loading clients…")
                } else if loaded && clients.isEmpty && error == nil {
                    // "Still loading", "failed" and "nothing matched" each say so.
                    Text(search.isEmpty
                         ? (api.scoped ? "No clients on your caseload." : "No clients yet.")
                         : "No clients found for “\(search)”.")
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
                // The server sends at most 200 clients.
                if clients.count >= 200 {
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
            }
            .sheet(isPresented: $showingNewClient, onDismiss: announceSheetResult) {
                ClientEditSheet(existing: nil) { _, message in
                    confirmation = message; sheetResult = message
                    Task { await load() }
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

    private func load(announceResult: Bool = false) async {
        let q = search
        do {
            error = nil
            let response: ClientsResponse = try await api.call("clients", query: q.isEmpty ? [:] : ["q": q])
            guard q == search else { return } // a newer keystroke superseded this request
            clients = response.clients
            loaded = true
            // Focus stays in the search field, so the outcome of a search is spoken.
            if announceResult {
                announce(clients.isEmpty ? "No clients found." : "\(clients.count) client\(clients.count == 1 ? "" : "s") found.")
            }
        } catch {
            guard q == search else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

struct ClientDetailView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    @State private var detail: ClientDetail?
    @State private var error: String?
    @State private var showingNoteSheet = false
    @State private var editingNote: Note?
    @State private var showingEditClient = false
    @State private var showingUpload = false
    @State private var showingAISummary = false
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
    @Environment(\.dynamicTypeSize) private var typeSize

    private var shownNotes: [Note] { (detail?.notes ?? []) + olderNotes }

    private var notesHeader: String {
        let shown = shownNotes.count
        if let total = detail?.notesTotal, total > shown { return "Notes (latest \(shown) of \(total))" }
        if detail?.notesTotal == nil && shown >= 50 { return "Notes (latest \(shown))" }
        return "Notes (\(shown))"
    }

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let confirmation {
                Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
            }
            if let detail {
                Section("Contact") {
                    LabeledContent("Status", value: detail.client.status)
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
                        if let url = URL(string: "mailto:" + email) {
                            Link(destination: url) { LabeledContent("Email", value: email) }
                        } else {
                            LabeledContent("Email", value: email)
                        }
                    }
                    if let counselor = detail.client.counselorName, !counselor.isEmpty {
                        LabeledContent("Vocational rehabilitation (VR) counselor", value: counselor)
                    }
                    if let counselorEmail = detail.client.counselorEmail, !counselorEmail.isEmpty {
                        if let url = URL(string: "mailto:" + counselorEmail) {
                            Link(destination: url) { LabeledContent("Counselor email", value: counselorEmail) }
                        } else {
                            LabeledContent("Counselor email", value: counselorEmail)
                        }
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

                Section("Progress tools") {
                    NavigationLink { ScoredAssessmentsView(clientId: clientId) } label: { Label("Scored assessments", systemImage: "list.number") }
                    NavigationLink { CompetencyView(clientId: clientId) } label: { Label("Curriculum & competency", systemImage: "graduationcap") }
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
                DocumentsSection(clientId: clientId, reloadToken: sectionReload)

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
                                }
                            }
                            if mayTruncate(note.body, lines: 6, accessibilitySize: typeSize.isAccessibilitySize) {
                                NoteExpandButton(subject: note.subject, open: open) {
                                    if open { expandedNotes.remove(note.id) } else { expandedNotes.insert(note.id) }
                                }
                            }
                        }
                        .padding(.vertical, 2)
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
                if api.aiHas("draft") {
                    Button { confirmation = nil; showingAISummary = true } label: {
                        Label("AI case summary", systemImage: "sparkles")
                    }
                }
            } label: {
                Label("Actions", systemImage: "ellipsis.circle")
            }
        }
        // Each sheet hands back what to tell the person; it is shown here and said once the
        // sheet has closed (closing it moves VoiceOver focus, which cut off a message said inside).
        .sheet(isPresented: $showingAISummary, onDismiss: announceSheetResult) {
            AICaseSummarySheet(clientId: clientId) { message in
                confirmation = message; sheetResult = message
                Task { await reload() }
            }
        }
        .sheet(isPresented: $showingEditClient, onDismiss: announceSheetResult) {
            if let client = detail?.client {
                ClientEditSheet(existing: client, emailVerified: emailVerified) { _, message in
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
        .refreshable { confirmation = nil; await reload() }
        .task { await load() }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
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
            let record: ClientRecord = try await api.call("client", query: ["id": String(clientId)])
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
    /// The result to tell the person. The presenter shows it and says it once the sheet has
    /// gone: closing the sheet moves VoiceOver focus, which cut off a message said from here.
    let onSaved: (String) -> Void

    private let noteTypes = ["Case Note", "Phone Call", "Progress Report", "Monthly Summary", "Intake"]
    @State private var noteType = "Case Note"
    @State private var noteFormat = "free"
    @State private var subject = ""
    @State private var body_ = ""
    @State private var sections = ["", "", "", ""]
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

    /// A new note's unsaved text is kept on this phone as it is typed (DraftStore), so a forced
    /// sign-in or the app being closed does not lose it. Cleared once it is saved, queued
    /// offline or discarded.
    private var draftKey: String { "note-\(clientId)" }

    private func saveDraft() {
        guard existing == nil else { return }
        var fields = ["subject": subject, "body": body_,
                      "sec1": sections[0], "sec2": sections[1], "sec3": sections[2], "sec4": sections[3]]
        // The type and format travel only with text: a draft with nothing typed is removed.
        if fields.values.contains(where: { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) {
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
        if existing == nil { DraftStore.clear(key: draftKey, owner: api.userId) }
    }

    private var activeTracks: [Track] { tracks.filter { $0.status == "Active" } }
    private var labels: [String] { noteFormatLabels[noteFormat] ?? [] }
    private var structured: Bool { noteFormat != "free" }

    /// Anything typed, dictated or generated that closing the sheet would throw away.
    private var hasChanges: Bool {
        if let existing {
            let saved = existing.sections?.count == 4 ? existing.sections! : ["", "", "", ""]
            // Every field update_note saves, against what onAppear filled in from the note.
            return subject != existing.subject || body_ != existing.body || sections != saved
                || noteType != existing.noteType || noteFormat != (existing.noteFormat ?? "free")
                || attachTrackId != (existing.trackId ?? 0)
        }
        return !subject.isEmpty || !body_.isEmpty || sections.contains { !$0.isEmpty } || !messy.isEmpty || !homework.isEmpty
    }

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

    /// Convert pasted messy notes into the chosen structured format.
    private func generate() async {
        genBusy = true; error = nil
        let sentSections = sections
        let wasFree = !structured
        do {
            let fmt = structured ? noteFormat : "BIRP"
            let r: GenerateNoteResponse = try await api.call("ai_generate_note", body: [
                "client_id": clientId, "note_format": fmt, "text": messy,
            ])
            // Section text typed or dictated while the request ran is never replaced.
            guard sections == sentSections else {
                showFormError("The \(noteFormat) sections changed while the draft was being generated, so it was not applied. Tap Generate structured note again to replace them.", in: &error)
                genBusy = false
                return
            }
            noteFormat = r.format
            for i in 0..<4 { sections[i] = i < r.sections.count ? r.sections[i] : "" }
            questions = r.questions
            homework = r.homework
            if hwTrackId == 0 { hwTrackId = activeTracks.first?.id ?? 0 }
            // The draft fills in further down the form with nothing moving: say what arrived.
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
            ErrorFocusForm(error: error) {
                if existing != nil {
                    Text("Edits are recorded on the note and in the audit log.")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                } else if restoredDraft {
                    Text("Your unsaved note from earlier was restored. Cancel, then Discard, removes it.")
                        .font(.callout)
                }
                Section {
                    Picker("Type", selection: $noteType) { ForEach(noteTypes, id: \.self) { Text($0) } }
                    // Each format with its sections spelled out, as on the website.
                    Picker("Format", selection: $noteFormat) {
                        Text("Free text").tag("free")
                        ForEach(["BIRP", "PAIP", "SOAP"], id: \.self) { Text(noteFormatDescription($0)).tag($0) }
                    }
                    if !activeTracks.isEmpty {
                        Picker("Attach to track", selection: $attachTrackId) {
                            Text("None").tag(0)
                            ForEach(activeTracks) { Text($0.title).tag($0.id) }
                        }
                    }
                    CaptionedField(label: "Subject (required)", text: $subject, prompt: "Subject")
                }

                // Generator: paste messy notes → structured draft.
                if existing == nil && api.aiHas("draft") {
                    Section("Generate from messy notes") {
                        Text("Paste your raw session notes; the assistant drafts the \(structured ? noteFormat : "BIRP") sections, asks about anything missing, and pulls out homework.")
                            .font(.caption).foregroundStyle(Color.secondaryText)
                        TextField("Paste raw notes here", text: $messy, axis: .vertical).lineLimit(3...10)
                        Button { Task { await generate() } } label: {
                            Label(genBusy ? "Generating…" : "Generate structured note", systemImage: "wand.and.stars")
                        }.disabled(genBusy || messy.isEmpty)
                    }
                }

                if !questions.isEmpty {
                    Section {
                        ForEach(questions, id: \.self) { q in
                            Label(q, systemImage: "questionmark.circle").font(.callout)
                        }
                    } header: { Text("Before you save — double-check") }
                }

                if structured {
                    Section("\(noteFormat) sections (fill in at least one)") {
                        ForEach(0..<4, id: \.self) { i in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(i < labels.count ? labels[i] : "Section \(i + 1)")
                                    .font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
                                TextField(i < labels.count ? labels[i] : "", text: $sections[i], axis: .vertical)
                                    .lineLimit(2...8)
                            }
                        }
                    }
                    // A new note keeps its narrative box, as on the website: text typed in it
                    // (before the format changed, or before Generate) is saved after the
                    // sections under "Narrative", never dropped. An edit keeps the note's own
                    // narrative on the server, so it is not shown here.
                    if existing == nil {
                        Section("Narrative (optional)") {
                            TextField("Narrative", text: $body_, axis: .vertical).lineLimit(3...12)
                            Text("Saved after the sections under “Narrative”. Clear it if the sections already cover it.")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                } else {
                    Section("Note (required)") {
                        TextField("Note", text: $body_, axis: .vertical).lineLimit(5...12)
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
                                    }
                                }
                            }
                        }
                    } header: { Text("Homework → client portal") }
                }
            }
            .navigationTitle(existing == nil ? "Add note" : "Edit note")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing subject or text: save() says which field is needed.
                    Button("Save") { Task { await save() } }.disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy,
                                 question: existing == nil ? "Discard this note?" : "Discard your changes to this note?",
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
                if existing == nil && initialTrackId > 0 {
                    if attachTrackId == 0 { attachTrackId = initialTrackId }
                    // Homework goes only to an active track (one the picker offers).
                    if activeTracks.contains(where: { $0.id == initialTrackId }) { hwTrackId = initialTrackId }
                }
                if hwTrackId == 0 { hwTrackId = activeTracks.first?.id ?? 0 }
            }
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
        }
        payload["track_id"] = attachTrackId
        do {
            if let existing {
                payload["id"] = existing.id
                let _: OKResponse = try await api.call("update_note", body: payload)
            } else {
                payload["client_id"] = clientId
                payload["idem_key"] = idemKey
                let kept = homework.filter { $0.include && !$0.title.isEmpty }
                // Only to an active track the picker offers: the sheet says homework is not
                // saved otherwise (opened from a completed track, for example).
                if !kept.isEmpty && activeTracks.contains(where: { $0.id == hwTrackId }) {
                    payload["homework_track_id"] = hwTrackId
                    payload["homework"] = kept.map { ["title": $0.title, "instructions": $0.instructions, "due": $0.due] }
                }
                let content = [noteType, noteFormat, subject, body_] + sections
                if firstTryContent == nil { firstTryContent = content }
                do {
                    let result: SaveAck = try await api.call("add_note", body: payload)
                    if result.deduplicated == true {
                        // An earlier try was saved although its reply was lost.
                        let message = firstTryContent == content
                            ? "This note was already saved, so it was not added twice."
                            : "This note was already saved from an earlier try, so it was not added twice. Changes made after that try are not in it; edit the saved note to add them."
                        clearDraft(); onSaved(message); dismiss(); return
                    }
                } catch APIError.network {
                    // No signal: keep the note on the phone and sync later. Homework
                    // is included in the payload, so it syncs with the note.
                    try api.enqueue("add_note", body: payload, label: "Note: \(subject)")
                    clearDraft()
                    onSaved("Note saved offline — will sync when connected."); dismiss(); return
                }
            }
            clearDraft()
            onSaved(existing == nil ? "Note saved." : "Note updated."); dismiss()
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
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
            ErrorFocusForm(error: error) {
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
