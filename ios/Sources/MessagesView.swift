import SwiftUI
import PhotosUI

/// A message_threads row with its per-channel unread counts (absent on older servers), so a
/// thread can open on the conversation that holds the unread messages.
struct MessageThreadSummary: Decodable, Identifiable {
    let thread: MessageThread
    let unreadClient: Int?
    let unreadCounselor: Int?
    var id: Int { thread.id }
    private enum CodingKeys: String, CodingKey { case unreadClient, unreadCounselor }
    init(from d: Decoder) throws {
        thread = try MessageThread(from: d)
        let c = try d.container(keyedBy: CodingKeys.self)
        unreadClient = try? c.decodeIfPresent(Int.self, forKey: .unreadClient)
        unreadCounselor = try? c.decodeIfPresent(Int.self, forKey: .unreadCounselor)
    }
    /// Opening a thread marks only the channel it shows as read: start on the counselor's
    /// when every unread message is there.
    var startChannel: String { (unreadClient ?? 0) == 0 && (unreadCounselor ?? 0) > 0 ? "counselor" : "client" }
    var unreadLabel: String {
        guard let client = unreadClient, let counselor = unreadCounselor, client + counselor > 0 else { return "\(thread.unread) unread" }
        var parts: [String] = []
        if client > 0 { parts.append("\(client) unread from the client") }
        if counselor > 0 { parts.append("\(counselor) unread from the counselor") }
        return parts.joined(separator: ", ")
    }
}
struct MessageThreadSummaries: Decodable { let threads: [MessageThreadSummary] }

