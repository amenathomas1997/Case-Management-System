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
    /// The track whose draft key the typed entry is kept under (DraftStore).
    @State private var draftTrackId: Int?
    /// Set when the server could not be reached and the tracks shown are the ones kept on
    /// this phone (TrackCache), so hours can still be logged and queued.
    @State private var tracksNotice: String?

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
                    goals = []
                    idemKey = UUID().uuidString     // another track is another entry
                    // Typed text moves with the form to the new track (and its draft with it);
                    // an empty form picks up the new track's draft, if there is one.
                    let oldTrack = draftTrackId
                    draftTrackId = newValue
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
                if let tid = selectedTrack { clearDraft(tid) }
                dismiss()
            }
            .onChange(of: descriptionText) { _ in saveDraft() }
            .onChange(of: mileage) { _ in saveDraft() }
            .onChange(of: hours) { _ in saveDraft() }
            .task { await loadTracks() }
            .refreshable { await loadTracks() }
        }
    }

    /// The unsaved entry is kept on this phone as it is typed (DraftStore), per track, so a
    /// forced sign-in or the app being closed does not lose it. Cleared once it is logged,
    /// queued offline or discarded.
    private func draftKey(_ trackId: Int) -> String { "hours-\(trackId)" }

    private func saveDraft() {
        guard let tid = selectedTrack else { return }
        var fields = ["description": descriptionText, "mileage": mileage]
        // The hours travel only with text: a draft with nothing typed is removed.
        if fields.values.contains(where: { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) {
            fields["hours"] = String(hours)
        }
        DraftStore.save(fields, key: draftKey(tid), owner: api.userId)
    }

    private func restoreDraft(_ trackId: Int) {
        guard let draft = DraftStore.load(key: draftKey(trackId), owner: api.userId) else { return }
        let text = draft["description"] ?? ""
        let miles = draft["mileage"] ?? ""
        guard !text.isEmpty || !miles.isEmpty else { return }
        descriptionText = text
        mileage = miles
        if let h = draft["hours"].flatMap(Double.init), Self.hoursRange.contains(h) { hours = h }
        announce("Your unsaved hours entry for this track was restored.")
    }

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
                self.error = APIError.network.localizedDescription
                    + " No training tracks are saved on this phone yet: open a client's screen while connected to keep their tracks here."
                return
            }
            tracks = cached
            if !tracks.contains(where: { $0.id == selectedTrack }) { selectedTrack = nil; rsaCategory = "" }
            let notice = "No connection — showing the training tracks saved on this phone, which may be out of date. Hours logged now are saved here and sync automatically."
            if tracksNotice != notice { announce(notice) }
            tracksNotice = notice
        } catch {
            self.error = error.localizedDescription
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
            } catch {
                self.error = "Could not save offline. Your entry is still in this form. \(error.localizedDescription)"
            }
        } catch {
            self.error = error.localizedDescription
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
