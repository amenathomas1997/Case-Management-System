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
                if let data {
                    if data.tasks.isEmpty {
                        Text(search.isEmpty ? (showAll ? "No tasks." : "No open tasks.") : "No tasks match this search.")
                            .foregroundStyle(Color.secondaryText)
                            .accessibilityFocused($focusedTask, equals: -1)
                    }
                    if data.truncated == true {
                        Text("Showing the first 300 matches. Search to narrow the list.").font(.callout)
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
                guard message != nil else { return }
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

    @ViewBuilder private func row(_ task: TaskItem) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(task.title).font(.body.weight(.semibold))
            Text(task.dueText).font(.callout)
                .foregroundStyle((task.daysLeft ?? 0) < 0 && task.isOpen ? Color.alertRed : Color.secondaryText)
            if let client = task.clientName { Text("Client: \(client)").font(.caption).foregroundStyle(Color.secondaryText) }
            if task.referralId != nil { Text("Referral: \(task.referralName ?? "")").font(.caption).foregroundStyle(Color.secondaryText) }
            Text("Assigned to \(task.assignedName ?? "anyone")\(task.automatic ? " · automatic" : "")")
                .font(.caption).foregroundStyle(Color.secondaryText)
            if let details = task.details, !details.isEmpty { Text(details).font(.callout) }
            HStack(spacing: 16) {
                if queuedTasks.contains(task.id) {
                    Text("Waiting to send").font(.callout).foregroundStyle(Color.secondaryText)
                } else {
                    Button(task.isOpen ? "Done" : "Reopen") { Task { await setDone(task, reopen: !task.isOpen) } }
                        .buttonStyle(.borderless)
                        .frame(minHeight: 44)
                }
                if task.isOpen && !task.automatic {
                    Button("Edit") { editing = task }
                        .buttonStyle(.borderless)
                        .frame(minHeight: 44)
                }
            }
        }
        .padding(.vertical, 2)
        // One VoiceOver stop per task; Done / Reopen, Edit and the linked records are on the
        // Actions rotor.
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

    private func load(announceView: Bool = false) async {
        let wantsAll = showAll
        var query = ["show": wantsAll ? "all" : "open"]
        let q = search.trimmingCharacters(in: .whitespacesAndNewlines)
        if !q.isEmpty { query["q"] = q }
        do {
            error = nil
            let r: TasksResponse = try await api.call("tasks", query: query)
            guard wantsAll == showAll else { return }
            data = r
            if announceView { announce(wantsAll ? "Showing all tasks, including done." : "Showing open tasks only.") }
            else if !q.isEmpty { announce("\(r.tasks.count) task\(r.tasks.count == 1 ? "" : "s") found.") }
        } catch {
            guard wantsAll == showAll else { return }
            showFormError(error.localizedDescription, in: &self.error)
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
                }
                Section("Details") {
                    TextField("Details", text: $details, axis: .vertical).lineLimit(2...6)
                }
                Section {
                    OptionalDayField(title: "Due date", text: $due)
                    if canLinkClient {
                        NewRecordClientPicker(title: "Client (optional)", noneLabel: "None", selection: $clientId)
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
        if trimmed.isEmpty { showFormError("What needs doing: the task needs a title.", in: &error); return }
        busy = true; error = nil
        var body: [String: Any] = ["title": trimmed, "details": details, "due_date": due]
        if let existing { body["id"] = existing.id }
        if let clientId { body["client_id"] = clientId }
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
