import SwiftUI

/// One self-service request a client sent from their portal (the website's Client requests page).
struct PortalRequestItem: Decodable, Identifiable {
    let id: Int
    let clientId: Int
    let clientName: String
    let type: String
    let typeLabel: String
    let details: String?
    let status: String
    let createdAt: String
    let sessionStart: String?
    /// A records request (3.0.38): its due date and the outcome the client sees.
    var dueOn: String?
    var outcome: String?
    /// 4.35.0: times can be offered (an open appointment or reschedule request), and the
    /// starts already offered; false and empty from an older server.
    var canOffer = false
    var offered: [String] = []
    private enum CodingKeys: String, CodingKey { case id, clientId, clientName, type, typeLabel, details, status, createdAt, sessionStart, dueOn, outcome, canOffer, offered }
    private struct PortalOfferedStart: Decodable {
        let startAt: String?
        private enum CodingKeys: String, CodingKey { case startAt }
        init(from d: Decoder) throws {
            startAt = try? d.container(keyedBy: CodingKeys.self).decodeIfPresent(String.self, forKey: .startAt)
        }
    }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        type = (try? c.decode(String.self, forKey: .type)) ?? "other"
        typeLabel = (try? c.decode(String.self, forKey: .typeLabel)) ?? "Request"
        details = try? c.decodeIfPresent(String.self, forKey: .details)
        status = (try? c.decode(String.self, forKey: .status)) ?? "New"
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        sessionStart = try? c.decodeIfPresent(String.self, forKey: .sessionStart)
        dueOn = try? c.decodeIfPresent(String.self, forKey: .dueOn)
        outcome = try? c.decodeIfPresent(String.self, forKey: .outcome)
        canOffer = SessionNoteTarget.flag(c, .canOffer) ?? false
        offered = ((try? c.decodeIfPresent([PortalOfferedStart].self, forKey: .offered)) ?? nil)?.compactMap(\.startAt) ?? []
    }
    /// A copy-of-records or correction request: closing it needs the outcome the client sees.
    var isRecordsRequest: Bool { type == "records" || type == "correction" }
}

struct PortalRequestsResponse: Decodable {
    let requests: [PortalRequestItem]
    let statuses: [String]
    let newCount: Int
    let truncated: Bool?
}

struct PortalRequestUpdated: Decodable { let changed: Bool?; let message: String? }

extension PortalRequestItem {
    var spoken: String {
        var parts = [clientName, typeLabel, status, "sent \(formatDate(createdAt))"]
        if let sessionStart { parts.append("session \(formatDateTime(sessionStart))") }
        if let dueOn { parts.append("due \(formatDate(dueOn))") }
        if let outcome, !outcome.isEmpty { parts.append("Outcome: \(outcome)") }
        if !offered.isEmpty { parts.append("Offered: " + offered.map(formatDateTime).joined(separator: "; ")) }
        if let details, !details.isEmpty { parts.append(details) }
        return parts.joined(separator: ". ")
    }
}

