import SwiftUI

/// One note flagged for documentation review (GET doc_review). Tolerant: a null field must
/// not blank the list.
struct DocReviewItem: Decodable, Identifiable {
    var id: Int { noteId }
    let clientId: Int
    let clientName: String
    let noteId: Int
    let subject: String
    let flaggedFor: String
    let createdAt: String
    private enum CodingKeys: String, CodingKey { case clientId, clientName, noteId, subject, flaggedFor, createdAt }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        noteId = try c.decode(Int.self, forKey: .noteId)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        subject = (try? c.decode(String.self, forKey: .subject)) ?? ""
        flaggedFor = (try? c.decode(String.self, forKey: .flaggedFor)) ?? ""
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
    }
}

struct DocReviewResponse: Decodable {
    let items: [DocReviewItem]
    private enum CodingKeys: String, CodingKey { case items }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        items = (try? c.decode([DocReviewItem].self, forKey: .items)) ?? []
    }
}

/// Notes flagged for documentation review. Opening one goes to the client's record, with
/// VoiceOver put on that note (as Alerts opens a client).
struct DocReviewView: View {
    @EnvironmentObject var api: API
    @State private var items: [DocReviewItem] = []
    @State private var loaded = false
    @State private var error: String?
    /// The note being opened: its client's record, with VoiceOver put on the note.
    @State private var opening: DocReviewItem?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if loaded {
                if items.isEmpty { Text("No notes are flagged for review.").foregroundStyle(Color.secondaryText) }
                Section {
                    ForEach(items) { item in row(item) }
                } header: {
                    Text("Flagged notes (\(items.count))").font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                } footer: {
                    Text("Opening a note goes to the client's record, at that note.")
                        .foregroundStyle(Color.secondaryText)
                }
            } else if error == nil {
                ProgressView("Loading flagged notes…")
            }
        }
        .navigationTitle("Documentation review")
        .navigationDestination(isPresented: Binding(get: { opening != nil }, set: { if !$0 { opening = nil } })) {
            if let opening { ClientDetailView(clientId: opening.clientId, focusNoteId: opening.noteId) }
        }
        .refreshable { await load() }
        .task { await load() }
    }

    private func row(_ item: DocReviewItem) -> some View {
        let canOpen = api.can("clients") && item.clientId > 0
        return Button { if canOpen { opening = item } } label: {
            VStack(alignment: .leading, spacing: 3) {
                Text(item.clientName).font(.body.weight(.semibold))
                Text(item.subject)
                Text([item.flaggedFor.isEmpty ? nil : "Flagged for \(item.flaggedFor)",
                      item.createdAt.isEmpty ? nil : formatDateTime(item.createdAt)]
                    .compactMap { $0 }.joined(separator: " · "))
                    .font(.caption).foregroundStyle(Color.secondaryText)
            }
            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!canOpen)
        // One stop: client, note, why it was flagged and when; opening is its action.
        .accessibilityElement(children: VoiceOverRows.children)
        .accessibilityLabel(spoken(item))
        .accessibilityHint(canOpen ? "Opens the client's record at this note." : "")
        .accessibilityAddTraits(canOpen ? .isButton : [])
        .accessibilityActions {
            if canOpen { Button("Open note") { opening = item } }
        }
    }

    private func spoken(_ item: DocReviewItem) -> String {
        var parts = [item.clientName, item.subject]
        if !item.flaggedFor.isEmpty { parts.append("flagged for \(item.flaggedFor)") }
        if !item.createdAt.isEmpty { parts.append(formatDateTime(item.createdAt)) }
        return parts.joined(separator: ", ")
    }

    private func load() async {
        do {
            error = nil
            let r: DocReviewResponse = try await api.call("doc_review")
            items = r.items
            loaded = true
        } catch APIError.server(let message) where message == "Unknown action." {
            showFormError("This server does not offer documentation review in the app yet.", in: &self.error)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}
