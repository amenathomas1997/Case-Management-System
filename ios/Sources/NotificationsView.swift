import SwiftUI

/// One in-app alert (the website's Alerts page).
struct AlertItem: Decodable, Identifiable {
    let id: Int
    let message: String
    /// The website page the alert points to, relative to the site (client.php?id=4…); nil when none.
    let link: String?
    let createdAt: String
    let unread: Bool
    private enum CodingKeys: String, CodingKey { case id, message, link, createdAt, unread }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        message = (try? c.decode(String.self, forKey: .message)) ?? ""
        link = try? c.decodeIfPresent(String.self, forKey: .link)
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        unread = (try? c.decode(Bool.self, forKey: .unread)) ?? false
    }
}

struct AlertsResponse: Decodable { let notifications: [AlertItem]; let unread: Int }
struct AlertCount: Decodable { let unread: Int }
struct AlertsMarked: Decodable { let marked: Int; let unread: Int; let message: String? }

/// Where an alert's link leads in the app: a matching screen, or the website page.
enum AlertDestination: Hashable {
    case client(Int), referral(Int), tasks, portalRequests, incidents, messages, counselors

    /// The app screen for a website link, when the account may open it here.
    @MainActor static func from(_ link: String, api: API) -> AlertDestination? {
        guard let parts = URLComponents(string: link) else { return nil }
        let page = (parts.path as NSString).lastPathComponent
        let query = Dictionary((parts.queryItems ?? []).map { ($0.name, $0.value ?? "") }, uniquingKeysWith: { a, _ in a })
        switch page {
        case "client.php": if api.can("clients"), let id = Int(query["id"] ?? "") { return .client(id) }
        case "referrals.php": if api.can("referrals"), let id = Int(query["view"] ?? "") { return .referral(id) }
        case "tasks.php": return .tasks
        case "portal_requests.php": if api.can("clients") { return .portalRequests }
        case "incidents.php": if api.can("clients") { return .incidents }
        case "messages.php": if api.can("clients") { return .messages }
        case "counselors.php": if api.can("admin") { return .counselors }
        default: break
        }
        return nil
    }

    @ViewBuilder var screen: some View {
        switch self {
        case .client(let id): ClientDetailView(clientId: id)
        case .referral(let id): ReferralDetailView(referralId: id)
        case .tasks: TasksView()
        case .portalRequests: PortalRequestsView()
        case .incidents: IncidentsView()
        case .messages: MessagesView()
        case .counselors: CounselorsView()
        }
    }
}

/// The website's Alerts page: things that happened while the person was away, unread ones
/// first. Opening one goes to the matching screen in the app (or the website page in the
/// in-app browser) and marks it read.
struct NotificationsView: View {
    @EnvironmentObject var api: API
    /// Told the unread count after every load and change (the dashboard's badge).
    var onUnreadChange: (Int) -> Void = { _ in }
    @State private var items: [AlertItem] = []
    @State private var unread = 0
    @State private var loaded = false
    @State private var error: String?
    @State private var busy = false
    @State private var destination: AlertDestination?
    @State private var webPage: WebPage?
    @AccessibilityFocusState private var errorFocused: Bool

    private var shownUnread: [AlertItem] { items.filter(\.unread) }

    var body: some View {
        List {
            if let error {
                ErrorBanner(message: error).listRowSeparator(.hidden).accessibilityFocused($errorFocused)
            }
            if loaded {
                Section {
                    if !shownUnread.isEmpty {
                        Button { Task { await markRead(shownUnread.map(\.id)) } } label: {
                            Label(unread > shownUnread.count ? "Mark these \(shownUnread.count) as read" : "Mark all as read",
                                  systemImage: "checkmark.circle")
                                .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                        }
                        .disabled(busy)
                    }
                    if unread > shownUnread.count {
                        Text("Showing the newest \(shownUnread.count) of \(unread) unread alerts. Mark these as read to list the older ones.")
                            .font(.callout)
                    }
                } footer: {
                    Text("Things that happened while you were away. Emails still go out as usual; this is the in-app copy.")
                        .foregroundStyle(Color.secondaryText)
                }
                if items.isEmpty { Text("Nothing yet.").foregroundStyle(Color.secondaryText) }
                Section {
                    ForEach(items) { item in row(item) }
                } header: {
                    Text(unread > 0 ? "Alerts (\(unread) new)" : "Alerts").font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                }
            } else if error == nil {
                ProgressView("Loading alerts…")
            }
        }
        .navigationTitle("Alerts")
        .navigationDestination(isPresented: Binding(get: { destination != nil }, set: { if !$0 { destination = nil } })) {
            if let destination { destination.screen }
        }
        .sheet(item: $webPage) { page in
            InAppWebView(url: page.url) { webPage = nil }.ignoresSafeArea()
        }
        .refreshable { await load() }
        .task { await load() }
    }

    @ViewBuilder private func row(_ item: AlertItem) -> some View {
        let target = item.link.flatMap { AlertDestination.from($0, api: api) }
        let webURL = target == nil ? item.link.flatMap(workflowURL) : nil
        Button { open(item, target: target, webURL: webURL) } label: {
            VStack(alignment: .leading, spacing: 3) {
                HStack(alignment: .firstTextBaseline) {
                    Text(item.message).font(item.unread ? .body.weight(.semibold) : .body)
                    Spacer()
                    if item.unread {
                        Text("New").font(.caption2.weight(.semibold)).foregroundStyle(.white)
                            .padding(.horizontal, 6).padding(.vertical, 2)
                            .background(Color.brandFill).clipShape(Capsule())
                    }
                }
                Text(formatDateTime(item.createdAt)).font(.caption).foregroundStyle(Color.secondaryText)
            }
            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(target == nil && webURL == nil)
        // One stop: new or read, the message and when; opening and marking read are its actions.
        .accessibilityElement(children: VoiceOverRows.children)
        .accessibilityLabel("\(item.unread ? "New. " : "")\(item.message). \(formatDateTime(item.createdAt))")
        .accessibilityHint(target != nil ? "Opens the matching screen." : (webURL != nil ? "Opens the website page in the app." : ""))
        .accessibilityAddTraits(target != nil || webURL != nil ? .isButton : [])
        .accessibilityActions {
            if target != nil || webURL != nil { Button("Open") { open(item, target: target, webURL: webURL) } }
            if item.unread { Button("Mark as read") { Task { await markRead([item.id]) } } }
        }
    }

    private func open(_ item: AlertItem, target: AlertDestination?, webURL: URL?) {
        if item.unread { Task { await markRead([item.id], quietly: true) } }
        if let target { destination = target }
        else if let webURL { webPage = WebPage(url: webURL) }
    }

    private func markRead(_ ids: [Int], quietly: Bool = false) async {
        guard !ids.isEmpty else { return }
        busy = true
        defer { busy = false }
        do {
            let r: AlertsMarked = try await api.call("mark_notifications_read", body: ["ids": ids.map(String.init).joined(separator: ",")])
            await load()
            if !quietly { announce(r.message ?? "Marked as read.") }
        } catch {
            if !quietly { showFormError(error.localizedDescription, in: &self.error); errorFocused = true }
        }
    }

    private func load() async {
        do {
            error = nil
            let r: AlertsResponse = try await api.call("notifications")
            items = r.notifications
            unread = r.unread
            loaded = true
            onUnreadChange(r.unread)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}
