import SwiftUI

/// My training (4.16.0): training to read and pass (api_more.php training, submit_training).
struct TrainingView: View {
    @EnvironmentObject var api: API
    @State private var items: [[String: Any]] = []
    @State private var loaded = false
    @State private var error: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if !loaded && error == nil { ProgressView("Loading your training…") }
            if loaded {
                // 4.36.0: every module not done counts, renewals due too, as the website's "N of M to take".
                let needed = items.filter { ($0["state"] as? String) != "done" }.count
                Text(items.isEmpty ? "There is no training for you to take." : needed > 0 ? "\(needed) of \(items.count) to take." : "Every training is passed and current.")
            }
            ForEach(Array(items.enumerated()), id: \.offset) { _, m in
                let id = m["id"] as? Int ?? 0
                NavigationLink {
                    TrainingTakeView(id: id, onPassed: { Task { await load() } })
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(m["title"] as? String ?? "Training").font(.body.weight(.semibold))
                        Text(((m["state"] as? String) == "needed" ? "To take: " + (m["why"] as? String ?? "").lowercased() : (m["why"] as? String ?? "")))
                            .font(.subheadline).foregroundStyle(Color.secondaryText)
                    }.accessibilityElement(children: .combine)
                }
            }
        }
        .navigationTitle("My training")
        .task { await load() }
        .refreshable { await load() }
    }

    private func load() async {
        do {
            items = try await api.callDict("training")["training"] as? [[String: Any]] ?? []
            loaded = true; error = nil
        } catch { self.error = error.localizedDescription }
    }
}

struct TrainingTakeView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let id: Int
    let onPassed: () -> Void
    @State private var module: [String: Any]?
    @State private var answers: [Int] = []
    @State private var wrong: [Int] = []
    @State private var notice: String?
    @State private var busy = false
    @State private var error: String?

    private var questions: [[String: Any]] { module?["questions"] as? [[String: Any]] ?? [] }

    var body: some View {
        // The error banner is scrolled to and focused, or the question an error is about (4.30.0).
        ErrorFocusForm(inNavigationStack: true, error: error) {
            if let m = module {
                Section {
                    Text((m["why"] as? String ?? "") + ". Read it, then answer the \(questions.count) questions; \(m["pass_pct"] as? Int ?? 80)% passes.")
                    KBBlocksView(text: m["body"] as? String ?? "")
                } header: { Text("Read").accessibilityAddTraits(.isHeader) }
                if let notice { Section { Text(notice).font(.callout.weight(.semibold)) } }
                ForEach(Array(questions.enumerated()), id: \.offset) { i, q in
                    let opts = q["options"] as? [String] ?? []
                    Section {
                        // The section header names the question, so the picker's own label is hidden (read once).
                        Picker("\(i + 1). \(q["q"] as? String ?? "")", selection: Binding(
                            get: { answers.indices.contains(i) ? answers[i] : -1 },
                            set: { v in if answers.indices.contains(i) { answers[i] = v } })) {
                            ForEach(Array(opts.enumerated()), id: \.offset) { o, text in Text(text).tag(o) }
                        }
                        .pickerStyle(.inline)
                        .labelsHidden()
                    } header: {
                        Text("\(i + 1). \(q["q"] as? String ?? "")" + (wrong.contains(i + 1) ? " (answered wrongly last time)" : "")).accessibilityAddTraits(.isHeader)
                            // A question left unanswered: the error is shown under it and
                            // VoiceOver is put on it.
                            .errorField("Question \(i + 1)", setsID: false)
                    }
                    .id(FormFieldID(name: "question \(i + 1)"))
                }
                Section {
                    Button(busy ? "Checking…" : "Check my answers") { Task { await submit(m) } }.disabled(busy)
                }
            } else if error == nil {
                ProgressView("Loading the training…")
            }
        }
        .navigationTitle(module?["title"] as? String ?? "Training")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
    }

    private func load() async {
        do {
            module = (try await api.callDict("training")["training"] as? [[String: Any]] ?? []).first { ($0["id"] as? Int) == id }
            if module == nil { error = "That training is no longer asked for." }
            answers = Array(repeating: -1, count: questions.count)
        } catch { self.error = error.localizedDescription }
    }

    private func submit(_ m: [String: Any]) async {
        if let missing = answers.firstIndex(of: -1) {
            showFormError("Question \(missing + 1): choose an answer. Every question needs one.", in: &error); return
        }
        busy = true; error = nil
        do {
            let r = try await api.callDict("submit_training", body: ["id": id, "version": m["version"] as? Int ?? 0, "text_hash": m["text_hash"] as? String ?? "", "answers": answers])
            busy = false
            if (r["passed"] as? Bool) == true {
                onPassed()
                await api.refreshMe()
                // Said once this screen has closed: the pop moves VoiceOver, which cut it off.
                announceAfterDismiss(r["message"] as? String ?? "Passed.")
                dismiss()
            } else {
                wrong = r["wrong"] as? [Int] ?? []
                notice = r["message"] as? String
                announce(notice ?? "Not passed.")
            }
        } catch {
            busy = false
            showFormError(error.localizedDescription, in: &self.error)
            if error.localizedDescription.contains("changed while you had it open") { wrong = []; await load() }
        }
    }
}

