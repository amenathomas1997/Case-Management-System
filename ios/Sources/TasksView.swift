import SwiftUI

/// One follow-up task as the tasks action sends it (the website's Tasks page).
struct TaskItem: Decodable, Identifiable {
    let id: Int
    let title: String
    let details: String?
    let clientId: Int?
    let clientName: String?
    let referralId: Int?
    let referralName: String?
    let dueDate: String?
    /// Days until due by the server's date (negative: overdue); nil without a due date.
    let daysLeft: Int?
    let status: String
    let assignedTo: Int?
    let assignedName: String?
    let automatic: Bool
    let ageDays: Int
    let canOpenClient: Bool
    let canOpenReferral: Bool
    private enum CodingKeys: String, CodingKey {
        case id, title, details, clientId, clientName, referralId, referralName, dueDate, daysLeft, status
        case assignedTo, assignedName, automatic, ageDays, canOpenClient, canOpenReferral
    }
    // Tolerant decode: one odd task must not blank the whole list.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        details = try? c.decodeIfPresent(String.self, forKey: .details)
        clientId = try? c.decodeIfPresent(Int.self, forKey: .clientId)
        clientName = try? c.decodeIfPresent(String.self, forKey: .clientName)
        referralId = try? c.decodeIfPresent(Int.self, forKey: .referralId)
        referralName = try? c.decodeIfPresent(String.self, forKey: .referralName)
        dueDate = try? c.decodeIfPresent(String.self, forKey: .dueDate)
        daysLeft = try? c.decodeIfPresent(Int.self, forKey: .daysLeft)
        status = (try? c.decode(String.self, forKey: .status)) ?? "Open"
        assignedTo = try? c.decodeIfPresent(Int.self, forKey: .assignedTo)
        assignedName = try? c.decodeIfPresent(String.self, forKey: .assignedName)
        automatic = (try? c.decode(Bool.self, forKey: .automatic)) ?? false
        ageDays = (try? c.decode(Int.self, forKey: .ageDays)) ?? 0
        canOpenClient = (try? c.decode(Bool.self, forKey: .canOpenClient)) ?? false
        canOpenReferral = (try? c.decode(Bool.self, forKey: .canOpenReferral)) ?? false
    }
}

struct TaskStaffChoice: Decodable, Identifiable, Hashable { let id: Int; let name: String }

struct TasksResponse: Decodable {
    let tasks: [TaskItem]
    let truncated: Bool?
    let canAssign: Bool?
    let canLinkClient: Bool?
    let staff: [TaskStaffChoice]?
    /// Whether this account may delete a task (administrators, 4.36.0); nil from an older server.
    let canDelete: Bool?
}

/// complete_tasks's reply (4.36.0): how many were completed and the website's sentence.
struct TasksCompletedResult: Decodable { let completed: Int?; let message: String }

/// The website's "Filter: due date" choices (tasks.php), in its order.
let taskDueChoices: [(value: String, label: String)] = [
    ("any", "Any due date"), ("overdue", "Overdue"), ("today", "Due today (open)"),
    ("week", "Next 7 days (open)"), ("none", "No due date"),
]

/// "<title>, <client>, due <date>", as the website's Complete several tasks at once.
func bulkTaskLabel(_ t: TaskItem) -> String {
    t.title + (t.clientName.map { ", " + $0 } ?? "") + (t.dueDate.map { ", due " + formatDate($0) } ?? "")
}

struct TaskSaveResult: Decodable { let ok: Bool; let id: Int?; let message: String? }

extension TaskItem {
    var isOpen: Bool { status == "Open" }
    /// How the due date reads, in words (as the website's due note): never by colour alone.
    var dueText: String {
        guard let daysLeft else { return "no due date" }
        if !isOpen { return "due \(formatDate(dueDate))" }
        if daysLeft < 0 { return "\(-daysLeft) day\(daysLeft == -1 ? "" : "s") overdue" }
        if daysLeft == 0 { return "due today" }
        return "due in \(daysLeft) day\(daysLeft == 1 ? "" : "s"), \(formatDate(dueDate))"
    }
    /// The whole row as VoiceOver reads it.
    var spoken: String {
        var parts = [title, dueText]
        if let clientName { parts.append("Client: \(clientName)") }
        if referralId != nil { parts.append("Referral: \(referralName ?? "")") }
        parts.append("Assigned to \(assignedName ?? "anyone")")
        if !isOpen { parts.append("Done") }
        if automatic { parts.append("automatic, \(ageDays) day\(ageDays == 1 ? "" : "s") since created") }
        if let details, !details.isEmpty { parts.append(details) }
        return parts.joined(separator: ". ")
    }
}

