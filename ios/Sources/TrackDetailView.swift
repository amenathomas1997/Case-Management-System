import SwiftUI

struct Goal: Decodable, Identifiable {
    let id: Int
    let specificGoal: String
    let measurableMetric: String
    let focusArea: String?
    let status: String
    let targetDate: String?
    let progressPct: Int?
    private enum CodingKeys: String, CodingKey { case id, specificGoal, measurableMetric, focusArea, status, targetDate, progressPct }
    // Tolerant decode so one malformed goal can't blank the track's goal list.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        specificGoal = (try? c.decode(String.self, forKey: .specificGoal)) ?? ""
        measurableMetric = (try? c.decode(String.self, forKey: .measurableMetric)) ?? ""
        focusArea = try? c.decodeIfPresent(String.self, forKey: .focusArea)
        status = (try? c.decode(String.self, forKey: .status)) ?? "Not Started"
        targetDate = try? c.decodeIfPresent(String.self, forKey: .targetDate)
        progressPct = try? c.decodeIfPresent(Int.self, forKey: .progressPct)
    }
}

struct GoalProgressEntry: Decodable, Identifiable {
    var id: String { "\(createdAt)-\(progressPct)" }
    let progressPct: Int
    let status: String
    let note: String?
    let createdAt: String
    let byName: String?
}
struct GoalProgressResponse: Decodable { let history: [GoalProgressEntry] }

struct GoalsResponse: Decodable { let goals: [Goal] }

struct GoalTemplate: Decodable, Identifiable {
    let id: Int
    let category: String
    let specificGoal: String
    let measurableMetric: String
    let focusArea: String?
}

struct GoalTemplatesResponse: Decodable {
    let templates: [GoalTemplate]
    /// Set by a server that searches: further matches exist beyond the 50 sent.
    let more: Bool?
    /// Every template area, for the area picker (nil from a server that predates search).
    let categories: [String]?
}

/// One expense logged on a track (GET expenses). Only an unbilled one can be deleted.
struct TrackExpense: Decodable, Identifiable {
    let id: Int
    let expenseDate: String
    let description: String
    let amount: Double
    let isBilled: Bool
    private enum CodingKeys: String, CodingKey { case id, expenseDate, description, amount, isBilled }
    // Tolerant decode so one odd row can't blank the expense list. A row whose billed flag
    // can't be read counts as billed, so no Delete is offered that the server would refuse.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        expenseDate = (try? c.decode(String.self, forKey: .expenseDate)) ?? ""
        description = (try? c.decode(String.self, forKey: .description)) ?? ""
        amount = (try? c.decode(Double.self, forKey: .amount))
            ?? (try? c.decode(String.self, forKey: .amount)).flatMap { Double($0) } ?? 0
        if let flag = try? c.decode(Bool.self, forKey: .isBilled) { isBilled = flag }
        else if let number = try? c.decode(Int.self, forKey: .isBilled) { isBilled = number != 0 }
        else { isBilled = true }
    }
}

// Outside the Decodable struct: tests/ios_models.py compiles the app's models on their own.
extension TrackExpense {
    var amountText: String { String(format: "$%.2f", amount) }
    /// Date, amount and description, so each row's Delete can be told apart.
    var summary: String { "\(formatDate(expenseDate)), \(amountText), \(description)" }
}

struct TrackExpensesResponse: Decodable { let expenses: [TrackExpense] }

/// A goal's objective cut to its first few words, so each goal's controls can be told apart
/// without the whole paragraph being read again for every one of them.
func shortGoalText(_ text: String) -> String {
    guard text.count > 40 else { return text }
    let cut = text.prefix(40)
    if let space = cut.lastIndex(of: " ") { return String(cut[..<space]) + "…" }
    return String(cut) + "…"
}

struct TrackDetailView: View {
    @EnvironmentObject var api: API
    let track: Track
    let clientName: String

    @State private var goals: [Goal] = []
    @State private var error: String?
    @State private var confirmation: String?
    @State private var savingGoals: Set<Int> = []
    @State private var showingAddGoal = false
    @State private var showingExpense = false
    @State private var showingReport = false
    @State private var showingLogHours = false
    @State private var showingVoice = false
    @State private var recordingGoal: Goal?
    /// The status Record progress opens with when it was opened by the status control.
    @State private var recordingStatus: String?
    @State private var trackNotes: [Note] = []
    /// All of the track's notes, from a server that reports it (nil otherwise).
    @State private var trackNotesTotal: Int?
    /// Set once each list has loaded, so "No goals yet." / "No notes" is never said before
    /// the answer arrives (or after it failed).
    @State private var goalsLoaded = false
    @State private var notesLoaded = false
    @State private var trackClientId: Int?
    @State private var showingTrackNote = false
    @State private var editingTrackNote: Note?
    @State private var expenses: [TrackExpense] = []
    @State private var expensesLoaded = false
    /// The expense waiting for the person to confirm its deletion.
    @State private var deletingExpense: TrackExpense?
    @State private var deletingExpenseBusy = false
    /// After a delete takes an expense row away, VoiceOver is put on the row that takes its
    /// place (0: the "No expenses" line) rather than left wherever it lands.
    @AccessibilityFocusState private var focusedExpense: Int?
    /// After a failure, VoiceOver is put on the error banner (see the list's onChange).
    @AccessibilityFocusState private var errorFocused: Bool
    private static let errorRow = "trackError"
    /// The result of a sheet (goal, progress, note, expense, report), said once the sheet has
    /// closed: closing it moves VoiceOver focus, which cut off a message said from inside it.
    @State private var sheetResult: String?
    /// A status picked on a goal's status control, waiting for the person to confirm it.
    @State private var pendingGoalStatus: PendingGoalStatus?
    /// Notes shown in full rather than as a six-line preview.
    @State private var expandedNotes: Set<Int> = []
    /// The goal whose history VoiceOver's History action opened (the visible History link
    /// navigates on its own).
    @State private var historyGoal: Goal?
    /// Edit track (save_track): the sheet, and the authorization as last saved here, shown in
    /// place of the values this screen was opened with.
    @State private var showingEditTrack = false
    @State private var savedTrack: TrackFormValues?
    @Environment(\.dynamicTypeSize) private var typeSize

