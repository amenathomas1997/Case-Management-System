import SwiftUI

/// Device follow-ups / AT abandonment tracking (web v2.45 parity).
struct FollowupsView: View {
    @EnvironmentObject var api: API
    @State private var data: FollowupsResponse?
    @State private var error: String?
    /// Set while the list is the copy kept on this phone (the server could not be reached):
    /// when it was saved. Snooze and Skip wait for a connection; Record still works, since a
    /// recorded check is kept on this phone and uploads later.
    @State private var offlineSince: Date?
    @State private var recording: Followup?
    @State private var skipping: Followup?
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// A follow-up recorded without its task (the server's warning): shown until the next
    /// refresh as well as announced, since it asks the user to add the task themselves.
    @State private var notice: String?
    /// After Snooze or Skip takes a row out of Due now, VoiceOver is put on that section's
    /// heading rather than left wherever it lands.
    @AccessibilityFocusState private var dueHeaderFocused: Bool
    /// Follow-ups with a Snooze or Skip being sent: a second tap would move a snoozed check out
    /// another two weeks, so their buttons are off until the reply comes.
    @State private var acting: Set<Int> = []

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let notice {
                // Said in words and with an icon, not only by colour.
                Label { Text(notice) } icon: { Image(systemName: "exclamationmark.triangle.fill").accessibilityHidden(true) }
                    .font(.callout.weight(.medium))
                    .listRowSeparator(.hidden)
            }
            if let offlineSince { OfflineNotice(savedAt: offlineSince).listRowSeparator(.hidden) }

