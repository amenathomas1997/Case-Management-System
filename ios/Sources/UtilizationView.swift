import SwiftUI

/// One staff row of the utilization report, as the server sends it (4.38.0: at_or_above).
struct UtilStaffRow: Decodable {
    let name: String
    let weekly: Double
    let delivered: Double
    let value: Double
    let clients: Int
    let expected: Double
    let util: Int
    /// No weekly hours are set, so `util` is not a measurement.
    let noTarget: Bool
    /// 100% or more of the expected hours (the website's "at or above target").
    let atOrAbove: Bool
    private enum CodingKeys: String, CodingKey { case name, weekly, delivered, value, clients, expected, util, noTarget, atOrAbove }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        weekly = (try? c.decode(Double.self, forKey: .weekly)) ?? 0
        delivered = (try? c.decode(Double.self, forKey: .delivered)) ?? 0
        value = (try? c.decode(Double.self, forKey: .value)) ?? 0
        clients = (try? c.decode(Int.self, forKey: .clients)) ?? 0
        expected = (try? c.decode(Double.self, forKey: .expected)) ?? 0
        util = (try? c.decode(Int.self, forKey: .util)) ?? 0
        noTarget = (try? c.decode(Bool.self, forKey: .noTarget)) ?? (weekly <= 0)
        atOrAbove = (try? c.decode(Bool.self, forKey: .atOrAbove)) ?? (!noTarget && util >= 100)
    }
}

struct UtilPreset: Decodable { let key: String; let label: String }

/// The utilization report (4.38.0: the website's helper, period and wording). Every figure is
/// the server's; an older server sends no presets, notes, weeks or footer.
struct UtilizationReply: Decodable {
    let from: String
    let to: String
    let orgUtil: Int
    let totalDelivered: Double
    let totalValue: Double
    let formerStaffDelivered: Double
    let formerStaffValue: Double
    let noTargetDelivered: Double
    let caseloadScoped: Bool
    let rows: [UtilStaffRow]
    let period: String?
    let presets: [UtilPreset]
    let notes: [String]
    let weeks: Double?
    let footer: String?
    let scopedNote: String?
    private enum CodingKeys: String, CodingKey {
        case from, to, orgUtil, totalDelivered, totalValue, formerStaffDelivered, formerStaffValue, noTargetDelivered, caseloadScoped, rows
        case period, presets, notes, weeks, footer, scopedNote
    }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        from = (try? c.decode(String.self, forKey: .from)) ?? ""
        to = (try? c.decode(String.self, forKey: .to)) ?? ""
        orgUtil = (try? c.decode(Int.self, forKey: .orgUtil)) ?? 0
        totalDelivered = (try? c.decode(Double.self, forKey: .totalDelivered)) ?? 0
        totalValue = (try? c.decode(Double.self, forKey: .totalValue)) ?? 0
        formerStaffDelivered = (try? c.decode(Double.self, forKey: .formerStaffDelivered)) ?? 0
        formerStaffValue = (try? c.decode(Double.self, forKey: .formerStaffValue)) ?? 0
        noTargetDelivered = (try? c.decode(Double.self, forKey: .noTargetDelivered)) ?? 0
        caseloadScoped = (try? c.decode(Bool.self, forKey: .caseloadScoped)) ?? false
        rows = LenientList.decode(UtilStaffRow.self, from: c, forKey: .rows)
        period = try? c.decodeIfPresent(String.self, forKey: .period)
        presets = LenientList.decode(UtilPreset.self, from: c, forKey: .presets)
        notes = (try? c.decode([String].self, forKey: .notes)) ?? []
        weeks = try? c.decodeIfPresent(Double.self, forKey: .weeks)
        footer = try? c.decodeIfPresent(String.self, forKey: .footer)
        scopedNote = try? c.decodeIfPresent(String.self, forKey: .scopedNote)
    }
}

