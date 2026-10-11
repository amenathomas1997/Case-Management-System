import SwiftUI

struct Instrument: Decodable, Identifiable { let id: Int; let name: String; let description: String?; let items: Int }
struct InstrumentsResponse: Decodable { let instruments: [Instrument] }
struct InstrumentItem: Decodable, Identifiable { let id: Int; let label: String }
struct ScoreOption: Decodable { let value: Int; let label: String }
struct InstrumentItemsResponse: Decodable { let items: [InstrumentItem]; let scaleOptions: [ScoreOption]? }
struct ScoredRow: Decodable, Identifiable { let id: Int; let assessedOn: String; let totalScore: Int; let maxScore: Int; let pct: Double?; let instrument: String; let assessor: String? }
struct ScoredListResponse: Decodable { let assessments: [ScoredRow] }
struct ScoredItemRow: Decodable, Identifiable { var id: String { label }; let label: String; let score: Int }
struct ScoredDetail: Decodable { let id: Int; let instrument: String; let assessedOn: String; let totalScore: Int; let maxScore: Int; let pct: Double?; let notes: String?; let assessor: String?; var instrumentId: Int? }
struct ScoredDetailResponse: Decodable { let assessment: ScoredDetail; let items: [ScoredItemRow] }

let scoreLabels = ["0 — Unable", "1 — Maximal assistance", "2 — Moderate assistance", "3 — Minimal assistance / prompts", "4 — Independent"]

struct ScoredAssessmentsView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    @State private var rows: [ScoredRow] = []
    @State private var instruments: [Instrument] = []
    // Each list says it is empty only after its own request succeeded.
    @State private var rowsLoaded = false
    @State private var instrumentsLoaded = false
    @State private var error: String?
    @State private var administering: Instrument?
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            Section("Administer") {
                if instrumentsLoaded && instruments.isEmpty {
                    Text("No instruments defined.").foregroundStyle(Color.secondaryText)
                } else if !instrumentsLoaded && error == nil {
                    ProgressView("Loading instruments…")
                }
                ForEach(instruments) { ins in
                    Button { administering = ins } label: {
                        HStack { Text(ins.name); Spacer(); Text("\(ins.items) items").font(.caption).foregroundStyle(Color.secondaryText) }
                    }
                }
            }
            Section("History") {
                if rowsLoaded && rows.isEmpty {
                    Text("None yet.").foregroundStyle(Color.secondaryText)
                } else if !rowsLoaded && error == nil {
                    ProgressView("Loading history…")
                }
                ForEach(rows) { r in
                    NavigationLink { ScoredDetailView(id: r.id) } label: {
                        VStack(alignment: .leading, spacing: 2) {
                            let pct = Int((r.pct ?? (r.maxScore > 0 ? Double(r.totalScore) / Double(r.maxScore) * 100 : 0)).rounded())
                            Text(r.instrument).font(.body.weight(.semibold))
                            Text("\(formatDate(r.assessedOn)) · \(r.totalScore)/\(r.maxScore) (\(pct)%)").font(.caption).foregroundStyle(Color.secondaryText)
                        }
                    }
                }
            }
        }
        .navigationTitle("Scored assessments")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await load() }
        .task { await load() }
        .sheet(item: $administering, onDismiss: announceSheetResult) { ins in
            AdministerSheet(clientId: clientId, instrument: ins) { sheetResult = "Assessment saved."; Task { await load() } }
        }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        error = nil
        // Two separate requests: one failing must not leave the other list unloaded.
        do {
            let a: ScoredListResponse = try await api.call("scored_assessments", query: ["client": String(clientId)])
            rows = a.assessments; rowsLoaded = true
        } catch { self.error = error.localizedDescription }
        do {
            let i: InstrumentsResponse = try await api.call("instruments")
            instruments = i.instruments; instrumentsLoaded = true
        } catch { if self.error == nil { self.error = error.localizedDescription } }
    }
}

struct ScoredDetailView: View {
    @EnvironmentObject var api: API
    let id: Int
    @State private var data: ScoredDetailResponse?
    /// The instrument's rating labels by value, so each item reads as the website shows it.
    @State private var scaleLabels: [Int: String] = [:]
    @State private var error: String?
    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let d = data {
                Section {
                    let pct = Int((d.assessment.pct ?? (d.assessment.maxScore > 0 ? Double(d.assessment.totalScore) / Double(d.assessment.maxScore) * 100 : 0)).rounded())
                    LabeledContent("Instrument", value: d.assessment.instrument)
                    LabeledContent("Score", value: "\(d.assessment.totalScore)/\(d.assessment.maxScore) (\(pct)%)")
                    LabeledContent("Date", value: formatDate(d.assessment.assessedOn))
                }
                Section("Items") {
                    // Enumerate for stable unique ids: two items can share a label, and
                    // ScoredItemRow's id is the label, which would otherwise collapse rows.
                    ForEach(Array(d.items.enumerated()), id: \.offset) { _, it in
                        HStack { Text(it.label); Spacer(); Text(scaleLabels[it.score] ?? "\(it.score)").font(.body.weight(.semibold)).foregroundStyle(Color.brand) }
                            .accessibilityElement(children: .combine)
                    }
                }
                if let n = d.assessment.notes, !n.isEmpty { Section("Notes") { Text(n) } }
            } else if error == nil {
                ProgressView("Loading assessment…")
            }
        }
        .navigationTitle("Assessment")
        .navigationBarTitleDisplayMode(.inline)
        .task {
            do {
                error = nil
                let d: ScoredDetailResponse = try await api.call("scored_assessment", query: ["id": String(id)])
                data = d
                // The labels are a reading aid: if they cannot be fetched, the numbers shown are
                // still the stored scores, so that failure is not reported as an error.
                if let iid = d.assessment.instrumentId,
                   let r: InstrumentItemsResponse = try? await api.call("instrument_items", query: ["id": String(iid)]),
                   let options = r.scaleOptions {
                    scaleLabels = Dictionary(options.map { ($0.value, $0.label) }, uniquingKeysWith: { first, _ in first })
                }
            }
            catch { self.error = error.localizedDescription }
        }
    }
}