    struct PendingGoalStatus {
        let goal: Goal
        let status: String
    }

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden)
                        .id(Self.errorRow)
                        .accessibilityFocused($errorFocused)
                }
                if let confirmation {
                    Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
                        .listRowSeparator(.hidden)
                }
                Section("Authorization") {
                    LabeledContent("Service", value: savedTrack?.serviceType ?? track.serviceType ?? "Assistive Technology")
                    LabeledContent("Authorization number", value: savedTrack?.authNumber ?? track.authNumber)
                    if let saved = savedTrack {
                        LabeledContent("Funding agency", value: saved.fundingAgency)
                        LabeledContent("Authorized hours", value: hoursSpoken(saved.authorizedHours))
                    }
                    LabeledContent("Status", value: savedTrack?.status ?? track.status)
                    LabeledContent("Rate", value: String(format: "$%.2f per hour", savedTrack?.hourlyRate ?? track.hourlyRate))
                    if let expiration = savedTrack.map({ $0.expirationDate }) ?? track.expirationDate {
                        LabeledContent("Expires", value: formatDate(expiration))
                    }
                    Button { showingEditTrack = true } label: { Label("Edit track", systemImage: "pencil") }
                        .accessibilityHint("Title, status, program, authorization, rates and dates.")
                    // After Edit track, the bar follows the saved authorization, not the one loaded.
                    let authorized = savedTrack?.authorizedHours ?? track.authorizedHours
                    AuthProgressBar(hoursWorked: track.hoursWorked,
                                    authorizedHours: authorized,
                                    hoursRemaining: savedTrack == nil ? track.hoursRemaining : max(0, authorized - track.hoursWorked),
                                    pctUsed: savedTrack == nil ? track.pctUsed : (authorized > 0 ? track.hoursWorked / authorized * 100 : 0))
                }
                Section(goalsLoaded ? "SMART goals (\(goals.count))" : "SMART goals") {
                    if !goalsLoaded {
                        if error == nil { ProgressView("Loading goals…") }
                    } else if goals.isEmpty {
                        Text("No goals yet.").foregroundStyle(Color.secondaryText)
                    }
                    ForEach(goals) { goal in
                        let short = "\(goalPosition(goal)): \(shortGoalText(goal.specificGoal))"
                        VStack(alignment: .leading, spacing: 4) {
                            Text(goal.specificGoal).font(.body.weight(.medium))
                            Text(goal.measurableMetric).font(.callout).foregroundStyle(Color.secondaryText)
                            if let target = goal.targetDate {
                                Text("Target: \(formatDate(target))").font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            let pct = goal.progressPct ?? 0
                            ProgressView(value: Double(pct), total: 100) {
                                Text("\(pct)% · \(goal.status)").font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            // Each control names its goal briefly: the full objective is the text just
                            // above, and the Buttons rotor and Voice Control still tell goals apart.
                            .accessibilityLabel("Progress, \(short)")
                            .accessibilityValue("\(pct) percent, \(goal.status)")
                            Picker("Status", selection: statusBinding(for: goal)) {
                                ForEach(goalStatuses, id: \.self) { Text($0) }
                            }
                            .pickerStyle(.segmented)
                            .disabled(savingGoals.contains(goal.id))
                            .accessibilityLabel("Status, \(short)")
                            .accessibilityHint("Asks before saving: a new status changes the recorded progress.")
                            // Body-size text and 44-point targets (caption-size controls were about 16 points tall).
                            HStack {
                                Button { recordingStatus = nil; recordingGoal = goal } label: {
                                    Label("Record progress", systemImage: "chart.line.uptrend.xyaxis")
                                        .frame(minHeight: 44).contentShape(Rectangle())
                                }
                                .buttonStyle(.borderless)
                                .accessibilityLabel("Record progress, \(short)")
                                .accessibilityInputLabels(["Record progress", "Record progress, \(short)"])
                                Spacer()
                                NavigationLink { GoalHistoryView(goalId: goal.id, goalText: goal.specificGoal) } label: {
                                    Text("History").frame(minWidth: 44, minHeight: 44).contentShape(Rectangle())
                                }
                                .accessibilityLabel("History, \(short)")
                                .accessibilityInputLabels(["History", "History, \(short)"])
                            }
                        }
                        .padding(.vertical, 3)
                        // VoiceOver: the goal is one stop, read in full; Record progress is the
                        // double-tap, and every control is on the Actions rotor (a status change
                        // still asks first, as the status control does).
                        .accessibilityElement(children: VoiceOverRows.children)
                        .accessibilityLabel(goalSummary(goal))
                        .accessibilityAction { recordingStatus = nil; recordingGoal = goal }
                        .accessibilityActions {
                            Button("Record progress") { recordingStatus = nil; recordingGoal = goal }
                            if !savingGoals.contains(goal.id) {
                                ForEach(goalStatuses.filter { $0 != goal.status }, id: \.self) { status in
                                    Button("Mark \(status)") { statusBinding(for: goal).wrappedValue = status }
                                }
                            }
                            Button("History") { historyGoal = goal }
                        }
                    }
                    Button { showingAddGoal = true } label: { Label("Add goal", systemImage: "plus") }
                }
                Section(notesHeader) {
                    if !notesLoaded {
                        if error == nil { ProgressView("Loading notes…") }
                    } else if trackNotes.isEmpty {
                        Text("No notes on this track yet.").foregroundStyle(Color.secondaryText)
                    }
                    ForEach(trackNotes) { note in
                        let open = expandedNotes.contains(note.id)
                        VStack(alignment: .leading, spacing: 3) {
                            HStack(alignment: .top) {
                                // The note's text is one element; Edit stays its own (as the expense
                                // rows' Delete does), so the subject is not read twice.
                                VStack(alignment: .leading, spacing: 3) {
                                    Text(note.subject).font(.body.weight(.semibold))
                                    HStack(spacing: 6) {
                                        Text(note.noteType).font(.caption).foregroundStyle(Color.secondaryText)
                                        if let fmt = note.noteFormat, fmt != "free" {
                                            NoteFormatBadge(format: fmt)
                                        }
                                        Text(formatDateTime(note.createdAt)).font(.caption).foregroundStyle(Color.secondaryText)
                                    }
                                    // A preview that opens in full (VoiceOver reads the whole note either way).
                                    Text(note.body).font(.callout).lineLimit(open ? nil : 6)
                                }
                                .accessibilityElement(children: .combine)
                                Spacer()
                                if note.canEdit {
                                    Button { editingTrackNote = note } label: {
                                        Image(systemName: "pencil")
                                            .frame(minWidth: 44, minHeight: 44)
                                            .contentShape(Rectangle())
                                    }
                                    .buttonStyle(.borderless)
                                    .accessibilityLabel("Edit note: \(note.subject)")
                                }
                            }
                            if mayTruncate(note.body, lines: 6, accessibilitySize: typeSize.isAccessibilitySize) {
                                NoteExpandButton(subject: note.subject, open: open) {
                                    if open { expandedNotes.remove(note.id) } else { expandedNotes.insert(note.id) }
                                }
                            }
                        }
                        .padding(.vertical, 2)
                        // VoiceOver: the whole note is one stop (its full text, whatever the preview
                        // shows); Edit and the preview's Show full note are on the Actions rotor.
                        .accessibilityElement(children: VoiceOverRows.children)
                        .accessibilityLabel(noteSummary(note))
                        .accessibilityActions {
                            if note.canEdit {
                                Button("Edit note") { editingTrackNote = note }
                            }
                            if mayTruncate(note.body, lines: 6, accessibilitySize: typeSize.isAccessibilitySize) {
                                Button(open ? "Show less" : "Show full note") {
                                    if open { expandedNotes.remove(note.id) } else { expandedNotes.insert(note.id) }
                                }
                            }
                        }
                    }
                    // The server sends the newest 50 notes on the track: say so rather than imply that
                    // is all of them.
                    if notesLoaded && notesCapped {
                        Text(trackNotesTotal.map { "Showing the latest \(trackNotes.count) of \($0) notes on this track. The rest are on the website's track page." }
                             ?? "Showing the latest \(trackNotes.count) notes on this track. Older ones are on the website's track page.")
                            .font(.callout).foregroundStyle(Color.secondaryText)
                    }
                    if trackClientId != nil {
                        Button { showingTrackNote = true } label: { Label("Add note on this track", systemImage: "square.and.pencil") }
                    }
                }
                Section(expensesLoaded ? "Billable expenses (\(expenses.count))" : "Billable expenses") {
                    if !expensesLoaded {
                        if error == nil { ProgressView("Loading expenses…") }
                    } else if expenses.isEmpty {
                        Text("No expenses logged on this track.").foregroundStyle(Color.secondaryText)
                            .accessibilityFocused($focusedExpense, equals: 0)
                    }
                    ForEach(expenses) { expense in
                        HStack {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("\(expense.amountText) · \(expense.description)").font(.body)
                                // Billed or not is said in words, not shown by the Delete button alone.
                                Text("\(formatDate(expense.expenseDate)) · \(expense.isBilled ? "On an invoice" : "Not yet billed")")
                                    .font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            Spacer()
                            // An expense already on an invoice stays (the invoice totals would no longer match).
                            if !expense.isBilled {
                                // The darker alert red (system red text was 3.5:1), on a 44-point target.
                                Button(role: .destructive) { deletingExpense = expense } label: {
                                    Text("Delete").foregroundStyle(Color.alertRed)
                                        .frame(minWidth: 44, minHeight: 44).contentShape(Rectangle())
                                }
                                .buttonStyle(.borderless)
                                .disabled(deletingExpenseBusy)
                                .accessibilityLabel("Delete expense: \(expense.summary)")
                                .accessibilityInputLabels(["Delete", "Delete expense"])
                            }
                        }
                        .padding(.vertical, 2)
                        // VoiceOver: one stop per expense; Delete (which asks first) is on the
                        // Actions rotor.
                        .accessibilityElement(children: VoiceOverRows.children)
                        .accessibilityLabel("\(expense.amountText), \(expense.description). \(formatDate(expense.expenseDate)). \(expense.isBilled ? "On an invoice" : "Not yet billed")")
                        .accessibilityFocused($focusedExpense, equals: expense.id)
                        .accessibilityActions {
                            if !expense.isBilled && !deletingExpenseBusy {
                                Button("Delete expense", role: .destructive) { deletingExpense = expense }
                            }
                        }
                    }
                }
                Section("Actions") {
                    // Opens with this track chosen (its unsaved entry, if any, comes back with it).
                    if api.can("clients") {
                        Button { showingLogHours = true } label: {
                            Label("Log hours on this track", systemImage: "clock.badge.checkmark")
                        }
                    }
                    // Sending the report needs the 'reports' permission (as on the website).
                    if api.can("reports") {
                        Button { showingReport = true } label: {
                            Label("Email progress report to the vocational rehabilitation (VR) counselor", systemImage: "paperplane")
                        }
                    }
                    Button { showingExpense = true } label: {
                        Label("Log billable expense", systemImage: "dollarsign.circle")
                    }
                    NavigationLink { AmendmentView(trackId: track.id) } label: {
                        Label("Authorization burn-down & amendment", systemImage: "chart.line.downtrend.xyaxis")
                    }
                }
            }
            // The banner is the list's first row: a failed goal status, expense delete or reload from
            // further down would otherwise leave it off screen, where a List never draws it (or says
            // it). Scrolled into view once it exists, then VoiceOver is put on it.
            .onChange(of: error) { message in
                guard message != nil else { return }
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(Self.errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    errorFocused = true
                }
            }
        }
        .navigationTitle(savedTrack?.title ?? track.title)
        .toolbar {
            // The assistant opened here knows which client and track are on screen.
            if api.aiHas("voice") && api.can("clients") {
                ToolbarItem(placement: .primaryAction) {
                    Button { showingVoice = true } label: { Label("Voice assistant", systemImage: "mic.fill") }
                        .disabled(trackClientId == nil)
                }
            }
            ToolbarItem(placement: .secondaryAction) {
                Button { showingEditTrack = true } label: { Label("Edit track", systemImage: "pencil") }
            }
        }
        .sheet(isPresented: $showingVoice) {
            VoiceView(context: trackClientId.map {
                VoiceContext(clientId: $0, clientName: clientName, trackId: track.id, trackTitle: track.title)
            })
        }
        .sheet(isPresented: $showingEditTrack, onDismiss: announceSheetResult) {
            TrackEditSheet(trackId: track.id, clientId: nil, clientName: clientName) { message, saved in
                if let saved { savedTrack = saved }
                sheetDone(message)
            }
        }
        .navigationDestination(isPresented: Binding(get: { historyGoal != nil }, set: { if !$0 { historyGoal = nil } })) {
            if let goal = historyGoal {
                GoalHistoryView(goalId: goal.id, goalText: goal.specificGoal)
            }
        }
        .confirmationDialog("Delete the expense \(deletingExpense?.summary ?? "")? It moves to the trash, where an administrator can restore it for 30 days.",
                            isPresented: Binding(get: { deletingExpense != nil }, set: { if !$0 { deletingExpense = nil } }),
                            titleVisibility: .visible, presenting: deletingExpense) { expense in
            Button("Delete expense", role: .destructive) { Task { await deleteExpense(expense) } }
            Button("Cancel", role: .cancel) {}
        }
        // A status change rewrites the goal's recorded percent (Achieved is 100%, Not Started 0%)
        // and adds a history entry, so it is confirmed rather than saved by one stray tap; reopening
        // an achieved goal opens Record progress only when the person asks for it here.
        .confirmationDialog(goalStatusQuestion,
                            isPresented: Binding(get: { pendingGoalStatus != nil }, set: { if !$0 { pendingGoalStatus = nil } }),
                            titleVisibility: .visible, presenting: pendingGoalStatus) { pending in
            if pending.goal.status == "Achieved" && pending.status == "In Progress" {
                Button("Record progress") {
                    recordingStatus = pending.status
                    recordingGoal = pending.goal
                }
            } else {
                Button("Mark \(pending.status)") { saveGoalStatus(pending.goal, pending.status) }
            }
            Button("Keep \(pending.goal.status)", role: .cancel) {}
        }
        .refreshable { await load() }
        .task { await load() }
        // Each sheet hands back what to tell the person: shown here, and said once it has closed.
        .sheet(isPresented: $showingTrackNote, onDismiss: announceSheetResult) {
            if let cid = trackClientId {
                AddNoteSheet(clientId: cid, tracks: [track], initialTrackId: track.id, existing: nil) { message in
                    sheetDone(message); Task { await load() }
                }
            }
        }
        .sheet(item: $editingTrackNote, onDismiss: announceSheetResult) { note in
            if let cid = trackClientId {
                AddNoteSheet(clientId: cid, tracks: [track], existing: note) { message in
                    sheetDone(message); Task { await load() }
                }
            }
        }
        // The form says its own results and stays open for another entry.
        .sheet(isPresented: $showingLogHours) {
            LogHoursView(initialTrackId: track.id)
        }
        .sheet(isPresented: $showingAddGoal, onDismiss: announceSheetResult) {
            AddGoalSheet(trackId: track.id) { sheetDone("Goal added."); Task { await load() } }
        }
        .sheet(isPresented: $showingExpense, onDismiss: announceSheetResult) {
            ExpenseSheet(trackId: track.id) { message in sheetDone(message); Task { await loadExpenses() } }
        }
        .sheet(isPresented: $showingReport, onDismiss: announceSheetResult) {
            SendReportSheet(trackId: track.id, clientName: clientName) { message in sheetDone(message) }
        }
        .sheet(item: $recordingGoal, onDismiss: announceSheetResult) { goal in
            GoalProgressSheet(goal: goal, initialStatus: recordingStatus) { message in sheetDone(message); Task { await load() } }
        }
    }

    /// track_notes sends at most the newest 50.
    private var notesCapped: Bool {
        if let total = trackNotesTotal { return total > trackNotes.count }
        return trackNotes.count >= 50
    }

    private var notesHeader: String {
        guard notesLoaded else { return "Case notes for this track" }
        if notesCapped { return "Case notes for this track (latest \(trackNotes.count)\(trackNotesTotal.map { " of \($0)" } ?? ""))" }
        return "Case notes for this track (\(trackNotes.count))"
    }

    private func sheetDone(_ message: String) {
        confirmation = message
        sheetResult = message
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    /// A goal row as VoiceOver reads it: position, objective, how it is measured, target,
    /// then progress and status.
    private func goalSummary(_ goal: Goal) -> String {
        var parts = ["\(goalPosition(goal)): \(goal.specificGoal)"]
        if !goal.measurableMetric.isEmpty { parts.append("Measured by: \(goal.measurableMetric)") }
        if let target = goal.targetDate { parts.append("Target: \(formatDate(target))") }
        parts.append("\(goal.progressPct ?? 0) percent, \(goal.status)")
        return parts.joined(separator: ". ")
    }

    /// A track note as VoiceOver reads it: subject, type, format, date, then the whole text.
    private func noteSummary(_ note: Note) -> String {
        var parts = [note.subject, note.noteType]
        if let fmt = note.noteFormat, fmt != "free" { parts.append("Format: \(noteFormatDescription(fmt))") }
        parts.append(formatDateTime(note.createdAt))
        parts.append(note.body)
        return parts.joined(separator: ". ")
    }

    /// "Goal 2 of 5": the position the user hears in the list, not the database id.
    private func goalPosition(_ goal: Goal) -> String {
        guard let i = goals.firstIndex(where: { $0.id == goal.id }) else { return "Goal" }
        return "Goal \(i + 1) of \(goals.count)"
    }

    private var goalStatusQuestion: String {
        guard let pending = pendingGoalStatus else { return "" }
        let short = "\(goalPosition(pending.goal)): \(shortGoalText(pending.goal.specificGoal))"
        let pct = pending.goal.progressPct ?? 0
        switch pending.status {
        case "Achieved": return "Mark “\(short)” achieved? Its progress is recorded as 100%."
        case "Not Started": return "Mark “\(short)” Not Started? Its progress is reset from \(pct)% to 0%."
        default:
            return pending.goal.status == "Achieved"
                ? "Reopen “\(short)”? Record progress opens so you can enter how complete it is now."
                : "Mark “\(short)” In Progress? Its progress stays at \(pct)%."
        }
    }

    /// The control shows the saved status; picking another asks first (see the dialog).
    private func statusBinding(for goal: Goal) -> Binding<String> {
        Binding(
            get: { goal.status },
            set: { newValue in
                guard !savingGoals.contains(goal.id), newValue != goal.status else { return }
                pendingGoalStatus = PendingGoalStatus(goal: goal, status: newValue)
            }
        )
    }

    private func saveGoalStatus(_ goal: Goal, _ newValue: String) {
        guard !savingGoals.contains(goal.id) else { return }
        savingGoals.insert(goal.id)
        Task {
            defer { savingGoals.remove(goal.id) }
            confirmation = nil
            error = nil
            do {
                let _: OKResponse = try await api.call("update_goal", body: ["id": goal.id, "status": newValue])
                confirmation = newValue == "Achieved" ? "Goal marked achieved 🎉" : "Goal updated."
                announce(confirmation!)
                await load()
            } catch {
                showFormError(error.localizedDescription, in: &self.error)
            }
        }
    }

    private func load() async {
        do {
            error = nil
            let response: GoalsResponse = try await api.call("goals", query: ["track_id": String(track.id)])
            goals = response.goals
            goalsLoaded = true
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
        // Preserve already loaded notes while making a failed refresh visible.
        do {
            let r: TrackNotesResponse = try await api.call("track_notes", query: ["track_id": String(track.id)])
            trackNotes = r.notes
            trackNotesTotal = r.notesTotal
            trackClientId = r.clientId
            notesLoaded = true
        } catch {
            // The same failure as the goals' (no connection, say) is not said twice.
            if self.error != error.localizedDescription { showFormError(error.localizedDescription, in: &self.error) }
        }
        await loadExpenses()
    }

    /// Leaves an error already on screen in place (a refused delete says more than a failed reload).
    private func loadExpenses() async {
        do {
            let r: TrackExpensesResponse = try await api.call("expenses", query: ["track_id": String(track.id)])
            expenses = r.expenses
            expensesLoaded = true
        } catch {
            if self.error == nil { showFormError(error.localizedDescription, in: &self.error) }
        }
    }

    private func deleteExpense(_ expense: TrackExpense) async {
        deletingExpenseBusy = true
        defer { deletingExpenseBusy = false }
        confirmation = nil
        error = nil
        // The row shown in its place: the next one, else the one before it.
        let index = expenses.firstIndex(where: { $0.id == expense.id })
        let rest = expenses.filter { $0.id != expense.id }
        let next = index.flatMap { rest.isEmpty ? nil : rest[min($0, rest.count - 1)].id }
        var message: String?
        do {
            let _: OKResponse = try await api.call("delete_expense", body: ["id": expense.id])
            message = "Expense of \(expense.amountText) on \(formatDate(expense.expenseDate)) moved to the trash."
            confirmation = message
        } catch {
            // The server says why: not found (already deleted), or already on an invoice.
            showFormError(error.localizedDescription, in: &self.error)
        }
        // Either way the list shows what the server now holds.
        await loadExpenses()
        // The deleted row took the Delete button VoiceOver was on with it: VoiceOver is put on
        // the row in its place, then the result is said (said before, it was cut off).
        guard let message else { return }
        try? await Task.sleep(nanoseconds: 150_000_000)   // let the reloaded rows appear first
        if let next, expenses.contains(where: { $0.id == next }) { focusedExpense = next }
        else if expenses.isEmpty && expensesLoaded { focusedExpense = 0 }
        announceAfterDismiss(message)
    }
}

struct TrackNotesResponse: Decodable {
    let clientId: Int
    let notes: [Note]
    /// All of the track's notes (`notes` holds the newest 50); nil from a server that does not report it.
    var notesTotal: Int?
}

/// Authorization burn-down with a counselor amendment letter (web v2.52 parity).
struct AmendmentView: View {
    @EnvironmentObject var api: API
    let trackId: Int
    @State private var data: AmendmentResponse?
    /// The counselor email on file waits for staff to confirm it on the client's record, so the
    /// letter is not offered for emailing to it (see AmendmentReply).
    @State private var counselorUnconfirmed = false
    @State private var letter = ""
    /// The letter as the server last supplied it, to tell whether the person has edited it.
    @State private var serverLetter = ""
    @State private var confirmingRedraft = false
    @State private var error: String?
    @State private var aiBusy = false
    /// After a failure, VoiceOver is put on the error banner (see the list's onChange).
    @AccessibilityFocusState private var errorFocused: Bool
    private static let errorRow = "amendmentError"

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden)
                        .id(Self.errorRow)
                        .accessibilityFocused($errorFocused)
                }
                if let d = data {
                    // When mileage and expenses use the funds up before the hours, the server's run-out
                    // date and the letter are about funds: the figures here say the same.
                    let byFunds = d.fundsLimit == true && d.suggestFunds != nil
                    Section("Burn-down") {
                        LabeledContent("Hours used", value: String(format: "%.2f of %.2f hours (%d%%)", d.worked, d.authorized, d.pct))
                        LabeledContent("Hours remaining", value: String(format: "%.2f hours", d.remaining))
                        if let auth = d.fundsAuthorized, auth > 0, let used = d.fundsUsed, let left = d.fundsRemaining {
                            LabeledContent("Funds used", value: "\(money(used)) of \(money(auth))" + (d.fundsPct.map { " (\($0)%)" } ?? ""))
                            LabeledContent("Funds remaining", value: money(left))
                        }
                        if byFunds {
                            Text("The authorized funds, not the hours, are the limit: mileage and expenses are using them up faster, so they run out before the hours do.")
                                .font(.callout)
                            if let spend = d.weeklySpend, spend > 0 { LabeledContent("Recent spending", value: "\(money(spend)) per week") }
                        } else if d.weeklyRate > 0 {
                            LabeledContent("Recent pace", value: String(format: "%.2f hours per week", d.weeklyRate))
                        }
                        if let r = d.runOut { LabeledContent(byFunds ? "Projected run-out of funds" : "Projected run-out of hours", value: r) }
                        if let e = d.expiration { LabeledContent(d.expired == true ? "Expired on" : "Expires", value: e) }
                        if d.expired == true { Text("The authorization has already expired, so no run-out date is projected.").font(.callout) }
                        LabeledContent("Suggested top-up", value: byFunds
                                       ? "\(money(d.suggestFunds ?? 0)) in additional authorized funds"
                                       : "\(d.suggestAdd) hours")
                    }
                    Section("Amendment request letter") {
                        if !d.aiNote.isEmpty { Text(d.aiNote).font(.caption).foregroundStyle(Color.secondaryText) }
                        TextField("Letter", text: $letter, axis: .vertical).lineLimit(8...30)
                        if api.aiHas("draft") {
                            // A new draft from the track's facts (the server never sees this text),
                            // so it replaces the letter: asked first when the person has edited it.
                            Button {
                                if letter != serverLetter { confirmingRedraft = true } else { Task { await draftAI() } }
                            } label: {
                                Label(aiBusy ? "Drafting…" : "Draft with AI", systemImage: "sparkles")
                            }.disabled(aiBusy)
                        }
                        // Only to a counselor email staff have confirmed: one typed into the public
                        // referral form may not be the counselor's, and the letter names the client.
                        if counselorUnconfirmed {
                            Text("Counselor email not confirmed — confirm it on the client's profile before emailing this letter.")
                                .font(.callout)
                        } else if let email = d.counselorEmail, !email.isEmpty {
                            let subject = "Authorization amendment request — \(d.client)"
                            if let url = mailto(email, subject: subject, body: letter) {
                                Link(destination: url) { Label("Email counselor", systemImage: "paperplane") }
                            }
                        }
                        // The letter names the client: the copy stays on this phone (not Handoff),
                        // is cleared from the clipboard after 10 minutes, and at sign-out.
                        Button {
                            api.copyProtected(letter)
                            announce("Letter copied. The copy is cleared from the clipboard in 10 minutes.")
                        } label: {
                            Label("Copy letter", systemImage: "doc.on.doc")
                        }
                        .disabled(letter.isEmpty)
                    }
                } else if error == nil { ProgressView() }
            }
            // The banner is the list's first row: a failed AI draft, asked for from the letter further
            // down, would otherwise leave it off screen, where a List never draws it (or says it).
            // Scrolled into view once it exists, then VoiceOver is put on it.
            .onChange(of: error) { message in
                guard message != nil else { return }
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(Self.errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    errorFocused = true
                }
            }
        }
        .navigationTitle("Burn-down")
        .navigationBarTitleDisplayMode(.inline)
        .confirmationDialog("Replace the letter with a new AI draft? Your edits to it will be lost.",
                            isPresented: $confirmingRedraft, titleVisibility: .visible) {
            Button("Replace with a new draft", role: .destructive) { Task { await draftAI() } }
            Button("Keep my letter", role: .cancel) {}
        }
        .task { await load() }
    }

    /// Everything but ASCII letters, digits and -._~ is percent-encoded (the address keeps its
    /// @): URLComponents.path left "&" and "," in an address as they were, so a stored address
    /// holding them could add recipients or headers to the email.
    private func mailto(_ to: String, subject: String, body: String) -> URL? {
        let plain = CharacterSet(charactersIn: "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~")
        guard let address = to.trimmingCharacters(in: .whitespacesAndNewlines)
                .addingPercentEncoding(withAllowedCharacters: plain.union(CharacterSet(charactersIn: "@"))),
              !address.isEmpty,
              let subjectText = subject.addingPercentEncoding(withAllowedCharacters: plain),
              let bodyText = body.addingPercentEncoding(withAllowedCharacters: plain) else { return nil }
        return URL(string: "mailto:\(address)?subject=\(subjectText)&body=\(bodyText)")
    }

    private func show(_ reply: AmendmentReply) {
        data = reply.data; letter = reply.data.letter; serverLetter = reply.data.letter
        counselorUnconfirmed = reply.counselorUnconfirmed
    }

    private func load() async {
        do {
            error = nil
            let reply: AmendmentReply = try await api.call("amendment", query: ["track": String(trackId)])
            show(reply)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    private func draftAI() async {
        aiBusy = true; error = nil
        do {
            let reply: AmendmentReply = try await api.call("amendment", body: ["track": trackId, "ai_draft": 1])
            show(reply)
            announce(reply.data.aiNote.isEmpty ? "Letter updated." : reply.data.aiNote)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        aiBusy = false
    }
}

/// The amendment reply, with whether its counselor email waits for staff to confirm it: 3.0.21
/// servers leave an unconfirmed address out of counselor_email and say so with
/// counselor_email_unconfirmed; a server that sends the address with counselor_verified false
/// is treated the same. Neither flag (an older server) leaves the address as sent.
private struct AmendmentReply: Decodable {
    let data: AmendmentResponse
    let counselorUnconfirmed: Bool
    private enum Keys: String, CodingKey { case counselorEmailUnconfirmed, counselorVerified }
    init(from d: Decoder) throws {
        let reply = try AmendmentResponse(from: d)
        data = reply
        let c = try? d.container(keyedBy: Keys.self)
        // A flag as a boolean or as 0/1, depending on how the server writes it.
        func flag(_ key: Keys) -> Bool? {
            if let value = try? c?.decodeIfPresent(Bool.self, forKey: key) { return value }
            if let number = try? c?.decodeIfPresent(Int.self, forKey: key) { return number != 0 }
            return nil
        }
        let hasAddress = !(reply.counselorEmail ?? "").trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
        counselorUnconfirmed = flag(.counselorEmailUnconfirmed) == true
            || (flag(.counselorVerified) == false && hasAddress)
    }
}

struct GoalProgressSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let goal: Goal
    let onDone: (String) -> Void
    @State private var status: String
    @State private var pct: Double
    /// The person moved the slider: from then on a status change leaves the percent alone.
    @State private var pctEntered = false
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false
    /// The status the sheet opened with, to tell whether anything was changed.
    private let startStatus: String

    init(goal: Goal, initialStatus: String? = nil, onDone: @escaping (String) -> Void) {
        self.goal = goal; self.onDone = onDone
        let start = initialStatus ?? goal.status
        startStatus = start
        _status = State(initialValue: start)
        _pct = State(initialValue: GoalProgressSheet.followingPct(status: start, goal: goal))
    }

    /// The percent a status change implies until one is entered, as on the website: Achieved is
    /// 100%; Not Started (from another status) is 0%; otherwise the goal's current percent.
    private static func followingPct(status: String, goal: Goal) -> Double {
        if status == "Achieved" { return 100 }
        if status == "Not Started" && goal.status != "Not Started" { return 0 }
        return Double(goal.progressPct ?? 0)
    }

    /// Reopened from Achieved: its 100% no longer holds, so a percent must be entered first.
    private var needsNewPct: Bool {
        goal.status == "Achieved" && status == "In Progress" && !pctEntered
    }
    private static let reopenMessage = "This goal was 100% complete while Achieved. Set Progress percent to how complete it is now, then save."

    private var hasChanges: Bool { !note.isEmpty || pctEntered || status != startStatus }

    private var pctBinding: Binding<Double> {
        Binding(get: { pct }, set: { pct = $0.rounded(); pctEntered = true })
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                Text(goal.specificGoal).font(.callout)
                Picker("Status", selection: $status) { ForEach(goalStatuses, id: \.self) { Text($0) } }
                    // The slider follows the status until a percent is entered (Achieved is always
                    // stored as 100%, so the slider says so too).
                    .onChange(of: status) { newStatus in
                        if newStatus == "Achieved" || !pctEntered {
                            pct = GoalProgressSheet.followingPct(status: newStatus, goal: goal)
                        }
                        if needsNewPct { announce(Self.reopenMessage) }
                    }
                VStack(alignment: .leading) {
                    // Any whole percent, as on the website: the slider for a quick setting, the
                    // stepper to adjust it one percent at a time.
                    Stepper(value: pctBinding, in: 0...100, step: 1) {
                        Text("Progress: \(Int(pct))%")
                    }
                    .voiceOverAdjustable("Progress percent, in steps of 1", value: "\(Int(pct)) percent",
                                         binding: pctBinding, in: 0...100, step: 1)
                    Slider(value: pctBinding, in: 0...100, step: 1)
                        .accessibilityLabel("Progress percent")
                        .accessibilityValue("\(Int(pct)) percent")
                    if needsNewPct {
                        Text(Self.reopenMessage).font(.callout)
                    }
                }
                CaptionedField(label: "Progress note (optional)", text: $note, lines: 2...5)
            }
            .navigationTitle("Record progress")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) { Button("Save") { Task { await save() } }.disabled(busy || needsNewPct) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this progress entry?",
                                 asking: $askingDiscard) { dismiss() }
            .onAppear { if needsNewPct { announce(Self.reopenMessage) } }
        }
    }
    private func save() async {
        busy = true; error = nil
        do {
            // The confirmation states the percent the server stored, which can differ from the
            // slider (Achieved is always 100%).
            struct Stored: Decodable {
                let progressPct: Int?
                private enum CodingKeys: String, CodingKey { case progressPct }
                init(from d: Decoder) throws {
                    progressPct = try? d.container(keyedBy: CodingKeys.self).decodeIfPresent(Int.self, forKey: .progressPct)
                }
            }
            var body: [String: Any] = ["id": goal.id, "status": status, "progress_pct": Int(pct), "note": note]
            // Reopening an achieved goal and deliberately setting 100% again keeps it there (the
            // website's "Record again to keep it at 100%"); without this the server refuses it.
            if goal.status == "Achieved" && status != "Achieved" && pctEntered && Int(pct) == 100 {
                body["reopen_confirmed"] = 1
            }
            let stored: Stored = try await api.call("update_goal", body: body)
            // Said by the track screen once this sheet has closed (closing it would cut it off).
            onDone("Progress recorded — \(stored.progressPct ?? Int(pct))%."); dismiss()
        } catch { showFormError(error.localizedDescription, in: &self.error); busy = false }
    }
}

