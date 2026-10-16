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
    /// What is still owed: the total until it is paid, then any short-paid remainder.
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
    private enum CodingKeys: String, CodingKey {
        case id, invoiceNumber, status, clientId, clientName, trackTitle, authNumber, fundingAgency, hours, totalAmount
        case paidAmount, balance, shortPaid, daysOutstanding, createdAt, submittedAt, paidAt, canOpenClient
        case writtenOff, dueDate, closedReason
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
    }
}

struct InvoiceAgingRow: Decodable { let fundingAgency: String; let count: Int; let amount: Double; let oldestDays: Int? }

struct InvoicesResponse: Decodable {
    let invoices: [InvoiceItem]
    let total: Int
    let page: Int
    let pages: Int
    let outstanding: [InvoiceAgingRow]
    let unbilled: Double
}

extension InvoiceItem {
    var statusText: String {
        if status == "Denied" || status == "Void" { return status + ((closedReason ?? "").isEmpty ? "" : ": \(closedReason!)") }
        if shortPaid { return "\(status == "Paid" ? "Paid" : "Part-paid"): \(String(format: "$%.2f", balance)) still owed" }
        if status == "Submitted", let days = daysOutstanding { return "Submitted, \(days) day\(days == 1 ? "" : "s") outstanding" }
        return status
    }
    var datesText: String {
        var parts = ["Created \(formatDate(createdAt))"]
        if let submittedAt { parts.append("submitted \(formatDate(submittedAt))") }
        if let dueDate, status == "Submitted" { parts.append("due \(formatDate(dueDate))") }
        if let paidAt { parts.append("last payment \(formatDate(paidAt))") }
        if writtenOff > 0 { parts.append(String(format: "$%.2f written off", writtenOff)) }
        return parts.joined(separator: ", ")
    }
    var spoken: String {
        var parts = ["Invoice \(invoiceNumber)", clientName, String(format: "$%.2f", totalAmount), statusText]
        if balance > 0 && status != "Paid" { parts.append(String(format: "balance $%.2f", balance)) }
        parts.append("\(trackTitle), authorization \(authNumber ?? "none")")
        if let fundingAgency, !fundingAgency.isEmpty { parts.append(fundingAgency) }
        parts.append(hoursSpoken(hours))
        parts.append(datesText)
        return parts.joined(separator: ". ")
    }
}

/// The invoice list, read only: status, amount, balance and dates, filtered by status, with
/// what each funding agency still owes. Billing actions stay on the website.
struct InvoicesView: View {
    @EnvironmentObject var api: API
    @State private var data: InvoicesResponse?
    @State private var error: String?
    @State private var status = ""
    @State private var page = 1

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            Section {
                Picker("Show", selection: $status) {
                    Text("All invoices").tag("")
                    Text("Draft").tag("Draft")
                    Text("Submitted").tag("Submitted")
                    Text("Paid").tag("Paid")
                    Text("Denied").tag("Denied")
                    Text("Void").tag("Void")
                }
                Text("Read only. Submitting, recording payments and write-offs, and closing denied invoices are on the website.")
                    .font(.callout).foregroundStyle(Color.secondaryText)
            }
            if let d = data {
                Section {
                    LabeledContent("Unbilled work", value: String(format: "$%.2f", d.unbilled))
                    ForEach(Array(d.outstanding.enumerated()), id: \.offset) { _, a in
                        LabeledContent(a.fundingAgency.isEmpty ? "No agency" : a.fundingAgency,
                                       value: String(format: "$%.2f, %d invoice%@%@", a.amount, a.count, a.count == 1 ? "" : "s",
                                                     a.oldestDays.map { ", oldest \($0) days" } ?? ""))
                    }
                    if d.outstanding.isEmpty { Text("Nothing is outstanding.").foregroundStyle(Color.secondaryText) }
                } header: {
                    Text("Outstanding by funding agency").font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                }
                Section {
                    if d.invoices.isEmpty { Text("No invoices.").foregroundStyle(Color.secondaryText) }
                    ForEach(d.invoices) { inv in
                        let row = VStack(alignment: .leading, spacing: 3) {
                            HStack {
                                Text(inv.invoiceNumber).font(.body.weight(.semibold))
                                Spacer()
                                Text(String(format: "$%.2f", inv.totalAmount)).font(.body.monospacedDigit())
                            }
                            Text(inv.clientName).font(.callout)
                            Text(inv.statusText).font(.caption).foregroundStyle(inv.shortPaid ? Color.alertRed : Color.secondaryText)
                            Text("\(inv.trackTitle) · Authorization \(inv.authNumber ?? "none")").font(.caption).foregroundStyle(Color.secondaryText)
                            Text(inv.datesText).font(.caption).foregroundStyle(Color.secondaryText)
                        }
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel(inv.spoken)
                        if inv.canOpenClient {
                            NavigationLink { ClientDetailView(clientId: inv.clientId) } label: { row }
                        } else {
                            row
                        }
                    }
                    if d.pages > 1 {
                        HStack {
                            Button("Newer") { page = max(1, page - 1); Task { await load() } }.disabled(page <= 1)
                            Spacer()
                            Button("Older") { page = min(d.pages, page + 1); Task { await load() } }.disabled(page >= d.pages)
                        }
                        .buttonStyle(.borderless)
                        .frame(minHeight: 44)
                    }
                } header: {
                    Text(d.pages > 1 ? "Invoices (page \(d.page) of \(d.pages), \(d.total) in all)" : "Invoices (\(d.total))")
                        .font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                }
            } else if error == nil {
                ProgressView("Loading invoices…")
            }
        }
        .navigationTitle("Invoices")
        .onChange(of: status) { _ in page = 1; Task { await load(announceView: true) } }
        .refreshable { await load() }
        .task { await load() }
    }

    private func load(announceView: Bool = false) async {
        let wanted = status
        var query = ["page": String(page)]
        if !wanted.isEmpty { query["status"] = wanted }
        do {
            error = nil
            let r: InvoicesResponse = try await api.call("invoices", query: query)
            guard wanted == status else { return }
            data = r
            if announceView { announce("\(r.total) \(wanted.isEmpty ? "" : wanted.lowercased() + " ")invoice\(r.total == 1 ? "" : "s").") }
        } catch {
            guard wanted == status else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
