import SwiftUI

struct ContractRow: Decodable, Identifiable {
    let id: Int; let name: String; let funder: String?; let amount: Double; let endDate: String?; let status: String; let spent: Double
    /// 4.38.0 servers: the website's percent spent (nil: no budget set), the open deliverables,
    /// and the end-date phrase (" — ended" / " — ending soon").
    let pct: Int?
    let openDeliverables: Int?
    let endsText: String?
    private enum CodingKeys: String, CodingKey { case id, name, funder, amount, endDate, status, spent, pct, openDeliverables, endsText }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        funder = try? c.decodeIfPresent(String.self, forKey: .funder)
        amount = (try? c.decode(Double.self, forKey: .amount)) ?? 0
        endDate = try? c.decodeIfPresent(String.self, forKey: .endDate)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        spent = (try? c.decode(Double.self, forKey: .spent)) ?? 0
        pct = try? c.decodeIfPresent(Int.self, forKey: .pct)
        openDeliverables = try? c.decodeIfPresent(Int.self, forKey: .openDeliverables)
        endsText = try? c.decodeIfPresent(String.self, forKey: .endsText)
    }
}
struct ContractsResponse: Decodable { let contracts: [ContractRow] }
struct ContractDeliverable: Decodable, Identifiable {
    let id: Int; let title: String; let dueDate: String?; let status: String
    /// 4.38.0 servers: the deliverable's notes, and whether it is overdue (the website's rule).
    let notes: String?
    let overdue: Bool
    private enum CodingKeys: String, CodingKey { case id, title, dueDate, status, notes, overdue }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        dueDate = try? c.decodeIfPresent(String.self, forKey: .dueDate)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        notes = try? c.decodeIfPresent(String.self, forKey: .notes)
        overdue = (try? c.decode(Bool.self, forKey: .overdue)) ?? false
    }
}
struct ContractSpend: Decodable, Identifiable {
    let id: Int; let spentOn: String; let amount: Double; let memo: String?
    /// 4.38.0 servers: who recorded it.
    let by: String?
    private enum CodingKeys: String, CodingKey { case id, spentOn, amount, memo, by }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        spentOn = (try? c.decode(String.self, forKey: .spentOn)) ?? ""
        amount = (try? c.decode(Double.self, forKey: .amount)) ?? 0
        memo = try? c.decodeIfPresent(String.self, forKey: .memo)
        by = try? c.decodeIfPresent(String.self, forKey: .by)
    }
}
struct ContractInfo: Decodable {
    let id: Int; let name: String; let funder: String?; let contractNumber: String?
    let amount: Double; let startDate: String?; let endDate: String?; let status: String
    let notes: String?
}
/// `spend` is the latest 100 entries; `spendTotal` is how many there are in all (nil from an
/// older server that does not say), while `spent` is always the full sum. 4.38.0 servers add the
/// website's percent spent, what remains, whether it is over budget and the end-date phrase.
struct ContractResponse: Decodable {
    let contract: ContractInfo; let spent: Double; let deliverables: [ContractDeliverable]; let spend: [ContractSpend]; let spendTotal: Int?
    let pct: Int?
    let remaining: Double?
    let overBudget: Bool?
    let daysText: String?
}

let deliverableStatuses = ["Pending", "In Progress", "Submitted", "Accepted"]

/// The statuses a deliverable can be moved to: every one but its own (4.38.0, as Android).
func deliverableStatusChoices(_ current: String) -> [String] { deliverableStatuses.filter { $0 != current } }

/// Money typed for a contract (4.38.0), as the website's contract_money_in(): "$50,000.00" or
/// "50000" (dollar sign, spaces and thousands commas dropped), up to 10 digits before the cents
/// and a period before them. Nil when it is not in that shape ("12,50" included); "" when blank.
func contractMoneyText(_ raw: String) -> String? {
    let text = moneyInput(raw)
    if text.isEmpty { return "" }
    return text.range(of: "^[0-9]{1,10}(\\.[0-9]{1,2})?$", options: .regularExpression) != nil ? text : nil
}

