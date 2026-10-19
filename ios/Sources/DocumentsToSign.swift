import SwiftUI

// MARK: - Documents to sign (4.25.0)

/// How a document goes to its signer: a private link by email, signed here on this phone now,
/// or (4.26.0) prepared now and kept on this phone to sign later, even with no signal.
enum SignatureMode: String, Identifiable {
    case email, inPerson, prepare
    var id: String { rawValue }
}

/// A document to sign in person, and whether it was prepared earlier and kept on this phone.
struct SigningSession: Identifiable {
    let document: SigningDocument
    let prepared: Bool
    /// The last moment it can be signed (two days from the server's start, less a minute); nil
    /// when the start time is not known.
    let signBy: Date?
    var id: Int { document.id }

    init(document: SigningDocument, prepared: Bool, signBy: Date?) {
        self.document = document; self.prepared = prepared; self.signBy = signBy
    }

    /// One just started or opened again: the window counts from the server's start time.
    init(document: SigningDocument, startedAt raw: String?) {
        self.init(document: document, prepared: false,
                  signBy: SigningWindow.serverTime(raw ?? document.sentAt).map(SigningWindow.signBy))
    }
}

/// A client's documents to sign: consent to services, equipment loan agreements and any other
/// document, sent by email or signed here, in person, with the phone handed to the signer.
struct SignaturesView: View {
    @EnvironmentObject var api: API
    let clientId: Int
    let client: ClientInfo
    /// Tells the client screen to refresh its "waiting" count.
    var onChanged: () -> Void = {}

