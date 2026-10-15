import SwiftUI

/// A referral or client on the intake pipeline (the website's Pipeline page).
struct PipelineEntry: Decodable, Identifiable {
    let id: Int
    let name: String
    let status: String
    let daysInStage: Int
    /// Waiting more than 30 days in an early stage (flagged on the website too).
    let over30: Bool
    let instructor: String?
    let assignedName: String?
    private enum CodingKeys: String, CodingKey { case id, name, status, daysInStage, over30, instructor, assignedName }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        daysInStage = (try? c.decode(Int.self, forKey: .daysInStage)) ?? 0
        over30 = (try? c.decode(Bool.self, forKey: .over30)) ?? false
        instructor = try? c.decodeIfPresent(String.self, forKey: .instructor)
        assignedName = try? c.decodeIfPresent(String.self, forKey: .assignedName)
    }
}

struct PipelineStage: Decodable, Identifiable {
    let name: String
    let clients: [PipelineEntry]
    var id: String { name }
}

struct PipelineResponse: Decodable {
    let canSeeReferrals: Bool
    let referrals: [PipelineEntry]
    let stages: [PipelineStage]
    let closed: [PipelineEntry]
}

extension PipelineEntry {
    var daysText: String {
        "\(daysInStage) day\(daysInStage == 1 ? "" : "s") in stage" + (over30 ? ", over 30 days" : "")
    }
}

/// Where every prospect and client sits, and for how long: referrals awaiting action, each
/// client stage, and clients closed in the last 90 days. Long waits are said in words.
struct PipelineView: View {
    @EnvironmentObject var api: API
    @State private var data: PipelineResponse?
    @State private var error: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let d = data {
                Section {
                    Text("Referrals and clients waiting more than 30 days (pending assessment, waitlist or on hold) are marked “over 30 days”.")
                        .font(.callout)
                }
                if d.canSeeReferrals {
                    section("Referrals awaiting action", d.referrals, empty: "None waiting.") { r in
                        NavigationLink { ReferralDetailView(referralId: r.id) } label: {
                            entry(r, detail: "\(r.status) · \(r.daysText) · \(r.assignedName ?? "unassigned")", kind: "referral")
                        }
                    }
                }
                ForEach(d.stages) { stage in
                    section(stage.name, stage.clients, empty: "No clients in this stage.") { c in
                        NavigationLink { ClientDetailView(clientId: c.id) } label: {
                            entry(c, detail: c.daysText + (c.instructor.map { " · \($0)" } ?? ""), kind: "client record")
                        }
                    }
                }
                section("Closed in last 90 days", d.closed, empty: "None.") { c in
                    NavigationLink { ClientDetailView(clientId: c.id) } label: {
                        entry(c, detail: c.status, kind: "client record")
                    }
                }
            } else if error == nil {
                ProgressView("Loading the pipeline…")
            }
        }
        .navigationTitle("Intake progress")
        .refreshable { await load() }
        .task { await load() }
    }

    @ViewBuilder private func section<Row: View>(_ title: String, _ list: [PipelineEntry], empty: String,
                                                 @ViewBuilder row: @escaping (PipelineEntry) -> Row) -> some View {
        Section {
            if list.isEmpty { Text(empty).foregroundStyle(Color.secondaryText) }
            ForEach(list) { row($0) }
        } header: {
            Text("\(title) (\(list.count))").font(.headline).foregroundStyle(.primary).textCase(nil)
                .accessibilityAddTraits(.isHeader)
        }
    }

    private func entry(_ e: PipelineEntry, detail: String, kind: String) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(e.name).font(.body.weight(.semibold))
            Text(detail).font(.caption)
                .foregroundStyle(e.over30 ? Color.alertRed : Color.secondaryText)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("\(e.name), \(kind). \(detail)")
    }

    private func load() async {
        do { error = nil; data = try await api.call("pipeline") }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}
