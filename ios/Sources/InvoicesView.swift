import SwiftUI

/// One invoice on the read-only list (the website's Invoices page).
struct InvoiceItem: Decodable, Identifiable {
    let id: Int
    let invoiceNumber: String
    let status: String
    let clientId: Int
    let clientName: String
    let trackTitle: String
    let authNumber: String?
    let fundingAgency: String?
    let hours: Double
    let totalAmount: Double
    let paidAmount: Double?
    /// What is still owed (4.38.0: the website's invoice_balance(), 0 for a draft).
    let balance: Double
    let shortPaid: Bool
    let daysOutstanding: Int?
    let createdAt: String
    let submittedAt: String?
    let paidAt: String?
    let canOpenClient: Bool
    /// 3.0.34 servers: the amount written off, the due date from the payment terms, and why
    /// a Denied or Void invoice was closed (absent from older servers).
    let writtenOff: Double
    let dueDate: String?
    let closedReason: String?
    /// 4.38.0 servers: the website row's sentence ("$1,149.99 owed (part-paid), 3 days past
    /// due"), its date lines in order, and the days past the due date.
    let owedText: String?
    let dateLines: [String]?
    let daysOverdue: Int?
    private enum CodingKeys: String, CodingKey {
        case id, invoiceNumber, status, clientId, clientName, trackTitle, authNumber, fundingAgency, hours, totalAmount
        case paidAmount, balance, shortPaid, daysOutstanding, createdAt, submittedAt, paidAt, canOpenClient
        case writtenOff, dueDate, closedReason, owedText, dateLines, daysOverdue
    }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        invoiceNumber = (try? c.decode(String.self, forKey: .invoiceNumber)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        trackTitle = (try? c.decode(String.self, forKey: .trackTitle)) ?? ""
        authNumber = try? c.decodeIfPresent(String.self, forKey: .authNumber)
        fundingAgency = try? c.decodeIfPresent(String.self, forKey: .fundingAgency)
        hours = (try? c.decode(Double.self, forKey: .hours)) ?? 0
        totalAmount = (try? c.decode(Double.self, forKey: .totalAmount)) ?? 0
        paidAmount = try? c.decodeIfPresent(Double.self, forKey: .paidAmount)
        balance = (try? c.decode(Double.self, forKey: .balance)) ?? 0
        shortPaid = (try? c.decode(Bool.self, forKey: .shortPaid)) ?? false
        daysOutstanding = try? c.decodeIfPresent(Int.self, forKey: .daysOutstanding)
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        submittedAt = try? c.decodeIfPresent(String.self, forKey: .submittedAt)
        paidAt = try? c.decodeIfPresent(String.self, forKey: .paidAt)
        canOpenClient = (try? c.decode(Bool.self, forKey: .canOpenClient)) ?? false
        writtenOff = (try? c.decode(Double.self, forKey: .writtenOff)) ?? 0
        dueDate = try? c.decodeIfPresent(String.self, forKey: .dueDate)
        closedReason = try? c.decodeIfPresent(String.self, forKey: .closedReason)
        owedText = try? c.decodeIfPresent(String.self, forKey: .owedText)
        dateLines = try? c.decodeIfPresent([String].self, forKey: .dateLines)
        daysOverdue = try? c.decodeIfPresent(Int.self, forKey: .daysOverdue)
    }
}

/// What one funding agency still owes (the website's Outstanding table). `overdue` and
/// `partPaid` come from 4.38.0 servers.
struct InvoiceAgingRow: Decodable {
    let fundingAgency: String
    let count: Int
    let amount: Double
    let oldestDays: Int?
    let overdue: Double?
    let partPaid: Int?
    private enum CodingKeys: String, CodingKey { case fundingAgency, count, amount, oldestDays, overdue, partPaid }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        fundingAgency = (try? c.decode(String.self, forKey: .fundingAgency)) ?? ""
        count = (try? c.decode(Int.self, forKey: .count)) ?? 0
        amount = (try? c.decode(Double.self, forKey: .amount)) ?? 0
        oldestDays = try? c.decodeIfPresent(Int.self, forKey: .oldestDays)
        overdue = try? c.decodeIfPresent(Double.self, forKey: .overdue)
        partPaid = try? c.decodeIfPresent(Int.self, forKey: .partPaid)
    }
}

