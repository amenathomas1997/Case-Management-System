import SwiftUI

struct ComplianceAuth: Decodable, Identifiable {
    let id: Int; let title: String; let client: String; let pct: Int
    let expirationDate: String?; let expired: Bool
    // Optional so a server that predates the funds check still decodes.
    let fundsPct: Int?; let fundsRemaining: Double?
}
struct ComplianceReport: Decodable, Identifiable {
    let id: Int; let title: String; let client: String; let due: String; let cadence: String
}
struct ComplianceEquip: Decodable, Identifiable {
    let id: Int; let name: String; let due: String; let overdue: Bool
}
/// A guardian consent to renew (consents_due, the website's "Consents & releases to renew").
struct ConsentDueItem: Decodable, Identifiable {
    let clientId: Int
    let client: String
    let guardianName: String
    /// Expired, Withdrawn, Expiring or Unsigned.
    let kind: String
    let label: String
    let date: String
    /// A renewal request already sent and not yet signed.
    let pendingSentAt: String?
    let action: String
    var id: Int { clientId }
}
struct ConsentsDueResponse: Decodable { let consents: [ConsentDueItem]; let validityDays: Int }

struct ComplianceResponse: Decodable {
    let authRisk: [ComplianceAuth]; let reportsDue: [ComplianceReport]; let equipmentDue: [ComplianceEquip]
}

/// Red for small warning text at the enhanced (7:1) level: #8C1D18 in light mode (9.1:1 on a
/// white row, 8.2:1 on #F2F2F7) and #FF9A90 in dark mode (8.3:1 on a #1C1C1E row). System red
/// caption text is about 3.5:1. Each warning also says it in words ("Expired", "overdue").
private let complianceAlertRed = Color(UIColor { traits in
    traits.userInterfaceStyle == .dark
        ? UIColor(red: 255/255, green: 154/255, blue: 144/255, alpha: 1)
        : UIColor(red: 140/255, green: 29/255, blue: 24/255, alpha: 1)
})

struct ComplianceView: View {
    @EnvironmentObject var api: API
    @State private var data: ComplianceResponse?
    @State private var error: String?
    @State private var stale = false     // the last refresh failed; what is shown may be out of date
    @State private var consents: ConsentsDueResponse?
    @State private var consentsFailed = false

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            // "Nothing at risk" / "All caught up" are claims about the caseload: they are only
            // made for a list that actually loaded and whose latest refresh did not fail.
            if data == nil && error == nil { ProgressView() }

            if data != nil { Section("Authorizations at risk") {
                if let a = data?.authRisk, !a.isEmpty {
                    ForEach(a) { t in
                        VStack(alignment: .leading, spacing: 3) {
                            Text(t.title).font(.body.weight(.semibold))
                            // Caption text in the primary colour: grey secondary caption text is about 3.4:1.
                            Text(t.client).font(.caption)
                            HStack {
                                Text("\(t.pct)% of hours used").font(.caption)
                                    .foregroundStyle(t.pct >= 100 ? complianceAlertRed : .primary)
                                if t.expired { Text("Expired").font(.caption.bold()).foregroundStyle(complianceAlertRed) }
                                else if t.expirationDate != nil { Text("expires \(formatDate(t.expirationDate))").font(.caption) }
                            }
                            if let fp = t.fundsPct {
                                Text("\(fp)% of funds used, \(money(t.fundsRemaining ?? 0)) remaining").font(.caption)
                                    .foregroundStyle(fp >= 100 ? complianceAlertRed : .primary)
                            }
                        }.padding(.vertical, 2)
                        .accessibilityElement(children: .combine)
                    }
                } else if !stale { Text("Nothing at risk.") }
                else { Text("Could not refresh — this list may be out of date.") }
            } }

            if data != nil { Section("Progress reports due") {
                if let r = data?.reportsDue, !r.isEmpty {
                    ForEach(r) { t in
                        VStack(alignment: .leading, spacing: 3) {
                            Text(t.title).font(.body.weight(.semibold))
                            Text("\(t.client) · \(t.cadence) · due \(formatDate(t.due))").font(.caption)
                        }.padding(.vertical, 2)
                        .accessibilityElement(children: .combine)
                    }
                } else if !stale { Text("All caught up.") }
                else { Text("Could not refresh — this list may be out of date.") }
            } }

            if data != nil { Section {
                if let c = consents?.consents, !c.isEmpty {
                    ForEach(c) { f in
                        let when = ["Unsigned": "sent", "Expired": "expired", "Withdrawn": "withdrawn", "Expiring": "expires"][f.kind] ?? ""
                        let renewal = f.pendingSentAt.map { "Renewal request sent \(formatDate($0)), not yet signed." }
                        NavigationLink { ClientDetailView(clientId: f.clientId) } label: {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(f.client).font(.body.weight(.semibold))
                                Text("\(f.label) · \(when) \(formatDate(f.date))").font(.caption)
                                    .foregroundStyle(f.kind == "Expired" || f.kind == "Withdrawn" ? complianceAlertRed : .primary)
                                if !f.guardianName.isEmpty { Text("Guardian: \(f.guardianName)").font(.caption) }
                                if let renewal { Text(renewal).font(.caption) }
                            }
                            .padding(.vertical, 2)
                        }
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel("\(f.client). \(f.label), \(when) \(formatDate(f.date)). \(f.guardianName.isEmpty ? "" : "Guardian \(f.guardianName). ")\(renewal ?? "")")
                        .accessibilityHint("Opens the client record, where you can \(f.action.lowercased()).")
                    }
                } else if consents != nil && !stale && !consentsFailed { Text("No consents need attention.") }
                else if consentsFailed { Text("Could not refresh — this list may be out of date.") }
            } header: {
                Text("Consents to renew\(consents.map { " (\($0.consents.count))" } ?? "")")
            } footer: {
                if let days = consents?.validityDays {
                    Text("Consents within 30 days of their \(days)-day validity, expired or withdrawn, and requests unsigned for over two weeks. Send the renewal from the client's record.")
                }
            } }

            if let e = data?.equipmentDue, !e.isEmpty {
                Section("Equipment service due") {
                    ForEach(e) { item in
                        HStack {
                            Text(item.name)
                            Spacer()
                            Text(item.overdue ? "overdue \(formatDate(item.due))" : "due \(formatDate(item.due))")
                                .font(.caption).foregroundStyle(item.overdue ? complianceAlertRed : .primary)
                        }
                        .accessibilityElement(children: .combine)
                    }
                }
            }
        }
        .navigationTitle("Compliance")
        .refreshable { await load() }
        .task { await load() }
    }

    private func load() async {
        do { error = nil; data = try await api.call("compliance"); stale = false }
        catch { showFormError(error.localizedDescription, in: &self.error); stale = true }
        do { consents = try await api.call("consents_due"); consentsFailed = false }
        catch { consentsFailed = true }
    }
}