struct GoalHistoryView: View {
    @EnvironmentObject var api: API
    let goalId: Int
    let goalText: String
    @State private var history: [GoalProgressEntry] = []
    @State private var loaded = false
    @State private var error: String?

    var body: some View {
        List {
            // Which goal this is: the title stays short, and goal text can be long.
            Text(goalText).font(.callout.weight(.semibold))
                .accessibilityLabel("Goal: \(goalText)")
                .accessibilityAddTraits(.isHeader)
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if !loaded && error == nil {
                ProgressView("Loading history…")
            } else if loaded && history.isEmpty {
                Text("No progress recorded yet.").foregroundStyle(Color.secondaryText)
            }
            ForEach(Array(history.enumerated()), id: \.offset) { _, h in
                VStack(alignment: .leading, spacing: 2) {
                    Text("\(h.progressPct)% · \(h.status)").font(.body.weight(.semibold))
                    Text("\(formatDateTime(h.createdAt))\(h.byName != nil ? " · \(h.byName!)" : "")").font(.caption).foregroundStyle(Color.secondaryText)
                    if let n = h.note, !n.isEmpty { Text(n).font(.callout) }
                }.padding(.vertical, 2)
            }
        }
        .navigationTitle("Progress history")
        .navigationBarTitleDisplayMode(.inline)
        .task {
            do { error = nil; let r: GoalProgressResponse = try await api.call("goal_progress", query: ["goal": String(goalId)]); history = r.history; loaded = true }
            catch { showFormError(error.localizedDescription, in: &self.error) }
        }
    }
}

