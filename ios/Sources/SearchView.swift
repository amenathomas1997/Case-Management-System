import SwiftUI

struct SearchGroup: Decodable, Identifiable {
    var id: String { group }
    let group: String
    let items: [SearchHit]
}

struct SearchHit: Decodable, Identifiable {
    let id: Int
    let title: String
    let subtitle: String?
    let clientId: Int?
    /// What `id` is: "client", "note", "referral" or "track" (nil from older servers, whose
    /// hits all open the client).
    let kind: String?
}

struct SearchResponse: Decodable { let results: [SearchGroup] }

struct SearchView: View {
    @EnvironmentObject var api: API
    @State private var query: String
    @State private var groups: [SearchGroup] = []
    @State private var searched = false
    @State private var error: String?
    @State private var requestID = UUID()

    /// `initialQuery` lets another screen (the voice assistant's "Search for …" link) open
    /// the search already filled in and run.
    init(initialQuery: String = "") {
        _query = State(initialValue: initialQuery)
    }

    var body: some View {
        List {
            if let error { ErrorBanner(message: error) }
            if searched && groups.isEmpty && error == nil {
                Text("No results for “\(query)”.").foregroundStyle(Color.secondaryText)
            }
            ForEach(groups) { group in
                Section(group.group) {
                    ForEach(group.items) { hit in
                        // A referral has no client yet, and a track opens at the track itself.
                        if hit.kind == "referral" {
                            NavigationLink { ReferralDetailView(referralId: hit.id) } label: {
                                hitRow(hit)
                            }
                        } else if hit.kind == "track", let clientId = hit.clientId {
                            NavigationLink { SearchTrackView(trackId: hit.id, clientId: clientId) } label: {
                                hitRow(hit)
                            }
                        } else if let clientId = hit.clientId {
                            NavigationLink(value: clientId) {
                                hitRow(hit)
                            }
                        } else {
                            hitRow(hit)
                        }
                    }
                }
            }
        }
        .navigationTitle("Search")
        .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always),
                    prompt: "Clients, notes, referrals, tracks")
        .onSubmit(of: .search) { Task { await run(submitted: true) } }
        .task { if !searched && groups.isEmpty && query.count >= 2 { await run() } }
        .onChange(of: query) { newValue in
            let typed = UUID()
            requestID = typed
            groups = []
            searched = false
            error = nil
            // Searches once typing pauses, so results (and their spoken count) don't arrive
            // for every keystroke.
            if newValue.count >= 3 {
                Task {
                    try? await Task.sleep(nanoseconds: 350_000_000)
                    guard requestID == typed, query == newValue else { return }
                    await run()
                }
            }
        }
    }

    private func hitRow(_ hit: SearchHit) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(hit.title)
            if let subtitle = hit.subtitle, !subtitle.isEmpty {
                Text(subtitle).font(.caption).foregroundStyle(Color.secondaryText)
            }
        }
    }

    /// `submitted`: the person pressed Search, so a query too short to send is said, not
    /// silently ignored (typing pauses and the first load stay quiet).
    private func run(submitted: Bool = false) async {
        let q = query
        guard q.count >= 2 else {
            if submitted { showFormError("Enter at least 2 characters to search.", in: &error) }
            return
        }
        let currentRequest = UUID()
        requestID = currentRequest
        error = nil
        do {
            let response: SearchResponse = try await api.call("search", query: ["q": q])
            guard q == query, requestID == currentRequest else { return }
            groups = response.results
            searched = true
            // Focus stays in the search field, so the outcome is spoken.
            let count = response.results.reduce(0) { $0 + $1.items.count }
            announce(count == 0 ? "No results for \(q)." : "\(count) result\(count == 1 ? "" : "s") for \(q).")
        } catch {
            guard q == query, requestID == currentRequest else { return }
            groups = []
            searched = false
            self.error = error.localizedDescription
        }
    }
}

/// Opens a track found by search. A track comes with its client's record, so that record is
/// loaded and the track picked out of it.
private struct SearchTrackView: View {
    @EnvironmentObject var api: API
    let trackId: Int
    let clientId: Int
    @State private var detail: ClientDetail?
    @State private var error: String?

    var body: some View {
        Group {
            if let detail, let track = detail.tracks.first(where: { $0.id == trackId }) {
                TrackDetailView(track: track, clientName: detail.client.name)
            } else if let error {
                List { ErrorBanner(message: error) }
                    .navigationTitle("Track")
            } else {
                ProgressView("Loading track…")
            }
        }
        .task { if detail == nil && error == nil { await load() } }
    }

    private func load() async {
        do {
            let d: ClientDetail = try await api.call("client", query: ["id": String(clientId)])
            if d.tracks.contains(where: { $0.id == trackId }) {
                detail = d
            } else {
                error = "That track was not found. It may have been removed."
            }
        } catch {
            self.error = error.localizedDescription
        }
    }
}
