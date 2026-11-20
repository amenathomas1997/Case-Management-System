import SwiftUI

struct Booking: Decodable, Identifiable {
    let id: Int; let serviceType: String; let provider: String?; let neededAt: String?
    let durationMin: Int; let location: String?; let status: String; let cost: Double?
    let clientName: String; let clientId: Int
    /// Access needs, where to meet (nil from a server that does not send it).
    let notes: String?
    /// 4.38.0 servers: the website's label for the service ("CART (real-time captioning)") and
    /// its wording of when it is needed.
    let typeLabel: String?
    let whenText: String?
}
struct BookingsResponse: Decodable { let bookings: [Booking] }

var bookingTypes: [String] { FormOptions.list("booking_types", ["Interpreter", "CART captioning", "Transportation", "Note-taker", "Other"]) }
let bookingStatuses = ["Requested", "Confirmed", "Completed", "Cancelled"]

/// The booking's service as the website names it (an older server: the stored name).
func bookingLabel(_ b: Booking) -> String { (b.typeLabel ?? "").isEmpty ? b.serviceType : (b.typeLabel ?? b.serviceType) }

/// A booking's lines, in the website's order: "{label} for {client}", when, the duration, the
/// estimated cost, the provider, the location and the notes.
func bookingLines(_ b: Booking) -> [String] {
    var lines = ["\(bookingLabel(b)) for \(b.clientName)"]
    if let w = b.whenText, !w.isEmpty { lines.append(w) } else { lines.append(b.neededAt.map(formatDateTime) ?? "no date set") }
    if b.durationMin > 0 { lines.append("\(b.durationMin) minutes") }
    if let c = b.cost { lines.append("Estimated cost \(money(c))") }
    if let p = b.provider, !p.isEmpty { lines.append(p) }
    if let l = b.location, !l.isEmpty { lines.append(l) }
    if let n = b.notes, !n.isEmpty { lines.append("Notes: \(n)") }
    return lines
}

/// The statuses a booking can be set to from here: every other one; a cancelled booking can be
/// restored to Requested, Confirmed or Completed.
func bookingStatusChoices(_ status: String) -> [String] { bookingStatuses.filter { $0 != status } }

/// The question before a booking is cancelled (names the booking).
func bookingCancelTitle(label: String, client: String) -> String { "Cancel the \(label) booking for \(client)?" }

/// bookings' query for the chosen view (4.38.0: view; an older server reads upcoming / past).
func bookingsQuery(_ view: String) -> [String: String] {
    switch view {
    // The history alone: 4.37.0's past=1 request, with no view (view=past is every booking not
    // cancelled, which would list the upcoming ones twice).
    case "past": return ["past": "1"]
    case "cancelled": return ["view": "cancelled"]
    default: return ["view": "upcoming", "upcoming": "1"]
    }
}

/// Booking cost typed as on the website (4.38.0): "$1,250.50" or "125.50", up to 8 digits
/// before the cents; "" when blank, nil when it is not in that shape.
func bookingCostText(_ raw: String) -> String? {
    let text = moneyInput(raw)
    if text.isEmpty { return "" }
    return text.range(of: "^[0-9]{1,8}(\\.[0-9]{1,2})?$", options: .regularExpression) != nil ? text : nil
}

/// The website's sentence for a cost it refuses (access_bookings.php).
let bookingCostHint = "Estimated cost: Enter the estimated cost in dollars and cents, for example 125.50 (no more than 99,999,999.99), or leave it blank."

/// A booking write's reply (4.38.0: the website's sentence).
struct BookingWriteReply: Decodable { let message: String? }

