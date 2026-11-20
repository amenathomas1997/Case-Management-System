import SwiftUI

// MARK: - Shared

struct AITextResponse: Decodable {
    let text: String
    /// The provider stopped at its output limit, so the result ends before the text sent did
    /// (ai_remediate, ai_translate; false when an older server does not say).
    let truncated: Bool
    /// A translation's language as a BCP-47 code and its direction ("rtl" or "auto"), when the
    /// server says (ai_translate).
    let lang: String?
    let dir: String?
    /// How many active clients a risk review covered, of how many there are: at most 80 fit
    /// one review (ai_risk; nil when an older server does not say).
    let assessed: Int?
    let totalActive: Int?
    /// Ask your caseload (4.34.0): only part of the caseload fit in one question.
    let caseloadCut: Bool
    /// Risk flags (4.34.0): the period the sessions were counted in ("YYYY-MM-DD").
    let periodFrom: String?
    let periodTo: String?
    private enum CodingKeys: String, CodingKey { case text, truncated, lang, dir, assessed, totalActive, caseloadCut, periodFrom, periodTo }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        text = try c.decode(String.self, forKey: .text)
        truncated = (try? c.decodeIfPresent(Bool.self, forKey: .truncated))
            ?? (try? c.decodeIfPresent(Int.self, forKey: .truncated)).map { $0 != 0 } ?? false
        let code = (try? c.decodeIfPresent(String.self, forKey: .lang)) ?? ""
        lang = code.isEmpty ? nil : code
        dir = try? c.decodeIfPresent(String.self, forKey: .dir)
        assessed = try? c.decodeIfPresent(Int.self, forKey: .assessed)
        totalActive = try? c.decodeIfPresent(Int.self, forKey: .totalActive)
        caseloadCut = (try? c.decodeIfPresent(Bool.self, forKey: .caseloadCut))
            ?? (try? c.decodeIfPresent(Int.self, forKey: .caseloadCut)).map { $0 != 0 } ?? false
        periodFrom = (try? c.decodeIfPresent(String.self, forKey: .periodFrom)) ?? nil
        periodTo = (try? c.decodeIfPresent(String.self, forKey: .periodTo)) ?? nil
    }
}

/// The notices said above an AI result (4.34.0), as the website words them for each tool.
enum AINotice {
    static let askIncomplete = "Incomplete: the AI stopped before finishing this answer, so clients after the last line are missing. Narrow the question and ask again."
    static let caseloadCut = "Note: only part of the caseload fit in one question — narrow the question for larger caseloads."
    static let riskIncomplete = "Incomplete: the AI stopped before finishing this list, so the clients after the last item were not assessed."
    static let curriculumIncomplete = "Incomplete: the AI stopped before finishing this lesson plan, so the last lessons are missing. Narrow the focus and draft again."
    static let progressIncomplete = "Incomplete: this review was cut short, so goals after the last one it mentions were not reviewed."

    /// Ask your caseload's notices, joined into one (said as one stop with the result).
    static func ask(truncated: Bool, caseloadCut: Bool) -> String? {
        let parts = [truncated ? askIncomplete : nil, caseloadCut ? self.caseloadCut : nil].compactMap { $0 }
        return parts.isEmpty ? nil : parts.joined(separator: " ")
    }
}
struct AIKBSource: Decodable, Identifiable { let id: Int; let title: String }
struct AIKBResponse: Decodable { let text: String; let sources: [AIKBSource] }

