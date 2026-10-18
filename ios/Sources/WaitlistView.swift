import SwiftUI

/// One referral on the waitlist (GET waitlist). Tolerant: a null field must not blank the list.
struct WaitlistItem: Decodable, Identifiable {
    var id: Int { referralId }
    let referralId: Int
    let clientName: String
    let status: String
    let receivedAt: String
    let followupDate: String?
    let notes: String?
    private enum CodingKeys: String, CodingKey { case referralId, clientName, status, receivedAt, followupDate, notes }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        referralId = try c.decode(Int.self, forKey: .referralId)
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        receivedAt = (try? c.decode(String.self, forKey: .receivedAt)) ?? ""
        followupDate = try? c.decodeIfPresent(String.self, forKey: .followupDate)
        notes = try? c.decodeIfPresent(String.self, forKey: .notes)
    }
}

struct WaitlistResponse: Decodable {
    let items: [WaitlistItem]
    /// The statuses the server offers, in its order; empty from a server that sends none.
    let statuses: [String]
    private enum CodingKeys: String, CodingKey { case items, statuses }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        items = (try? c.decode([WaitlistItem].self, forKey: .items)) ?? []
        statuses = (try? c.decode([String].self, forKey: .statuses)) ?? []
    }
}

/// waitlist_update's reply: only `ok` is required (the message is said when sent).
struct WaitlistUpdateResponse: Decodable {
    let message: String?
    private enum CodingKeys: String, CodingKey { case message }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        let text = try? c.decodeIfPresent(String.self, forKey: .message)
        message = (text ?? "").isEmpty ? nil : text
    }
}

/// The referral waitlist: referrals waiting for a place, grouped by status, each with its
/// follow-up date and notes. Update (status, follow-up date, notes) is on the Actions rotor.
struct WaitlistView: View {
    @EnvironmentObject var api: API
    @State private var data: WaitlistResponse?
    @State private var error: String?
    @State private var updating: WaitlistItem?
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// After an update moves a row to another group, VoiceOver is put on that referral.
    @AccessibilityFocusState private var focused: Int?

