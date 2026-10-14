import SwiftUI

struct CounselorRow: Decodable, Identifiable {
    var id: String { email }
    let email: String
    let name: String
    let agency: String?
    let clients: Int
    /// A link was sent and still opens (false once it has expired).
    let portalActive: Bool
    /// A link was sent but has passed its expiry date: the counselor needs a new one.
    let portalExpired: Bool
    let portalExpiresAt: String?
    let lastUsedAt: String?
    /// The counselor's name, or their email when no name is on file.
    var displayName: String { name.isEmpty ? email : name }
    private enum CodingKeys: String, CodingKey { case email, name, agency, clients, portalActive, portalExpired, portalExpiresAt, lastUsedAt }
    // Tolerant decode: a counselor email with no name on any client (the column is nullable)
    // must not blank the whole Counselors screen. Only the email (the row's identity) is required.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        email = try c.decode(String.self, forKey: .email)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        agency = try? c.decodeIfPresent(String.self, forKey: .agency)
        clients = (try? c.decode(Int.self, forKey: .clients)) ?? 0
        portalActive = (try? c.decode(Bool.self, forKey: .portalActive)) ?? false
        portalExpired = (try? c.decode(Bool.self, forKey: .portalExpired)) ?? false
        portalExpiresAt = try? c.decodeIfPresent(String.self, forKey: .portalExpiresAt)
        lastUsedAt = try? c.decodeIfPresent(String.self, forKey: .lastUsedAt)
    }
}

struct CounselorsResponse: Decodable { let counselors: [CounselorRow] }