    @State private var data: SignaturesResponse?
    @State private var error: String?
    @State private var confirmation: String?
    @State private var request: SignatureMode?
    /// Set by the in-person form once the document is ready; the signing screen opens when the form has closed.
    @State private var readyToSign: SigningDocument?
    @State private var signing: SigningSession?
    @State private var sheetResult: String?
    @State private var withdrawing: SignatureRow?
    @State private var removingPrepared: PreparedSignature?
    @State private var opening = false
    /// Prepared on this phone to sign with no signal (4.26.0).
    @State private var prepared: [PreparedSignature] = []
    /// Signed here with no signal, waiting to be sent (4.26.0).
    @State private var waitingToSend: [OfflineSignatures.Waiting] = []

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let confirmation {
                Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
            }
            if !waitingToSend.isEmpty {
                Section("Signed here, waiting to send") {
                    ForEach(waitingToSend) { item in
                        VStack(alignment: .leading, spacing: 3) {
                            Text(item.title).font(.body.weight(.semibold))
                            Text(waitingDetails(item)).font(.callout)
                            if let why = item.lastError {
                                Label("Not accepted: \(why)", systemImage: "exclamationmark.triangle.fill")
                                    .font(.callout).foregroundStyle(Color.alertRed)
                            }
                        }
                        .padding(.vertical, 2)
                        .accessibilityElement(children: .combine)
                    }
                }
            }
            if !prepared.isEmpty {
                Section("Ready to sign here, even with no signal") {
                    ForEach(prepared) { item in
                        Button { confirmation = nil; signing = SigningSession(document: item.document, prepared: true, signBy: item.expiresAt) } label: {
                            VStack(alignment: .leading, spacing: 3) {
                                Text(item.title).font(.body.weight(.semibold))
                                Text(preparedDetails(item)).font(.callout).foregroundStyle(Color.secondaryText)
                            }
                            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                            .contentShape(Rectangle())
                        }
                        .foregroundStyle(.primary)
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel("Sign \(item.title). \(preparedDetails(item))")
                        .accessibilityAddTraits(.isButton)
                        .accessibilityActions {
                            Button("Remove from this phone", role: .destructive) { removingPrepared = item }
                        }
                        .contextMenu {
                            Button("Remove from this phone", role: .destructive) { removingPrepared = item }
                        }
                    }
                }
            }
            Section("Send or sign") {
                Button { confirmation = nil; request = .inPerson } label: {
                    Label("Sign here now", systemImage: "hand.draw")
                }
                Button { confirmation = nil; request = .prepare } label: {
                    Label("Prepare to sign with no signal", systemImage: "arrow.down.doc")
                }
                Button { confirmation = nil; request = .email } label: {
                    Label("Send by email", systemImage: "envelope")
                }
            }
            .disabled(data == nil || opening)
            Section(data.map { "Documents (\($0.signatures.count))" } ?? "Documents") {
                if data == nil && error == nil {
                    ProgressView("Loading documents to sign…")
                } else if let data, data.signatures.isEmpty {
                    Text("No documents sent to sign yet.").foregroundStyle(Color.secondaryText)
                }
                ForEach(data?.signatures ?? []) { row in
                    VStack(alignment: .leading, spacing: 3) {
                        Text(row.title).font(.body.weight(.semibold))
                        Text("\(row.status) · \(row.method)").font(.callout)
                        Text(details(row)).font(.caption).foregroundStyle(Color.secondaryText)
                    }
                    .padding(.vertical, 2)
                    // One stop per document; Withdraw and Sign here now are on the Actions rotor.
                    .accessibilityElement(children: .ignore)
                    .accessibilityLabel("\(row.title). \(row.status), \(row.method). \(details(row))")
                    .accessibilityActions { rowActions(row) }
                    .contextMenu { rowActions(row) }
                }
            }
        }
        .navigationTitle("Documents to sign")
        .refreshable { await load() }
        .task { await load() }
        // An upload of what was signed here with no signal finished (or was refused): the list follows.
        .onChange(of: api.pendingSync) { _ in Task { await load(flush: false) } }
        .onChange(of: api.syncError) { _ in refreshKept() }
        .sheet(item: $request, onDismiss: {
            refreshKept()
            if let doc = readyToSign {
                readyToSign = nil
                signing = SigningSession(document: doc, startedAt: doc.sentAt)
            } else if let message = sheetResult {
                announceAfterDismiss(message)
            }
            sheetResult = nil
        }) { mode in
            SignatureRequestSheet(mode: mode, clientId: clientId, client: client,
                                  kinds: data?.kinds ?? [:], openLoans: data?.openLoans ?? []) { message in
                confirmation = message; sheetResult = message
                Task { await load(); onChanged() }
            } onStarted: { doc in
                readyToSign = doc
            }
        }
        .fullScreenCover(item: $signing, onDismiss: {
            refreshKept()
            Task { await load(); onChanged() }
        }) { session in
            InPersonSigningView(clientId: clientId, document: session.document, prepared: session.prepared,
                                signBy: session.signBy) { rows, queued in
                if let rows, let current = data { data = current.replacing(rows) }
                confirmation = queued
                    ? "Signed with no signal: \(session.document.title). It is kept on this phone and sent when the connection returns."
                    : "Signed: \(session.document.title)."
            }
        }
        .confirmationDialog(removingPrepared.map { "Remove \($0.title) from this phone? It can no longer be signed here with no signal." } ?? "",
                            isPresented: Binding(get: { removingPrepared != nil }, set: { if !$0 { removingPrepared = nil } }),
                            titleVisibility: .visible) {
            Button("Remove from this phone", role: .destructive) {
                if let item = removingPrepared {
                    PreparedSignatures.remove(id: item.id, owner: api.userId)
                    refreshKept()
                    announce("\(item.title) removed from this phone.")
                }
            }
            Button("Cancel", role: .cancel) {}
        }
        .confirmationDialog(withdrawing.map { "Withdraw \($0.title)? It can no longer be signed." } ?? "",
                            isPresented: Binding(get: { withdrawing != nil }, set: { if !$0 { withdrawing = nil } }),
                            titleVisibility: .visible) {
            Button("Withdraw", role: .destructive) {
                if let row = withdrawing { Task { await withdraw(row) } }
            }
            Button("Cancel", role: .cancel) {}
        }
    }

    @ViewBuilder
    private func rowActions(_ row: SignatureRow) -> some View {
        if row.status == "Waiting to be signed here" {
            Button("Sign here now") { Task { await resume(row) } }
        }
        if row.isOpen {
            Button("Withdraw", role: .destructive) { withdrawing = row }
        }
    }

    /// Who signs, and when it was sent and signed, in words.
    private func details(_ row: SignatureRow) -> String {
        var parts = ["Signer: \(row.signerName)" + (row.signerEmail.isEmpty ? "" : ", \(row.signerEmail)")]
        if let sent = row.sentAt, !sent.isEmpty { parts.append("Sent \(formatDateTime(sent))") }
        if let signed = row.signedAt, !signed.isEmpty {
            parts.append("Signed \(formatDateTime(signed))" + ((row.signedName ?? "").isEmpty ? "" : " as \(row.signedName!)"))
            if row.hasDrawnSignature { parts.append("Drawn signature kept") }
        }
        return parts.joined(separator: ". ")
    }

    /// A document prepared to sign with no signal: who signs, when it was prepared and by when it can be signed.
    private func preparedDetails(_ item: PreparedSignature) -> String {
        "Signer: \(item.signerName). Prepared \(item.preparedAt.formatted(date: .abbreviated, time: .shortened)). "
            + "Sign by \(item.expiresAt.formatted(date: .abbreviated, time: .shortened))"
    }

    private func waitingDetails(_ item: OfflineSignatures.Waiting) -> String {
        var parts = ["Signed as \(item.typedName)"]
        if let at = item.signedAt { parts.append("on this phone \(at.formatted(date: .abbreviated, time: .shortened))") }
        return parts.joined(separator: " ") + (item.lastError == nil ? ". Sent when the connection returns." : ". Still kept on this phone.")
    }

    /// What this phone keeps for this client: prepared documents and signatures waiting to send.
    private func refreshKept() {
        prepared = PreparedSignatures.forClient(clientId, owner: api.userId)
        waitingToSend = OfflineSignatures.waiting(clientId: clientId, api: api)
    }

    /// `flush`: send signatures kept with no signal first, now that the server answers.
    private func load(flush: Bool = true) async {
        refreshKept()
        do {
            let reply: SignaturesResponse = try await api.call("signatures", query: ["client_id": String(clientId)])
            if flush, !waitingToSend.isEmpty {
                await api.flushQueue()
                refreshKept()
                data = try await api.call("signatures", query: ["client_id": String(clientId)])
            } else {
                data = reply
            }
            if let rows = data?.signatures {
                PreparedSignatures.reconcile(clientId: clientId, rows: rows, owner: api.userId)
                refreshKept()
            }
            error = nil
        } catch {
            guard !Task.isCancelled else { return }
            if case APIError.network = error, !prepared.isEmpty {
                showFormError("No connection. The documents under Ready to sign here can still be signed; nothing else can be changed until the connection returns.", in: &self.error)
            } else {
                showFormError(error.localizedDescription, in: &self.error)
            }
        }
    }

    /// A document started here earlier and not yet signed: opened again to sign.
    private func resume(_ row: SignatureRow) async {
        opening = true
        defer { opening = false }
        do {
            error = nil
            let r: SigningDocumentResponse = try await api.call("signature", query: ["client_id": String(clientId), "id": String(row.id)])
            signing = SigningSession(document: r.signature, startedAt: row.sentAt)
        } catch {
            showFormError("Could not open \(row.title). \(error.localizedDescription)", in: &self.error)
        }
    }

    private func withdraw(_ row: SignatureRow) async {
        do {
            error = nil
            let r: SignatureListReply = try await api.call("withdraw_signature", body: ["client_id": clientId, "id": row.id])
            if let rows = r.signatures, let current = data { data = current.replacing(rows) } else { await load() }
            confirmation = "\(row.title) withdrawn."
            announce("\(row.title) withdrawn.")
            onChanged()
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

extension SignaturesResponse {
    /// The same reply with the list as the server now has it.
    func replacing(_ rows: [SignatureRow]) -> SignaturesResponse {
        var copy = self
        copy.signatures = rows
        return copy
    }
}

/// What to send: the kind of document, who signs, and (for another document) its words. By
/// email it sends a private signing link; in person it starts the document to sign on this phone.
struct SignatureRequestSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let mode: SignatureMode
    let clientId: Int
    let client: ClientInfo
    let kinds: [String: String]
    let openLoans: [SignatureLoan]
    /// Emailed, or prepared to sign later: what to confirm.
    let onDone: (String) -> Void
    let onStarted: (SigningDocument) -> Void

    @State private var kind = "services"
    @State private var loanId = 0
    @State private var title = ""
    @State private var text = ""
    @State private var signerRole = "Client"
    @State private var signerName = ""
    @State private var signerEmail = ""
    @State private var busy = false
    @State private var error: String?
    @State private var started = false
    @State private var askingDiscard = false
    /// Sent as idem_key, so a retry after a lost reply is not started or emailed twice (4.27.0).
    @State private var idem = IdemKey()

    private var kindChoices: [(key: String, label: String)] {
        var list: [(String, String)] = [("services", kinds["services"] ?? "Consent to services")]
        if !openLoans.isEmpty { list.append(("loan", kinds["loan"] ?? "Equipment loan agreement")) }
        list.append(("document", kinds["document"] ?? "Another document"))
        return list
    }
    private var hasGuardian: Bool { !(client.guardianName ?? "").isEmpty }
    private var hasChanges: Bool {
        kind != "services" || !title.isEmpty || !text.isEmpty || signerRole != "Client"
            || signerName != client.name || signerEmail != (client.email ?? "")
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section {
                    Text(intro).font(.callout)
                }
                Section("Document") {
                    Picker("Kind of document", selection: $kind) {
                        ForEach(kindChoices, id: \.key) { Text($0.label).tag($0.key) }
                    }
                    if kind == "loan" {
                        Picker("Equipment on loan", selection: $loanId) {
                            Text("Choose an item").tag(0)
                            ForEach(openLoans) { loan in
                                Text(loan.name + ((loan.serial ?? "").isEmpty ? "" : ", serial \(loan.serial!)")).tag(loan.id)
                            }
                        }
                    }
                    if kind == "document" {
                        CaptionedField(label: "Title (required)", text: $title)
                        CaptionedField(label: "Text to sign (required). Leave a blank line between paragraphs.", text: $text, lines: 4...12)
                    }
                }
                Section("Who signs") {
                    Picker("Who signs", selection: $signerRole) {
                        Text("The client").tag("Client")
                        Text("Parent or guardian").tag("Guardian")
                    }
                    CaptionedField(label: "Signer's name (required)", text: $signerName)
                        .autocorrectionDisabled()
                    CaptionedField(label: mode == .email ? "Signer's email (required)" : "Signer's email (optional)",
                                   text: $signerEmail, keyboard: .emailAddress)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                }
            }
            .navigationTitle(screenTitle)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }.disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? (mode == .email ? "Sending…" : "Preparing…") : (mode == .email ? "Send" : mode == .prepare ? "Prepare" : "Start")) {
                        Task { await submit() }
                    }
                    .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this document?", asking: $askingDiscard) { dismiss() }
            .onAppear(perform: start)
            .onChange(of: signerRole) { role in
                // The name and email follow who signs.
                signerName = role == "Guardian" ? (client.guardianName ?? "") : client.name
                signerEmail = role == "Guardian" ? (client.guardianEmail ?? "") : (client.email ?? "")
            }
        }
    }

    private var intro: String {
        switch mode {
        case .email: return "A private signing link is emailed to the signer."
        case .inPerson: return "When the document is ready, hand the phone to the signer. They read it and type their name to sign."
        case .prepare: return "The document is prepared now and kept on this phone, so it can be signed here within two days, even with no signal. When you are with the signer, open it under Ready to sign here and hand them the phone."
        }
    }
    private var screenTitle: String {
        switch mode {
        case .email: return "Send by email"
        case .inPerson: return "Sign here now"
        case .prepare: return "Prepare to sign"
        }
    }

    private func start() {
        guard !started else { return }
        started = true
        signerName = client.name
        signerEmail = client.email ?? ""
        if openLoans.count == 1 { loanId = openLoans[0].id }
    }

    private func submit() async {
        let name = signerName.trimmingCharacters(in: .whitespacesAndNewlines)
        let email = signerEmail.trimmingCharacters(in: .whitespacesAndNewlines)
        if kind == "loan" && loanId == 0 { showFormError("Equipment on loan: choose the item the agreement is for.", in: &error); return }
        if kind == "document" && title.trimmingCharacters(in: .whitespaces).isEmpty { showFormError("Title: enter the document's title.", in: &error); return }
        if kind == "document" && text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { showFormError("Text to sign: enter the words the signer agrees to.", in: &error); return }
        if name.isEmpty { showFormError("Signer's name: enter the name of the person who signs.", in: &error); return }
        if mode == .email && !email.contains("@") {
            showFormError("Signer's email: enter an email address in the form name@example.com.", in: &error); return
        }
        var body: [String: Any] = ["client_id": clientId, "kind": kind, "signer_role": signerRole,
                                   "signer_name": name, "signer_email": email]
        if kind == "loan" { body["loan_id"] = loanId }
        if kind == "document" { body["title"] = title; body["body"] = text }
        // Nothing is kept on a phone without a passcode, so there is nothing to sign later on it.
        if mode == .prepare && !API.phoneCanKeepClientData {
            showFormError("This iPhone has no passcode, so the app keeps nothing on it to sign later. Use Sign here now when you are with the signer and have a signal, or set a passcode in Settings.", in: &error)
            return
        }
        body["idem_key"] = idem.key(for: body.merging(["mode": "\(mode)"]) { a, _ in a })
        busy = true; error = nil
        do {
            if mode == .email {
                let _: TaskSaveResult = try await api.call("send_signature", body: body)
                idem.answered()   // a lost reply throws first, so a retry keeps the key
                onDone("Signing link emailed to \(email).")
            } else if mode == .prepare {
                let doc: SigningDocument = try await api.call("start_signature", body: body)
                idem.answered()
                let item = PreparedSignature(document: doc, clientId: clientId, clientName: client.name)
                guard PreparedSignatures.add(item, owner: api.userId) else {
                    // Started on the server but not kept here: it is listed as waiting to be signed here.
                    busy = false
                    showFormError("\(doc.title) is started, but it could not be kept on this phone to sign with no signal. Sign it with a signal from the list (Sign here now on its actions), or withdraw it.", in: &error)
                    return
                }
                onDone("Ready to sign here, even with no signal: \(doc.title). Sign it by \(item.expiresAt.formatted(date: .abbreviated, time: .shortened)).")
            } else {
                let doc: SigningDocument = try await api.call("start_signature", body: body)
                idem.answered()
                onStarted(doc)
            }
            dismiss()
        } catch {
            busy = false
            if case APIError.network = error {} else { idem.answered() }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// The document as the signer reads and signs it, full screen, with the phone in their hands.
/// Typing the name signs; drawing a signature is optional, so a blind signer can sign alone.
/// However it closes after the phone was handed over, the app locks (as SignatureSheet).
struct InPersonSigningView: View {
    @EnvironmentObject var api: API
    @EnvironmentObject private var lock: AppLock
    @Environment(\.dismiss) private var dismiss
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.dynamicTypeSize) private var typeSize
    let clientId: Int
    let document: SigningDocument
    /// Prepared earlier and kept on this phone (4.26.0): stopping leaves it ready to sign later.
    var prepared = false
    /// The last moment the server takes the signature (nil: not known; the server decides).
    var signBy: Date? = nil
    /// The list as the server now has it (nil when the reply did not include it), and whether
    /// the signature was kept on this phone to send when the connection returns.
    let onSigned: ([SignatureRow]?, Bool) -> Void

    @State private var typedName = ""
    @State private var strokes: [[CGPoint]] = []
    @State private var padSize = CGSize(width: 600, height: 220)
    @State private var drawing = false
    @State private var busy = false
    @State private var error: String?
    @State private var askingCancel = false
    /// Signed with no signal (4.26.0): kept on this phone, and the signer is told so before handing the phone back.
    @State private var keptToSend = false
    /// Sign was pressed and the server's answer did not confirm it (4.27.0): before withdrawing,
    /// the document's status is checked, so a document that was signed is never withdrawn.
    @State private var signOutcomeUncertain = false
    @AccessibilityFocusState private var errorFocused: Bool
    @AccessibilityFocusState private var keptFocused: Bool

    private var spanish: Bool { document.lang == "es" }
    /// The words around the document, in the document's language (as the website's signing page).
    private func t(_ en: String, _ es: String) -> String { spanish ? es : en }

    private var inkLength: CGFloat {
        strokes.reduce(0) { total, s in
            total + zip(s, s.dropFirst()).reduce(0) { $0 + hypot($1.1.x - $1.0.x, $1.1.y - $1.0.y) }
        }
    }
    private var hasDrawing: Bool { inkLength >= 60 }
    private var padHeight: CGFloat {
        if verticalSizeClass == .compact { return 130 }
        return typeSize.isAccessibilitySize ? 160 : 220
    }

    /// The body: paragraphs split by blank lines; several lines in a paragraph are a list.
    private var blocks: [[String]] {
        document.body.replacingOccurrences(of: "\r", with: "")
            .components(separatedBy: "\n\n")
            .map { $0.components(separatedBy: "\n").map { $0.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty } }
            .filter { !$0.isEmpty }
    }

    /// Text marked with the document's language, so VoiceOver reads it with the right voice.
    private func marked(_ s: String) -> Text {
        var a = AttributedString(s)
        if let lang = document.lang, !lang.isEmpty { a.languageIdentifier = lang }
        return Text(a)
    }

    var body: some View {
        NavigationStack {
            if keptToSend { keptView } else { signingView }
        }
    }

    /// After signing with no signal: said and shown, then the phone goes back (and the app locks).
    private var keptView: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                Text(t("Signed", "Firmado")).font(.title2.weight(.bold)).accessibilityAddTraits(.isHeader)
                Text(keptMessage)
                    .fixedSize(horizontal: false, vertical: true)
                    .accessibilityFocused($keptFocused)
                Button {
                    close()
                } label: {
                    Text(t("Done, give the phone back", "Listo, devuelva el teléfono"))
                        .frame(maxWidth: .infinity, minHeight: 44)
                }
                .buttonStyle(.borderedProminent)
                .onBrandLabel()
            }
            .padding()
        }
        .navigationTitle(t("Document to sign", "Documento para firmar"))
        .navigationBarTitleDisplayMode(.inline)
        .interactiveDismissDisabled(true)
        .onAppear {
            Task { @MainActor in
                try? await Task.sleep(nanoseconds: 400_000_000)
                keptFocused = true
            }
        }
    }

    private var keptMessage: String {
        t("\(document.title) is signed. This phone has no connection right now, so the signature is kept on it and sent automatically when the connection returns. Please give the phone back.",
          "\(document.title) está firmado. Este teléfono no tiene conexión ahora, así que la firma se guarda en él y se envía automáticamente cuando vuelva la conexión. Por favor, devuelva el teléfono.")
    }

    private var signingView: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                if let error {
                    ErrorBanner(message: error).accessibilityFocused($errorFocused)
                }
                marked(document.title).font(.title2.weight(.bold))
                    .accessibilityAddTraits(.isHeader)
                Text(t("In this document, “we” means \(document.org) and “you” means the person who signs.",
                       "En este documento, “nosotros” significa \(document.org) y “usted” significa la persona que firma."))
                Text(t("The name on this document is \(document.signerName).", "El nombre en este documento es \(document.signerName)."))
                Text(t("When you sign, you agree to the following:", "Al firmar, usted acepta lo siguiente:"))
                ForEach(Array(blocks.enumerated()), id: \.offset) { _, lines in
                    if lines.count == 1 {
                        marked(lines[0])
                    } else {
                        VStack(alignment: .leading, spacing: 6) {
                            ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                                HStack(alignment: .firstTextBaseline, spacing: 8) {
                                    Text("•").accessibilityHidden(true)
                                    marked(line)
                                }
                            }
                        }
                    }
                }
                .environment(\.locale, Locale(identifier: spanish ? "es" : "en"))
                .fixedSize(horizontal: false, vertical: true)

                Text(t("Sign", "Firmar")).font(.headline).accessibilityAddTraits(.isHeader).padding(.top, 8)
                VStack(alignment: .leading, spacing: 4) {
                    Text(t("Type your full name to sign", "Escriba su nombre completo para firmar"))
                        .font(.callout.weight(.semibold)).accessibilityHidden(true)
                    TextField(t("Type your full name to sign", "Escriba su nombre completo para firmar"), text: $typedName)
                        .textFieldStyle(.roundedBorder)
                        // No AutoFill: it would offer the phone owner's name.
                        .autocorrectionDisabled()
                        .submitLabel(.done)
                        .accessibilityHint(t("Capital letters, accents and punctuation do not need to match.",
                                             "No importa si las mayúsculas, los acentos o la puntuación son diferentes."))
                    Text(t("Capital letters, accents and punctuation do not need to match.",
                           "No importa si las mayúsculas, los acentos o la puntuación son diferentes."))
                        .font(.caption).foregroundStyle(Color.secondaryText).accessibilityHidden(true)
                }
                Text(t("Drawn signature (optional). Typing your name is enough.", "Firma dibujada (opcional). Escribir su nombre es suficiente."))
                    .font(.callout)
                SignaturePad(strokes: $strokes, padSize: $padSize,
                             status: strokes.isEmpty ? t("Empty", "Vacío") : t("Signature drawn", "Firma dibujada"),
                             onDrawing: { drawing = $0 })
                    .frame(maxWidth: .infinity)
                    .frame(height: padHeight)
                Button(t("Clear drawing", "Borrar firma dibujada")) {
                    strokes = []
                    announce(t("Drawing cleared.", "Firma dibujada borrada."))
                }
                .buttonStyle(.bordered)
                .disabled(strokes.isEmpty || busy)
                Button {
                    Task { await sign() }
                } label: {
                    Text(busy ? t("Signing…", "Firmando…") : t("Sign and submit", "Firmar y enviar"))
                        .frame(maxWidth: .infinity, minHeight: 44)
                }
                .buttonStyle(.borderedProminent)
                .onBrandLabel()
                .disabled(busy)
            }
            .padding()
        }
        .scrollDisabled(drawing)
        .scrollDismissesKeyboard(.interactively)
        .navigationTitle(t("Document to sign", "Documento para firmar"))
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .cancellationAction) {
                Button(t("Cancel", "Cancelar")) { askingCancel = true }.disabled(busy)
            }
        }
        .interactiveDismissDisabled(true)
        .confirmationDialog(prepared
                            ? t("Stop without signing? The document stays ready to sign later.",
                                "¿Salir sin firmar? El documento queda listo para firmar más tarde.")
                            : t("Stop without signing? The document is withdrawn and can't be signed.",
                                "¿Salir sin firmar? El documento se retira y ya no se puede firmar."),
                            isPresented: $askingCancel, titleVisibility: .visible) {
            Button(t("Stop without signing", "Salir sin firmar"), role: .destructive) { stopWithoutSigning() }
            Button(t("Keep signing", "Seguir firmando"), role: .cancel) {}
        }
    }

    /// The phone is in the signer's hands from the moment this opens, so however it closes the
    /// app locks: the rest of the app opens again only for the device owner.
    private func close() {
        lock.lockNow()
        dismiss()
    }

    /// Not signed: the request is withdrawn, so it is not left waiting to be signed here. After a
    /// Sign that failed or went unanswered, the server is asked first whether it was signed
    /// (4.27.0, as Android): a signed one is reported as signed, never withdrawn. The server also
    /// refuses to withdraw a signed document (409, "already signed"), reported the same way.
    private func stopWithoutSigning() {
        // One prepared to sign with no signal stays on this phone (and on the server) for later.
        if prepared { close(); return }
        let api = api, clientId = clientId, id = document.id, check = signOutcomeUncertain, onSigned = onSigned
        Task {
            if check {
                guard let status: SignatureStatusReply = try? await api.call("signature", query: ["client_id": String(clientId), "id": String(id)]) else {
                    return   // not known whether it was signed: left as it is, never withdrawn blind
                }
                if status.isSigned { onSigned(nil, false); return }
            }
            do {
                let _: SignatureListReply = try await api.call("withdraw_signature", body: ["client_id": clientId, "id": id])
            } catch APIError.server(let message) where message.lowercased().contains("already signed") {
                onSigned(nil, false)
            } catch {}
        }
        close()
    }

    private func sign() async {
        let name = typedName.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !name.isEmpty else {
            fail(t("Type your full name to sign.", "Escriba su nombre completo para firmar."))
            return
        }
        // Past the two days from the server's start: not signed, and nothing is kept.
        guard !windowPassed() else { return }
        busy = true; error = nil
        // When it was signed: sent with it if it has to wait for a connection.
        let signedAt = Date()
        var body: [String: Any] = ["client_id": clientId, "id": document.id, "typed_name": name]
        if hasDrawing, let png = renderPNGBase64() { body["signature_b64"] = png }
        do {
            let r: SignatureListReply = try await api.call("sign_in_person", body: body)
            PreparedSignatures.remove(id: document.id, owner: api.userId)
            announce(t("Signed.", "Firmado."))
            onSigned(r.signatures, false)
            close()
        } catch APIError.network {
            signOutcomeUncertain = true   // the reply may have been lost after it was signed
            keepToSendLater(body, name: name, signedAt: signedAt)
        } catch {
            // The typed name and any drawing stay for another try.
            signOutcomeUncertain = true
            busy = false
            fail(error.localizedDescription)
        }
    }

    /// No connection (4.26.0): the signature is kept on this phone, protected like the other
    /// offline entries, and sent as sign_in_person with the time it was signed when the
    /// connection returns (API.flushQueue). The kept copy goes only once the server accepts it.
    private func keepToSendLater(_ sent: [String: Any], name: String, signedAt: Date) {
        // The window may have closed while the server was being tried.
        guard !windowPassed(at: signedAt) else { busy = false; return }
        // The server's own check, done here, so the signer is not told it is signed when it would be refused.
        guard OfflineSignatures.nameMatches(document.signerName, name) else {
            busy = false
            fail(t("The name typed does not match the name on the document: \(document.signerName).",
                   "El nombre escrito no coincide con el nombre en el documento: \(document.signerName)."))
            return
        }
        var body = sent
        body["signed_at"] = OfflineSignatures.timestamp(signedAt)
        do {
            try api.enqueue("sign_in_person", body: body, label: "Signed in person: \(document.title)")
        } catch {
            busy = false
            fail(t("Not signed: this phone has no connection and can't keep the signature to send later. Please give the phone back.",
                   "No se firmó: este teléfono no tiene conexión y no puede guardar la firma para enviarla después. Por favor, devuelva el teléfono."))
            return
        }
        PreparedSignatures.remove(id: document.id, owner: api.userId)
        busy = false
        onSigned(nil, true)
        keptToSend = true
        announce(keptMessage)
    }

    /// True (and the signer told so) when the time to sign it has passed.
    private func windowPassed(at moment: Date = Date()) -> Bool {
        guard let signBy, moment > signBy else { return false }
        fail(t("This document can't be signed now: it had to be signed within two days of being started. Nothing was signed. Please give the phone back.",
               "Este documento ya no se puede firmar: había que firmarlo dentro de los dos días después de iniciarlo. No se firmó nada. Por favor, devuelva el teléfono."))
        return true
    }

    private func fail(_ message: String) {
        showFormError(message, in: &error)
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 400_000_000)
            errorFocused = true
        }
    }

    /// As SignatureSheet: one scale for both axes, the whole of what was drawn kept.
    private func renderPNGBase64() -> String? {
        let size = CGSize(width: 600, height: 220)
        let ink = strokes.joined()
        let area = CGRect(x: 0, y: 0,
                          width: max(padSize.width, ink.map(\.x).max() ?? 0),
                          height: max(padSize.height, ink.map(\.y).max() ?? 0))
        let scale = min(size.width / max(area.width, 1), size.height / max(area.height, 1))
        let image = UIGraphicsImageRenderer(size: size).image { ctx in
            UIColor.white.setFill()
            ctx.fill(CGRect(origin: .zero, size: size))
            UIColor.black.setStroke()
            for stroke in strokes {
                guard let first = stroke.first else { continue }
                let path = UIBezierPath()
                path.lineWidth = 3
                path.move(to: CGPoint(x: first.x * scale, y: first.y * scale))
                for p in stroke.dropFirst() { path.addLine(to: CGPoint(x: p.x * scale, y: p.y * scale)) }
                path.stroke()
            }
        }
        return image.pngData()?.base64EncodedString()
    }
}

