import SwiftUI

struct ComplianceAuth: Decodable, Identifiable {
    let id: Int; let title: String; let client: String; let pct: Int
    let expirationDate: String?; let expired: Bool
    // Optional so a server that predates the funds check still decodes.
    let fundsPct: Int?; let fundsRemaining: Double?
    /// 4.36.0: the client (the row opens the track), the funds used of the authorized funds, and
    /// the website's sentence for the row; nil from an older server.
    var clientId: Int?
    var fundsUsed: Double?
    var authorizedFunds: Double?
    var text: String?
}
struct ComplianceReport: Decodable, Identifiable {
    let id: Int; let title: String; let client: String; let due: String; let cadence: String
    var clientId: Int?
    var text: String?
}
struct ComplianceEquip: Decodable, Identifiable {
    let id: Int; let name: String; let due: String; let overdue: Bool
    var text: String?
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

/// The 4.36.0 compliance rows: each carries the website's sentence (`text`) and what it opens.
struct ComplianceFollowupDue: Decodable, Identifiable { let id: Int; let clientId: Int; let text: String }
struct ComplianceLoanOverdue: Decodable, Identifiable { let id: Int; let name: String; let text: String }
struct ComplianceContractRisk: Decodable, Identifiable { let id: Int; let name: String; let text: String }
struct ComplianceReleaseRisk: Decodable { let clientId: Int; let client: String; let text: String }
struct ComplianceClientRow: Decodable { let clientId: Int; let client: String; var text: String? }
struct ComplianceCounts: Decodable {
    let authRisk: Int?; let reportsDue: Int?; let equipmentDue: Int?; let expired: Int?
    let consents: Int?; let releases: Int?; let followupsDue: Int?; let loansOverdue: Int?
}

struct ComplianceResponse: Decodable {
    let authRisk: [ComplianceAuth]; let reportsDue: [ComplianceReport]; let equipmentDue: [ComplianceEquip]
    /// 4.36.0: every other section of the website's Compliance page, in its order (nil from an
    /// older server, which hides them). Loans and contracts are absent without the equipment or
    /// billing permission, as on the website. Each is decoded on its own and row by row.
    let followupsDue: [ComplianceFollowupDue]?
    let followupsDueTotal: Int?
    let loansOverdue: [ComplianceLoanOverdue]?
    let contractsRisk: [ComplianceContractRisk]?
    let consents: [ConsentDueItem]?
    let releasesRisk: [ComplianceReleaseRisk]?
    let noRelease: [ComplianceClientRow]?
    let minorsNoConsent: [ComplianceClientRow]?
    let noNotice: [ComplianceClientRow]?
    let counts: ComplianceCounts?
    let validityDays: Int?
    /// A list whose rows are decoded one by one (4.36.0): a row that does not decode is left
    /// out, so one odd row never blanks the screen. Nested, so the model compiles on its own.
    struct ComplianceRows<Row: Decodable>: Decodable {
        let rows: [Row]
        private struct Skip: Decodable { init(from decoder: Decoder) throws {} }
        init(from decoder: Decoder) throws {
            var list = try decoder.unkeyedContainer()
            var out: [Row] = []
            while !list.isAtEnd {
                if let row = try? list.decode(Row.self) { out.append(row) } else { _ = try? list.decode(Skip.self) }
            }
            rows = out
        }
    }
    private enum CodingKeys: String, CodingKey {
        case authRisk, reportsDue, equipmentDue, followupsDue, followupsDueTotal, loansOverdue, contractsRisk, consents
        case releasesRisk, noRelease, minorsNoConsent, noNotice, counts, validityDays
    }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        authRisk = try c.decode(ComplianceRows<ComplianceAuth>.self, forKey: .authRisk).rows
        reportsDue = try c.decode(ComplianceRows<ComplianceReport>.self, forKey: .reportsDue).rows
        equipmentDue = (try? c.decode(ComplianceRows<ComplianceEquip>.self, forKey: .equipmentDue).rows) ?? []
        followupsDue = (try? c.decodeIfPresent(ComplianceRows<ComplianceFollowupDue>.self, forKey: .followupsDue))??.rows
        followupsDueTotal = (try? c.decodeIfPresent(Int.self, forKey: .followupsDueTotal)) ?? nil
        loansOverdue = (try? c.decodeIfPresent(ComplianceRows<ComplianceLoanOverdue>.self, forKey: .loansOverdue))??.rows
        contractsRisk = (try? c.decodeIfPresent(ComplianceRows<ComplianceContractRisk>.self, forKey: .contractsRisk))??.rows
        consents = (try? c.decodeIfPresent(ComplianceRows<ConsentDueItem>.self, forKey: .consents))??.rows
        releasesRisk = (try? c.decodeIfPresent(ComplianceRows<ComplianceReleaseRisk>.self, forKey: .releasesRisk))??.rows
        noRelease = (try? c.decodeIfPresent(ComplianceRows<ComplianceClientRow>.self, forKey: .noRelease))??.rows
        minorsNoConsent = (try? c.decodeIfPresent(ComplianceRows<ComplianceClientRow>.self, forKey: .minorsNoConsent))??.rows
        noNotice = (try? c.decodeIfPresent(ComplianceRows<ComplianceClientRow>.self, forKey: .noNotice))??.rows
        counts = (try? c.decodeIfPresent(ComplianceCounts.self, forKey: .counts)) ?? nil
        validityDays = (try? c.decodeIfPresent(Int.self, forKey: .validityDays)) ?? nil
    }
}

