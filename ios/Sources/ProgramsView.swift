import SwiftUI

struct CohortRow: Decodable, Identifiable {
    let id: Int; let name: String; let school: String?; let members: Int; let sessions: Int
    private enum CodingKeys: String, CodingKey { case id, name, school, members, sessions }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        school = try? c.decodeIfPresent(String.self, forKey: .school)
        members = (try? c.decode(Int.self, forKey: .members)) ?? 0
        sessions = (try? c.decode(Int.self, forKey: .sessions)) ?? 0
    }
}
struct CohortsResponse: Decodable { let cohorts: [CohortRow] }
struct CohortMember: Decodable, Identifiable { let id: Int; let name: String }
struct CohortSession: Decodable, Identifiable { let id: Int; let sessionDate: String; let activity: String; let topic: String?; let durationMin: Int; let presentN: Int }
struct CohortInfo: Decodable { let id: Int; let name: String; let school: String? }
struct CohortDetailResponse: Decodable { let cohort: CohortInfo; let members: [CohortMember]; let sessions: [CohortSession]; let available: [CohortMember] }

let preetsActivities = ["Job Exploration Counseling", "Work-Based Learning", "Postsecondary Counseling", "Workplace Readiness", "Self-Advocacy Instruction", "Other"]

struct ProgramsView: View {
    @EnvironmentObject var api: API
    @State private var rows: [CohortRow] = []
    /// Set once a list has loaded, so "No active cohorts." is never claimed before then.
    @State private var loaded = false
    @State private var error: String?
    @State private var showNew = false
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if loaded && rows.isEmpty {
                Text("No active cohorts.").foregroundStyle(Color.secondaryText)
            } else if !loaded && error == nil {
                ProgressView("Loading cohorts…")
            }
            ForEach(rows) { c in
                NavigationLink { CohortDetail(id: c.id) } label: {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(c.name).font(.body.weight(.semibold))
                        Text("\(c.members) students · \(c.sessions) sessions\(c.school != nil ? " · \(c.school!)" : "")").font(.caption).foregroundStyle(Color.secondaryText)
                    }
                }
            }
        }
        .navigationTitle("Programs")
        .refreshable { await load() }
        .task { await load() }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add cohort") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) { CohortNewSheet { sheetResult = "Cohort created."; Task { await load() } } }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { error = nil; let r: CohortsResponse = try await api.call("cohorts"); rows = r.cohorts; loaded = true }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct CohortDetail: View {
    @EnvironmentObject var api: API
    let id: Int
    @State private var data: CohortDetailResponse?
    @State private var error: String?
    @State private var addMember: Int?
    @State private var logSession = false
    /// The student whose removal is waiting for confirmation.
    @State private var pendingRemove: CohortMember?
    /// A student is being added, so the Add button cannot send it twice.
    @State private var addingMember = false
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// After an add, VoiceOver is put on the new student; after a removal, on the Students
    /// heading (-1), since the row it was on is gone (errorFocus: the error banner, after a failure).
    @AccessibilityFocusState private var focusedMember: Int?
    private let errorFocus = -2
    private let errorRow = "cohortError"

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden)
                        .id(errorRow)
                        .accessibilityFocused($focusedMember, equals: errorFocus)
                }
                if let d = data {
                    Section {
                        ForEach(d.members) { m in
                            HStack {
                                Text(m.name)
                                Spacer()
                                // A visible single-tap way to remove, as well as the swipe action below.
                                Button("Remove") { pendingRemove = m }
                                    .buttonStyle(.bordered).controlSize(.large)
                                    .accessibilityLabel("Remove \(m.name) from the cohort")
                                    .accessibilityInputLabels(["Remove", "Remove \(m.name)"])
                            }
                            // VoiceOver: one stop per student; Remove (confirmed first, as the button
                            // and swipe action are) is on the Actions rotor.
                            .accessibilityElement(children: .ignore)
                            .accessibilityLabel(m.name)
                            .accessibilityAction(named: "Remove from cohort") { pendingRemove = m }
                            .accessibilityFocused($focusedMember, equals: m.id)
                            // Confirmed first (not a destructive-role button, which would take the
                            // row off screen before the user has confirmed).
                            .swipeActions(allowsFullSwipe: false) {
                                Button { pendingRemove = m } label: { Text("Remove") }
                                    .tint(Color.alertFill)   // white on #9B1C1C is 8.2:1 (system red was 3.55:1)
                            }
                        }
                        if !d.available.isEmpty {
                            // Choosing a name only selects it; the button below adds the student, as
                            // on the website.
                            Picker("Student to add", selection: $addMember) {
                                Text("Choose…").tag(nil as Int?)
                                ForEach(d.available) { a in Text(a.name).tag(a.id as Int?) }
                            }
                            let chosen = d.available.first(where: { $0.id == addMember })
                            Button {
                                if let chosen { Task { await member(chosen.id, name: chosen.name, "add") } }
                            } label: {
                                Label(chosen.map { "Add \($0.name) to the cohort" } ?? "Add student", systemImage: "person.badge.plus")
                            }
                            .disabled(chosen == nil || addingMember)
                            .accessibilityHint(chosen == nil ? "Choose a student to add first." : "")
                        }
                    } header: {
                        Text("Students (\(d.members.count))").accessibilityFocused($focusedMember, equals: -1)
                    }
                    Section {
                        Button { logSession = true } label: { Label("Log a group session", systemImage: "plus.circle") }
                            .disabled(d.members.isEmpty)
                    }
                    Section("Recent sessions") {
                        if d.sessions.isEmpty { Text("None yet.").foregroundStyle(Color.secondaryText) }
                        ForEach(d.sessions) { s in
                            VStack(alignment: .leading, spacing: 2) {
                                Text(s.activity).font(.body.weight(.semibold))
                                Text("\(formatDate(s.sessionDate)) · \(s.durationMin) min · \(s.presentN) present").font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            .accessibilityElement(children: .combine)
                        }
                    }
                } else if error == nil { ProgressView() }
            }
            // The banner is the list's first row: a failed add or removal further down would
            // otherwise leave it off screen, where a List never draws it, so it was neither seen
            // nor heard. Scroll it into view once it exists, then put VoiceOver on it.
            .onChange(of: error) { message in
                guard message != nil else { return }
                DispatchQueue.main.async { withAnimation { proxy.scrollTo(errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    focusedMember = errorFocus
                }
            }
        }
        .navigationTitle(data?.cohort.name ?? "Cohort")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .sheet(isPresented: $logSession, onDismiss: announceSheetResult) {
            if let d = data { CohortSessionSheet(cohortId: id, members: d.members) { sheetResult = "Session logged."; Task { await load() } } }
        }
        // Taking a student out of the cohort takes a second, deliberate step.
        .confirmationDialog(
            "Remove \(pendingRemove?.name ?? "") from \(data?.cohort.name ?? "this cohort")?",
            isPresented: Binding(get: { pendingRemove != nil }, set: { if !$0 { pendingRemove = nil } }),
            titleVisibility: .visible
        ) {
            Button("Remove student", role: .destructive) {
                if let m = pendingRemove { Task { await member(m.id, name: m.name, "remove") } }
                pendingRemove = nil
            }
            Button("Keep student", role: .cancel) { pendingRemove = nil }
        }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { error = nil; data = try await api.call("cohort", query: ["id": String(id)]) }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    private func member(_ clientId: Int, name: String, _ op: String) async {
        if op == "add" {
            guard !addingMember else { return }
            addingMember = true
        }
        defer { if op == "add" { addingMember = false } }
        // Cleared first so a repeat of the same failure is shown (and announced) again.
        error = nil
        do {
            let _: OKResponse = try await api.call("cohort_member", body: ["cohort_id": id, "client_id": clientId, "op": op])
            if op == "add" { addMember = nil }
            await load()
            // A known place for VoiceOver once the list has changed, then the result, after the
            // focus move so it is not cut off.
            focusedMember = op == "add" ? clientId : -1
            announceAfterDismiss(op == "add" ? "\(name) added to the cohort." : "\(name) removed from the cohort.")
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct CohortSessionSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let cohortId: Int; let members: [CohortMember]; let onDone: () -> Void
    @State private var activity = "Job Exploration Counseling"
    @State private var date = Date(); @State private var durationText = "60"; @State private var topic = ""; @State private var notes = ""
    @State private var present: Set<Int> = []
    @State private var busy = false; @State private var error: String?
    /// A logged session cannot be changed or removed from the app, so it is reviewed first.
    @State private var confirming = false

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm): Log is usually
            // pressed with the attendance list and Notes scrolled into view.
            ErrorFocusForm(error: error) {
                Picker("Activity", selection: $activity) { ForEach(preetsActivities, id: \.self) { Text($0) } }
                DatePicker("Date", selection: $date, displayedComponents: .date)
                // Any whole number of minutes, as the server and website accept (a 50-minute class
                // period is logged as 50, not rounded to a 15-minute step).
                KeyboardCaptionedField(label: "Duration in minutes (1 to 1440)", text: $durationText, keyboard: .numberPad)
                // Visible names that stay once the fields are filled.
                CaptionedField(label: "Topic", text: $topic)
                Section("Attendance") {
                    ForEach(members) { m in
                        Toggle(m.name, isOn: Binding(get: { present.contains(m.id) }, set: { on in if on { present.insert(m.id) } else { present.remove(m.id) } }))
                    }
                }
                Section { CaptionedField(label: "Notes", text: $notes) }
            }
            .navigationTitle("Log session").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) { Button("Log") { reviewLog() }.disabled(busy) }
            }
            .interactiveDismissDisabled(busy)
            .confirmationDialog(summary, isPresented: $confirming, titleVisibility: .visible) {
                Button("Log session") { Task { await submit() } }
                Button("Go back", role: .cancel) {}
            }
            .onAppear { present = Set(members.map { $0.id }) }
        }
    }
    /// The duration typed as whole minutes from 1 to 1440; nil when the text is not one
    /// (nothing is rounded or clamped).
    private var duration: Int? {
        let t = durationText.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !t.isEmpty, t.count <= 4, t.allSatisfy({ ("0"..."9").contains($0) }), let n = Int(t), (1...1440).contains(n) else { return nil }
        return n
    }
    private static let durationMessage = "Duration: enter the session length in whole minutes from 1 to 1440, like 50."
    /// The duration is checked before the session is read back for confirmation.
    private func reviewLog() {
        guard duration != nil else { showFormError(Self.durationMessage, in: &error); return }
        error = nil
        confirming = true
    }
    /// What will be recorded, with the students marked absent named, read back before it is sent.
    private var summary: String {
        let here = members.filter { present.contains($0.id) }.count
        let absent = members.filter { !present.contains($0.id) }.map(\.name)
        let day = date.formatted(date: .abbreviated, time: .omitted)
        let away = absent.isEmpty ? "" : " Absent: \(absent.joined(separator: ", "))."
        let minutes = duration.map { "\($0) minute\($0 == 1 ? "" : "s")" } ?? "no valid duration"
        return "Log \(activity) on \(day), \(minutes), \(here) of \(members.count) present?\(away) A logged session cannot be changed from the app."
    }
    private func submit() async {
        guard let duration else { showFormError(Self.durationMessage, in: &error); return }
        busy = true; error = nil
        // The cohort screen announces the result once this sheet has gone.
        do { let _: OKResponse = try await api.call("log_cohort_session", body: ["cohort_id": cohortId, "activity": activity, "session_date": APIDate.day(date), "duration_min": duration, "topic": topic, "notes": notes, "present": Array(present)]); onDone(); dismiss() }
        catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct CohortNewSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var name = ""; @State private var school = ""; @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false
    private var hasChanges: Bool {
        [name, school].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }
    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(error: error) {
                // Visible names that stay once the fields are filled; the required one says so.
                CaptionedField(label: "Cohort name (required)", text: $name)
                CaptionedField(label: "School / district", text: $school)
            }
            .navigationTitle("New cohort").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the cohort would still be created, with no word of it here.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                // Left enabled: a missing name is explained by the error banner, not a dimmed button.
                ToolbarItem(placement: .confirmationAction) { Button("Create") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this new cohort?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }
    private func submit() async {
        if name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Cohort name: enter a name for the cohort.", in: &error); return
        }
        busy = true; error = nil
        // The programs list announces the result once this sheet has gone.
        do { let _: OKResponse = try await api.call("add_cohort", body: ["name": name, "school": school]); onDone(); dismiss() }
        catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}
