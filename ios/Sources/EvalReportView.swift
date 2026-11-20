import SwiftUI

// MARK: - Formal AT evaluation report (4.37.0, S7), as eval_report.php

/// One section of the evaluation report: its key, label, text and whether it still holds the
/// prefilled wording.
struct EvalSection: Decodable {
    let key: String
    let label: String
    let text: String
    let `default`: Bool?
}

/// eval_report's reply: the assessment, the four sections, the recipient (the counselor's email
/// when it is confirmed), the signer's name, the release warning and whether AI drafting is on.
struct EvalAssessmentInfo: Decodable { let id: Int; let date: String?; let clientId: Int?; let client: String? }
struct EvalReportReply: Decodable {
    let assessment: EvalAssessmentInfo?
    let sections: [EvalSection]
    let to: String?
    let counselorVerified: Bool?
    let signer: String?
    let releaseWarning: String?
    let ai: Bool?
}

/// ai_eval_draft's reply: the sections after drafting, and eval_report.php's message.
struct EvalDraftReply: Decodable {
    let sections: [EvalSection]
    let message: String?
    let truncated: Bool?
}

/// eval_report's accessible HTML (POST with html: true).
struct EvalHTMLReply: Decodable { let html: String; let file: String? }

/// The four sections in eval_report.php's order, with their labels.
let evalSectionOrder: [(key: String, label: String)] = [
    ("background", "Background"), ("findings", "Evaluation findings"),
    ("recommendations", "Recommendations"), ("justification", "Justification of need"),
]

/// The sections as the server reads them: {background, findings, recommendations, justification}.
func evalSectionsBody(_ texts: [String: String]) -> [String: String] {
    Dictionary(uniqueKeysWithValues: evalSectionOrder.map { ($0.key, texts[$0.key] ?? "") })
}

