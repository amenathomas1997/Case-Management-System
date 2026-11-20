import SwiftUI

// MARK: - Letters (4.37.0, S14), as letters.php

struct LetterChoice: Decodable { let key: String; let name: String }

/// letters' reply: the seven letters, the one opened, its title and wording filled in from the
/// record, its language, whether the client prefers Spanish, and whether AI drafting is on.
struct LettersReply: Decodable {
    let letters: [LetterChoice]
    let key: String
    let title: String
    let body: String
    let lang: String?
    let prefersSpanish: Bool?
    let ai: Bool?
}

/// ai_letter's reply: the draft, whether it goes in a separate box (the letter was edited), the
/// website's sentence and whether it stopped early.
struct LetterDraftReply: Decodable { let text: String; let separate: Bool?; let message: String?; let truncated: Bool? }

/// save_letter's reply.
struct LetterSaveReply: Decodable { let message: String?; let documentId: Int? }

/// save_letter's body: "doc" saves it to the client's documents (to the portal when asked),
/// "note" keeps it as a case note.
func letterSaveBody(clientId: Int, how: String, key: String, title: String, body: String, lang: String, toPortal: Bool) -> [String: Any] {
    ["client_id": clientId, "how": how, "key": key, "title": title.trimmingCharacters(in: .whitespacesAndNewlines), "body": body,
     "lang": lang, "to_portal": how == "doc" && toPortal]
}

/// The letter as a plain accessible HTML page (title as its heading, paragraphs as typed), the
/// file the website's "Download to print or send" gives.
func letterHTML(title: String, body: String, lang: String) -> String {
    func esc(_ s: String) -> String {
        s.replacingOccurrences(of: "&", with: "&amp;").replacingOccurrences(of: "<", with: "&lt;")
            .replacingOccurrences(of: ">", with: "&gt;").replacingOccurrences(of: "\"", with: "&quot;")
    }
    let paragraphs = body.components(separatedBy: "\n\n").map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty }
        .map { "<p>" + esc($0).replacingOccurrences(of: "\n", with: "<br>") + "</p>" }.joined(separator: "\n")
    return "<!doctype html>\n<html lang=\"\(lang == "es" ? "es" : "en")\">\n<head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>\(esc(title))</title></head>\n<body>\n<h1>\(esc(title))</h1>\n\(paragraphs)\n</body>\n</html>\n"
}

/// The file name the letter is shared under.
func letterFileName(_ title: String) -> String {
    let safe = title.map { $0.isLetter || $0.isNumber ? $0 : "-" }.reduce("") { $0 + String($1) }
    let trimmed = safe.trimmingCharacters(in: CharacterSet(charactersIn: "-"))
    return (trimmed.isEmpty ? "letter" : String(trimmed.prefix(80))) + ".html"
}

/// The question before another letter replaces an edited one.
func switchLetterQuestion(_ name: String) -> String {
    "Open the \(name) instead? Your changes to this letter are lost."
}