// MARK: - Release of information request (4.25.0)

/// Emails a release of information to sign, as the website's client page does: who signs, who
/// may receive what, and why.
struct ReleaseRequestSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    let client: ClientInfo
    let onSent: (String) -> Void

    @State private var choices: ReleaseChoices?
    @State private var signerRole = "Client"
    @State private var signerName = ""
    @State private var signerEmail = ""
    @State private var recipientType = ""
    @State private var recipientName = ""
    @State private var recipientEmail = ""
    @State private var info: Set<String> = []
    @State private var infoOther = ""
    @State private var purpose = ""
    @State private var busy = false
    @State private var error: String?
    @State private var started = false
    @State private var confirming = false
    @State private var askingDiscard = false
    /// Sent as idem_key, so a retry after a lost reply is not started or emailed twice (4.27.0).
    @State private var idem = IdemKey()

    private var hasChanges: Bool {
        !recipientType.isEmpty || !recipientName.isEmpty || !recipientEmail.isEmpty || !info.isEmpty
            || !infoOther.isEmpty || !purpose.isEmpty || signerRole != "Client"
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if let choices {
                    if let days = choices.validityDays, days > 0 {
                        Section {
                            Text("The signer gets a private link by email. Once signed, the release is in force for \(days) days.").font(.callout)
                        }
                    }
                    Section("Who signs") {
                        Picker("Who signs", selection: $signerRole) {
                            Text("The client").tag("Client")
                            Text("Parent or guardian").tag("Guardian")
                        }
                        CaptionedField(label: "Signer's name (required)", text: $signerName).autocorrectionDisabled()
                        CaptionedField(label: "Signer's email (required)", text: $signerEmail, keyboard: .emailAddress)
                            .textInputAutocapitalization(.never).autocorrectionDisabled()
                    }
                    Section("Who may receive the information") {
                        Picker("Recipient type (required)", selection: $recipientType) {
                            Text("Choose a type").tag("")
                            ForEach(choices.recipientTypes, id: \.self) { Text($0).tag($0) }
                        }
                        CaptionedField(label: "Recipient's name or organisation (required)", text: $recipientName)
                        CaptionedField(label: "Recipient's email (optional)", text: $recipientEmail, keyboard: .emailAddress)
                            .textInputAutocapitalization(.never).autocorrectionDisabled()
                    }
                    Section("Information to share (choose at least one)") {
                        ForEach(choices.infoItems, id: \.self) { item in
                            Toggle(item, isOn: Binding(get: { info.contains(item) },
                                                       set: { if $0 { info.insert(item) } else { info.remove(item) } }))
                        }
                        CaptionedField(label: "Other information (optional)", text: $infoOther)
                    }
                    Section("Purpose") {
                        CaptionedField(label: "Why the information is shared (required)", text: $purpose, lines: 2...6)
                    }
                } else if error == nil {
                    ProgressView("Loading the release form…")
                }
            }
            .navigationTitle("Request a release")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }.disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(busy ? "Sending…" : "Send") { check() }.disabled(busy || choices == nil)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this release request?", asking: $askingDiscard) { dismiss() }
            .confirmationDialog("Email the release to \(signerEmail.trimmingCharacters(in: .whitespaces)) to sign?",
                                isPresented: $confirming, titleVisibility: .visible) {
                Button("Email release") { Task { await send() } }
                Button("Cancel", role: .cancel) {}
            }
            .onChange(of: signerRole) { role in
                signerName = role == "Guardian" ? (client.guardianName ?? "") : client.name
                signerEmail = role == "Guardian" ? (client.guardianEmail ?? "") : (client.email ?? "")
            }
            .task { await load() }
        }
    }

    private func load() async {
        if !started {
            started = true
            signerName = client.name
            signerEmail = client.email ?? ""
        }
        do {
            error = nil
            choices = try await api.call("release_choices")
        } catch {
            showFormError("Could not load the release form. \(error.localizedDescription)", in: &self.error)
        }
    }

    private func check() {
        if signerName.trimmingCharacters(in: .whitespaces).isEmpty { showFormError("Signer's name: enter the name of the person who signs.", in: &error); return }
        if !signerEmail.contains("@") { showFormError("Signer's email: enter an email address in the form name@example.com.", in: &error); return }
        if recipientType.isEmpty { showFormError("Recipient type: choose who receives the information.", in: &error); return }
        if recipientName.trimmingCharacters(in: .whitespaces).isEmpty { showFormError("Recipient's name: enter who receives the information.", in: &error); return }
        if info.isEmpty && infoOther.trimmingCharacters(in: .whitespaces).isEmpty {
            showFormError("Information to share: choose at least one, or describe it under Other information.", in: &error); return
        }
        if purpose.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { showFormError("Purpose: say why the information is shared.", in: &error); return }
        confirming = true
    }

    private func send() async {
        busy = true; error = nil
        // In the order the form lists them.
        let items = (choices?.infoItems ?? []).filter { info.contains($0) }
        var body: [String: Any] = [
            "client_id": clientId, "signer_role": signerRole,
            "signer_name": signerName.trimmingCharacters(in: .whitespaces), "signer_email": signerEmail.trimmingCharacters(in: .whitespaces),
            "recipient_type": recipientType, "recipient_name": recipientName.trimmingCharacters(in: .whitespaces),
            "info": items, "purpose": purpose,
        ]
        if !recipientEmail.trimmingCharacters(in: .whitespaces).isEmpty { body["recipient_email"] = recipientEmail.trimmingCharacters(in: .whitespaces) }
        if !infoOther.trimmingCharacters(in: .whitespaces).isEmpty { body["info_other"] = infoOther }
        body["idem_key"] = idem.key(for: body)
        do {
            let result: ReleaseResult = try await api.call("request_release", body: body)
            idem.answered()
            // Saved, but whether the email went is not known (4.27.0): the server says what to do.
            if result.unknown == true, let message = result.message, !message.isEmpty {
                onSent(message)
            } else {
                onSent("Release emailed to \(signerEmail.trimmingCharacters(in: .whitespaces)) to sign.")
            }
            dismiss()
        } catch {
            busy = false
            if case APIError.network = error {} else { idem.answered() }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// The idem_key for a request that creates or emails something (4.27.0): the same key while
/// what is sent stays the same and no answer has come, so a retry after a lost reply is
/// recognised; a new one once the server answered or anything in the request changed.
struct IdemKey {
    private var value = UUID().uuidString
    private var sentFor: Data?

    mutating func key(for body: [String: Any]) -> String {
        var fields = body
        fields.removeValue(forKey: "idem_key")
        let current = try? JSONSerialization.data(withJSONObject: fields, options: [.sortedKeys])
        if let sentFor, sentFor != current { value = UUID().uuidString }
        sentFor = current
        return value
    }

    mutating func answered() {
        value = UUID().uuidString
        sentFor = nil
    }
}

/// The `signature` reply, read only for whether the document is signed (4.27.0).
struct SignatureStatusReply: Decodable {
    struct Status: Decodable { let signedAt: String? }
    let signature: Status
    var isSigned: Bool { !(signature.signedAt ?? "").isEmpty }
}

/// request_release's reply: unknown when the request was saved but the email's outcome is not
/// known, with the server's message saying so (4.27.0).
struct ReleaseResult: Decodable { let ok: Bool; let id: Int?; let unknown: Bool?; let message: String? }