/// The website's Tasks page: open tasks grouped as the action center groups them (Overdue,
/// Today, Upcoming, No due date), with add, edit and complete.
struct TasksView: View {
    @EnvironmentObject var api: API
    @State private var completing: Set<Int> = []
    @State private var data: TasksResponse?
    @State private var error: String?
    @State private var showAll = false
    @State private var search = ""
    @State private var adding = false
    @State private var editing: TaskItem?
    @State private var sheetResult: String?
    @State private var openClient: Int?
    @State private var openReferral: Int?
    /// The website's due-date filter (4.36.0): any, overdue, today, week or none.
    @State private var due: String
    /// A task waiting for its permanent-delete confirmation (administrators, 4.36.0).
    @State private var deleting: TaskItem?
    @State private var completingSeveral = false
    /// The open tasks as they were when Complete several tasks at once was opened: the sheet's
    /// choices never change under the person while it is open.
    @State private var bulkTasks: [TaskItem] = []

    init(due: String = "any") {
        _due = State(initialValue: taskDueChoices.contains { $0.value == due } ? due : "any")
    }
    /// After a task is completed or reopened, VoiceOver goes to that task, or to the one now in
    /// its place (-1: the empty-list text, -2: the error banner).
    @AccessibilityFocusState private var focusedTask: Int?
    private let errorRow = "tasksError"

    private struct Group: Identifiable { let id: String; let tasks: [TaskItem] }