/// Hours as the website prints them: up to two decimals, no trailing zeros ("12.5").
func utilHours(_ h: Double) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.minimumFractionDigits = 0
    f.maximumFractionDigits = 2
    f.usesGroupingSeparator = false
    return f.string(from: NSNumber(value: h)) ?? String(h)
}

/// A staff row's first line: "{name}: {util}%[ at or above target]" or "{name}: no target".
func utilizationRowTitle(_ r: UtilStaffRow) -> String {
    r.noTarget ? "\(r.name): no target" : "\(r.name): \(r.util)%\(r.atOrAbove ? " at or above target" : "")"
}

/// A staff row's second line: "{d} of {e} expected hours · {n} clients · {$v} value".
func utilizationRowDetail(_ r: UtilStaffRow) -> String {
    "\(utilHours(r.delivered)) of \(utilHours(r.expected)) expected hours · \(r.clients) client\(r.clients == 1 ? "" : "s") · \(money(r.value)) value"
}

/// The rows in the website's order: the server sorts them (4.38.0); an older server mixed the
/// rows with no target in, so they are put last here, as the website lists them.
func utilizationShownRows(_ d: UtilizationReply) -> [UtilStaffRow] {
    d.presets.isEmpty ? d.rows.filter { !$0.noTarget } + d.rows.filter { $0.noTarget } : d.rows
}

/// utilization's query: the preset, with the dates only for Custom dates.
func utilizationQuery(preset: String, from: Date, to: Date) -> [String: String] {
    if preset.isEmpty || preset == "custom" {
        var q = ["from": APIDate.day(from), "to": APIDate.day(to)]
        if preset == "custom" { q["period"] = "custom" }
        return q
    }
    return ["period": preset]
}

