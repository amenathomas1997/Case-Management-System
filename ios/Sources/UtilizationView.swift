import SwiftUI

/// The utilization report plus the work by former or unrecorded staff, which the server counts
/// in the hour and value totals but lists in no row (0 when an older server does not say).
private struct UtilizationReport: Decodable {
    let report: UtilizationResponse
    let formerStaffDelivered: Double
    let formerStaffValue: Double
    /// Hours by active staff with no weekly target: counted in Hours delivered, left out of the
    /// team percentage.
    let noTargetDelivered: Double
    /// Per row, in the report's order: the staff member has no weekly target, so the row's 0%
    /// is not a measurement (from the row's weekly hours when an older server does not say).
    let noTarget: [Bool]
    private enum CodingKeys: String, CodingKey { case formerStaffDelivered, formerStaffValue, noTargetDelivered, rows }
    private struct RowFlag: Decodable {
        let noTarget: Bool?
        private enum CodingKeys: String, CodingKey { case noTarget }
        init(from d: Decoder) throws {
            noTarget = try? d.container(keyedBy: CodingKeys.self).decodeIfPresent(Bool.self, forKey: .noTarget)
        }
    }
    init(from d: Decoder) throws {
        let rep = try UtilizationResponse(from: d)
        report = rep
        let c = try d.container(keyedBy: CodingKeys.self)
        formerStaffDelivered = (try? c.decodeIfPresent(Double.self, forKey: .formerStaffDelivered)) ?? 0
        formerStaffValue = (try? c.decodeIfPresent(Double.self, forKey: .formerStaffValue)) ?? 0
        noTargetDelivered = (try? c.decodeIfPresent(Double.self, forKey: .noTargetDelivered)) ?? 0
        let flags = (try? c.decodeIfPresent([RowFlag].self, forKey: .rows)) ?? []
        noTarget = rep.rows.indices.map { i in (i < flags.count ? flags[i].noTarget : nil) ?? (rep.rows[i].weekly <= 0) }
    }
}

/// A staff row and whether it has a weekly target to measure against.
private struct StaffUtil { let row: UtilRow; let noTarget: Bool }

