import SwiftUI

struct KBArticleRow: Decodable, Identifiable {
    let id: Int; let title: String; let category: String?; let isPublic: Bool; let updatedAt: String
    private enum CodingKeys: String, CodingKey { case id, title, category, isPublic, updatedAt }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        category = try? c.decodeIfPresent(String.self, forKey: .category)
        // A tinyint column arrives as 0/1 unless the server casts it; accept either.
        isPublic = (try? c.decode(Bool.self, forKey: .isPublic))
            ?? (try? c.decode(Int.self, forKey: .isPublic)).map { $0 != 0 } ?? false
        updatedAt = (try? c.decode(String.self, forKey: .updatedAt)) ?? ""
    }
}
struct KBArticlesResponse: Decodable { let articles: [KBArticleRow] }
struct KBArticle: Decodable {
    let id: Int; let title: String; let category: String?; let body: String?
    let isPublic: Bool; let author: String?; let updatedAt: String
    private enum CodingKeys: String, CodingKey { case id, title, category, body, isPublic, author, updatedAt }
    // Tolerant decode: is_public is a tinyint column (0/1 on the wire unless the server casts
    // it), and a strict Bool made every article fail to open. Only the id is required.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        category = try? c.decodeIfPresent(String.self, forKey: .category)
        body = try? c.decodeIfPresent(String.self, forKey: .body)
        isPublic = (try? c.decode(Bool.self, forKey: .isPublic))
            ?? (try? c.decode(Int.self, forKey: .isPublic)).map { $0 != 0 } ?? false
        author = try? c.decodeIfPresent(String.self, forKey: .author)
        updatedAt = (try? c.decode(String.self, forKey: .updatedAt)) ?? ""
    }
}
struct KBArticleResponse: Decodable { let article: KBArticle }

struct KnowledgeView: View {
    @EnvironmentObject var api: API
    @State private var articles: [KBArticleRow] = []
    @State private var query = ""
    /// The search the list on screen came from ("" = every article), set only once it loaded.
    @State private var shownQuery: String?
    /// The search of the newest request sent, answered or not ("" = every article).
    @State private var requestedQuery = ""
    @State private var loadSeq = 0
    @State private var error: String?
    @State private var editing: KBArticle?
    @State private var showNew = false
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let shownQuery, articles.isEmpty {
                // The 7:1 secondary-text colour (system secondary is about 3.4:1).
                Text(shownQuery.isEmpty ? "No articles yet." : "No articles match your search.").foregroundStyle(Color.secondaryText)
            } else if shownQuery == nil && error == nil {
                ProgressView("Loading articles…")
            }
            ForEach(articles) { a in
                NavigationLink {
                    KBDetailView(id: a.id, onChanged: { Task { await load() } })
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(a.title).font(.body.weight(.semibold))
                        HStack(spacing: 6) {
                            if let c = a.category, !c.isEmpty { Text(c).font(.caption).foregroundStyle(Color.secondaryText) }
                            if a.isPublic { Text("Public").font(.caption2.bold()).foregroundStyle(Color.brandTeal) }
                        }
                    }
                }
            }
        }
        .navigationTitle("Knowledge base")
        .searchable(text: $query, prompt: "Search articles")
        .onSubmit(of: .search) { Task { await load(announceResult: true) } }
        // Clearing the field or tapping Cancel goes back to every article. Compared with the
        // newest search sent (not the last one answered), so clearing while a search is still
        // loading starts a full reload that supersedes it.
        .onChange(of: query) { if $0.isEmpty && requestedQuery != "" { Task { await load() } } }
        .refreshable { await load() }
        .task { await load() }
        .toolbar {
            if api.can("templates") {
                ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add article") }
            }
        }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) {
            KBEditSheet(article: nil) { _ in sheetResult = "Article saved."; Task { await load() } }
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    /// announceResult: only a search the user runs says how many articles it found; a reload
    /// (back from an article, pull to refresh, after an edit) updates the list quietly, so it
    /// does not talk over VoiceOver or the detail screen's own "Article saved."
    private func load(announceResult: Bool = false) async {
        // Only the newest request may fill the list: an older search answering late must not
        // replace the results of the one the user ran (or cleared) after it.
        loadSeq += 1
        let mine = loadSeq
        let q = query
        requestedQuery = q
        do {
            error = nil
            let resp: KBArticlesResponse = try await api.call("kb_articles", query: q.isEmpty ? [:] : ["q": q])
            guard mine == loadSeq else { return }
            articles = resp.articles
            shownQuery = q
            // A search only swaps the list below the field: say how it went (not for the full
            // list, which is what every visit shows).
            if announceResult && !q.isEmpty {
                announce(articles.isEmpty ? "No articles match your search." : "\(articles.count) article\(articles.count == 1 ? "" : "s") found.")
            }
        } catch {
            guard mine == loadSeq else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

struct KBDetailView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let id: Int
    let onChanged: () -> Void
    @State private var article: KBArticle?
    @State private var error: String?
    @State private var editing = false
    @State private var deleted = false
    @State private var saved = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                if let error { ErrorBanner(message: error) }
                if let a = article {
                    Text(a.title).font(.title2.bold())
                        .accessibilityAddTraits(.isHeader)
                    HStack(spacing: 6) {
                        if let c = a.category, !c.isEmpty { Text(c).font(.caption).foregroundStyle(Color.secondaryText) }
                        if a.isPublic { Text("Shared with clients").font(.caption.bold()).foregroundStyle(Color.brandTeal) }
                    }
                    .accessibilityElement(children: .combine)
                    // One element per paragraph, heading or list item, read as the website
                    // reads it ("## " a section heading, "- " or "1. " a list item), so
                    // VoiceOver can move through a long article by heading or a piece at a time.
                    ForEach(Array(kbBlocks(a.body ?? "").enumerated()), id: \.offset) { _, b in
                        switch b {
                        case .heading(let t):
                            Text(t).font(.headline).padding(.top, 4)
                                .accessibilityAddTraits(.isHeader)
                        case .item(let marker, let t):
                            HStack(alignment: .firstTextBaseline, spacing: 6) {
                                Text(marker).font(.body)
                                Text(t).font(.body)
                            }
                            .accessibilityElement(children: .combine)
                        case .paragraph(let t):
                            Text(t).font(.body)
                        }
                    }
                } else if error == nil {
                    ProgressView("Loading article…")
                }
            }.padding()
        }
        .navigationTitle(article?.title ?? "Article")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .toolbar {
            if api.can("templates"), article != nil {
                ToolbarItem(placement: .primaryAction) { Button("Edit") { editing = true } }
            }
        }
        // After a delete there is nothing left to show (re-fetching the id only answers
        // "Not found."), so leave the article once the sheet has closed and refresh the list.
        // The result is announced once the sheet has gone (a message posted while it is still
        // open is cut off when it closes).
        .sheet(isPresented: $editing, onDismiss: {
            if deleted { dismiss(); announceAfterDismiss("Article deleted.") }
            else if saved { saved = false; announceAfterDismiss("Article saved.") }
        }) {
            if let a = article {
                KBEditSheet(article: a) { wasDeleted in
                    if wasDeleted { deleted = true; onChanged() } else { saved = true; Task { await load(); onChanged() } }
                }
            }
        }
    }

    private func load() async {
        do { error = nil; let r: KBArticleResponse = try await api.call("kb_article", query: ["id": String(id)]); article = r.article }
        catch { self.error = error.localizedDescription }
    }
}

