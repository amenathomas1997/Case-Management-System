import SwiftUI

// MARK: - 4.37.0 shared pieces (services, equipment and reports; plan-4370)

/// The field a refusal is about: the first of `order` that the server's `errors` names, as the
/// label the form marks with `errorField`. Nil when the refusal names no field of this form.
func refusedFieldName(_ fields: [String: String], order: [String], names: [String: String]) -> String? {
    order.first { fields[$0] != nil }.flatMap { names[$0] }
}

/// The server's `errors` from a refusal, as field → sentence.
func refusalFields(_ reply: [String: Any]) -> [String: String] {
    (reply["errors"] as? [String: String]) ?? [:]
}

/// An AI draft put into a field as the website does: an empty field gets the draft; otherwise
/// the draft goes below what was typed, after one line break. Typed text is never replaced.
func mergeDraft(_ typed: String, _ draft: String) -> String {
    var kept = typed
    while let last = kept.last, last.isWhitespace { kept.removeLast() }
    return kept.isEmpty ? draft : kept + "\n" + draft
}

/// The first bracketed placeholder still in a text ("[Recommendations to be completed]"), if any.
func firstPlaceholder(_ text: String) -> String? {
    // Any bracketed tag but "[not on file]" (the letters' mark for a detail the record lacks).
    var rest = text[...]
    while let r = rest.range(of: #"\[[^\[\]\n]{1,60}\]"#, options: .regularExpression) {
        let tag = String(rest[r])
        if tag != "[not on file]" { return tag }
        rest = rest[r.upperBound...]
    }
    return nil
}

/// Said when a draft still holds a placeholder (plan-4370 AI rule).
func placeholderNotice(_ text: String) -> String? {
    firstPlaceholder(text).map { "It still contains the placeholder \($0): replace it with the actual detail before sending." }
}

/// The notice for an AI draft that stopped before the end (S5, S8 and the other drafts).
let aiDraftIncomplete = "Incomplete: the AI stopped before the end. Review it closely."

/// Writes an HTML (or other) file the server built into its own folder in the preview store,
/// for the share sheet. The store is emptied at sign-out and at the next launch.
func shareableFile(_ contents: String, named raw: String, fallback: String = "document.html") -> URL? {
    let base = (raw as NSString).lastPathComponent
    let name = base.isEmpty || base == "." || base == ".." ? fallback : base
    let dir = PreviewStore.directory.appendingPathComponent(UUID().uuidString, isDirectory: true)
    do {
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let url = dir.appendingPathComponent(name)
        try Data(contents.utf8).write(to: url, options: [.atomic, .completeFileProtection])
        return url
    } catch { return nil }
}

/// The same for bytes (an assessment attachment).
func shareableFile(data: Data, named raw: String, fallback: String = "attachment") -> URL? {
    let base = (raw as NSString).lastPathComponent
    let name = base.isEmpty || base == "." || base == ".." ? fallback : base
    let dir = PreviewStore.directory.appendingPathComponent(UUID().uuidString, isDirectory: true)
    do {
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let url = dir.appendingPathComponent(name)
        try data.write(to: url, options: [.atomic, .completeFileProtection])
        return url
    } catch { return nil }
}

/// A document the server built (device_letter, worksite_report): its title, plain text, the
/// accessible HTML and the file name to share it under.
struct BuiltDocumentReply: Decodable {
    let title: String?
    let text: String?
    let html: String?
    let file: String?
    let releaseWarning: String?
}

/// Shows a document the server built as text, with "Share the <noun> (HTML file)" and
/// "Copy <noun>" (S4 justification letter, S8 recommendation report). The text is read as
/// plain paragraphs; copying goes through API.copyProtected (this device only, cleared after
/// 10 minutes), as every other client text in the app.
struct BuiltDocumentView: View {
    @EnvironmentObject var api: API
    let navigationTitle: String
    let action: String
    let query: [String: String]
    /// "letter" or "report": the buttons say "Share the letter (HTML file)", "Copy letter".
    let noun: String
    /// A sentence shown first (for example "Save the assessment first so the report includes
    /// your changes."), or nil.
    var note: String? = nil
    @State private var reply: BuiltDocumentReply?
    @State private var file: URL?
    @State private var error: String?

