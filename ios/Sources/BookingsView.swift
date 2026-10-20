import SwiftUI

struct Booking: Decodable, Identifiable {
    let id: Int; let serviceType: String; let provider: String?; let neededAt: String?
    let durationMin: Int; let location: String?; let status: String; let cost: Double?
    let clientName: String; let clientId: Int
    /// Access needs, where to meet (nil from a server that does not send it).
    let notes: String?
}
struct BookingsResponse: Decodable { let bookings: [Booking] }

var bookingTypes: [String] { FormOptions.list("booking_types", ["Interpreter", "CART captioning", "Transportation", "Note-taker", "Other"]) }
let bookingStatuses = ["Requested", "Confirmed", "Completed", "Cancelled"]

struct BookingsView: View {
    @EnvironmentObject var api: API
    @State private var upcoming: [Booking] = []
    @State private var past: [Booking] = []
    @State private var loaded = false
    @State private var error: String?
    @State private var showNew = false
    @State private var pendingCancel: Booking?
    /// The sheet's confirmation, spoken once the sheet has closed (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// The booking VoiceOver is put on after a cancelled one leaves the list (emptyFocus: the
    /// "No upcoming bookings" line when none are left).
    @AccessibilityFocusState private var focusedBooking: Int?
    private let emptyFocus = -1

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden).id("bookingsError") }
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
            // The banner is the list's first row: a failure from a row further down would
            // otherwise leave it off screen, where it is neither seen nor announced.
            .onChange(of: error) { if $0 != nil { proxy.scrollTo("bookingsError", anchor: .top) } }
        }
        .navigationTitle("Bookings")
        .refreshable { await load() }
        .task { await load() }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add booking") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) { BookingEditSheet { sheetResult = "Booking saved."; Task { await load() } } }
        // A cancelled booking leaves every list for good, so it takes a second, deliberate step.
        .confirmationDialog(
            "Cancel the \(pendingCancel?.serviceType ?? "") booking for \(pendingCancel?.clientName ?? "")? Cancelled bookings are removed from this list.",
            isPresented: Binding(get: { pendingCancel != nil }, set: { if !$0 { pendingCancel = nil } }),
            titleVisibility: .visible
        ) {
            Button("Cancel booking", role: .destructive) {
                if let b = pendingCancel { Task { await setStatus(b, "Cancelled") } }
            }
            Button("Keep booking", role: .cancel) { pendingCancel = nil }
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    private func row(_ b: Booking) -> some View {
        HStack(alignment: .top) {
            // Caption text in the primary colour: grey secondary caption text is about 3.4:1.
            VStack(alignment: .leading, spacing: 3) {
                Text(b.serviceType).font(.body.weight(.semibold))
                Text(b.clientName).font(.caption)
                if let n = b.neededAt { Text(formatDateTime(n)).font(.caption) }
                if let p = b.provider, !p.isEmpty { Text(p).font(.caption) }
                // As on the website: the booking's notes are shown with it, named.
                if let n = b.notes, !n.isEmpty { Text("Notes: \(n)").font(.caption) }
            }
            Spacer()
            Menu {
                ForEach(bookingStatuses, id: \.self) { s in
                    Button {
                        if s == "Cancelled" { pendingCancel = b } else { Task { await setStatus(b, s) } }
                    } label: {
                        if s == b.status { Label(s, systemImage: "checkmark") } else { Text(s) }
                    }
                }
            } label: {
                // Primary text on the brand tint (brand text on its own tint is about 6.4:1 in
                // dark mode), inside a 44-point target around the small capsule.
                Text(b.status).font(.caption.weight(.semibold))
                    .padding(.horizontal, 8).padding(.vertical, 3)
                    .background(Color.brand.opacity(0.15), in: Capsule()).foregroundStyle(.primary)
                    .frame(minWidth: 44, minHeight: 44)
                    .contentShape(Rectangle())
            }
            .accessibilityLabel("Status of \(b.serviceType) for \(b.clientName)")
            .accessibilityValue(b.status)
            .accessibilityHint("Opens a menu to change the status.")
            .accessibilityInputLabels([b.status, "Status of \(b.serviceType) for \(b.clientName)"])
        }
        .padding(.vertical, 2)
        // VoiceOver: one stop per booking, its status included; each other status from the menu
        // is an action on the Actions rotor (Cancel booking is still confirmed first).
        .accessibilityElement(children: VoiceOverRows.children)
        .accessibilityLabel(bookingSpokenLabel(b))
        .accessibilityAction { announceRowActions() }
        .accessibilityActions {
            ForEach(bookingStatuses.filter { $0 != b.status }, id: \.self) { s in
                Button(s == "Cancelled" ? "Cancel booking" : "Mark \(s)") {
                    if s == "Cancelled" { pendingCancel = b } else { Task { await setStatus(b, s) } }
                }
            }
        }
        .accessibilityFocused($focusedBooking, equals: b.id)
    }

    /// What VoiceOver reads for a booking row, in the order it is shown, then its status.
    private func bookingSpokenLabel(_ b: Booking) -> String {
        var parts = [b.serviceType, b.clientName]
        if let n = b.neededAt { parts.append(formatDateTime(n)) }
        if let p = b.provider, !p.isEmpty { parts.append(p) }
        if let n = b.notes, !n.isEmpty { parts.append("Notes: \(n)") }
        parts.append("Status: \(b.status)")
        return parts.joined(separator: ", ")
    }

    /// Needed within the last day or later (or not dated yet) counts as upcoming, as on the website.
    /// Used only for an older server that ignores ?upcoming / ?past and sends every booking.
    private func isUpcoming(_ b: Booking, cutoff: Date) -> Bool {
        guard let raw = b.neededAt, let when = APIDate.parseDateTimeCentral(raw) else { return true }
        return when >= cutoff
    }

    private func load() async {
        do {
            error = nil
            // Upcoming and past are asked for separately so a long history can never push
            // the bookings that are still to come off the end of the list.
            async let soon: BookingsResponse = api.call("bookings", query: ["upcoming": "1"])
            async let before: BookingsResponse = api.call("bookings", query: ["past": "1"])
            let (u, p) = try await (soon, before)
            // The server's own split is used as sent: re-cutting it on the phone's clock could
            // leave a booking near the boundary in neither list (clock skew, daylight saving).
            // Only when the two answers are the same bookings (an older server that sends
            // everything either way) is the list split here instead. A booking in both answers
            // otherwise only straddled the boundary between the two queries: it stays upcoming,
            // and the rest of the history is kept.
            let upcomingIds = Set(u.bookings.map { $0.id })
            var soonList = u.bookings, pastList = p.bookings
            if !upcomingIds.isEmpty && upcomingIds == Set(p.bookings.map { $0.id }) {
                let cutoff = Date().addingTimeInterval(-86_400)
                soonList = u.bookings.filter { isUpcoming($0, cutoff: cutoff) }
                pastList = u.bookings.filter { !isUpcoming($0, cutoff: cutoff) }
            } else {
                pastList = p.bookings.filter { !upcomingIds.contains($0.id) }
            }
            upcoming = soonList
                .sorted { ($0.neededAt == nil ? 1 : 0, $0.neededAt ?? "") < ($1.neededAt == nil ? 1 : 0, $1.neededAt ?? "") }
            past = pastList
                .sorted { ($0.neededAt ?? "") > ($1.neededAt ?? "") }
            loaded = true
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    private func setStatus(_ b: Booking, _ to: String) async {
        pendingCancel = nil
        do {
            error = nil
            let _: OKResponse = try await api.call("booking_status", body: ["booking_id": b.id, "to": to])
            guard to == "Cancelled" else {
                announce("Booking marked \(to).")
                await load()
                return
            }
            // A cancelled booking leaves the list, taking the status menu VoiceOver was on with
            // it: put VoiceOver on the booking that takes its place, then confirm (an
            // announcement made before that focus move would be cut off by it).
            let next = bookingAfterRemoving(b)
            await load()
            try? await Task.sleep(nanoseconds: 150_000_000) // let the reloaded rows appear first
            let ids = Set((upcoming + past).map { $0.id })
            if let next, ids.contains(next) { focusedBooking = next }
            else if let first = upcoming.first ?? past.first { focusedBooking = first.id }
            else if loaded { focusedBooking = emptyFocus }
            announceAfterDismiss("Booking cancelled.")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }

    /// The booking shown in a removed one's place: the next in its section, else the one
    /// before it, else the first booking in the other section.
    private func bookingAfterRemoving(_ b: Booking) -> Int? {
        let section = upcoming.contains(where: { $0.id == b.id }) ? upcoming : past
        let rest = section.filter { $0.id != b.id }
        guard let index = section.firstIndex(where: { $0.id == b.id }), !rest.isEmpty else {
            return (upcoming + past).first(where: { $0.id != b.id })?.id
        }
        return rest[min(index, rest.count - 1)].id
    }
}

struct BookingEditSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void

    @State private var selectedClient: Int?
    // The first type when an organisation removed "Interpreter" from its list (4.5.0).
    @State private var serviceType = bookingTypes.contains("Interpreter") ? "Interpreter" : (bookingTypes.first ?? "Interpreter")
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

    init(onDone: @escaping () -> Void) {
        self.onDone = onDone
        let now = Date()
        _neededAt = State(initialValue: now)
        _openedAt = State(initialValue: now)
    }

    private var hasChanges: Bool {
        selectedClient != nil || serviceType != "Interpreter" || neededAt != openedAt || duration != 60
            || [provider, location, cost, notes].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(inNavigationStack: true, error: error) {
                NewRecordClientPicker(title: "Client (required)", selection: $selectedClient, errorName: "Client")
                Picker("Service", selection: $serviceType) { ForEach(bookingTypes, id: \.self) { Text($0) } }
                DatePicker("When", selection: $neededAt)
                // Up to a full day, as the server and the website allow.
                Stepper("Duration: \(duration) min", value: $duration, in: 0...1440, step: 15)
                    .voiceOverAdjustable("Duration", value: "\(duration) minutes", binding: $duration, in: 0...1440, step: 15)
                // Captions that stay visible once the fields hold text (a placeholder alone disappears).
                CaptionedField(label: "Provider / agency", text: $provider)
                CaptionedField(label: "Location", text: $location)
                CaptionedField(label: "Estimated cost", text: $cost, prompt: "Estimated cost, like 125.00", keyboard: .decimalPad, errorName: "Estimated cost")
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
        }
    }

    private func submit() async {
        guard let cid = selectedClient else { showFormError("Client: choose who the booking is for.", in: &error); return }
        guard let costText = checkedAmount(cost) else { showFormError(amountFormatMessage("Estimated cost"), in: &error); return }
        busy = true; error = nil
        // Written in the server's timezone (America/Chicago) so the booking time isn't
        // skewed when the device is in another zone (read side renders in Central).
        do {
            var body: [String: Any] = [
                "client_id": cid, "service_type": serviceType, "needed_at": APIDate.dateTimeCentral(neededAt),
                "duration_min": duration, "provider": provider, "location": location, "cost": costText, "notes": notes,
            ]
            body["idem_key"] = idem.key(for: body)
            let _: OKResponse = try await api.call("add_booking", body: body)
            idem.answered()
            // The presenter confirms once the sheet has gone: closing it moves VoiceOver focus,
            // which would cut off an announcement made here.
            onDone(); dismiss()
        } catch APIError.network {
            // The request may have arrived: it isn't known whether the booking was created, and
            // creating it again would book it twice.
            busy = false
            showFormError("The connection dropped before the server answered, so it isn't known whether this booking was created. Check the bookings list for \(serviceType) on \(neededAt.formatted(date: .abbreviated, time: .shortened)) before creating it again.", in: &self.error)
        } catch { idem.failed(error); busy = false; showFormError(IdemKey.message(for: error), in: &self.error) }
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
            if includeClosed {
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