struct BookingsView: View {
    @EnvironmentObject var api: API
    @State private var upcoming: [Booking] = []
    @State private var past: [Booking] = []
    @State private var cancelled: [Booking] = []
    /// 4.38.0: Upcoming, Including past, or Cancelled (an older server: upcoming and past).
    @State private var view = "upcoming"
    @State private var loaded = false
    @State private var error: String?
    @State private var notice: String?
    @State private var showNew = false
    @State private var pendingCancel: Booking?
    /// A status change is being saved: the menus wait, so a second tap cannot send another.
    @State private var busy = false
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// The booking VoiceOver is put on after a changed one leaves the list (emptyFocus: the
    /// "No upcoming bookings" line when none are left).
    @AccessibilityFocusState private var focusedBooking: Int?
    private let emptyFocus = -1

    private var newServer: Bool { api.serverAtLeast(4, 38) }

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden).id("bookingsError") }
                if let notice { Text(notice).font(.callout.weight(.semibold)) }
                if newServer {
                    Picker("Show", selection: $view) {
                        Text("Upcoming").tag("upcoming")
                        Text("Including past").tag("past")
                        Text("Cancelled").tag("cancelled")
                    }
                }
                if newServer && view == "cancelled" {
                    Section("Cancelled bookings") {
                        if loaded && cancelled.isEmpty {
                            Text("No cancelled bookings.").accessibilityFocused($focusedBooking, equals: emptyFocus)
                        } else if !loaded && error == nil {
                            ProgressView("Loading bookings…")
                        }
                        ForEach(cancelled) { b in row(b) }
                    }
                } else {
                    // What is coming up first, soonest first; history after it, most recent first.
                    Section("Upcoming") {
                        if loaded && upcoming.isEmpty {
                            Text("No upcoming bookings.")
                                .accessibilityFocused($focusedBooking, equals: emptyFocus)
                        } else if !loaded && error == nil {
                            ProgressView("Loading bookings…")
                        }
                        ForEach(upcoming) { b in row(b) }
                    }
                    if !past.isEmpty {
                        Section("Past") { ForEach(past) { b in row(b) } }
                    }
                }
            }
            // The banner is the list's first row: a failure from a row further down would
            // otherwise leave it off screen, where it is neither seen nor announced.
            .onChange(of: error) { if $0 != nil { proxy.scrollTo("bookingsError", anchor: .top) } }
        }
        .navigationTitle("Bookings")
        .modifier(ReadablePickers(inNavigationStack: true))
        .refreshable { await load() }
        .task { await load() }
        .onChange(of: view) { _ in error = nil; notice = nil; loaded = false; Task { await load() } }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add booking") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) { BookingEditSheet { sheetResult = $0; Task { await load() } } }
        .confirmationDialog(
            bookingCancelTitle(label: pendingCancel.map(bookingLabel) ?? "", client: pendingCancel?.clientName ?? ""),
            isPresented: Binding(get: { pendingCancel != nil }, set: { if !$0 { pendingCancel = nil } }),
            titleVisibility: .visible
        ) {
            Button("Cancel booking", role: .destructive) {
                if let b = pendingCancel { Task { await setStatus(b, "Cancelled") } }
            }
            Button("Keep booking", role: .cancel) { pendingCancel = nil }
        } message: {
            Text(newServer ? "It moves to the cancelled list, where it can be restored." : "Cancelled bookings are not listed in the app on this server.")
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    /// One booking as one VoiceOver stop (its status read with it); each other status is on its
    /// Actions rotor and also in the visible menu after it (4.38.0: nothing only on the rotor).
    @ViewBuilder private func row(_ b: Booking) -> some View {
        let lines = bookingLines(b)
        VStack(alignment: .leading, spacing: 3) {
            // Caption text in the primary colour: grey secondary caption text is about 3.4:1.
            Text(lines[0]).font(.body.weight(.semibold))
            ForEach(Array(lines.dropFirst().enumerated()), id: \.offset) { _, line in Text(line).font(.caption) }
            Text("Status: \(b.status)").font(.caption.weight(.semibold))
        }
        .padding(.vertical, 2)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel((lines + ["Status: \(b.status)"]).joined(separator: ", "))
        .accessibilityActions {
            ForEach(bookingStatusChoices(b.status), id: \.self) { s in
                Button(s == "Cancelled" ? "Cancel booking" : "Mark \(s)") { choose(b, s) }
            }
        }
        .accessibilityFocused($focusedBooking, equals: b.id)
        Menu {
            ForEach(bookingStatusChoices(b.status), id: \.self) { s in
                Button(s == "Cancelled" ? "Cancel booking" : "Mark \(s)") { choose(b, s) }
            }
        } label: {
            Text("Change status of \(bookingLabel(b)) for \(b.clientName)").frame(minHeight: 44)
        }
        .disabled(busy)
    }

    private func choose(_ b: Booking, _ s: String) {
        guard !busy else { return }
        if s == "Cancelled" { pendingCancel = b } else { Task { await setStatus(b, s) } }
    }

    /// Needed within the last day or later (or not dated yet) counts as upcoming, as on the website.
    /// Used only for an older server that ignores ?upcoming / ?past and sends every booking.
    private func isUpcoming(_ b: Booking, cutoff: Date) -> Bool {
        guard let raw = b.neededAt, let when = APIDate.parseDateTimeCentral(raw) else { return true }
        return when >= cutoff
    }

    private func load() async {
        let wanted = newServer ? view : "past"
        do {
            if wanted == "cancelled" {
                let r: BookingsResponse = try await api.call("bookings", query: bookingsQuery("cancelled"))
                guard wanted == (newServer ? view : "past") else { return }
                cancelled = r.bookings
                error = nil; loaded = true
                return
            }
            // Upcoming and past are asked for separately so a long history can never push
            // the bookings that are still to come off the end of the list.
            let u: BookingsResponse = try await api.call("bookings", query: bookingsQuery("upcoming"))
            var p = BookingsResponse(bookings: [])
            if wanted == "past" { p = try await api.call("bookings", query: bookingsQuery("past")) }
            guard wanted == (newServer ? view : "past") else { return }
            // The server's own split is used as sent: re-cutting it on the phone's clock could
            // leave a booking near the boundary in neither list (clock skew, daylight saving).
            // Only when the two answers are the same bookings (an older server that sends
            // everything either way) is the list split here instead. A booking in both answers
            // otherwise only straddled the boundary between the two queries: it stays upcoming,
            // and the rest of the history is kept.
            let upcomingIds = Set(u.bookings.map { $0.id })
            var soonList = u.bookings, pastList = p.bookings
            if wanted == "past" && !upcomingIds.isEmpty && upcomingIds == Set(p.bookings.map { $0.id }) {
                let cutoff = Date().addingTimeInterval(-86_400)
                soonList = u.bookings.filter { isUpcoming($0, cutoff: cutoff) }
                pastList = u.bookings.filter { !isUpcoming($0, cutoff: cutoff) }
            } else {
                // Never twice: a booking already listed as upcoming is left out of the history.
                pastList = p.bookings.filter { !upcomingIds.contains($0.id) }
            }
            upcoming = soonList
                .sorted { ($0.neededAt == nil ? 1 : 0, $0.neededAt ?? "") < ($1.neededAt == nil ? 1 : 0, $1.neededAt ?? "") }
            past = pastList
                .sorted { ($0.neededAt ?? "") > ($1.neededAt ?? "") }
            error = nil
            loaded = true
        } catch { notice = nil; showFormError(error.localizedDescription, in: &self.error) }
    }

    private func setStatus(_ b: Booking, _ to: String) async {
        pendingCancel = nil
        guard !busy else { return }
        busy = true
        defer { busy = false }
        do {
            error = nil; notice = nil
            let r: BookingWriteReply = try await api.call("booking_status", body: ["booking_id": b.id, "to": to])
            let message = r.message ?? (to == "Cancelled" ? "Booking cancelled." : "Booking marked \(to).")
            // A booking that leaves this list takes the status menu VoiceOver was on with it:
            // VoiceOver is put on the booking that takes its place, then the result is said (an
            // announcement made before that focus move would be cut off by it).
            let leaves = (to == "Cancelled") != (newServer && view == "cancelled")
            let next = leaves ? bookingAfterRemoving(b) : nil
            await load()
            notice = message
            guard leaves else { announce(message); return }
            try? await Task.sleep(nanoseconds: 150_000_000) // let the reloaded rows appear first
            let shown = view == "cancelled" && newServer ? cancelled : upcoming + past
            let ids = Set(shown.map { $0.id })
            if let next, ids.contains(next) { focusedBooking = next }
            else if let first = shown.first { focusedBooking = first.id }
            else if loaded { focusedBooking = emptyFocus }
            announceAfterDismiss(message)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }

    /// The booking shown in a removed one's place: the next in its section, else the one
    /// before it, else the first booking in the other section.
    private func bookingAfterRemoving(_ b: Booking) -> Int? {
        let all = newServer && view == "cancelled" ? cancelled : upcoming + past
        let section = cancelled.contains(where: { $0.id == b.id }) ? cancelled : (upcoming.contains(where: { $0.id == b.id }) ? upcoming : past)
        let rest = section.filter { $0.id != b.id }
        guard let index = section.firstIndex(where: { $0.id == b.id }), !rest.isEmpty else {
            return all.first(where: { $0.id != b.id })?.id
        }
        return rest[min(index, rest.count - 1)].id
    }
}

struct BookingEditSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let onDone: (String) -> Void

    @State private var selectedClient: Int?
    // The first type when an organisation removed "Interpreter" from its list (4.5.0).
    @State private var serviceType = bookingTypes.contains("Interpreter") ? "Interpreter" : (bookingTypes.first ?? "Interpreter")
    /// 4.38.0: the website's labels for the service names (form_options' booking_type_labels).
    @State private var typeLabels: [String: String] = [:]
    /// 4.38.0: off leaves the date unset ("no date set"), as the website allows.
    @State private var whenKnown = true
    @State private var neededAt: Date
    /// The time the form opened with, so a changed time counts as something to keep.
    @State private var openedAt: Date
    @State private var duration = 60
    @State private var provider = ""
    @State private var location = ""
    @State private var cost = ""
    @State private var notes = ""
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false

    init(onDone: @escaping (String) -> Void) {
        self.onDone = onDone
        let now = Date()
        _neededAt = State(initialValue: now)
        _openedAt = State(initialValue: now)
    }

    private var hasChanges: Bool {
        selectedClient != nil || serviceType != "Interpreter" || neededAt != openedAt || duration != 60 || !whenKnown
            || [provider, location, cost, notes].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(inNavigationStack: true, error: error) {
                NewRecordClientPicker(title: "Client (required)", selection: $selectedClient, errorName: "Client")
                Picker("Service", selection: $serviceType) { ForEach(bookingTypes, id: \.self) { Text(typeLabels[$0] ?? $0).tag($0) } }
                if api.serverAtLeast(4, 38) {
                    Toggle("Date and time known", isOn: $whenKnown)
                }
                if whenKnown { DatePicker("When", selection: $neededAt) }
                // Up to a full day, as the server and the website allow.
                Stepper("Duration: \(duration) min", value: $duration, in: 0...1440, step: 15)
                    .voiceOverAdjustable("Duration", value: "\(duration) minutes", binding: $duration, in: 0...1440, step: 15)
                // Captions that stay visible once the fields hold text (a placeholder alone disappears).
                CaptionedField(label: "Provider / agency", text: $provider)
                CaptionedField(label: "Location", text: $location)
                CaptionedField(label: "Estimated cost in dollars (optional; up to 99,999,999.99)", text: $cost, prompt: "Estimated cost, like 125.50",
                               keyboard: .decimalPad, errorName: "Estimated cost")
                CaptionedField(label: "Notes", text: $notes, lines: 1...5)
            }
            .navigationTitle("New booking")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) {
                    // Not dimmed for a missing client: submit() says which field is needed.
                    Button("Create") { Task { await submit() } }
                        .disabled(busy)
                }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this new booking?",
                                 asking: $askingDiscard) { dismiss() }
            .task {
                guard api.serverAtLeast(4, 38), typeLabels.isEmpty,
                      let obj = try? await api.callDict("form_options"),
                      let labels = obj["booking_type_labels"] as? [String: String] else { return }
                typeLabels = labels
            }
        }
    }

    private func submit() async {
        guard let cid = selectedClient else { showFormError("Client: choose who the booking is for.", in: &error); return }
        guard let costText = bookingCostText(cost) else { showFormError(bookingCostHint, in: &error); return }
        busy = true; error = nil
        // Written in the server's timezone (America/Chicago) so the booking time isn't
        // skewed when the device is in another zone (read side renders in Central).
        var fields: [String: String] = [:]
        do {
            var body: [String: Any] = [
                "client_id": cid, "service_type": serviceType,
                "duration_min": duration, "provider": provider, "location": location, "cost": costText, "notes": notes,
            ]
            if whenKnown { body["needed_at"] = APIDate.dateTimeCentral(neededAt) }
            body["idem_key"] = idem.key(for: body)
            let r: BookingWriteReply = try await api.call("add_booking", body: body, refusal: { fields = refusalFields($0) })
            idem.answered()
            // The presenter confirms once the sheet has gone: closing it moves VoiceOver focus,
            // which would cut off an announcement made here.
            onDone(r.message ?? "Booking saved."); dismiss()
        } catch APIError.network {
            // The request may have arrived: it isn't known whether the booking was created, and
            // creating it again would book it twice.
            busy = false
            showFormError("The connection dropped before the server answered, so it isn't known whether this booking was created. Check the bookings list for \(serviceType)\(whenKnown ? " on \(neededAt.formatted(date: .abbreviated, time: .shortened))" : "") before creating it again.", in: &self.error)
        } catch {
            idem.failed(error); busy = false
            showFormError(fields.isEmpty ? IdemKey.message(for: error) : error.localizedDescription, in: &self.error,
                          field: fields["cost"] != nil ? "Estimated cost" : (fields["client_id"] != nil ? "Client" : nil))
        }
    }
}