/// The website's sentences for contract money (contracts.php).
let contractBudgetHint = "Budget: Enter a dollar amount of 0 or more, for example 50000 or 50,000.00, with a period before the cents."
let contractSpendHint = "Amount: enter a dollar amount above 0, for example 1250 or 1,250.00, with a period before the cents."

/// The question before a deliverable or spend entry is removed (names the item).
func contractRemoveTitle(deliverable title: String) -> String { "Remove the deliverable \(title)?" }
func contractRemoveTitle(spendAmount amount: Double, on date: String) -> String { "Remove the spend entry \(money(amount)) on \(formatDate(date))?" }

/// A contract on the list, as one line after its name.
func contractRowLines(_ c: ContractRow) -> [String] {
    var spent = "\(money(c.spent)) of \(money(c.amount))"
    if let pct = c.pct { spent += " (\(pct)%)" }
    if let f = c.funder, !f.isEmpty { spent += " · \(f)" }
    var lines = [spent]
    if let e = c.endDate { lines.append("ends \(formatDate(e))" + ((c.endsText ?? "").isEmpty ? "" : " \(c.endsText ?? "")")) }
    if let n = c.openDeliverables { lines.append("\(n) open deliverable\(n == 1 ? "" : "s")") }
    return lines
}

/// A spend entry as one line: "{date}, {$x}, {memo}, by {name}".
func contractSpendLine(_ s: ContractSpend) -> String {
    var parts = [formatDate(s.spentOn), money(s.amount)]
    if let m = s.memo, !m.isEmpty { parts.append(m) }
    if let by = s.by, !by.isEmpty { parts.append("by \(by)") }
    return parts.joined(separator: ", ")
}

/// contract_update's reply (4.38.0: the website's sentence).
struct ContractOpReply: Decodable { let message: String? }