/// The filters the server used (4.38.0), as it read them.
struct InvoiceFilters: Decodable, Equatable {
    var status: String?
    var agency: String?
    var month: String?
    var q: String?
}

struct InvoicesResponse: Decodable {
    let invoices: [InvoiceItem]
    let total: Int
    let page: Int
    let pages: Int
    let outstanding: [InvoiceAgingRow]
    let unbilled: Double
    /// 4.38.0 servers: the filters as read, the agencies on the list, the website's heading,
    /// and whether one invoice was asked for (and found).
    let filters: InvoiceFilters?
    let agencies: [String]?
    let heading: String?
    let requested: Bool?
    let found: Bool?
    private enum CodingKeys: String, CodingKey { case invoices, total, page, pages, outstanding, unbilled, filters, agencies, heading, requested, found }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        invoices = LenientList.decode(InvoiceItem.self, from: c, forKey: .invoices)
        total = (try? c.decode(Int.self, forKey: .total)) ?? 0
        page = (try? c.decode(Int.self, forKey: .page)) ?? 1
        pages = (try? c.decode(Int.self, forKey: .pages)) ?? 1
        outstanding = LenientList.decode(InvoiceAgingRow.self, from: c, forKey: .outstanding)
        unbilled = (try? c.decode(Double.self, forKey: .unbilled)) ?? 0
        filters = try? c.decodeIfPresent(InvoiceFilters.self, forKey: .filters)
        agencies = try? c.decodeIfPresent([String].self, forKey: .agencies)
        heading = try? c.decodeIfPresent(String.self, forKey: .heading)
        requested = try? c.decodeIfPresent(Bool.self, forKey: .requested)
        found = try? c.decodeIfPresent(Bool.self, forKey: .found)
    }
}

// MARK: - 4.38.0 invoice text (plan-4380 T1-T5): the server's sentences, money only formatted

/// The row's status line: the website's owed sentence when the server sends one; otherwise the
/// status, with the reason a Denied or Void invoice was closed (an older server: part-paid too).
func invoiceStatusLine(_ inv: InvoiceItem) -> String {
    if let owed = inv.owedText, !owed.isEmpty { return owed }
    if inv.status == "Denied" || inv.status == "Void" {
        return inv.status + ((inv.closedReason ?? "").isEmpty ? "" : ": \(inv.closedReason ?? "")")
    }
    if inv.dateLines == nil, inv.shortPaid { return "\(inv.status == "Paid" ? "Paid" : "Part-paid"): \(money(inv.balance)) still owed" }
    return inv.status
}

/// The row's dates: the website's date lines joined ("Created Nov 3, 2026, Submitted …"); for an
/// older server, the same built from the row's dates.
func invoiceDatesLine(_ inv: InvoiceItem) -> String {
    if let lines = inv.dateLines { return lines.joined(separator: ", ") }
    var parts = ["Created \(formatDate(inv.createdAt))"]
    if let s = inv.submittedAt { parts.append("Submitted \(formatDate(s))") }
    if let due = inv.dueDate, inv.status == "Submitted" || inv.status == "Paid" { parts.append("Due \(formatDate(due))") }
    if let p = inv.paidAt { parts.append("Last payment \(formatDate(p))") }
    if inv.writtenOff > 0 { parts.append("\(money(inv.writtenOff)) written off") }
    return parts.joined(separator: ", ")
}

/// The row's amount line, as the website's Amount cell: "$1,250.00, 12.5 hours".
func invoiceAmountLine(_ inv: InvoiceItem) -> String {
    "\(money(inv.totalAmount)), \(utilHours(inv.hours)) hour\(inv.hours == 1 ? "" : "s")"
}

/// One invoice read as one VoiceOver stop.
func invoiceSpoken(_ inv: InvoiceItem) -> String {
    var parts = ["Invoice \(inv.invoiceNumber)", inv.clientName, "\(inv.trackTitle), authorization \(inv.authNumber ?? "none")"]
    parts.append(invoiceAmountLine(inv))
    parts.append(invoiceStatusLine(inv))
    if let agency = inv.fundingAgency, !agency.isEmpty { parts.append(agency) }
    parts.append(invoiceDatesLine(inv))
    return parts.joined(separator: ". ")
}