    private var groups: [Group] {
        let tasks = data?.tasks ?? []
        let open = tasks.filter(\.isOpen)
        var out = [
            Group(id: "Overdue", tasks: open.filter { ($0.daysLeft ?? 1) < 0 }),
            Group(id: "Today", tasks: open.filter { $0.daysLeft == 0 }),
            Group(id: "Upcoming", tasks: open.filter { ($0.daysLeft ?? -1) > 0 }),
            Group(id: "No due date", tasks: open.filter { $0.daysLeft == nil }),
        ]
        if showAll { out.append(Group(id: "Done", tasks: tasks.filter { !$0.isOpen })) }
        return out.filter { !$0.tasks.isEmpty }
    }

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden)
                        .id(errorRow)
                        .accessibilityFocused($focusedTask, equals: -2)
                }
                // The website's due-date filter, visible as the list's first row (not in a menu),
                // a quiet picker: the result is said once the list has loaded.
                Picker("Filter: due date", selection: $due) {
                    ForEach(taskDueChoices, id: \.value) { choice in Text(choice.label).tag(choice.value) }
                }
                .onChange(of: due) { _ in Task { await load(announceFilter: true) } }
                if let data {
                    if data.tasks.isEmpty {
                        Text(filtered ? "No tasks match these filters." : (showAll ? "No tasks." : "No open tasks."))
                            .foregroundStyle(Color.secondaryText)
                            .accessibilityFocused($focusedTask, equals: -1)
                    }
                    if data.truncated == true {
                        Text("Showing the first 300 matches. Narrow your filters to find more specific tasks.").font(.callout)
                    }
                    if openShown.count >= 2 {
                        Button { openBulk() } label: {
                            Label("Complete several tasks at once", systemImage: "checklist.checked")
                        }
                    }
                    ForEach(groups) { group in
                        Section {
                            ForEach(group.tasks) { task in row(task) }
                        } header: {
                            Text("\(group.id) (\(group.tasks.count))").font(.headline).foregroundStyle(.primary).textCase(nil)
                                .accessibilityAddTraits(.isHeader)
                        }
                    }
                } else if error == nil {
                    ProgressView("Loading tasks…")
                }
            }
            .onChange(of: error) { message in
                guard let message else { return }
                claimFormErrorFocus(message)   // VoiceOver is put on the banner, which reads it
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    focusedTask = -2
                }
            }
        }
        .searchable(text: $search, prompt: "Search task titles and details")
        .onSubmit(of: .search) { Task { await load() } }
        .onChange(of: search) { text in if text.isEmpty { Task { await load() } } }
        .navigationTitle("Tasks")
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button { adding = true } label: { Label("Add task", systemImage: "plus") }
                    .disabled(data == nil)
            }
            ToolbarItem(placement: .secondaryAction) {
                Button(showAll ? "Show open tasks only" : "Show all tasks, including done") {
                    showAll.toggle()
                    Task { await load(announceView: true) }
                }
            }
            if openShown.count >= 2 {
                ToolbarItem(placement: .secondaryAction) {
                    Button("Complete several tasks at once") { openBulk() }
                }
            }
        }
        .confirmationDialog(deleting.map { "Delete the task “\($0.title)” permanently?" } ?? "Delete this task permanently?",
                            isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } }),
                            titleVisibility: .visible, presenting: deleting) { task in
            Button("Delete", role: .destructive) { Task { await delete(task) } }
            Button("Keep it", role: .cancel) {}
        } message: { _ in
            Text("This cannot be undone.")
        }
        .sheet(isPresented: $completingSeveral, onDismiss: announceSheetResult) {
            BulkCompleteSheet(tasks: bulkTasks, reload: { await load(); return openShown }) { message in
                sheetResult = message
                Task { await load() }
            }
        }
        .navigationDestination(isPresented: Binding(get: { openClient != nil }, set: { if !$0 { openClient = nil } })) {
            if let id = openClient { ClientDetailView(clientId: id) }
        }
        .navigationDestination(isPresented: Binding(get: { openReferral != nil }, set: { if !$0 { openReferral = nil } })) {
            if let id = openReferral { ReferralDetailView(referralId: id) }
        }
        .sheet(isPresented: $adding, onDismiss: announceSheetResult) {
            TaskEditSheet(existing: nil, canAssign: data?.canAssign ?? false, canLinkClient: data?.canLinkClient ?? false,
                          staff: data?.staff ?? []) { message in sheetResult = message; Task { await load() } }
        }
        .sheet(item: $editing, onDismiss: announceSheetResult) { task in
            TaskEditSheet(existing: task, canAssign: data?.canAssign ?? false, canLinkClient: data?.canLinkClient ?? false,
                          staff: data?.staff ?? []) { message in sheetResult = message; Task { await load() } }
        }
        .refreshable { await load() }
        .task { await load() }
    }

    private func openBulk() {
        bulkTasks = openShown
        completingSeveral = true
    }

    /// The open tasks on screen, in the order shown (for Complete several tasks at once).
    private var openShown: [TaskItem] { groups.filter { $0.id != "Done" }.flatMap(\.tasks) }

    /// A search or a due-date filter is narrowing the list.
    private var filtered: Bool {
        !search.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || due != "any"
    }

    @ViewBuilder private func row(_ task: TaskItem) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            VStack(alignment: .leading, spacing: 4) {
                Text(task.title).font(.body.weight(.semibold))
                Text(task.dueText).font(.callout)
                    .foregroundStyle((task.daysLeft ?? 0) < 0 && task.isOpen ? Color.alertRed : Color.secondaryText)
                if let client = task.clientName { Text("Client: \(client)").font(.caption).foregroundStyle(Color.secondaryText) }
                if task.referralId != nil { Text("Referral: \(task.referralName ?? "")").font(.caption).foregroundStyle(Color.secondaryText) }
                Text("Assigned to \(task.assignedName ?? "anyone")\(task.automatic ? " · automatic" : "")")
                    .font(.caption).foregroundStyle(Color.secondaryText)
                if let details = task.details, !details.isEmpty { Text(details).font(.callout) }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            // One VoiceOver stop per task, with its actions on the Actions rotor too.
            .accessibilityElement(children: VoiceOverRows.children)
            .accessibilityLabel(task.spoken)
            .accessibilityFocused($focusedTask, equals: task.id)
            .accessibilityAction { announceRowActions() }
            .accessibilityActions {
                if !queuedTasks.contains(task.id) {
                    Button(task.isOpen ? "Mark done" : "Reopen") { Task { await setDone(task, reopen: !task.isOpen) } }
                }
                if task.isOpen && !task.automatic { Button("Edit task") { editing = task } }
                if task.canOpenClient, let id = task.clientId { Button("Open client record") { openClient = id } }
                if task.canOpenReferral, let id = task.referralId { Button("Open referral") { openReferral = id } }
                if data?.canDelete == true { Button("Delete") { deleting = task } }
            }
            // 4.36.0: the buttons sit outside the row's one VoiceOver stop, so each is reached by
            // swiping as well as on the rotor (nothing is rotor-only), each naming the task.
            rowButtons(task)
        }
        .padding(.vertical, 2)
    }

    @ViewBuilder private func rowButtons(_ task: TaskItem) -> some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: 16) { rowButtonList(task) }
            VStack(alignment: .leading, spacing: 4) { rowButtonList(task) }
        }
    }

    @ViewBuilder private func rowButtonList(_ task: TaskItem) -> some View {
        if queuedTasks.contains(task.id) {
            Text("Waiting to send").font(.callout).foregroundStyle(Color.secondaryText)
        } else {
            Button(task.isOpen ? "Done" : "Reopen") { Task { await setDone(task, reopen: !task.isOpen) } }
                .buttonStyle(.borderless)
                .frame(minHeight: 44)
                .accessibilityLabel(task.isOpen ? "Done: \(task.title)" : "Reopen \(task.title)")
                .accessibilityInputLabels([task.isOpen ? "Done" : "Reopen"])
        }
        if task.isOpen && !task.automatic {
            Button("Edit") { editing = task }
                .buttonStyle(.borderless)
                .frame(minHeight: 44)
                .accessibilityLabel("Edit \(task.title)")
                .accessibilityInputLabels(["Edit"])
        }
        if task.canOpenClient, let id = task.clientId {
            Button("Open client record") { openClient = id }
                .buttonStyle(.borderless)
                .frame(minHeight: 44)
                .accessibilityLabel("Open client record for \(task.title)")
                .accessibilityInputLabels(["Open client record"])
        }
        if task.canOpenReferral, let id = task.referralId {
            Button("Open referral") { openReferral = id }
                .buttonStyle(.borderless)
                .frame(minHeight: 44)
                .accessibilityLabel("Open referral for \(task.title)")
                .accessibilityInputLabels(["Open referral"])
        }
        if data?.canDelete == true {
            Button("Delete", role: .destructive) { deleting = task }
                .buttonStyle(.borderless)
                .frame(minHeight: 44)
                .accessibilityLabel("Delete \(task.title)")
                .accessibilityInputLabels(["Delete"])
        }
    }

    /// delete_task (administrators): permanent, asked first; the website's sentences.
    private func delete(_ task: TaskItem) async {
        deleting = nil
        error = nil
        do {
            let r: TaskSaveResult = try await api.call("delete_task", method: "POST", body: ["id": task.id])
            await load()
            announceAfterDismiss(r.message ?? "Task deleted.")
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            await load()
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    /// Tasks completed or reopened without a signal, waiting in the offline queue (4.8.0).
    @State private var queuedTasks: Set<Int> = []

    private func setDone(_ task: TaskItem, reopen: Bool) async {
        // A double tap (or the button and the rotor action together) sends one request.
        guard !completing.contains(task.id) else { return }
        completing.insert(task.id); defer { completing.remove(task.id) }
        let order = groups.flatMap(\.tasks).map(\.id)
        let index = order.firstIndex(of: task.id) ?? 0
        error = nil
        do {
            var body: [String: Any] = ["id": task.id]
            if reopen { body["reopen"] = true }
            let result: TaskSaveResult = try await api.call("complete_task", body: body)
            await load()
            let now = groups.flatMap(\.tasks).map(\.id)
            if now.contains(task.id) { focusedTask = task.id }
            else if !now.isEmpty { focusedTask = now[min(index, now.count - 1)] }
            else { focusedTask = -1 }
            announceAfterDismiss(result.message ?? (reopen ? "Task reopened." : "Task completed."))
        } catch APIError.network {
            // No signal (4.8.0): kept on this phone and sent when it is back online; the server
            // treats a task already in that state as done.
            var body: [String: Any] = ["id": task.id, "idem_key": UUID().uuidString]
            if reopen { body["reopen"] = true }
            do {
                try api.enqueue("complete_task", body: body, label: (reopen ? "Reopen task: " : "Task done: ") + String(task.title.prefix(40)))
                queuedTasks.insert(task.id)
                announce("No signal. This is kept on this iPhone and saved when you are back online.")
            } catch { showFormError(error.localizedDescription, in: &self.error) }
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }

    private func load(announceView: Bool = false, announceFilter: Bool = false) async {
        let wantsAll = showAll
        let wantsDue = due
        var query = ["show": wantsAll ? "all" : "open"]
        let q = search.trimmingCharacters(in: .whitespacesAndNewlines)
        if !q.isEmpty { query["q"] = q }
        if wantsDue != "any" { query["due"] = wantsDue }
        do {
            error = nil
            let r: TasksResponse = try await api.call("tasks", query: query)
            guard wantsAll == showAll, wantsDue == due else { return }
            data = r
            if announceView { announce(wantsAll ? "Showing all tasks, including done." : "Showing open tasks only.") }
            else if announceFilter { announce(tasksFilterSummary(r.tasks.count, due: wantsDue)) }
            else if !q.isEmpty { announce("\(r.tasks.count) task\(r.tasks.count == 1 ? "" : "s") found.") }
        } catch {
            guard wantsAll == showAll, wantsDue == due else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// What a due-date filter found, said once: "3 tasks overdue." or "No tasks match these filters."
func tasksFilterSummary(_ count: Int, due: String) -> String {
    guard count > 0 else { return "No tasks match these filters." }
    let words = ["overdue": "overdue", "today": "due today", "week": "due in the next 7 days", "none": "with no due date"][due] ?? "shown"
    return "\(count) task\(count == 1 ? "" : "s") \(words)."
}

/// Complete several open tasks in one step, as the website's "Complete several tasks at once".
struct BulkCompleteSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    /// Loads the task list again and gives its open tasks (after the server completed none).
    let reload: () async -> [TaskItem]
    let onDone: (String) -> Void
    /// The choices, fixed when the sheet opened; replaced only by a reload after a refusal.
    @State private var tasks: [TaskItem]
    @State private var chosen: Set<Int> = []
    @State private var idem = IdemKey()
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section {
                    if tasks.isEmpty { Text("No open tasks.") }
                    ForEach(tasks) { task in
                        Toggle(bulkTaskLabel(task), isOn: Binding(get: { chosen.contains(task.id) },
                                                                  set: { on in if on { chosen.insert(task.id) } else { chosen.remove(task.id) } }))
                    }
                } header: {
                    Text("Open tasks").font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                }
                Section {
                    Button { Task { await complete() } } label: {
                        Text(busy ? "Completing…" : "Complete the chosen tasks (\(chosenIds.count))")
                            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    }
                    .disabled(busy)
                    .accessibilityLabel("Complete the chosen tasks (\(chosenIds.count))")
                }
            }
            .navigationTitle("Complete several tasks")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
            }
            .interactiveDismissDisabled(busy)
        }
    }

    init(tasks: [TaskItem], reload: @escaping () async -> [TaskItem], onDone: @escaping (String) -> Void) {
        _tasks = State(initialValue: tasks)
        self.reload = reload
        self.onDone = onDone
    }

    /// The chosen tasks still listed, in the order shown (the count and what is sent agree).
    private var chosenIds: [Int] { tasks.map(\.id).filter { chosen.contains($0) } }

    private func complete() async {
        guard !chosenIds.isEmpty else {
            showFormError("Nothing was completed: choose at least one open task.", in: &error)
            return
        }
        busy = true
        error = nil
        defer { busy = false }
        // In the order shown, so the same choice always sends the same request (and key).
        var body: [String: Any] = ["task_ids": chosenIds]
        body["idem_key"] = idem.key(for: body)
        do {
            let r: TasksCompletedResult = try await api.call("complete_tasks", method: "POST", body: body)
            idem.answered()
            onDone(r.message)
            dismiss()
        } catch APIError.server(let message) {
            // Nothing was completed (done or removed meanwhile): the list and choices are
            // brought up to date, and the server's sentence is said.
            idem.answered()
            tasks = await reload()
            chosen = chosen.intersection(tasks.map(\.id))
            showFormError(message, in: &self.error)
        } catch {
            idem.failed(error)
            showFormError(IdemKey.message(for: error), in: &self.error)
        }
    }
}