struct ContractsView: View {
    @EnvironmentObject var api: API
    @State private var rows: [ContractRow] = []
    /// Set once the list has loaded, so "No active contracts." is never claimed before then.
    @State private var loaded = false
    @State private var error: String?
    @State private var showNew = false
    /// 4.38.0: active or closed contracts, as the website's Show closed / Show active.
    @State private var show = "active"
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if api.serverAtLeast(4, 38) {
                Picker("Show", selection: $show) {
                    Text("Active contracts").tag("active")
                    Text("Closed contracts").tag("closed")
                }
            }
            if !loaded && error == nil { ProgressView("Loading contracts…") }
            if loaded && rows.isEmpty && error == nil { Text(show == "closed" ? "No closed contracts." : "No active contracts.") }
            ForEach(rows) { c in
                NavigationLink { ContractDetail(id: c.id, onChanged: { Task { await load() } }) } label: {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(c.name).font(.body.weight(.semibold))
                        // Primary, not secondary: grey caption text is about 3.4:1 on a white row.
                        ForEach(Array(contractRowLines(c).enumerated()), id: \.offset) { _, line in Text(line).font(.caption) }
                    }
                }
            }
        }
        .navigationTitle("Contracts")
        .modifier(ReadablePickers(inNavigationStack: true))
        .refreshable { await load() }
        .task { await load() }
        .onChange(of: show) { _ in Task { await load() } }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add contract") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) { ContractNewSheet { sheetResult = "Contract added."; Task { await load() } } }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        let wanted = show
        do {
            let r: ContractsResponse = try await api.call("contracts", query: wanted == "active" ? [:] : ["show": wanted])
            guard wanted == show else { return }
            error = nil; rows = r.contracts; loaded = true
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ContractDetail: View {
    @EnvironmentObject var api: API
    let id: Int
    let onChanged: () -> Void
    @State private var data: ContractResponse?
    @State private var error: String?
    @State private var notice: String?
    @State private var addDeliverable = false
    @State private var addSpend = false
    @State private var changeBudget = false
    @State private var removingDeliverable: ContractDeliverable?
    @State private var removingSpend: ContractSpend?
    @State private var busy = false
    @State private var opIdem = IdemKey()
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?

    private var manages: Bool { api.serverAtLeast(4, 38) }

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden).id("contractError") }
                if let notice { Text(notice).font(.callout.weight(.semibold)) }
                if let d = data {
                    Section {
                        if let f = d.contract.funder, !f.isEmpty { LabeledContent("Funder", value: f) }
                        if let n = d.contract.contractNumber, !n.isEmpty { LabeledContent("Contract number", value: n) }
                        if let s = d.contract.startDate { LabeledContent("Start date", value: formatDate(s)) }
                        if let e = d.contract.endDate {
                            LabeledContent("End date", value: formatDate(e) + ((d.daysText ?? "").isEmpty ? "" : " (\(d.daysText ?? ""))"))
                        }
                        LabeledContent("Budget", value: money(d.contract.amount))
                        spentRows(d)
                        if let notes = d.contract.notes, !notes.isEmpty { LabeledContent("Notes", value: notes) }
                    }
                    if manages {
                        Section {
                            Button("Change budget") { changeBudget = true }.frame(minHeight: 44)
                            if d.contract.status == "Closed" {
                                Button("Reopen contract") { Task { await op(["op": "set_active", "active": true], fallback: "Contract reopened.") } }
                                    .disabled(busy).frame(minHeight: 44)
                            } else {
                                Button("Close contract") { Task { await op(["op": "set_active", "active": false], fallback: "Contract closed.") } }
                                    .disabled(busy).frame(minHeight: 44)
                            }
                        }
                    }
                    Section("Deliverables") {
                        ForEach(d.deliverables) { del in deliverableRow(del) }
                        Button { addDeliverable = true } label: { Label("Add deliverable", systemImage: "plus") }
                    }
                    Section("Spend") {
                        // Said before the rows, so a list that adds up to less than Spent is not
                        // taken for a discrepancy.
                        if let total = d.spendTotal, total > d.spend.count {
                            Text("Showing the latest \(d.spend.count) of \(total) entries. The website lists them all.").font(.callout)
                        }
                        ForEach(d.spend) { s in
                            Text(contractSpendLine(s))
                            if manages {
                                Button("Remove", role: .destructive) { removingSpend = s }
                                    .disabled(busy)
                                    .accessibilityLabel("Remove the spend entry \(money(s.amount)) on \(formatDate(s.spentOn))")
                                    .frame(minHeight: 44)
                            }
                        }
                        Button { addSpend = true } label: { Label("Record spend", systemImage: "plus") }
                    }
                } else if error == nil {
                    ProgressView("Loading contract…")
                }
            }
            // The banner is the list's first row: a failed status change on a deliverable further
            // down would otherwise leave it off screen, where it is neither seen nor announced.
            .onChange(of: error) { if $0 != nil { proxy.scrollTo("contractError", anchor: .top) } }
        }
        .navigationTitle(data?.contract.name ?? "Contract")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .confirmationDialog(contractRemoveTitle(deliverable: removingDeliverable?.title ?? ""),
                            isPresented: Binding(get: { removingDeliverable != nil }, set: { if !$0 { removingDeliverable = nil } }),
                            titleVisibility: .visible) {
            Button("Remove", role: .destructive) {
                if let del = removingDeliverable {
                    Task { await op(["op": "delete_deliverable", "deliverable_id": del.id], fallback: "Deliverable removed.") }
                }
                removingDeliverable = nil
            }
            Button("Keep it", role: .cancel) { removingDeliverable = nil }
        }
        .confirmationDialog(contractRemoveTitle(spendAmount: removingSpend?.amount ?? 0, on: removingSpend?.spentOn ?? ""),
                            isPresented: Binding(get: { removingSpend != nil }, set: { if !$0 { removingSpend = nil } }),
                            titleVisibility: .visible) {
            Button("Remove", role: .destructive) {
                if let s = removingSpend {
                    Task { await op(["op": "delete_spend", "spend_id": s.id], fallback: "Spend entry removed.") }
                }
                removingSpend = nil
            }
            Button("Keep it", role: .cancel) { removingSpend = nil }
        }
        .sheet(isPresented: $addDeliverable, onDismiss: announceSheetResult) { ContractDeliverableSheet(contractId: id) { sheetResult = $0; Task { await load() } } }
        .sheet(isPresented: $addSpend, onDismiss: announceSheetResult) { ContractSpendSheet(contractId: id) { sheetResult = $0; Task { await load() } } }
        .sheet(isPresented: $changeBudget, onDismiss: announceSheetResult) {
            ContractBudgetSheet(contractId: id, current: data?.contract.amount ?? 0) { sheetResult = $0; Task { await load(); onChanged() } }
        }
    }

    /// Spent and Remaining: the server's percent and remainder (4.38.0); an older server's reply
    /// has neither, and they are worked out here as before.
    @ViewBuilder private func spentRows(_ d: ContractResponse) -> some View {
        if d.remaining != nil || d.pct != nil {
            LabeledContent("Spent", value: d.pct.map { "\(money(d.spent)) (\($0)%)" } ?? "\(money(d.spent)) (no budget set)")
            LabeledContent("Remaining", value: money(d.remaining ?? 0) + (d.overBudget == true ? " (over budget)" : ""))
            if let pct = d.pct { ProgressView(value: min(Double(pct), 100), total: 100).accessibilityLabel("Budget spent") }
        } else {
            // A server before 4.38.0: rounded down in whole cents, as on the website.
            let budgetCents = Int((d.contract.amount * 100).rounded())
            if budgetCents > 0 {
                let pct = Int((d.spent * 100).rounded()) * 100 / budgetCents
                LabeledContent("Spent", value: "\(money(d.spent)) (\(pct)%)")
                LabeledContent("Remaining", value: money(d.contract.amount - d.spent))
                ProgressView(value: min(Double(pct), 100), total: 100).accessibilityLabel("Budget spent")
            } else {
                LabeledContent("Spent", value: "\(money(d.spent)) (no budget set)")
                LabeledContent("Remaining", value: money(d.contract.amount - d.spent))
            }
        }
    }

    /// One deliverable as one VoiceOver stop; its status menu, the other statuses and Remove
    /// are on its Actions rotor and also visible after it (4.38.0).
    @ViewBuilder private func deliverableRow(_ del: ContractDeliverable) -> some View {
        let spoken = [del.title, del.dueDate.map { "due \(formatDate($0))" + (del.overdue ? ", overdue" : "") }, "Status: \(del.status)",
                      (del.notes ?? "").isEmpty ? nil : del.notes].compactMap { $0 }.joined(separator: ", ")
        VStack(alignment: .leading, spacing: 2) {
            Text(del.title)
            if let due = del.dueDate {
                Text("due \(formatDate(due))\(del.overdue ? ", overdue" : "")").font(.caption)
                    .foregroundStyle(del.overdue ? Color.alertRed : Color.primary)
            }
            Text("Status: \(del.status)").font(.caption)
            if let notes = del.notes, !notes.isEmpty { Text(notes).font(.caption) }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(spoken)
        .accessibilityActions {
            ForEach(deliverableStatusChoices(del.status), id: \.self) { s in
                Button("Mark \(s)") { Task { await setStatus(del, s) } }
            }
            if manages { Button("Remove") { removingDeliverable = del } }
        }
        Menu {
            ForEach(deliverableStatusChoices(del.status), id: \.self) { s in Button(s) { Task { await setStatus(del, s) } } }
        } label: {
            Text("Change status of \(del.title)").frame(minHeight: 44)
        }
        .disabled(busy)
        if manages {
            Button("Remove", role: .destructive) { removingDeliverable = del }
                .accessibilityLabel("Remove the deliverable \(del.title)")
                .disabled(busy)
                .frame(minHeight: 44)
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { data = try await api.call("contract", query: ["id": String(id)]); error = nil }
        catch { notice = nil; showFormError(error.localizedDescription, in: &self.error) }
    }
    private func setStatus(_ del: ContractDeliverable, _ s: String) async {
        await op(["op": "set_deliverable", "deliverable_id": del.id, "status": s], fallback: "\(del.title) marked \(s).")
    }
    /// One change to the contract; the server's sentence (or `fallback` from an older server) is
    /// shown and said once. contract_update is retried safely with the same key.
    private func op(_ body: [String: Any], fallback: String) async {
        guard !busy else { return }
        var b = body; b["contract_id"] = id
        b["idem_key"] = opIdem.key(for: b)
        busy = true
        // Cleared first so a repeat of the same failure is shown (and announced) again.
        error = nil; notice = nil
        do {
            let r: ContractOpReply = try await api.call("contract_update", body: b)
            opIdem.answered()
            let message = r.message ?? fallback
            notice = message
            announce(message)
            await load(); onChanged()
        } catch {
            opIdem.failed(error)
            showFormError(IdemKey.message(for: error, what: "the change"), in: &self.error)
        }
        busy = false
    }
}

struct ContractDeliverableSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let contractId: Int; let onDone: (String) -> Void
    /// Sent as idem_key (4.29.0), so a retry after a lost reply does not add the deliverable twice.
    @State private var idem = IdemKey()
    @State private var title = ""; @State private var hasDue = false; @State private var due = Date()
    @State private var notes = ""
    @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false
    private var hasChanges: Bool { !title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || hasDue || !notes.isEmpty }
    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                ContractCaptionedField(label: "Deliverable (required)", text: $title)
                Toggle("Due date", isOn: $hasDue)
                if hasDue { DatePicker("Due", selection: $due, displayedComponents: .date) }
                // 4.38.0: as the website's deliverable form.
                ContractCaptionedField(label: "Notes (optional; up to 500 characters)", shortName: "Notes", text: $notes)
            }
            .navigationTitle("Add deliverable").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the deliverable would still be added after the sheet had gone.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }.disabled(busy)
                }
                // Not dimmed when the title is empty: Add says what is missing instead.
                ToolbarItem(placement: .confirmationAction) { Button("Add") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this deliverable?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }
    private func submit() async {
        guard !title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { showFormError("Deliverable is required.", in: &error); return }
        guard notes.count <= 500 else { showFormError("Notes: keep the notes to 500 characters or fewer.", in: &error); return }
        busy = true; error = nil
        var b: [String: Any] = ["contract_id": contractId, "op": "add_deliverable", "title": title]
        if hasDue { b["due_date"] = APIDate.day(due) }
        let trimmed = notes.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.isEmpty { b["notes"] = trimmed }
        b["idem_key"] = idem.key(for: b)
        // The presenter confirms once the sheet has gone: closing it moves VoiceOver focus,
        // which would cut off an announcement made here.
        do {
            let r: ContractOpReply = try await api.call("contract_update", body: b)
            idem.answered(); onDone(r.message ?? "Deliverable added."); dismiss()
        }
        catch { idem.failed(error); showFormError(IdemKey.message(for: error, what: "the deliverable"), in: &self.error); busy = false }
    }
}

