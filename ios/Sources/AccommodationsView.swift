import SwiftUI

struct WorksiteRow: Decodable, Identifiable {
    let id: Int; let employer: String?; let jobTitle: String?; let status: String
    let assessmentDate: String?; let followUpDate: String?; let clientName: String; let clientId: Int
    private enum CodingKeys: String, CodingKey { case id, employer, jobTitle, status, assessmentDate, followUpDate, clientName, clientId }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        employer = try? c.decodeIfPresent(String.self, forKey: .employer)
        jobTitle = try? c.decodeIfPresent(String.self, forKey: .jobTitle)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        assessmentDate = try? c.decodeIfPresent(String.self, forKey: .assessmentDate)
        followUpDate = try? c.decodeIfPresent(String.self, forKey: .followUpDate)
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
    }
}
struct WorksiteRowsResponse: Decodable { let assessments: [WorksiteRow] }
struct Worksite: Decodable {
    let id: Int; let clientId: Int; let employer: String?; let jobTitle: String?
    let worksiteLocation: String?; let assessmentDate: String?; let jobTasks: String?
    let barriers: String?; let recommendations: String?; let status: String
    let followUpDate: String?; let outcome: String?
    let clientName: String?
}
struct WorksiteResponse: Decodable { let assessment: Worksite }

let worksiteStatuses = ["Scheduled", "Assessed", "Recommendations Sent", "Implemented", "Follow-up", "Closed"]

struct AccommodationsView: View {
    @EnvironmentObject var api: API
    @State private var rows: [WorksiteRow] = []
    @State private var loaded = false
    @State private var error: String?
    @State private var showNew = false
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            // The empty-state claim only after a load has actually returned no rows.
            if loaded && rows.isEmpty {
                Text("No worksite assessments yet.")
            } else if !loaded && error == nil {
                ProgressView("Loading assessments…")
            }
            ForEach(rows) { r in
                NavigationLink {
                    WorksiteDetailView(id: r.id, onChanged: { Task { await load() } })
                } label: {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(r.clientName).font(.body.weight(.semibold))
                        // Primary, not secondary: grey caption text is about 3.4:1 on a white row.
                        Text([r.employer, r.jobTitle].compactMap { $0 }.joined(separator: " · ")).font(.caption)
                        Text(r.status).font(.caption2.weight(.semibold)).foregroundStyle(Color.brand)
                    }
                }
            }
        }
        .navigationTitle("Accommodations")
        .refreshable { await load() }
        .task { await load() }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add worksite assessment") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) { WorksiteNewSheet { sheetResult = "Assessment created."; Task { await load() } } }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    private func load() async {
        do { error = nil; let r: WorksiteRowsResponse = try await api.call("worksite_assessments"); rows = r.assessments; loaded = true }
        catch { self.error = error.localizedDescription }
    }
}

struct WorksiteDetailView: View {
    @EnvironmentObject var api: API
    let id: Int
    let onChanged: () -> Void
    @State private var w: Worksite?
    @State private var error: String?
    @State private var busy = false
    @State private var loading = false