// MARK: - Client choice for new records

/// The Client picker on the "new record" sheets (bookings, loans, reservations, device
/// requests, worksite assessments, incidents). It reads the client_choices list (through
/// API.clientPickerChoices) — every non-archived client the user may pick, scoped by the
/// server, with no 200-row cap — and when that list cannot be loaded it says so in words with
/// a retry, instead of leaving an empty picker and a dimmed Create button with no explanation.
struct NewRecordClientPicker: View {
    @EnvironmentObject var api: API
    var title = "Client"
    /// The "no client" choice: "Choose…" where a client is required, "None" where optional.
    var noneLabel = "Choose…"
    /// Closed clients are left out unless the record may concern one (an incident).
    var includeClosed = false
    /// The client a saved record is linked to now (4.29.0), named even when the list leaves it
    /// out (a closed client), so the picker never says "None" for a link that exists.
    var linkedId: Int? = nil
    var linkedName: String? = nil
    @Binding var selection: Int?
    /// The name errors about this choice start with ("Client: …"), shown and said with it.
    var errorName: String? = nil
    /// The clients to choose from when the screen's own reply already lists them (4.36.0: the
    /// privacy incident form's privacy_incident_choices); nil loads client_choices.
    var preset: [ClientRow]? = nil
    @State private var clients: [ClientRow] = []
    @State private var started = false
    @State private var loading = false
    @State private var loaded = false
    @State private var loadError: String?