struct ContractSpendSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let contractId: Int; let onDone: (String) -> Void
    /// Sent as idem_key (4.29.0), so a retry after a lost reply does not count the spending twice.
    @State private var idem = IdemKey()
    @State private var amount = ""; @State private var date = Date(); @State private var memo = ""
    @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false
    /// The day the sheet opened with, so choosing another date counts as a change.
    @State private var openedDay = APIDate.day(Date())
    private var hasChanges: Bool {
        [amount, memo].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty } || APIDate.day(date) != openedDay
    }
    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                ContractCaptionedField(label: "Amount in dollars (required; for example 1250 or 1,250.00)", shortName: "Amount", text: $amount, keyboard: .decimalPad)
                DatePicker("Date", selection: $date, displayedComponents: .date)
                ContractCaptionedField(label: "Memo", text: $memo)
            }
            .navigationTitle("Record spend").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the spending would still be recorded after the sheet had gone,
                // and recorded again by someone who thought it was not.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }.disabled(busy)
                }
                // Not dimmed when the amount is empty: Save says what is missing instead.
                ToolbarItem(placement: .confirmationAction) { Button("Save") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this spending entry?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }
    private func submit() async {
        // 4.38.0: the website's money rule and sentence ("$1,250.00" accepted; 10 digits).
        guard let amountText = contractMoneyText(amount), !amountText.isEmpty, (Double(amountText) ?? 0) > 0 else {
            showFormError(contractSpendHint, in: &error); return
        }
        busy = true; error = nil
        var b: [String: Any] = ["contract_id": contractId, "op": "add_spend", "amount": amountText, "spent_on": APIDate.day(date), "memo": memo]
        b["idem_key"] = idem.key(for: b)
        var fields: [String: String] = [:]
        do {
            let r: ContractOpReply = try await api.call("contract_update", body: b, refusal: { fields = refusalFields($0) })
            idem.answered(); onDone(r.message ?? "Spending recorded."); dismiss()
        }
        catch APIError.network {
            // The request may have arrived: it isn't known whether the spending was recorded, and
            // saving it again would count the money twice against the contract.
            // The key is kept (4.29.0): saving again unchanged is recognised and not counted twice.
            showFormError("The connection dropped before the server answered, so it isn't known whether this spending was recorded. Check the contract's spending for $\(amountText) on \(date.formatted(date: .abbreviated, time: .omitted)) before saving it again.", in: &self.error)
            busy = false
        }
        catch {
            idem.failed(error)
            showFormError(error.localizedDescription, in: &self.error, field: fields["amount"] != nil ? "Amount" : nil)
            busy = false
        }
    }
}