struct AIResultCard: View {
    let text: String
    /// Names what produced the result (for example "Spanish translation"), shown and spoken.
    var caption: String? = nil
    var footer: String? = nil
    /// A translation's language as a BCP-47 code ("es", "zh-Hans"), set on the result text so
    /// VoiceOver reads it in that language's voice; nil for text in the app's own language.
    var language: String? = nil
    /// The result is in a right-to-left script (Arabic), so it is laid out from the right.
    var rightToLeft = false
    /// The provider stopped at its output limit: the result ends before the text sent did.
    var incomplete = false
    /// The tool's own words for what is missing (4.34.0), shown where the incomplete notice is
    /// and in its place; shown even when the result is complete (a caseload cut, say).
    var notice: String? = nil
    /// Set by the screen when a new result arrives (see `revealAIResult`). The card moves
    /// VoiceOver onto the result once and clears it, so the row scrolling back into view
    /// later never pulls focus or repeats the announcement.
    @Binding var reveal: Bool
    @EnvironmentObject var api: API
    @Environment(\.layoutDirection) private var appDirection
    private enum Spot: Hashable { case notice, result }
    @AccessibilityFocusState private var focus: Spot?
    /// The result split into headings, list items and paragraphs, so each is its own element
    /// (headings with the header trait) and markdown symbols are drawn, not read out.
    private var blocks: [AIResultBlock] { AIResultBlock.parse(text, language: language) }
    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            // The caption names the result in the app's language; a header, so the Headings
            // rotor reaches the result, and the first thing VoiceOver lands on.
            if let caption {
                Text(caption).font(.caption.weight(.semibold))
                    .accessibilityAddTraits(.isHeader)
                    .accessibilityAction(named: "Copy result") { copyResult() }
                    .accessibilityFocused($focus, equals: .result)
            }
            if incomplete || notice != nil {
                // In words with an icon (not colour alone), and above the result, so VoiceOver
                // lands on it first when the result arrives.
                Label(notice ?? "Incomplete — the result stops before the end of your text; split it and try again.", systemImage: "exclamationmark.triangle")
                    .font(.callout.weight(.semibold))
                    .accessibilityFocused($focus, equals: .notice)
            }
            VStack(alignment: .leading, spacing: 6) {
                ForEach(Array(blocks.enumerated()), id: \.offset) { index, block in
                    if caption == nil && index == 0 {
                        blockView(block).accessibilityFocused($focus, equals: .result)
                    } else {
                        blockView(block)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .environment(\.layoutDirection, rightToLeft ? .rightToLeft : appDirection)
            if let footer { Text(footer).font(.caption) }
            // The one way to copy the result (the blocks are not selectable: a system Copy would
            // go to other devices and never expire). It may name a client, so the copy stays on
            // this device, is cleared from the clipboard after 10 minutes, and is cleared at
            // sign-out while it is still the clipboard's latest item (API.copyProtected).
            Button {
                copyResult()
            } label: { Label("Copy result", systemImage: "doc.on.doc").frame(minHeight: 44).contentShape(Rectangle()) }
                .buttonStyle(.borderless)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding()
        .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
        .task(id: reveal) {
            guard reveal else { return }
            try? await Task.sleep(nanoseconds: 900_000_000)
            guard !Task.isCancelled else { return }
            focus = incomplete || notice != nil ? .notice : .result
            reveal = false
        }
    }

    /// The Copy result button's code; also a named action on every part of the result, so
    /// VoiceOver can copy from wherever it is without flicking to the end of the card.
    private func copyResult() {
        api.copyProtected(text)
        announce("Result copied. The copy is cleared from the clipboard in 10 minutes.")
    }

    private func blockView(_ block: AIResultBlock) -> some View {
        blockContent(block).accessibilityAction(named: "Copy result") { copyResult() }
    }

    @ViewBuilder private func blockContent(_ block: AIResultBlock) -> some View {
        switch block.kind {
        case .heading:
            Text(block.text).font(.callout.weight(.bold))
                .accessibilityAddTraits(.isHeader)
        case .item(let marker):
            HStack(alignment: .firstTextBaseline, spacing: 6) {
                Text(marker).font(.callout)
                Text(block.text).font(.callout)
            }
            .accessibilityElement(children: .combine)
        case .paragraph:
            Text(block.text).font(.callout)
        }
    }
}

/// One block of an AI result: a heading, a list item (with its bullet or number) or a
/// paragraph. Inline markdown (bold, italic, code, links) is rendered; the block markers
/// ("## ", "- ", "1. ", fences and rules) are taken off so they are never spoken.
struct AIResultBlock {
    enum Kind { case heading, item(String), paragraph }
    let kind: Kind
    let text: AttributedString

    static func parse(_ raw: String, language: String?) -> [AIResultBlock] {
        func styled(_ s: String) -> AttributedString {
            var a = (try? AttributedString(markdown: s, options: .init(interpretedSyntax: .inlineOnlyPreservingWhitespace)))
                ?? AttributedString(s)
            if let language { a.languageIdentifier = language }
            return a
        }
        /// Code from inside a ``` fence: shown exactly as written (the same text "Copy result"
        /// copies), so no markdown is read into it.
        func plain(_ s: String) -> AttributedString {
            var a = AttributedString(s)
            if let language { a.languageIdentifier = language }
            return a
        }
        func match(_ line: String, _ pattern: String) -> [String]? {
            guard let re = try? NSRegularExpression(pattern: pattern),
                  let m = re.firstMatch(in: line, range: NSRange(line.startIndex..., in: line)) else { return nil }
            return (0..<m.numberOfRanges).map { i in
                Range(m.range(at: i), in: line).map { String(line[$0]) } ?? ""
            }
        }
        var blocks: [AIResultBlock] = []
        var paragraph: [String] = []
        var inCode = false
        func flush() {
            // Every flush inside a fence (a blank line, the closing fence, or the end of an
            // unclosed fence) happens while inCode is still true, so code never goes through styled().
            if !paragraph.isEmpty {
                let joined = paragraph.joined(separator: "\n")
                blocks.append(.init(kind: .paragraph, text: inCode ? plain(joined) : styled(joined)))
            }
            paragraph = []
        }
        let lines = raw.replacingOccurrences(of: "\r\n", with: "\n").components(separatedBy: "\n")
        for line in lines {
            let trimmed = line.trimmingCharacters(in: .whitespaces)
            if trimmed.hasPrefix("```") { flush(); inCode.toggle(); continue }
            if inCode {
                // Code is kept as written: flush() builds it with plain(), not styled().
                if trimmed.isEmpty { flush() } else { paragraph.append(line) }
                continue
            }
            if trimmed.isEmpty { flush(); continue }
            if match(trimmed, "^([-*_])(\\s*\\1){2,}$") != nil { flush(); continue }
            if let m = match(trimmed, "^#{1,6}\\s+(.*?)\\s*#*$") {
                flush(); blocks.append(.init(kind: .heading, text: styled(m[1]))); continue
            }
            if let m = match(trimmed, "^\\*\\*([^*]+)\\*\\*:?$") {
                // A line that is only bold text is used as a heading.
                flush(); blocks.append(.init(kind: .heading, text: styled(m[1]))); continue
            }
            if let m = match(trimmed, "^[-*+•]\\s+(.*)$") {
                flush(); blocks.append(.init(kind: .item("•"), text: styled(m[1]))); continue
            }
            if let m = match(trimmed, "^(\\d{1,3}[.)])\\s+(.*)$") {
                flush(); blocks.append(.init(kind: .item(m[1]), text: styled(m[2]))); continue
            }
            paragraph.append(trimmed)
        }
        flush()
        if blocks.isEmpty { blocks.append(.init(kind: .paragraph, text: styled(raw))) }
        return blocks
    }
}

/// A new AI result arrived below the button after a wait, with nothing moving on screen:
/// say so when it arrives (wherever its row is), and ask the card to put VoiceOver on it.
@MainActor func revealAIResult(_ reveal: inout Bool) {
    announce("Result ready.")
    reveal = true
}

/// An AI request has started: the button's new text ("Translating…") is not spoken, so say
/// that the tap registered and that the wait is expected.
@MainActor func announceAIWork(_ status: String) {
    announce("\(status). This can take up to a minute.")
}

/// A text field whose name stays visible above it once it holds text (a placeholder alone
/// disappears), so filled-in drafts can be reviewed field by field.
private struct AILabeledField: View {
    let label: String
    @Binding var text: String
    var lines: ClosedRange<Int>? = nil
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            // The field itself carries the same name for VoiceOver, so the caption is not repeated.
            // Primary, not secondary: grey caption text is about 3.4:1 on a white row.
            Text(label).font(.caption).accessibilityHidden(true)
            if let lines {
                TextField(label, text: $text, axis: .vertical).lineLimit(lines)
            } else {
                TextField(label, text: $text)
            }
        }
    }
}