/// One agency of the Outstanding table, as one stop: "Synthetic VR: $1,250.00 owed, $0.00 past
/// due. 2 invoices (1 part-paid), oldest 40 days." The past-due amount is always said, as the
/// page always shows its column (an older server sends none, so it is left out there).
func invoiceAgingLine(_ a: InvoiceAgingRow) -> String {
    let agency = a.fundingAgency.isEmpty ? "No agency" : a.fundingAgency
    var text = "\(agency): \(money(a.amount)) owed"
    if let overdue = a.overdue { text += ", \(money(overdue)) past due" }
    text += ". \(a.count) invoice\(a.count == 1 ? "" : "s")"
    if let k = a.partPaid, k > 0 { text += " (\(k) part-paid)" }
    if let d = a.oldestDays { text += ", oldest \(d) day\(d == 1 ? "" : "s")" }
    return text + "."
}

/// The list's query: the page, and only the filters that are set; one invoice asked for by id
/// leaves the other filters out, as the website's ?id= does.
func invoicesQuery(status: String, agency: String, month: String, q: String, page: Int, invoiceId: Int?) -> [String: String] {
    if let invoiceId { return ["id": String(invoiceId)] }
    var query = ["page": String(page)]
    let pairs = [("status", status), ("agency", agency), ("month", month), ("q", q)]
    for (key, value) in pairs {
        let v = value.trimmingCharacters(in: .whitespacesAndNewlines)
        if !v.isEmpty { query[key] = v }
    }
    return query
}

/// The Agency picker's choices after "All agencies": the agencies on the list, and a kept agency
/// that is not among them as "{agency} (no invoices)" (the website's wording). Pairs of tag, label.
func invoiceAgencyChoices(_ agencies: [String], kept: String) -> [(tag: String, label: String)] {
    var out = agencies.map { (tag: $0, label: $0) }
    if !kept.isEmpty, !agencies.contains(kept) { out.append((tag: kept, label: "\(kept) (no invoices)")) }
    return out
}

/// The drafts the several-at-once sheet offers: oldest first, at most 200 (the website's form).
func invoiceFirstDrafts(_ rows: [InvoiceItem]) -> [InvoiceItem] {
    Array(rows.filter { $0.status == "Draft" }
        .sorted { $0.createdAt == $1.createdAt ? $0.id < $1.id : $0.createdAt < $1.createdAt }
        .prefix(200))
}

/// The question before several drafts are marked submitted (names the count and the date).
func invoiceBulkConfirmTitle(_ n: Int, date: String) -> String {
    "Mark \(n) invoice\(n == 1 ? "" : "s") submitted as sent on \(date)?"
}

/// submit_invoices' body (the idem_key is added by the sheet).
func invoiceBulkBody(ids: [Int], submittedOn: String) -> [String: Any] {
    ["invoice_ids": ids, "submitted_on": submittedOn]
}

/// The field of the sheet a submit_invoices refusal is about.
let invoiceBulkFields = ["invoice_ids": "Draft invoices", "submitted_on": "Date they were sent"]

/// The invoice list: status, amount, balance and dates, with the website's filters and what each
/// funding agency still owes. A draft can be marked submitted (one, or several at once); the
/// invoice opens as its accessible document; other billing actions stay on the website.
struct InvoicesView: View {
    @EnvironmentObject var api: API
    /// One invoice asked for (a search result, 4.38.0), as the website's invoices.php?id=.
    @State private var invoiceId: Int?
    @State private var data: InvoicesResponse?
    @State private var error: String?
    // The filters being chosen, and the ones the shown list was loaded with.
    @State private var status = ""
    @State private var agency = ""
    @State private var month = ""
    @State private var q = ""
    @State private var applied = InvoiceFilters(status: "", agency: "", month: "", q: "")
    @State private var page = 1
    @State private var loadSerial = 0
    /// The draft waiting for a yes before it is marked submitted (4.0.0).
    @State private var pendingSubmit: InvoiceItem?
    /// The submit sheet's result, said (or shown, for a refusal) once the sheet has gone.
    @State private var submitResult: String?
    @State private var submitRefused: String?
    /// 4.38.0: several drafts at once.
    @State private var bulkDrafts: BulkDrafts?
    /// The last submit's result, shown on the screen as well as said (4.38.0: the bulk sentence
    /// names the invoices not changed).
    @State private var notice: String?
    /// The list is being reloaded after a bulk submit: the bulk button waits for it.
    @State private var reloadingAfterBulk = false