/// Requests clients sent from their portal (appointments, reschedules, cancellations, device
/// help), open ones first; each one's status can be changed as on the website.
struct PortalRequestsView: View {
    @EnvironmentObject var api: API
    @State private var data: PortalRequestsResponse?
    @State private var error: String?
    @State private var showAll = false
    @State private var openClient: Int?
    @State private var saving: Set<Int> = []
    /// A records request being closed, waiting for its outcome.
    @State private var closing: (request: PortalRequestItem, to: String)?
    @State private var outcomeText = ""
    /// 4.35.0: the request times are being offered for.
    @State private var offering: PortalRequestItem?
    @State private var sheetResult: String?
    @AccessibilityFocusState private var focused: Int?
    private let errorRow = "portalRequestsError"

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden).id(errorRow)
                        .accessibilityFocused($focused, equals: -2)
                }
                if let d = data {
                    Section {
                        Text("\(d.newCount) new. " + (showAll ? "Showing all requests, including done and declined ones." : "Showing open requests (new and in progress)."))
                            .font(.callout)
                        if d.truncated == true { Text("Only the first 300 are listed.").font(.callout) }
                    }
                    if d.requests.isEmpty {
                        Text(showAll ? "No requests." : "No open requests.").foregroundStyle(Color.secondaryText)
                            .accessibilityFocused($focused, equals: -1)
                    }
                    Section {
                        ForEach(d.requests) { r in row(r, statuses: d.statuses) }
                    } header: {
                        Text("Requests (\(d.requests.count))").font(.headline).foregroundStyle(.primary).textCase(nil)
                            .accessibilityAddTraits(.isHeader)
                    }
                } else if error == nil {
                    ProgressView("Loading requests…")
                }
            }
            .onChange(of: error) { message in
                guard let message else { return }
                claimFormErrorFocus(message)   // VoiceOver is put on the banner, which reads it
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    focused = -2
                }
            }
        }
        .navigationTitle("Client requests")
        .toolbar {
            ToolbarItem(placement: .secondaryAction) {
                Button(showAll ? "Show open requests only" : "Show all requests, including done and declined") {
                    showAll.toggle()
                    Task { await load(announceView: true) }
                }
            }
        }
        .navigationDestination(isPresented: Binding(get: { openClient != nil }, set: { if !$0 { openClient = nil } })) {
            if let id = openClient { ClientDetailView(clientId: id) }
        }
        .alert(closing?.to == "Declined" ? "Why is the request refused?" : "How was it handled?", isPresented: Binding(get: { closing != nil }, set: { if !$0 { closing = nil } })) {
            TextField("Outcome the client sees", text: $outcomeText)
            Button("Save") {
                if let c = closing { Task { await setStatus(c.request, c.to, outcome: outcomeText) } }
                closing = nil
            }
            Button("Cancel", role: .cancel) { closing = nil }
        } message: {
            Text("The client sees this in their portal.")
        }
        .sheet(item: $offering, onDismiss: {
            if let message = sheetResult { announceAfterDismiss(message) }
            sheetResult = nil
        }) { request in
            OfferTimesSheet(requestId: request.id, clientName: request.clientName) { message in
                sheetResult = message
                Task { await load() }
            }
        }
        .refreshable { await load() }
        .task { await load() }
    }

    @ViewBuilder private func row(_ r: PortalRequestItem, statuses: [String]) -> some View {
        VStack(alignment: .leading, spacing: 4) {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(r.clientName).font(.body.weight(.semibold))
                Spacer()
                Text(r.status).font(.caption).foregroundStyle(Color.secondaryText)
            }
            Text("\(r.typeLabel) · sent \(formatDate(r.createdAt))").font(.caption).foregroundStyle(Color.secondaryText)
            if let s = r.sessionStart { Text("Session \(formatDateTime(s))").font(.caption).foregroundStyle(Color.secondaryText) }
            if let due = r.dueOn { Text("Due \(formatDate(due))").font(.caption.weight(.semibold)) }
            if let o = r.outcome, !o.isEmpty { Text("Outcome: \(o)").font(.caption).foregroundStyle(Color.secondaryText) }
            if !r.offered.isEmpty {
                Text("Offered: " + r.offered.map(formatDateTime).joined(separator: "; ")).font(.caption).foregroundStyle(Color.secondaryText)
            }
            if let details = r.details, !details.isEmpty { Text(details).font(.callout) }
            Menu {
                ForEach(statuses, id: \.self) { s in
                    Button { Task { await setStatus(r, s) } } label: {
                        if s == r.status { Label(s, systemImage: "checkmark") } else { Text(s) }
                    }
                }
            } label: {
                Label("Status: \(r.status)", systemImage: "chevron.up.chevron.down")
                    .frame(minHeight: 44, alignment: .leading)
                    .contentShape(Rectangle())
            }
            .disabled(saving.contains(r.id))
        }
        .padding(.vertical, 2)
        // One VoiceOver stop per request; each status and the client record are on the Actions rotor.
        .accessibilityElement(children: VoiceOverRows.children)
        .accessibilityLabel(r.spoken)
        .accessibilityFocused($focused, equals: r.id)
        .accessibilityAction { announceRowActions() }
        .accessibilityActions {
            ForEach(statuses.filter { $0 != r.status }, id: \.self) { s in
                Button("Mark \(s)") { Task { await setStatus(r, s) } }
            }
            if r.canOffer { Button(r.offered.isEmpty ? "Offer times" : "Offer different times") { offering = r } }
            if api.can("clients") { Button("Open client record") { openClient = r.clientId } }
        }
            // 4.35.0: as the website's "Offer times" on an open appointment or reschedule
            // request; outside the row's one VoiceOver stop, so it is reached by swiping too.
            if r.canOffer {
                Button { offering = r } label: {
                    Label(r.offered.isEmpty ? "Offer times" : "Offer different times", systemImage: "calendar.badge.plus")
                        .frame(minHeight: 44, alignment: .leading)
                        .contentShape(Rectangle())
                }
                .buttonStyle(.borderless)
                .accessibilityLabel((r.offered.isEmpty ? "Offer times to " : "Offer different times to ") + r.clientName + ", " + r.typeLabel.lowercased() + " request")
            }
        }
    }

    private func setStatus(_ r: PortalRequestItem, _ to: String, outcome: String? = nil) async {
        guard to != r.status, !saving.contains(r.id) else { return }
        if r.isRecordsRequest && (to == "Done" || to == "Declined") && (outcome ?? "").trimmingCharacters(in: .whitespaces).isEmpty {
            outcomeText = r.outcome ?? ""
            closing = (r, to)
            return
        }
        let index = data?.requests.firstIndex(where: { $0.id == r.id }) ?? 0
        saving.insert(r.id)
        defer { saving.remove(r.id) }
        error = nil
        do {
            var body: [String: Any] = ["id": r.id, "to": to]
            if let outcome { body["outcome"] = outcome }
            let result: PortalRequestUpdated = try await api.call("portal_request_update", body: body)
            await load()
            if let list = data?.requests {
                if list.contains(where: { $0.id == r.id }) { focused = r.id }
                else if !list.isEmpty { focused = list[min(index, list.count - 1)].id }
                else { focused = -1 }
            }
            announceAfterDismiss(result.message ?? "Request set to \(to).")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }

    private func load(announceView: Bool = false) async {
        let wantsAll = showAll
        do {
            error = nil
            let r: PortalRequestsResponse = try await api.call("portal_requests", query: wantsAll ? ["all": "1"] : [:])
            guard wantsAll == showAll else { return }
            data = r
            if announceView { announce(wantsAll ? "Showing all requests, including done and declined." : "Showing open requests only.") }
        } catch {
            guard wantsAll == showAll else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

/// offer_times (GET, 4.35.0): the website's offer form for one request, with its defaults.
struct OfferTimesForm: Decodable {
    struct OfferRequestInfo: Decodable {
        let type: String
        let clientName: String
        private enum CodingKeys: String, CodingKey { case type, clientName }
        init(from d: Decoder) throws {
            let c = try d.container(keyedBy: CodingKeys.self)
            type = (try? c.decode(String.self, forKey: .type)) ?? "appointment"
            clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        }
    }
    struct OfferDefaults: Decodable {
        let staffId: Int?
        let minutes: Int?
        let remote: Bool
        let location: String
        let trackId: Int?
        private enum CodingKeys: String, CodingKey { case staffId, minutes, remote, location, trackId }
        init(from d: Decoder) throws {
            let c = try d.container(keyedBy: CodingKeys.self)
            staffId = SessionNoteTarget.int(c, .staffId)
            minutes = SessionNoteTarget.int(c, .minutes)
            remote = SessionNoteTarget.flag(c, .remote) ?? false
            location = (try? c.decode(String.self, forKey: .location)) ?? ""
            trackId = SessionNoteTarget.int(c, .trackId)
        }
    }
    struct OfferMade: Decodable {
        let startAt: String
        let staffName: String?
        private enum CodingKeys: String, CodingKey { case startAt, staffName }
        init(from d: Decoder) throws {
            let c = try d.container(keyedBy: CodingKeys.self)
            startAt = (try? c.decode(String.self, forKey: .startAt)) ?? ""
            staffName = try? c.decodeIfPresent(String.self, forKey: .staffName)
        }
    }
    let request: OfferRequestInfo?
    let canOffer: Bool
    let staff: [StaffMember]
    let defaults: OfferDefaults?
    let lengths: [Int]
    let keepsLength: Bool
    let tracks: [GroupChoiceTrack]
    let offered: [OfferMade]
    private enum CodingKeys: String, CodingKey { case request, canOffer, staff, defaults, lengths, keepsLength, tracks, offered }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        request = try? c.decodeIfPresent(OfferRequestInfo.self, forKey: .request)
        canOffer = SessionNoteTarget.flag(c, .canOffer) ?? false
        staff = LenientList.decode(StaffMember.self, from: c, forKey: .staff)
        defaults = try? c.decodeIfPresent(OfferDefaults.self, forKey: .defaults)
        let sent = (try? c.decodeIfPresent([Int].self, forKey: .lengths)) ?? nil
        lengths = (sent?.isEmpty ?? true) ? [30, 45, 60, 90, 120, 180] : sent!
        keepsLength = SessionNoteTarget.flag(c, .keepsLength) ?? false
        tracks = LenientList.decode(GroupChoiceTrack.self, from: c, forKey: .tracks)
        offered = LenientList.decode(OfferMade.self, from: c, forKey: .offered)
    }
}

/// offer_times's reply (POST): how many were offered and the website's message.
struct OfferTimesReply: Decodable {
    let offered: Int
    let message: String?
    private enum CodingKeys: String, CodingKey { case ok, offered, message }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        guard (try? c.decode(Bool.self, forKey: .ok)) == true else {
            throw DecodingError.dataCorruptedError(forKey: .ok, in: c, debugDescription: "not ok")
        }
        offered = SessionNoteTarget.int(c, .offered) ?? 0
        message = try? c.decodeIfPresent(String.self, forKey: .message)
    }
}

/// Offer up to three times for a client's appointment or reschedule request (4.35.0), as the
/// website's "Offer times": the client chooses one in their portal.
struct OfferTimesSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let requestId: Int
    let clientName: String
    let onDone: (String) -> Void

    @State private var form: OfferTimesForm?
    @State private var loadError: String?
    @State private var first = Date().addingTimeInterval(86_400)
    @State private var offerSecond = false
    @State private var second = Date().addingTimeInterval(2 * 86_400)
    @State private var offerThird = false
    @State private var third = Date().addingTimeInterval(3 * 86_400)
    @State private var minutes = 60
    @State private var staffId = 0
    @State private var trackId = 0
    @State private var remote = false
    @State private var location = ""
    @State private var confirming = false
    @State private var idem = IdemKey()
    @State private var busy = false
    @State private var error: String?

    static let timeFields = ["First time", "Second time", "Third time"]

    /// The times chosen, in the server's zone as "Y-m-d H:i" (offer_times), each with the field
    /// it came from: the server numbers its time1..3 errors by position among the times sent.
    static func offerTimesBody(_ times: [Date?]) -> [(field: String, time: String)] {
        times.enumerated().compactMap { i, d in
            d.map { (timeFields[i], APIDate.dayCentral($0) + " " + APIDate.timeCentral($0)) }
        }
    }

    /// The field a time error is about: errors.timeN is the Nth time sent.
    static func timeErrorField(_ key: String, sent: [(field: String, time: String)]) -> String? {
        guard key.hasPrefix("time"), let n = Int(key.dropFirst(4)), n >= 1 else { return nil }
        return n <= sent.count ? sent[n - 1].field : timeFields.first
    }

    private var sentTimes: [(field: String, time: String)] {
        Self.offerTimesBody([first, offerSecond ? second : nil, offerThird ? third : nil])
    }
    private var chosenTimes: [String] { sentTimes.map(\.time) }

    /// The question asked first, listing the times (as the Android app asks it).
    static func offerQuestion(client: String, times: [String]) -> String {
        "Offer \(times.count == 1 ? "this time" : "these times") to \(client): \(times.map(formatDateTime).joined(separator: "; "))? They choose one in their portal."
    }
    private var question: String { Self.offerQuestion(client: clientName, times: chosenTimes.map { $0 + ":00" }) }

    /// Anything changed since the form loaded, which a swipe down would throw away.
    @State private var loadedValues: [String] = []
    private var currentValues: [String] {
        [chosenTimes.joined(separator: ","), String(minutes), String(staffId), String(trackId), remote ? "1" : "0", location]
    }
    private var hasChanges: Bool { !loadedValues.isEmpty && currentValues != loadedValues }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                if let loadError {
                    ErrorBanner(message: loadError)
                    Button { Task { await load() } } label: { Label("Try loading the offer form again", systemImage: "arrow.clockwise") }
                } else if form == nil {
                    ProgressView("Loading…")
                }
                if let form {
                    if !form.offered.isEmpty {
                        Section("Offered now") {
                            ForEach(Array(form.offered.enumerated()), id: \.offset) { _, o in
                                Text(formatDateTime(o.startAt) + (o.staffName.map { ", with \($0)" } ?? ""))
                            }
                        }
                    }
                    Section("Times to offer") {
                        DatePicker("First time (required)", selection: $first, in: Date()...)
                            .errorField("First time")
                        Toggle("Offer a second time", isOn: $offerSecond)
                        if offerSecond {
                            DatePicker("Second time", selection: $second, in: Date()...)
                                .errorField("Second time")
                        }
                        Toggle("Offer a third time", isOn: $offerThird)
                        if offerThird {
                            DatePicker("Third time", selection: $third, in: Date()...)
                                .errorField("Third time")
                        }
                    }
                    if form.keepsLength {
                        Text("The session keeps its length.")
                    } else {
                        Picker("Length", selection: $minutes) {
                            ForEach(form.lengths, id: \.self) { Text("\($0) minutes").tag($0) }
                        }
                        .errorField("Length")
                    }
                    Picker("With", selection: $staffId) {
                        ForEach(form.staff) { Text($0.name).tag($0.id) }
                    }
                    .errorField("With")
                    if !form.keepsLength && !form.tracks.isEmpty {
                        Picker("Training track", selection: $trackId) {
                            ForEach(form.tracks) { Text($0.title).tag($0.id) }
                        }
                        .errorField("Training track")
                    }
                    Toggle("Remote", isOn: $remote)
                    CaptionedField(label: "Location (optional)", text: $location)
                    Button {
                        confirming = true
                    } label: {
                        HStack(spacing: 8) {
                            Text("Offer these times")
                            if busy { ProgressView().accessibilityHidden(true) }
                        }
                    }
                    .disabled(busy || !form.canOffer)
                    .accessibilityValue(busy ? "Sending" : "")
                    if !form.canOffer {
                        Text("This request is closed, so no times can be offered.").foregroundStyle(Color.secondaryText)
                    }
                }
            }
            .navigationTitle("Offer times to \(clientName)")
            .navigationBarTitleDisplayMode(.inline)
            .interactiveDismissDisabled(busy || hasChanges)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
            }
            .confirmationDialog(question, isPresented: $confirming, titleVisibility: .visible) {
                Button("Offer these times") { Task { await submit() } }
                Button("Not yet", role: .cancel) {}
            }
            .task { await load() }
        }
    }

    private func load() async {
        loadError = nil
        do {
            let r: OfferTimesForm = try await api.call("offer_times", query: ["id": String(requestId)])
            form = r
            staffId = r.defaults?.staffId ?? r.staff.first?.id ?? 0
            minutes = r.defaults?.minutes.flatMap { r.lengths.contains($0) ? $0 : nil } ?? (r.lengths.contains(60) ? 60 : r.lengths.first ?? 60)
            trackId = r.defaults?.trackId ?? r.tracks.first?.id ?? 0
            remote = r.defaults?.remote ?? false
            location = r.defaults?.location ?? ""
            loadedValues = currentValues
        } catch {
            showFormError(error.localizedDescription, in: &loadError)
        }
    }

    private static let fieldNames = ["minutes": "Length", "staff_id": "With", "track_id": "Training track"]

    private func submit() async {
        guard let form else { return }
        let sent = sentTimes
        var body: [String: Any] = [
            "id": requestId,
            "times": sent.map(\.time),
            "minutes": minutes,
            "staff_id": staffId,
            "track_id": form.keepsLength ? 0 : trackId,
            "remote": remote ? 1 : 0,
            "location": location.trimmingCharacters(in: .whitespaces),
        ]
        body["idem_key"] = idem.key(for: body)
        busy = true; error = nil
        announce("Offering the times.")
        defer { busy = false }
        var refusedFields: [String: String] = [:]
        do {
            let r: OfferTimesReply = try await api.call("offer_times", body: body, refusal: { reply in
                refusedFields = (reply["errors"] as? [String: String]) ?? [:]
            })
            idem.answered()
            onDone(r.message ?? "\(r.offered) time\(r.offered == 1 ? "" : "s") offered to \(clientName). They choose one in their portal.")
            dismiss()
        } catch {
            idem.failed(error)
            let order = ["time1", "time2", "time3", "minutes", "staff_id", "track_id"]
            if let key = order.first(where: { refusedFields[$0] != nil }), let text = refusedFields[key] {
                showFormError(text, in: &self.error, field: Self.timeErrorField(key, sent: sent) ?? Self.fieldNames[key])
            } else {
                showFormError(IdemKey.message(for: error, what: "the offer"), in: &self.error)
            }
        }
    }
}