// MARK: - Hub

struct AIToolsView: View {
    @EnvironmentObject var api: API
    // Each tool is offered only with the permission its API action requires (Ask needs
    // clients; remediation, translation and the KB assistant need only a sign-in), so a tool
    // is never shown that can only answer "no access".
    private var showRemediate: Bool { api.aiHas("remediate") }
    private var showAsk: Bool { api.aiHas("ask") && api.can("clients") }
    private var showCurriculum: Bool { api.aiHas("curriculum") && api.can("templates") }
    private var showRisk: Bool { api.aiHas("risk") && api.can("analytics") }
    private var anyGeneralTool: Bool {
        showRemediate || showAsk || api.aiHas("kb") || api.aiHas("translate") || showCurriculum || showRisk
    }
    private var anyClientTool: Bool {
        api.can("clients") && (api.aiHas("recommend") || api.aiHas("intake") || api.aiHas("progress"))
    }
    var body: some View {
        List {
            if !api.aiEnabled {
                Text("The AI assistant is not configured. An administrator can set it up on the website.")
            } else {
                Section {
                    if showRemediate { NavigationLink { RemediateView() } label: { Label("Accessible content & remediation", systemImage: "text.badge.checkmark") } }
                    if showAsk { NavigationLink { AskCaseloadView() } label: { Label("Ask your caseload", systemImage: "questionmark.bubble") } }
                    if api.aiHas("kb") { NavigationLink { KBAskView() } label: { Label("Knowledge-base assistant", systemImage: "book.closed") } }
                    if api.aiHas("translate") { NavigationLink { TranslateView() } label: { Label("Translate", systemImage: "character.bubble") } }
                    if showCurriculum { NavigationLink { CurriculumGenView() } label: { Label("Curriculum generator", systemImage: "list.bullet.rectangle") } }
                    if showRisk { NavigationLink { RiskView() } label: { Label("Outcome risk flags", systemImage: "exclamationmark.triangle") } }
                    if !anyGeneralTool { Text("No general AI tools are switched on for your account.") }
                }
                Section {
                    Text(aiReviewSentence(api.aiRedaction) + (anyClientTool ? " Client-specific tools (assistive technology recommendations, guided intake, progress review) are on each client's screen." : ""))
                        .font(.caption)
                }
            }
        }
        .navigationTitle("AI tools")
        .navigationBarTitleDisplayMode(.inline)
    }
}

// MARK: - General tools

struct RemediateView: View {
    @EnvironmentObject var api: API
    /// From a client's AI tools (4.37.0, S25): the client whose preferred format the result
    /// targets, as remediate.php?client=. Nil from the general AI tools.
    var clientId: Int? = nil
    /// The client's preferred format as the client screen knows it, until the server says.
    var clientFormat: String? = nil
    @State private var mode = "plain"
    @State private var content = ""
    @State private var result: String?
    @State private var resultCaption = ""
    @State private var resultIncomplete = false
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?
    /// The preferred format the server targeted (its reply's format).
    @State private var targetFormat: String?
    /// remediate.php's mode labels (4.37.0, S25).
    private let modes = [("plain", "Plain language (6th–8th grade)"), ("simplify", "Simplify (shorter sentences)"), ("summary", "Summarize"),
                         ("largeprint", "Format for large print / low vision"), ("alt", "Alt text for an image (describe it below)")]