    init(invoiceId: Int? = nil) {
        _invoiceId = State(initialValue: invoiceId)
    }

    /// Whether the server is 4.38.0 or later (it sends the website's heading): the filters
    /// beyond Show, the invoice document and several-at-once are offered only then.
    private var newServer: Bool { data?.heading != nil }

    private var anyFilter: Bool {
        [applied.status, applied.agency, applied.month, applied.q].contains { !($0 ?? "").isEmpty }
    }

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let notice { Text(notice).font(.callout.weight(.semibold)) }
            if invoiceId != nil {
                Section {
                    if let d = data, d.requested == true {
                        Text(d.found == true ? "Showing one invoice." : "That invoice was not found, or it is not on your caseload.")
                    }
                    Button("Show all invoices") { invoiceId = nil; page = 1; Task { await load(announceView: true) } }
                        .frame(minHeight: 44)
                }
            } else {
                filterSection
            }
            if let d = data {
                if invoiceId == nil {
                    Section {
                        LabeledContent("Unbilled work", value: money(d.unbilled))
                        ForEach(Array(d.outstanding.enumerated()), id: \.offset) { _, a in
                            Text(invoiceAgingLine(a))
                        }
                        if d.outstanding.isEmpty { Text("Nothing is outstanding.").foregroundStyle(Color.secondaryText) }
                    } header: {
                        Text("Outstanding (submitted and not yet settled)").font(.headline).foregroundStyle(.primary).textCase(nil)
                            .accessibilityAddTraits(.isHeader)
                    }
                }
                if newServer, invoiceId == nil, invoiceFirstDrafts(d.invoices).count > 1 {
                    Section {
                        Button {
                            bulkDrafts = BulkDrafts(rows: invoiceFirstDrafts(d.invoices))
                        } label: {
                            Label("Mark several drafts submitted", systemImage: "paperplane").frame(minHeight: 44)
                        }
                        .disabled(reloadingAfterBulk)
                    }
                }
                Section {
                    if d.pages > 1 { Text("Invoices (page \(d.page) of \(d.pages), \(d.total) in all)").font(.callout) }
                    if d.invoices.isEmpty { Text("No invoices.").foregroundStyle(Color.secondaryText) }
                    ForEach(d.invoices) { inv in invoiceRow(inv) }
                    if d.pages > 1 {
                        Button("Newer invoices") { page = max(1, page - 1); Task { await load() } }
                            .disabled(page <= 1).frame(minHeight: 44)
                        Button("Older invoices") { page = min(d.pages, page + 1); Task { await load() } }
                            .disabled(page >= d.pages).frame(minHeight: 44)
                    }
                } header: {
                    Text(d.heading ?? "Invoices (\(d.total))")
                        .font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                }
            } else if error == nil {
                ProgressView("Loading invoices…")
            }
        }
        .navigationTitle("Invoices")
        // The pickers' choices are not cut short at the accessibility text sizes.
        .modifier(ReadablePickers(inNavigationStack: true))
        // 4.34.0: asks for the date it was sent, as the website does (the due date follows from it).
        .sheet(item: $pendingSubmit, onDismiss: afterSubmitSheet) { inv in
            SubmitInvoiceSheet(invoice: inv) { message, refused in
                submitResult = message
                if refused { submitRefused = message }
                Task { await load() }
            }
        }
        .sheet(item: $bulkDrafts, onDismiss: afterSubmitSheet) { drafts in
            BulkSubmitInvoicesSheet(drafts: drafts.rows) { message in
                submitResult = message
                reloadingAfterBulk = true
                Task { await load(); reloadingAfterBulk = false }
            }
        }
        .refreshable { await load() }
        .task { if data == nil { await load() } }
    }

    /// The website's filter form, in its order: Show, Agency, Invoice month, Invoice number or
    /// client; Apply filters and (when a filter is set) Clear filters.
    @ViewBuilder private var filterSection: some View {
        Section {
            Picker("Show", selection: $status) {
                Text("All invoices").tag("")
                Text("Draft").tag("Draft")
                Text("Submitted").tag("Submitted")
                Text("Paid").tag("Paid")
                Text("Denied").tag("Denied")
                Text("Void").tag("Void")
            }
            if newServer {
                Picker("Agency", selection: $agency) {
                    Text("All agencies").tag("")
                    ForEach(invoiceAgencyChoices(data?.agencies ?? [], kept: agency), id: \.tag) { choice in
                        Text(choice.label).tag(choice.tag)
                    }
                }
                TextField("Invoice month (year and month it was created, as YYYY-MM)", text: $month)
                    .keyboardType(.numbersAndPunctuation)
                    .textInputAutocapitalization(.never).autocorrectionDisabled()
                TextField("Invoice number or client", text: $q)
                    .textInputAutocapitalization(.never).autocorrectionDisabled()
                    .onSubmit { applyFilters() }
            }
            Button("Apply filters") { applyFilters() }.frame(minHeight: 44)
            if anyFilter {
                Button("Clear filters") {
                    status = ""; agency = ""; month = ""; q = ""
                    applyFilters()
                }
                .frame(minHeight: 44)
            }
            Text("A draft can be marked submitted here. Recording payments and write-offs, and closing denied invoices, are on the website.")
                .font(.callout).foregroundStyle(Color.secondaryText)
        }
    }

    private func applyFilters() {
        notice = nil
        applied = InvoiceFilters(status: status, agency: agency, month: month, q: String(q.prefix(120)))
        page = 1
        Task { await load(announceView: true) }
    }

    /// One invoice, opening the client's record when allowed; then, outside its one VoiceOver
    /// stop, Open invoice and (for a draft) Mark submitted.
    @ViewBuilder
    private func invoiceRow(_ inv: InvoiceItem) -> some View {
        let row = VStack(alignment: .leading, spacing: 3) {
            Text("\(inv.invoiceNumber): \(inv.clientName)").font(.body.weight(.semibold))
            Text("\(inv.trackTitle) · Authorization \(inv.authNumber ?? "none")").font(.caption).foregroundStyle(Color.secondaryText)
            // The website's Amount cell (as Android): the total and the hours.
            Text(invoiceAmountLine(inv)).font(.callout.monospacedDigit())
            Text(invoiceStatusLine(inv)).font(.caption)
                .foregroundStyle((inv.daysOverdue ?? 0) > 0 || inv.shortPaid ? Color.alertRed : Color.secondaryText)
            Text(invoiceDatesLine(inv)).font(.caption).foregroundStyle(Color.secondaryText)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(invoiceSpoken(inv))
        Group {
            if inv.canOpenClient {
                NavigationLink { ClientDetailView(clientId: inv.clientId) } label: { row }
            } else {
                row
            }
        }
        .modifier(DraftSubmitActions(isDraft: inv.status == "Draft") { pendingSubmit = inv })
        // 4.38.0 (T5): the invoice as the website's accessible HTML, to read, share or copy.
        if newServer {
            NavigationLink {
                BuiltDocumentView(navigationTitle: "Invoice \(inv.invoiceNumber)", action: "invoice_document",
                                  query: ["invoice_id": String(inv.id)], noun: "invoice", note: nil)
            } label: {
                Label("Open invoice \(inv.invoiceNumber)", systemImage: "doc.text").frame(minHeight: 44)
            }
        }
        // A visible single-tap way, as well as the swipe and the Actions rotor: its own stop
        // after the row's one stop, so it is reached by swiping with VoiceOver too (4.38.0).
        if inv.status == "Draft" {
            Button { pendingSubmit = inv } label: {
                Label("Mark submitted", systemImage: "paperplane").frame(minHeight: 44)
            }
            .buttonStyle(.bordered)
            .controlSize(.large)
            .accessibilityLabel("Mark invoice \(inv.invoiceNumber) submitted")
            .accessibilityInputLabels(["Mark submitted", "Mark invoice \(inv.invoiceNumber) submitted"])
        }
    }

    /// Said once the sheet has gone (a message said while it closes is cut off).
    private func afterSubmitSheet() {
        if let refused = submitRefused {
            submitRefused = nil; submitResult = nil
            notice = nil
            showFormError(refused, in: &error)
            return
        }
        if let message = submitResult { error = nil; notice = message; announceAfterDismiss(message) }
        submitResult = nil
    }

    private func load(announceView: Bool = false) async {
        loadSerial += 1
        let serial = loadSerial
        let query = invoicesQuery(status: applied.status ?? "", agency: applied.agency ?? "", month: applied.month ?? "",
                                  q: applied.q ?? "", page: page, invoiceId: invoiceId)
        do {
            let r: InvoicesResponse = try await api.call("invoices", query: query)
            guard serial == loadSerial else { return }
            error = nil
            data = r
            if let f = r.filters, invoiceId == nil {
                // What the server used (an unreadable month is ignored there, as on the website).
                applied = InvoiceFilters(status: f.status ?? "", agency: f.agency ?? "", month: f.month ?? "", q: f.q ?? "")
                month = f.month ?? ""
            }
            if announceView {
                announce("\(r.heading ?? "Invoices"): \(r.total) invoice\(r.total == 1 ? "" : "s").")
            }
        } catch {
            guard serial == loadSerial else { return }
            notice = nil
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// submit_invoices' reply: how many were marked, the numbers refused, and the website's sentence.
struct BulkSubmitReply: Decodable { let changed: Int?; let refused: [String]?; let message: String? }

/// The drafts offered to the several-at-once sheet.
struct BulkDrafts: Identifiable {
    let id = UUID()
    let rows: [InvoiceItem]
}

/// Mark several drafts submitted (4.38.0, T4), with the website's bulk form: the drafts to tick,
/// the date they were sent (today or earlier), and a confirmation naming the count and the date.
struct BulkSubmitInvoicesSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let drafts: [InvoiceItem]
    let onDone: (String) -> Void
    @State private var chosen: Set<Int> = []
    @State private var sentOn = Date()
    @State private var busy = false
    @State private var confirming = false
    @State private var error: String?
    @State private var idem = IdemKey()

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section {
                    ForEach(Array(drafts.enumerated()), id: \.element.id) { index, inv in
                        let toggle = Toggle("\(inv.invoiceNumber), \(inv.clientName), \(money(inv.totalAmount))", isOn: Binding(
                            get: { chosen.contains(inv.id) },
                            set: { on in if on { chosen.insert(inv.id) } else { chosen.remove(inv.id) } }))
                        if index == 0 { toggle.errorField("Draft invoices") } else { toggle }
                    }
                    Button("Choose all shown drafts") { chosen = Set(drafts.map(\.id)) }.frame(minHeight: 44)
                    Button("Choose none") { chosen = [] }.frame(minHeight: 44)
                } header: {
                    Text("Draft invoices").font(.headline).foregroundStyle(.primary).textCase(nil).accessibilityAddTraits(.isHeader)
                }
                Section {
                    DatePicker("Date they were sent", selection: $sentOn, in: ...Date(), displayedComponents: .date)
                        .errorField("Date they were sent")
                    Button(busy ? "Saving…" : "Mark the chosen invoices submitted") {
                        if chosen.isEmpty {
                            showFormError("Tick at least one draft invoice.", in: &error, field: "Draft invoices")
                        } else {
                            confirming = true
                        }
                    }
                    .disabled(busy).frame(minHeight: 44)
                }
            }
            .navigationTitle("Mark several drafts submitted")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
            }
            .confirmationDialog(invoiceBulkConfirmTitle(chosen.count, date: formatDate(APIDate.day(sentOn))),
                                isPresented: $confirming, titleVisibility: .visible) {
                Button("Mark them submitted") { Task { await submit() } }
                Button("Keep them as drafts", role: .cancel) {}
            } message: {
                Text("This starts the aging clock on each and cannot be undone.")
            }
        }
    }

    private func submit() async {
        busy = true; error = nil
        announce("Saving…")
        // In the list's order, so the same choice always sends the same body (one retry key).
        let ids = drafts.map(\.id).filter { chosen.contains($0) }
        var body = invoiceBulkBody(ids: ids, submittedOn: APIDate.day(sentOn))
        body["idem_key"] = idem.key(for: body)
        var fields: [String: String] = [:]
        do {
            let r: BulkSubmitReply = try await api.call("submit_invoices", method: "POST", body: body, refusal: { fields = refusalFields($0) })
            idem.answered()
            onDone(r.message ?? "\(r.changed ?? 0) invoice\((r.changed ?? 0) == 1 ? "" : "s") marked submitted.")
            dismiss()
        } catch {
            idem.failed(error)
            busy = false
            showFormError(fields.isEmpty ? IdemKey.message(for: error, what: "the change") : error.localizedDescription, in: &self.error,
                          field: refusedFieldName(fields, order: ["invoice_ids", "submitted_on"], names: invoiceBulkFields))
        }
    }
}