            if let d = data {
                Section {
                    HStack {
                        statPill(d.retention != nil ? "\(d.retention!)%" : "—", "Still in use")
                        statPill("\(d.abandoned)", "No longer used")
                        statPill("\(d.completed)", "Checks done")
                    }
                    // The three figures are one VoiceOver stop.
                    .accessibilityElement(children: .combine)
                } footer: {
                    Text("Post-provision checks that loaned assistive technology is still being used. Scheduled automatically after a device goes out.")
                }

                Section {
                    if d.due.isEmpty { Text("Nothing due.").foregroundStyle(Color.secondaryText) }
                    ForEach(d.due) { f in followupRow(f, due: true) }
                } header: {
                    Text("Due now (\(d.due.count))").accessibilityFocused($dueHeaderFocused)
                }
                if !d.upcoming.isEmpty {
                    Section("Upcoming") { ForEach(d.upcoming) { f in followupRow(f, due: false) } }
                }
                if !d.recent.isEmpty {
                    Section("Recently recorded") {
                        ForEach(d.recent) { f in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(f.equipmentName).font(.body.weight(.semibold))
                                Text("\(f.clientName) · \(f.status)\(f.inUse != nil ? (f.inUse == 1 ? " · in use" : " · not in use") : "")")
                                    .font(.caption).foregroundStyle(Color.secondaryText)
                            }.padding(.vertical, 1)
                            .accessibilityElement(children: .combine)
                        }
                    }
                }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle("Device follow-ups")
        .sheet(item: $recording, onDismiss: announceSheetResult) { f in
            RecordFollowupSheet(followup: f) { message in
                // The server's warning (recorded without its task), or that it was kept on this
                // phone to upload later: shown until the next refresh as well as said.
                notice = message
                sheetResult = message ?? "Follow-up recorded."
                Task { await load() }
            }
        }
        // Skipping closes the check for good, so it takes a second, deliberate step.
        .confirmationDialog("Skip this check for \(skipping?.equipmentName ?? "this device")? It will not be asked again.",
                            isPresented: Binding(get: { skipping != nil }, set: { if !$0 { skipping = nil } }),
                            titleVisibility: .visible, presenting: skipping) { f in
            Button("Skip this check", role: .destructive) { Task { await act(f, "skip") } }
            Button("Cancel", role: .cancel) {}
        }
        .refreshable { notice = nil; await load() }
        .task { await load() }
    }

    private func followupRow(_ f: Followup, due: Bool) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(f.equipmentName).font(.body.weight(.semibold))
            Text("\(f.clientName) · \(f.intervalDays)-day check").font(.caption).foregroundStyle(Color.secondaryText)
            // Overdue is said in words and with an icon, not only shown in red; the red is
            // Color.alertRed (system red caption text was 3.6:1).
            HStack(alignment: .firstTextBaseline, spacing: 4) {
                if isOverdue(f.dueDate) {
                    Image(systemName: "exclamationmark.triangle.fill").accessibilityHidden(true)
                }
                Text(dueLabel(f.dueDate))
                    .fontWeight(isOverdue(f.dueDate) ? .semibold : .regular)
            }
            .font(.caption)
            .foregroundStyle(isOverdue(f.dueDate) ? Color.alertRed : Color.secondaryText)
            if due {
                // Full-size buttons (44 points or more) side by side, or one under another when
                // they do not fit, as at the larger text sizes.
                ViewThatFits(in: .horizontal) {
                    HStack(spacing: 8) { dueButtons(f) }
                    VStack(alignment: .leading, spacing: 8) { dueButtons(f) }
                }
                .padding(.top, 2)
            }
        }.padding(.vertical, 1)
        // VoiceOver: one stop per check (device, client, interval, when due); Record, Snooze 2
        // weeks and Skip (confirmed first, as the button is) are on the Actions rotor.
        .accessibilityElement(children: VoiceOverRows.children)
        .accessibilityLabel("\(f.equipmentName), \(f.clientName), \(f.intervalDays)-day check, \(dueLabel(f.dueDate))")
        // Double-tap records the check (it opens a form); never a snooze or skip by accident.
        .accessibilityAction { if due && !acting.contains(f.id) { recording = f } else { announceRowActions() } }
        .accessibilityActions {
            if due && !acting.contains(f.id) {
                Button("Record") { recording = f }
                if offlineSince == nil {
                    Button("Snooze 2 weeks") { Task { await act(f, "snooze") } }
                    Button("Skip") { skipping = f }
                }
            }
        }
    }

    @ViewBuilder private func dueButtons(_ f: Followup) -> some View {
        // Each button names its check, since every due row has the same three; the
        // visible words stay a Voice Control name.
        let check = "\(f.equipmentName) check for \(f.clientName)"
        let sending = acting.contains(f.id)
        Button("Record") { recording = f }.buttonStyle(.borderedProminent).onBrandLabel().controlSize(.large)
            .disabled(sending)
            .accessibilityLabel("Record \(check)")
            .accessibilityInputLabels(["Record", "Record \(check)"])
        Button("Snooze 2 weeks") { Task { await act(f, "snooze") } }.buttonStyle(.bordered).controlSize(.large)
            .disabled(sending || offlineSince != nil)
            .accessibilityLabel("Snooze \(check) two weeks")
            .accessibilityInputLabels(["Snooze", "Snooze 2 weeks", "Snooze \(check) two weeks"])
        Button("Skip") { skipping = f }.buttonStyle(.bordered).controlSize(.large)
            .disabled(sending || offlineSince != nil)
            .accessibilityLabel("Skip \(check)")
            .accessibilityInputLabels(["Skip", "Skip \(check)"])
    }

    private func announceSheetResult() {
        if let message = sheetResult {
            dueHeaderFocused = true
            announceAfterDismiss(message)
        }
        sheetResult = nil
    }

    private func statPill(_ n: String, _ label: String) -> some View {
        VStack(spacing: 2) {
            Text(n).font(.title3.bold().monospacedDigit()).foregroundStyle(.primary)
            Text(label).font(.caption2).foregroundStyle(Color.secondaryText).multilineTextAlignment(.center)
        }.frame(maxWidth: .infinity)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(label): \(n == "—" ? "no data yet" : n)")
    }

    private func dueLabel(_ d: String) -> String {
        isOverdue(d) ? "Overdue — due \(formatDate(d))" : "Due \(formatDate(d))"
    }
    private func isOverdue(_ d: String) -> Bool {
        guard let date = APIDate.parseDay(d) else { return false }
        return date < Calendar.current.startOfDay(for: Date())
    }

    private func act(_ f: Followup, _ op: String) async {
        guard !acting.contains(f.id) else { return }
        acting.insert(f.id)
        defer { acting.remove(f.id) }
        do {
            let _: OKResponse = try await api.call("save_followup", body: ["id": f.id, "op": op])
            await load()
            // The row has left Due now: put VoiceOver on that heading, then say what happened,
            // after the focus move so it is not cut off.
            dueHeaderFocused = true
            announceAfterDismiss(op == "skip" ? "Follow-up skipped." : "Follow-up moved out two weeks.")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }

    private func load() async {
        do {
            error = nil
            // With no signal, the last list the server sent is shown instead.
            let (response, savedAt): (FollowupsResponse, Date?) = try await api.callCached("followups", cacheKey: "followups")
            data = response
            offlineSince = savedAt
            // The 8:00 summary follows the count as last loaded from the server.
            if savedAt == nil { LocalNotifications.shared.scheduleFollowups(dueCount: response.due.count) }
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct RecordFollowupSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let followup: Followup
    /// Called with the server's warning when the follow-up was recorded but its task could not
    /// be created, or nil.
    let onSaved: (String?) -> Void

    /// "yes", "no" or "" (Unknown); nil until the user answers. Nothing is chosen for them: an
    /// untouched answer was saved as "in use" for good and counted in the retention figure.
    @State private var inUse: String?
    @State private var satisfaction = 0
    @State private var barriers = ""
    @State private var notes = ""
    @State private var busy = false
    @State private var error: String?
    @State private var confirmingDiscard = false
    /// "No" also adds a case note and a task, and a recorded follow-up cannot be changed, so
    /// that answer is confirmed first (as on the website).
    @State private var confirmingNotInUse = false
    /// The answers typed earlier were put back from this phone's draft when the sheet opened.
    @State private var restoredDraft = false

    /// The follow-up's unsaved answers are kept on this phone as they are typed (DraftStore), so
    /// a forced sign-in or the app being closed does not lose them. Cleared once it is recorded
    /// or discarded.
    private var draftKey: String { "followup-\(followup.id)" }

    private func saveDraft() {
        var fields = ["barriers": barriers, "notes": notes]
        // The choices travel only with text: a draft with nothing typed is removed.
        if hasTypedText {
            fields["in_use"] = inUse ?? "unanswered"
            fields["satisfaction"] = String(satisfaction)
        }
        DraftStore.save(fields, key: draftKey, owner: api.userId)
    }

    private func restoreDraft() {
        guard !hasTypedText, let draft = DraftStore.load(key: draftKey, owner: api.userId) else { return }
        let savedBarriers = draft["barriers"] ?? ""
        let savedNotes = draft["notes"] ?? ""
        guard !(savedBarriers + savedNotes).trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return }
        barriers = savedBarriers
        notes = savedNotes
        if let answer = draft["in_use"], ["yes", "no", ""].contains(answer) { inUse = answer }
        if let rating = draft["satisfaction"].flatMap(Int.init), (0...5).contains(rating) { satisfaction = rating }
        restoredDraft = true
        // Said after the sheet has opened: its arrival moves VoiceOver focus.
        announceAfterDismiss("Your unsaved follow-up for this device was restored.")
    }

    private func clearDraft() {
        DraftStore.clear(key: draftKey, owner: api.userId)
    }

    /// Typed text that closing the sheet would throw away.
    private var hasTypedText: Bool {
        !barriers.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            || !notes.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                if restoredDraft {
                    Text("Your unsaved follow-up from earlier was restored. Cancel, then Discard, removes it.")
                        .font(.callout)
                }
                Section(followup.equipmentName) {
                    Text(followup.clientName).foregroundStyle(Color.secondaryText)
                    // A segmented picker shows (and speaks) no label of its own. No segment is
                    // selected until one is chosen.
                    Text("Still in use? (required)")
                    Picker("Still in use?", selection: $inUse) {
                        Text("Yes").tag("yes" as String?); Text("No").tag("no" as String?); Text("Unknown").tag("" as String?)
                    }.pickerStyle(.segmented)
                    // Each rating says what it means, and "not rated" is a word, not a dash.
                    Picker("Satisfaction", selection: $satisfaction) {
                        Text("Not rated").tag(0)
                        ForEach(1...5, id: \.self) { Text(satisfactionLabel($0)).tag($0) }
                    }
                }
                Section {
                    TextField("Barriers (if any)", text: $barriers, axis: .vertical).lineLimit(1...4)
                    TextField("Notes", text: $notes, axis: .vertical).lineLimit(1...6)
                } footer: {
                    if inUse == "no" {
                        Text("Because the device is no longer in use, a case note and a follow-up task will be created automatically.")
                    }
                }
            }
            .confirmationDialog("Record that \(followup.equipmentName) is no longer in use by \(followup.clientName)? This adds a case note and a follow-up task, and a recorded follow-up cannot be changed.",
                                isPresented: $confirmingNotInUse, titleVisibility: .visible) {
                Button("Record as not in use") { Task { await save() } }
                Button("Go back", role: .cancel) {}
            }
            .navigationTitle("Record follow-up")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the result (or a failure) must be shown on this sheet.
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { requestClose() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { if inUse == "no" { confirmingNotInUse = true } else { Task { await save() } } }
                        .disabled(busy || inUse == nil)
                        .accessibilityHint(inUse == nil ? "Answer Still in use first." : "")
                }
            }
            // A swipe down cannot close the sheet while saving or once text has been typed;
            // Cancel and the VoiceOver escape gesture ask first.
            .interactiveDismissDisabled(busy || hasTypedText)
            .accessibilityAction(.escape) { requestClose() }
            .confirmationDialog("Discard this follow-up? What you typed will be lost.",
                                isPresented: $confirmingDiscard, titleVisibility: .visible) {
                Button("Discard follow-up", role: .destructive) { clearDraft(); dismiss() }
                Button("Keep editing", role: .cancel) {}
            }
            .onChange(of: barriers) { _ in saveDraft() }
            .onChange(of: notes) { _ in saveDraft() }
            .onChange(of: inUse) { _ in saveDraft() }
            .onChange(of: satisfaction) { _ in saveDraft() }
            .onAppear { restoreDraft() }
        }
    }

    private func requestClose() {
        guard !busy else { return }
        if hasTypedText { confirmingDiscard = true } else { dismiss() }
    }

    private func satisfactionLabel(_ n: Int) -> String {
        switch n {
        case 1: return "1 – Very dissatisfied"
        case 2: return "2 – Dissatisfied"
        case 3: return "3 – Neutral"
        case 4: return "4 – Satisfied"
        default: return "5 – Very satisfied"
        }
    }

    private func save() async {
        guard let inUse else { return }
        busy = true; error = nil
        var body: [String: Any] = ["id": followup.id, "op": "complete", "in_use": inUse, "barriers": barriers, "notes": notes]
        if satisfaction > 0 { body["satisfaction"] = satisfaction }
        do {
            let r: SaveFollowupResponse = try await api.call("save_followup", body: body)
            // The list announces the result once this sheet has gone (a message posted while it
            // is still open is cut off when it closes).
            clearDraft()
            onSaved(r.warning); dismiss()
        } catch APIError.network {
            // No signal: the answers are kept on this phone with the offline notes and hours,
            // and upload automatically (the server records the check then).
            do {
                try api.enqueue("save_followup", body: body, label: "Follow-up: \(followup.equipmentName)")
                clearDraft()
                onSaved("Follow-up saved offline — will sync when connected. It stays listed as due until then."); dismiss()
            } catch {
                busy = false
                showFormError("Could not save offline. Your answers are still in this form. \(error.localizedDescription)", in: &self.error)
            }
        } catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}

/// save_followup's reply: `warning` says the follow-up was recorded but its task could not be
/// created (absent on older servers and when nothing went wrong). Tolerant: the follow-up is
/// recorded once the server says ok, so an unexpected shape here must not be shown as a
/// failure that invites recording it again.
struct SaveFollowupResponse: Decodable {
    let warning: String?
    private enum CodingKeys: String, CodingKey { case warning }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        let text = try? c.decodeIfPresent(String.self, forKey: .warning)
        warning = (text ?? "").isEmpty ? nil : text
    }
}