    var body: some View {
        // ErrorFocusForm: Transform sits below a long text box, so a failure is scrolled to and
        // given VoiceOver focus rather than left in a banner row that is off screen.
        ErrorFocusForm(inNavigationStack: true, error: error) {
            if let format = remediateTargetText(targetFormat ?? clientFormat) {
                Text(format).font(.callout)
            }
            // Inputs are locked while a request runs, and a result is cleared when they change,
            // so the result on screen always matches the choice and text shown above it.
            Picker("What to do", selection: $mode) { ForEach(modes, id: \.0) { Text($0.1).tag($0.0) } }
                .disabled(busy)
            Section("Content (for alt text, describe the image)") {
                TextEditor(text: $content).frame(minHeight: 160)
                    .accessibilityLabel("Content. For alt text, describe the image.")
                    .errorField("Content")
                    .disabled(busy)
            }
            Text("The text you enter is sent to the AI provider as-is.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Working…" : "Transform", systemImage: "wand.and.stars") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result { AIResultCard(text: result, caption: resultCaption, incomplete: resultIncomplete, reveal: $reveal) }
        }
        .onChange(of: mode) { _ in if !busy { result = nil } }
        .onChange(of: content) { _ in if !busy { result = nil } }
        .navigationTitle("Remediation").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        // remediate.php's two refusals, said on the field before anything is sent.
        let length = content.unicodeScalars.count
        if content.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Paste some content first.", in: &error, field: "Content"); return
        }
        if length > 20000 {
            showFormError("Shorten the content to 20,000 characters or fewer (it has \(websiteCount(length))), or split it into smaller parts.", in: &error, field: "Content"); return
        }
        busy = true; error = nil; result = nil
        announceAIWork("Working")
        let label = modes.first(where: { $0.0 == mode })?.1 ?? mode
        var refused: [String: String] = [:]
        do {
            let r: RemediateReply = try await api.call("ai_remediate", body: remediateBody(mode: mode, content: content, clientId: clientId),
                                                       refusal: { refused = refusalFields($0) })
            if let f = r.format { targetFormat = f }
            resultIncomplete = r.truncated == true
            resultCaption = "\(label) result" + (r.truncated == true ? " (incomplete)" : ""); result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error, field: refused["content"] != nil ? "Content" : nil) }
        busy = false
    }
}

/// ai_remediate's reply (4.37.0 adds the client's preferred format it targeted).
struct RemediateReply: Decodable { let text: String; let truncated: Bool?; let format: String? }

/// ai_remediate's body (4.37.0, S25): the client only when the tool was opened from a client.
func remediateBody(mode: String, content: String, clientId: Int?) -> [String: Any] {
    var body: [String: Any] = ["mode": mode, "content": content]
    if let clientId, clientId > 0 { body["client_id"] = clientId }
    return body
}

/// remediate.php's line for the client's preferred format, or nil when none is set.
func remediateTargetText(_ format: String?) -> String? {
    guard let f = format?.trimmingCharacters(in: .whitespacesAndNewlines), !f.isEmpty else { return nil }
    return "Targeting the client's preferred format: \(f)."
}

struct TranslateView: View {
    @EnvironmentObject var api: API
    private static let contentHelp = "Pasted text is sent to the provider as-is. Review before sending to a client. Up to 6,000 characters at a time; split longer text into parts."
    /// The list's last choice (4.37.0, S24): the language is typed in "Other language".
    static let other = "Other (type below)"
    @State private var lang = "Spanish"
    @State private var otherLanguage = ""
    @State private var content = ""
    @State private var result: String?
    @State private var resultCaption = ""
    @State private var resultLanguage: String?
    @State private var resultRightToLeft = false
    @State private var resultIncomplete = false
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?
    /// The listed languages with their BCP-47 codes (as in translate.php), used to mark the
    /// result's language when the server does not say.
    private let langs: [(name: String, code: String)] = [
        ("Spanish", "es"), ("Arabic", "ar"), ("Chinese (Simplified)", "zh-Hans"), ("Vietnamese", "vi"), ("Russian", "ru"), ("French", "fr"),
        ("Haitian Creole", "ht"), ("Somali", "so"), ("Nepali", "ne"), ("Korean", "ko"), ("Tagalog", "tl"),
    ]

