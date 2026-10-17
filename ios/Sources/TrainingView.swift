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
                let needed = items.filter { ($0["state"] as? String) == "needed" }.count
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
        Form {
            if let error { ErrorBanner(message: error) }
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
                    }
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
        if let missing = answers.firstIndex(of: -1) { error = "Answer every question (question \(missing + 1) has no answer)."; announce(error!); return }
        busy = true; error = nil
        do {
            let r = try await api.callDict("submit_training", body: ["id": id, "version": m["version"] as? Int ?? 0, "text_hash": m["text_hash"] as? String ?? "", "answers": answers])
            busy = false
            if (r["passed"] as? Bool) == true {
                onPassed()
                await api.refreshMe()
                announce(r["message"] as? String ?? "Passed.")
                dismiss()
            } else {
                wrong = r["wrong"] as? [Int] ?? []
                notice = r["message"] as? String
                announce(notice ?? "Not passed.")
            }
        } catch {
            busy = false
            self.error = error.localizedDescription
            announce(error.localizedDescription)
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
    @State private var busy = false
    @State private var error: String?
    @State private var confirmRemove = false

    private var id: Int { credential["id"] as? Int ?? 0 }

    var body: some View {
        Form {
            if let error { ErrorBanner(message: error) }
            Picker("Credential", selection: $type) {
                Text("Choose one").tag("")
                ForEach(types, id: \.self) { Text($0).tag($0) }
            }
            if type == "Other" { CaptionedField(label: "Its name", text: $other) }
            CaptionedField(label: "Number, optional", text: $number)
            CaptionedField(label: "Issued by, optional", text: $issuer)
            CaptionedField(label: "Date issued, year-month-day, optional", text: $issued)
            CaptionedField(label: "Expires on, year-month-day; empty if it does not expire", text: $expires)
            Button(busy ? "Saving…" : "Save") { Task { await save() } }.disabled(busy || type.isEmpty)
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
        }
    }

    private func save() async {
        let name = type == "Other" ? other.trimmingCharacters(in: .whitespaces) : type
        guard !name.isEmpty else { error = "Name the credential."; return }
        busy = true; error = nil
        do {
            _ = try await api.callDict("save_credential", body: ["id": id, "name": name, "number": number, "issuer": issuer, "issued_on": issued, "expires_on": expires])
            await onDone(); announce("Saved \(name)."); dismiss()
        } catch { busy = false; self.error = error.localizedDescription; announce(error.localizedDescription) }
    }

    private func remove() async {
        do { _ = try await api.callDict("delete_credential", body: ["id": id]); await onDone(); announce("Removed."); dismiss() }
        catch { self.error = error.localizedDescription }
    }
}