/// My credentials (4.16.0): certifications and checks with expiry dates.
struct CredentialsView: View {
    @EnvironmentObject var api: API
    @State private var items: [[String: Any]] = []
    @State private var types: [String] = []
    @State private var loaded = false
    @State private var error: String?
    @State private var editing: [String: Any]?
    @State private var showEditor = false

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            Text("From 60 days before one expires, it shows on your Home page and an administrator is reminded.")
            Button("Add a credential") { editing = [:]; showEditor = true }
            if loaded && items.isEmpty { Text("None recorded yet.") }
            ForEach(Array(items.enumerated()), id: \.offset) { _, c in
                Button { editing = c; showEditor = true } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(c["name"] as? String ?? "").font(.body.weight(.semibold))
                        Text(c["words"] as? String ?? "").font(.subheadline).foregroundStyle(Color.secondaryText)
                    }.accessibilityElement(children: .combine).accessibilityHint("Change or remove it")
                }
            }
        }
        .navigationTitle("My credentials")
        .task { await load() }
        .sheet(isPresented: $showEditor) {
            NavigationStack { CredentialEditView(credential: editing ?? [:], types: types) { await load() } }
        }
    }

    private func load() async {
        do {
            let r = try await api.callDict("credentials")
            items = r["credentials"] as? [[String: Any]] ?? []
            types = r["types"] as? [String] ?? []
            loaded = true; error = nil
        } catch { self.error = error.localizedDescription }
    }
}

