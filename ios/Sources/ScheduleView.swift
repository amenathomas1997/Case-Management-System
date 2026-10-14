import SwiftUI

/// GET schedule, with the counts 3.0.12 servers add: the server sends only the next
/// upcomingLimit upcoming sessions, and upcomingTotal says how many there are in all.
/// Both optional, so an older server still decodes.
private struct ScheduleListResponse: Decodable {
    let sessions: [Session]
    let upcomingTotal: Int?
    let upcomingLimit: Int?
    private enum CodingKeys: String, CodingKey { case sessions, upcomingTotal, upcomingLimit }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        sessions = try c.decode([Session].self, forKey: .sessions)
        upcomingTotal = try? c.decodeIfPresent(Int.self, forKey: .upcomingTotal)
        upcomingLimit = try? c.decodeIfPresent(Int.self, forKey: .upcomingLimit)
    }
}

struct ScheduleView: View {
    @EnvironmentObject var api: API
    @EnvironmentObject private var lock: AppLock
    @Environment(\.dynamicTypeSize) private var typeSize
    @State private var sessions: [Session] = []
    /// How many upcoming sessions exist in all, and how many the server sends (nil from an
    /// older server).
    @State private var upcomingTotal: Int?
    @State private var upcomingLimit: Int?
    @State private var error: String?
    @State private var confirmation: String?
    @State private var showingNewSession = false
    @State private var showingLogHours = false
    @State private var signingSession: Session?
    @State private var loaded = false
    /// Sessions whose outcome (completed, cancelled, no-show) is on its way, so a second tap
    /// can't send it again.
    @State private var completing: Set<Int> = []
    /// A completion (with no track), cancel or no-show waiting for the person to confirm it.
    @State private var pendingOutcome: SessionOutcome?
    /// The result of a sheet (new session, signature), said once the sheet has closed.
    @State private var sheetResult: String?
    /// A signed completion's result: the signature sheet locks the app as it closes, so this is
    /// said once the device owner has unlocked (said under the lock screen, it was lost).
    @State private var resultAfterUnlock: String?
    /// The row VoiceOver moves to after an outcome removes the row it was on.
    @AccessibilityFocusState private var focusedSession: Int?
    /// After a failure, VoiceOver is put on the error banner (see the list's onChange).
    @AccessibilityFocusState private var errorFocused: Bool
    private static let errorRow = "scheduleError"

    /// The session an outcome applies to, and the status it would get.
    struct SessionOutcome {
        let session: Session
        let to: String   // "Completed" (complete_session, no track), "Cancelled" or "No Show" (set_session_status)
    }

