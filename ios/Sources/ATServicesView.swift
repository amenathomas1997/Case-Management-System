import SwiftUI

struct ATModule: Decodable, Identifiable {
    let id: String
    let label: String
    let description: String
    let statuses: [String]
}
struct ATOption: Decodable, Identifiable {
    let value: String
    let label: String
    var id: String { value }
}
struct ATField: Decodable, Identifiable {
    let key: String
    let label: String
    let type: String
    let required: Bool
    let options: [ATOption]
    var id: String { key }
    private enum CodingKeys: String, CodingKey { case key, label, type, required, options }
    // Tolerant decode: a field with no options (or an absent flag) must not fail the
    // whole at_list/at_detail decode and blank the screen.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = try c.decode(String.self, forKey: .key)
        label = (try? c.decode(String.self, forKey: .label)) ?? ""
        type = (try? c.decode(String.self, forKey: .type)) ?? "text"
        required = (try? c.decode(Bool.self, forKey: .required)) ?? false
        options = (try? c.decode([ATOption].self, forKey: .options)) ?? []
    }
}
struct ATRecord: Decodable, Identifiable {
    let id: Int
    let kind: String
    let title: String
    let status: String
    let version: Int
    let values: [String: String]
    let updatedAt: String
    private enum CodingKeys: String, CodingKey { case id, kind, title, status, version, values, updatedAt }
    // Tolerate a single malformed/absent field so one bad record cannot blank the
    // whole list for a VoiceOver user; only the id is genuinely required.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        kind = (try? c.decode(String.self, forKey: .kind)) ?? ""
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        version = (try? c.decode(Int.self, forKey: .version)) ?? 0
        values = (try? c.decode([String: String].self, forKey: .values)) ?? [:]
        updatedAt = (try? c.decode(String.self, forKey: .updatedAt)) ?? ""
    }
}
struct ATCatalogResponse: Decodable { let modules: [ATModule] }
struct ATListResponse: Decodable {
    let records: [ATRecord]
    let fields: [ATField]
    let statuses: [String]
    let description: String
}
struct ATDetailResponse: Decodable {
    let record: ATRecord
    let fields: [ATField]
    let children: [ATRecord]
    let summary: [String: String]
    let statuses: [String]
    let description: String
}
struct ATSaveResponse: Decodable {
    let id: Int
    private enum CodingKeys: String, CodingKey { case ok, id }
    // Tolerant: once the server says ok the record is saved (or the equipment issued); a
    // missing id must not be shown as a failure that invites a second, duplicate save.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard (try? c.decode(Bool.self, forKey: .ok)) == true else {
            throw DecodingError.dataCorruptedError(forKey: .ok, in: c, debugDescription: "not ok")
        }
        id = (try? c.decode(Int.self, forKey: .id)) ?? 0
    }
}
struct ATIssueChoices: Decodable { let clients: [ATOption]; let equipment: [ATOption] }
struct ATMatch: Decodable, Identifiable {
    let id: Int
    let name: String
    let matched: String
    let missing: String
    let availableHours: Double
    let bookedHours: Double
    let capacityHours: Double
    let availability: String
}
struct ATMatchesResponse: Decodable { let matches: [ATMatch] }
struct ATProfit: Decodable, Identifiable {
    let id: Int
    let title: String
    let client: String
    let service: String
    let funder: String
    let contract: String
    let invoiced: Double
    let received: Double
    let cost: Double
    let margin: Double
    let unpricedHours: Double
}
struct ATProfitResponse: Decodable { let rows: [ATProfit] }

