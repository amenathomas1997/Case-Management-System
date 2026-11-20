import SwiftUI

// MARK: - 4.38.0 Incoming authorizations (plan-4380 T6): the website's authorizations.php

/// A waiting authorization email: the server's row text (the website's waiting row).
struct IncomingAuthRow: Decodable, Identifiable {
    let id: Int
    let authNumber: String
    let text: String
    let matched: Bool
    private enum CodingKeys: String, CodingKey { case id, authNumber, text, matched }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        authNumber = (try? c.decode(String.self, forKey: .authNumber)) ?? ""
        text = (try? c.decode(String.self, forKey: .text)) ?? ""
        matched = (try? c.decode(Bool.self, forKey: .matched)) ?? false
    }
}

struct IncomingAuthList: Decodable {
    let rows: [IncomingAuthRow]
    let total: Int
    private enum CodingKeys: String, CodingKey { case rows, total }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        rows = LenientList.decode(IncomingAuthRow.self, from: c, forKey: .rows)
        total = (try? c.decode(Int.self, forKey: .total)) ?? rows.count
    }
}

struct AuthDetail: Decodable { let label: String; let value: String }
struct AuthMatch: Decodable { let id: Int; let text: String }
struct AuthOtherClient: Decodable { let id: Int; let name: String }
struct AuthCounselor: Decodable { let text: String; let confirmed: Bool; let preticked: Bool }

/// incoming_authorization's reply: the website's review of one email.
struct IncomingAuthReview: Decodable {
    let id: Int
    let authNumber: String
    let status: String
    let trackId: Int?
    let statusText: String?
    let notApproved: Bool
    let already: [String]
    let details: [AuthDetail]
    let matches: [AuthMatch]
    /// The client chosen to start with (0: "Another client, chosen below").
    let presetClientId: Int
    let otherClients: [AuthOtherClient]
    let counselor: AuthCounselor?
    let emailText: String
    private enum CodingKeys: String, CodingKey {
        case id, authNumber, status, trackId, statusText, notApproved, already, details, matches, presetClientId, otherClients, counselor, emailText
    }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        authNumber = (try? c.decode(String.self, forKey: .authNumber)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        trackId = try? c.decodeIfPresent(Int.self, forKey: .trackId)
        statusText = try? c.decodeIfPresent(String.self, forKey: .statusText)
        notApproved = (try? c.decode(Bool.self, forKey: .notApproved)) ?? false
        already = (try? c.decode([String].self, forKey: .already)) ?? []
        details = LenientList.decode(AuthDetail.self, from: c, forKey: .details)
        matches = LenientList.decode(AuthMatch.self, from: c, forKey: .matches)
        presetClientId = (try? c.decode(Int.self, forKey: .presetClientId)) ?? 0
        otherClients = LenientList.decode(AuthOtherClient.self, from: c, forKey: .otherClients)
        counselor = try? c.decodeIfPresent(AuthCounselor.self, forKey: .counselor)
        emailText = (try? c.decode(String.self, forKey: .emailText)) ?? ""
    }
}

/// read_authorization_email's reply: the new (or already stored) email, or the sentence saying
/// it was read but its client is not on this caseload.
struct ReadAuthReply: Decodable { let id: Int?; let message: String? }

/// start_authorization_track's reply: the client, the counselor notices, the track form's
/// prefill (all strings) and the website's heading for the form.
struct StartAuthReply: Decodable {
    let clientId: Int
    let notices: [String]
    let prefill: [String: String]
    let heading: String?
    private enum CodingKeys: String, CodingKey { case clientId, notices, prefill, heading }
    /// A prefill value sent as a string (as the contract says) or, from any server, a number.
    private struct PrefillText: Decodable {
        let value: String
        init(from d: Decoder) throws {
            let c = try d.singleValueContainer()
            if let s = try? c.decode(String.self) { value = s }
            else if let i = try? c.decode(Int.self) { value = String(i) }
            else if let n = try? c.decode(Double.self) { value = String(n) }
            else { value = "" }
        }
    }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        notices = (try? c.decode([String].self, forKey: .notices)) ?? []
        prefill = ((try? c.decode([String: PrefillText].self, forKey: .prefill)) ?? [:]).mapValues(\.value)
        heading = try? c.decodeIfPresent(String.self, forKey: .heading)
    }
}