/// Add a task, or edit an open one (title, details, due date, client, and who it is for when
/// the account may assign tasks). Typed text is kept on this phone until it is saved.
struct TaskEditSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let existing: TaskItem?
    let canAssign: Bool
    let canLinkClient: Bool
    let staff: [TaskStaffChoice]
    let onSaved: (String) -> Void

    @State private var title = ""
    @State private var details = ""
    @State private var due = ""
    @State private var clientId: Int?
    /// 0 = anyone (unassigned).
    @State private var assignee = 0
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false
    @State private var started = false

    private var draftKey: String { existing.map { "task-\($0.id)" } ?? "task-new" }

    private var hasChanges: Bool {
        if let existing {
            return title != existing.title || details != (existing.details ?? "") || due != (existing.dueDate ?? "")
                || clientId != existing.clientId || assignee != (existing.assignedTo ?? 0)
        }
        return !(title + details).trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section("What needs doing? (required)") {
                    TextField("Task title", text: $title, axis: .vertical).lineLimit(1...3)
                        .accessibilityLabel("What needs doing, required")
                        .errorField("What needs doing")
                }
                Section("Details") {
                    TextField("Details", text: $details, axis: .vertical).lineLimit(2...6)
                }
                Section {
                    OptionalDayField(title: "Due date", text: $due)
                    if canLinkClient {
                        NewRecordClientPicker(title: "Client (optional)", noneLabel: "None",
                                              linkedId: existing?.clientId, linkedName: existing?.clientName, selection: $clientId)
                    }
                    if canAssign {
                        Picker("Assign to", selection: $assignee) {
                            Text("Anyone").tag(0)
                            ForEach(staff) { Text($0.name).tag($0.id) }
                        }
                    }
                }
            }
            .navigationTitle(existing == nil ? "Add task" : "Edit task")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { DraftStore.clear(key: draftKey, owner: api.userId); dismiss() } }.disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Saving…" : "Save") { Task { await save() } }.disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this task?", asking: $askingDiscard) {
                DraftStore.clear(key: draftKey, owner: api.userId); dismiss()
            }
            .onAppear(perform: start)
            .onChange(of: title) { _ in saveDraft() }
            .onChange(of: details) { _ in saveDraft() }
            .onChange(of: due) { _ in saveDraft() }
        }
    }

    private func start() {
        guard !started else { return }
        started = true
        if let existing {
            title = existing.title; details = existing.details ?? ""; due = existing.dueDate ?? ""
            clientId = existing.clientId; assignee = existing.assignedTo ?? 0
        } else if canAssign {
            assignee = api.userId   // the website's form preselects the signed-in person
        }
        if let draft = DraftStore.load(key: draftKey, owner: api.userId),
           !((draft["title"] ?? "") + (draft["details"] ?? "")).trimmingCharacters(in: .whitespacesAndNewlines).isEmpty,
           draft["title"] != title || draft["details"] != details || draft["due"] != due {
            title = draft["title"] ?? title
            details = draft["details"] ?? details
            due = draft["due"] ?? due
            announceAfterDismiss("Your unsaved task was restored.")
        }
    }

    private func saveDraft() {
        guard started else { return }
        // The task's own saved values are not a draft: keeping them could later restore
        // stale text over a newer website edit.
        let loaded = (existing?.title ?? "", existing?.details ?? "", existing?.dueDate ?? "")
        if (title, details, due) == loaded { DraftStore.clear(key: draftKey, owner: api.userId); return }
        DraftStore.save(["title": title, "details": details, "due": due], key: draftKey, owner: api.userId)
    }

    private func save() async {
        let trimmed = title.trimmingCharacters(in: .whitespacesAndNewlines)
        if trimmed.isEmpty { showFormError("The task needs a title: say what needs doing.", in: &error, field: "What needs doing"); return }
        busy = true; error = nil
        var body: [String: Any] = ["title": trimmed, "details": details, "due_date": due]
        if let existing { body["id"] = existing.id }
        // 4.29.0: an edit always says the client, 0 for None, so choosing None removes the link
        // (left out, the server keeps it). Only someone who can link clients sends it on an edit.
        if let clientId { body["client_id"] = clientId } else if existing != nil && canLinkClient { body["client_id"] = 0 }
        if canAssign { body["assigned_to"] = assignee }
        // A new task (4.28.0): a retry after a lost reply is recognised and not added twice.
        if existing == nil { body["idem_key"] = idem.key(for: body) }
        do {
            let result: TaskSaveResult = try await api.call("save_task", body: body)
            idem.answered()
            DraftStore.clear(key: draftKey, owner: api.userId)
            onSaved(result.message ?? (existing == nil ? "Task added." : "Task saved."))
            dismiss()
        } catch APIError.network where existing == nil {
            // The request may have arrived: it isn't known whether the task was added, and
            // adding it again would list it twice. (Saving an existing task again is harmless.)
            busy = false
            showFormError("The connection dropped before the server answered, so it isn't known whether this task was added. Check the task list for “\(trimmed)” before adding it again; your text is kept here.", in: &self.error)
        } catch { idem.failed(error); busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}
