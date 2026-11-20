import SwiftUI

// MARK: - 4.38.0 Data quality and the documentation audit (plan-4380 T15), read-only

/// A client a check found, with what it found.
struct DataQualityRow: Decodable {
    let clientId: Int
    let name: String
    let detail: String
    private enum CodingKeys: String, CodingKey { case clientId, name, detail }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        detail = (try? c.decode(String.self, forKey: .detail)) ?? ""
    }
}

/// One check of data_quality.php: its heading, what it looks for, and the rows it found.
struct DataQualitySection: Decodable {
    let key: String
    let heading: String
    let explanation: String
    let rows: [DataQualityRow]
    private enum CodingKeys: String, CodingKey { case key, heading, explanation, rows }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        key = (try? c.decode(String.self, forKey: .key)) ?? ""
        heading = (try? c.decode(String.self, forKey: .heading)) ?? ""
        explanation = (try? c.decode(String.self, forKey: .explanation)) ?? ""
        rows = LenientList.decode(DataQualityRow.self, from: c, forKey: .rows)
    }
}

struct DataQualityReply: Decodable {
    let scoped: Bool
    let summary: String
    let sections: [DataQualitySection]
    private enum CodingKeys: String, CodingKey { case scoped, summary, sections }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        scoped = (try? c.decode(Bool.self, forKey: .scoped)) ?? false
        summary = (try? c.decode(String.self, forKey: .summary)) ?? ""
        sections = LenientList.decode(DataQualitySection.self, from: c, forKey: .sections)
    }
}

/// A client with documentation gaps, and each gap in the website's words.
struct DocAuditRow: Decodable {
    let clientId: Int
    let name: String
    let findings: [String]
    private enum CodingKeys: String, CodingKey { case clientId, name, findings }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        findings = (try? c.decode([String].self, forKey: .findings)) ?? []
    }
}

struct DocAuditReply: Decodable {
    let scoped: Bool
    let intro: String
    let active: Int
    let withGaps: Int
    let totalFindings: Int
    let rows: [DocAuditRow]
    let checks: [String]
    let footnote: String
    let empty: String
    private enum CodingKeys: String, CodingKey { case scoped, intro, active, withGaps, totalFindings, rows, checks, footnote, empty }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        scoped = (try? c.decode(Bool.self, forKey: .scoped)) ?? false
        intro = (try? c.decode(String.self, forKey: .intro)) ?? ""
        active = (try? c.decode(Int.self, forKey: .active)) ?? 0
        withGaps = (try? c.decode(Int.self, forKey: .withGaps)) ?? 0
        totalFindings = (try? c.decode(Int.self, forKey: .totalFindings)) ?? 0
        rows = LenientList.decode(DocAuditRow.self, from: c, forKey: .rows)
        checks = (try? c.decode([String].self, forKey: .checks)) ?? []
        footnote = (try? c.decode(String.self, forKey: .footnote)) ?? ""
        empty = (try? c.decode(String.self, forKey: .empty)) ?? "No documentation gaps found."
    }
}

/// A check's heading with its count: "Likely duplicate clients (2)".
func dataQualityHeading(_ heading: String, _ n: Int) -> String { "\(heading) (\(n))" }

/// A documentation-audit row as one stop: the name, then each finding.
func docAuditRowText(_ row: DocAuditRow) -> String {
    ([row.name] + row.findings).joined(separator: ". ")
}

/// Data quality: the website's six checks, each row opening its client.
struct DataQualityView: View {
    @EnvironmentObject var api: API
    @State private var data: DataQualityReply?
    @State private var error: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error) }
            if let d = data {
                Section { Text(d.summary) }
                ForEach(Array(d.sections.enumerated()), id: \.offset) { _, s in
                    Section {
                        Text(s.explanation).font(.callout).foregroundStyle(Color.secondaryText)
                        if s.rows.isEmpty { Text("None.") }
                        ForEach(Array(s.rows.enumerated()), id: \.offset) { _, row in
                            NavigationLink { ClientDetailView(clientId: row.clientId) } label: {
                                Text("\(row.name): \(row.detail)")
                            }
                        }
                    } header: {
                        Text(dataQualityHeading(s.heading, s.rows.count)).font(.headline).foregroundStyle(.primary).textCase(nil)
                            .accessibilityAddTraits(.isHeader)
                    }
                }
            } else if error == nil {
                ProgressView("Loading the checks…")
            }
        }
        .navigationTitle("Data quality")
        .refreshable { await load() }
        .task { await load() }
    }

    private func load() async {
        do {
            data = try await api.call("data_quality")
            error = nil
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// The documentation audit: the website's counts, each client's gaps, and what it checks.
struct DocAuditView: View {
    @EnvironmentObject var api: API
    @State private var data: DocAuditReply?
    @State private var error: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error) }
            if let d = data {
                Section {
                    if !d.intro.isEmpty { Text(d.intro).font(.callout) }
                    LabeledContent("Active clients", value: String(d.active))
                    LabeledContent("With gaps", value: String(d.withGaps))
                    LabeledContent("Total findings", value: String(d.totalFindings))
                }
                Section {
                    if d.rows.isEmpty { Text(d.empty) }
                    ForEach(Array(d.rows.enumerated()), id: \.offset) { _, row in
                        NavigationLink { ClientDetailView(clientId: row.clientId) } label: {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(row.name).font(.body.weight(.semibold))
                                ForEach(Array(row.findings.enumerated()), id: \.offset) { _, f in Text(f).font(.callout) }
                            }
                            .accessibilityElement(children: .ignore)
                            .accessibilityLabel(docAuditRowText(row))
                        }
                    }
                }
                Section {
                    ForEach(Array(d.checks.enumerated()), id: \.offset) { _, c in Text(c).font(.callout) }
                    if !d.footnote.isEmpty { Text(d.footnote).font(.callout).foregroundStyle(Color.secondaryText) }
                } header: {
                    Text("What this checks").font(.headline).foregroundStyle(.primary).textCase(nil).accessibilityAddTraits(.isHeader)
                }
            } else if error == nil {
                ProgressView("Loading the audit…")
            }
        }
        .navigationTitle("Documentation audit")
        .refreshable { await load() }
        .task { await load() }
    }

    private func load() async {
        do {
            data = try await api.call("doc_audit")
            error = nil
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