/// Mark submitted, as a swipe and on the Actions rotor, on a draft invoice only.
private struct DraftSubmitActions: ViewModifier {
    let isDraft: Bool
    let action: () -> Void
    func body(content: Content) -> some View {
        if isDraft {
            content
                .swipeActions { Button("Mark submitted", action: action).tint(.brandTeal) }
                .accessibilityAction(named: "Mark submitted", action)
        } else {
            content
        }
    }
}

/// Mark a draft invoice submitted (4.34.0): the date it went to the funder, from the day it was
/// created to today, as the website asks; the due date is set from it by the payment terms.
struct SubmitInvoiceSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let invoice: InvoiceItem
    /// The message to say, and whether it is a refusal (the list is reloaded either way).
    let onDone: (String, Bool) -> Void
    @State private var submittedOn = Date()
    @State private var busy = false
    @State private var error: String?
    @State private var idem = IdemKey()

    private var earliest: Date {
        APIDate.strictDay(String(invoice.createdAt.prefix(10))).map { min($0, Date()) } ?? Calendar.current.startOfDay(for: Date())
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Text("Mark invoice \(invoice.invoiceNumber) as submitted to the funder? Its due date is set from the payment terms.")
                DatePicker("Date submitted", selection: $submittedOn, in: earliest...Date(), displayedComponents: .date)
                    .errorField("Date submitted")
            }
            .navigationTitle("Mark submitted")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Saving…" : "Mark submitted") { Task { await submit() } }.disabled(busy)
                }
            }
        }
    }

    private func submit() async {
        busy = true; error = nil
        struct Reply: Decodable { let submittedOn: String?; let dueDate: String? }
        var body: [String: Any] = ["id": invoice.id, "submitted_on": APIDate.day(submittedOn)]
        body["idem_key"] = idem.key(for: body)
        var status409 = false
        var dateRefused = false
        do {
            let r: Reply = try await api.call("submit_invoice", body: body, refusal: { reply in
                // 4.34.0: the server marks a draft that changed meanwhile (its 409) with changed:true.
                status409 = (reply["changed"] as? Bool) == true
                let fields = (reply["errors"] as? [String: String]) ?? [:]
                dateRefused = fields["submitted_on"] != nil
            })
            idem.answered()
            let on = formatDate(r.submittedOn ?? APIDate.day(submittedOn))
            let due = (r.dueDate ?? "").isEmpty ? "" : "; it is due \(formatDate(r.dueDate))"
            onDone("Invoice \(invoice.invoiceNumber) marked submitted on \(on)\(due).", false)
            dismiss()
        } catch {
            idem.failed(error)
            busy = false
            if status409 {
                // Someone else changed it: the list is reloaded and the reason said there.
                onDone(error.localizedDescription, true)
                dismiss()
                return
            }
            // About the date: by its error key, or (the server sends none today) by its words,
            // "The date sent must be from the day the invoice was created to today."
            let aboutDate = dateRefused || error.localizedDescription.contains("date sent")
            showFormError(aboutDate ? error.localizedDescription : IdemKey.message(for: error, what: "the invoice"),
                          in: &self.error, field: aboutDate ? "Date submitted" : nil)
        }
    }
}