struct ATServicesView: View {
    @EnvironmentObject var api: API
    @State private var modules: [ATModule] = []
    /// Set once the catalog has answered, so "loading" and "none available" are never confused.
    @State private var loaded = false
    @State private var error: String?
    var body: some View {
        List {
            if let error { ErrorBanner(message: error) }
            if !loaded && error == nil { ProgressView("Loading AT services…") }
            if loaded && modules.isEmpty && error == nil {
                Text("No AT services are available for your account.")
            }
            ForEach(modules) { module in
                NavigationLink { ATModuleView(module: module) } label: {
                    VStack(alignment: .leading) {
                        Text(module.label).font(.headline)
                        // Primary, not secondary: grey caption text is about 3.4:1 on a white row.
                        Text(module.description).font(.caption)
                    }
                }
            }
        }.navigationTitle("AT services").task { await load() }.refreshable { await load() }
    }
    private func load() async {
        do { let response: ATCatalogResponse = try await api.call("at_catalog"); modules = response.modules; error = nil; loaded = true }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ATModuleView: View {
    @EnvironmentObject var api: API
    let module: ATModule
    @State private var data: ATListResponse?
    @State private var error: String?
    @State private var adding = false
    @State private var search = ""
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?
    private func matching(_ records: [ATRecord]) -> [ATRecord] {
        records.filter { search.isEmpty || ($0.title + " " + $0.status).localizedCaseInsensitiveContains(search) }
    }
    var body: some View {
        List {
            if let error { ErrorBanner(message: error) }
            if let data {
                let shown = matching(data.records)
                // The date column means different things by kind (a rate's "Effective from", a
                // license's "Renewal date"), so it is named with this kind's own field label.
                let dateLabel = data.fields.first(where: { $0.key == "due_date" })?.label ?? "Due / effective date"
                Section { Text(data.description).font(.callout) }
                if module.id == "trial_option" {
                    // As on the website: a trial option is added from its comparison, which fills
                    // in the client and the comparison (a list of every client's comparisons
                    // could not tell same-named ones apart).
                    Text("To add a trial option, open its device trial comparison and choose Add trial option under Related records.").font(.callout)
                }
                if module.id == "staff_profile" { NavigationLink("Find instructor matches") { ATMatchingView() } }
                if module.id == "cost" && (api.can("admin") || api.can("billing")) { NavigationLink("View profitability") { ATProfitabilityView() } }
                ForEach(shown) { record in
                    NavigationLink { ATRecordView(id: record.id) } label: {
                        VStack(alignment: .leading) {
                            Text(record.title)
                            // Primary, not secondary (grey caption text is about 3.4:1), and the
                            // date is named and written out rather than left as yyyy-MM-dd.
                            Text(record.status + (record.values["due_date"].flatMap { $0.isEmpty ? nil : " · \(dateLabel): " + formatDate($0) } ?? "")).font(.caption)
                        }
                    }
                }
                if data.records.isEmpty { Text("No records yet.") }
                else if shown.isEmpty { Text("No records match \u{201C}\(search)\u{201D}.") }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle(module.label).searchable(text: $search)
        .onChange(of: search) { _ in Task { await searchChanged() } }
        .toolbar {
            if data != nil && module.id != "trial_option" && (module.id != "resource" || (api.can("templates") || api.can("billing"))) {
                Button { adding = true } label: { Label("Add \(atRecordNoun(module.id))", systemImage: "plus") }
            }
        }
        .task { await load() }.refreshable { await load() }
        .sheet(isPresented: $adding, onDismiss: announceSheetResult) {
            if let data { ATEditSheet(kind: module.id, fields: data.fields, statuses: data.statuses, record: nil, defaults: [:]) { message in sheetResult = message; Task { await load() } } }
        }
    }
    private func announceSheetResult() {
        // Closed without a confirmation (Cancel): reloaded anyway, so a new record whose reply
        // was lost to a dropped connection is in the list before anyone saves it again.
        if let message = sheetResult { announceAfterDismiss(message) } else { Task { await load() } }
        sheetResult = nil
    }
    /// The list filters as you type, which VoiceOver does not hear: once typing pauses, say
    /// how many records match.
    private func searchChanged() async {
        let q = search
        try? await Task.sleep(nanoseconds: 600_000_000)
        guard q == search, !q.isEmpty, let data else { return }
        let n = matching(data.records).count
        announce(n == 0 ? "No records match." : "\(n) record\(n == 1 ? "" : "s") match.")
    }
    private func load() async {
        do { data = try await api.call("at_list", query: ["kind": module.id]); error = nil }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ATRecordView: View {
    @EnvironmentObject var api: API
    let id: Int
    @State private var data: ATDetailResponse?
    @State private var error: String?
    @State private var editing = false
    @State private var issuing = false
    @State private var returning = false
    @State private var child: ATChildDraft?
    @State private var working = false
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// Set once a linked loan's return is recorded; the notice replaces the return button.
    @State private var returnRecorded = false
    /// The comparison report as the server writes it (at_report, which also records that
    /// the report was produced); nil until it arrives, when the local text stands in.
    @State private var serverReport: String?
    @AccessibilityFocusState private var returnNoticeFocused: Bool
    private var canEdit: Bool {
        guard let r = data?.record else { return false }
        if r.kind == "resource" && !(api.can("templates") || api.can("billing")) { return false }
        if r.kind == "staff_profile" && !(api.can("admin") || api.can("billing")) && r.values["staff_id"] != String(api.userId) { return false }
        return r.kind != "reuse" || r.status != "Redeployed"
    }
    var body: some View {
        List {
            if let error { ErrorBanner(message: error) }
            if let data {
                Section { LabeledContent("Status", value: data.record.status).font(.headline); Text(data.description).font(.callout) }
                Section("Record") {
                    ForEach(data.fields) { field in
                        if let value = data.record.values[field.key], !value.isEmpty {
                            // The caption is hidden from VoiceOver and its name is spoken with the
                            // value instead, so each field is one swipe that says what it is.
                            // A date is written out ("Oct 15, 2026"), not read as digits and dashes.
                            // Not selectable: these are client records, and a system Copy would go
                            // to the person's other devices and stay on the clipboard indefinitely.
                            let shown = field.options.first(where: { $0.value == value })?.label
                                ?? (field.type == "date" && APIDate.strictDay(value) != nil ? formatDate(value) : value)
                            VStack(alignment: .leading, spacing: 4) {
                                Text(field.label).font(.caption).accessibilityHidden(true)
                                if field.type == "url", let url = URL(string: value), url.scheme == "https" {
                                    Link(value, destination: url).accessibilityLabel("\(field.label): \(value)")
                                }
                                else { Text(shown).accessibilityLabel("\(field.label): \(shown)") }
                            }
                        }
                    }
                }
                if !data.summary.isEmpty {
                    Section("Current totals / equipment") {
                        ForEach(data.summary.keys.sorted(), id: \.self) { key in
                            // A date ("Return due", "Returned", "Expires") is written out, as in the
                            // Record section, not read as digits and dashes.
                            let value = data.summary[key] ?? ""
                            VStack(alignment: .leading) { Text(key).font(.caption); Text(APIDate.strictDay(value) != nil ? formatDate(value) : value) }
                                .accessibilityElement(children: .combine)
                        }
                    }
                }
                if data.record.kind == "trial" {
                    Section("Comparison report") {
                        ShareLink(item: serverReport ?? comparisonText(data)) { Label("Share comparison and recommendation", systemImage: "square.and.arrow.up") }
                    }
                }
                if let kind = childKind(data.record.kind) {
                    Section("Related records") {
                        ForEach(data.children) { item in
                            // The comparison's recommended option says so in its own name, as on the web card.
                            let recommended = data.record.kind == "trial" && data.record.values["preferred_option"] == String(item.id)
                            NavigationLink(item.title + (recommended ? " (recommended)" : "") + " · " + item.status) { ATRecordView(id: item.id) }
                        }
                        // As on the website: only a published resource can be assigned (the server lists
                        // published resources only, so a draft's assignment could never be saved).
                        if data.record.kind == "resource" && data.record.status != "Published" {
                            Text("Publish this resource before assigning it to a client.")
                        } else {
                            Button("Add \(atRecordNoun(kind))") { Task { await prepareChild(kind, parent: data.record) } }.disabled(working)
                        }
                    }
                }
                if data.record.kind == "repair" || data.record.kind == "reuse" {
                    // As on the website: return is offered only while the linked loan is still
                    // out, and a new loaner only once it is back (the server refuses a second).
                    let loanOpen = data.record.values["loan_id"] != nil && data.summary["Returned"] == "Not returned"
                    Section("Equipment") {
                        if !loanOpen && (data.record.kind == "repair" && !["Resolved", "Cancelled"].contains(data.record.status) || data.record.kind == "reuse" && data.record.status == "Ready") {
                            Button("Issue equipment") { issuing = true }
                        }
                        if loanOpen {
                            Button("Return linked equipment") { returning = true }.disabled(working)
                        }
                        if returnRecorded {
                            // Stays on screen, and is where VoiceOver is put once the return button
                            // it was on has gone (an announcement would be cut off by that move).
                            Label { Text("Equipment return recorded.") } icon: {
                                Image(systemName: "checkmark.circle.fill").foregroundStyle(Color.brandTeal)
                            }
                            .accessibilityFocused($returnNoticeFocused)
                        }
                    }
                }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle(data?.record.title ?? "AT record")
        .toolbar { if canEdit { Button("Edit") { editing = true } } }
        .task { await load() }.refreshable { await load() }
        .sheet(isPresented: $editing, onDismiss: announceSheetResult) {
            if let data { ATEditSheet(kind: data.record.kind, fields: data.fields, statuses: data.statuses, record: data.record, defaults: [:]) { message in sheetResult = message; Task { await load() } } }
        }
        .sheet(item: $child, onDismiss: announceSheetResult) { draft in ATEditSheet(kind: draft.kind, fields: draft.data.fields, statuses: draft.data.statuses, record: nil, defaults: draft.defaults) { message in sheetResult = message; Task { await load() } } }
        .sheet(isPresented: $issuing, onDismiss: announceSheetResult) { if let data { ATIssueSheet(record: data.record) { message in sheetResult = message; returnRecorded = false; Task { await load() } } } }
        .confirmationDialog("Confirm the equipment has been returned", isPresented: $returning, titleVisibility: .visible) {
            Button("Record return") { Task { await returnEquipment() } }
        }
    }
    /// The reply to at_report: the comparison report's title and text as the website writes them.
    struct ATReportResponse: Decodable { let ok: Bool; let title: String?; let text: String? }
    /// A trial option's fields as the web report (at_report.php) lists them, with the form's
    /// labels from inc/at_catalog.php: each score's label carries its scale.
    private static let trialOptionLines: [(key: String, label: String)] = [
        ("device", "Device / software and version"), ("tested_on", "Trial date"),
        ("task_results", "Results against each comparison goal"),
        ("independence", "Independence (1 = needs full support, 5 = fully independent)"),
        ("ease", "Ease of use (1 = very difficult, 5 = very easy)"), ("preference", "Client preference"),
        ("cost", "Estimated total cost"), ("barriers", "Barriers and support required"),
    ]
    private func comparisonText(_ data: ATDetailResponse) -> String {
        var lines = [data.record.title, "Status: " + data.record.status, "Goals: " + (data.record.values["goals"] ?? ""), "Context: " + (data.record.values["context"] ?? "")]
        // As on the web report: the recommended option is named in its own heading line and at
        // the start of the rationale, not in a bare line after its scores.
        var preferredTitle: String?
        for child in data.children {
            let isPreferred = data.record.values["preferred_option"] == String(child.id)
            if isPreferred { preferredTitle = child.title }
            lines.append("\n" + child.title + " — " + child.status + (isPreferred ? " (recommended)" : ""))
            for (key, label) in Self.trialOptionLines {
                // As on the web report: a blank is "Not recorded", the trial date is written out
                // and the cost is shown as money.
                let value = (child.values[key] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
                let shown: String
                if value.isEmpty { shown = "Not recorded" }
                else if key == "tested_on", APIDate.strictDay(value) != nil { shown = formatDate(value) }
                else if key == "cost", let amount = Double(value) { shown = money(amount) }
                else { shown = value }
                lines.append(label + ": " + shown)
            }
        }
        lines.append("\nRecommendation rationale:")
        if let preferredTitle { lines.append("Recommended option: " + preferredTitle) }
        let rationale = (data.record.values["rationale"] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        lines.append(rationale.isEmpty ? "No recommendation finalized." : rationale)
        lines.append("\nScores are recorded staff observations, not a validated clinical assessment.")
        return lines.joined(separator: "\n")
    }
    private func announceSheetResult() {
        // Closed without a confirmation (Cancel): reloaded anyway, so a related record whose
        // reply was lost to a dropped connection is listed before anyone saves it again.
        if let message = sheetResult { announceAfterDismiss(message) } else { Task { await load() } }
        sheetResult = nil
    }
    private func childKind(_ kind: String) -> String? { ["trial": "trial_option", "setup": "outcome", "resource": "learning", "license": "license_seat"][kind] }
    private func load() async {
        do { data = try await api.call("at_detail", query: ["id": String(id)]); error = nil }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        // The server's own report for a device comparison, so sharing matches the website's
        // report and is recorded there. A failure is not an error here: the local text is shared.
        if data?.record.kind == "trial" {
            if let r: ATReportResponse = try? await api.call("at_report", body: ["id": id]), r.ok, let text = r.text, !text.isEmpty {
                serverReport = text
            }
        }
    }
    private func prepareChild(_ kind: String, parent: ATRecord) async {
        // Cleared first so the same error coming back is shown (and announced) again.
        working = true; error = nil; defer { working = false }
        do {
            // The child belongs to the parent's client, so its pickers list only that client's records.
            let parentClient = parent.values["client_id"] ?? ""
            var query = ["kind": kind]
            if !parentClient.isEmpty && parentClient != "0" { query["client_id"] = parentClient }
            let response: ATListResponse = try await api.call("at_list", query: query)
            child = ATChildDraft(kind: kind, data: response, defaults: ["parent_id": String(parent.id), "client_id": parentClient])
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    private func returnEquipment() async {
        working = true; error = nil; returnRecorded = false; defer { working = false }
        do {
            let _: OKResponse = try await api.call("at_return", body: ["id": id])
            // Reload first (the return button VoiceOver was on is removed), then put VoiceOver on
            // the confirmation, which speaks it, once the list has settled.
            await load()
            returnRecorded = true
            Task { @MainActor in
                try? await Task.sleep(nanoseconds: 500_000_000)
                returnNoticeFocused = true
            }
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}
/// What one record of an AT service is called, for button and sheet titles ("Add trial
/// option"), so the person knows what kind of record they are adding or editing.
func atRecordNoun(_ kind: String) -> String {
    [
        "trial": "device trial comparison", "trial_option": "trial option", "funding": "authorization record",
        "setup": "device setup record", "outcome": "post-delivery outcome", "repair": "repair record",
        "staff_profile": "instructor profile", "resource": "learning resource", "learning": "learning assignment",
        "cost": "cost record", "rate": "staff labor rate", "license": "software license",
        "license_seat": "license assignment", "reuse": "device reuse record",
    ][kind] ?? "record"
}

/// A text field whose name stays visible above it once it holds text (a placeholder alone
/// disappears). The field carries the same name for VoiceOver, so the caption is hidden.
private struct ATCaptionedField: View {
    let label: String
    @Binding var text: String
    var body: some View {
        VStack(alignment: .leading) {
            Text(label).font(.caption).accessibilityHidden(true)
            TextField(label, text: $text).accessibilityLabel(label)
        }
    }
}

struct ATChildDraft: Identifiable {
    let id = UUID()
    let kind: String
    let data: ATListResponse
    let defaults: [String: String]
}

struct ATEditSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let kind: String
    let fields: [ATField]
    let statuses: [String]
    let record: ATRecord?
    let defaults: [String: String]
    /// Called with the confirmation to speak once the sheet has closed.
    let onSave: (String) -> Void
    @State private var title = ""
    @State private var status = ""
    @State private var values: [String: String] = [:]
    @State private var error: String?
    @State private var busy = false
    var body: some View {
        NavigationStack {
            // ErrorFocusForm: Save is in the toolbar and the form is long, so a refused save is
            // scrolled to and given VoiceOver focus rather than left in a banner row off screen.
            ErrorFocusForm(inNavigationStack: true, error: error) {
                // A caption that stays visible once the title is filled in (a placeholder alone
                // disappears); the field carries the same name for VoiceOver.
                VStack(alignment: .leading) {
                    Text("Title (required)").font(.caption).accessibilityHidden(true)
                    TextField("Title (required)", text: $title).accessibilityLabel("Title (required)")
                }
                Picker("Status", selection: $status) { ForEach(statuses, id: \.self) { Text($0).tag($0) } }
                ForEach(fields) { field in
                    let binding = Binding<String>(get: { values[field.key] ?? "" }, set: { values[field.key] = $0 })
                    // What the record was created with and the server refuses to change (its client,
                    // its parent record, a repair or reuse record's device) is shown read-only, as on the website.
                    let fixed = record != nil && (field.key == "client_id" || field.key == "parent_id" || (field.key == "equipment_id" && ["repair", "reuse"].contains(kind)))
                    let label = field.label + (field.required && !fixed ? " (required)" : "")
                    if fixed {
                        let value = values[field.key] ?? ""
                        VStack(alignment: .leading) {
                            LabeledContent(label, value: value.isEmpty ? "None" : (field.options.first { $0.value == value }?.label ?? "Current selection (not in your list)"))
                                .accessibilityHint("Cannot be changed after the record is created.")
                            Text("Cannot be changed after the record is created.").font(.caption).accessibilityHidden(true)
                        }
                    } else if field.type == "reference" || field.type == "select" {
                        Picker(label, selection: binding) {
                            Text("Choose…").tag("")
                            ForEach(field.options) { Text($0.label).tag($0.value) }
                            if let v = values[field.key], !v.isEmpty, !field.options.contains(where: { $0.value == v }) { Text("Current selection (not in your list; kept if unchanged)").tag(v) }
                        }
                    } else if field.type == "textarea" {
                        // The field carries the same name for VoiceOver, so the caption is not read twice.
                        VStack(alignment: .leading) { Text(label).font(.caption).accessibilityHidden(true); TextEditor(text: binding).frame(minHeight: 90).accessibilityLabel(label) }
                    } else if field.type == "date" {
                        // A switch and a date picker, not typed YYYY-MM-DD text.
                        OptionalDayField(title: label, text: binding)
                    } else {
                        VStack(alignment: .leading) {
                            Text(label).font(.caption).accessibilityHidden(true)
                            TextField(label, text: binding)
                                .keyboardType(field.type == "money" ? .decimalPad : (["integer", "score"].contains(field.type) ? .numberPad : .default))
                                .autocorrectionDisabled(field.type == "url")
                                .accessibilityLabel(label)
                                .accessibilityHint(entryHint(field.type))
                        }
                    }
                }
                if api.aiHas("draft"), fields.contains(where: { ["text", "textarea"].contains($0.type) }) {
                    ATAIDraftPanel(kind: kind, targets: fields.filter { ["text", "textarea"].contains($0.type) }.map { ATDraftTarget(key: $0.key, label: $0.label) }, context: values.merging(["title": title], uniquingKeysWith: { _, new in new }), recordId: record?.id ?? 0) { key, text in values[key] = text }
                }
            }
            .navigationTitle((record == nil ? "Add " : "Edit ") + atRecordNoun(kind))
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) { Button("Save") { Task { await save() } }.disabled(busy || title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty) }
            }
            .interactiveDismissDisabled(busy)
            .onAppear { title = record?.title ?? ""; status = record?.status ?? statuses.first ?? ""; values = record?.values ?? defaults }
        }
    }
    private func entryHint(_ type: String) -> String {
        switch type {
        case "money": return "Digits and a period, like 12.50"
        case "integer": return "A whole number"
        case "score": return "A number from 1 to 5"
        default: return ""
        }
    }

    /// The server's own field rules, checked before sending so a slip is reported at once and
    /// in words (and announced) rather than after a round trip.
    private func validationProblem() -> String? {
        for field in fields {
            let value = (values[field.key] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
            if value.isEmpty {
                if field.required { return "\(field.label) is required." }
                continue
            }
            switch field.type {
            case "date":
                if APIDate.strictDay(value) == nil { return "\(field.label): choose a valid date." }
            case "money":
                // inc/at_services.php allows one more whole digit than api_money does.
                if checkedAmount(value, maxWholeDigits: 8) == nil { return amountFormatMessage(field.label, maxWholeDigits: 8) }
            case "integer":
                let digitsOnly = value.allSatisfy { ("0"..."9").contains($0) }
                if !digitsOnly || !(1...100_000).contains(Int(value) ?? 0) { return "\(field.label): enter a whole number from 1 to 100000." }
            case "score":
                if !["1", "2", "3", "4", "5"].contains(value) { return "\(field.label): choose 1 through 5." }
            default:
                break
            }
        }
        return nil
    }

    private func save() async {
        if let problem = validationProblem() { showFormError(problem, in: &error); return }
        // Money goes in the server's shape: a comma typed as the decimal mark ("12,50") is
        // sent with a period, as validationProblem() accepted it.
        var sent = values
        for field in fields where field.type == "money" {
            if let raw = sent[field.key], let amount = checkedAmount(raw, maxWholeDigits: 8) { sent[field.key] = amount }
        }
        busy = true; error = nil; defer { busy = false }
        do {
            var body: [String: Any] = ["kind": kind, "id": record?.id ?? 0, "version": record?.version ?? 0, "title": title, "status": status, "values": sent]
            // A new record (4.28.0): a retry after a lost reply is recognised and not stored twice.
            if record == nil { body["idem_key"] = idem.key(for: body) }
            let _: ATSaveResponse = try await api.call("at_save", body: body)
            idem.answered()
            // Spoken by the presenter once the sheet has gone: closing it moves VoiceOver focus,
            // which would cut off an announcement made here.
            let noun = atRecordNoun(kind)
            onSave(String(noun.prefix(1)).uppercased() + String(noun.dropFirst()) + " saved."); dismiss()
        } catch APIError.network where record == nil {
            // The connection can drop after the server has stored a new record, and at_save has
            // no duplicate check for one, so a second Save could store it twice. (An edit is safe
            // to retry: the version check refuses a repeat.)
            showFormError("The connection dropped before the server answered, so this \(atRecordNoun(kind)) may have been saved. Choose Cancel, then check the list (it reloads when this form closes) before saving it again.", in: &self.error)
        } catch { idem.failed(error); showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ATIssueSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let record: ATRecord
    /// Called with the confirmation to speak once the sheet has closed.
    let onSave: (String) -> Void
    @State private var choices: ATIssueChoices?
    @State private var client = ""
    @State private var equipment = ""
    @State private var due = ""
    @State private var busy = false
    @State private var error: String?
    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                if choices == nil && error == nil { ProgressView("Loading choices…") }
                if let choices {
                    Picker("Client (required)", selection: $client) { Text("Choose…").tag(""); ForEach(choices.clients) { Text($0.label).tag($0.value) } }
                    if choices.clients.isEmpty { Text("No client can receive this equipment right now.") }
                    Picker("Equipment (required)", selection: $equipment) { Text("Choose…").tag(""); ForEach(choices.equipment) { Text($0.label).tag($0.value) } }
                    if choices.equipment.isEmpty { Text(record.kind == "repair" ? "No loaner is available right now." : "No equipment is available to issue right now.") }
                    // A switch and a date picker, as OptionalDayField, but held to the days the
                    // server accepts: today through five years ahead.
                    let dueDay = APIDate.strictDay(due)
                    Toggle("Set return date", isOn: Binding(get: { dueDay != nil }, set: { due = $0 ? APIDate.day(Date()) : "" }))
                        .accessibilityHint(dueDay != nil ? "Turning this off clears the date." : "Turning this on sets the date to today, then you can change it.")
                    if let dueDay {
                        DatePicker("Return date", selection: Binding(get: { dueDay }, set: { due = APIDate.day($0) }),
                                   in: returnDateRange, displayedComponents: .date)
                            .accessibilityHint("From today to five years ahead.")
                    }
                }
            }.navigationTitle("Issue equipment")
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                    ToolbarItem(placement: .confirmationAction) {
                        Button("Issue") { Task { await issue() } }
                            .accessibilityHint(client.isEmpty || equipment.isEmpty ? "Choose a client and equipment first." : "")
                            .disabled(busy || client.isEmpty || equipment.isEmpty)
                    }
                }
                .interactiveDismissDisabled(busy)
                .task {
                    do {
                        // Nothing is preselected: both are chosen on purpose, and Issue stays off until they are.
                        choices = try await api.call("at_issue_choices", query: ["id": String(record.id)])
                    } catch { showFormError(error.localizedDescription, in: &self.error) }
                }
        }
    }
    /// Today through five years ahead: the server refuses a loan return date already past, or
    /// in a year more than five years from now.
    private var returnDateRange: ClosedRange<Date> {
        let today = Calendar.current.startOfDay(for: Date())
        return today...(Calendar.current.date(byAdding: .year, value: 5, to: Date()) ?? today)
    }
    private func issue() async {
        // Cleared first so the same error coming back is shown (and announced) again.
        busy = true; error = nil; defer { busy = false }
        do { let _: ATSaveResponse = try await api.call("at_issue", body: ["id": record.id, "client_id": Int(client) ?? 0, "equipment_id": Int(equipment) ?? 0, "due_date": due]); onSave("Equipment issued."); dismiss() }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ATMatchingView: View {
    @EnvironmentObject var api: API
    @State private var expertise = ""
    @State private var languages = ""
    @State private var areas = ""
    @State private var modality = ""
    @State private var matches: [ATMatch] = []
    @State private var error: String?
    @State private var busy = false
    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Section("Client needs (comma-separated)") {
                // Captions that stay visible once text is typed; each field carries its own name for VoiceOver.
                ATCaptionedField(label: "Expertise", text: $expertise)
                ATCaptionedField(label: "Languages / communication", text: $languages)
                ATCaptionedField(label: "Service areas", text: $areas)
                Picker("Delivery", selection: $modality) { Text("Any").tag(""); Text("In person").tag("In person"); Text("Remote").tag("Remote") }
                Button("Find matches") { Task { await search() } }.disabled(busy)
            }
            Section("Suggestions for the next 7 days") {
                ForEach(matches) { match in
                    VStack(alignment: .leading, spacing: 6) {
                        Text(match.name).font(.headline)
                        Text("Matched: \(match.matched.isEmpty ? "None specified" : match.matched)")
                        Text("Missing: \(match.missing.isEmpty ? "None" : match.missing)")
                        Text("Estimated available hours: \(match.availableHours, specifier: "%.1f")")
                        Text(match.availability)
                    }
                    // One element per instructor, so a swipe moves from one match to the next.
                    .accessibilityElement(children: .combine)
                }
            }
        }.navigationTitle("Instructor matching")
    }
    private func search() async {
        busy = true; error = nil; defer { busy = false }
        do {
            let r: ATMatchesResponse = try await api.call("at_matches", query: ["expertise": expertise, "languages": languages, "areas": areas, "modality": modality]); matches = r.matches; error = nil
            announce(matches.isEmpty ? "No instructor matches found." : "\(matches.count) instructor match\(matches.count == 1 ? "" : "es") found.")
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}
struct ATProfitabilityView: View {
    @EnvironmentObject var api: API
    @State private var rows: [ATProfit] = []
    @State private var error: String?
    @State private var grouping = "Track"
    /// Set once the report has answered, so "loading" and "nothing to report" are never confused.
    @State private var loaded = false
    var body: some View {
        List {
            Text("Submitted and paid invoice totals minus recorded expenses and labor. Missing cost rates make margins incomplete; unrecorded overhead and unbilled revenue are excluded.").font(.callout)
            if let error { ErrorBanner(message: error) }
            Picker("Group by", selection: $grouping) { ForEach(["Track", "Service", "Contract"], id: \.self) { Text($0).tag($0) } }
            if !loaded && error == nil { ProgressView("Loading profitability…") }
            if loaded && rows.isEmpty { Text("No invoiced tracks to report yet.") }
            if grouping != "Track" {
                ForEach(groupLabels, id: \.self) { label in
                    let members = rows.filter { (grouping == "Service" ? $0.service : $0.contract) == label }
                    Section(label) {
                        // One VoiceOver stop for the group's figures.
                        VStack(alignment: .leading, spacing: 6) {
                            Text("Invoiced: \(members.reduce(0) { $0 + $1.invoiced }, specifier: "%.2f")")
                            Text("Received: \(members.reduce(0) { $0 + $1.received }, specifier: "%.2f")")
                            Text("Direct cost: \(members.reduce(0) { $0 + $1.cost }, specifier: "%.2f")")
                            Text("Margin: \(members.reduce(0) { $0 + $1.margin }, specifier: "%.2f")")
                            Text("Hours without labor rates: \(members.reduce(0) { $0 + $1.unpricedHours }, specifier: "%.1f")")
                        }
                        .accessibilityElement(children: .combine)
                    }
                }
            } else { ForEach(rows) { row in
                Section(row.client + " / " + row.title) {
                    // One VoiceOver stop for the track's figures.
                    VStack(alignment: .leading, spacing: 6) {
                        Text(row.service + " · " + row.funder)
                        Text("Invoiced: \(row.invoiced, specifier: "%.2f") · Received: \(row.received, specifier: "%.2f")")
                        Text("Direct cost: \(row.cost, specifier: "%.2f") · Margin: \(row.margin, specifier: "%.2f")")
                        // In words with an icon in the text colour: system orange text is about 2.2:1.
                        if row.unpricedHours > 0 {
                            Label { Text("Missing labor rates for \(row.unpricedHours, specifier: "%.1f") hours") } icon: { Image(systemName: "exclamationmark.triangle").accessibilityHidden(true) }
                        }
                    }
                    .accessibilityElement(children: .combine)
                }
            } }
        }.navigationTitle("Profitability").task { await load() }.refreshable { await load() }
    }
    private var groupLabels: [String] { Array(Set(rows.map { grouping == "Service" ? $0.service : $0.contract })).sorted() }
    private func load() async {
        do {
            let r: ATProfitResponse = try await api.call("at_profitability"); rows = r.rows; error = nil
            if !loaded { announce(rows.isEmpty ? "No invoiced tracks to report yet." : "Profitability loaded for \(rows.count) track\(rows.count == 1 ? "" : "s").") }
            loaded = true
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct ATDraftTarget: Identifiable {
    let key: String
    let label: String
    var id: String { key }
}
struct ATAIDraftResponse: Decodable {
    let text: String
    /// The provider stopped at its output limit, so the draft ends before it was finished
    /// (false when an older server does not say).
    let truncated: Bool
    private enum CodingKeys: String, CodingKey { case text, truncated }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        text = try c.decode(String.self, forKey: .text)
        truncated = (try? c.decodeIfPresent(Bool.self, forKey: .truncated))
            ?? (try? c.decodeIfPresent(Int.self, forKey: .truncated)).map { $0 != 0 } ?? false
    }
}
struct ATAIDraftPanel: View {
    @EnvironmentObject var api: API
    let kind: String
    let targets: [ATDraftTarget]
    let context: [String: String]
    var recordId: Int = 0
    let apply: (String, String) -> Void
    @State private var field = ""
    @State private var instruction = ""
    @State private var draft = ""
    @State private var draftField = ""
    /// The draft stops before the end (the AI reached its output limit).
    @State private var draftIncomplete = false
    /// Each field's own text from before a draft was placed in it, kept until the sheet
    /// closes, so "Restore" always gives back the person's own text (not an earlier draft).
    @State private var previous: [String: String] = [:]
    /// The draft last placed in each field: when the field no longer holds it, the person has
    /// edited it since, and that text is what a later Restore must give back.
    @State private var applied: [String: String] = [:]
    @State private var busy = false
    @State private var error: String?
    @AccessibilityFocusState private var draftFocused: Bool
    var body: some View {
        Section("Work on this with AI") {
            Text("Draft, simplify, translate or revise. Review the result before applying it; drafting does not save or share the assignment.").font(.caption)
            Picker("Field to draft", selection: $field) { ForEach(targets) { Text($0.label).tag($0.key) } }
            VStack(alignment: .leading) {
                Text("What should AI help with?").font(.caption).accessibilityHidden(true)
                TextField("What should AI help with?", text: $instruction, axis: .vertical).lineLimit(2...5)
                    .accessibilityLabel("What should AI help with?")
            }
            Button(busy ? "Drafting…" : "Draft with AI") { Task { await generate() } }.disabled(busy || field.isEmpty)
            if let error { ErrorBanner(message: error) }
            if !draftField.isEmpty {
                // The field the draft is for is named, since the picker above may have moved on.
                let target = label(draftField)
                let heading = "AI draft for \(target)\(draftIncomplete ? " (incomplete)" : "") — review and edit"
                // Hidden from VoiceOver: the editor below carries the same words, and VoiceOver
                // is moved onto it when the draft arrives.
                Text(heading).font(.headline).accessibilityHidden(true)
                if draftIncomplete {
                    // In words with an icon, not colour alone, as on the website.
                    Label("The AI stopped before finishing. Check the end, or ask for a shorter draft.", systemImage: "exclamationmark.triangle")
                        .font(.callout.weight(.semibold))
                }
                TextEditor(text: $draft).frame(minHeight: 140).accessibilityLabel(heading).accessibilityFocused($draftFocused)
                Button("Use this draft for \(target)") {
                    // As on the website: the field's text is kept for Restore on the first draft,
                    // and again whenever it was changed by hand since the last draft went in, so
                    // Restore never throws away the person's own corrections.
                    let current = context[draftField] ?? ""
                    if previous[draftField] == nil || current != applied[draftField] { previous[draftField] = current }
                    apply(draftField, draft); applied[draftField] = draft
                    announce("Draft placed in \(target).")
                }.disabled(busy)
                if let original = previous[draftField] {
                    Button("Restore previous \(target) text") {
                        apply(draftField, original); previous[draftField] = nil; applied[draftField] = nil
                        announce("Previous \(target) text restored.")
                    }.disabled(busy)
                }
            }
        }.onAppear { if field.isEmpty { field = targets.first?.key ?? "" } }
    }
    private func label(_ key: String) -> String { targets.first(where: { $0.key == key })?.label ?? key }
    private func generate() async {
        busy = true; error = nil; defer { busy = false }
        let selected = field
        do {
            let result: ATAIDraftResponse = try await api.call("at_ai_draft", body: ["kind": kind, "field": selected, "values": context, "instruction": instruction, "id": recordId])
            draft = result.text; draftField = selected; draftIncomplete = result.truncated
            // The draft appears below the button with no focus move: say so, then put
            // VoiceOver on it.
            announce(result.truncated
                     ? "AI draft ready, but it is incomplete: the AI stopped before finishing. Check the end, or ask for a shorter draft."
                     : "AI draft ready. Review it before using it.")
            Task { try? await Task.sleep(nanoseconds: 900_000_000); draftFocused = true }
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}