/// Change a contract's budget (4.38.0, T11), as the website's "Budget (USD)" and "Save budget".
struct ContractBudgetSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let contractId: Int
    let current: Double
    let onDone: (String) -> Void
    @State private var amount = ""
    @State private var busy = false
    @State private var error: String?
    @State private var idem = IdemKey()
    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Text("The budget is now \(money(current)).")
                ContractCaptionedField(label: "Budget in dollars (required)", shortName: "Budget", text: $amount, keyboard: .decimalPad)
            }
            .navigationTitle("Change budget").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) { Button(busy ? "Saving…" : "Save budget") { Task { await submit() } }.disabled(busy) }
            }
        }
    }
    private func submit() async {
        guard let amountText = contractMoneyText(amount), !amountText.isEmpty else { showFormError(contractBudgetHint, in: &error); return }
        busy = true; error = nil
        var b: [String: Any] = ["contract_id": contractId, "op": "set_budget", "amount": amountText]
        b["idem_key"] = idem.key(for: b)
        var fields: [String: String] = [:]
        do {
            let r: ContractOpReply = try await api.call("contract_update", body: b, refusal: { fields = refusalFields($0) })
            idem.answered(); onDone(r.message ?? "Budget changed to \(money(Double(amountText) ?? 0))."); dismiss()
        } catch {
            idem.failed(error)
            showFormError(fields.isEmpty ? IdemKey.message(for: error, what: "the budget") : error.localizedDescription, in: &self.error,
                          field: fields["amount"] != nil ? "Budget" : nil)
            busy = false
        }
    }
}