/// Secure message center (web v2.53 parity): staff ↔ client and staff ↔ counselor
/// threads, one per client per channel.
struct MessagesView: View {
    @EnvironmentObject var api: API
    @State private var threads: [MessageThreadSummary] = []
    @State private var loaded = false
    @State private var error: String?
    @State private var picking = false
    /// Unsent replies by client and channel, kept here while the Messages screen is open so
    /// going back from a thread does not lose what was typed. Each thread also keeps its
    /// replies on this phone (DraftStore), so closing the app or a forced sign-in does not.
    @State private var drafts: [String: String] = [:]

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            // Names the button as it is labelled, so Voice Control and VoiceOver users can find it.
            // The 7:1 secondary-text colour (system secondary is about 3.4:1).
            if loaded && threads.isEmpty {
                Text("No conversations yet. Start one with the New message button.")
                    .foregroundStyle(Color.secondaryText)
            } else if !loaded && error == nil {
                ProgressView("Loading conversations…")
            }
            ForEach(threads) { summary in
                let t = summary.thread
                NavigationLink { MessageThreadView(clientId: t.clientId, clientName: t.clientName, startChannel: summary.startChannel, drafts: $drafts) } label: {
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(t.clientName).font(.body.weight(.semibold))
                            Text("\(t.total) message\(t.total == 1 ? "" : "s")\(t.lastAt != nil ? " · \(formatDateTime(t.lastAt!))" : "")")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        }
                        Spacer()
                        if t.unread > 0 {
                            // White text needs the fill that stays navy in dark mode too.
                            Text("\(t.unread)").font(.caption.bold()).foregroundStyle(.white)
                                .padding(.horizontal, 8).padding(.vertical, 3)
                                .background(Color.brandFill).clipShape(Capsule())
                                .accessibilityLabel(summary.unreadLabel)
                        }
                    }
                    // One stop, name and unread first: what decides whether to open it.
                    .accessibilityElement(children: .ignore)
                    .accessibilityLabel([t.clientName,
                                         t.unread > 0 ? summary.unreadLabel : nil,
                                         "\(t.total) message\(t.total == 1 ? "" : "s")",
                                         t.lastAt.map { "last \(formatDateTime($0))" }]
                        .compactMap { $0 }.joined(separator: ", "))
                }
            }
        }
        .navigationTitle("Messages")
        .toolbar {
            Button { picking = true } label: { Label("New message", systemImage: "square.and.pencil") }
        }
        // The picker opens threads itself; reload when it closes so a conversation started
        // there (and any messages read there) show up in this list.
        .sheet(isPresented: $picking, onDismiss: { Task { await load() } }) {
            MessageClientPicker(drafts: $drafts)
        }
        .refreshable { await load() }
        .task { await load() }
    }

    private func load() async {
        do {
            error = nil
            let r: MessageThreadSummaries = try await api.call("message_threads")
            threads = r.threads
            loaded = true
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

/// Lets the user pick any client (scope-limited by the server) to open a thread.
struct MessageClientPicker: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    /// The Messages list's unsent replies (see MessagesView.drafts).
    @Binding var drafts: [String: String]
    @State private var clients: [ClientRow] = []
    @State private var search = ""
    /// The search the list on screen came from, set once that search has loaded.
    @State private var shownSearch: String?
    @State private var error: String?

    var body: some View {
        NavigationStack {
            List {
                if let error { ErrorBanner(message: error) }
                if let shownSearch, clients.isEmpty {
                    Text(shownSearch.isEmpty ? "No clients found." : "No clients match your search.").foregroundStyle(Color.secondaryText)
                } else if shownSearch == nil && error == nil {
                    ProgressView("Loading clients…")
                }
                ForEach(clients) { c in
                    NavigationLink { MessageThreadView(clientId: c.id, clientName: c.name, drafts: $drafts) } label: {
                        Text(c.name)
                    }
                }
            }
            .navigationTitle("Message a client")
            .navigationBarTitleDisplayMode(.inline)
            .searchable(text: $search, prompt: "Search clients")
            .onChange(of: search) { typed in
                Task {
                    // Wait for a pause in typing, so each keystroke is not a request and an announcement.
                    try? await Task.sleep(nanoseconds: 350_000_000)
                    guard typed == search else { return }
                    await load()
                }
            }
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Done") { dismiss() } } }
            .task { await load() }
        }
    }
    private func load() async {
        let q = search
        do {
            error = nil
            let r: ClientsResponse = try await api.call("clients", query: q.isEmpty ? [:] : ["q": q])
            guard q == search else { return }   // a newer keystroke superseded this request
            clients = r.clients
            shownSearch = q
            if !q.isEmpty {
                announce(r.clients.isEmpty ? "No clients match your search." : "\(r.clients.count) client\(r.clients.count == 1 ? "" : "s") found.")
            }
        } catch {
            guard q == search else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

struct MessageThreadView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    let clientName: String
    @State private var channel: String
    @State private var messages: [ThreadMessage] = []
    @State private var counselorName: String?
    /// One draft per client and channel, so text written to the client never moves to the
    /// counselor's thread (or the other way round) when the channel is switched. Kept by the
    /// Messages list when it passes them in, so leaving the thread does not lose them.
    private let sharedDrafts: Binding<[String: String]>?
    @State private var localDrafts: [String: String] = [:]
    @State private var error: String?
    /// A saved message the recipient cannot read yet (no active portal link).
    @State private var notice: String?
    @State private var sending = false
    @State private var loaded = false
    @AccessibilityFocusState private var focusedMessage: Int?
    /// By channel, a reply whose sending was not confirmed, with the idempotency key it was sent
    /// with: sending the same text again reuses the key, so a server that recognises it does not
    /// save the message twice (changed text is a new message with a new key).
    @State private var unconfirmed: [String: (text: String, key: String)] = [:]
    /// Texts (4.17.0): whether this client can be texted, and staff's choice to also text this reply.
    @State private var textEnabled = false
    @State private var textBlocked: String?
    @State private var byText = false
    /// A file sent with the reply (4.25.0): one of the client's documents, or a new file.
    @State private var attachment: MessageAttachment?
    @State private var choosingDocument = false
    @State private var showingCamera = false
    @State private var showingPhotos = false
    @State private var showingFiles = false
    @State private var photoItem: PhotosPickerItem?
    @StateObject private var downloader = DocumentDownloader()
    /// Files can be sent only to a server that keeps them (4.25.0); an older one would drop the file.
    @State private var canAttach = false

    /// `startChannel` is the conversation shown first ("client" or "counselor").
    init(clientId: Int, clientName: String, startChannel: String = "client", drafts: Binding<[String: String]>? = nil) {
        self.clientId = clientId
        self.clientName = clientName
        self.sharedDrafts = drafts
        _channel = State(initialValue: startChannel == "counselor" ? "counselor" : "client")
    }

    private func draftText(_ ch: String) -> String {
        (sharedDrafts?.wrappedValue ?? localDrafts)["\(clientId)|\(ch)"] ?? ""
    }
    private func setDraft(_ ch: String, _ text: String) {
        let key = "\(clientId)|\(ch)"
        let value: String? = text.isEmpty ? nil : text
        if let sharedDrafts { sharedDrafts.wrappedValue[key] = value } else { localDrafts[key] = value }
        // Kept on this phone as it is typed; an empty reply removes the saved one.
        DraftStore.save(["body": text], key: storedDraftKey(ch), owner: api.userId)
    }

    /// The unsent reply on this phone (DraftStore), per client and channel. Cleared once sent.
    private func storedDraftKey(_ ch: String) -> String { "message-\(clientId)-\(ch)" }

    /// Puts back the replies kept on this phone that are not already in the box. Returns what
    /// to say about the channel shown, if its reply was put back.
    private func restoreStoredDrafts() -> String? {
        var restoredShown = false
        for ch in ["client", "counselor"] where draftText(ch).isEmpty {
            guard let text = DraftStore.load(key: storedDraftKey(ch), owner: api.userId)?["body"],
                  !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { continue }
            setDraft(ch, text)
            if ch == channel { restoredShown = true }
        }
        return restoredShown ? "Your unsent message to \(recipient) was restored in the message box." : nil
    }
    private var draft: Binding<String> {
        Binding(get: { draftText(channel) }, set: { setDraft(channel, $0) })
    }
    private var recipient: String { channel == "counselor" ? "the counselor" : clientName }

    private var emptyText: String {
        channel == "counselor" && (counselorName ?? "").isEmpty
            ? "No counselor on file for this client."
            : "No messages yet."
    }

    var body: some View {
        GeometryReader { screen in
        VStack(spacing: 0) {
            Picker("Channel", selection: $channel) {
                Text("Client").tag("client")
                Text("Counselor").tag("counselor")
            }
            .modifier(SegmentedUnlessLargeText()).padding()   // a menu at the accessibility text sizes
            .onChange(of: channel) { _ in
                // Never leave the other channel's messages under the new selection.
                messages = []; loaded = false; notice = nil
                // A file goes to whoever the conversation is with, so it does not follow the switch.
                if attachment != nil {
                    attachment = nil
                    announce("Attachment removed, because the conversation changed.")
                }
                Task { await load(focusNewest: true) }
            }

            if let error { ErrorBanner(message: error).padding(.horizontal) }
            if let downloadError = downloader.error { ErrorBanner(message: downloadError).padding(.horizontal) }
            if let notice {
                Label(notice, systemImage: "exclamationmark.triangle")
                    .font(.callout.weight(.medium))
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.horizontal)
            }

            ScrollViewReader { proxy in
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 8) {
                        if messages.isEmpty && loaded {
                            Text(emptyText).foregroundStyle(Color.secondaryText).padding()
                        }
                        ForEach(messages) { m in bubble(m) }
                    }
                    .padding(.horizontal)
                }
                // Open on (and follow) the newest message instead of the oldest.
                .onChange(of: messages.last?.id) { newest in
                    guard let newest else { return }
                    // After this update's layout, so the new rows exist to scroll to.
                    DispatchQueue.main.async { proxy.scrollTo(newest, anchor: .bottom) }
                }
            }

            // 4.29.0: the controls under the conversation scroll when they would not fit.
            VStack(spacing: 0) {
            if channel == "client" && textEnabled {
                if textBlocked == nil {
                    Toggle("Also send it as a text message", isOn: $byText).padding(.horizontal)
                } else {
                    Text("Not by text: \(textBlocked ?? "")").font(.callout).foregroundStyle(Color.secondaryText)
                        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal)
                }
            }
            if let attachment {
                HStack {
                    Label("Attached: \(attachment.name)", systemImage: "paperclip").font(.callout)
                    Spacer()
                    Button {
                        self.attachment = nil
                        announce("Attachment removed.")
                    } label: {
                        Image(systemName: "xmark.circle").frame(minWidth: 44, minHeight: 44).contentShape(Rectangle())
                    }
                    .accessibilityLabel("Remove attachment: \(attachment.name)")
                    .accessibilityInputLabels(["Remove attachment", "Remove"])
                }
                .padding(.horizontal)
            }
            HStack(spacing: 8) {
                if canAttach { attachMenu }
                // Both the field and the button name who the message goes to.
                TextField(channel == "counselor" ? "Message to counselor…" : "Message to client…", text: draft, axis: .vertical).lineLimit(1...4)
                    .textFieldStyle(.roundedBorder)
                    .accessibilityLabel("Message to \(recipient)")
                Button { Task { await send() } } label: {
                    // A 44-point target around the icon, not just the glyph.
                    Image(systemName: "arrow.up.circle.fill").font(.title2)
                        .frame(minWidth: 44, minHeight: 44)
                        .contentShape(Rectangle())
                }
                .accessibilityLabel("Send message to \(recipient)")
                // Short names for Voice Control ("Tap Send"), 4.30.0.
                .accessibilityInputLabels(["Send", "Send message", "Send message to \(recipient)"])
                .disabled(sending || (attachment == nil && draft.wrappedValue.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty))
            }.padding()
            }
            .modifier(ScrollsWhenCramped(maxHeight: screen.size.height * 0.5))
        }
        }
        .navigationTitle(clientName)
        .navigationBarTitleDisplayMode(.inline)
        .quickLookPreview($downloader.previewURL)
        .onChange(of: downloader.previewURL) { url in
            if url == nil { downloader.removePreview() }
        }
        .sheet(isPresented: $choosingDocument) {
            MessageDocumentPicker(clientId: clientId) { document in
                attachment = .document(id: document.id, title: document.title)
                announceAfterDismiss("\(document.title) attached. It is shared with \(recipient) when you send.")
            }
        }
        .fullScreenCover(isPresented: $showingCamera) {
            CameraCapture { image in
                if let jpeg = image.jpegData(compressionQuality: 0.7) {
                    attachFile(jpeg, as: "photo_\(Int(Date().timeIntervalSince1970)).jpg")
                } else {
                    attachError("Could not use that photo. Try taking it again.")
                }
            }
            .ignoresSafeArea()
        }
        .photosPicker(isPresented: $showingPhotos, selection: $photoItem, matching: .images)
        .onChange(of: photoItem) { item in
            guard let item else { return }
            photoItem = nil
            Task {
                guard let data = try? await item.loadTransferable(type: Data.self) else {
                    attachError("Could not read that photo. Choose another, or take a new one.")
                    return
                }
                let jpeg = await Task.detached(priority: .userInitiated) { UIImage(data: data)?.jpegData(compressionQuality: 0.7) }.value
                guard let jpeg else { attachError("Could not read that photo. Choose another, or take a new one."); return }
                attachFile(jpeg, as: "photo_\(Int(Date().timeIntervalSince1970)).jpg")
            }
        }
        .fileImporter(isPresented: $showingFiles, allowedContentTypes: UploadDocumentSheet.importTypes) { result in
            switch result {
            case .success(let url): Task { await importFile(url) }
            case .failure: attachError("Could not open that file.")
            }
        }
        .task {
            let restored = restoreStoredDrafts()
            async let probe: ClientFeatureProbe? = try? api.call("client", query: ["id": String(clientId)])
            await load(focusNewest: true)
            canAttach = await probe?.supports425 ?? false
            // Said once VoiceOver has moved to the newest message, which would cut it off.
            if let restored { announce(restored) }
        }
    }

    private func bubble(_ m: ThreadMessage) -> some View {
        let mine = m.sender == "staff"
        return VStack(alignment: mine ? .trailing : .leading, spacing: 4) {
            HStack {
                if mine { Spacer(minLength: 40) }
                VStack(alignment: mine ? .trailing : .leading, spacing: 2) {
                    Text(m.body).font(.callout)
                        .foregroundStyle(mine ? .white : .primary)
                        .padding(10)
                        .background(mine ? Color.brandFill : Color(.secondarySystemBackground),
                                    in: RoundedRectangle(cornerRadius: 12))
                    // Caption size in the 7:1 secondary-text colour (caption2 in system secondary was about 3.4:1).
                    Text("\(senderLabel(m))\(m.via == "text" ? ", by text" : "") · \(formatDateTime(m.createdAt))")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                }
                if !mine { Spacer(minLength: 40) }
            }
            // One element per message, spoken sender first.
            .accessibilityElement(children: .ignore)
            .accessibilityLabel("\(senderLabel(m))\(m.via == "text" ? ", by text" : ""): \(m.body)")
            .accessibilityValue(formatDateTime(m.createdAt))
            .accessibilityFocused($focusedMessage, equals: m.id)
            // The message's file (4.25.0), opened as the client's documents are.
            if let documentId = m.documentId {
                let title = (m.documentTitle ?? "").isEmpty ? "Attached file" : m.documentTitle!
                Button {
                    downloader.open(ClientDocument(id: documentId, title: title), api: api)
                } label: {
                    Label("File: \(title)", systemImage: "doc").font(.callout)
                        .frame(minHeight: 44).contentShape(Rectangle())
                }
                .disabled(downloader.downloading)
            }
        }
        .frame(maxWidth: .infinity, alignment: mine ? .trailing : .leading)
        .id(m.id)
    }

    /// Attach a file: one of the client's documents, or a new one (camera, photos or Files).
    private var attachMenu: some View {
        Menu {
            Button { choosingDocument = true } label: { Label("Choose one of their documents", systemImage: "doc.on.doc") }
            Section("Add a new file") {
                if UIImagePickerController.isSourceTypeAvailable(.camera) {
                    Button { showingCamera = true } label: { Label("Take a photo", systemImage: "camera") }
                }
                Button { showingPhotos = true } label: { Label("Choose from photo library", systemImage: "photo.on.rectangle") }
                Button { showingFiles = true } label: { Label("Choose a file (PDF, Word…)", systemImage: "folder") }
            }
        } label: {
            Image(systemName: "paperclip").font(.title3)
                .frame(minWidth: 44, minHeight: 44)
                .contentShape(Rectangle())
        }
        .accessibilityLabel("Attach a file")
        .accessibilityInputLabels(["Attach", "Attach a file", "Paperclip"])
        .disabled(sending)
    }

    /// Checks the type and size before reading, as the upload form does.
    private func importFile(_ url: URL) async {
        guard UploadDocumentSheet.allowedExtensions.contains(url.pathExtension.lowercased()) else {
            attachError("That type of file can't be sent. Choose a PDF, Word, Excel, PowerPoint, text, RTF or CSV file, or a PNG, JPEG or HEIC image.")
            return
        }
        let accessed = url.startAccessingSecurityScopedResource()
        defer { if accessed { url.stopAccessingSecurityScopedResource() } }
        if let size = try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize, size > UploadDocumentSheet.maxBytes {
            attachError("That file is larger than 25 MB, the most that can be sent.")
            return
        }
        let data = await Task.detached(priority: .userInitiated) { try? Data(contentsOf: url) }.value
        guard let data, !data.isEmpty, data.count <= UploadDocumentSheet.maxBytes else {
            attachError("Could not read that file (25 MB maximum).")
            return
        }
        attachFile(data, as: UploadDocumentSheet.uploadName(url.lastPathComponent))
    }

    /// Said a beat later: the camera, photo picker or file browser is still closing.
    private func attachFile(_ data: Data, as filename: String) {
        guard data.count <= UploadDocumentSheet.maxBytes else {
            attachError("That file is larger than 25 MB, the most that can be sent.")
            return
        }
        attachment = .file(data: data, filename: filename)
        error = nil
        let who = recipient
        Task {
            try? await Task.sleep(nanoseconds: 700_000_000)
            announce("\(filename) attached. It is saved to the client's documents and shared with \(who) when you send.")
        }
    }

    private func attachError(_ message: String) {
        Task {
            try? await Task.sleep(nanoseconds: 700_000_000)
            showFormError(message, in: &error)
        }
    }
    private func senderLabel(_ m: ThreadMessage) -> String {
        switch m.sender {
        case "staff": return m.staffName ?? "Staff"
        case "counselor": return "Counselor"
        default: return clientName
        }
    }

    /// `focusNewest` moves VoiceOver to the latest message (on opening a thread or switching
    /// channel); after sending, focus stays where it is and the send is announced instead.
    private func load(focusNewest: Bool = false) async {
        let requested = channel
        do {
            error = nil
            let r: ThreadResponse = try await api.call("message_thread",
                query: ["client_id": String(clientId), "channel": requested])
            guard requested == channel else { return }   // the channel changed while this was loading
            messages = r.messages
            counselorName = r.counselorName
            textEnabled = r.textEnabled ?? false
            textBlocked = r.textBlocked
            loaded = true
            guard focusNewest else { return }
            if let newest = messages.last?.id {
                try? await Task.sleep(nanoseconds: 700_000_000)   // let the list scroll it into existence
                if requested == channel { focusedMessage = newest }
            } else {
                announce(emptyText)
            }
        } catch {
            guard requested == channel else { return }
            // Not marked loaded: "No messages yet." must not be claimed for a thread that
            // could not be read. The error banner says what happened.
            self.error = error.localizedDescription
        }
    }
    private func send() async {
        sending = true; error = nil; notice = nil
        let target = channel
        let text = draftText(target)
        let sentAttachment = attachment
        // The same text and file again reuse the key; anything changed is a new message.
        let sameness = text + "|" + (sentAttachment?.name ?? "")
        let key = unconfirmed[target].flatMap { $0.text == sameness ? $0.key : nil } ?? UUID().uuidString
        unconfirmed[target] = (text: sameness, key: key)
        var body: [String: Any] = ["client_id": clientId, "channel": target, "body": text, "idem_key": key,
                                   "by_text": target == "client" && byText && textEnabled && textBlocked == nil]
        switch sentAttachment {
        case .document(let id, _): body["document_id"] = id
        case .file(let data, let filename):
            body["file_b64"] = data.base64EncodedString()
            body["filename"] = filename
        case nil: break
        }
        if sentAttachment != nil { announce("Sending.") }
        do {
            let r: PostMessageResponse = try await api.call("post_message", body: body)
            byText = false
            unconfirmed[target] = nil
            if draftText(target) == text { setDraft(target, "") }
            if attachment == sentAttachment { attachment = nil }
            await load()
            if r.delivered == false {
                // Saved, but nobody on that side can read it yet: say so rather than "sent".
                let who = target == "counselor" ? "the counselor has" : "this client has"
                let message = r.warning ?? "Message saved, but \(who) no active portal link, so it cannot be read yet."
                if target == channel { notice = message }
                announce(message)
            } else if let warning = r.warning {
                // Sent, with something to know (a text held until 8 a.m., or one that could not go).
                if target == channel { notice = warning }
                announce("Message sent. " + warning)
            } else {
                announce("Message sent")
            }
        } catch APIError.network {
            // A lost reply can come after the server has saved the message, so "could not reach
            // the server" invited sending it twice: show the thread as it now stands instead.
            if target == channel { await load() }
            self.error = "The connection dropped while sending, so the message may or may not have been sent. Check the conversation for it before sending it again; your text is still in the message box."
        } catch { self.error = error.localizedDescription }
        sending = false
    }
}

