import SwiftUI

/// Device follow-ups / AT abandonment tracking (web v2.45 parity).
struct FollowupsView: View {
    @EnvironmentObject var api: API
    @State private var data: FollowupsResponse?
    @State private var error: String?
    @State private var recording: Followup?
    @State private var skipping: Followup?
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// After Snooze or Skip takes a row out of Due now, VoiceOver is put on that section's
    /// heading rather than left wherever it lands.
    @AccessibilityFocusState private var dueHeaderFocused: Bool

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }

            if let d = data {
                Section {
                    HStack {
                        statPill(d.retention != nil ? "\(d.retention!)%" : "—", "Still in use")
                        statPill("\(d.abandoned)", "No longer used")
                        statPill("\(d.completed)", "Checks done")
                    }
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
                        }
                    }
                }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle("Device follow-ups")
        .sheet(item: $recording, onDismiss: announceSheetResult) { f in
            RecordFollowupSheet(followup: f) { sheetResult = "Follow-up recorded."; Task { await load() } }
        }
        // Skipping closes the check for good, so it takes a second, deliberate step.
        .confirmationDialog("Skip this check for \(skipping?.equipmentName ?? "this device")? It will not be asked again.",
                            isPresented: Binding(get: { skipping != nil }, set: { if !$0 { skipping = nil } }),
                            titleVisibility: .visible, presenting: skipping) { f in
            Button("Skip this check", role: .destructive) { Task { await act(f, "skip") } }
            Button("Cancel", role: .cancel) {}
        }
        .refreshable { await load() }
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
    }

    @ViewBuilder private func dueButtons(_ f: Followup) -> some View {
        // Each button names its check, since every due row has the same three; the
        // visible words stay a Voice Control name.
        let check = "\(f.equipmentName) check for \(f.clientName)"
        Button("Record") { recording = f }.buttonStyle(.borderedProminent).onBrandLabel().controlSize(.large)
            .accessibilityLabel("Record \(check)")
            .accessibilityInputLabels(["Record", "Record \(check)"])
        Button("Snooze 2 weeks") { Task { await act(f, "snooze") } }.buttonStyle(.bordered).controlSize(.large)
            .accessibilityLabel("Snooze \(check) two weeks")
            .accessibilityInputLabels(["Snooze", "Snooze 2 weeks", "Snooze \(check) two weeks"])
        Button("Skip") { skipping = f }.buttonStyle(.bordered).controlSize(.large)
            .accessibilityLabel("Skip \(check)")
            .accessibilityInputLabels(["Skip", "Skip \(check)"])
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
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
        do {
            let _: OKResponse = try await api.call("save_followup", body: ["id": f.id, "op": op])
            await load()
            // The row has left Due now: put VoiceOver on that heading, then say what happened,
            // after the focus move so it is not cut off.
            dueHeaderFocused = true
            announceAfterDismiss(op == "skip" ? "Follow-up skipped." : "Follow-up moved out two weeks.")
        } catch { self.error = error.localizedDescription }
    }

    private func load() async {
        do { error = nil; data = try await api.call("followups") }
        catch { self.error = error.localizedDescription }
    }
}

struct RecordFollowupSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let followup: Followup
    let onSaved: () -> Void

    @State private var inUse = "yes"
    @State private var satisfaction = 0
    @State private var barriers = ""
    @State private var notes = ""
    @State private var busy = false
    @State private var error: String?
    @State private var confirmingDiscard = false

    /// Typed text that closing the sheet would throw away.
    private var hasTypedText: Bool {
        !barriers.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
            || !notes.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                Section(followup.equipmentName) {
                    Text(followup.clientName).foregroundStyle(Color.secondaryText)
                    // A segmented picker shows (and speaks) no label of its own.
                    Text("Still in use?")
                    Picker("Still in use?", selection: $inUse) {
                        Text("Yes").tag("yes"); Text("No").tag("no"); Text("Unknown").tag("")
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
            .navigationTitle("Record follow-up")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the result (or a failure) must be shown on this sheet.
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { requestClose() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Save") { Task { await save() } }.disabled(busy)
                }
            }
            // A swipe down cannot close the sheet while saving or once text has been typed;
            // Cancel and the VoiceOver escape gesture ask first.
            .interactiveDismissDisabled(busy || hasTypedText)
            .accessibilityAction(.escape) { requestClose() }
            .confirmationDialog("Discard this follow-up? What you typed will be lost.",
                                isPresented: $confirmingDiscard, titleVisibility: .visible) {
                Button("Discard follow-up", role: .destructive) { dismiss() }
                Button("Keep editing", role: .cancel) {}
            }
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
        busy = true; error = nil
        var body: [String: Any] = ["id": followup.id, "op": "complete", "in_use": inUse, "barriers": barriers, "notes": notes]
        if satisfaction > 0 { body["satisfaction"] = satisfaction }
        do {
            let _: OKResponse = try await api.call("save_followup", body: body)
            // The list announces the result once this sheet has gone (a message posted while it
            // is still open is cut off when it closes).
            onSaved(); dismiss()
        } catch { self.error = error.localizedDescription; busy = false }
    }
}
