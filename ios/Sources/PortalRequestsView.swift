import SwiftUI

/// One self-service request a client sent from their portal (the website's Client requests page).
struct PortalRequestItem: Decodable, Identifiable {
    let id: Int
    let clientId: Int
    let clientName: String
    let type: String
    let typeLabel: String
    let details: String?
    let status: String
    let createdAt: String
    let sessionStart: String?
    private enum CodingKeys: String, CodingKey { case id, clientId, clientName, type, typeLabel, details, status, createdAt, sessionStart }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        type = (try? c.decode(String.self, forKey: .type)) ?? "other"
        typeLabel = (try? c.decode(String.self, forKey: .typeLabel)) ?? "Request"
        details = try? c.decodeIfPresent(String.self, forKey: .details)
        status = (try? c.decode(String.self, forKey: .status)) ?? "New"
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        sessionStart = try? c.decodeIfPresent(String.self, forKey: .sessionStart)
    }
}

struct PortalRequestsResponse: Decodable {
    let requests: [PortalRequestItem]
    let statuses: [String]
    let newCount: Int
    let truncated: Bool?
}

struct PortalRequestUpdated: Decodable { let changed: Bool?; let message: String? }

extension PortalRequestItem {
    var spoken: String {
        var parts = [clientName, typeLabel, status, "sent \(formatDate(createdAt))"]
        if let sessionStart { parts.append("session \(formatDateTime(sessionStart))") }
        if let details, !details.isEmpty { parts.append(details) }
        return parts.joined(separator: ". ")
    }
}

/// Requests clients sent from their portal (appointments, reschedules, cancellations, device
/// help), open ones first; each one's status can be changed as on the website.
struct PortalRequestsView: View {
    @EnvironmentObject var api: API
    @State private var data: PortalRequestsResponse?
    @State private var error: String?
    @State private var showAll = false
    @State private var openClient: Int?
    @State private var saving: Set<Int> = []
    @AccessibilityFocusState private var focused: Int?
    private let errorRow = "portalRequestsError"

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden).id(errorRow)
                        .accessibilityFocused($focused, equals: -2)
                }
                if let d = data {
                    Section {
                        Text("\(d.newCount) new. " + (showAll ? "Showing all requests, including done and declined ones." : "Showing open requests (new and in progress)."))
                            .font(.callout)
                        if d.truncated == true { Text("Only the first 300 are listed.").font(.callout) }
                    }
                    if d.requests.isEmpty {
                        Text(showAll ? "No requests." : "No open requests.").foregroundStyle(Color.secondaryText)
                            .accessibilityFocused($focused, equals: -1)
                    }
                    Section {
                        ForEach(d.requests) { r in row(r, statuses: d.statuses) }
                    } header: {
                        Text("Requests (\(d.requests.count))").font(.headline).foregroundStyle(.primary).textCase(nil)
                            .accessibilityAddTraits(.isHeader)
                    }
                } else if error == nil {
                    ProgressView("Loading requests…")
                }
            }
            .onChange(of: error) { message in
                guard message != nil else { return }
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    focused = -2
                }
            }
        }
        .navigationTitle("Client requests")
        .toolbar {
            ToolbarItem(placement: .secondaryAction) {
                Button(showAll ? "Show open requests only" : "Show all requests, including done and declined") {
                    showAll.toggle()
                    Task { await load(announceView: true) }
                }
            }
        }
        .navigationDestination(isPresented: Binding(get: { openClient != nil }, set: { if !$0 { openClient = nil } })) {
            if let id = openClient { ClientDetailView(clientId: id) }
        }
        .refreshable { await load() }
        .task { await load() }
    }

    @ViewBuilder private func row(_ r: PortalRequestItem, statuses: [String]) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(r.clientName).font(.body.weight(.semibold))
                Spacer()
                Text(r.status).font(.caption).foregroundStyle(Color.secondaryText)
            }
            Text("\(r.typeLabel) · sent \(formatDate(r.createdAt))").font(.caption).foregroundStyle(Color.secondaryText)
            if let s = r.sessionStart { Text("Session \(formatDateTime(s))").font(.caption).foregroundStyle(Color.secondaryText) }
            if let details = r.details, !details.isEmpty { Text(details).font(.callout) }
            Menu {
                ForEach(statuses, id: \.self) { s in
                    Button { Task { await setStatus(r, s) } } label: {
                        if s == r.status { Label(s, systemImage: "checkmark") } else { Text(s) }
                    }
                }
            } label: {
                Label("Status: \(r.status)", systemImage: "chevron.up.chevron.down")
                    .frame(minHeight: 44, alignment: .leading)
                    .contentShape(Rectangle())
            }
            .disabled(saving.contains(r.id))
        }
        .padding(.vertical, 2)
        // One VoiceOver stop per request; each status and the client record are on the Actions rotor.
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(r.spoken)
        .accessibilityFocused($focused, equals: r.id)
        .accessibilityAction { announceRowActions() }
        .accessibilityActions {
            ForEach(statuses.filter { $0 != r.status }, id: \.self) { s in
                Button("Mark \(s)") { Task { await setStatus(r, s) } }
            }
            if api.can("clients") { Button("Open client record") { openClient = r.clientId } }
        }
    }

    private func setStatus(_ r: PortalRequestItem, _ to: String) async {
        guard to != r.status, !saving.contains(r.id) else { return }
        let index = data?.requests.firstIndex(where: { $0.id == r.id }) ?? 0
        saving.insert(r.id)
        defer { saving.remove(r.id) }
        error = nil
        do {
            let result: PortalRequestUpdated = try await api.call("portal_request_update", body: ["id": r.id, "to": to])
            await load()
            if let list = data?.requests {
                if list.contains(where: { $0.id == r.id }) { focused = r.id }
                else if !list.isEmpty { focused = list[min(index, list.count - 1)].id }
                else { focused = -1 }
            }
            announceAfterDismiss(result.message ?? "Request set to \(to).")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }

    private func load(announceView: Bool = false) async {
        let wantsAll = showAll
        do {
            error = nil
            let r: PortalRequestsResponse = try await api.call("portal_requests", query: wantsAll ? ["all": "1"] : [:])
            guard wantsAll == showAll else { return }
            data = r
            if announceView { announce(wantsAll ? "Showing all requests, including done and declined." : "Showing open requests only.") }
        } catch {
            guard wantsAll == showAll else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