struct KBEditSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let article: KBArticle?
    /// Called after a successful save (false) or delete (true).
    let onDone: (_ deleted: Bool) -> Void

    @State private var title = ""
    @State private var category = ""
    @State private var body_ = ""
    @State private var isPublic = false
    @State private var busy = false
    @State private var error: String?
    @State private var confirmingDelete = false
    @State private var confirmingDiscard = false
    /// The values the sheet opened with, so only real edits ask before they are discarded.
    @State private var initial: [String] = ["", "", ""]
    @State private var initialPublic = false
    @State private var filled = false

    private var hasChanges: Bool { [title, category, body_] != initial || isPublic != initialPublic }

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(inNavigationStack: true, error: error) {
                // Visible names that stay once the fields are filled (as they are when editing).
                CaptionedField(label: "Title (required)", text: $title)
                CaptionedField(label: "Category", text: $category)
                Toggle("Publish to client portals", isOn: $isPublic)
                Section("Content") {
                    TextEditor(text: $body_).frame(minHeight: 220).accessibilityLabel("Article content")
                }
                if article != nil {
                    Button("Delete article", role: .destructive) { confirmingDelete = true }.disabled(busy)
                }
            }
            // Deleting cannot be undone, so it takes a second, deliberate step.
            .confirmationDialog("Delete this article? This cannot be undone.",
                                isPresented: $confirmingDelete, titleVisibility: .visible) {
                Button("Delete article", role: .destructive) {
                    if let a = article { Task { await delete(a.id) } }
                }
                Button("Cancel", role: .cancel) {}
            }
            .navigationTitle(article == nil ? "New article" : "Edit article")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // A save or delete cannot be called back once sent, so the sheet stays until it
                // finishes (and the article screen then knows whether the article is gone).
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { requestClose() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    // Left enabled: a missing title is explained by the error banner, not a dimmed button.
                    Button("Save") { Task { await save() } }.disabled(busy)
                }
            }
            // Unsaved edits are not thrown away by a swipe down; Cancel and the VoiceOver
            // escape gesture ask first.
            .interactiveDismissDisabled(busy || hasChanges)
            .accessibilityAction(.escape) { requestClose() }
            .confirmationDialog("Discard your changes to this article?",
                                isPresented: $confirmingDiscard, titleVisibility: .visible) {
                Button("Discard changes", role: .destructive) { dismiss() }
                Button("Keep editing", role: .cancel) {}
            }
            .onAppear {
                // Once only, so a second appearance never resets what has been typed.
                guard !filled else { return }
                filled = true
                if let a = article { title = a.title; category = a.category ?? ""; body_ = a.body ?? ""; isPublic = a.isPublic }
                initial = [title, category, body_]; initialPublic = isPublic
            }
        }
    }

    private func requestClose() {
        guard !busy else { return }
        if hasChanges { confirmingDiscard = true } else { dismiss() }
    }

    // The presenter announces the result once the sheet has gone.
    private func save() async {
        if title.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Title: enter a title for the article.", in: &error); return
        }
        busy = true; error = nil
        var b: [String: Any] = ["title": title, "category": category, "body": body_, "is_public": isPublic ? 1 : 0]
        if let a = article { b["id"] = a.id }
        do { let _: OKResponse = try await api.call("save_kb", body: b); onDone(false); dismiss() }
        catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
    private func delete(_ id: Int) async {
        busy = true; error = nil
        do { let _: OKResponse = try await api.call("delete_kb", body: ["id": id]); onDone(true); dismiss() }
        catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}