struct LetterView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let clientName: String
    /// Given the server's sentence once the letter is saved; said when this sheet has gone.
    let onSaved: (String) -> Void
    @State private var letters: [LetterChoice] = []
    @State private var key = ""
    @State private var title = ""
    @State private var letterBody = ""
    @State private var lang = "en"
    @State private var prefersSpanish = false
    @State private var aiOn = false
    @State private var toPortal = false
    /// The wording as opened, to tell whether the person has changed it.
    @State private var loadedTitle = ""
    @State private var loadedBody = ""
    @State private var loaded = false
    @State private var pendingKey: String?
    @State private var drafting = false
    @State private var draftNotice: String?
    @State private var aiDraft: String?
    @State private var bodyBeforeDraft: String?
    @State private var saving = false
    @State private var idem = IdemKey()
    @State private var error: String?
    @State private var askingDiscard = false
    /// The letter as an HTML file for the share sheet, rebuilt as it changes.
    @State private var letterFile: URL?

    private var edited: Bool { loaded && (letterBody != loadedBody || title != loadedTitle) }
    private func name(_ key: String) -> String { letters.first { $0.key == key }?.name ?? "letter" }

    private var letterKey: Binding<String> {
        Binding(get: { key }, set: { next in
            guard next != key else { return }
            if edited { pendingKey = next } else { Task { await load(next) } }
        })
    }

    /// letters.php's hint, inside the label: always said, with the Spanish advice when the
    /// client prefers Spanish.
    private var languageLabel: String {
        prefersSpanish
            ? "Language of the letter (this client prefers Spanish; the wording below is in English, so choose Spanish if you write it in Spanish)"
            : "Language of the letter (the wording below is in English)"
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if !loaded && error == nil { ProgressView("Loading the letter…") }
                if loaded {
                    Section {
                        Picker("Letter", selection: letterKey) {
                            ForEach(letters, id: \.key) { Text($0.name).tag($0.key) }
                        }
                        Text("Filled in from the record. Check it and change anything before saving; \"[not on file]\" marks details the record does not have.")
                            .font(.callout)
                        CaptionedField(label: "Title (optional; blank uses the letter's name)", text: $title, errorName: "Title")
                        Picker(languageLabel, selection: $lang) {
                            Text("English").tag("en")
                            Text("Spanish").tag("es")
                        }
                        CaptionedField(label: "Letter (required)", text: $letterBody, lines: 10...30, errorName: "Letter")
                            .disabled(drafting)
                    }
                    if let aiDraft {
                        Section("AI draft") {
                            Text(aiDraft).font(.callout)
                            Button("Use the AI draft as the letter") {
                                bodyBeforeDraft = letterBody
                                letterBody = aiDraft
                                self.aiDraft = nil
                                announce("The AI draft is now the letter. Review and edit it before saving.")
                            }
                        }
                    }
                    if let restore = bodyBeforeDraft {
                        Section {
                            Button("Restore my letter") {
                                letterBody = restore; bodyBeforeDraft = nil
                                announce("Your letter is back.")
                            }
                        }
                    }
                    // letters.php's order: the portal tick, the buttons, then the AI draft last.
                    Section {
                        Toggle("Let the client read it in their portal", isOn: $toPortal)
                        Button(saving ? "Saving…" : "Save to the client's documents") { Task { await save("doc") } }
                            .disabled(saving || drafting)
                        Button("Keep as a case note") { Task { await save("note") } }
                            .disabled(saving || drafting)
                        // The website's "Download to print or send": the letter as an HTML file.
                        if let file = letterFile {
                            ShareLink(item: file) { Label("Share the letter", systemImage: "square.and.arrow.up") }
                        }
                        Button {
                            api.copyProtected(letterBody)
                            announce("Letter copied.")
                        } label: { Label("Copy letter", systemImage: "doc.on.doc") }
                        .disabled(letterBody.isEmpty)
                    }
                    if aiOn && api.aiHas("draft") {
                        Section {
                            Button(drafting ? "Drafting…" : "Draft the letter with AI") { Task { await draft() } }
                                .disabled(drafting || saving)
                            if let draftNotice { Label(draftNotice, systemImage: "exclamationmark.triangle").font(.callout.weight(.semibold)) }
                        }
                    }
                }
            }
            .onChange(of: letterBody) { _ in refreshLetterFile() }
            .onChange(of: title) { _ in refreshLetterFile() }
            .onChange(of: lang) { _ in refreshLetterFile() }
            .onDisappear { if let letterFile { try? FileManager.default.removeItem(at: letterFile.deletingLastPathComponent()) } }
            .navigationTitle("Write a letter")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if edited { askingDiscard = true } else { dismiss() } }.disabled(saving)
                }
            }
            // As Android: the question, what is lost, and Discard / Keep editing.
            .interactiveDismissDisabled(saving || edited)
            .accessibilityAction(.escape) {
                if saving { announce("Still saving. Wait for it to finish.") }
                else if edited { askingDiscard = true }
                else { dismiss() }
            }
            .confirmationDialog("Discard this letter?", isPresented: $askingDiscard, titleVisibility: .visible) {
                Button("Discard", role: .destructive) { dismiss() }
                Button("Keep editing", role: .cancel) {}
            } message: {
                Text("What you changed in this letter is not saved.")
            }
            .confirmationDialog(switchLetterQuestion(name(pendingKey ?? "")),
                                isPresented: Binding(get: { pendingKey != nil }, set: { if !$0 { pendingKey = nil } }),
                                titleVisibility: .visible) {
                Button("Open it", role: .destructive) {
                    if let next = pendingKey { Task { await load(next) } }
                    pendingKey = nil
                }
                Button("Keep my letter", role: .cancel) { pendingKey = nil }
            }
            .task { if !loaded { await load(nil) } }
        }
    }

    /// The title saved: what was typed, else the letter's name (as letters.php).
    private var shownTitle: String {
        let typed = title.trimmingCharacters(in: .whitespacesAndNewlines)
        return typed.isEmpty ? name(key) : typed
    }

    private func refreshLetterFile() {
        if let old = letterFile { try? FileManager.default.removeItem(at: old.deletingLastPathComponent()) }
        guard !letterBody.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { letterFile = nil; return }
        letterFile = shareableFile(letterHTML(title: shownTitle, body: letterBody, lang: lang), named: letterFileName(shownTitle), fallback: "letter.html")
    }

    private func load(_ next: String?) async {
        var query = ["client_id": String(clientId)]
        if let next { query["key"] = next }
        do {
            let r: LettersReply = try await api.call("letters", query: query)
            letters = r.letters
            key = r.key
            title = r.title; loadedTitle = r.title
            letterBody = r.body; loadedBody = r.body
            lang = r.lang == "es" ? "es" : "en"
            prefersSpanish = r.prefersSpanish == true
            aiOn = r.ai == true
            aiDraft = nil; bodyBeforeDraft = nil; draftNotice = nil
            loaded = true
            error = nil
            refreshLetterFile()
            if next != nil { announce("\(name(r.key)) opened.") }
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    /// "Draft the letter with AI", as letters.php: an unedited letter is replaced by the draft;
    /// an edited one is kept, and the draft goes in a separate box.
    private func draft() async {
        drafting = true; error = nil; draftNotice = nil
        announce("Drafting…")
        defer { drafting = false }
        let wasEdited = letterBody != loadedBody
        do {
            let r: LetterDraftReply = try await api.call("ai_letter", method: "POST",
                                                         body: ["client_id": clientId, "key": key, "body": letterBody, "edited": wasEdited])
            var said: String
            if r.separate == true || wasEdited {
                aiDraft = r.text
                said = r.message ?? "Your letter is unchanged. The AI draft is in a separate box below it — review it, and use it only if you want it."
            } else {
                letterBody = r.text
                said = r.message ?? "The AI draft is in the letter box below — review and edit it before saving."
            }
            if r.truncated == true { draftNotice = aiDraftIncomplete; said += " " + aiDraftIncomplete }
            if let left = placeholderNotice(r.text) { said += " " + left }
            announce(said)
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func save(_ how: String) async {
        if letterBody.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("The letter is empty. Write the letter, or open it again to start from the filled-in wording.", in: &error, field: "Letter"); return
        }
        saving = true; error = nil
        var body = letterSaveBody(clientId: clientId, how: how, key: key, title: title, body: letterBody, lang: lang, toPortal: toPortal)
        body["idem_key"] = idem.key(for: body)
        var refused: [String: String] = [:]
        do {
            let r: LetterSaveReply = try await api.call("save_letter", method: "POST", body: body, refusal: { refused = refusalFields($0) })
            idem.answered()
            onSaved(r.message ?? (how == "note" ? "The letter was kept as a case note." : "The letter was saved to the client's documents."))
            dismiss()
        } catch {
            idem.failed(error)
            saving = false
            showFormError(IdemKey.message(for: error, what: "the letter"), in: &self.error,
                          field: refusedFieldName(refused, order: ["title", "body"], names: ["title": "Title", "body": "Letter"]))
        }
    }
}