    var body: some View {
        // ErrorFocusForm: Translate sits below a long text box, so a failure is scrolled to and
        // given VoiceOver focus rather than left in a banner row that is off screen.
        ErrorFocusForm(inNavigationStack: true, error: error) {
            // Locked while translating, and a change clears the result, so a translation is
            // never shown under a language it was not made in.
            Picker("Into", selection: $lang) {
                ForEach(langs, id: \.name) { Text($0.name).tag($0.name) }
                Text(Self.other).tag(Self.other)
            }
            .disabled(busy)
            if lang == Self.other {
                CaptionedField(label: "Other language (letters, spaces, hyphens, apostrophes, periods and parentheses; up to 40 characters)",
                               text: $otherLanguage, errorName: "Other language")
                    .disabled(busy)
            }
            // The server refuses more than 6,000 characters at once (ai_translate), so the limit
            // is stated up front and checked here before anything is sent. 4.29.0: said as the
            // field's hint, so it is not a stop of its own.
            Section("Content") {
                TextEditor(text: $content).frame(minHeight: 160).accessibilityLabel("Text to translate")
                    .accessibilityHint(Self.contentHelp).disabled(busy)
                    .errorField("Text to translate")
            }
            Text(Self.contentHelp).font(.caption).accessibilityHidden(true)
            Button { Task { await run() } } label: { Label(busy ? "Translating…" : "Translate", systemImage: "character.bubble") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result {
                AIResultCard(text: result, caption: resultCaption, language: resultLanguage, rightToLeft: resultRightToLeft,
                             incomplete: resultIncomplete, reveal: $reveal)
            }
        }
        .onChange(of: lang) { _ in if !busy { result = nil } }
        .onChange(of: otherLanguage) { _ in if !busy { result = nil } }
        .onChange(of: content) { _ in if !busy { result = nil } }
        .navigationTitle("Translate").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        let requested = translateTarget(choice: lang, typed: otherLanguage)
        if let problem = translateInputProblem(content: content, language: requested, typed: lang == Self.other) {
            showFormError(problem.message, in: &error, field: problem.field); return
        }
        busy = true; error = nil; result = nil
        announceAIWork("Translating")
        var refused: [String: String] = [:]
        do {
            let r: AITextResponse = try await api.call("ai_translate", body: ["lang": requested, "content": content],
                                                       refusal: { refused = refusalFields($0) })
            let code = r.lang ?? langs.first(where: { $0.name == requested })?.code
            resultLanguage = code
            resultRightToLeft = r.dir.map { $0 == "rtl" } ?? (code == "ar")
            resultIncomplete = r.truncated
            resultCaption = "\(requested) translation" + (r.truncated ? " (incomplete)" : ""); result = r.text; revealAIResult(&reveal)
        }
        catch {
            let field = refused["content"] != nil ? "Text to translate" : (refused["language"] != nil && lang == Self.other ? "Other language" : nil)
            showFormError(error.localizedDescription, in: &self.error, field: field)
        }
        busy = false
    }
}

/// The language sent to ai_translate (4.37.0, S24): the typed one for "Other (type below)".
func translateTarget(choice: String, typed: String) -> String {
    choice == TranslateView.other ? typed.trimmingCharacters(in: .whitespacesAndNewlines) : choice
}

/// translate.php's refusals, checked before sending, with the field each is about.
func translateInputProblem(content: String, language: String, typed: Bool) -> (message: String, field: String?)? {
    if content.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { return ("Paste the text to translate.", "Text to translate") }
    let length = content.unicodeScalars.count
    if length > 6000 {
        return ("That is too much text to translate at once: shorten it to 6,000 characters or fewer (it has \(websiteCount(length))), or split it into parts and translate each one.", "Text to translate")
    }
    if language.isEmpty { return ("Type the language to translate into.", typed ? "Other language" : nil) }
    if language.unicodeScalars.count > 40 || language.range(of: #"^[\p{L}\p{M} ()'’.-]+$"#, options: .regularExpression) == nil {
        return ("The language name can use only letters, spaces, hyphens, apostrophes, periods and parentheses (40 characters maximum).", typed ? "Other language" : nil)
    }
    return nil
}

struct CurriculumGenView: View {
    @EnvironmentObject var api: API
    @State private var tech = ""
    @State private var level = "Beginner"
    @State private var focus = ""
    @State private var result: String?
    @State private var notice: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            // Locked while drafting, and a change clears the draft, so the plan shown always
            // match the technology, level and focus above them.
            AILabeledField(label: "Technology (e.g. JAWS)", text: $tech).disabled(busy)
            Picker("Level", selection: $level) { ForEach(["Beginner", "Intermediate", "Advanced"], id: \.self) { Text($0) } }
                .disabled(busy)
            AILabeledField(label: "Focus / notes", text: $focus).disabled(busy)
            Button { Task { await run() } } label: { Label(busy ? "Drafting…" : "Draft lesson plan", systemImage: "list.bullet.rectangle") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy || tech.isEmpty)
            if let result {
                AIResultCard(text: result, footer: "Save it as a curriculum on the website: AI tools › Curriculum generator.",
                             notice: notice, reveal: $reveal)
            }
        }
        .onChange(of: tech) { _ in if !busy { result = nil } }
        .onChange(of: level) { _ in if !busy { result = nil } }
        .onChange(of: focus) { _ in if !busy { result = nil } }
        .navigationTitle("Curriculum").navigationBarTitleDisplayMode(.inline)
        .modifier(ReadablePickers(inNavigationStack: true))   // choices not cut short at large text sizes
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Drafting the lesson plan")
        do {
            let r: AITextResponse = try await api.call("ai_curriculum", body: ["tech": tech, "level": level, "focus": focus])
            notice = r.truncated ? AINotice.curriculumIncomplete : nil
            result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct AskCaseloadView: View {
    @EnvironmentObject var api: API
    @State private var question = ""
    @State private var result: String?
    @State private var notice: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            // Locked while asking, and a change clears the answer, so an answer is never shown
            // under a question it was not given for.
            TextField("Your question", text: $question, axis: .vertical).lineLimit(1...3).disabled(busy)
            Text("Client names are never sent to the AI: each name is replaced with a code before sending, and the names are put back in the answer. Read-only; verify before acting.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Thinking…" : "Ask", systemImage: "questionmark.bubble") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy || question.isEmpty)
            if let result { AIResultCard(text: result, notice: notice, reveal: $reveal) }
        }
        .onChange(of: question) { _ in if !busy { result = nil } }
        .navigationTitle("Ask your caseload").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Thinking")
        do {
            let r: AITextResponse = try await api.call("ai_ask", body: ["question": question])
            // 4.34.0: said when the answer stopped early or only part of the caseload fit.
            notice = AINotice.ask(truncated: r.truncated, caseloadCut: r.caseloadCut)
            result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct KBAskView: View {
    @EnvironmentObject var api: API
    @State private var question = ""
    /// 4.37.0 (S23): kb_ask.php's tick, answering from general knowledge when no article covers it.
    @State private var general = false
    @State private var result: String?
    @State private var resultGeneral = false
    @State private var reveal = false
    @State private var sources: [AIKBSource] = []
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ErrorFocusForm(inNavigationStack: true, error: error) {
            TextField("Your question", text: $question, axis: .vertical).lineLimit(1...3).disabled(busy)
                .errorField("Your question")
            Toggle("If no article covers it, answer from general knowledge (marked as not agency procedure)", isOn: $general).disabled(busy)
            Button { Task { await run() } } label: { Label(busy ? "Searching…" : "Ask", systemImage: "book.closed") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result {
                AIResultCard(text: result, caption: kbCaption(general: resultGeneral),
                             footer: sources.isEmpty ? nil : "Grounded in: " + sources.map { $0.title }.joined(separator: ", "), reveal: $reveal)
            }
        }
        .onChange(of: question) { _ in if !busy { result = nil; sources = [] } }
        .onChange(of: general) { _ in if !busy { result = nil; sources = [] } }
        .navigationTitle("Knowledge-base assistant").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        if question.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Type a question to ask.", in: &error, field: "Your question"); return
        }
        let length = question.unicodeScalars.count
        if length > 2000 {
            showFormError("Shorten the question to 2,000 characters or fewer (it has \(websiteCount(length))).", in: &error, field: "Your question"); return
        }
        busy = true; error = nil; result = nil; sources = []
        announceAIWork("Searching")
        var steps: [String] = []
        var refused: [String: String] = [:]
        do {
            let r: KBAnswerReply = try await api.call("ai_kb", body: ["question": question, "general": general], refusal: { reply in
                refused = refusalFields(reply)
                steps = (reply["steps"] as? [String]) ?? []
            })
            resultGeneral = r.general == true
            result = r.text; sources = r.sources ?? []; revealAIResult(&reveal)
        }
        catch {
            // A question no article covers: the server's sentence and kb_ask.php's three steps,
            // as one message (one stop).
            let message = ([error.localizedDescription] + steps).joined(separator: " ")
            showFormError(message, in: &self.error, field: refused["question"] != nil ? "Your question" : nil)
        }
        busy = false
    }
}

/// ai_kb's reply (4.37.0 says when the answer is from general knowledge).
struct KBAnswerReply: Decodable { let text: String; let sources: [AIKBSource]?; let general: Bool? }

/// The knowledge-base answer's caption, as kb_ask.php's heading.
func kbCaption(general: Bool) -> String {
    general ? "Answer (general knowledge, not from your articles)" : "Answer"
}

struct RiskView: View {
    @EnvironmentObject var api: API
    @State private var result: String?
    /// "N of M active clients assessed", when the review could not cover them all.
    @State private var resultCaption: String?
    @State private var resultFooter: String?
    @State private var riskNotice: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Text("Reviews active clients' engagement and progress signals and flags who's at risk of not completing. Operational triage, not a prediction; each client name is replaced with a code before anything is sent to the AI.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Assessing…" : "Assess active clients", systemImage: "exclamationmark.triangle") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result { AIResultCard(text: result, caption: resultCaption, footer: resultFooter, notice: riskNotice, reveal: $reveal) }
        }
        .navigationTitle("Risk flags").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Assessing active clients")
        do {
            let r: AITextResponse = try await api.call("ai_risk", body: [:])
            // As on the website: when there are more active clients than fit one review, say
            // how many were covered and which, so the flags are not read as the whole caseload.
            var footer: [String] = []
            if let from = r.periodFrom, let to = r.periodTo {
                footer.append("Period: \(formatDate(from)) to \(formatDate(to)).")
            }
            if let assessed = r.assessed, let total = r.totalActive, assessed < total {
                resultCaption = "\(assessed) of \(total) active clients assessed"
                footer.append("Only the \(assessed) with no session yet or the longest time since their last session fit one review; the others were not assessed.")
            } else {
                resultCaption = nil
            }
            resultFooter = footer.isEmpty ? nil : footer.joined(separator: " ")
            riskNotice = r.truncated ? AINotice.riskIncomplete : nil
            result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

// MARK: - Client-specific tools

struct AIRecommendView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    var clientName = ""
    @State private var result: String?
    @State private var incomplete = false
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?
    /// 4.37.0 (S6): "Start a device request for <client>" from the result.
    @State private var startingRequest = false
    @State private var requestCreated = false

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Text("Drafts assistive-technology recommendations from the client's disability, goals and job tasks. Review before acting.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Thinking…" : "Recommend assistive technology", systemImage: "lightbulb") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result {
                AIResultCard(text: result, footer: recommendFooter(canProcurement: api.can("procurement")),
                             notice: incomplete ? aiDraftIncomplete : nil, reveal: $reveal)
                if api.can("procurement") {
                    Button { startingRequest = true } label: {
                        Label("Start a device request for \(clientName.isEmpty ? "this client" : clientName)", systemImage: "cart.badge.plus")
                    }
                }
            }
        }
        .navigationTitle("Assistive technology recommendations").navigationBarTitleDisplayMode(.inline)
        .sheet(isPresented: $startingRequest, onDismiss: {
            if requestCreated { requestCreated = false; announceAfterDismiss("Request created.") }
        }) {
            DeviceNewSheet(client: clientId, justification: startRequestPrefill(result ?? "")) { requestCreated = true }
        }
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Thinking")
        do {
            let r: AITextResponse = try await api.call("ai_recommend", body: ["client_id": clientId])
            incomplete = r.truncated
            result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

/// The recommendation result's footer (4.37.0, S6).
func recommendFooter(canProcurement: Bool) -> String {
    canProcurement ? "Review the recommendations, then start a device request for any you want to pursue."
        : "Recommendations drafted — review them before using any."
}

/// The justification a request started from the recommendations begins with: at most 4,000
/// characters, cut at a line end (4.37.0, S6).
func startRequestPrefill(_ text: String) -> String {
    let t = text.trimmingCharacters(in: .whitespacesAndNewlines)
    let scalars = Array(t.unicodeScalars)
    guard scalars.count > 4000 else { return t }
    var cut = String(String.UnicodeScalarView(scalars[0..<4000]))
    if let end = cut.lastIndex(of: "\n"), end > cut.startIndex { cut = String(cut[..<end]) }
    return cut.trimmingCharacters(in: .whitespacesAndNewlines)
}

/// One active goal under the progress review (4.37.0, S10), as ai_progress lists them.
struct ReviewGoal: Decodable, Identifiable {
    let id: Int
    let text: String
    let trackId: Int?
    let track: String?
    let status: String
    let pct: Int?
}

/// ai_progress's reply with its goals (4.37.0; none from an older server).
struct ProgressReviewReply: Decodable { let text: String; let truncated: Bool?; let goals: [ReviewGoal]? }

/// A goal's row under the review: "<goal>, <track>: <pct>% (<status>)".
func reviewGoalRowText(_ g: ReviewGoal) -> String {
    "\(g.text), \(g.track ?? ""): \(g.pct ?? 0)% (\(g.status))"
}

struct AIProgressReviewView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    @State private var result: String?
    @State private var notice: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?
    /// 4.37.0 (S10): the active goals, each with Record progress.
    @State private var goals: [ReviewGoal] = []
    @State private var recording: Goal?
    @State private var recorded: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Text("Reviews recent notes and sessions against each active goal. Record any progress below.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Reviewing…" : "Generate review", systemImage: "chart.bar.doc.horizontal") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result { AIResultCard(text: result, notice: notice, reveal: $reveal) }
            if result != nil && !goals.isEmpty {
                Section("Record progress") {
                    ForEach(goals) { g in
                        Text(reviewGoalRowText(g))
                        Button("Record progress for \(String(g.text.prefix(60)))") {
                            recording = Goal(id: g.id, specificGoal: g.text, status: g.status, progressPct: g.pct)
                        }
                    }
                }
            }
        }
        .navigationTitle("Progress review").navigationBarTitleDisplayMode(.inline)
        .sheet(item: $recording, onDismiss: {
            if let recorded { announceAfterDismiss(recorded) }
            recorded = nil
        }) { goal in
            GoalProgressSheet(goal: goal) { message in
                recorded = message
                Task { await refreshGoal(goal.id) }
            }
        }
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Reviewing")
        do {
            let r: ProgressReviewReply = try await api.call("ai_progress", body: ["client_id": clientId])
            notice = r.truncated == true ? AINotice.progressIncomplete : nil
            goals = r.goals ?? []
            result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
    /// After a recording, the goal's row says its new status and percent.
    private func refreshGoal(_ id: Int) async {
        guard let i = goals.firstIndex(where: { $0.id == id }), let track = goals[i].trackId,
              let r: GoalsResponse = try? await api.call("goals", query: ["track_id": String(track)]),
              let g = r.goals.first(where: { $0.id == id }), let j = goals.firstIndex(where: { $0.id == id }) else { return }
        let old = goals[j]
        goals[j] = ReviewGoal(id: old.id, text: old.text, trackId: old.trackId, track: old.track, status: g.status, pct: g.progressPct)
    }
}

struct AIIntakeFields: Decodable {
    let primaryDisability: String; let primaryTech: String; let hardwareDetails: String; let softwareVersion: String; let summary: String
}
struct AIIntakeResponse: Decodable { let fields: AIIntakeFields; let instruments: String }

struct AIIntakeView: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    let clientId: Int
    let onSaved: () -> Void
    @State private var goals = ""; @State private var limitations = ""; @State private var tried = ""; @State private var context = ""
    @State private var generated = false
    @State private var disability = ""; @State private var tech = "Other"; @State private var hardware = ""; @State private var software = ""; @State private var summary = ""; @State private var instruments = ""
    @State private var date = Date()
    @State private var busy = false
    @State private var saved = false
    @State private var error: String?
    /// Where VoiceOver goes when the screen changes under it: the button it was on is replaced
    /// by the draft (then by the saved notice), so focus is moved, not left to land anywhere.
    private enum Spot: Hashable { case draft, saved }
    @AccessibilityFocusState private var focus: Spot?