/// The client picker's choices: the matches, then "Another client, chosen below" (tag 0) last.
func authClientChoices(_ matches: [AuthMatch]) -> [(tag: Int, label: String)] {
    matches.map { (tag: $0.id, label: $0.text) } + [(tag: 0, label: "Another client, chosen below")]
}

/// start_authorization_track's body: the chosen match, or the other client when "Another client"
/// is chosen (0 when none is: the server then says to choose one).
func startAuthBody(id: Int, choice: Int, other: Int, setCounselor: Bool) -> [String: Any] {
    ["id": id, "client_id": choice != 0 ? choice : other, "set_counselor": setCounselor]
}

/// Said when no client record matches the email's name: the name only when the email gives one
/// (never "named not given"), as Android.
func authNoMatchText(_ name: String) -> String {
    let n = name.trimmingCharacters(in: .whitespacesAndNewlines)
    let named = n.isEmpty || n == "not given" ? "" : "No client record is named \(n). "
    return named + "Add the client first, or choose another client below."
}

/// The counselor toggle's label, as the website's checkbox.
func authCounselorLabel(_ c: AuthCounselor) -> String {
    "Record \(c.text) as the client's VR counselor" + (c.confirmed ? "" : " (unconfirmed until checked on the client's edit page)")
}

/// Incoming authorizations: the emails waiting, and one pasted in.
struct AuthorizationsView: View {
    @EnvironmentObject var api: API
    @State private var list: IncomingAuthList?
    @State private var error: String?
    @State private var notice: String?
    @State private var emailText = ""
    @State private var busy = false
    @State private var idem = IdemKey()
    @State private var opened: OpenedEmail?

    struct OpenedEmail: Identifiable, Hashable { let id: Int }

    var body: some View {
        ErrorFocusForm(inNavigationStack: true, error: error) {
            Section {
                Text("Paste an approved authorization email, or forward it to the CMS mailbox, and the track form is filled in from it for you to check.")
            }
            if let notice { Section { Text(notice).font(.callout.weight(.semibold)) } }
            Section {
                if let list {
                    if list.rows.isEmpty { Text("No authorization emails are waiting.").foregroundStyle(Color.secondaryText) }
                    ForEach(list.rows) { row in
                        NavigationLink { AuthorizationReviewView(emailId: row.id, onDismissed: dismissedHere) } label: { Text(row.text) }
                    }
                } else if error == nil {
                    ProgressView("Loading authorization emails…")
                }
            } header: {
                Text("Waiting (\(list?.total ?? 0))").font(.headline).foregroundStyle(.primary).textCase(nil).accessibilityAddTraits(.isHeader)
            }
            Section {
                CaptionedField(label: "The authorization email, including the line Authorization Number (required)", text: $emailText,
                               lines: 4...14, errorName: "The authorization email")
                Button(busy ? "Reading…" : "Read the email") { Task { await read() } }
                    .disabled(busy).frame(minHeight: 44)
            } header: {
                Text("Paste an authorization email").font(.headline).foregroundStyle(.primary).textCase(nil).accessibilityAddTraits(.isHeader)
            }
        }
        .navigationTitle("Incoming authorizations")
        .navigationDestination(isPresented: Binding(get: { opened != nil }, set: { if !$0 { opened = nil } })) {
            if let e = opened { AuthorizationReviewView(emailId: e.id, onDismissed: dismissedHere) }
        }
        .refreshable { await load() }
        .task { await load() }
    }

