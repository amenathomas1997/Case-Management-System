import SwiftUI

struct ContractRow: Decodable, Identifiable {
    let id: Int; let name: String; let funder: String?; let amount: Double; let endDate: String?; let status: String; let spent: Double
    private enum CodingKeys: String, CodingKey { case id, name, funder, amount, endDate, status, spent }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        funder = try? c.decodeIfPresent(String.self, forKey: .funder)
        amount = (try? c.decode(Double.self, forKey: .amount)) ?? 0
        endDate = try? c.decodeIfPresent(String.self, forKey: .endDate)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        spent = (try? c.decode(Double.self, forKey: .spent)) ?? 0
    }
}
struct ContractsResponse: Decodable { let contracts: [ContractRow] }
struct ContractDeliverable: Decodable, Identifiable { let id: Int; let title: String; let dueDate: String?; let status: String }
struct ContractSpend: Decodable, Identifiable { let id: Int; let spentOn: String; let amount: Double; let memo: String? }
struct ContractInfo: Decodable {
    let id: Int; let name: String; let funder: String?; let contractNumber: String?
    let amount: Double; let startDate: String?; let endDate: String?; let status: String
}
/// `spend` is the latest 100 entries; `spendTotal` is how many there are in all (nil from an
/// older server that does not say), while `spent` is always the full sum.
struct ContractResponse: Decodable { let contract: ContractInfo; let spent: Double; let deliverables: [ContractDeliverable]; let spend: [ContractSpend]; let spendTotal: Int? }

let deliverableStatuses = ["Pending", "In Progress", "Submitted", "Accepted"]