/// Staff billable utilization (the website's utilization.php): delivered service hours against
/// expected capacity (weekly target × weeks in the period), for the period remembered with the
/// website's (4.38.0).
struct UtilizationView: View {
    @EnvironmentObject var api: API
    @State private var data: UtilizationReply?
    @State private var error: String?
    /// The latest run failed, so the figures shown are from an earlier period.
    @State private var stale = false
    /// Only the newest request may fill the screen: an older run answering late must not put
    /// another period's figures under the dates chosen.
    @State private var loadSeq = 0
    /// The period preset chosen ("" until the server has said which it used).
    @State private var preset = ""
    @State private var from = Calendar.current.date(byAdding: .day, value: -28, to: Date()) ?? Date()
    @State private var to = Date()
    @State private var firstLoad = true
    @Environment(\.dynamicTypeSize) private var typeSize

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }

            Section("Period") {
                if let presets = data?.presets, !presets.isEmpty {
                    Picker("Period", selection: $preset) {
                        ForEach(presets, id: \.key) { p in Text(p.label).tag(p.key) }
                    }
                }
                if (data?.presets ?? []).isEmpty || preset == "custom" {
                    // Date pickers, not typed text: a mistyped date used to fall back to the last 28 days unannounced.
                    DatePicker("From", selection: $from, displayedComponents: .date)
                    DatePicker("To", selection: $to, displayedComponents: .date)
                }
                Button("Run report") { Task { await load(announceResult: true) } }
                    .accessibilityLabel("Run report for the chosen period")
                    .accessibilityInputLabels(["Run report", "Run"])
            }

            if let d = data {
                if !d.notes.isEmpty { Text(d.notes.joined(separator: " ")).font(.callout) }
                if d.caseloadScoped {
                    Text(d.scopedNote ?? "Activity is limited to your assigned clients. Expected hours remain the staff members’ full capacity targets.")
                        .font(.callout)
                }
                Section {
                    if stale {
                        Text("Could not refresh — these figures are still for \(formatDate(d.from)) to \(formatDate(d.to)), not the period chosen above.")
                            .font(.callout)
                    }
                    // Side by side, or one under another at the accessibility text sizes, where
                    // the columns would break the figures and labels mid-word.
                    let tiles = typeSize.isAccessibilitySize
                        ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout())
                    tiles {
                        stat("\(d.orgUtil)%", "Team utilization")
                        stat(utilHours(d.totalDelivered), "Billable hours delivered")
                        stat(money(d.totalValue), "Billable value")
                        if let w = d.weeks { stat(utilHours(w), "Weeks in period") }
                    }
                } header: {
                    // The period the figures cover, which may differ from the pickers until Run succeeds.
                    Text("Summary for \(formatDate(d.from)) to \(formatDate(d.to))").font(.headline).foregroundStyle(.primary).textCase(nil)
                        .accessibilityAddTraits(.isHeader)
                }
                Section {
                    if d.rows.isEmpty { Text("No active staff.").foregroundStyle(Color.secondaryText) }
                    ForEach(Array(utilizationShownRows(d).enumerated()), id: \.offset) { _, r in
                        VStack(alignment: .leading, spacing: 3) {
                            Text(utilizationRowTitle(r)).font(.body.weight(.semibold))
                            Text(utilizationRowDetail(r)).font(.caption).foregroundStyle(Color.secondaryText)
                        }
                        .padding(.vertical, 1)
                        .accessibilityElement(children: .combine)
                    }
                    if d.formerStaffDelivered > 0 || d.formerStaffValue > 0 {
                        VStack(alignment: .leading, spacing: 3) {
                            Text("Former or unrecorded staff").font(.body.weight(.semibold))
                            Text("\(utilHours(d.formerStaffDelivered)) hours delivered · \(money(d.formerStaffValue)) value")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        }
                        .accessibilityElement(children: .combine)
                    }
                } header: {
                    Text("Staff utilization").font(.headline).foregroundStyle(.primary).textCase(nil).accessibilityAddTraits(.isHeader)
                } footer: {
                    if let footer = d.footer, !footer.isEmpty {
                        Text(footer).foregroundStyle(Color.secondaryText)
                    } else if d.noTargetDelivered > 0 {
                        Text("Billable hours delivered includes \(utilHours(d.noTargetDelivered)) hours by staff with no weekly target; Team utilization leaves them out.")
                            .foregroundStyle(Color.secondaryText)
                    }
                }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle("Utilization")
        .modifier(ReadablePickers(inNavigationStack: true))
        .refreshable { await load() }
        .task { await load() }
    }

    private func stat(_ n: String, _ label: String) -> some View {
        let stacked = typeSize.isAccessibilitySize
        return VStack(alignment: stacked ? .leading : .center, spacing: 2) {
            Text(n).font(.title3.bold().monospacedDigit()).foregroundStyle(.primary)
            Text(label).font(.caption).foregroundStyle(Color.secondaryText).multilineTextAlignment(stacked ? .leading : .center)
        }.frame(maxWidth: .infinity, alignment: stacked ? .leading : .center)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(label): \(n)")
    }

    private func load(announceResult: Bool = false) async {
        loadSeq += 1
        let mine = loadSeq
        // On open nothing is sent: the server uses the period remembered with the website's.
        let query = firstLoad ? [:] : utilizationQuery(preset: preset, from: from, to: to)
        do {
            let r: UtilizationReply = try await api.call("utilization", query: query)
            guard mine == loadSeq else { return }
            error = nil
            data = r; stale = false; firstLoad = false
            // The period the server used is the one selected (its dates fill Custom dates).
            if let p = r.period, r.presets.contains(where: { $0.key == p }) { preset = p }
            else if r.presets.contains(where: { $0.key == "custom" }) { preset = "custom" }
            if let f = APIDate.strictDay(r.from) { from = f }
            if let t = APIDate.strictDay(r.to) { to = t }
            // Run only swaps the figures below the button: say that it happened, and for which period.
            if announceResult { announce("Summary for \(formatDate(r.from)) to \(formatDate(r.to)): team utilization \(r.orgUtil) percent.") }
        }
        catch {
            guard mine == loadSeq else { return }
            showFormError(error.localizedDescription, in: &self.error)
            stale = data != nil
        }
    }
}