    /// The chosen client's name, or the "no client" choice.
    private var chosenName: String {
        guard let selection else { return noneLabel }
        if let name = clients.first(where: { $0.id == selection })?.name { return name }
        if selection == linkedId, let linkedName, !linkedName.isEmpty { return linkedName }
        return noneLabel
    }

    var body: some View {
        // A searchable list on its own screen, not a menu: a menu of every client has no
        // search, so a VoiceOver user would swipe through each name to reach the one wanted.
        // The list opens only once the clients have loaded: opened earlier it would hold no
        // names and no word of why, and would sound like an empty caseload.
        NavigationLink {
            NewRecordClientList(title: title, noneLabel: noneLabel, clients: clients,
                                emptyText: emptyText, selection: $selection)
        } label: {
            LabeledContent(title, value: chosenName)
        }
        .disabled(!loaded)
        .modifier(OptionalErrorField(name: errorName))
        .modifier(RowID(id: errorName.map { FormFieldID(name: $0.lowercased()) }))
        .accessibilityHint(loaded ? "" : loadError != nil
                           ? "Unavailable: the client list could not be loaded. Use Try loading clients again, below."
                           : "Unavailable until the client list has loaded.")
        .onAppear {
            // Once per sheet, not each time the row scrolls back into view. An unstructured
            // task, not .task: .task is cancelled when this row disappears, and a cancelled
            // request would be reported as "could not reach the server" with no reload.
            guard !started else { return }
            started = true
            Task { await load() }
        }
        if loading {
            ProgressView("Loading clients…")
        } else if let loadError {
            ErrorBanner(message: loadError)
            Button { Task { await load() } } label: { Label("Try loading clients again", systemImage: "arrow.clockwise") }
        } else if loaded && clients.isEmpty {
            Text(emptyText)
        }
    }