/// `delivered` is false when the recipient has no active portal link, and `warning` then says
/// so in words (both absent on older servers). Tolerant: the message is already saved once the
/// server says ok, so an unexpected shape here must not be reported as a failure that invites
/// sending it again.
struct PostMessageResponse: Decodable {
    let delivered: Bool?
    let warning: String?
    private enum CodingKeys: String, CodingKey { case delivered, warning }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        delivered = (try? c.decodeIfPresent(Bool.self, forKey: .delivered))
            ?? (try? c.decodeIfPresent(Int.self, forKey: .delivered)).map { $0 != 0 }
        let text = try? c.decodeIfPresent(String.self, forKey: .warning)
        warning = (text ?? "").isEmpty ? nil : text
    }
}

/// A file sent with a message: one already on the client's record, or a new one.
enum MessageAttachment: Equatable {
    case document(id: Int, title: String)
    case file(data: Data, filename: String)
    var name: String {
        switch self {
        case .document(_, let title): return title
        case .file(_, let filename): return filename
        }
    }
}

/// The client's documents, to send one with a message.
struct MessageDocumentPicker: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let onPick: (ClientDocument) -> Void
    @State private var documents: [ClientDocument] = []
    @State private var loaded = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            List {
                if let error { ErrorBanner(message: error) }
                if !loaded && error == nil {
                    ProgressView("Loading documents…")
                } else if loaded && documents.isEmpty {
                    Text("No documents on file.").foregroundStyle(Color.secondaryText)
                }
                ForEach(documents) { document in
                    Button {
                        onPick(document)
                        dismiss()
                    } label: {
                        VStack(alignment: .leading, spacing: 2) {
                            Text(document.title).font(.body.weight(.medium)).foregroundStyle(.primary)
                            Text("\(document.category) · \(formatDateTime(document.createdAt)) · \(document.sharingText)")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                }
            }
            .navigationTitle("Choose a document")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
            .task { await load() }
        }
    }

    private func load() async {
        do {
            let response: DocumentsResponse = try await api.call("documents", query: ["client_id": String(clientId)])
            documents = response.documents
            loaded = true
            error = nil
        } catch {
            guard !Task.isCancelled else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