struct AddGoalSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let trackId: Int
    let onSaved: () -> Void

    @State private var specific = ""
    @State private var metric = ""
    @State private var focus = ""
    @State private var hasTarget = false
    @State private var targetDate = Calendar.current.date(byAdding: .month, value: 2, to: Date()) ?? Date()
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false

    private var hasChanges: Bool { !specific.isEmpty || !metric.isEmpty || !focus.isEmpty || hasTarget }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                // Thousands of templates: searched by area and keyword, 50 at a time, each shown
                // in full — never one long menu.
                NavigationLink {
                    GoalTemplatePicker { template in
                        specific = template.specificGoal
                        metric = template.measurableMetric
                        focus = template.focusArea ?? ""
                        // Said once the picker has closed: the pop moves VoiceOver focus, which
                        // cut off an announcement made as it closed.
                        announceAfterDismiss("Template filled in. Review the goal before adding it.")
                    }
                } label: {
                    Label("Start from a template…", systemImage: "text.badge.plus")
                }
                // Each name stays visible once a template fills the fields in.
                CaptionedField(label: "Specific objective (required)", text: $specific, prompt: "Specific objective", lines: 2...5)
                CaptionedField(label: "Measurable criteria (required)", text: $metric, prompt: "Measurable criteria", lines: 2...5)
                CaptionedField(label: "Focus area", text: $focus)
                Toggle("Target date", isOn: $hasTarget)
                if hasTarget {
                    DatePicker("Target", selection: $targetDate, displayedComponents: .date)
                }
            }
            .navigationTitle("Add goal")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing field: save() says which one is needed.
                    Button("Add") { Task { await save() } }
                        .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this goal?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }

    private func save() async {
        if specific.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Specific objective: say what the client will be able to do.", in: &error)
            return
        }
        if metric.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Measurable criteria: say how progress on this goal is measured.", in: &error)
            return
        }
        busy = true; error = nil
        do {
            let _: OKResponse = try await api.call("add_goal", body: [
                "track_id": trackId, "specific_goal": specific, "measurable_metric": metric,
                "focus_area": focus, "target_date": hasTarget ? APIDate.day(targetDate) : "",
            ])
            // "Goal added." is said by the track screen once this sheet has closed.
            onSaved()
            dismiss()
        } catch APIError.network {
            // The request may have arrived: it isn't known whether the goal was added, and
            // adding it again would list it twice.
            showFormError("The connection dropped before the server answered, so it isn't known whether this goal was added. Check the track's goals for “\(specific.trimmingCharacters(in: .whitespacesAndNewlines))” before adding it again.", in: &self.error)
            busy = false
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }
}

