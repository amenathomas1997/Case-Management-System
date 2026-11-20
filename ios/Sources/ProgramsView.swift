import SwiftUI

struct CohortRow: Decodable, Identifiable {
    let id: Int; let name: String; let school: String?; let members: Int; let sessions: Int
    /// 4.37.0 (S21): the coordinator and dates (nil from an older server).
    let coordinator: String?; let startDate: String?; let endDate: String?
    private enum CodingKeys: String, CodingKey { case id, name, school, members, sessions, coordinator, startDate, endDate }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        school = try? c.decodeIfPresent(String.self, forKey: .school)
        members = (try? c.decode(Int.self, forKey: .members)) ?? 0
        sessions = (try? c.decode(Int.self, forKey: .sessions)) ?? 0
        coordinator = try? c.decodeIfPresent(String.self, forKey: .coordinator)
        startDate = try? c.decodeIfPresent(String.self, forKey: .startDate)
        endDate = try? c.decodeIfPresent(String.self, forKey: .endDate)
    }
}
struct CohortsResponse: Decodable { let cohorts: [CohortRow] }
struct CohortMember: Decodable, Identifiable { let id: Int; let name: String }
struct CohortSession: Decodable, Identifiable {
    let id: Int; let sessionDate: String; let activity: String; let topic: String?; let durationMin: Int; let presentN: Int
    /// 4.37.0 (S21): programs.php's line for the session and whether it can be deleted.
    let text: String?; let canDelete: Bool
    private enum CodingKeys: String, CodingKey { case id, sessionDate, activity, topic, durationMin, presentN, text, canDelete }
    // Tolerant decode: one odd session row must never blank the cohort screen.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        sessionDate = (try? c.decode(String.self, forKey: .sessionDate)) ?? ""
        activity = (try? c.decode(String.self, forKey: .activity)) ?? ""
        topic = try? c.decodeIfPresent(String.self, forKey: .topic)
        durationMin = (try? c.decode(Int.self, forKey: .durationMin)) ?? Int((try? c.decode(String.self, forKey: .durationMin)) ?? "") ?? 0
        presentN = (try? c.decode(Int.self, forKey: .presentN)) ?? Int((try? c.decode(String.self, forKey: .presentN)) ?? "") ?? 0
        text = try? c.decodeIfPresent(String.self, forKey: .text)
        canDelete = (try? c.decode(Bool.self, forKey: .canDelete)) ?? ((try? c.decode(Int.self, forKey: .canDelete)).map { $0 != 0 } ?? false)
    }
}
/// The cohort row as the server sends it (its raw columns: dates as text, active as 1 or 0,
/// read tolerantly).
struct CohortInfo: Decodable {
    let id: Int; let name: String; let school: String?
    let startDate: String?; let endDate: String?
    private enum CodingKeys: String, CodingKey { case id, name, school, startDate, endDate }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        school = try? c.decodeIfPresent(String.self, forKey: .school)
        startDate = try? c.decodeIfPresent(String.self, forKey: .startDate)
        endDate = try? c.decodeIfPresent(String.self, forKey: .endDate)
    }
}
/// The cohort reply. 4.37.0 (S21) sends the coordinator's name, the notes and whether the
/// cohort is active beside the cohort row (nil from an older server, which offers no archive).
struct CohortDetailResponse: Decodable {
    let cohort: CohortInfo; let members: [CohortMember]; let sessions: [CohortSession]; let available: [CohortMember]
    let sessionsTotal: Int?
    let coordinatorName: String?; let notes: String?; let active: Bool?
    private enum CodingKeys: String, CodingKey { case cohort, members, sessions, available, sessionsTotal, coordinatorName, notes, active }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        cohort = try c.decode(CohortInfo.self, forKey: .cohort)
        members = (try? c.decode([CohortMember].self, forKey: .members)) ?? []
        sessions = LenientList.decode(CohortSession.self, from: c, forKey: .sessions)
        available = (try? c.decode([CohortMember].self, forKey: .available)) ?? []
        sessionsTotal = try? c.decodeIfPresent(Int.self, forKey: .sessionsTotal)
        coordinatorName = try? c.decodeIfPresent(String.self, forKey: .coordinatorName)
        notes = try? c.decodeIfPresent(String.self, forKey: .notes)
        active = (try? c.decodeIfPresent(Bool.self, forKey: .active)) ?? ((try? c.decodeIfPresent(Int.self, forKey: .active)).map { $0 != 0 })
    }
}
/// cohort_choices (4.37.0, S21): the active staff who can coordinate, and the activities.
struct CohortChoice: Decodable { let id: Int; let name: String }
struct CohortChoicesReply: Decodable { let coordinators: [CohortChoice]; let activities: [String]? }