    @State private var employer = ""; @State private var jobTitle = ""; @State private var location = ""
    @State private var jobTasks = ""; @State private var barriers = ""; @State private var recommendations = ""
    @State private var status = "Scheduled"; @State private var outcome = ""
    @State private var hasDate = false; @State private var date = Date()
    @State private var hasFollow = false; @State private var follow = Date()

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            // The form appears only once the stored assessment has loaded: a blank form saved
            // after a failed load would overwrite the record with empty fields.
            if let w {
                if let name = w.clientName, !name.isEmpty {
                    Section { LabeledContent("Client", value: name) }
                }
                Section("Worksite") {
                    // Names that stay visible once the stored values fill the fields.
                    WorksiteCaptionedField(label: "Employer", text: $employer)
                    WorksiteCaptionedField(label: "Job title", text: $jobTitle)
                    WorksiteCaptionedField(label: "Worksite location", text: $location)
                    Toggle("Assessment date", isOn: $hasDate)
                    if hasDate { DatePicker("Date", selection: $date, displayedComponents: .date) }
                }
                Section("Essential job tasks") { TextEditor(text: $jobTasks).frame(minHeight: 70).accessibilityLabel("Essential job tasks") }
                Section("Barriers identified") { TextEditor(text: $barriers).frame(minHeight: 70).accessibilityLabel("Barriers identified") }
                Section("Recommended accommodations") { TextEditor(text: $recommendations).frame(minHeight: 90).accessibilityLabel("Recommended accommodations") }
                Section("Status & follow-up") {
                    Picker("Status", selection: $status) { ForEach(worksiteStatuses, id: \.self) { Text($0) } }
                    Toggle("Follow-up date", isOn: $hasFollow)
                    if hasFollow { DatePicker("Follow-up", selection: $follow, displayedComponents: .date) }
                }
                Section("Outcome notes") { TextEditor(text: $outcome).frame(minHeight: 60).accessibilityLabel("Outcome notes") }
            } else if error == nil || loading {
                ProgressView("Loading assessment…")
            } else {
                Button { Task { await load() } } label: { Label("Try loading the assessment again", systemImage: "arrow.clockwise") }
            }
        }
        .navigationTitle("Assessment")
        .navigationBarTitleDisplayMode(.inline)
        // Load once: coming back to this screen must not replace edits that are not saved yet.
        .task { if w == nil { await load() } }
        .toolbar { ToolbarItem(placement: .confirmationAction) { Button("Save") { Task { await save() } }.disabled(busy || w == nil) } }
    }

    private func load() async {
        loading = true; defer { loading = false }
        do {
            error = nil
            let r: WorksiteResponse = try await api.call("worksite_assessment", query: ["id": String(id)])
            let a = r.assessment; w = a
            employer = a.employer ?? ""; jobTitle = a.jobTitle ?? ""; location = a.worksiteLocation ?? ""
            jobTasks = a.jobTasks ?? ""; barriers = a.barriers ?? ""; recommendations = a.recommendations ?? ""
            status = a.status; outcome = a.outcome ?? ""
            if let d = a.assessmentDate, let pd = ymd(d) { hasDate = true; date = pd }
            if let d = a.followUpDate, let pd = ymd(d) { hasFollow = true; follow = pd }
        } catch { self.error = error.localizedDescription }
    }
    private func save() async {
        guard w != nil else { return }
        busy = true; error = nil
        var b: [String: Any] = ["id": id, "employer": employer, "job_title": jobTitle, "worksite_location": location,
            "job_tasks": jobTasks, "barriers": barriers, "recommendations": recommendations, "status": status, "outcome": outcome]
        // The same calendar day the pickers show (and the one ymd() read), so a save can't shift it.
        b["assessment_date"] = hasDate ? APIDate.day(date) : ""
        b["follow_up_date"] = hasFollow ? APIDate.day(follow) : ""
        do { let _: OKResponse = try await api.call("save_worksite", body: b); announce("Assessment saved."); onChanged() }
        catch { self.error = error.localizedDescription }
        busy = false
    }
}

struct WorksiteNewSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var selectedClient: Int?
    @State private var employer = ""; @State private var jobTitle = ""
    @State private var date = Date(); @State private var busy = false; @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                NewRecordClientPicker(title: "Client (required)", selection: $selectedClient)
                WorksiteCaptionedField(label: "Employer", text: $employer)
                WorksiteCaptionedField(label: "Job title", text: $jobTitle)
                DatePicker("Assessment date", selection: $date, displayedComponents: .date)
            }
            .navigationTitle("New assessment")
            .navigationBarTitleDisplayMode(.inline)
            // A created assessment can't be called back once sent, so the sheet stays until it finishes.
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Creating…" : "Create") { Task { await submit() } }
                        .accessibilityHint(selectedClient == nil ? "Choose a client first." : "")
                        .disabled(busy || selectedClient == nil)
                }
            }
            .interactiveDismissDisabled(busy)
        }
    }
    private func submit() async {
        guard let cid = selectedClient else { return }
        busy = true; error = nil
        do { let _: OKResponse = try await api.call("add_worksite", body: ["client_id": cid, "employer": employer, "job_title": jobTitle, "assessment_date": APIDate.day(date)]); onDone(); dismiss() }
        catch { self.error = error.localizedDescription; busy = false }
    }
}

/// A text field whose name stays visible above it once it holds text (a placeholder alone
/// disappears). The field carries the same name for VoiceOver, so the caption is hidden.
private struct WorksiteCaptionedField: View {
    let label: String
    @Binding var text: String
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(.caption).accessibilityHidden(true)
            TextField(label, text: $text).accessibilityLabel(label)
        }
    }
}

/// Parse a yyyy-MM-dd (possibly datetime) string into a Date.
func ymd(_ s: String) -> Date? { APIDate.parseDay(s) }