/// Goal templates searched the way the website's picker works (track.php): an area plus words
/// that must each match, at most 50 at a time, each template shown in full.
struct GoalTemplatePicker: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let onPick: (GoalTemplate) -> Void

    @State private var query = ""
    @State private var category = ""
    @State private var categories: [String] = []
    @State private var results: [GoalTemplate] = []
    @State private var more = false
    @State private var loaded = false
    @State private var error: String?
    @State private var requestID = UUID()
    /// The search that waits for typing to pause. Submitting cancels it: firing after the
    /// submitted search started, it replaced that search, whose result count was then not said.
    @State private var debounce: Task<Void, Never>?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if !categories.isEmpty {
                Picker("Area", selection: $category) {
                    Text("All areas").tag("")
                    ForEach(categories, id: \.self) { Text($0).tag($0) }
                }
                .pickerStyle(.navigationLink)
            }
            Section {
                if !loaded && error == nil {
                    ProgressView("Loading templates…")
                } else if loaded && results.isEmpty {
                    Text("No templates match. Try other words or another area.").foregroundStyle(Color.secondaryText)
                }
                ForEach(results) { template in
                    Button {
                        onPick(template)
                        dismiss()
                    } label: {
                        VStack(alignment: .leading, spacing: 3) {
                            Text(template.specificGoal).foregroundStyle(.primary)
                            Text(template.measurableMetric).font(.callout).foregroundStyle(Color.secondaryText)
                            Text(template.category).font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                    .accessibilityHint("Fills in the goal form with this template.")
                }
            } header: {
                Text(loaded ? "Matching templates (\(results.count)\(more ? "+" : ""))" : "Matching templates")
            } footer: {
                if more { Text("Showing the first 50 matches. Add words or choose an area to narrow them.") }
            }
        }
        .navigationTitle("Goal templates")
        .navigationBarTitleDisplayMode(.inline)
        .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Tool, task or keyword")
        .onSubmit(of: .search) {
            debounce?.cancel()
            Task { await load(announceResult: true) }
        }
        .onChange(of: query) { typed in
            // Searches once typing pauses, not on every keystroke. The list updates without a
            // word: a count spoken after each pause cut off VoiceOver's echo of the letter just
            // typed. The count is said when the search is submitted or the area changes.
            debounce?.cancel()
            debounce = Task {
                try? await Task.sleep(nanoseconds: 400_000_000)
                guard !Task.isCancelled, typed == query else { return }
                await load(announceResult: false)
            }
        }
        .onChange(of: category) { _ in Task { await load(announceResult: true) } }
        .task { if !loaded { await load(announceResult: false) } }
    }

    private func load(announceResult: Bool) async {
        let q = query.trimmingCharacters(in: .whitespacesAndNewlines)
        let area = category
        let current = UUID()
        requestID = current
        do {
            error = nil
            var params: [String: String] = [:]
            if !q.isEmpty { params["q"] = q }
            if !area.isEmpty { params["category"] = area }
            let response: GoalTemplatesResponse = try await api.call("goal_templates", query: params)
            guard requestID == current else { return }   // a newer search replaced this one
            var matching = response.templates
            if response.more == nil {
                // A server that predates search sends every template: filter and cap them here
                // the same way (every word must match the objective, area or focus).
                let words = q.split(whereSeparator: \.isWhitespace).prefix(6).map(String.init)
                matching = matching.filter { t in
                    (area.isEmpty || t.category == area) && words.allSatisfy { w in
                        [t.specificGoal, t.category, t.focusArea ?? ""].contains {
                            $0.range(of: w, options: [.caseInsensitive, .diacriticInsensitive]) != nil
                        }
                    }
                }
                if categories.isEmpty { categories = Array(Set(response.templates.map(\.category))).sorted() }
            }
            results = Array(matching.prefix(50))
            more = (response.more ?? false) || matching.count > 50
            if let list = response.categories { categories = list }
            loaded = true
            if announceResult {
                announce(results.isEmpty ? "No templates match." : "\(results.count)\(more ? " or more" : "") templates.")
            }
        } catch {
            guard requestID == current else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

struct ExpenseSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let trackId: Int
    let onDone: (String) -> Void

    @State private var date = Date()
    @State private var descriptionText = ""
    @State private var amount = ""
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false
    /// One key for this expense, sent on every try (see save).
    @State private var idemKey = UUID().uuidString
    /// The server's authorization problem with this expense (expired, not yet started, or the
    /// track not Active), shown with the switch that logs it anyway, as Log hours does. Offered
    /// only after the server named it, and withdrawn when the date changes.
    @State private var authorizationIssue: String?
    @State private var authorizationAck = false

    private var hasChanges: Bool { !descriptionText.isEmpty || !amount.isEmpty }

    /// The end of add_expense's refusal of an expense outside the authorization (3.0.21
    /// servers): the app answers it with the "Log it anyway" switch instead.
    private static let authorizationConfirmSuffix = "Log it on the website to confirm."

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                // Just after the error banner (the next thing VoiceOver reaches from it), with the
                // problem it answers next to it, as the website's expense form offers it.
                if let authorizationIssue {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(authorizationIssue).font(.callout)
                        Toggle("Log it anyway (the authorization is being renewed or amended)", isOn: $authorizationAck)
                    }
                }
                // Up to today: the server refuses an expense dated in the future.
                DatePicker("Date", selection: $date, in: ...Date(), displayedComponents: .date)
                CaptionedField(label: "Description (required)", text: $descriptionText, prompt: "Description")
                CaptionedField(label: "Amount in US dollars (required)", text: $amount, prompt: "12.50", keyboard: .decimalPad)
                Text("Included on the next invoice generated for this authorization.")
                    .font(.caption).foregroundStyle(Color.secondaryText)
            }
            .navigationTitle("Log expense")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing or mistyped field: save() says what is wrong instead.
                    Button("Log") { Task { await save() } }
                        .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this expense?",
                                 asking: $askingDiscard) { dismiss() }
            // The authorization problem was for the date it was sent with.
            .onChange(of: date) { _ in clearAuthorizationIssue() }
        }
    }

    private func clearAuthorizationIssue() {
        // The banner telling the person to use the switch goes with it.
        if let issue = authorizationIssue, error?.hasPrefix(issue) == true { error = nil }
        authorizationIssue = nil
        authorizationAck = false
    }

    private func save() async {
        if descriptionText.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Description: say what the expense was for.", in: &error)
            return
        }
        if amount.trimmingCharacters(in: .whitespaces).isEmpty {
            showFormError("Amount: enter the amount in dollars, like 12.50.", in: &error)
            return
        }
        // A billable amount: anything that isn't a plain number is reported, never sent as 0.
        guard let amountText = checkedAmount(amount), let value = Double(amountText) else {
            showFormError(amountFormatMessage("Amount"), in: &error)
            return
        }
        // The server refuses $0.00 with a message about required fields; said here instead.
        guard value > 0 else {
            showFormError("Amount: enter more than $0.00.", in: &error)
            return
        }
        busy = true; error = nil
        do {
            // Sent as the checked text, exactly as typed, rather than through a Double. The same
            // key on every try of this expense, so a server that keeps it (3.0.21 and later)
            // recognises a retry after a lost reply instead of billing the expense twice.
            var body: [String: Any] = [
                "track_id": trackId, "date": APIDate.day(date),
                "description": descriptionText, "amount": amountText,
                "idem_key": idemKey,
            ]
            // Only once the person turned it on, in answer to the server's authorization problem.
            if authorizationIssue != nil && authorizationAck { body["authorization_ack"] = true }
            let result: SaveAck = try await api.call("add_expense", body: body)
            // Said by the track screen once this sheet has closed (closing it would cut it off).
            onDone(result.deduplicated == true
                   ? "This expense was already logged from an earlier try, so it was not logged twice."
                   : String(format: "Expense of $%.2f logged.", value))
            dismiss()
        } catch APIError.network {
            // The request may have arrived: it isn't known whether the expense was logged.
            showFormError("The connection dropped before the server answered, so it isn't known whether this expense was logged. Check Billable expenses on the track before logging it again.", in: &self.error)
            busy = false
        } catch APIError.server(let message) where message.hasSuffix(Self.authorizationConfirmSuffix) {
            // Outside the authorization (expired, not yet started, or the track not Active): the
            // server names the problem, and the expense is logged only once the person says to.
            let problem = message.dropLast(Self.authorizationConfirmSuffix.count).trimmingCharacters(in: .whitespaces)
            authorizationIssue = problem
            authorizationAck = false
            showFormError(problem + " To log this expense anyway (the authorization is being renewed or amended), turn on “Log it anyway” just below this message, then tap Log again.", in: &self.error)
            busy = false
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }
}

