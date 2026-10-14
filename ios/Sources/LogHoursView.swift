import SwiftUI

struct LogHoursView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    @State private var tracks: [TrackOption] = []
    @State private var selectedTrack: Int?
    @State private var serviceType = "Assistive Technology"
    @State private var rsaCategory = ""
    @State private var goals: [Goal] = []
    @State private var selectedGoal: Int?
    @State private var date = Date()
    @State private var hours = 1.0
    @State private var mileage = ""
    @State private var descriptionText = ""
    @State private var busy = false
    @State private var error: String?
    @State private var confirmation: String?
    /// One key per entry, kept across retries (a new one only after the entry is saved): a
    /// retry after a lost reply is recognised by the server instead of billing the hours twice.
    @State private var idemKey = UUID().uuidString
    @State private var askingDiscard = false
    /// The track whose draft key the typed entry is kept under (DraftStore). It stays on the
    /// last track chosen while "Choose…" is picked, so that draft is moved or discarded with
    /// the entry instead of being left behind to come back later.
    @State private var draftTrackId: Int?
    /// A goal from a restored draft, chosen once the track's goals have loaded.
    @State private var restoredGoalId: Int?
    /// Set when the server could not be reached and the tracks shown are the ones kept on
    /// this phone (TrackCache), so hours can still be logged and queued.
    @State private var tracksNotice: String?
    /// The server's authorization problem with this entry (expired, not yet started, or the
    /// track not Active), shown with the switch that logs it anyway. Offered only after the
    /// server named it, and withdrawn when the track or date changes.
    @State private var authorizationIssue: String?
    @State private var authorizationAck = false

    /// The description as it is sent: spaces or blank lines alone are no description.
    private var trimmedDescription: String { descriptionText.trimmingCharacters(in: .whitespacesAndNewlines) }
    /// An entry typed but not yet logged, which closing the sheet would throw away.
    private var hasEntry: Bool { !trimmedDescription.isEmpty || !mileage.isEmpty }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                if let confirmation {
                    Text(confirmation)
                        .font(.callout.weight(.medium))
                        .foregroundStyle(Color.brandTeal)
                }
                // Just after the error banner (the next thing VoiceOver reaches from it), with the
                // problem it answers next to it, as the website's hour log offers it.
                if let authorizationIssue {
                    VStack(alignment: .leading, spacing: 4) {
                        Text(authorizationIssue).font(.callout)
                        Toggle("Log it anyway (the authorization is being renewed or amended)", isOn: $authorizationAck)
                    }
                }
                if let tracksNotice {
                    Text(tracksNotice).font(.callout)
                }
                Picker("Training track", selection: $selectedTrack) {
                    Text("Choose…").tag(nil as Int?)
                    ForEach(tracks) { track in
                        Text("\(track.client) — \(track.title)").tag(track.id as Int?)
                    }
                }
                .onChange(of: selectedTrack) { newValue in
                    let track = tracks.first(where: { $0.id == newValue })
                    if let trackType = track?.serviceType { serviceType = trackType }
                    rsaCategory = track?.visitCategories?.first ?? ""
                    selectedGoal = nil
                    restoredGoalId = nil
                    goals = []
                    idemKey = UUID().uuidString     // another track is another entry
                    clearAuthorizationIssue()
                    // Typed text moves with the form to the new track (and its draft with it);
                    // an empty form picks up the new track's draft, if there is one. "Choose…"
                    // keeps the draft where it is, under the last track.
                    let oldTrack = draftTrackId
                    if let newValue { draftTrackId = newValue }
                    if hasEntry {
                        if let oldTrack, let newValue, oldTrack != newValue { clearDraft(oldTrack) }
                        saveDraft()
                    } else if let tid = newValue {
                        restoreDraft(tid)
                    }
                    if let tid = newValue { Task { await loadGoals(tid) } }
                }
                Picker("Service this visit", selection: $serviceType) {
                    ForEach(serviceTypes, id: \.self) { Text($0) }
                }
                // The RSA category delivered this visit — offered when the
                // track's program (bundle) covers more than one category.
                if visitCategories.count > 1 {
                    Picker("Rehabilitation Services Administration (RSA) category this visit", selection: $rsaCategory) {
                        ForEach(visitCategories, id: \.self) { Text($0) }
                    }
                } else if let only = visitCategories.first {
                    LabeledContent("Rehabilitation Services Administration (RSA) category", value: only)
                }
                if !openGoals.isEmpty {
                    Picker("Goal this session advanced", selection: $selectedGoal) {
                        Text("None").tag(nil as Int?)
                        ForEach(openGoals) { g in
                            Text(g.specificGoal).lineLimit(2).tag(g.id as Int?)
                        }
                    }
                }
                // 2000 through today, as the server accepts for logged hours, so a flick of the
                // year wheel can't queue a future entry that is refused when it syncs.
                DatePicker("Date", selection: $date, in: Self.dateRange, displayedComponents: .date)
                // Up to 24 hours, as the server and the website allow.
                Stepper(value: $hours, in: Self.hoursRange, step: 0.25) {
                    Text(String(format: "Hours: %.2f", hours))
                }
                // Each name stays visible above its field once it holds text.
                CaptionedField(label: "Mileage (optional, 0 to 1000 miles)", text: $mileage, keyboard: .decimalPad)
                CaptionedField(label: "What did you work on? (required)", text: $descriptionText,
                               prompt: "What did you work on?", lines: 3...8)
                Button {
                    Task { await submit() }
                } label: {
                    // The name stays while saving; the spinner beside it is not read.
                    HStack(spacing: 8) {
                        Text("Log hours")
                        if busy { ProgressView().accessibilityHidden(true) }
                    }
                    .frame(maxWidth: .infinity)
                }
                .disabled(busy || selectedTrack == nil || trimmedDescription.isEmpty)
                .accessibilityLabel("Log hours")
                .accessibilityValue(busy ? "Saving hours" : "")
                .accessibilityHint(selectedTrack == nil || trimmedDescription.isEmpty
                                   ? "Available once a training track is chosen and the work is described."
                                   : "")
            }
            .navigationTitle("Log hours")
            .toolbar {
                // A named way to close the sheet (Voice Control, Switch Control, VoiceOver). Not
                // while an entry is being logged (a refusal would land on a closed sheet), and it
                // asks first when an entry has been typed but not logged.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { if hasEntry { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasEntry, busy: busy, question: "Discard the hours entry you have not logged?",
                                 asking: $askingDiscard) {
                if let tid = draftTrackId ?? selectedTrack { clearDraft(tid) }
                dismiss()
            }
            .onChange(of: descriptionText) { _ in saveDraft() }
            .onChange(of: mileage) { _ in saveDraft() }
            .onChange(of: hours) { _ in saveDraft() }
            .onChange(of: date) { _ in
                // The authorization problem was for the date it was sent with.
                clearAuthorizationIssue()
                saveDraft()
            }
            .onChange(of: serviceType) { _ in saveDraft() }
            .onChange(of: rsaCategory) { _ in saveDraft() }
            .onChange(of: selectedGoal) { _ in saveDraft() }
            .task { await loadTracks() }
            .refreshable { await loadTracks() }
        }
    }

    /// The unsaved entry is kept on this phone as it is typed (DraftStore), per track, so a
    /// forced sign-in or the app being closed does not lose it. Cleared once it is logged,
    /// queued offline or discarded.
    private func draftKey(_ trackId: Int) -> String { "hours-\(trackId)" }

    private func saveDraft() {
        guard let tid = draftTrackId else { return }
        var fields = ["description": descriptionText, "mileage": mileage]
        // The rest of the entry travels only with text: a draft with nothing typed is removed.
        if fields.values.contains(where: { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) {
            fields["hours"] = String(hours)
            fields["date"] = APIDate.day(date)
            fields["service_type"] = serviceType
            fields["rsa_category"] = rsaCategory
            fields["goal_id"] = (selectedGoal ?? restoredGoalId).map(String.init) ?? ""
        }
        DraftStore.save(fields, key: draftKey(tid), owner: api.userId)
    }

    /// Puts the whole entry back (the work date, service, RSA category and goal too), each
    /// checked against what can be chosen now; one that can't be put back is said, never
    /// silently left at its default.
    private func restoreDraft(_ trackId: Int) {
        guard let draft = DraftStore.load(key: draftKey(trackId), owner: api.userId) else { return }
        let text = draft["description"] ?? ""
        let miles = draft["mileage"] ?? ""
        guard !text.isEmpty || !miles.isEmpty else { return }
        descriptionText = text
        mileage = miles
        if let h = draft["hours"].flatMap(Double.init), Self.hoursRange.contains(h) { hours = h }
        var checks: [String] = []
        // 2000 through today, as the date picker allows (yyyy-MM-dd compares as text).
        if let day = draft["date"], let saved = APIDate.strictDay(day), day >= "2000-01-01", day <= APIDate.day(Date()) {
            date = min(saved, Date())
        } else {
            checks.append("The date was not restored: check it before logging.")
        }
        if let service = draft["service_type"], serviceTypes.contains(service) { serviceType = service }
        // (A track kept on this phone may not list its categories: the server then uses its own.)
        if let category = draft["rsa_category"], !category.isEmpty, !visitCategories.isEmpty {
            if visitCategories.contains(category) { rsaCategory = category }
            else { checks.append("The RSA category chosen before is no longer offered on this track: check it.") }
        }
        // Chosen once this track's goals have loaded (loadGoals).
        restoredGoalId = draft["goal_id"].flatMap(Int.init)
        announce((["Your unsaved hours entry for this track was restored."] + checks).joined(separator: " "))
    }

    private func clearAuthorizationIssue() {
        // The banner telling the person to use the switch goes with it.
        if let issue = authorizationIssue, error?.hasPrefix(issue) == true { error = nil }
        authorizationIssue = nil
        authorizationAck = false
    }

    /// The end of log_hours' refusal of an entry outside the authorization (3.0.21 servers):
    /// the app answers it with the "Log it anyway" switch instead.
    private static let authorizationConfirmSuffix = "Log it on the website to confirm."

    private func clearDraft(_ trackId: Int) {
        DraftStore.clear(key: draftKey(trackId), owner: api.userId)
    }

    private static let hoursRange = 0.25...24.0

    /// 1 January 2000 through today: the server refuses a work date in the future.
    private static var dateRange: ClosedRange<Date> {
        let calendar = Calendar.current
        let start = calendar.date(from: DateComponents(year: 2000, month: 1, day: 1)) ?? .distantPast
        return start...Date()
    }

    private var visitCategories: [String] {
        tracks.first(where: { $0.id == selectedTrack })?.visitCategories ?? []
    }

    private var openGoals: [Goal] { goals.filter { $0.status != "Achieved" } }

    private func loadGoals(_ trackId: Int) async {
        if let response: GoalsResponse = try? await api.call("goals", query: ["track_id": String(trackId)]),
           selectedTrack == trackId {   // a slow reply must not overwrite a newer selection
            goals = response.goals
            // The goal a restored draft had: chosen again if it is still open, else said.
            if let goalId = restoredGoalId {
                restoredGoalId = nil
                if openGoals.contains(where: { $0.id == goalId }) {
                    selectedGoal = goalId
                } else {
                    announce("The goal chosen in your unsaved entry is no longer open on this track. Choose a goal again if one applies.")
                }
            }
        }
    }

    private func loadTracks() async {
        do {
            error = nil
            let response: TracksResponse = try await api.call("tracks")
            tracks = response.tracks
            tracksNotice = nil
            TrackCache.save(response.tracks, owner: api.userId)
            // A refresh may change the selected track's categories (or drop the
            // track entirely) — never leave a stale selection behind.
            if let track = tracks.first(where: { $0.id == selectedTrack }) {
                let cats = track.visitCategories ?? []
                if !cats.contains(rsaCategory) { rsaCategory = cats.first ?? "" }
            } else {
                selectedTrack = nil
                rsaCategory = ""
            }
        } catch APIError.network {
            // No signal: offer the tracks kept on this phone, so the hours can be queued and
            // sync later (the server checks the track and caseload again then).
            let cached = TrackCache.load(owner: api.userId)
            guard !cached.isEmpty else {
                showFormError(APIError.network.localizedDescription
                    + " No training tracks are saved on this phone yet: open a client's screen while connected to keep their tracks here.", in: &self.error)
                return
            }
            tracks = cached
            if !tracks.contains(where: { $0.id == selectedTrack }) { selectedTrack = nil; rsaCategory = "" }
            let notice = "No connection — showing the training tracks saved on this phone, which may be out of date. Hours logged now are saved here and sync automatically."
            if tracksNotice != notice { announce(notice) }
            tracksNotice = notice
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func submit() async {
        guard let trackId = selectedTrack else { return }
        // Mileage is billable: something typed that isn't a number is reported, never logged as 0.
        // Checked against the server's limits (0 to 1000 miles, stored to one decimal place)
        // here, so an offline entry is not refused at sync, where it could only be discarded.
        guard let mileageText = checkedAmount(mileage, maxWholeDigits: 4),
              mileageText.isEmpty || mileageText.range(of: "^[0-9]+(\\.[0-9]0?)?$", options: .regularExpression) != nil,
              (Double(mileageText) ?? 0) <= 1000 else {
            showFormError("Mileage: enter the miles driven, from 0 to 1000, in digits with at most one decimal place, like 12.5. Use a period or comma for the decimal point; no thousands separators or units.", in: &error)
            return
        }
        busy = true; error = nil; confirmation = nil
        announce("Saving hours")
        var body: [String: Any] = [
            "track_id": trackId,
            "service_type": serviceType,
            "rsa_category": rsaCategory,
            "hours": hours,
            "date": APIDate.day(date),
            "mileage": Double(mileageText) ?? 0,
            "description": trimmedDescription,
            // The same key on every try of this entry, so a lost response can't double-bill on retry.
            "idem_key": idemKey,
        ]
        if let selectedGoal { body["goal_id"] = selectedGoal }
        // Only once the person turned it on, in answer to the server's authorization problem.
        if authorizationIssue != nil && authorizationAck { body["authorization_ack"] = true }
        do {
            let result: SaveAck = try await api.call("log_hours", body: body)
            confirmation = result.deduplicated == true
                ? "These hours were already logged from an earlier try, so they were not logged twice."
                : String(format: "%.2f hours logged.", hours)
            announce(confirmation!)
            clearDraft(trackId)
            descriptionText = ""
            mileage = ""
            idemKey = UUID().uuidString     // the next entry is a new one
            clearAuthorizationIssue()
        } catch APIError.network {
            // No signal: keep the entry on the phone and sync automatically.
            do {
                try api.enqueue("log_hours", body: body, label: "Hours: \(trimmedDescription.prefix(40))")
                confirmation = "No connection — saved on this phone, will sync automatically."
                announce(confirmation!)
                clearDraft(trackId)
                descriptionText = ""
                mileage = ""
                idemKey = UUID().uuidString
                clearAuthorizationIssue()
            } catch {
                showFormError("Could not save offline. Your entry is still in this form. \(error.localizedDescription)", in: &self.error)
            }
        } catch APIError.server(let message) where message.hasSuffix(Self.authorizationConfirmSuffix) {
            // Outside the authorization (expired, not yet started, or the track not Active): the
            // server names the problem, and the hours are logged only once the person says to.
            let problem = message.dropLast(Self.authorizationConfirmSuffix.count).trimmingCharacters(in: .whitespaces)
            authorizationIssue = problem
            authorizationAck = false
            showFormError(problem + " To log these hours anyway (the authorization is being renewed or amended), turn on “Log it anyway” just below this message, then tap Log hours again.", in: &self.error)
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
        busy = false
    }
}

/// The training tracks Log hours offers, kept on this phone for the signed-in account so hours
/// can be logged (and queued) with no signal. Saved from every tracks list the server sends and
/// from each client screen (that client's active tracks); read only when the server can't be
/// reached. Held in DraftStore's protected, not-backed-up folder for the account; the server
/// checks the track and the caseload again when a queued entry syncs.
enum TrackCache {
    private static let key = "track-cache"

    private struct Entry: Codable {
        let id: Int
        let title: String
        let serviceType: String?
        let program: String?
        let visitCategories: [String]?
        let client: String
        let clientId: Int?
    }

    private static func entry(_ t: TrackOption) -> Entry {
        Entry(id: t.id, title: t.title, serviceType: t.serviceType, program: t.program,
              visitCategories: t.visitCategories, client: t.client, clientId: t.clientId)
    }

    private static func entries(owner: Int) -> [Entry] {
        guard let text = DraftStore.load(key: key, owner: owner)?["tracks"],
              let list = try? JSONDecoder().decode([Entry].self, from: Data(text.utf8)) else { return [] }
        return list
    }

    private static func store(_ list: [Entry], owner: Int) {
        let sorted = list.sorted { $0.client == $1.client ? $0.title < $1.title : $0.client < $1.client }
        guard let data = try? JSONEncoder().encode(sorted) else { return }
        // An empty list removes the file (DraftStore keeps nothing with no text in it).
        DraftStore.save(["tracks": sorted.isEmpty ? "" : String(decoding: data, as: UTF8.self)], key: key, owner: owner)
    }

    static func load(owner: Int) -> [TrackOption] {
        entries(owner: owner).map {
            TrackOption(id: $0.id, title: $0.title, serviceType: $0.serviceType, program: $0.program,
                        visitCategories: $0.visitCategories, client: $0.client, clientId: $0.clientId)
        }
    }

    /// Replaces the whole list with the server's (every active track the account can log to).
    static func save(_ tracks: [TrackOption], owner: Int) {
        store(tracks.map(entry), owner: owner)
    }

    /// Replaces one client's tracks with the active ones on their screen, keeping the program
    /// and visit categories a full tracks list supplied for them.
    static func saveClient(id clientId: Int, name: String, tracks: [Track], owner: Int) {
        let old = entries(owner: owner)
        let ids = Set(tracks.map(\.id))
        var list = old.filter { $0.clientId != clientId && !ids.contains($0.id) }
        for track in tracks where track.status == "Active" {
            let known = old.first(where: { $0.id == track.id })
            list.append(Entry(id: track.id, title: track.title, serviceType: track.serviceType,
                              program: known?.program, visitCategories: known?.visitCategories,
                              client: name, clientId: clientId))
        }
        store(list, owner: owner)
    }
}