struct AdministerSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let instrument: Instrument
    let onDone: () -> Void
    @State private var items: [InstrumentItem] = []
    /// Set once the instrument's items have loaded, so "no items" is never claimed before then.
    @State private var itemsLoaded = false
    /// Only the ratings the assessor chose: as on the website, nothing is preselected, so an
    /// item skipped by mistake is never saved at a default rating.
    @State private var scores: [Int: Int] = [:]
    @State private var scaleOptions = scoreLabels.enumerated().map { ScoreOption(value: $0.offset, label: $0.element) }
    @State private var date = Date()
    @State private var notes = ""
    @State private var busy = false
    @State private var error: String?
    /// A saved assessment cannot be edited, so its total is read back before it is sent.
    @State private var confirmingSave = false
    @State private var confirmingDiscard = false

    /// Ratings or notes that closing the sheet would throw away.
    private var hasEdits: Bool { !scores.isEmpty || !notes.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    private var total: Int { items.reduce(0) { $0 + (scores[$1.id] ?? 0) } }
    /// Items still waiting for a rating; Save stays off until there are none.
    private var unratedCount: Int { items.filter { scores[$0.id] == nil }.count }
    /// Why Save is off, shown under the items and read as the Save button's hint.
    private var unratedNotice: String {
        unratedCount == 1 ? "1 of \(items.count) items is not rated yet. Rate every item to save."
                          : "\(unratedCount) of \(items.count) items are not rated yet. Rate every item to save."
    }

    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                DatePicker("Assessment date", selection: $date, displayedComponents: .date)
                if !itemsLoaded && error == nil {
                    ProgressView("Loading items…")
                } else if itemsLoaded && items.isEmpty {
                    Text("This instrument has no items yet, so it cannot be scored. An administrator can add items on the website.")
                }
                ForEach(items) { it in
                    VStack(alignment: .leading) {
                        Text(it.label).font(.callout)
                        Picker("Score", selection: Binding<Int?>(get: { scores[it.id] }, set: { scores[it.id] = $0 })) {
                            Text("Choose a rating").tag(nil as Int?)
                            ForEach(scaleOptions, id: \.value) { Text($0.label).tag($0.value as Int?) }
                        }
                        .pickerStyle(.menu)
                        .accessibilityLabel("Score for \(it.label)")
                    }
                }
                if itemsLoaded && unratedCount > 0 {
                    Text(unratedNotice).font(.callout).foregroundStyle(Color.secondaryText)
                }
                Section { TextField("Notes", text: $notes, axis: .vertical).lineLimit(2...5) }
            }
            .navigationTitle(instrument.name)
            .navigationBarTitleDisplayMode(.inline)
            // A saved assessment can't be called back once sent, so the sheet stays until it finishes.
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { requestClose() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Saving…" : "Save") { reviewSave() }
                        .disabled(busy || items.isEmpty || unratedCount > 0)
                        .accessibilityHint(unratedCount > 0 ? unratedNotice : "")
                }
            }
            // Entered ratings are not thrown away by a swipe down; Cancel and the VoiceOver
            // escape gesture ask first.
            .interactiveDismissDisabled(busy || hasEdits)
            .accessibilityAction(.escape) { requestClose() }
            .confirmationDialog("Save this assessment? All \(items.count) items rated, total score \(total). A saved assessment cannot be changed.",
                                isPresented: $confirmingSave, titleVisibility: .visible) {
                Button("Save assessment") { Task { await save() } }
                Button("Go back", role: .cancel) {}
            }
            .confirmationDialog("Discard this assessment? The ratings entered will be lost.",
                                isPresented: $confirmingDiscard, titleVisibility: .visible) {
                Button("Discard assessment", role: .destructive) { dismiss() }
                Button("Keep rating", role: .cancel) {}
            }
            .task {
                do {
                    let r: InstrumentItemsResponse = try await api.call("instrument_items", query: ["id": String(instrument.id)])
                    items = r.items
                    if let options = r.scaleOptions, !options.isEmpty { scaleOptions = options }
                    itemsLoaded = true
                } catch { self.error = error.localizedDescription }
            }
        }
    }
    private func requestClose() {
        guard !busy else { return }
        if hasEdits { confirmingDiscard = true } else { dismiss() }
    }
    /// Every item must be rated (as on the website); then the total is confirmed. Save is off
    /// until then, so this check only guards against the items changing underneath it. A
    /// server refusal (422) names the item and is shown by save() as it is.
    private func reviewSave() {
        let unrated = items.filter { scores[$0.id] == nil }
        if let first = unrated.first {
            let count = unrated.count == 1 ? "1 item is" : "\(unrated.count) items are"
            showFormError("Choose a rating for every item: \(count) not rated yet, starting with \"\(first.label)\".", in: &error)
            return
        }
        confirmingSave = true
    }
    private func save() async {
        busy = true; error = nil
        var scoreDict: [String: Int] = [:]
        for it in items { if let v = scores[it.id] { scoreDict[String(it.id)] = v } }
        do {
            let _: OKResponse = try await api.call("save_scored", body: ["client_id": clientId, "instrument_id": instrument.id, "assessed_on": APIDate.day(date), "notes": notes, "scores": scoreDict])
            // The list announces the result once this sheet has gone.
            onDone(); dismiss()
        } catch { self.error = error.localizedDescription; busy = false }
    }
}