    /// The server's status order first, then any status it did not list.
    private var groups: [(status: String, items: [WaitlistItem])] {
        guard let data else { return [] }
        var order = data.statuses
        for item in data.items where !order.contains(item.status) { order.append(item.status) }
        return order.compactMap { status in
            let items = data.items.filter { $0.status == status }
            return items.isEmpty ? nil : (status, items)
        }
    }

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let data {
                if data.items.isEmpty {
                    Text("No referrals are on the waitlist.").foregroundStyle(Color.secondaryText)
                }
                ForEach(groups, id: \.status) { group in
                    Section {
                        ForEach(group.items) { item in row(item) }
                    } header: {
                        Text("\(group.status.isEmpty ? "No status" : group.status) (\(group.items.count))")
                    }
                }
            } else if error == nil {
                ProgressView("Loading waitlist…")
            }
        }
        .navigationTitle("Referral waitlist")
        .sheet(item: $updating, onDismiss: announceSheetResult) { item in
            WaitlistUpdateSheet(item: item, statuses: data?.statuses ?? []) { message in
                sheetResult = message
                focused = item.referralId
                Task { await load() }
            }
        }
        .refreshable { await load() }
        .task { await load() }
    }

    private func row(_ item: WaitlistItem) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(item.clientName).font(.body.weight(.semibold))
            Text("Received \(formatDateTime(item.receivedAt))").font(.caption).foregroundStyle(Color.secondaryText)
            if let date = item.followupDate, !date.isEmpty {
                Text("Follow up \(formatDate(date))").font(.caption).foregroundStyle(Color.secondaryText)
            }
            if let notes = item.notes, !notes.isEmpty {
                Text(notes).font(.callout)
            }
            Button { updating = item } label: { Label("Update", systemImage: "pencil") }
                .buttonStyle(.bordered)
                .controlSize(.large)
                .padding(.top, 2)
                .accessibilityLabel("Update waitlist entry for \(item.clientName)")
                .accessibilityInputLabels(["Update", "Update \(item.clientName)"])
        }
        .padding(.vertical, 2)
        // VoiceOver: one stop per referral (name, when received, follow-up date, notes);
        // Update is on the Actions rotor. A double-tap opens the update form.
        .accessibilityElement(children: VoiceOverRows.children)
        .accessibilityLabel(spoken(item))
        .accessibilityHint("Double-tap to update the status, follow-up date or notes.")
        .accessibilityFocused($focused, equals: item.referralId)
        .accessibilityAction { updating = item }
        .accessibilityActions {
            Button("Update") { updating = item }
        }
    }

    private func spoken(_ item: WaitlistItem) -> String {
        var parts = [item.clientName, "received \(formatDateTime(item.receivedAt))"]
        if let date = item.followupDate, !date.isEmpty { parts.append("follow up \(formatDate(date))") }
        if let notes = item.notes, !notes.isEmpty { parts.append(notes) }
        return parts.joined(separator: ", ")
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    private func load() async {
        do {
            error = nil
            data = try await api.call("waitlist")
        } catch APIError.server(let message) where message == "Unknown action." {
            showFormError("This server does not offer the referral waitlist in the app yet.", in: &self.error)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

/// Status, follow-up date and notes of one waitlist entry (POST waitlist_update). What is
/// typed is kept on this phone until saved or discarded (DraftStore), as the other forms do.
struct WaitlistUpdateSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let item: WaitlistItem
    let statuses: [String]
    /// Called with what to tell the person once the sheet has closed.
    let onSaved: (String) -> Void

    @State private var status: String
    @State private var followupDate: String
    @State private var notes: String
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false
    @State private var restoredDraft = false

    init(item: WaitlistItem, statuses: [String], onSaved: @escaping (String) -> Void) {
        self.item = item
        self.statuses = statuses
        self.onSaved = onSaved
        _status = State(initialValue: item.status)
        _followupDate = State(initialValue: item.followupDate ?? "")
        _notes = State(initialValue: item.notes ?? "")
    }

    private var draftKey: String { "waitlist-\(item.referralId)" }

    /// The statuses offered: the server's, and the entry's own if it is not among them.
    private var choices: [String] {
        statuses.contains(status) || status.isEmpty ? statuses : [status] + statuses
    }

    private var hasChanges: Bool {
        status != item.status || followupDate != (item.followupDate ?? "") || notes != (item.notes ?? "")
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if restoredDraft {
                    Text("Your unsaved changes from earlier were restored. Cancel, then Discard, removes them.")
                        .font(.callout)
                }
                Section(item.clientName) {
                    Text("Received \(formatDateTime(item.receivedAt))").foregroundStyle(Color.secondaryText)
                    if choices.isEmpty {
                        CaptionedField(label: "Status", text: $status)
                    } else {
                        Picker("Status", selection: $status) {
                            ForEach(choices, id: \.self) { Text($0).tag($0) }
                        }
                    }
                    OptionalDayField(title: "Follow-up date", text: $followupDate)
                    CaptionedField(label: "Notes", text: $notes, lines: 1...6)
                }
            }
            .navigationTitle("Update waitlist entry")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { clearDraft(); dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Saving…" : "Save") { Task { await save() } }
                        .disabled(busy || status.isEmpty)
                        .accessibilityLabel("Save")
                        .accessibilityValue(busy ? "Saving" : "")
                }
            }
            .onChange(of: status) { _ in saveDraft() }
            .onChange(of: followupDate) { _ in saveDraft() }
            .onChange(of: notes) { _ in saveDraft() }
            .onAppear { restoreDraft() }
        }
        .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard the changes to this waitlist entry? Nothing has been saved.",
                             asking: $askingDiscard) { clearDraft(); dismiss() }
    }

    private func saveDraft() {
        guard hasChanges else { clearDraft(); return }
        DraftStore.save(["status": status, "followup_date": followupDate, "notes": notes], key: draftKey, owner: api.userId)
    }

    private func restoreDraft() {
        guard let draft = DraftStore.load(key: draftKey, owner: api.userId) else { return }
        if let s = draft["status"], !s.isEmpty { status = s }
        if let d = draft["followup_date"] { followupDate = d }
        if let n = draft["notes"] { notes = n }
        guard hasChanges else { return }
        restoredDraft = true
        // Said after the sheet has opened: its arrival moves VoiceOver focus.
        announceAfterDismiss("Your unsaved changes to this waitlist entry were restored.")
    }

    private func clearDraft() {
        DraftStore.clear(key: draftKey, owner: api.userId)
    }

    private func save() async {
        busy = true
        error = nil
        var body: [String: Any] = ["referral_id": item.referralId, "status": status]
        if !followupDate.isEmpty { body["followup_date"] = followupDate }
        body["notes"] = notes
        do {
            let r: WaitlistUpdateResponse = try await api.call("waitlist_update", body: body)
            clearDraft()
            // Said by the list once this sheet has gone (closing it moves VoiceOver focus).
            onSaved(r.message ?? "Waitlist entry updated.")
            dismiss()
        } catch {
            busy = false
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