/// The drafted sections applied: a section empty or still at its prefilled wording takes the
/// draft; one written in keeps exactly what was typed.
func evalApplyDraft(current: [String: String], defaults: [String: String], drafted: [EvalSection]) -> [String: String] {
    var out = current
    for s in drafted {
        let now = (current[s.key] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        let prefilled = defaults[s.key].map { $0.trimmingCharacters(in: .whitespacesAndNewlines) == now } ?? false
        if now.isEmpty || prefilled { out[s.key] = s.text }
    }
    return out
}

/// The confirmation's title, naming the recipient.
func evalConfirmTitle(_ to: String) -> String {
    "Email the signed evaluation report to \(to.trimmingCharacters(in: .whitespacesAndNewlines))?"
}

struct EvalReportView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let assessmentId: Int
    let assessmentDate: String
    /// Given the server's sentence once the report is emailed; said when this sheet has gone.
    let onSent: (String) -> Void
    @State private var reply: EvalReportReply?
    @State private var texts: [String: String] = [:]
    /// The prefilled wording of each section, as loaded.
    @State private var defaults: [String: String] = [:]
    @State private var to = ""
    @State private var signature = ""
    @State private var error: String?
    @State private var drafting = false
    @State private var draftNotice: String?
    @State private var sending = false
    @State private var confirmingSend = false
    @State private var idem = IdemKey()
    @State private var sharing = false
    @State private var shareURL: URL?

    private var aiOn: Bool { (reply?.ai ?? false) && api.aiHas("draft") }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if reply == nil && error == nil { ProgressView("Loading the evaluation report…") }
                if let reply {
                    if let warning = reply.releaseWarning, !warning.isEmpty {
                        Section { Label(warning, systemImage: "exclamationmark.triangle").font(.callout.weight(.semibold)) }
                    }
                    Section {
                        ForEach(evalSectionOrder, id: \.key) { s in
                            CaptionedField(label: s.label, text: binding(s.key), lines: 4...14, errorName: s.label)
                                .disabled(drafting)
                        }
                    }
                    if aiOn {
                        Section("Draft with AI") {
                            Text("“Draft narrative with AI” fills only the sections above that are empty or still hold the prefilled text; a section you have written in is kept as it is. To have it drafted, clear it first. Review and edit every section before sending.")
                                .font(.callout)
                            Button(drafting ? "Drafting…" : "Draft narrative with AI") { Task { await draft() } }
                                .disabled(drafting || sending)
                            if let draftNotice { Label(draftNotice, systemImage: "exclamationmark.triangle").font(.callout.weight(.semibold)) }
                        }
                    }
                    Section {
                        CaptionedField(label: "Email to (required to email)", text: $to, keyboard: .emailAddress, errorName: "Email to")
                            .textInputAutocapitalization(.never).autocorrectionDisabled()
                        CaptionedField(label: "Electronic signature: type your full name (\(reply.signer ?? api.userName)) (required to email)",
                                       text: $signature, errorName: "Electronic signature")
                            .textInputAutocapitalization(.words)
                        Button(sending ? "Sending…" : "Email signed report to counselor") { askToSend() }
                            .disabled(sending || drafting)
                    }
                    Section {
                        Button(sharing ? "Preparing…" : "Share accessible HTML") { Task { await shareHTML() } }
                            .disabled(sharing)
                    }
                }
            }
            .navigationTitle("Evaluation report for the assessment of \(assessmentDate)")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Close") { dismiss() }.disabled(sending) }
            }
            .interactiveDismissDisabled(sending)
            .confirmationDialog(evalConfirmTitle(to), isPresented: $confirmingSend, titleVisibility: .visible) {
                Button("Email signed report") { Task { await send() } }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("It is signed by you and logged on the client's file.")
            }
            .sheet(item: Binding(get: { shareURL.map { SharedFile(url: $0) } }, set: { if $0 == nil { shareURL = nil } })) { file in
                FileActivitySheet(url: file.url)
            }
            .task { if reply == nil { await load() } }
        }
    }

    private func binding(_ key: String) -> Binding<String> {
        Binding(get: { texts[key] ?? "" }, set: { texts[key] = $0 })
    }

    private func apply(_ sections: [EvalSection]) {
        for s in sections { texts[s.key] = s.text }
        for s in sections where s.default == true { defaults[s.key] = s.text }
    }

    /// The AI's sections applied as eval_report.php drafts them: only a section that is empty or
    /// still holds the prefilled wording takes the draft; a section written in is never changed.
    private func applyDraft(_ sections: [EvalSection]) {
        texts = evalApplyDraft(current: texts, defaults: defaults, drafted: sections)
    }

    private func load() async {
        do {
            let r: EvalReportReply = try await api.call("eval_report", query: ["assessment_id": String(assessmentId)])
            reply = r
            apply(r.sections)
            if to.isEmpty { to = r.to ?? "" }
            error = nil
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func fieldName(_ key: String) -> String? {
        if key == "to" { return "Email to" }
        if key == "signature" { return "Electronic signature" }
        return evalSectionOrder.first { $0.key == key }?.label
    }

    private func refusalField(_ refused: [String: String]) -> String? {
        (["to", "signature"] + evalSectionOrder.map(\.key)).first { refused[$0] != nil }.flatMap(fieldName)
    }

    /// "Draft narrative with AI": the server drafts only the empty or prefilled sections and
    /// sends all four back; "Drafting…" is said as it starts and its message once.
    private func draft() async {
        drafting = true; error = nil; draftNotice = nil
        announce("Drafting…")
        defer { drafting = false }
        do {
            let r: EvalDraftReply = try await api.call("ai_eval_draft", method: "POST",
                                                       body: ["assessment_id": assessmentId, "sections": evalSectionsBody(texts)])
            applyDraft(r.sections)
            var said = r.message ?? "Review and edit every section before sending."
            if r.truncated == true { draftNotice = aiDraftIncomplete; said += " " + aiDraftIncomplete }
            if let left = r.sections.lazy.compactMap({ placeholderNotice($0.text) }).first { said += " " + left }
            announce(said)
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func askToSend() {
        if to.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Enter a valid email address, for example name@agency.org.", in: &error, field: "Email to"); return
        }
        if signature.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Type your full name (\(reply?.signer ?? api.userName)) to sign before emailing.", in: &error, field: "Electronic signature"); return
        }
        confirmingSend = true
    }

    private func send() async {
        sending = true; error = nil
        var body: [String: Any] = ["assessment_id": assessmentId, "sections": evalSectionsBody(texts),
                                   "to": to.trimmingCharacters(in: .whitespacesAndNewlines),
                                   "signature": signature.trimmingCharacters(in: .whitespacesAndNewlines)]
        body["idem_key"] = idem.key(for: body)
        var refused: [String: String] = [:]
        do {
            let r: MessageReply = try await api.call("send_eval_report", method: "POST", body: body, refusal: { refused = refusalFields($0) })
            idem.answered()
            onSent(r.message ?? "Evaluation report emailed to \(to) and logged on the client's file.")
            dismiss()
        } catch {
            idem.failed(error)
            sending = false
            showFormError(IdemKey.message(for: error, what: "the report"), in: &self.error, field: refusalField(refused))
        }
    }

    /// The unsigned accessible HTML of the sections as they stand, for the share sheet.
    private func shareHTML() async {
        sharing = true; error = nil
        defer { sharing = false }
        do {
            let r: EvalHTMLReply = try await api.call("eval_report", method: "POST",
                                                      body: ["assessment_id": assessmentId, "sections": evalSectionsBody(texts), "html": true])
            guard let url = shareableFile(r.html, named: r.file ?? "", fallback: "evaluation-report.html") else {
                showFormError("The report could not be prepared for sharing. Try again.", in: &error); return
            }
            shareURL = url
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