/// Red for small warning text at the enhanced (7:1) level: #8C1D18 in light mode (9.1:1 on a
/// white row, 8.2:1 on #F2F2F7) and #FF9A90 in dark mode (8.3:1 on a #1C1C1E row). System red
/// caption text is about 3.5:1. Each warning also says it in words ("Expired", "overdue").
private let complianceAlertRed = Color(UIColor { traits in
    traits.userInterfaceStyle == .dark
        ? UIColor(red: 255/255, green: 154/255, blue: 144/255, alpha: 1)
        : UIColor(red: 140/255, green: 29/255, blue: 24/255, alpha: 1)
})

/// The website's Compliance page (4.36.0: every section it shows the person, in its order, with
/// its headings and counts; each row opens its track, item, contract or client).
struct ComplianceView: View {
    @EnvironmentObject var api: API
    @State private var data: ComplianceResponse?
    @State private var error: String?
    @State private var stale = false     // the last refresh failed; what is shown may be out of date
    /// From consents_due, for a server whose compliance reply has no consents (before 4.36.0).
    @State private var consents: ConsentsDueResponse?
    @State private var consentsFailed = false

    private var consentRows: [ConsentDueItem]? { data?.consents ?? consents?.consents }
    private var validityDays: Int? { data?.validityDays ?? consents?.validityDays }

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            // "Nothing at risk" / "All caught up" are claims about the caseload: they are only
            // made for a list that actually loaded and whose latest refresh did not fail.
            if data == nil && error == nil { ProgressView() }
            if let d = data { sections(d) }
        }
        .navigationTitle("Compliance")
        .refreshable { await load() }
        .task { await load() }
    }

    private func heading(_ title: String) -> some View {
        Text(title).font(.headline).foregroundStyle(.primary).textCase(nil)
            .accessibilityAddTraits(.isHeader)
    }

    /// The website's empty sentence, or the out-of-date notice after a failed refresh.
    @ViewBuilder private func empty(_ text: String) -> some View {
        if stale { Text("Could not refresh — this list may be out of date.") } else { Text(text) }
    }

    @ViewBuilder private func sections(_ d: ComplianceResponse) -> some View {
        let expired = d.counts?.expired ?? d.authRisk.filter(\.expired).count
        Section {
            if d.authRisk.isEmpty { empty("Nothing needs attention right now.") }
            ForEach(d.authRisk) { t in
                trackLink(trackId: t.id, clientId: t.clientId) { authRow(t) }
            }
        } header: { heading("Authorizations at risk (\(d.authRisk.count)\(expired > 0 ? ", \(expired) expired" : ""))") }

        Section {
            if d.reportsDue.isEmpty { empty("All caught up.") }
            ForEach(d.reportsDue) { t in
                trackLink(trackId: t.id, clientId: t.clientId) {
                    VStack(alignment: .leading, spacing: 3) {
                        if let text = t.text { Text(text) } else {
                            Text(t.title).font(.body.weight(.semibold))
                            Text("\(t.client) · \(t.cadence) · due \(formatDate(t.due))").font(.caption)
                        }
                    }
                    .padding(.vertical, 2)
                    .accessibilityElement(children: .combine)
                }
            }
        } header: { heading("Progress reports due (\(d.reportsDue.count))") }

        // Sent with the equipment permission (an older server sends only a non-empty list).
        if d.counts.map({ $0.equipmentDue != nil }) ?? !d.equipmentDue.isEmpty {
            Section {
                if d.equipmentDue.isEmpty { empty("No service due.") }
                ForEach(d.equipmentDue) { item in
                    NavigationLink { EquipmentDetailView(itemId: item.id, itemName: item.name) } label: {
                        if let text = item.text { Text(text) } else {
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
            } header: { heading("Equipment service due (\(d.equipmentDue.count))") }
        }

        if let fu = d.followupsDue {
            Section {
                if fu.isEmpty { empty("No follow-ups are due.") }
                // compliance.php's sentence, before the list as there.
                if let total = d.followupsDueTotal, total > fu.count {
                    Text("Showing the first \(fu.count) of \(total) checks due, oldest first.").font(.callout)
                }
                ForEach(fu) { f in
                    NavigationLink { FollowupsView() } label: { Text(f.text) }
                }
            } header: { heading("Device follow-ups due (\(d.followupsDueTotal ?? fu.count))") }
        }

        if let loans = d.loansOverdue {
            Section {
                if loans.isEmpty { empty("No loans are overdue.") }
                ForEach(loans) { l in
                    NavigationLink { EquipmentDetailView(itemId: l.id, itemName: l.name) } label: { Text(l.text) }
                }
            } header: { heading("Overdue equipment loans (\(loans.count))") }
        }

        if let contracts = d.contractsRisk {
            Section {
                if contracts.isEmpty { empty("Nothing needs attention right now.") }
                ForEach(contracts) { c in
                    NavigationLink { ContractDetail(id: c.id, onChanged: {}) } label: { Text(c.text) }
                }
            } header: { heading("Contracts at risk (\(contracts.count))") }
        }

        consentsSection

        if let releases = d.releasesRisk {
            let noRelease = d.noRelease ?? []
            let minors = d.minorsNoConsent ?? []
            Section {
                if releases.isEmpty && noRelease.isEmpty && minors.isEmpty { empty("No releases need attention.") }
            } header: { heading("Releases of information (\(releases.count + noRelease.count + minors.count))") }
            if !minors.isEmpty {
                Section {
                    ForEach(Array(minors.enumerated()), id: \.offset) { _, c in clientLink(c.clientId, c.text ?? c.client) }
                } header: { heading("Minors and Pre-ETS students with no guardian consent requested (\(minors.count))") }
            }
            if !releases.isEmpty {
                Section {
                    ForEach(Array(releases.enumerated()), id: \.offset) { _, r in clientLink(r.clientId, r.text) }
                } header: { heading("Expiring, expired or withdrawn (\(releases.count))") }
            }
            if !noRelease.isEmpty {
                Section {
                    ForEach(Array(noRelease.enumerated()), id: \.offset) { _, c in clientLink(c.clientId, c.text ?? c.client) }
                } header: { heading("No release or consent on file (\(noRelease.count))") }
            }
        }

        if let notices = d.noNotice, !notices.isEmpty {
            Section {
                ForEach(Array(notices.enumerated()), id: \.offset) { _, c in clientLink(c.clientId, c.text ?? c.client) }
            } header: { heading("Privacy notice not recorded (\(notices.count))") }
        }
    }

    @ViewBuilder private var consentsSection: some View {
        Section {
            if let c = consentRows, !c.isEmpty {
                ForEach(c) { f in
                    // 4.34.0: "Adult", a guardian's consent from before the client turned 18, as the website lists it.
                    let when = ["Unsigned": "sent", "Expired": "expired", "Withdrawn": "withdrawn", "Expiring": "expires",
                                "Adult": "client turned 18 on"][f.kind] ?? ""
                    let renewal = f.pendingSentAt.map { "Renewal request sent \(formatDate($0)), not yet signed." }
                    NavigationLink { ClientDetailView(clientId: f.clientId) } label: {
                        VStack(alignment: .leading, spacing: 3) {
                            Text(f.client).font(.body.weight(.semibold))
                            Text("\(f.label) · \(when) \(formatDate(f.date))").font(.caption)
                                .foregroundStyle(f.kind == "Expired" || f.kind == "Withdrawn" || f.kind == "Adult" ? complianceAlertRed : .primary)
                            if !f.guardianName.isEmpty { Text("Guardian: \(f.guardianName)").font(.caption) }
                            if let renewal { Text(renewal).font(.caption) }
                        }
                        .padding(.vertical, 2)
                    }
                    .accessibilityElement(children: .ignore)
                    .accessibilityLabel("\(f.client). \(f.label), \(when) \(formatDate(f.date)). \(f.guardianName.isEmpty ? "" : "Guardian \(f.guardianName). ")\(renewal ?? "")")
                    .accessibilityHint("Opens the client record, where you can \(f.action.lowercased()).")
                }
            } else if consentRows != nil && !stale { Text("No consents need attention.") }
            else if stale || consentsFailed { Text("Could not refresh — this list may be out of date.") }
        } header: {
            heading("Consents to renew\(consentRows.map { " (\($0.count))" } ?? "")")
        } footer: {
            if let days = validityDays {
                Text("Each open client's latest signed consent when it is within 30 days of its \(days)-day validity, already expired, or withdrawn by the guardian — listed until a new consent is signed, with the date of any renewal request already sent — and, for clients with no signed consent, requests sent over two weeks ago that are still unsigned. Send the renewal from the client's page.")
            }
        }
    }

    @ViewBuilder private func authRow(_ t: ComplianceAuth) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            if let text = t.text {
                Text(text).foregroundStyle(t.expired || t.pct >= 100 ? complianceAlertRed : .primary)
            } else {
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
                    Text(complianceFundsLine(used: t.fundsUsed, of: t.authorizedFunds, pct: fp, remaining: t.fundsRemaining ?? 0)).font(.caption)
                        .foregroundStyle(fp >= 100 ? complianceAlertRed : .primary)
                }
            }
        }
        .padding(.vertical, 2)
        .accessibilityElement(children: .combine)
    }

    /// A row that opens its track (the client's record is loaded with it), or a plain row when
    /// an older server does not say the client.
    @ViewBuilder private func trackLink<RowLabel: View>(trackId: Int, clientId: Int?, @ViewBuilder label: () -> RowLabel) -> some View {
        if let clientId, clientId > 0 {
            NavigationLink { TrackByIdView(trackId: trackId, clientId: clientId) } label: { label() }
        } else {
            label()
        }
    }

    private func clientLink(_ clientId: Int, _ text: String) -> some View {
        NavigationLink { ClientDetailView(clientId: clientId) } label: { Text(text) }
    }

    private func load() async {
        do {
            error = nil
            let r: ComplianceResponse = try await api.call("compliance")
            data = r
            stale = false
            // A 4.36.0 server sends the consents with the rest; an older one has consents_due.
            if r.consents != nil { return }
        }
        catch { showFormError(error.localizedDescription, in: &self.error); stale = true }
        do { consents = try await api.call("consents_due"); consentsFailed = false }
        catch { consentsFailed = true }
    }
}

/// "Funds: $X of $Y (P%), $Z remaining", with ", used up" at 100% (the website's words, 4.36.0);
/// without the amounts (an older server) "P% of funds used, $Z remaining".
func complianceFundsLine(used: Double?, of authorized: Double?, pct: Int, remaining: Double) -> String {
    guard let used, let authorized else { return "\(pct)% of funds used, \(money(remaining)) remaining" }
    return "Funds: \(money(used)) of \(money(authorized)) (\(pct)%\(pct >= 100 ? ", used up" : "")), \(money(remaining)) remaining"
}