struct ContractsView: View {
    @EnvironmentObject var api: API
    @State private var rows: [ContractRow] = []
    /// Set once the list has loaded, so "No active contracts." is never claimed before then.
    @State private var loaded = false
    @State private var error: String?
    @State private var showNew = false
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if !loaded && error == nil { ProgressView("Loading contracts…") }
            if loaded && rows.isEmpty && error == nil { Text("No active contracts.") }
            ForEach(rows) { c in
                NavigationLink { ContractDetail(id: c.id, onChanged: { Task { await load() } }) } label: {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(c.name).font(.body.weight(.semibold))
                        // Primary, not secondary: grey caption text is about 3.4:1 on a white row.
                        Text("\(money(c.spent)) of \(money(c.amount))\(c.funder != nil ? " · \(c.funder!)" : "")").font(.caption)
                        if let e = c.endDate { Text("ends \(formatDate(e))").font(.caption2) }
                    }
                }
            }
        }
        .navigationTitle("Contracts")
        .refreshable { await load() }
        .task { await load() }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add contract") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) { ContractNewSheet { sheetResult = "Contract added."; Task { await load() } } }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { error = nil; let r: ContractsResponse = try await api.call("contracts"); rows = r.contracts; loaded = true }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ContractDetail: View {
    @EnvironmentObject var api: API
    let id: Int
    let onChanged: () -> Void
    @State private var data: ContractResponse?
    @State private var error: String?
    @State private var addDeliverable = false
    @State private var addSpend = false
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden).id("contractError") }
                if let d = data {
                    Section {
                        // Rounded down in whole cents, as on the website, so 99.5% spent never reads as 100%.
                        let budgetCents = Int((d.contract.amount * 100).rounded())
                        LabeledContent("Budget", value: money(d.contract.amount))
                        if budgetCents > 0 {
                            let pct = Int((d.spent * 100).rounded()) * 100 / budgetCents
                            LabeledContent("Spent", value: "\(money(d.spent)) (\(pct)%)")
                            LabeledContent("Remaining", value: money(d.contract.amount - d.spent))
                            ProgressView(value: min(Double(pct), 100), total: 100).accessibilityLabel("Budget spent")
                        } else {
                            // As on the website: a share of a $0 budget would read as 0% spent.
                            LabeledContent("Spent", value: "\(money(d.spent)) (no budget set)")
                            LabeledContent("Remaining", value: money(d.contract.amount - d.spent))
                        }
                    }
                    Section("Deliverables") {
                        ForEach(d.deliverables) { del in
                            HStack {
                                VStack(alignment: .leading) {
                                    Text(del.title)
                                    if let due = del.dueDate { Text("due \(formatDate(due))").font(.caption) }
                                }
                                Spacer()
                                Menu {
                                    ForEach(deliverableStatuses, id: \.self) { s in Button(s) { Task { await update(["op": "set_deliverable", "deliverable_id": del.id, "status": s], done: "\(del.title) marked \(s).") } } }
                                } label: { Text(del.status).font(.caption.weight(.semibold)).foregroundStyle(Color.brand) }
                                // Says which deliverable it belongs to and what it does; the visible
                                // status stays a spoken input label for Voice Control.
                                .accessibilityLabel("Status of \(del.title)")
                                .accessibilityValue(del.status)
                                .accessibilityHint("Opens a menu to change the status.")
                                .accessibilityInputLabels([del.status, "Status of \(del.title)"])
                            }
                            // VoiceOver: one stop per deliverable (title, due date, status); each
                            // other status from the menu is a "Mark …" action on the Actions rotor.
                            .accessibilityElement(children: .ignore)
                            .accessibilityLabel([del.title, del.dueDate.map { "due \(formatDate($0))" }, "Status: \(del.status)"]
                                .compactMap { $0 }.joined(separator: ", "))
                            .accessibilityActions {
                                ForEach(deliverableStatuses.filter { $0 != del.status }, id: \.self) { s in
                                    Button("Mark \(s)") { Task { await update(["op": "set_deliverable", "deliverable_id": del.id, "status": s], done: "\(del.title) marked \(s).") } }
                                }
                            }
                        }
                        Button { addDeliverable = true } label: { Label("Add deliverable", systemImage: "plus") }
                    }
                    Section("Spend") {
                        // Said before the rows, so a list that adds up to less than Spent is not
                        // taken for a discrepancy.
                        if let total = d.spendTotal, total > d.spend.count {
                            Text("Showing the latest \(d.spend.count) of \(total) entries. The website lists them all.").font(.callout)
                        }
                        ForEach(d.spend) { s in
                            HStack {
                                VStack(alignment: .leading) {
                                    Text(money(s.amount))
                                    if let m = s.memo, !m.isEmpty { Text(m).font(.caption) }
                                }
                                Spacer()
                                Text(formatDate(s.spentOn)).font(.caption)
                            }
                            .accessibilityElement(children: .combine)
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
        .sheet(isPresented: $addDeliverable, onDismiss: announceSheetResult) { ContractDeliverableSheet(contractId: id) { sheetResult = "Deliverable saved."; Task { await load() } } }
        .sheet(isPresented: $addSpend, onDismiss: announceSheetResult) { ContractSpendSheet(contractId: id) { sheetResult = "Spending recorded."; Task { await load() } } }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { error = nil; data = try await api.call("contract", query: ["id": String(id)]) }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    /// `done` is spoken once the change is saved (a menu choice otherwise closes in silence).
    private func update(_ body: [String: Any], done: String) async {
        var b = body; b["contract_id"] = id
        // Cleared first so a repeat of the same failure is shown (and announced) again.
        error = nil
        do { let _: OKResponse = try await api.call("contract_update", body: b); announce(done); await load(); onChanged() }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ContractDeliverableSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let contractId: Int; let onDone: () -> Void
    @State private var title = ""; @State private var hasDue = false; @State private var due = Date()
    @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false
    private var hasChanges: Bool { !title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || hasDue }
    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                ContractCaptionedField(label: "Deliverable (required)", text: $title)
                Toggle("Due date", isOn: $hasDue)
                if hasDue { DatePicker("Due", selection: $due, displayedComponents: .date) }
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
        busy = true; error = nil
        var b: [String: Any] = ["contract_id": contractId, "op": "add_deliverable", "title": title]
        if hasDue { b["due_date"] = APIDate.day(due) }
        // The presenter confirms once the sheet has gone: closing it moves VoiceOver focus,
        // which would cut off an announcement made here.
        do { let _: OKResponse = try await api.call("contract_update", body: b); onDone(); dismiss() }
        catch { showFormError(error.localizedDescription, in: &self.error); busy = false }
    }
}

struct ContractSpendSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let contractId: Int; let onDone: () -> Void
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
            Form {
                if let error { ErrorBanner(message: error) }
                ContractCaptionedField(label: "Amount (required, digits, like 1250.00)", shortName: "Amount", text: $amount, keyboard: .decimalPad)
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
        guard !amount.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { showFormError("Amount is required.", in: &error); return }
        guard let amountText = checkedAmount(amount), !amountText.isEmpty else { showFormError(amountFormatMessage("Amount"), in: &error); return }
        busy = true; error = nil
        do { let _: OKResponse = try await api.call("contract_update", body: ["contract_id": contractId, "op": "add_spend", "amount": amountText, "spent_on": APIDate.day(date), "memo": memo]); onDone(); dismiss() }
        catch APIError.network {
            // The request may have arrived: it isn't known whether the spending was recorded, and
            // saving it again would count the money twice against the contract.
            showFormError("The connection dropped before the server answered, so it isn't known whether this spending was recorded. Check the contract's spending for $\(amountText) on \(date.formatted(date: .abbreviated, time: .omitted)) before saving it again.", in: &self.error)
            busy = false
        }
        catch { showFormError(error.localizedDescription, in: &self.error); busy = false }
    }
}

struct ContractNewSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var name = ""; @State private var funder = ""; @State private var number = ""; @State private var amount = ""
    @State private var hasStart = false; @State private var start = Date(); @State private var hasEnd = false; @State private var end = Date()
    @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false
    private var hasChanges: Bool {
        [name, funder, number, amount].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty } || hasStart || hasEnd
    }
    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                // Captions that stay visible once the fields are filled in.
                ContractCaptionedField(label: "Name (required)", text: $name)
                ContractCaptionedField(label: "Funder", text: $funder)
                ContractCaptionedField(label: "Contract number", text: $number)
                ContractCaptionedField(label: "Budget (digits, like 12500.00)", shortName: "Budget", text: $amount, keyboard: .decimalPad)
                Toggle("Start date", isOn: $hasStart); if hasStart { DatePicker("Start", selection: $start, displayedComponents: .date) }
                Toggle("End date", isOn: $hasEnd); if hasEnd { DatePicker("End", selection: $end, displayedComponents: .date) }
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
        guard let amountText = checkedAmount(amount) else { showFormError(amountFormatMessage("Budget"), in: &error); return }
        busy = true; error = nil
        var b: [String: Any] = ["name": name, "funder": funder, "contract_number": number, "amount": amountText]
        if hasStart { b["start_date"] = APIDate.day(start) }
        if hasEnd { b["end_date"] = APIDate.day(end) }
        do { let _: OKResponse = try await api.call("add_contract", body: b); onDone(); dismiss() }
        catch { showFormError(error.localizedDescription, in: &self.error); busy = false }
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
        }
    }
}
