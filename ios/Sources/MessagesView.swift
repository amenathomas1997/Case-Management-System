import SwiftUI

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
    /// Unsent replies by client and channel, kept here (in memory only, for as long as the
    /// Messages screen is open) so going back from a thread does not lose what was typed.
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
        } catch { self.error = error.localizedDescription }
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
            self.error = error.localizedDescription
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
        VStack(spacing: 0) {
            Picker("Channel", selection: $channel) {
                Text("Client").tag("client")
                Text("Counselor").tag("counselor")
            }
            .pickerStyle(.segmented).padding()
            .onChange(of: channel) { _ in
                // Never leave the other channel's messages under the new selection.
                messages = []; loaded = false; notice = nil
                Task { await load(focusNewest: true) }
            }

            if let error { ErrorBanner(message: error).padding(.horizontal) }
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

            HStack(spacing: 8) {
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
                .disabled(sending || draft.wrappedValue.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }.padding()
        }
        .navigationTitle(clientName)
        .navigationBarTitleDisplayMode(.inline)
        .task { await load(focusNewest: true) }
    }

    private func bubble(_ m: ThreadMessage) -> some View {
        let mine = m.sender == "staff"
        return HStack {
            if mine { Spacer(minLength: 40) }
            VStack(alignment: mine ? .trailing : .leading, spacing: 2) {
                Text(m.body).font(.callout)
                    .foregroundStyle(mine ? .white : .primary)
                    .padding(10)
                    .background(mine ? Color.brandFill : Color(.secondarySystemBackground),
                                in: RoundedRectangle(cornerRadius: 12))
                // Caption size in the 7:1 secondary-text colour (caption2 in system secondary was about 3.4:1).
                Text("\(senderLabel(m)) · \(formatDateTime(m.createdAt))")
                    .font(.caption).foregroundStyle(Color.secondaryText)
            }
            if !mine { Spacer(minLength: 40) }
        }
        .id(m.id)
        // One element per message, spoken sender first.
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(senderLabel(m)): \(m.body)")
        .accessibilityValue(formatDateTime(m.createdAt))
        .accessibilityFocused($focusedMessage, equals: m.id)
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
        do {
            let r: PostMessageResponse = try await api.call("post_message",
                body: ["client_id": clientId, "channel": target, "body": text])
            if draftText(target) == text { setDraft(target, "") }
            await load()
            if r.delivered == false {
                // Saved, but nobody on that side can read it yet: say so rather than "sent".
                let who = target == "counselor" ? "the counselor has" : "this client has"
                let message = r.warning ?? "Message saved, but \(who) no active portal link, so it cannot be read yet."
                if target == channel { notice = message }
                announce(message)
            } else {
                announce("Message sent")
            }
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