struct CounselorsView: View {
    @EnvironmentObject var api: API
    @State private var counselors: [CounselorRow] = []
    /// Set once the list has loaded, so "No counselors on file yet." is never claimed before then.
    @State private var loaded = false
    @State private var error: String?
    @State private var confirmation: String?
    @State private var pendingSend: CounselorRow?
    @State private var pendingRevoke: CounselorRow?
    /// Links being turned off, so a second tap cannot send it again.
    @State private var revoking: Set<String> = []
    /// Where VoiceOver is put after a link is turned off: the Turn off link button it was on is
    /// removed by the reload, so focus is moved to the confirmation (which speaks it).
    @AccessibilityFocusState private var confirmationFocused: Bool
    /// Where VoiceOver is put after a send or revoke fails: the banner is the list's first
    /// row, which a failure on a row further down would otherwise leave off screen.
    @AccessibilityFocusState private var errorFocused: Bool
    /// Bumped to scroll the confirmation (the list's first rows) into view before focusing it.
    @State private var revealConfirmation = 0

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden)
                        .id("counselorsError")
                        .accessibilityFocused($errorFocused)
                }
                if let confirmation {
                    // Primary text (teal text on white is about 6.4:1), with an icon so the success is
                    // not shown by colour; the icon is hidden, the words carry it for VoiceOver.
                    Label { Text(confirmation) } icon: { Image(systemName: "checkmark.circle.fill").foregroundStyle(Color.brandTeal).accessibilityHidden(true) }
                        .font(.callout.weight(.medium))
                        .listRowSeparator(.hidden)
                        .id("counselorConfirmation")
                        .accessibilityFocused($confirmationFocused)
                }
                if !loaded && error == nil {
                    ProgressView("Loading counselors…")
                } else if loaded && counselors.isEmpty && error == nil {
                    Text("No counselors on file yet.")
                }
                ForEach(counselors) { counselor in
                    VStack(alignment: .leading, spacing: 4) {
                        HStack {
                            Text(counselor.displayName).font(.body.weight(.semibold))
                            Spacer()
                            // Said in words; the tint only repeats it. Primary text on the light tint
                            // is at least 11:1 in both appearances (grey or teal text was 2.9–5.1:1).
                            Text(counselor.portalActive ? "Portal active" : (counselor.portalExpired ? "Portal expired" : "Not invited"))
                                .font(.caption.weight(.semibold))
                                .padding(.horizontal, 8).padding(.vertical, 3)
                                .background((counselor.portalActive ? Color.brandTeal : Color.secondary).opacity(0.15), in: Capsule())
                                .foregroundStyle(.primary)
                        }
                        Text("\(counselor.agency ?? "—") · \(counselor.clients) client\(counselor.clients == 1 ? "" : "s")")
                            .font(.callout)
                        Text(counselor.email).font(.caption)
                        if counselor.portalActive, let expires = counselor.portalExpiresAt {
                            Text("Link expires \(formatDate(expires))").font(.caption)
                        } else if counselor.portalExpired {
                            Text("Link expired\(counselor.portalExpiresAt.map { " \(formatDate($0))" } ?? ""); the counselor needs a new link")
                                .font(.caption)
                        }
                        if let used = counselor.lastUsedAt {
                            Text("Last opened \(formatDateTime(used))").font(.caption)
                        }
                        let action = counselor.portalActive ? "Resend / reset link" : (counselor.portalExpired ? "Send a new link" : "Send portal link")
                        // Large controls (at least 44 points tall), spaced apart so a slip does not
                        // land on the neighbouring button.
                        HStack(spacing: 16) {
                            Button {
                                pendingSend = counselor
                            } label: {
                                Label(action, systemImage: "envelope")
                            }
                            .buttonStyle(.bordered)
                            .controlSize(.large)
                            // The visible words first (Voice Control), then whose link it is.
                            .accessibilityLabel("\(action) for \(counselor.displayName)")
                            if counselor.portalActive {
                                Button {
                                    pendingRevoke = counselor
                                } label: {
                                    Label("Turn off link", systemImage: "xmark.circle")
                                }
                                .buttonStyle(.bordered)
                                .controlSize(.large)
                                .disabled(revoking.contains(counselor.email))
                                // As on the website: the visible words, then whose link it is.
                                .accessibilityLabel("Turn off link for \(counselor.displayName)")
                            }
                        }
                        .padding(.top, 4)
                    }
                    .padding(.vertical, 4)
                }
            }
            .onChange(of: revealConfirmation) { _ in proxy.scrollTo("counselorConfirmation", anchor: .top) }
            // As EquipmentView: scroll the banner into view once it exists (a List never draws
            // an off-screen row, so it would be neither seen nor heard), then put VoiceOver on it.
            .onChange(of: error) { message in
                guard message != nil else { return }
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo("counselorsError", anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    errorFocused = true
                }
            }
        }
        .navigationTitle("Counselor portal")
        .refreshable { confirmation = nil; await load() }
        .task { await load() }
        .confirmationDialog(
            "Email a portal link to \(pendingSend?.displayName ?? "")? Any previous link stops working.",
            isPresented: Binding(get: { pendingSend != nil }, set: { if !$0 { pendingSend = nil } }),
            titleVisibility: .visible
        ) {
            Button("Send link") {
                if let counselor = pendingSend { Task { await send(counselor) } }
            }
            Button("Cancel", role: .cancel) { pendingSend = nil }
        }
        .confirmationDialog(
            "Turn off the portal link for \(pendingRevoke?.displayName ?? "")? It stops working at once, and they need a new link to get back in.",
            isPresented: Binding(get: { pendingRevoke != nil }, set: { if !$0 { pendingRevoke = nil } }),
            titleVisibility: .visible
        ) {
            Button("Turn off link", role: .destructive) {
                if let counselor = pendingRevoke { Task { await revoke(counselor) } }
                pendingRevoke = nil
            }
            Button("Keep link", role: .cancel) { pendingRevoke = nil }
        }
    }

    private func load() async {
        do {
            error = nil
            let response: CounselorsResponse = try await api.call("counselors")
            counselors = response.counselors
            loaded = true
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func send(_ counselor: CounselorRow) async {
        do {
            // Only the latest outcome is shown: an earlier success must not sit above this error.
            error = nil; confirmation = nil
            let _: OKResponse = try await api.call("send_vrc_portal", body: [
                "email": counselor.email, "name": counselor.name,
            ])
            confirmation = "Portal link emailed to \(counselor.displayName)."
            announce(confirmation!)
            await load()
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
        pendingSend = nil
    }

    private func revoke(_ counselor: CounselorRow) async {
        guard !revoking.contains(counselor.email) else { return }
        revoking.insert(counselor.email)
        defer { revoking.remove(counselor.email) }
        do {
            error = nil; confirmation = nil
            let _: OKResponse = try await api.call("revoke_vrc_portal", body: ["email": counselor.email])
            // Reload first (it removes the Turn off link button VoiceOver was on), then show the
            // confirmation and put VoiceOver on it once the list has settled; an announcement
            // made before that focus move would be cut off by it.
            await load()
            confirmation = "Portal link turned off for \(counselor.displayName). Send a new link when they need access again."
            revealConfirmation += 1
            Task { @MainActor in
                try? await Task.sleep(nanoseconds: 500_000_000)
                confirmationFocused = true
            }
        } catch APIError.server(let message) where message == "Unknown action." {
            // A server that predates this action: say where it can be done instead.
            showFormError("This server cannot turn off a link from the app yet. Use Turn off link on the website's Counselor portal page.", in: &self.error)
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