    private var emptyText: String {
        includeClosed ? "No clients to choose from." : "No active clients to choose from."
    }

    private func load() async {
        loading = true; loadError = nil
        defer { loading = false }
        do {
            if let preset {
                clients = preset
            } else if includeClosed {
                // Closed (not archived) clients stay choosable here, as on the website.
                let r: ClientsResponse
                do { r = try await api.call("client_choices") }
                catch APIError.server(let message) where message == "Unknown action." { r = try await api.call("clients") }
                clients = r.clients
            } else {
                clients = try await api.clientPickerChoices()
            }
            loaded = true
        } catch {
            showFormError("Could not load the client list. \(error.localizedDescription)", in: &loadError)
        }
    }
}

/// The client list behind NewRecordClientPicker: searchable by name, and choosing a client
/// returns to the form.
private struct NewRecordClientList: View {
    @Environment(\.dismiss) private var dismiss
    let title: String
    let noneLabel: String
    let clients: [ClientRow]
    /// Said in the list itself when there is no client to choose.
    let emptyText: String
    @Binding var selection: Int?
    @State private var query = ""

    private var shown: [ClientRow] {
        let q = query.trimmingCharacters(in: .whitespaces)
        return q.isEmpty ? clients : clients.filter { $0.name.localizedCaseInsensitiveContains(q) }
    }

    var body: some View {
        List {
            if query.isEmpty { choice(noneLabel, id: nil) }
            if query.isEmpty && clients.isEmpty { Text(emptyText) }
            ForEach(shown) { c in choice(c.name, id: c.id) }
            if !query.isEmpty && shown.isEmpty { Text("No clients match \u{201C}\(query)\u{201D}.") }
        }
        .navigationTitle(title)
        .navigationBarTitleDisplayMode(.inline)
        .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Search clients by name")
        .onChange(of: query) { typed in
            // Said once typing pauses, not on every keystroke.
            Task {
                try? await Task.sleep(nanoseconds: 500_000_000)
                guard typed == query, !typed.isEmpty else { return }
                let n = shown.count
                announce(n == 0 ? "No clients match." : "\(n) client\(n == 1 ? "" : "s") match.")
            }
        }
    }

    private func choice(_ name: String, id: Int?) -> some View {
        Button {
            selection = id
            dismiss()
        } label: {
            HStack {
                Text(name).foregroundStyle(.primary)
                Spacer()
                if selection == id { Image(systemName: "checkmark").accessibilityHidden(true) }
            }
        }
        .accessibilityAddTraits(selection == id ? .isSelected : [])
    }
}