/// The cohort's header line: "Coordinator: X", then its dates.
func cohortHeader(_ d: CohortDetailResponse) -> String? {
    var parts: [String] = []
    if let n = d.coordinatorName, !n.isEmpty { parts.append("Coordinator: \(n)") }
    let start = (d.cohort.startDate ?? "").isEmpty ? nil : formatDate(d.cohort.startDate)
    let end = (d.cohort.endDate ?? "").isEmpty ? nil : formatDate(d.cohort.endDate)
    if let start, let end { parts.append("\(start) to \(end)") }
    else if let start { parts.append("Starts \(start)") }
    else if let end { parts.append("Ends \(end)") }
    return parts.isEmpty ? nil : parts.joined(separator: ". ")
}

/// The delete question for a logged session (4.37.0, S21), naming it.
func deleteSessionTitle(activity: String, date: String) -> String {
    "Delete the session \(activity), \(date), and its attendance?"
}

/// add_cohort's 4.37.0 body (S21): the coordinator (0 = none) only once the choices are known,
/// so an older server keeps the caller as coordinator.
func cohortBody(name: String, school: String, coordinatorId: Int?, startDate: String, endDate: String, notes: String) -> [String: Any] {
    var body: [String: Any] = ["name": name, "school": school, "start_date": startDate, "end_date": endDate, "notes": notes]
    if let coordinatorId { body["coordinator_id"] = coordinatorId }
    return body
}

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
    /// 4.37.0 (S21): programs.php's "Show archived" / "Show active cohorts".
    @State private var show = "active"
    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            Picker("Show", selection: $show) {
                Text("Active cohorts").tag("active")
                Text("Archived cohorts").tag("archived")
            }
            if loaded && rows.isEmpty {
                Text(show == "archived" ? "No archived cohorts." : "No active cohorts.").foregroundStyle(Color.secondaryText)
            } else if !loaded && error == nil {
                ProgressView("Loading cohorts…")
            }
            ForEach(rows) { c in
                NavigationLink { CohortDetail(id: c.id) } label: {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(c.name).font(.body.weight(.semibold))
                        Text("\(c.members) students · \(c.sessions) sessions\(c.school != nil ? " · \(c.school!)" : "")").font(.caption).foregroundStyle(Color.secondaryText)
                        if let coordinator = c.coordinator, !coordinator.isEmpty {
                            Text("Coordinator: \(coordinator)").font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                }
            }
        }
        .navigationTitle("Programs")
        .refreshable { await load() }
        .task { await load() }
        .onChange(of: show) { _ in Task { await load() } }
        .modifier(ReadablePickers(inNavigationStack: true))
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add cohort") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) { CohortNewSheet { message in sheetResult = message; Task { await load() } } }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { error = nil; let r: CohortsResponse = try await api.call("cohorts", query: ["show": show]); rows = r.cohorts; loaded = true }
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
    /// Sent as idem_key when adding a member (4.28.0), so a retry after a lost reply is recognised.
    @State private var memberIdem = IdemKey()
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// 4.37.0 (S21): the session waiting for "Delete the session …?", and a change being sent.
    @State private var deletingSession: CohortSession?
    @State private var changing = false
    @State private var note: String?
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
                    if let note { Text(note).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal) }
                    if let header = cohortHeader(d) {
                        Section {
                            Text(header)
                            if let n = d.notes, !n.isEmpty { Text(n).font(.callout) }
                        }
                    }
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
                            .accessibilityElement(children: VoiceOverRows.children)
                            .accessibilityLabel(m.name)
                            .accessibilityAction { announceRowActions() }
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
                                Text(s.text ?? "\(formatDate(s.sessionDate)) · \(s.durationMin) min · \(s.presentN) present").font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            .accessibilityElement(children: .combine)
                            // 4.37.0 (S21): a visible button after the session's one stop.
                            if s.canDelete {
                                Button("Delete session: \(s.activity), \(formatDate(s.sessionDate))", role: .destructive) { deletingSession = s }
                                    .disabled(changing)
                            }
                        }
                        if let total = d.sessionsTotal, total > d.sessions.count {
                            Text("Showing the newest \(d.sessions.count) sessions. Older ones are on the website's cohort page, where they can be deleted too.")
                                .font(.callout).foregroundStyle(Color.secondaryText)
                        }
                    }
                    if let active = d.active {
                        Section {
                            // No confirmation, as programs.php: either is undone by the other.
                            Button(active ? "Archive cohort" : "Reactivate cohort") { Task { await setActive(!active) } }
                                .disabled(changing)
                        }
                    }
                } else if error == nil { ProgressView() }
            }
            // The banner is the list's first row: a failed add or removal further down would
            // otherwise leave it off screen, where a List never draws it, so it was neither seen
            // nor heard. Scroll it into view once it exists, then put VoiceOver on it.
            .onChange(of: error) { message in
                guard let message else { return }
                claimFormErrorFocus(message)   // VoiceOver is put on the banner, which reads it
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    focusedMember = errorFocus
                }
            }
        }
        .navigationTitle(data?.cohort.name ?? "Cohort")
        .modifier(ReadablePickers(inNavigationStack: true))   // choices not cut short at large text sizes
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .sheet(isPresented: $logSession, onDismiss: announceSheetResult) {
            if let d = data { CohortSessionSheet(cohortId: id, members: d.members) { message in sheetResult = message; Task { await load() } } }
        }
        .confirmationDialog(deleteSessionTitle(activity: deletingSession?.activity ?? "", date: formatDate(deletingSession?.sessionDate)),
                            isPresented: Binding(get: { deletingSession != nil }, set: { if !$0 { deletingSession = nil } }),
                            titleVisibility: .visible) {
            Button("Delete session", role: .destructive) {
                if let s = deletingSession { Task { await deleteSession(s) } }
                deletingSession = nil
            }
            Button("Keep it", role: .cancel) { deletingSession = nil }
        } message: {
            Text("Its hours come out of the report.")
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
    /// delete_cohort_session and set_cohort_active (4.37.0, S21): the server's sentence is shown
    /// and said once, then the cohort reloads.
    private func cohortChange(_ action: String, _ body: [String: Any]) async {
        guard !changing else { return }
        changing = true; error = nil; note = nil
        defer { changing = false }
        do {
            let r: MessageReply = try await api.call(action, method: "POST", body: body)
            let message = r.message ?? "Saved."
            note = message
            await load()
            announce(message)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    private func deleteSession(_ s: CohortSession) async {
        await cohortChange("delete_cohort_session", ["cohort_id": id, "session_id": s.id])
    }
    private func setActive(_ active: Bool) async {
        await cohortChange("set_cohort_active", ["cohort_id": id, "active": active])
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
            var body: [String: Any] = ["cohort_id": id, "client_id": clientId, "op": op]
            if op == "add" { body["idem_key"] = memberIdem.key(for: body) }
            let _: OKResponse = try await api.call("cohort_member", body: body)
            if op == "add" { memberIdem.answered() }
            if op == "add" { addMember = nil }
            await load()
            // A known place for VoiceOver once the list has changed, then the result, after the
            // focus move so it is not cut off.
            focusedMember = op == "add" ? clientId : -1
            announceAfterDismiss(op == "add" ? "\(name) added to the cohort." : "\(name) removed from the cohort.")
        }
        catch {
            if op == "add" { memberIdem.failed(error) }
            showFormError(op == "add" ? IdemKey.message(for: error, what: "\(name)'s membership") : error.localizedDescription, in: &self.error)
        }
    }
}

struct CohortSessionSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let cohortId: Int; let members: [CohortMember]
    /// Given the server's sentence (4.37.0 adds how to correct a mistake).
    let onDone: (String) -> Void
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
            ErrorFocusForm(inNavigationStack: true, error: error) {
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
        return "Log \(activity) on \(day), \(minutes), \(here) of \(members.count) present?\(away) A logged session cannot be changed; if anything is wrong, delete it under Recent sessions and log it again."
    }
    private func submit() async {
        guard let duration else { showFormError(Self.durationMessage, in: &error); return }
        busy = true; error = nil
        // The cohort screen announces the result once this sheet has gone.
        var body: [String: Any] = ["cohort_id": cohortId, "activity": activity, "session_date": APIDate.day(date), "duration_min": duration, "topic": topic, "notes": notes, "present": Array(present).sorted()]
        body["idem_key"] = idem.key(for: body)
        do {
            let r: MessageReply = try await api.call("log_cohort_session", body: body); idem.answered()
            onDone(r.message ?? "Session logged. If anything is wrong, delete it under Recent sessions and log it again."); dismiss()
        }
        catch { idem.failed(error); busy = false; showFormError(IdemKey.message(for: error, what: "the session"), in: &self.error) }
    }
}