struct ContractNewSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var name = ""; @State private var funder = ""; @State private var number = ""; @State private var amount = ""
    @State private var notes = ""
    @State private var hasStart = false; @State private var start = Date(); @State private var hasEnd = false; @State private var end = Date()
    @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false
    private var hasChanges: Bool {
        [name, funder, number, amount, notes].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty } || hasStart || hasEnd
    }
    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                // Captions that stay visible once the fields are filled in.
                ContractCaptionedField(label: "Name (required)", text: $name)
                ContractCaptionedField(label: "Funder", text: $funder)
                ContractCaptionedField(label: "Contract number", text: $number)
                ContractCaptionedField(label: "Budget in dollars (for example 50000 or 50,000.00)", shortName: "Budget", text: $amount, keyboard: .decimalPad)
                Toggle("Start date", isOn: $hasStart); if hasStart { DatePicker("Start", selection: $start, displayedComponents: .date) }
                Toggle("End date", isOn: $hasEnd); if hasEnd { DatePicker("End", selection: $end, displayedComponents: .date) }
                if api.serverAtLeast(4, 38) {
                    // 4.38.0 (owner decision 1): as the website's new-contract form.
                    ContractCaptionedField(label: "Notes (optional; up to 2,000 characters)", shortName: "Notes", text: $notes)
                }
            }
            .navigationTitle("New contract").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the contract would still be created after the sheet had gone.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }.disabled(busy)
                }
                // Not dimmed when the name is empty: Create says what is missing instead.
                ToolbarItem(placement: .confirmationAction) { Button("Create") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this new contract?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }
    private func submit() async {
        guard !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { showFormError("Name is required.", in: &error); return }
        guard let amountText = contractMoneyText(amount) else { showFormError(contractBudgetHint, in: &error); return }
        guard notes.count <= 2000 else { showFormError("Notes: Notes must be 2,000 characters or fewer.", in: &error); return }
        busy = true; error = nil
        var b: [String: Any] = ["name": name, "funder": funder, "contract_number": number, "amount": amountText]
        if hasStart { b["start_date"] = APIDate.day(start) }
        if hasEnd { b["end_date"] = APIDate.day(end) }
        let trimmed = notes.trimmingCharacters(in: .whitespacesAndNewlines)
        if !trimmed.isEmpty { b["notes"] = trimmed }
        b["idem_key"] = idem.key(for: b)
        var fields: [String: String] = [:]
        do { let _: OKResponse = try await api.call("add_contract", body: b, refusal: { fields = refusalFields($0) }); idem.answered(); onDone(); dismiss() }
        catch {
            idem.failed(error)
            let field = fields["amount"] != nil ? "Budget" : (fields["notes"] != nil ? "Notes" : nil)
            showFormError(fields.isEmpty ? IdemKey.message(for: error, what: "the contract") : error.localizedDescription, in: &self.error, field: field)
            busy = false
        }
    }
}

/// A text field whose name stays visible above it once it holds text (a placeholder alone
/// disappears). The field carries the same name for VoiceOver, so the caption is hidden.
private struct ContractCaptionedField: View {
    let label: String
    /// A short name Voice Control users can say ("Budget") when the label also carries the
    /// input format (3.3.2); the full label is still what VoiceOver reads.
    var shortName: String? = nil
    @Binding var text: String
    var keyboard: UIKeyboardType = .default
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(.caption).accessibilityHidden(true)
            TextField(label, text: $text).keyboardType(keyboard).accessibilityLabel(label)
                .accessibilityInputLabels(shortName.map { [$0, label] } ?? [label])
                // Errors start with the short name ("Budget: …"): shown and said with the field.
                .modifier(OptionalErrorField(name: shortName))
        }
        .modifier(RowID(id: shortName.map { FormFieldID(name: $0.lowercased()) }))
    }
}