struct SendReportSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let trackId: Int
    let clientName: String
    let onDone: (String) -> Void

    @State private var intro = ""
    @State private var signature = ""
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false

    private var hasChanges: Bool { !intro.isEmpty || !signature.isEmpty }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                Text("Sends the full progress report for \(clientName) — authorization status, goals, and session log — to the VR counselor on file, with a receipt-confirmation link.")
                    .font(.callout)
                CaptionedField(label: "Personal message (optional)", text: $intro, lines: 2...5)
                Section("Electronic signature") {
                    // The exact name the server requires stays visible above the field once
                    // typing starts (a placeholder alone disappeared).
                    CaptionedField(label: "Signature: type your full name (\(api.userName))", text: $signature,
                                   prompt: api.userName)
                        .autocorrectionDisabled()
                    Text("The signature and timestamp are included on the report and recorded on the client's file.")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                }
            }
            .navigationTitle("Email report")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Send") { Task { await send() } }
                        .disabled(busy || signature.isEmpty)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this report message and signature?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }

    private func send() async {
        busy = true; error = nil
        do {
            // Tolerant: the report has gone out once the server says ok; a missing address
            // in the reply must not look like a failure (a retry would email it twice).
            struct SendResult: Decodable {
                let sentTo: String
                private enum CodingKeys: String, CodingKey { case ok, sentTo }
                init(from decoder: Decoder) throws {
                    let c = try decoder.container(keyedBy: CodingKeys.self)
                    guard (try? c.decode(Bool.self, forKey: .ok)) == true else {
                        throw DecodingError.dataCorruptedError(forKey: .ok, in: c, debugDescription: "not ok")
                    }
                    sentTo = (try? c.decode(String.self, forKey: .sentTo)) ?? ""
                }
            }
            let result: SendResult = try await api.call("send_report", body: [
                "track_id": trackId, "intro": intro, "signature": signature,
            ])
            // Said by the track screen once this sheet has closed: heard there, it is not sent twice.
            onDone(result.sentTo.isEmpty ? "Report signed and emailed." : "Report signed and emailed to \(result.sentTo).")
            dismiss()
        } catch APIError.network {
            // The request may have arrived and the email gone (the server sends it before it
            // answers): sending again could email the counselor twice.
            showFormError("The connection dropped before the server answered, so it isn't known whether the report was sent. Check the client's notes for “Training report emailed to VRC” before sending it again.", in: &self.error)
            busy = false
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }
}

