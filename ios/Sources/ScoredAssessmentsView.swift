import SwiftUI

struct Instrument: Decodable, Identifiable { let id: Int; let name: String; let description: String?; let items: Int }
struct InstrumentsResponse: Decodable { let instruments: [Instrument] }
struct InstrumentItem: Decodable, Identifiable { let id: Int; let label: String }
struct ScoreOption: Decodable { let value: Int; let label: String }
struct InstrumentItemsResponse: Decodable { let items: [InstrumentItem]; let scaleOptions: [ScoreOption]? }
struct ScoredRow: Decodable, Identifiable {
    let id: Int; let assessedOn: String; let totalScore: Int; let maxScore: Int; let pct: Double?; let instrument: String; let assessor: String?
    /// The instrument's rating scale and the number of items rated (absent from older servers).
    let scaleMin: Int?; let scaleMax: Int?; let itemCount: Int?
}
struct ScoredListResponse: Decodable { let assessments: [ScoredRow] }
struct ScoredItemRow: Decodable, Identifiable { var id: String { label }; let label: String; let score: Int }
struct ScoredDetail: Decodable {
    let id: Int; let instrument: String; let assessedOn: String; let totalScore: Int; let maxScore: Int; let pct: Double?; let notes: String?; let assessor: String?; var instrumentId: Int?
    /// The instrument's rating scale (absent from older servers).
    let scaleMin: Int?; let scaleMax: Int?
}
struct ScoredDetailResponse: Decodable { let assessment: ScoredDetail; let items: [ScoredItemRow] }

let scoreLabels = ["0 — Unable", "1 — Maximal assistance", "2 — Moderate assistance", "3 — Minimal assistance / prompts", "4 — Independent"]

/// The lowest total an assessment could score: the items rated times the scale's lowest rating
/// (a scale whose highest rating is not above its lowest is the 0–4 fallback, as on the
/// website). Nil when the scale is not known.
private func lowestTotal(items: Int?, scaleMin: Int?, scaleMax: Int?) -> Int? {
    guard let items, let scaleMin else { return nil }
    if let scaleMax, scaleMax <= scaleMin { return 0 }
    return items * scaleMin
}

/// A score as the website shows it: "30 of 40 (75%)", or, for a scale that does not start at
/// 0, "30 on a range of 10 to 50 (50%)": the percentage runs from the lowest possible total, so
/// "30 of 50" would not match it. With the lowest total unknown (an older server) the total is
/// read against the maximum.
private func scoreText(total: Int, maxScore: Int, pct: Double?, lowest: Int?) -> String {
    let low = lowest ?? 0
    let percent = Int((pct ?? (maxScore > low ? Double(total - low) / Double(maxScore - low) * 100 : 0)).rounded())
    return low == 0 ? "\(total) of \(maxScore) (\(percent)%)" : "\(total) on a range of \(low) to \(maxScore) (\(percent)%)"
}

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
                            let score = scoreText(total: r.totalScore, maxScore: r.maxScore, pct: r.pct,
                                                  lowest: lowestTotal(items: r.itemCount, scaleMin: r.scaleMin, scaleMax: r.scaleMax))
                            Text(r.instrument).font(.body.weight(.semibold))
                            Text("\(formatDate(r.assessedOn)) · \(score)").font(.caption).foregroundStyle(Color.secondaryText)
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
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        do {
            let i: InstrumentsResponse = try await api.call("instruments")
            instruments = i.instruments; instrumentsLoaded = true
        } catch { if self.error == nil { showFormError(error.localizedDescription, in: &self.error) } }
    }
}

struct ScoredDetailView: View {
    @EnvironmentObject var api: API
    let id: Int
    @State private var data: ScoredDetailResponse?
    /// The instrument's rating labels by value, so each item reads as the website shows it.
    @State private var scaleLabels: [Int: String] = [:]
    @State private var error: String?
    /// The lowest total the assessment could score: from the server, or else from the scale
    /// read with the labels (nil when neither is known).
    private func lowest(_ d: ScoredDetailResponse) -> Int? {
        lowestTotal(items: d.items.count, scaleMin: d.assessment.scaleMin, scaleMax: d.assessment.scaleMax)
            ?? scaleLabels.keys.min().map { d.items.count * $0 }
    }
    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let d = data {
                Section {
                    LabeledContent("Instrument", value: d.assessment.instrument)
                    LabeledContent("Score", value: scoreText(total: d.assessment.totalScore, maxScore: d.assessment.maxScore,
                                                             pct: d.assessment.pct, lowest: lowest(d)))
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
                // The labels are a reading aid: if they cannot be fetched, the numbers shown are
                // still the stored scores, so that failure is not reported as an error. Read
                // before the assessment is shown, since the scale they give can change how the
                // score line reads.
                if let iid = d.assessment.instrumentId,
                   let r: InstrumentItemsResponse = try? await api.call("instrument_items", query: ["id": String(iid)]),
                   let options = r.scaleOptions {
                    scaleLabels = Dictionary(options.map { ($0.value, $0.label) }, uniquingKeysWith: { first, _ in first })
                }
                data = d
            }
            catch { showFormError(error.localizedDescription, in: &self.error) }
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
                } catch { showFormError(error.localizedDescription, in: &self.error) }
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
        } catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}
