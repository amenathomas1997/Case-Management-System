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
    private enum CodingKeys: String, CodingKey { case text, truncated, lang, dir, assessed, totalActive }
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
                    .accessibilityFocused($focus, equals: .result)
            }
            if incomplete {
                // In words with an icon (not colour alone), and above the result, so VoiceOver
                // lands on it first when the result arrives.
                Label("Incomplete — the result stops before the end of your text; split it and try again.", systemImage: "exclamationmark.triangle")
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
                api.copyProtected(text)
                announce("Result copied. The copy is cleared from the clipboard in 10 minutes.")
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
            focus = incomplete ? .notice : .result
            reveal = false
        }
    }

    @ViewBuilder private func blockView(_ block: AIResultBlock) -> some View {
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
                    Text("Staff review every AI draft." + (anyClientTool ? " Client-specific tools (assistive technology recommendations, guided intake, progress review) are on each client's screen." : ""))
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
    @State private var mode = "plain"
    @State private var content = ""
    @State private var result: String?
    @State private var resultCaption = ""
    @State private var resultIncomplete = false
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?
    private let modes = [("plain", "Plain language"), ("simplify", "Simplify"), ("summary", "Summarize"), ("largeprint", "Large print format"), ("alt", "Alt text")]

    var body: some View {
        // ErrorFocusForm: Transform sits below a long text box, so a failure is scrolled to and
        // given VoiceOver focus rather than left in a banner row that is off screen.
        ErrorFocusForm(error: error) {
            // Inputs are locked while a request runs, and a result is cleared when they change,
            // so the result on screen always matches the choice and text shown above it.
            Picker("What to do", selection: $mode) { ForEach(modes, id: \.0) { Text($0.1).tag($0.0) } }
                .disabled(busy)
            Section("Content (for alt text, describe the image)") {
                TextEditor(text: $content).frame(minHeight: 160)
                    .accessibilityLabel("Content. For alt text, describe the image.")
                    .disabled(busy)
            }
            Text("The text you enter is sent to the AI provider as-is.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Working…" : "Transform", systemImage: "wand.and.stars") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy || content.isEmpty)
            if let result { AIResultCard(text: result, caption: resultCaption, incomplete: resultIncomplete, reveal: $reveal) }
        }
        .onChange(of: mode) { _ in if !busy { result = nil } }
        .onChange(of: content) { _ in if !busy { result = nil } }
        .navigationTitle("Remediation").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Working")
        let label = modes.first(where: { $0.0 == mode })?.1 ?? mode
        do {
            let r: AITextResponse = try await api.call("ai_remediate", body: ["mode": mode, "content": content])
            resultIncomplete = r.truncated
            resultCaption = "\(label) result" + (r.truncated ? " (incomplete)" : ""); result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct TranslateView: View {
    @EnvironmentObject var api: API
    @State private var lang = "Spanish"
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
        ErrorFocusForm(error: error) {
            // Locked while translating, and a change clears the result, so a translation is
            // never shown under a language it was not made in.
            Picker("Into", selection: $lang) { ForEach(langs, id: \.name) { Text($0.name).tag($0.name) } }
                .disabled(busy)
            Section("Content") { TextEditor(text: $content).frame(minHeight: 160).accessibilityLabel("Content to translate").disabled(busy) }
            // The server refuses more than 6,000 characters at once (ai_translate), so the limit
            // is stated up front and checked here before anything is sent.
            Text("Pasted text is sent to the provider as-is. Review before sending to a client. Up to 6,000 characters at a time; split longer text into parts.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Translating…" : "Translate", systemImage: "character.bubble") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy || content.isEmpty)
            if let result {
                AIResultCard(text: result, caption: resultCaption, language: resultLanguage, rightToLeft: resultRightToLeft,
                             incomplete: resultIncomplete, reveal: $reveal)
            }
        }
        .onChange(of: lang) { _ in if !busy { result = nil } }
        .onChange(of: content) { _ in if !busy { result = nil } }
        .navigationTitle("Translate").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        let length = content.count
        guard length <= 6000 else {
            showFormError("The content is too long to translate at once (maximum 6,000 characters; it has \(length)). Split it into parts and translate each one.", in: &error)
            return
        }
        busy = true; error = nil; result = nil
        announceAIWork("Translating")
        let requested = lang
        do {
            let r: AITextResponse = try await api.call("ai_translate", body: ["lang": requested, "content": content])
            let code = r.lang ?? langs.first(where: { $0.name == requested })?.code
            resultLanguage = code
            resultRightToLeft = r.dir.map { $0 == "rtl" } ?? (code == "ar")
            resultIncomplete = r.truncated
            resultCaption = "\(requested) translation" + (r.truncated ? " (incomplete)" : ""); result = r.text; revealAIResult(&reveal)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct CurriculumGenView: View {
    @EnvironmentObject var api: API
    @State private var tech = ""
    @State private var level = "Beginner"
    @State private var focus = ""
    @State private var result: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            // Locked while drafting, and a change clears the draft, so the skills shown always
            // match the technology, level and focus above them.
            AILabeledField(label: "Technology (e.g. JAWS)", text: $tech).disabled(busy)
            Picker("Level", selection: $level) { ForEach(["Beginner", "Intermediate", "Advanced"], id: \.self) { Text($0) } }
                .disabled(busy)
            AILabeledField(label: "Focus / notes", text: $focus).disabled(busy)
            Button { Task { await run() } } label: { Label(busy ? "Drafting…" : "Draft skills", systemImage: "list.bullet.rectangle") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy || tech.isEmpty)
            if let result { AIResultCard(text: result, footer: "Create the full curriculum on the website's Templates page.", reveal: $reveal) }
        }
        .onChange(of: tech) { _ in if !busy { result = nil } }
        .onChange(of: level) { _ in if !busy { result = nil } }
        .onChange(of: focus) { _ in if !busy { result = nil } }
        .navigationTitle("Curriculum").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Drafting skills")
        do { let r: AITextResponse = try await api.call("ai_curriculum", body: ["tech": tech, "level": level, "focus": focus]); result = r.text; revealAIResult(&reveal) }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct AskCaseloadView: View {
    @EnvironmentObject var api: API
    @State private var question = ""
    @State private var result: String?
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
            if let result { AIResultCard(text: result, reveal: $reveal) }
        }
        .onChange(of: question) { _ in if !busy { result = nil } }
        .navigationTitle("Ask your caseload").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Thinking")
        do { let r: AITextResponse = try await api.call("ai_ask", body: ["question": question]); result = r.text; revealAIResult(&reveal) }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct KBAskView: View {
    @EnvironmentObject var api: API
    @State private var question = ""
    @State private var result: String?
    @State private var reveal = false
    @State private var sources: [AIKBSource] = []
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            TextField("Your question", text: $question, axis: .vertical).lineLimit(1...3).disabled(busy)
            Button { Task { await run() } } label: { Label(busy ? "Searching…" : "Ask", systemImage: "book.closed") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy || question.isEmpty)
            if let result {
                AIResultCard(text: result, footer: sources.isEmpty ? nil : "Grounded in: " + sources.map { $0.title }.joined(separator: ", "), reveal: $reveal)
            }
        }
        .onChange(of: question) { _ in if !busy { result = nil; sources = [] } }
        .navigationTitle("Knowledge-base assistant").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil; sources = []
        announceAIWork("Searching")
        do { let r: AIKBResponse = try await api.call("ai_kb", body: ["question": question]); result = r.text; sources = r.sources; revealAIResult(&reveal) }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct RiskView: View {
    @EnvironmentObject var api: API
    @State private var result: String?
    /// "N of M active clients assessed", when the review could not cover them all.
    @State private var resultCaption: String?
    @State private var resultFooter: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Text("Reviews active clients' engagement and progress signals and flags who's at risk of not completing. Operational triage, not a prediction; each client name is replaced with a code before anything is sent to the AI.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Assessing…" : "Assess active clients", systemImage: "exclamationmark.triangle") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result { AIResultCard(text: result, caption: resultCaption, footer: resultFooter, reveal: $reveal) }
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
            if let assessed = r.assessed, let total = r.totalActive, assessed < total {
                resultCaption = "\(assessed) of \(total) active clients assessed"
                resultFooter = "Only the \(assessed) with no session yet or the longest time since their last session fit one review; the others were not assessed."
            } else {
                resultCaption = nil; resultFooter = nil
            }
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
    @State private var result: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Text("Drafts assistive-technology recommendations from the client's disability, goals and job tasks. Review before acting.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Thinking…" : "Recommend assistive technology", systemImage: "lightbulb") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result { AIResultCard(text: result, footer: "Create a device request on the website to pursue a recommendation.", reveal: $reveal) }
        }
        .navigationTitle("Assistive technology recommendations").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Thinking")
        do { let r: AITextResponse = try await api.call("ai_recommend", body: ["client_id": clientId]); result = r.text; revealAIResult(&reveal) }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct AIProgressReviewView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    @State private var result: String?
    @State private var reveal = false
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Text("Reviews recent notes and sessions against each active goal. Record any progress from the goal in its track.").font(.caption)
            Button { Task { await run() } } label: { Label(busy ? "Reviewing…" : "Generate review", systemImage: "chart.bar.doc.horizontal") }
                .accessibilityValue(busy ? "In progress" : "").disabled(busy)
            if let result { AIResultCard(text: result, reveal: $reveal) }
        }
        .navigationTitle("Progress review").navigationBarTitleDisplayMode(.inline)
    }
    private func run() async {
        busy = true; error = nil; result = nil
        announceAIWork("Reviewing")
        do { let r: AITextResponse = try await api.call("ai_progress", body: ["client_id": clientId]); result = r.text; revealAIResult(&reveal) }
        catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}

struct AIIntakeFields: Decodable {
    let primaryDisability: String; let primaryTech: String; let hardwareDetails: String; let softwareVersion: String; let summary: String
}
struct AIIntakeResponse: Decodable { let fields: AIIntakeFields; let instruments: String }

struct AIIntakeView: View {
    @EnvironmentObject var api: API
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
        ErrorFocusForm(error: error) {
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
            let _: OKResponse = try await api.call("add_assessment", body: [
                "client_id": clientId, "assessment_date": APIDate.day(date), "primary_disability": disability,
                "primary_tech": tech, "hardware_details": hardware, "software_version": software, "summary": summary,
            ])
            saved = true
            busy = false
            // The Save button VoiceOver was on is replaced by the saved notice: move onto it,
            // which speaks it (an announcement here would be cut off by the focus change).
            moveFocus(to: .saved)
            onSaved()
        } catch { showFormError(error.localizedDescription, in: &self.error); busy = false }
    }
}