    var body: some View {
        ErrorFocusForm(inNavigationStack: true, error: error) {
            if let warning = reply?.releaseWarning, !warning.isEmpty {
                Section { Label(warning, systemImage: "exclamationmark.triangle").font(.callout.weight(.semibold)) }
            }
            if let note { Section { Text(note).font(.callout) } }
            if reply == nil && error == nil { ProgressView("Loading the \(noun)…") }
            if let reply {
                Section {
                    if let title = reply.title, !title.isEmpty {
                        Text(title).font(.headline).accessibilityAddTraits(.isHeader)
                    }
                    ForEach(Array(paragraphs(reply.text ?? "").enumerated()), id: \.offset) { _, p in
                        Text(p).fixedSize(horizontal: false, vertical: true)
                    }
                }
                Section {
                    if let file {
                        ShareLink(item: file) { Label("Share the \(noun) (HTML file)", systemImage: "square.and.arrow.up") }
                    }
                    Button {
                        api.copyProtected(reply.text ?? "")
                        announce(noun == "letter" ? "Letter copied." : "Report copied.")
                    } label: { Label("Copy \(noun)", systemImage: "doc.on.doc") }
                }
            }
        }
        .navigationTitle(navigationTitle)
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .onDisappear { if let file { try? FileManager.default.removeItem(at: file.deletingLastPathComponent()) } }
    }

    private func paragraphs(_ text: String) -> [String] {
        text.components(separatedBy: "\n").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
    }

    private func load() async {
        do {
            let r: BuiltDocumentReply = try await api.call(action, query: query)
            reply = r
            if let html = r.html, !html.isEmpty { file = shareableFile(html, named: r.file ?? "", fallback: "\(noun).html") }
            error = nil
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// A reply that carries only the server's sentence (most 4.37.0 writes).
struct MessageReply: Decodable {
    let message: String?
}

/// A count as the website prints it (number_format: 1,234).
func websiteCount(_ n: Int) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.numberStyle = .decimal
    f.groupingSeparator = ","
    f.usesGroupingSeparator = true
    f.groupingSize = 3
    return f.string(from: NSNumber(value: n)) ?? String(n)
}

/// ai_draft type format's reply (4.37.0, S9): the four sections and client.php's sentence.
struct NoteFormatReply: Decodable { let sections: [String]; let message: String? }

/// ai_draft type format's body (4.37.0, S9).
func noteFormatBody(clientId: Int, format: String, body: String, sections: [String]) -> [String: Any] {
    ["type": "format", "client_id": clientId, "format": format, "body": body,
     "sections": (0..<4).map { $0 < sections.count ? sections[$0] : "" }]
}

/// The formatted sections applied as the website does: a section the AI left empty keeps what
/// was typed in it.
func applyFormatted(_ old: [String], _ new: [String]) -> [String] {
    (0..<4).map { i in
        let drafted = i < new.count ? new[i].trimmingCharacters(in: .whitespacesAndNewlines) : ""
        return drafted.isEmpty ? (i < old.count ? old[i] : "") : new[i]
    }
}

/// A file handed to the share sheet.
struct SharedFile: Identifiable {
    let url: URL
    var id: URL { url }
}

/// The system share sheet for a file (the evaluation report's accessible HTML).
struct FileActivitySheet: UIViewControllerRepresentable {
    let url: URL
    func makeUIViewController(context: Context) -> UIActivityViewController {
        UIActivityViewController(activityItems: [url], applicationActivities: nil)
    }
    func updateUIViewController(_ controller: UIActivityViewController, context: Context) {}
}