    var body: some View {
        NavigationStack {
            ScrollViewReader { proxy in
                List {
                    if let error {
                        ErrorBanner(message: error).listRowSeparator(.hidden)
                            .id(Self.errorRow)
                            .accessibilityFocused($errorFocused)
                    }
                    if let confirmation {
                        Text(confirmation)
                            .font(.callout.weight(.medium))
                            .foregroundStyle(Color.brandTeal)
                            .listRowSeparator(.hidden)
                    }
                    if !loaded && error == nil {
                        ProgressView("Loading schedule…")
                    } else if loaded && sessions.isEmpty && error == nil {
                        Text("Nothing scheduled. Use New session to add one.")
                            .foregroundStyle(Color.secondaryText)
                    }
                    // Sessions that have ended but are still Scheduled come first (the server sends
                    // every one of them): each needs an outcome, so its hours are logged or it is closed.
                    let ended = sessions.filter { $0.overdue == true }
                    let upcoming = sessions.filter { $0.overdue != true }
                    if !ended.isEmpty {
                        Section {
                            ForEach(ended) { session in sessionRow(session) }
                        } header: {
                            Text("Ended — awaiting an outcome (\(ended.count))")
                        } footer: {
                            Text("Mark each one completed, cancelled or a no-show.")
                        }
                    }
                    if !upcoming.isEmpty {
                        Section {
                            ForEach(upcoming) { session in sessionRow(session) }
                        } header: {
                            if !ended.isEmpty { Text("Upcoming") }
                        } footer: {
                            // Only the next upcomingLimit are sent: said, so the list is not taken
                            // for every booked session.
                            if let total = upcomingTotal, let limit = upcomingLimit, total > limit {
                                Text("Showing the next \(limit) of \(total) upcoming sessions; the rest are on the website's Schedule.")
                            }
                        }
                    }
                }
                // The banner is the list's first row: a failed outcome (cancel, no-show, completed)
                // from a row further down would otherwise leave it off screen, where a List never
                // draws it (or says it). Scrolled into view once it exists, then VoiceOver is put on it.
                .onChange(of: error) { message in
                    guard message != nil else { return }
                    DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(Self.errorRow, anchor: .top) } }
                    Task { @MainActor in
                        try? await Task.sleep(nanoseconds: 400_000_000)
                        errorFocused = true
                    }
                }
            }
            .navigationTitle("Schedule")
            .confirmationDialog(outcomeQuestion, isPresented: Binding(get: { pendingOutcome != nil },
                                                                      set: { if !$0 { pendingOutcome = nil } }),
                                titleVisibility: .visible, presenting: pendingOutcome) { outcome in
                if outcome.to == "Completed" {
                    Button("Mark completed") { Task { await completeFromList(outcome.session) } }
                } else {
                    Button(outcome.to == "Cancelled" ? "Cancel session" : "Mark no-show", role: .destructive) {
                        Task { await setStatus(outcome.session, to: outcome.to) }
                    }
                }
                Button("Keep it scheduled", role: .cancel) {}
            }
            .toolbar {
                ToolbarItem(placement: .primaryAction) {
                    Button { showingNewSession = true } label: {
                        Label("New session", systemImage: "plus")
                    }
                }
                // Logging hours reads client tracks, which needs the clients
                // permission — hide the button for schedule-only groups.
                if api.can("clients") {
                    ToolbarItem(placement: .secondaryAction) {
                        Button { showingLogHours = true } label: {
                            Label("Log hours", systemImage: "clock.badge.checkmark")
                        }
                    }
                }
            }
            .sheet(isPresented: $showingNewSession, onDismiss: announceSheetResult) {
                NewSessionSheet { message in
                    confirmation = message
                    sheetResult = message
                    Task { await load() }
                }
            }
            .sheet(isPresented: $showingLogHours) {
                LogHoursView()
            }
            .sheet(item: $signingSession, onDismiss: announceSheetResult) { session in
                // The sheet stays open until the completion is saved, so a failure keeps the
                // client's signature for a retry instead of asking them to sign again.
                SignatureSheet(clientName: session.client) { sig, name in
                    try await complete(session, signature: sig, signedName: name)
                }
            }
            .refreshable { await load() }
            .task { await load() }
            .onChange(of: lock.locked) { locked in
                guard !locked, let message = resultAfterUnlock else { return }
                resultAfterUnlock = nil
                // After the lock screen has gone and VoiceOver has settled on the schedule.
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 1_000_000_000)
                    announce(message)
                }
            }
        }
    }

    /// "session with Jane Doe, instructor Sam Lee, on Oct 8, 2026 at 2:00 PM": names the row in
    /// each of its buttons, so the Buttons rotor and Voice Control tell a weekly series apart.
    private func sessionName(_ session: Session) -> String {
        let instructor = (session.instructor ?? "").isEmpty ? nil : session.instructor
        return "session with \(session.client)\(instructor.map { ", instructor \($0)," } ?? "") on \(formatDateTime(session.startAt))"
    }

    private var outcomeQuestion: String {
        guard let outcome = pendingOutcome else { return "" }
        let name = sessionName(outcome.session)
        switch outcome.to {
        case "Completed": return "Mark the \(name) completed? No hours are logged, because no training track is linked. It can't be reopened in the app."
        case "Cancelled": return "Cancel the \(name)? It is closed without logging any hours."
        default: return "Mark the \(name) as a no-show? It is closed without logging any hours."
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult {
            if lock.locked { resultAfterUnlock = message } else { announceAfterDismiss(message) }
        }
        sheetResult = nil
    }

    /// The session VoiceOver should land on once `session`'s row has gone: the next one in the
    /// list as shown (ended first, then upcoming), else the one before it.
    private func neighbour(of session: Session) -> Int? {
        let shown = sessions.filter { $0.overdue == true } + sessions.filter { $0.overdue != true }
        guard let index = shown.firstIndex(where: { $0.id == session.id }) else { return nil }
        if index + 1 < shown.count { return shown[index + 1].id }
        return index > 0 ? shown[index - 1].id : nil
    }

    /// After the list reloads without `session`, moves VoiceOver to its neighbour (instead of
    /// wherever the platform puts it), then says the result: said before the reload and that
    /// focus move, it was cut off by them.
    private func refocus(after session: Session, next: Int?, saying message: String?) {
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 150_000_000)   // let the reloaded rows appear first
            if let next, !sessions.contains(where: { $0.id == session.id }),
               sessions.contains(where: { $0.id == next }) {
                focusedSession = next
            }
            if let message { announceAfterDismiss(message) }
        }
    }

    /// Whether the session's start (server time, America/Chicago) has come. A start that can't
    /// be read leaves the choice to the server.
    private func hasStarted(_ session: Session, at now: Date) -> Bool {
        guard let start = APIDate.parseDateTimeCentral(session.startAt) else { return true }
        return start <= now
    }

    @ViewBuilder
    private func sessionRow(_ session: Session) -> some View {
        let instructor = (session.instructor ?? "").isEmpty ? nil : session.instructor
        let name = sessionName(session)
        VStack(alignment: .leading, spacing: 4) {
            Text(session.client).font(.body.weight(.semibold))
                .accessibilityFocused($focusedSession, equals: session.id)
            Text(formatDateTime(session.startAt))
                .font(.callout)
            // Said in words on the row too, for anyone moving row by row past the heading.
            if session.overdue == true {
                Text("Ended — awaiting an outcome").font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
            }
            // Every instructor's sessions are listed: say whose this one is.
            if let instructor {
                Text("Instructor: \(instructor)").font(.callout).foregroundStyle(Color.secondaryText)
            }
            if session.isRemote == true {
                HStack(spacing: 6) {
                    Text("Remote").font(.caption2.weight(.semibold)).foregroundStyle(Color.brandTeal)
                        .padding(.horizontal, 6).padding(.vertical, 2)
                        .background(Color.brandTeal.opacity(0.15)).clipShape(Capsule())
                    if let u = session.meetingUrl, let url = URL(string: u),
                       let scheme = url.scheme?.lowercased(), scheme == "https" || scheme == "http" {
                        Link("Join online", destination: url).font(.callout)
                            .accessibilityLabel("Join \(name) online")
                            .accessibilityInputLabels(["Join online", "Join \(name) online"])
                    }
                }
            } else if let location = session.location, !location.isEmpty {
                Text(location).font(.callout).foregroundStyle(Color.secondaryText)
            }
            if session.trackId != nil {
                Text("Completing logs hours automatically")
                    .font(.caption).foregroundStyle(Color.secondaryText)
            }
            // As on the website, Completed is offered once the session has started: the server
            // refuses it before then, after the client may already have signed for it. Checked
            // each minute, so it appears at the start time with the screen left open.
            TimelineView(.everyMinute) { context in
                if hasStarted(session, at: context.date) {
                    Button {
                        // Sessions with a track bill hours — offer the client-signature
                        // step first (it can be completed without one, or cancelled). Without a track,
                        // completing closes the session for good, so it asks first, as the other outcomes do.
                        if session.trackId != nil { signingSession = session }
                        else { pendingOutcome = SessionOutcome(session: session, to: "Completed") }
                    } label: {
                        Label("Mark completed", systemImage: "checkmark.circle")
                    }
                    .buttonStyle(.bordered)
                    .controlSize(.large)        // 44-point targets (the regular size is about 34)
                    .padding(.top, 4)
                    .disabled(completing.contains(session.id))
                    .accessibilityLabel("Mark \(name) completed")
                    .accessibilityInputLabels(["Mark completed", "Mark \(name) completed"])
                } else {
                    Text("Can be marked completed once it has started.")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                        .padding(.top, 4)
                }
            }
            // The other outcomes close the session without logging hours: each asks first.
            // Stacked at the accessibility text sizes, where side by side they broke mid-word.
            let outcomeLayout = typeSize.isAccessibilitySize
                ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8))
                : AnyLayout(HStackLayout(spacing: 8))
            outcomeLayout {
                Button {
                    pendingOutcome = SessionOutcome(session: session, to: "Cancelled")
                } label: {
                    Label("Cancel session", systemImage: "xmark.circle")
                }
                .accessibilityLabel("Cancel \(name)")
                .accessibilityInputLabels(["Cancel session", "Cancel \(name)"])
                Button {
                    pendingOutcome = SessionOutcome(session: session, to: "No Show")
                } label: {
                    Label("No-show", systemImage: "person.crop.circle.badge.xmark")
                }
                .accessibilityLabel("Mark \(name) as a no-show")
                .accessibilityInputLabels(["No-show", "Mark \(name) as a no-show"])
            }
            .buttonStyle(.bordered)
            .controlSize(.large)
            .padding(.top, 4)           // 8 points from Mark completed with the row's spacing
            .disabled(completing.contains(session.id))
        }
        .padding(.vertical, 4)
    }

    /// Cancelled or No Show (set_session_status): closes the session without logging hours.
    private func setStatus(_ session: Session, to status: String) async {
        guard !completing.contains(session.id) else { return }
        completing.insert(session.id)
        defer { completing.remove(session.id) }
        confirmation = nil
        // Cleared first so a repeat of the same failure is shown (and announced) again.
        error = nil
        let next = neighbour(of: session)
        do {
            let _: OKResponse = try await api.call("set_session_status", body: ["session_id": session.id, "to": status])
            let message = status == "Cancelled"
                ? "Session with \(session.client) cancelled."
                : "Session with \(session.client) marked as a no-show."
            confirmation = message
            await load()
            refocus(after: session, next: next, saying: message)
        } catch {
            let message = error.localizedDescription
            await load(reportsFailure: false)
            if loaded && self.error == nil && !sessions.contains(where: { $0.id == session.id }) {
                // The reply was lost but the session is no longer scheduled: say so, so a retry
                // ("Session not found or already closed") doesn't look like a second failure.
                showFormError(message + " The session is no longer listed as scheduled, so it may already have been closed. Check before trying again.", in: &self.error)
            } else {
                // Also keeps a failed refresh's reason, when there is one.
                showFormError([message, self.error].compactMap { $0 }.joined(separator: " "), in: &self.error)
            }
        }
    }

    /// `reportsFailure`: false when the caller says a failure itself, with its own (the refresh
    /// after a failed outcome).
    private func load(reportsFailure: Bool = true) async {
        do {
            error = nil
            let response: ScheduleListResponse = try await api.call("schedule")
            sessions = response.sessions
            upcomingTotal = response.upcomingTotal
            upcomingLimit = response.upcomingLimit
            loaded = true
        } catch {
            if reportsFailure { showFormError(error.localizedDescription, in: &self.error) }
            else { self.error = error.localizedDescription }
        }
    }

    /// "Mark completed" on a session with no track (no signature step).
    private func completeFromList(_ session: Session) async {
        let next = neighbour(of: session)
        do {
            try await complete(session)
            refocus(after: session, next: next, saying: confirmation)
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    /// Throws on failure, after refreshing the list: the session may have been completed even
    /// though the reply was lost, and the list then shows what is true now.
    private func complete(_ session: Session, signature: String? = nil, signedName: String? = nil) async throws {
        guard !completing.contains(session.id) else {
            throw APIError.server("This session is already being marked completed.")
        }
        completing.insert(session.id)
        defer { completing.remove(session.id) }
        confirmation = nil
        do {
            error = nil
            // Tolerant: once the server says ok, a missing detail must not turn a completed
            // (and billed) session into an error the user would retry.
            struct CompleteResult: Decodable {
                let hoursLogged: Double
                /// The hours were logged outside the track's authorization (expired, not yet
                /// started, or the track not Active): the server's sentence saying so (3.0.21).
                let warning: String?
                private enum CodingKeys: String, CodingKey { case ok, hoursLogged, warning }
                init(from decoder: Decoder) throws {
                    let c = try decoder.container(keyedBy: CodingKeys.self)
                    guard (try? c.decode(Bool.self, forKey: .ok)) == true else {
                        throw DecodingError.dataCorruptedError(forKey: .ok, in: c, debugDescription: "not ok")
                    }
                    hoursLogged = (try? c.decode(Double.self, forKey: .hoursLogged)) ?? 0
                    let text = (try? c.decodeIfPresent(String.self, forKey: .warning))?
                        .trimmingCharacters(in: .whitespacesAndNewlines)
                    warning = (text?.isEmpty ?? true) ? nil : text
                }
            }
            var body: [String: Any] = ["session_id": session.id]
            if let signature { body["signature_b64"] = signature }
            if let signedName { body["signed_name"] = signedName }
            let result: CompleteResult = try await api.call("complete_session", body: body)
            let done: String = result.hoursLogged > 0
                ? String(format: "Completed — %.2f hours logged for %@.", result.hoursLogged, session.client)
                : "Session marked completed."
            // Logged outside the authorization: said with it (the hours are logged either way).
            let warning: String = result.warning.map { " " + $0 } ?? ""
            confirmation = done + warning
            if let c = confirmation {
                // From the signature sheet, said once it has closed; from the list, said by
                // completeFromList after the reload. Either way it would be cut off now.
                if signingSession?.id == session.id { sheetResult = c }
            }
            await load()
        } catch {
            // Quietly: the failure is said with the completion's own message.
            await load(reportsFailure: false)
            if loaded && self.error == nil && !sessions.contains(where: { $0.id == session.id }) {
                // The reply was lost but the session is no longer scheduled: say so, so a retry
                // ("Session not found or already closed") doesn't look like a second failure.
                throw APIError.server(error.localizedDescription + " The session is no longer listed as scheduled, so it may already have been completed. Check before trying again.")
            }
            throw error
        }
    }
}

struct NewSessionSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let onDone: (String) -> Void

    @State private var clients: [ClientRow] = []
    @State private var tracks: [TrackOption] = []
    @State private var staff: [StaffMember] = []
    @State private var myId: Int?
    @State private var selectedClient: Int?
    @State private var selectedTrack: Int?
    @State private var selectedStaff: Int?
    @State private var date = Date()
    @State private var duration = 1.5
    @State private var repeatWeeks = 1
    /// The authorization warnings the server returned for this booking ("Authorization review:"),
    /// shown with the approval switch. Cleared, with the approval, whenever the client, track,
    /// start, duration or series changes, so an approval only ever covers warnings seen for
    /// the booking as it is sent.
    @State private var authorizationWarning: String?
    @State private var authorizationAcknowledged = false
    @State private var location = ""
    @State private var notes = ""
    @State private var isRemote = false
    @State private var meetingUrl = ""
    @State private var busy = false
    @State private var error: String?
    /// The track list could not be loaded: without it a session can't be linked to a track,
    /// so completing it would log no hours. Said, with a retry, instead of hiding the picker.
    @State private var tracksError: String?
    @State private var askingDiscard = false

    /// Anything chosen or typed that closing the sheet would throw away.
    private var hasChanges: Bool {
        selectedClient != nil || selectedTrack != nil || repeatWeeks != 1 || isRemote
            || !location.isEmpty || !notes.isEmpty || !meetingUrl.isEmpty || authorizationAcknowledged
    }

    private var clientTracks: [TrackOption] {
        guard let clientId = selectedClient else { return [] }
        // Match by client id, not display name — two clients sharing a name would otherwise
        // cross tracks and a completed session would auto-log hours against the wrong client.
        let byId = tracks.filter { $0.clientId == clientId }
        if !byId.isEmpty { return byId }
        // Back-compat only: a server response that predates client_id (no track carries one).
        if tracks.allSatisfy({ $0.clientId == nil }),
           let clientName = clients.first(where: { $0.id == clientId })?.name {
            return tracks.filter { $0.client == clientName }
        }
        return byId
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                if let tracksError {
                    ErrorBanner(message: tracksError)
                    Button { Task { await loadTracks() } } label: {
                        Label("Try loading training tracks again", systemImage: "arrow.clockwise")
                    }
                }
                // Offered only after the server has named the warnings, next to them, and just
                // after the error banner (the next thing VoiceOver reaches from it).
                if let authorizationWarning {
                    Section("Authorization review") {
                        Text(authorizationWarning).font(.callout)
                        Toggle("I reviewed these authorization warnings and approve booking despite them", isOn: $authorizationAcknowledged)
                    }
                }
                Picker("Client (required)", selection: $selectedClient) {
                    Text("Choose…").tag(nil as Int?)
                    ForEach(clients) { client in
                        Text(client.name).tag(client.id as Int?)
                    }
                }
                if !clientTracks.isEmpty {
                    Picker("Training track", selection: $selectedTrack) {
                        Text("None").tag(nil as Int?)
                        ForEach(clientTracks) { track in
                            Text(track.title).tag(track.id as Int?)
                        }
                    }
                    Text("Link a track so completing the session logs hours automatically.")
                        .font(.caption).foregroundStyle(Color.secondaryText)
                }
                Picker("Instructor", selection: $selectedStaff) {
                    ForEach(staff) { member in
                        Text(member.name).tag(member.id as Int?)
                    }
                }
                DatePicker("Starts", selection: $date)
                Stepper(value: $duration, in: 0.25...12, step: 0.25) {
                    Text(String(format: "Duration: %.2f hours", duration))
                }
                Stepper(value: $repeatWeeks, in: 1...26) {
                    Text(repeatWeeks == 1 ? "One session" : "Repeats weekly ×\(repeatWeeks)")
                }
                Toggle("Remote session", isOn: $isRemote)
                // Each name (and the link's format) stays visible above its field once it holds text.
                if isRemote {
                    CaptionedField(label: "Meeting link (optional, starts with https://)", text: $meetingUrl, prompt: "https://…", keyboard: .URL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                } else {
                    CaptionedField(label: "Location (optional)", text: $location)
                }
                CaptionedField(label: "Notes (optional)", text: $notes)
            }
            .navigationTitle("New session")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing client: submit() says what is needed. The label
                    // says when it is working (a weekly series with invites takes a while).
                    Button(busy ? "Scheduling…" : "Schedule") { Task { await submit() } }
                        .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this new session?",
                                 asking: $askingDiscard) { dismiss() }
            .task { await loadPickers() }
            // Switching client must clear a track chosen for the previous client,
            // or a completed session would auto-log hours against the wrong client.
            .onChange(of: selectedClient) { _ in selectedTrack = nil; clearAuthorizationReview() }
            // The warnings were for the booking as it was: any change that can alter them
            // withdraws the approval (the server is asked again on the next Schedule).
            .onChange(of: selectedTrack) { _ in clearAuthorizationReview() }
            .onChange(of: date) { _ in clearAuthorizationReview() }
            .onChange(of: duration) { _ in clearAuthorizationReview() }
            .onChange(of: repeatWeeks) { _ in clearAuthorizationReview() }
        }
    }

    private func clearAuthorizationReview() {
        authorizationWarning = nil
        authorizationAcknowledged = false
    }

    /// The server's refusal when a booking needs the authorization warnings approved
    /// (api.php schedule_session).
    private static let authorizationReviewPrefix = "Authorization review:"

    private func loadPickers() async {
        // client_choices is open to scheduling groups without the clients permission (such as
        // Front Desk); a failure is said, not swallowed — an empty picker gave no reason.
        var clientProblem: String?
        do {
            clients = try await api.clientPickerChoices()
        } catch {
            let problem = "Could not load the client list. \(error.localizedDescription)"
            clientProblem = problem
            showFormError(problem, in: &self.error)
        }
        await loadTracks()
        do {
            let staffResponse: StaffResponse = try await api.call("staff")
            staff = staffResponse.staff
            struct Me: Decodable { let id: Int; let name: String }
            let me: Me = try await api.call("me")
            myId = me.id
            selectedStaff = staff.first(where: { $0.id == me.id })?.id ?? staff.first?.id
        } catch {
            showFormError([clientProblem, error.localizedDescription].compactMap { $0 }.joined(separator: " "), in: &self.error)
        }
    }

    /// Tracks need the clients permission: schedule-only accounts (which can't link a track)
    /// don't ask. For everyone else a failure is said, not swallowed.
    private func loadTracks() async {
        guard api.can("clients") else { return }
        tracksError = nil
        do {
            let trackResponse: TracksResponse = try await api.call("tracks")
            tracks = trackResponse.tracks
        } catch {
            showFormError("Could not load training tracks, so a session scheduled now can't be linked to one and won't log hours automatically. \(error.localizedDescription)", in: &tracksError)
        }
    }

    /// A meeting link the server will keep: http(s), with a host, no spaces, 500 characters at
    /// most. Anything else used to be dropped by the server while the app said "scheduled".
    private static func isMeetingLink(_ text: String) -> Bool {
        guard text.count <= 500, !text.contains(where: { $0.isWhitespace }),
              let url = URL(string: text), let scheme = url.scheme?.lowercased(),
              scheme == "https" || scheme == "http", let host = url.host, !host.isEmpty else { return false }
        return true
    }

    private func submit() async {
        guard let clientId = selectedClient else {
            showFormError("Client: choose who the session is with.", in: &error)
            return
        }
        guard let staffId = selectedStaff else {
            showFormError("Instructor: choose who leads the session. If the list is empty, close this form and open it again to reload it.", in: &error)
            return
        }
        let link = meetingUrl.trimmingCharacters(in: .whitespacesAndNewlines)
        if isRemote && !link.isEmpty && !Self.isMeetingLink(link) {
            showFormError("Meeting link: enter the whole address, starting with https://, or leave it blank.", in: &error)
            return
        }
        busy = true; error = nil
        announce("Scheduling.")
        // The server stores and interprets session times in America/Chicago (see
        // formatDateTime on the read side), so the date and time are written in that zone —
        // otherwise a device in another timezone skews the stored appointment/billing time.
        do {
            // Tolerant: a retry after a false "failed" would schedule the sessions twice.
            struct Result: Decodable {
                let inviteSent: Bool
                let sessionsCreated: Int
                private enum CodingKeys: String, CodingKey { case ok, inviteSent, sessionsCreated }
                init(from decoder: Decoder) throws {
                    let c = try decoder.container(keyedBy: CodingKeys.self)
                    guard (try? c.decode(Bool.self, forKey: .ok)) == true else {
                        throw DecodingError.dataCorruptedError(forKey: .ok, in: c, debugDescription: "not ok")
                    }
                    inviteSent = (try? c.decode(Bool.self, forKey: .inviteSent)) ?? false
                    sessionsCreated = (try? c.decode(Int.self, forKey: .sessionsCreated)) ?? 1
                }
            }
            let result: Result = try await api.call("schedule_session", body: [
                "client_id": clientId,
                "track_id": selectedTrack ?? 0,
                "staff_id": staffId,
                "date": APIDate.dayCentral(date),
                "time": APIDate.timeCentral(date),
                "duration": duration,
                "repeat_weeks": repeatWeeks,
                "authorization_ack": authorizationAcknowledged,
                // Hidden while Remote is on: a location typed before switching is not sent.
                "location": isRemote ? "" : location,
                "notes": notes,
                "is_remote": isRemote ? 1 : 0,
                "meeting_url": isRemote ? link : "",
            ])
            let base = result.sessionsCreated > 1 ? "Series of \(result.sessionsCreated) sessions scheduled." : "Session scheduled."
            let msg = base + (result.inviteSent ? " Calendar invite emailed." : "")
            // Said by the schedule once this sheet has closed (closing it would cut it off).
            onDone(msg)
            dismiss()
        } catch APIError.server(let message) where message.hasPrefix(Self.authorizationReviewPrefix) {
            // The warnings are shown with the approval switch, which is offered only now.
            var warnings = message.dropFirst(Self.authorizationReviewPrefix.count).trimmingCharacters(in: .whitespaces)
            if let instruction = warnings.range(of: " Review the track and explicitly approve", options: .backwards) {
                warnings = String(warnings[..<instruction.lowerBound])
            }
            authorizationWarning = warnings
            authorizationAcknowledged = false
            showFormError(message + " To book it anyway, turn on the approval just below this message, then tap Schedule again.", in: &self.error)
            busy = false
        } catch APIError.network {
            // The request may have reached the server: the session (or the whole series) may be
            // booked and the invite sent, and scheduling it again would book it twice.
            let who = clients.first(where: { $0.id == clientId })?.name ?? "this client"
            showFormError("The connection dropped before the server answered, so it isn't known whether this was scheduled. Check the Schedule for \(who) on \(date.formatted(date: .abbreviated, time: .shortened)) before scheduling it again.", in: &self.error)
            busy = false
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }
}