/// One piece of an article body.
enum KBBlock {
    case heading(String)
    /// A list item with its bullet ("•") or number ("2.").
    case item(marker: String, text: String)
    case paragraph(String)
}

/// The article body read as kb.php's plain_text_html() reads it: a line starting "# " to
/// "###### " is a heading; "- ", "* " or "• " a bulleted item and "1. " or "1) " a numbered
/// one (numbered on from the first item's number, and a line right after an item continues
/// it); a "---" line is dropped; blank lines separate paragraphs, which keep their line
/// breaks. The text is shown as written (no markdown is read into it).
func kbBlocks(_ body: String) -> [KBBlock] {
    func match(_ line: String, _ pattern: String) -> [String]? {
        guard let re = try? NSRegularExpression(pattern: pattern),
              let m = re.firstMatch(in: line, range: NSRange(line.startIndex..., in: line)) else { return nil }
        return (0..<m.numberOfRanges).map { i in
            Range(m.range(at: i), in: line).map { String(line[$0]) } ?? ""
        }
    }
    var blocks: [KBBlock] = []
    var para: [String] = []
    var listKind = ""          // "ul", "ol" or "" outside a list
    var items: [[String]] = []
    var start = 1
    func flush() {
        if !para.isEmpty { blocks.append(.paragraph(para.joined(separator: "\n"))); para = [] }
        for (i, lines) in items.enumerated() {
            blocks.append(.item(marker: listKind == "ol" ? "\(start + i)." : "•", text: lines.joined(separator: "\n")))
        }
        listKind = ""; items = []; start = 1
    }
    let text = body.replacingOccurrences(of: "\r\n", with: "\n").replacingOccurrences(of: "\r", with: "\n")
    for line in text.components(separatedBy: "\n") {
        let trimmed = line.trimmingCharacters(in: .whitespaces)
        if trimmed.isEmpty { flush(); continue }
        if match(line, "^\\s*([-*_])(?:\\s*\\1){2,}\\s*$") != nil { flush(); continue }
        if let m = match(line, "^\\s{0,3}#{1,6}\\s+(.+?)[\\s#]*$") {
            let t = m[1].trimmingCharacters(in: .whitespaces)
            if !t.isEmpty { flush(); blocks.append(.heading(t)); continue }
        }
        var kind = "", item = "", number = 1
        if let m = match(line, "^\\s*[-*•]\\s+(.+)$") { kind = "ul"; item = m[1] }
        else if let m = match(line, "^\\s*(\\d{1,3})[.)]\\s+(.+)$") { kind = "ol"; item = m[2]; number = max(1, Int(m[1]) ?? 1) }
        if !kind.isEmpty {
            if !para.isEmpty || listKind != kind {
                flush()
                listKind = kind
                start = kind == "ol" ? number : 1
            }
            items.append([item.trimmingCharacters(in: .whitespaces)])
            continue
        }
        if !listKind.isEmpty, !items.isEmpty { items[items.count - 1].append(trimmed); continue }
        para.append(trimmed)
    }
    flush()
    return blocks
}

/// Article or agreement text as headings, list items and paragraphs, one VoiceOver element each.
struct KBBlocksView: View {
    let text: String
    var body: some View {
        ForEach(Array(kbBlocks(text).enumerated()), id: \.offset) { _, b in
            switch b {
            case .heading(let t):
                Text(t).font(.headline).padding(.top, 4).accessibilityAddTraits(.isHeader)
            case .item(let marker, let t):
                HStack(alignment: .firstTextBaseline, spacing: 6) { Text(marker).font(.body); Text(t).font(.body) }
                    .accessibilityElement(children: .combine)
            case .paragraph(let t):
                Text(t).font(.body)
            }
        }
    }
}