    /// At least one intake answer, so the assistant has something to draft from.
    private var hasAnswers: Bool {
        [goals, limitations, tried, context].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }

    var body: some View {
        // ErrorFocusForm: Save assessment is at the bottom of a long draft, so a refused save
        // (an AI-drafted field over its length limit, say) is scrolled to and given VoiceOver
        // focus rather than left in a banner row that is off screen and never heard.
        ErrorFocusForm(inNavigationStack: true, error: error) {
            if !generated {
                Section("Intake") {
                    AILabeledField(label: "What does the client want to accomplish?", text: $goals, lines: 1...3)
                    AILabeledField(label: "Functional limitations", text: $limitations, lines: 1...3)
                    AILabeledField(label: "Technology tried / used", text: $tried, lines: 1...3)
                    AILabeledField(label: "Work / education context", text: $context, lines: 1...3)
                }
                // Locked while the draft is made: the request carries the answers as they were when
                // it started, and the draft replaces them, so a later edit would never reach it.
                .disabled(busy)
                Button { Task { await generate() } } label: { Label(busy ? "Drafting…" : "Draft assessment with AI", systemImage: "wand.and.stars") }
                    .accessibilityValue(busy ? "In progress" : "").disabled(busy || !hasAnswers)
                if !hasAnswers { Text("Answer at least one intake question to draft an assessment.").font(.caption) }
            } else {
                Section {
                    if !instruments.isEmpty { Text("Suggested instruments: \(instruments)").font(.caption) }
                    // Locked once saved: the saved assessment cannot be changed from here, so a later
                    // edit would otherwise be lost while "Assessment saved" is still shown.
                    Group {
                        DatePicker("Assessment date", selection: $date, displayedComponents: .date)
                        AILabeledField(label: "Primary disability", text: $disability)
                        Picker("Primary tech", selection: $tech) { ForEach(techTypes, id: \.self) { Text($0) } }
                        AILabeledField(label: "Hardware", text: $hardware)
                        AILabeledField(label: "Software & version", text: $software)
                        AILabeledField(label: "Summary", text: $summary, lines: 3...8)
                    }
                    .disabled(saved)
                    if saved {
                        // Saved once: say so and take the button away, rather than leave a dead form.
                        Label { Text("Assessment saved. These fields are now locked.") } icon: {
                            Image(systemName: "checkmark.circle.fill").foregroundStyle(Color.brandTeal)
                        }
                        .accessibilityFocused($focus, equals: .saved)
                    } else {
                        Button { Task { await save() } } label: { Label(busy ? "Saving…" : "Save assessment", systemImage: "checkmark.circle") }
                            .accessibilityValue(busy ? "In progress" : "").disabled(busy)
                    }
                } header: {
                    // The heading carries the instruction, and is where VoiceOver is put when the
                    // draft replaces the questions (an announcement would be cut off by that move).
                    Text("Draft assessment: review each field, then save")
                        .accessibilityAddTraits(.isHeader)
                        .accessibilityFocused($focus, equals: .draft)
                }
            }
        }
        .navigationTitle("Guided intake").navigationBarTitleDisplayMode(.inline)
    }
    private func generate() async {
        guard hasAnswers else { return }
        busy = true; error = nil
        announceAIWork("Drafting the assessment")
        do {
            let r: AIIntakeResponse = try await api.call("ai_intake", body: ["client_id": clientId, "goals": goals, "limitations": limitations, "tried": tried, "context": context])
            // The website shows a draft only when a summary came back; an empty or unreadable
            // reply (a refusal, or one cut off before its summary) is not a draft to review.
            guard !r.fields.summary.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
                showFormError("The assistant's reply could not be read. Try again, or add the assessment yourself from the client's record.", in: &error)
                busy = false
                return
            }
            disability = r.fields.primaryDisability; tech = techTypes.contains(r.fields.primaryTech) ? r.fields.primaryTech : "Other"
            hardware = r.fields.hardwareDetails; software = r.fields.softwareVersion; summary = r.fields.summary; instruments = r.instruments
            generated = true
            // The whole form is replaced by the draft, including the button VoiceOver was on:
            // put VoiceOver on the draft's heading once it is on screen.
            moveFocus(to: .draft)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
    private func moveFocus(to spot: Spot) {
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 500_000_000)
            focus = spot
        }
    }
    private func save() async {
        busy = true; error = nil
        do {
            var body: [String: Any] = [
                "client_id": clientId, "assessment_date": APIDate.day(date), "primary_disability": disability,
                "primary_tech": tech, "hardware_details": hardware, "software_version": software, "summary": summary,
            ]
            body["idem_key"] = idem.key(for: body)
            let _: OKResponse = try await api.call("add_assessment", body: body)
            idem.answered()
            saved = true
            busy = false
            // The Save button VoiceOver was on is replaced by the saved notice: move onto it,
            // which speaks it (an announcement here would be cut off by the focus change).
            moveFocus(to: .saved)
            onSaved()
        } catch {
            idem.failed(error)
            showFormError(IdemKey.message(for: error, what: "the assessment"), in: &self.error); busy = false
        }
    }
}

/// The AI tools' review sentence (4.34.0), saying whether client identifiers are stripped
/// before client data goes to the provider (me's ai_redaction; nil from an older server).
func aiReviewSentence(_ redaction: Bool?) -> String {
    switch redaction {
    case true?: return "Staff review every AI draft before it is used. Client identifiers are stripped before client data is sent to the provider."
    case false?: return "Staff review every AI draft before it is used. Identifier redaction is currently off."
    case nil: return "Staff review every AI draft."
    }
}