// MARK: - Add or edit a training track (save_track, the website's track_edit.php)

struct TrackBundleChoice: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let categories: [String]
    /// False for the track's own bundle when it is retired or no longer offered for this client
    /// type: it stays on the track but cannot be chosen anew.
    let available: Bool
}
struct TrackCadenceChoice: Decodable, Hashable { let value: String; let label: String }

struct TrackFormValues: Decodable {
    let id: Int
    let clientId: Int
    let title: String
    let serviceType: String
    let rsaCategory: String?
    let bundleId: Int?
    let status: String
    let reportCadence: String
    let authNumber: String
    let fundingAgency: String
    let authorizedHours: Double
    let hourlyRate: Double
    let mileageRate: Double
    let startDate: String?
    let expirationDate: String?
}

struct TrackFormResponse: Decodable {
    let track: TrackFormValues?
    let clientName: String?
    let serviceTypes: [String]
    let statuses: [String]
    let cadences: [TrackCadenceChoice]
    let rsaCategories: [String]
    let bundles: [TrackBundleChoice]
}

struct TrackSaveResult: Decodable {
    let id: Int?
    let message: String?
    let duplicate: Bool?
    let track: TrackFormValues?
}

/// A number with up to `decimals` places, typed with a period or (one) comma; "" when blank,
/// nil when it is not in that shape.
private func trackNumberText(_ raw: String, whole: Int, decimals: Int) -> String? {
    var text = raw.trimmingCharacters(in: .whitespacesAndNewlines)
    if text.isEmpty { return "" }
    if !text.contains("."), text.filter({ $0 == "," }).count == 1 { text = text.replacingOccurrences(of: ",", with: ".") }
    return text.range(of: "^[0-9]{1,\(whole)}(\\.[0-9]{1,\(decimals)})?$", options: .regularExpression) != nil ? text : nil
}

private func trackNumberString(_ value: Double, decimals: Int) -> String {
    let text = String(format: "%.\(decimals)f", value)
    // 12.50 shows as 12.5 and 12.00 as 12, as typed.
    guard text.contains(".") else { return text }
    var trimmed = text
    while trimmed.hasSuffix("0") { trimmed.removeLast() }
    if trimmed.hasSuffix(".") { trimmed.removeLast() }
    return trimmed
}