/// Staff billable utilization (web v2.56 parity): delivered service hours vs
/// expected capacity (weekly target × weeks in the period).
struct UtilizationView: View {
    @EnvironmentObject var api: API
    @State private var data: UtilizationResponse?
    @State private var formerHours = 0.0
    @State private var formerValue = 0.0
    /// The rows to list: staff with a weekly target first, then those with none, as on the website.
    @State private var staff: [StaffUtil] = []
    @State private var noTargetHours = 0.0
    @State private var error: String?
    /// The latest run failed, so the figures shown are from an earlier period.
    @State private var stale = false
    /// Only the newest request may fill the screen: an older run answering late must not put
    /// another period's figures under the dates chosen.
    @State private var loadSeq = 0
    @State private var from = Calendar.current.date(byAdding: .day, value: -28, to: Date()) ?? Date()
    @State private var to = Date()
    @Environment(\.dynamicTypeSize) private var typeSize

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }

            Section("Period") {
                // Date pickers, not typed text: a mistyped date used to fall back to the last 28 days unannounced.
                DatePicker("From", selection: $from, displayedComponents: .date)
                DatePicker("To", selection: $to, displayedComponents: .date)
                Button("Run report") { Task { await load(announceResult: true) } }
                    .accessibilityLabel("Run report for the chosen dates")
                    .accessibilityInputLabels(["Run report", "Run"])
            }

            if let d = data {
                if d.caseloadScoped == true {
                    Text("Activity is limited to your assigned clients. Expected hours remain the staff members’ full capacity targets.").font(.callout)
                }
                Section {
                    if stale {
                        Text("Could not refresh — these figures are still for \(formatDate(d.from)) to \(formatDate(d.to)), not the dates chosen above.")
                            .font(.callout)
                    }
                    // Side by side, or one under another at the accessibility text sizes, where
                    // three columns would break the figures and labels mid-word.
                    let tiles = typeSize.isAccessibilitySize
                        ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout())
                    tiles {
                        stat("\(d.orgUtil)%", d.caseloadScoped == true ? "Caseload / capacity" : "Org utilization")
                        stat(String(format: "%.1f", d.totalDelivered), "Hours delivered")
                        stat(String(format: "$%.0f", d.totalValue), "Billable value")
                    }
                    if formerHours > 0 || formerValue > 0 {
                        // Otherwise the rows below would not add up to these totals, unexplained.
                        Text(String(format: "Includes %.1f hours ($%.0f) by former or unrecorded staff, not listed below.", formerHours, formerValue))
                            .font(.caption).foregroundStyle(Color.secondaryText)
                    }
                    if noTargetHours > 0 {
                        // Otherwise Hours delivered and the percentage would not agree, unexplained.
                        Text(String(format: "Hours delivered includes %.1f hours by staff with no weekly target, listed last below; %@ leaves them out.",
                                    noTargetHours, d.caseloadScoped == true ? "Caseload / capacity" : "Org utilization"))
                            .font(.caption).foregroundStyle(Color.secondaryText)
                    }
                } header: {
                    // The period the figures cover, which may differ from the pickers until Run succeeds.
                    Text("Figures for \(formatDate(d.from)) to \(formatDate(d.to))")
                }
                Section {
                    // The 7:1 secondary-text colour throughout (system secondary is about 3.4:1).
                    if staff.isEmpty { Text("No activity in this period.").foregroundStyle(Color.secondaryText) }
                    ForEach(Array(staff.enumerated()), id: \.offset) { _, s in
                        let r = s.row
                        VStack(alignment: .leading, spacing: 3) {
                            HStack {
                                Text(r.name).font(.body.weight(.semibold))
                                Spacer()
                                if s.noTarget {
                                    // No weekly hours are set: a 0% "well below target" would be wrong.
                                    Text("No weekly target").font(.body.bold()).foregroundStyle(.primary)
                                } else {
                                    // The band is said in words as well as by the bar's colour.
                                    Text(verbatim: "\(r.util)% · \(utilBand(r.util))").font(.body.bold().monospacedDigit())
                                        .foregroundStyle(.primary)
                                }
                            }
                            // Hidden from VoiceOver: the text above says the exact figure, while the
                            // bar stops at 100 and would contradict it for anyone over capacity.
                            if !s.noTarget {
                                ProgressView(value: Double(min(r.util, 100)), total: 100).tint(utilColor(r.util))
                                    .accessibilityHidden(true)
                            }
                            Text(s.noTarget
                                 ? String(format: "%.1f hours delivered · %d client%@ · $%.0f value",
                                          r.delivered, r.clients, r.clients == 1 ? "" : "s", r.value)
                                 : String(format: "%.1f of %.1f expected hours · %d client%@ · $%.0f value",
                                          r.delivered, r.expected, r.clients, r.clients == 1 ? "" : "s", r.value))
                                .font(.caption).foregroundStyle(Color.secondaryText)
                        }.padding(.vertical, 1)
                        // One element read in words, without the separators the visible text uses.
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel(s.noTarget
                            ? String(format: "%@, no weekly target, %.1f hours delivered, %d client%@, %.0f dollars",
                                     r.name, r.delivered, r.clients, r.clients == 1 ? "" : "s", r.value)
                            : String(format: "%@, %d percent, %@, %.1f of %.1f expected hours, %d client%@, %.0f dollars",
                                     r.name, r.util, utilBand(r.util), r.delivered, r.expected, r.clients, r.clients == 1 ? "" : "s", r.value))
                    }
                } header: {
                    Text("By staff")
                } footer: {
                    Text(verbatim: "On target is 85% or more of expected hours; below target is 60–84%; well below target is under 60%.")
                        .foregroundStyle(Color.secondaryText)
                }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle("Utilization")
        .refreshable { await load() }
        .task { await load() }
    }

    /// The band the bar's colour shows, in words.
    private func utilBand(_ u: Int) -> String {
        u >= 85 ? "on target" : (u >= 60 ? "below target" : "well below target")
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
    /// Bar colours with at least 3:1 against the row in both appearances (system green and
    /// orange are about 2.2:1 on white): dark green #1E7B34, amber #8A4B00 and red #B3261E in
    /// light mode, and the brighter #30D158, #FF9F0A and #FF6B60 in dark mode.
    private func utilColor(_ u: Int) -> Color {
        let (light, dark): (UInt32, UInt32) = u >= 85 ? (0x1E7B34, 0x30D158) : (u >= 60 ? (0x8A4B00, 0xFF9F0A) : (0xB3261E, 0xFF6B60))
        func ui(_ hex: UInt32) -> UIColor {
            UIColor(red: CGFloat((hex >> 16) & 0xFF) / 255, green: CGFloat((hex >> 8) & 0xFF) / 255, blue: CGFloat(hex & 0xFF) / 255, alpha: 1)
        }
        return Color(UIColor { $0.userInterfaceStyle == .dark ? ui(dark) : ui(light) })
    }

    private func load(announceResult: Bool = false) async {
        loadSeq += 1
        let mine = loadSeq
        do {
            error = nil
            let r: UtilizationReport = try await api.call("utilization", query: ["from": APIDate.day(from), "to": APIDate.day(to)])
            guard mine == loadSeq else { return }
            let d = r.report
            data = d; stale = false
            formerHours = r.formerStaffDelivered; formerValue = r.formerStaffValue
            // Staff with no weekly target last, as on the website: their 0% is not a measurement.
            let rows = zip(d.rows, r.noTarget).map { StaffUtil(row: $0, noTarget: $1) }
            staff = rows.filter { !$0.noTarget } + rows.filter { $0.noTarget }
            noTargetHours = r.noTargetDelivered
            // Run only swaps the figures below the button: say that it happened, and for which period.
            if announceResult { announce("Utilization for \(formatDate(d.from)) to \(formatDate(d.to)): \(d.orgUtil) percent.") }
        }
        catch {
            guard mine == loadSeq else { return }
            self.error = error.localizedDescription
            stale = data != nil
        }
    }
}