    /// After Dismiss on a review opened from here: back on the list, with the server's sentence
    /// shown and said there (as Android).
    private func dismissedHere(_ message: String) {
        error = nil
        notice = message
        announceAfterDismiss(message)
        Task { await load() }
    }

    private func load() async {
        do {
            list = try await api.call("incoming_authorizations")
            error = nil
        } catch {
            notice = nil
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func read() async {
        busy = true; error = nil; notice = nil
        announce("Reading…")
        var body: [String: Any] = ["email_text": emailText]
        body["idem_key"] = idem.key(for: body)
        var fields: [String: String] = [:]
        do {
            let r: ReadAuthReply = try await api.call("read_authorization_email", method: "POST", body: body,
                                                       refusal: { fields = refusalFields($0) })
            idem.answered()
            busy = false
            emailText = ""
            await load()
            if let id = r.id {
                opened = OpenedEmail(id: id)
            } else if let message = r.message, !message.isEmpty {
                notice = message
                announce(message)
            }
        } catch {
            idem.failed(error)
            busy = false
            showFormError(fields.isEmpty ? IdemKey.message(for: error, what: "the email") : error.localizedDescription,
                          in: &self.error, field: fields["email_text"] != nil ? "The authorization email" : nil)
        }
    }
}

/// One authorization email: what it says, which client it is for, and Start the track or Dismiss.
struct AuthorizationReviewView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismissView
    let emailId: Int
    /// Opened from the list: after Dismiss the list is shown again with the server's sentence.
    /// Nil (opened from an alert): the sentence is shown here.
    var onDismissed: ((String) -> Void)? = nil
    @State private var review: IncomingAuthReview?
    @State private var error: String?
    @State private var choice = 0
    @State private var other = 0
    @State private var setCounselor = false
    @State private var busy = false
    @State private var askingDismiss = false
    @State private var startIdem = IdemKey()
    @State private var dismissIdem = IdemKey()
    @State private var started: StartedTrack?
    @State private var savedMessage: String?
    @State private var dismissedMessage: String?

    struct StartedTrack: Identifiable { let id = UUID(); let clientId: Int; let prefill: [String: String]; let heading: String?; let notices: [String] }

    var body: some View {
        ErrorFocusForm(inNavigationStack: true, error: error) {
            if let dismissedMessage { Section { Text(dismissedMessage).font(.callout.weight(.semibold)) } }
            if let r = review {
                Section {
                    Text("Authorization \(r.authNumber)").font(.headline).accessibilityAddTraits(.isHeader)
                    if let s = r.statusText, !s.isEmpty { Text(s) }
                    if r.notApproved {
                        Text("This email does not say the authorization was approved. Check it with the counselor before starting a track.")
                            .font(.callout.weight(.semibold))
                    }
                    ForEach(Array(r.already.enumerated()), id: \.offset) { _, line in Text(line).font(.callout) }
                }
                Section {
                    ForEach(Array(r.details.enumerated()), id: \.offset) { _, d in
                        LabeledContent(d.label, value: d.value)
                    }
                }
                if r.status == "New" {
                    Section {
                        Picker("Which client is this for? (required)", selection: $choice) {
                            ForEach(authClientChoices(r.matches), id: \.tag) { c in Text(c.label).tag(c.tag) }
                        }
                        .errorField("Which client is this for?")
                        if r.matches.isEmpty {
                            Text(authNoMatchText(clientNameInEmail(r))).font(.callout)
                        }
                        if choice == 0 {
                            Picker("Another client", selection: $other) {
                                Text("None").tag(0)
                                ForEach(r.otherClients, id: \.id) { o in Text(o.name).tag(o.id) }
                            }
                            .errorField("Another client")
                        }
                        if let c = r.counselor {
                            Toggle(authCounselorLabel(c), isOn: $setCounselor)
                        }
                    }
                    Section {
                        Button(busy ? "Starting…" : "Start the track from this authorization") { Task { await start(r) } }
                            .disabled(busy).frame(minHeight: 44)
                        Button("Dismiss", role: .destructive) { askingDismiss = true }
                            .disabled(busy).frame(minHeight: 44)
                    }
                }
                Section {
                    DisclosureGroup("The email as received") {
                        Text(r.emailText).font(.callout).textSelection(.enabled).fixedSize(horizontal: false, vertical: true)
                    }
                }
            } else if error == nil {
                ProgressView("Loading the authorization email…")
            }
        }
        .navigationTitle(review.map { "Authorization \($0.authNumber)" } ?? "Authorization email")
        .navigationBarTitleDisplayMode(.inline)
        .confirmationDialog("Dismiss authorization \(review?.authNumber ?? "") without starting a track?",
                            isPresented: $askingDismiss, titleVisibility: .visible) {
            Button("Dismiss", role: .destructive) { Task { await dismissEmail() } }
            Button("Keep it waiting", role: .cancel) {}
        }
        .sheet(item: $started, onDismiss: {
            if let message = savedMessage { announceAfterDismiss(message) }
            savedMessage = nil
            Task { await load() }
        }) { s in
            TrackEditSheet(trackId: nil, clientId: s.clientId, clientName: "", prefill: s.prefill, incomingAuth: emailId,
                           heading: s.heading, notices: s.notices) { message, _ in savedMessage = message }
        }
        .task { if review == nil { await load() } }
    }

    private func clientNameInEmail(_ r: IncomingAuthReview) -> String {
        r.details.first { $0.label == "Client name in the email" }?.value ?? ""
    }

    private func load() async {
        do {
            let r: IncomingAuthReview = try await api.call("incoming_authorization", query: ["id": String(emailId)])
            let first = review == nil
            review = r
            if first {
                error = nil
                choice = r.presetClientId
                if choice != 0, !r.matches.contains(where: { $0.id == choice }) { other = choice; choice = 0 }
                setCounselor = r.counselor?.preticked ?? false
            }
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func start(_ r: IncomingAuthReview) async {
        busy = true; error = nil; dismissedMessage = nil
        announce("Starting…")
        var body = startAuthBody(id: r.id, choice: choice, other: other, setCounselor: r.counselor != nil && setCounselor)
        body["idem_key"] = startIdem.key(for: body)
        var fields: [String: String] = [:]
        do {
            let reply: StartAuthReply = try await api.call("start_authorization_track", method: "POST", body: body,
                                                            refusal: { fields = refusalFields($0) })
            startIdem.answered()
            busy = false
            // The counselor notices are shown at the top of the track form and said once it is
            // open (TrackEditSheet), as Android's notice above its form.
            started = StartedTrack(clientId: reply.clientId, prefill: reply.prefill, heading: reply.heading, notices: reply.notices)
        } catch {
            startIdem.failed(error)
            busy = false
            showFormError(fields.isEmpty ? IdemKey.message(for: error, what: "the choice") : error.localizedDescription,
                          in: &self.error, field: fields["client_id"] != nil ? (choice == 0 ? "Another client" : "Which client is this for?") : nil)
            if fields.isEmpty, case APIError.server = error { await load() }
        }
    }

    private func dismissEmail() async {
        guard let r = review else { return }
        busy = true; error = nil
        var body: [String: Any] = ["id": r.id]
        body["idem_key"] = dismissIdem.key(for: body)
        do {
            let reply: MessageReply = try await api.call("dismiss_authorization", method: "POST", body: body)
            dismissIdem.answered()
            busy = false
            let message = reply.message ?? "Authorization \(r.authNumber) dismissed."
            if let onDismissed {
                onDismissed(message)
                dismissView()
                return
            }
            dismissedMessage = message
            announce(message)
            await load()
        } catch {
            dismissIdem.failed(error)
            busy = false
            showFormError(IdemKey.message(for: error, what: "the dismissal"), in: &self.error)
            if case APIError.server = error { await load() }
        }
    }
}