/// Create a training track for a client, or edit one: the website's track form, with the same
/// validation, authorization checks and amendment rules (a new rate also re-prices work not yet
/// invoiced and Draft invoices, as the server reports). What was typed is kept on this phone
/// until it is saved.
struct TrackEditSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    /// The track to edit, or nil for a new track for `clientId`.
    let trackId: Int?
    let clientId: Int?
    let clientName: String
    let onSaved: (String, TrackFormValues?) -> Void

    @State private var form: TrackFormResponse?
    @State private var loadError: String?
    @State private var error: String?
    @State private var busy = false
    @State private var askingDiscard = false
    @State private var original: [String: String] = [:]

    @State private var title = ""
    @State private var serviceType = "Assistive Technology"
    @State private var bundleId = 0
    @State private var rsaCategory = ""
    @State private var status = "Active"
    @State private var cadence = "monthly"
    @State private var authNumber = ""
    @State private var fundingAgency = "State VR"
    @State private var hours = ""
    @State private var rate = ""
    @State private var mileage = "0"
    @State private var reason = ""
    @State private var startDate = ""
    @State private var expirationDate = ""

    private var draftKey: String { trackId.map { "track-edit-\($0)" } ?? "track-new-\(clientId ?? 0)" }

    private var fields: [String: String] {
        ["title": title, "service_type": serviceType, "bundle_id": String(bundleId), "rsa_category": rsaCategory, "status": status,
         "report_cadence": cadence, "auth_number": authNumber, "funding_agency": fundingAgency, "authorized_hours": hours,
         "hourly_rate": rate, "mileage_rate": mileage, "amendment_reason": reason, "start_date": startDate, "expiration_date": expirationDate]
    }
    private var hasChanges: Bool { form != nil && fields != original }

    var body: some View {
        NavigationStack {
            Group {
                if let form {
                    ErrorFocusForm(error: error) { formBody(form) }
                } else if let loadError {
                    List { ErrorBanner(message: loadError); Button("Try again") { Task { await load() } } }
                } else {
                    ProgressView("Loading the track form…")
                }
            }
            .navigationTitle(trackId == nil ? "Add training track" : "Edit track")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { DraftStore.clear(key: draftKey, owner: api.userId); dismiss() } }.disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Saving…" : (trackId == nil ? "Create" : "Save")) { Task { await save() } }
                        .disabled(busy || form == nil)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: trackId == nil ? "Discard this new track?" : "Discard your changes to this track?",
                                 asking: $askingDiscard) {
                DraftStore.clear(key: draftKey, owner: api.userId); dismiss()
            }
            // Only real edits are kept: the loaded server values are never saved as a "draft",
            // or a later open could restore them over newer values and re-price work.
            .onChange(of: fields) { _ in
                guard form != nil else { return }
                if fields != original { DraftStore.save(fields, key: draftKey, owner: api.userId) }
                else { DraftStore.clear(key: draftKey, owner: api.userId) }
            }
            .task { if form == nil { await load() } }
        }
    }

    @ViewBuilder private func formBody(_ form: TrackFormResponse) -> some View {
        Section {
            Text("For \(form.clientName ?? clientName). Fields marked required must be filled in.").font(.callout)
        }
        Section("Track") {
            CaptionedField(label: "Track title (required)", text: $title, prompt: "For example, Screen Reader Training — JAWS")
            Picker("Service type", selection: $serviceType) { ForEach(form.serviceTypes, id: \.self) { Text($0).tag($0) } }
            Picker("Service bundle", selection: $bundleId) {
                Text("No bundle: single RSA category").tag(0)
                ForEach(form.bundles) { b in Text(b.name).tag(b.id) }
            }
            if let chosen = form.bundles.first(where: { $0.id == bundleId }) {
                Text("\(chosen.name): \(chosen.categories.joined(separator: ", "))").font(.callout).foregroundStyle(Color.secondaryText)
                if !chosen.available {
                    Text("This bundle is no longer offered; it stays on this track until you choose another.").font(.callout)
                }
            } else {
                Picker("Single RSA service category", selection: $rsaCategory) {
                    ForEach(form.rsaCategories, id: \.self) { Text($0).tag($0) }
                }
            }
            Picker("Status", selection: $status) { ForEach(form.statuses, id: \.self) { Text($0).tag($0) } }
            Picker("Automatic progress reports", selection: $cadence) {
                ForEach(form.cadences, id: \.value) { Text($0.label).tag($0.value) }
            }
        }
        Section("Service authorization") {
            CaptionedField(label: "Authorization number (required)", text: $authNumber)
            CaptionedField(label: "Funding agency (required)", text: $fundingAgency)
            CaptionedField(label: "Authorized hours, total granted (required)", text: $hours, keyboard: .decimalPad)
            CaptionedField(label: "Hourly billing rate in dollars (required)", text: $rate, prompt: "For example, 85.00", keyboard: .decimalPad)
            CaptionedField(label: "Mileage rate in dollars per mile", text: $mileage, prompt: "0 when travel is not billable", keyboard: .decimalPad)
            if trackId != nil {
                CaptionedField(label: "Amendment reason", text: $reason, prompt: "Why the authorization changed", lines: 1...3)
                Text("A new hourly or mileage rate also applies to work on this track not yet invoiced, and re-prices its Draft invoices; submitted and paid invoices keep what they billed.")
                    .font(.callout).foregroundStyle(Color.secondaryText)
            }
            OptionalDayField(title: "Start date", text: $startDate)
            OptionalDayField(title: "Expiration date", text: $expirationDate)
        }
    }

    private func apply(_ values: [String: String]) {
        title = values["title"] ?? title
        serviceType = values["service_type"] ?? serviceType
        bundleId = Int(values["bundle_id"] ?? "") ?? bundleId
        rsaCategory = values["rsa_category"] ?? rsaCategory
        status = values["status"] ?? status
        cadence = values["report_cadence"] ?? cadence
        authNumber = values["auth_number"] ?? authNumber
        fundingAgency = values["funding_agency"] ?? fundingAgency
        hours = values["authorized_hours"] ?? hours
        rate = values["hourly_rate"] ?? rate
        mileage = values["mileage_rate"] ?? mileage
        reason = values["amendment_reason"] ?? reason
        startDate = values["start_date"] ?? startDate
        expirationDate = values["expiration_date"] ?? expirationDate
    }

    private func load() async {
        loadError = nil
        var query: [String: String] = [:]
        if let trackId { query["id"] = String(trackId) } else if let clientId { query["client_id"] = String(clientId) }
        do {
            let r: TrackFormResponse = try await api.call("save_track", query: query)
            if let t = r.track {
                apply(["title": t.title, "service_type": t.serviceType, "bundle_id": String(t.bundleId ?? 0),
                       "rsa_category": t.rsaCategory ?? (r.rsaCategories.first ?? ""), "status": t.status, "report_cadence": t.reportCadence,
                       "auth_number": t.authNumber, "funding_agency": t.fundingAgency,
                       "authorized_hours": trackNumberString(t.authorizedHours, decimals: 2), "hourly_rate": trackNumberString(t.hourlyRate, decimals: 2),
                       "mileage_rate": trackNumberString(t.mileageRate, decimals: 3), "start_date": t.startDate ?? "", "expiration_date": t.expirationDate ?? ""])
            } else {
                serviceType = r.serviceTypes.contains("Assistive Technology") ? "Assistive Technology" : (r.serviceTypes.first ?? "")
                rsaCategory = r.rsaCategories.contains("Rehabilitation Technology") ? "Rehabilitation Technology" : (r.rsaCategories.first ?? "")
            }
            original = fields
            form = r
            if let draft = DraftStore.load(key: draftKey, owner: api.userId), draft != original {
                apply(draft)
                announceAfterDismiss("Your unsaved changes to this track were restored.")
            }
        } catch { showFormError(error.localizedDescription, in: &loadError) }
    }

    private func save() async {
        guard let hoursText = trackNumberText(hours, whole: 5, decimals: 2) else {
            showFormError("Authorized hours: enter a number such as 40 or 12.5.", in: &error); return
        }
        guard let rateText = checkedAmount(rate, maxWholeDigits: 6) else {
            showFormError(amountFormatMessage("Hourly billing rate", maxWholeDigits: 6), in: &error); return
        }
        guard let mileageText = trackNumberText(mileage, whole: 3, decimals: 3) else {
            showFormError("Mileage rate: enter dollars per mile with up to 3 decimals, such as 0.655, or 0.", in: &error); return
        }
        busy = true; error = nil
        var body: [String: Any] = ["title": title, "service_type": serviceType, "bundle_id": bundleId, "status": status,
                                   "report_cadence": cadence, "auth_number": authNumber, "funding_agency": fundingAgency,
                                   "authorized_hours": hoursText, "hourly_rate": rateText, "mileage_rate": mileageText,
                                   "start_date": startDate, "expiration_date": expirationDate, "rsa_category": rsaCategory]
        if let trackId { body["id"] = trackId; body["amendment_reason"] = reason } else if let clientId { body["client_id"] = clientId }
        do {
            let r: TrackSaveResult = try await api.call("save_track", body: body)
            DraftStore.clear(key: draftKey, owner: api.userId)
            onSaved(r.message ?? (trackId == nil ? "Training track created." : "Training track updated."), r.track)
            dismiss()
        } catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}