struct CredentialEditView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let credential: [String: Any]
    let types: [String]
    let onDone: () async -> Void
    @State private var type = ""
    @State private var other = ""
    @State private var number = ""
    @State private var issuer = ""
    @State private var issued = ""
    @State private var expires = ""
    @State private var notes = ""
    /// A new credential's retry key (4.36.0): a retry after a lost reply does not add it twice.
    @State private var idem = IdemKey()
    @State private var busy = false
    @State private var error: String?
    @State private var confirmRemove = false

    private var id: Int { credential["id"] as? Int ?? 0 }

    var body: some View {
        // Errors are scrolled to and focused, on the field they are about (4.30.0); the pickers
        // open a screen of their own at the accessibility text sizes (ReadablePickers).
        ErrorFocusForm(inNavigationStack: true, error: error) {
            Picker("Credential (required)", selection: $type) {
                Text("Choose one").tag("")
                ForEach(types, id: \.self) { Text($0).tag($0) }
            }
            .errorField("Credential")
            if type == "Other" { CaptionedField(label: "Its name (required)", text: $other, errorName: "Its name") }
            CaptionedField(label: "Number, optional", text: $number, errorName: "Number")
            CaptionedField(label: "Issued by, optional", text: $issuer, errorName: "Issued by")
            CaptionedField(label: "Date issued, year-month-day, optional", text: $issued, keyboard: .numbersAndPunctuation, errorName: "Date issued")
            CaptionedField(label: "Expires on, year-month-day; empty if it does not expire", text: $expires, keyboard: .numbersAndPunctuation,
                           errorName: "Expires on")
            CaptionedField(label: "Notes, optional", text: $notes, lines: 1...4, errorName: "Notes")
            // Not dimmed while the credential is unchosen: Save then says what is missing, on the field.
            Button(busy ? "Saving…" : "Save") { Task { await save() } }.disabled(busy)
            if id > 0 { Button("Remove this credential", role: .destructive) { confirmRemove = true } }
        }
        .navigationTitle(id > 0 ? (credential["name"] as? String ?? "Credential") : "Add a credential")
        .toolbar { ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } } }
        .confirmationDialog("Remove \(credential["name"] as? String ?? "this credential")?", isPresented: $confirmRemove, titleVisibility: .visible) {
            Button("Remove", role: .destructive) { Task { await remove() } }
        }
        .onAppear {
            let name = credential["name"] as? String ?? ""
            type = name.isEmpty ? "" : (types.contains(name) ? name : "Other")
            other = types.contains(name) ? "" : name
            number = credential["number"] as? String ?? ""; issuer = credential["issuer"] as? String ?? ""
            issued = credential["issued_on"] as? String ?? ""; expires = credential["expires_on"] as? String ?? ""
            notes = credential["notes"] as? String ?? ""
        }
    }

    private func save() async {
        let name = type == "Other" ? other.trimmingCharacters(in: .whitespaces) : type
        let issuedOn = issued.trimmingCharacters(in: .whitespaces)
        let expiresOn = expires.trimmingCharacters(in: .whitespaces)
        // The website's checks and sentences (credentials.php), said here first, on the field.
        if let problem = credentialProblem(type: type, name: name, number: number, issuer: issuer, issued: issuedOn,
                                           expires: expiresOn, notes: notes) {
            showFormError(problem.message, in: &error, field: problem.field)
            return
        }
        busy = true; error = nil
        var body: [String: Any] = ["id": id, "type": type, "other_name": type == "Other" ? name : "",
                                   // name too, for a server before 4.36.0, which reads only it
                                   "name": name, "number": number.trimmingCharacters(in: .whitespaces),
                                   "issuer": issuer.trimmingCharacters(in: .whitespaces), "issued_on": issuedOn, "expires_on": expiresOn,
                                   "notes": notes.trimmingCharacters(in: .whitespacesAndNewlines)]
        if id == 0 { body["idem_key"] = idem.key(for: body) }
        var refusedFields: [String: String] = [:]
        do {
            let _: OKResponse = try await api.call("save_credential", method: "POST", body: body, refusal: { reply in
                refusedFields = (reply["errors"] as? [String: String]) ?? [:]
            })
            idem.answered()
            // Said once the sheet has gone: closing it moves VoiceOver, which cut it off.
            await onDone(); announceAfterDismiss("Saved \(name)."); dismiss()
        } catch APIError.server(let message) {
            idem.answered()
            busy = false
            let order = ["type", "other_name", "number", "issuer", "issued_on", "expires_on", "notes"]
            showFormError(message, in: &self.error, field: order.first { refusedFields[$0] != nil }.flatMap { credentialFieldNames[$0] })
        } catch {
            idem.failed(error)
            busy = false
            showFormError(IdemKey.message(for: error), in: &self.error)
        }
    }

    private func remove() async {
        do { _ = try await api.callDict("delete_credential", body: ["id": id]); await onDone(); announceAfterDismiss("Removed."); dismiss() }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

/// save_credential's errors keys and the fields they are about (4.36.0).
let credentialFieldNames = ["type": "Credential", "other_name": "Its name", "issued_on": "Date issued",
                            "expires_on": "Expires on", "number": "Number", "issuer": "Issued by", "notes": "Notes"]

/// A real year-month-day date ("2026-02-30" is not), or nil.
func credentialDay(_ text: String) -> Date? {
    guard text.range(of: #"^\d{4}-\d{2}-\d{2}$"#, options: .regularExpression) != nil else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.calendar = Calendar(identifier: .gregorian)
    f.dateFormat = "yyyy-MM-dd"
    guard let date = f.date(from: text), f.string(from: date) == text else { return nil }
    return date
}

/// The first problem with a credential, in the website's words, and the field it is about.
func credentialProblem(type: String, name: String, number: String, issuer: String, issued: String,
                       expires: String, notes: String) -> (field: String, message: String)? {
    if type.isEmpty { return ("Credential", "Credential: choose the credential, or choose Other and name it.") }
    if type == "Other" && name.isEmpty { return ("Its name", "Its name: name the credential, since Other is chosen.") }
    if name.count > 120 { return ("Its name", "Its name: keep the name to 120 characters or fewer.") }
    if number.trimmingCharacters(in: .whitespaces).count > 80 { return ("Number", "Number: keep this to 80 characters or fewer.") }
    if issuer.trimmingCharacters(in: .whitespaces).count > 120 { return ("Issued by", "Issued by: keep this to 120 characters or fewer.") }
    var issuedDate: Date?
    if !issued.isEmpty {
        issuedDate = credentialDay(issued)
        if issuedDate == nil || issuedDate! > Date() {
            return ("Date issued", "Date issued: enter the date issued as a real date, not in the future.")
        }
    }
    if !expires.isEmpty {
        guard let expiry = credentialDay(expires) else {
            return ("Expires on", "Expires on: enter the expiry date as a real date, or leave it empty if it does not expire.")
        }
        if let issuedDate, expiry < issuedDate { return ("Expires on", "Expires on: the expiry date is before the date issued.") }
    }
    if notes.trimmingCharacters(in: .whitespacesAndNewlines).count > 255 { return ("Notes", "Notes: keep this to 255 characters or fewer.") }
    return nil
}