struct CohortNewSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    /// Given the server's sentence ("Cohort created.").
    let onDone: (String) -> Void
    @State private var name = ""; @State private var school = ""; @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false
    /// 4.37.0 (S21), as programs.php's New cohort form: the coordinator (you by default), the
    /// dates and the notes (owner decision 1).
    @State private var coordinators: [CohortChoice]?
    @State private var coordinatorId = 0
    @State private var hasStart = false
    @State private var startDate = Date()
    @State private var hasEnd = false
    @State private var endDate = Date()
    @State private var notes = ""
    private var hasChanges: Bool {
        [name, school, notes].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty } || hasStart || hasEnd
    }
    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(inNavigationStack: true, error: error) {
                // Visible names that stay once the fields are filled; the required one says so.
                CaptionedField(label: "Cohort name (required)", text: $name, errorName: "Cohort name")
                CaptionedField(label: "School / district", text: $school)
                if let coordinators {
                    Picker("Coordinator", selection: $coordinatorId) {
                        Text("None").tag(0)
                        ForEach(coordinators, id: \.id) { Text($0.name).tag($0.id) }
                    }
                    .errorField("Coordinator")
                }
                Toggle("Start date", isOn: $hasStart)
                if hasStart {
                    DatePicker("Start", selection: $startDate, displayedComponents: .date).errorField("Start date")
                }
                Toggle("End date", isOn: $hasEnd)
                if hasEnd {
                    DatePicker("End", selection: $endDate, displayedComponents: .date).errorField("End date")
                }
                CaptionedField(label: "Notes (optional)", text: $notes, lines: 2...6, errorName: "Notes")
            }
            .navigationTitle("New cohort").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the cohort would still be created, with no word of it here.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                // Left enabled: a missing name is explained by the error banner, not a dimmed button.
                ToolbarItem(placement: .confirmationAction) { Button("Create cohort") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this new cohort?",
                                 asking: $askingDiscard) { dismiss() }
            .task {
                // An older server has no choices: the cohort is then yours, as before.
                if coordinators == nil, let r: CohortChoicesReply = try? await api.call("cohort_choices") {
                    coordinators = r.coordinators
                    if r.coordinators.contains(where: { $0.id == api.userId }) { coordinatorId = api.userId }
                }
            }
        }
    }
    private func submit() async {
        if name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Cohort name is required.", in: &error, field: "Cohort name"); return
        }
        if hasStart && hasEnd && Calendar.current.startOfDay(for: endDate) < Calendar.current.startOfDay(for: startDate) {
            showFormError("The end date cannot be before the start date.", in: &error, field: "End date"); return
        }
        if notes.unicodeScalars.count > 2000 {
            showFormError("Notes: use 2,000 characters or fewer.", in: &error, field: "Notes"); return
        }
        busy = true; error = nil
        // The programs list announces the result once this sheet has gone.
        var body = cohortBody(name: name, school: school, coordinatorId: coordinators == nil ? nil : coordinatorId,
                              startDate: hasStart ? APIDate.day(startDate) : "", endDate: hasEnd ? APIDate.day(endDate) : "", notes: notes)
        body["idem_key"] = idem.key(for: body)
        var refused: [String: String] = [:]
        do {
            let r: MessageReply = try await api.call("add_cohort", body: body, refusal: { refused = refusalFields($0) }); idem.answered()
            onDone(r.message ?? "Cohort created."); dismiss()
        }
        catch {
            idem.failed(error); busy = false
            let names = ["name": "Cohort name", "coordinator_id": "Coordinator", "start_date": "Start date", "end_date": "End date", "notes": "Notes"]
            showFormError(IdemKey.message(for: error, what: "the cohort"), in: &self.error,
                          field: refusedFieldName(refused, order: ["name", "coordinator_id", "start_date", "end_date", "notes"], names: names))
        }
    }
}
