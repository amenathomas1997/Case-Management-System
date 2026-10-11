import SwiftUI

struct EquipmentItem: Decodable, Identifiable {
    let id: Int
    let name: String
    let category: String
    let serial: String?
    let itemCondition: String
    let status: String
    let loanId: Int?
    let loanedAt: String?
    let dueDate: String?
    let borrower: String?
    let borrowerId: Int?
    let overdue: Bool
    /// A reservation holds the item (for any caseload). The client is not named, so the app
    /// can only warn that lending it to someone else moves that hold to the waitlist.
    let held: Bool
    private enum CodingKeys: String, CodingKey { case id, name, category, serial, itemCondition, status, loanId, loanedAt, dueDate, borrower, borrowerId, overdue, held }
    // Tolerant decode so one malformed item can't blank the whole Equipment list.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        category = (try? c.decode(String.self, forKey: .category)) ?? ""
        serial = try? c.decodeIfPresent(String.self, forKey: .serial)
        itemCondition = (try? c.decode(String.self, forKey: .itemCondition)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        loanId = try? c.decodeIfPresent(Int.self, forKey: .loanId)
        loanedAt = try? c.decodeIfPresent(String.self, forKey: .loanedAt)
        dueDate = try? c.decodeIfPresent(String.self, forKey: .dueDate)
        borrower = try? c.decodeIfPresent(String.self, forKey: .borrower)
        borrowerId = try? c.decodeIfPresent(Int.self, forKey: .borrowerId)
        overdue = (try? c.decode(Bool.self, forKey: .overdue)) ?? false
        held = (try? c.decode(Bool.self, forKey: .held)) ?? false
    }
}

struct EquipmentResponse: Decodable { let equipment: [EquipmentItem] }

/// An equipment row with its list identity (see EquipmentView.keyedItems).
private struct KeyedEquipmentItem { let key: String; let item: EquipmentItem }

struct EquipmentView: View {
    @EnvironmentObject var api: API
    @State private var items: [EquipmentItem] = []
    /// Set once the list has loaded, so "No equipment tracked yet." is never claimed before then.
    @State private var loaded = false
    @State private var error: String?
    @State private var confirmation: String?
    @State private var loaningItem: EquipmentItem?
    @State private var showingAdd = false
    /// Loans whose return is being sent, so a second tap cannot send it again.
    @State private var returning: Set<Int> = []
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// The loan whose return is waiting for confirmation: a return cannot be undone.
    @State private var pendingReturn: EquipmentItem?
    /// After a return, VoiceOver is put back on the item, whose Mark returned button is gone
    /// (errorFocus: the error banner, after a failure).
    @AccessibilityFocusState private var focusedItem: Int?
    private let errorFocus = -1
    private let errorRow = "equipmentError"

    var body: some View {
        NavigationStack {
            ScrollViewReader { proxy in
                List {
                    if let error {
                        ErrorBanner(message: error).listRowSeparator(.hidden)
                            .id(errorRow)
                            .accessibilityFocused($focusedItem, equals: errorFocus)
                        // A button as well as pull-to-refresh, which needs a drag.
                        if items.isEmpty {
                            Button { Task { await load() } } label: { Label("Try loading equipment again", systemImage: "arrow.clockwise") }
                        }
                    }
                    if let confirmation {
                        Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
                            .listRowSeparator(.hidden)
                    }
                    NavigationLink { ReservationsView() } label: {
                        Label("Reservations & waitlist", systemImage: "calendar.badge.clock")
                    }
                    if !loaded && error == nil {
                        ProgressView("Loading equipment…")
                    } else if loaded && items.isEmpty && error == nil {
                        Text("No equipment tracked yet. Use Add equipment to add an item.")
                            .foregroundStyle(Color.secondaryText)
                    }
                    ForEach(keyedItems, id: \.key) { entry in
                        let item = entry.item
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                // A heading, so the rotor moves from item to item.
                                Text(item.name).font(.body.weight(.semibold))
                                    .accessibilityAddTraits(.isHeader)
                                    .accessibilityFocused($focusedItem, equals: item.id)
                                Spacer()
                                Text(item.status)
                                    .font(.caption.weight(.semibold))
                                    .padding(.horizontal, 8).padding(.vertical, 3)
                                    .background((item.status == "Available" ? Color.brandTeal : Color.brand).opacity(0.15), in: Capsule())
                                    .foregroundStyle(item.status == "Available" ? Color.brandTeal : Color.brand)
                            }
                            // Detail lines in the 7:1 secondary-text colour (system secondary is about 3.4:1).
                            if let serial = item.serial, !serial.isEmpty {
                                Text("Serial \(serial) · Condition: \(item.itemCondition)").font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            if item.held && item.status == "Available" {
                                Label("Held by a reservation", systemImage: "calendar.badge.clock")
                                    .font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            if item.borrower != nil {
                                // Overdue is said in words and with an icon, not only shown in red; the
                                // red is Color.alertRed (system red text was 3.6:1).
                                HStack(alignment: .firstTextBaseline, spacing: 4) {
                                    if item.overdue {
                                        Image(systemName: "exclamationmark.triangle.fill").accessibilityHidden(true)
                                    }
                                    Text(loanLine(item))
                                        .font(.callout)
                                        .fontWeight(item.overdue ? .semibold : .regular)
                                }
                                .foregroundStyle(item.overdue ? Color.alertRed : Color.secondaryText)
                            }
                            // A loan outside the user's caseload is masked (no borrower id) and the
                            // server refuses its return, so no button is offered for it. Any open loan
                            // can be returned, whatever the item's status says. Both buttons are large
                            // controls (44 points or more) and keep their visible words speakable.
                            if let loanId = item.loanId, item.borrowerId != nil {
                                Button {
                                    pendingReturn = item
                                } label: {
                                    Label("Mark returned", systemImage: "arrow.uturn.backward.circle")
                                }
                                .buttonStyle(.bordered)
                                .controlSize(.large)
                                .padding(.top, 4)
                                .disabled(returning.contains(loanId))
                                .accessibilityLabel("Mark \(item.name) returned")
                                .accessibilityInputLabels(["Mark returned", "Mark \(item.name) returned"])
                            } else if item.status == "Available" {
                                Button {
                                    loaningItem = item
                                } label: {
                                    Label("Loan out", systemImage: "arrow.up.forward.circle")
                                }
                                .buttonStyle(.bordered)
                                .controlSize(.large)
                                .padding(.top, 4)
                                .accessibilityLabel("Loan \(item.name) out")
                                .accessibilityInputLabels(["Loan out", "Loan \(item.name) out"])
                            }
                            NavigationLink { EquipmentDetailView(itemId: item.id, itemName: item.name) } label: {
                                Text("Service & details").font(.body)
                                    .frame(minHeight: 44, alignment: .leading)
                                    .contentShape(Rectangle())
                            }
                            // Names the item, so the links in the list are told apart.
                            .accessibilityLabel("Service and details for \(item.name)")
                            .accessibilityInputLabels(["Service & details", "Service and details for \(item.name)"])
                        }
                        .padding(.vertical, 4)
                        .accessibilityElement(children: .contain)
                    }
                }
                // The banner is the list's first row: a failed return from an item further down
                // would otherwise leave it off screen, where a List never draws it, so it was
                // neither seen nor heard. Scroll it into view once it exists, then put VoiceOver on it.
                .onChange(of: error) { message in
                    guard message != nil else { return }
                    DispatchQueue.main.async { withAnimation { proxy.scrollTo(errorRow, anchor: .top) } }
                    Task { @MainActor in
                        try? await Task.sleep(nanoseconds: 400_000_000)
                        focusedItem = errorFocus
                    }
                }
            }
            .navigationTitle("Equipment")
            .refreshable { confirmation = nil; await load() }
            .task { await load() }
            .toolbar { ToolbarItem(placement: .primaryAction) { Button { showingAdd = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add equipment") } }
            .sheet(item: $loaningItem, onDismiss: announceSheetResult) { item in
                LoanSheet(item: item) { message in
                    error = nil
                    confirmation = message
                    sheetResult = message
                    Task { await load() }
                }
            }
            .sheet(isPresented: $showingAdd, onDismiss: announceSheetResult) {
                AddEquipmentSheet { error = nil; confirmation = "Item added."; sheetResult = "Item added."; Task { await load() } }
            }
            // A return closes the loan for good (lending it again starts a new loan with new
            // dates), so it takes a second, deliberate step.
            .confirmationDialog(
                "Mark \(pendingReturn?.name ?? "this item") returned\(pendingReturn?.borrower.map { " from \($0)" } ?? "")?",
                isPresented: Binding(get: { pendingReturn != nil }, set: { if !$0 { pendingReturn = nil } }),
                titleVisibility: .visible
            ) {
                Button("Mark returned") {
                    if let item = pendingReturn { Task { await returnItem(item) } }
                    pendingReturn = nil
                }
                Button("Keep on loan", role: .cancel) { pendingReturn = nil }
            }
        }
    }

    /// List identity: the equipment id, so a return or a loan updates the item's row in place
    /// and VoiceOver keeps its place. Only an item with more than one open loan (older data),
    /// which appears once per loan, adds the loan id to stay unique; a loan outside the user's
    /// caseload comes without its id, so those rows are told apart by their place instead.
    private var keyedItems: [KeyedEquipmentItem] {
        var count: [Int: Int] = [:]
        for i in items { count[i.id, default: 0] += 1 }
        var masked: [Int: Int] = [:]
        return items.map { item in
            guard count[item.id, default: 0] > 1 else { return KeyedEquipmentItem(key: "\(item.id)", item: item) }
            if let loanId = item.loanId { return KeyedEquipmentItem(key: "\(item.id)-\(loanId)", item: item) }
            let n = masked[item.id, default: 0]
            masked[item.id] = n + 1
            return KeyedEquipmentItem(key: "\(item.id)-masked-\(n)", item: item)
        }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    private func load() async {
        do {
            error = nil
            let response: EquipmentResponse = try await api.call("equipment")
            items = response.equipment
            loaded = true
        } catch {
            self.error = error.localizedDescription
        }
    }

    private func loanLine(_ item: EquipmentItem) -> String {
        let borrower = item.borrower ?? ""
        let who = item.borrowerId == nil ? borrower : "With \(borrower)"
        guard let due = item.dueDate else { return item.overdue ? "\(who) · Overdue" : who }
        return item.overdue ? "\(who) · Overdue: was due back \(formatDate(due))" : "\(who) · due back \(formatDate(due))"
    }

    private func returnItem(_ item: EquipmentItem) async {
        guard let loanId = item.loanId, !returning.contains(loanId) else { return }
        returning.insert(loanId)
        defer { returning.remove(loanId) }
        do {
            error = nil; confirmation = nil
            let _: OKResponse = try await api.call("return_equipment", body: ["loan_id": loanId])
            let message = "\(item.name) marked returned."
            confirmation = message
            await load()
            // Back on the item once its row has been rebuilt, then the result, so the focus move
            // does not cut the announcement off.
            focusedItem = item.id
            announceAfterDismiss(message)
        } catch {
            self.error = error.localizedDescription
        }
    }
}

struct LoanSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let item: EquipmentItem
    let onDone: (String) -> Void

    @State private var selectedClient: Int?
    @State private var dueDate = Calendar.current.date(byAdding: .day, value: 60, to: Date()) ?? Date()
    @State private var notes = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                LabeledContent("Item", value: item.name)
                if item.held {
                    // As on the website's scan page; the reserved client is not named here.
                    Text("This item is held by a reservation. Lending it to that client fulfils the hold; lending it to anyone else moves the hold to the waitlist and notifies the staff member who made it.")
                        .font(.callout)
                }
                NewRecordClientPicker(title: "Client (required)", selection: $selectedClient)
                DatePicker("Return due", selection: $dueDate, displayedComponents: .date)
                TextField("Notes (optional)", text: $notes)
            }
            .navigationTitle("Loan out")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Loan") { Task { await submit() } }
                        .disabled(busy || selectedClient == nil)
                        .accessibilityHint(selectedClient == nil ? "Choose a client first." : "")
                }
            }
        }
    }

    private func submit() async {
        guard let clientId = selectedClient else { return }
        busy = true; error = nil
        do {
            let _: OKResponse = try await api.call("loan_equipment", body: [
                "equipment_id": item.id, "client_id": clientId,
                "due_date": APIDate.day(dueDate), "notes": notes,
            ])
            onDone("\(item.name) loaned out.")
            dismiss()
        } catch {
            self.error = error.localizedDescription
            busy = false
        }
    }
}

// MARK: - Equipment lifecycle: service history, reservations, add item

struct MaintenanceEntry: Decodable, Identifiable {
    var id: String { "\(serviceDate)-\(serviceType)-\(cost ?? 0)" }
    let serviceDate: String; let serviceType: String; let notes: String?; let cost: Double?; let byName: String?
}
struct EquipmentFull: Decodable {
    let id: Int; let name: String; let category: String; let serial: String?; let itemCondition: String
    let status: String; let lastServiceDate: String?; let maintenanceIntervalDays: Int?
    let value: Double?; let purchaseDate: String?; let usefulLifeMonths: Int?; let bookValue: Double?
}
struct EquipmentDetailResponse: Decodable { let item: EquipmentFull; let maintenance: [MaintenanceEntry] }

let maintenanceTypes = ["Maintenance", "Calibration", "Repair", "Inspection", "Cleaning", "Other"]
let equipmentCategoriesApp = ["Braille Display", "Screen Reader License", "Magnifier", "Tablet/Phone", "Computer", "Keyboard/Input", "Audio", "Other"]
let equipmentConditions = ["New", "Good", "Fair", "Needs Repair"]

/// A service interval typed as whole days from 0 to 3650, the range the server and the website
/// accept; nil when the text is not one (nothing is rounded or clamped).
private func parsedIntervalDays(_ raw: String) -> Int? {
    let t = raw.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !t.isEmpty, t.count <= 4, t.allSatisfy({ ("0"..."9").contains($0) }), let n = Int(t), n <= 3650 else { return nil }
    return n
}
private let intervalFormatMessage = "Service interval: enter a whole number of days from 0 to 3650, like 7, 14 or 365 (0 means no service schedule)."

struct EquipmentDetailView: View {
    @EnvironmentObject var api: API
    let itemId: Int
    let itemName: String
    @State private var data: EquipmentDetailResponse?
    @State private var error: String?
    /// The service interval as typed, in whole days (0 turns the schedule off).
    @State private var intervalText = ""
    /// The interval last read from or saved to the server, so a reload only replaces the field
    /// when it holds no unsaved change (nil until the first load).
    @State private var savedIntervalText: String?
    @State private var savingInterval = false
    /// Says the interval was saved; cleared as soon as the interval is changed again.
    @State private var intervalNote: String?
    /// Why the interval was not saved, shown and spoken beside the field (the list's error
    /// banner is usually scrolled off screen here, where it is neither drawn nor heard).
    @State private var intervalError: String?
    @State private var logging = false
    @State private var reserving = false
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if data == nil && error == nil { ProgressView("Loading details…") }
            if let d = data {
                Section {
                    LabeledContent("Status", value: d.item.status)
                    LabeledContent("Condition", value: d.item.itemCondition)
                    if let s = d.item.serial, !s.isEmpty { LabeledContent("Serial", value: s) }
                    if let l = d.item.lastServiceDate { LabeledContent("Last service", value: formatDate(l)) }
                }
                if d.item.value != nil || d.item.bookValue != nil {
                    Section("Asset value") {
                        if let v = d.item.value { LabeledContent("Purchase value", value: String(format: "$%.2f", v)) }
                        if let p = d.item.purchaseDate, !p.isEmpty { LabeledContent("Purchased", value: formatDate(p)) }
                        if let life = d.item.usefulLifeMonths {
                            LabeledContent("Useful life", value: life <= 0 ? "Non-depreciating" : "\(life) months")
                        }
                        if let bv = d.item.bookValue {
                            LabeledContent("Current book value", value: String(format: "$%.2f", bv))
                        }
                    }
                }
                Section("Maintenance schedule") {
                    // Any whole number of days, as the website and server accept (a weekly clean,
                    // a yearly calibration), typed rather than stepped in fixed amounts.
                    KeyboardCaptionedField(label: "Service every (days, 0 for no schedule)",
                                           text: Binding(get: { intervalText }, set: { intervalText = $0; intervalNote = nil; intervalError = nil }),
                                           keyboard: .numberPad)
                        .disabled(savingInterval)
                        // The error, while there is one, is read with the field it is about.
                        .accessibilityHint(intervalError.map { "Error: \($0)" } ?? "Whole days from 0 to 3650. A change is kept only after Save interval.")
                    Button(savingInterval ? "Saving interval…" : "Save interval") { Task { await saveInterval() } }
                        .disabled(savingInterval)
                    if let intervalError {
                        // Said in words and with an icon, not only shown in red.
                        Label { Text("Error: \(intervalError)") } icon: { Image(systemName: "exclamationmark.triangle.fill").accessibilityHidden(true) }
                            .font(.callout.weight(.medium)).foregroundStyle(Color.alertRed)
                    }
                    if let intervalNote { Text(intervalNote).font(.callout.weight(.medium)) }
                }
                Section {
                    Button { logging = true } label: { Label("Log a service", systemImage: "wrench.and.screwdriver") }
                    Button { reserving = true } label: { Label("Reserve for a client", systemImage: "calendar.badge.plus") }
                }
                Section("Service history") {
                    if d.maintenance.isEmpty { Text("No service logged.").foregroundStyle(Color.secondaryText) }
                    ForEach(Array(d.maintenance.enumerated()), id: \.offset) { _, m in
                        VStack(alignment: .leading, spacing: 2) {
                            Text("\(m.serviceType) · \(formatDate(m.serviceDate))").font(.body.weight(.semibold))
                            if let c = m.cost { Text("Cost \(money(c))").font(.caption).foregroundStyle(Color.secondaryText) }
                            if let n = m.notes, !n.isEmpty { Text(n).font(.callout) }
                        }
                    }
                }
            }
        }
        .navigationTitle(itemName)
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        .sheet(isPresented: $logging, onDismiss: announceSheetResult) { LogServiceSheet(itemId: itemId) { message in sheetResult = message; Task { await load() } } }
        .sheet(isPresented: $reserving, onDismiss: announceSheetResult) { ReservationSheet(fixedItemId: itemId) { message in sheetResult = message; Task { await load() } } }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do {
            error = nil
            let d: EquipmentDetailResponse = try await api.call("equipment_detail", query: ["id": String(itemId)])
            data = d
            // A reload after a sheet or a save keeps an interval typed but not yet saved.
            let server = String(d.item.maintenanceIntervalDays ?? 0)
            if savedIntervalText == nil || intervalText == savedIntervalText { intervalText = server }
            savedIntervalText = server
        }
        catch { self.error = error.localizedDescription }
    }
    private func saveInterval() async {
        // Each refusal is spoken once, here (the line beside the field does not announce itself).
        guard let days = parsedIntervalDays(intervalText) else {
            intervalNote = nil; intervalError = intervalFormatMessage; announce(intervalFormatMessage); return
        }
        savingInterval = true; intervalError = nil; intervalNote = nil
        do {
            let _: OKResponse = try await api.call("set_equipment_interval", body: ["equipment_id": itemId, "interval": days])
            intervalText = String(days); savedIntervalText = intervalText
            // Said as soon as the save succeeds, so a failed refresh below is not heard as a failed save.
            let message = days == 0 ? "Service schedule turned off." : "Service interval saved: every \(days) day\(days == 1 ? "" : "s")."
            intervalNote = message; announce(message)
            savingInterval = false
            await load()
        } catch {
            let message = "Service interval not saved. \(error.localizedDescription)"
            intervalError = message; announce(message); savingInterval = false
        }
    }
}

struct LogServiceSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let itemId: Int; let onDone: (String) -> Void
    @State private var type = "Maintenance"; @State private var date = Date(); @State private var notes = ""; @State private var cost = ""
    @State private var busy = false; @State private var error: String?
    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                Picker("Type", selection: $type) { ForEach(maintenanceTypes, id: \.self) { Text($0) } }
                // A service cannot be logged in the future: a later date would push "last
                // serviced" ahead and hide the item from the service-due list.
                DatePicker("Date", selection: $date, in: ...Date(), displayedComponents: .date)
                // Visible names that stay once a field holds text.
                CaptionedField(label: "Notes", text: $notes)
                KeyboardCaptionedField(label: "Cost (digits, like 12.50)", text: $cost, keyboard: .decimalPad)
            }
            .navigationTitle("Log service").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) { Button("Save") { Task { await submit() } }.disabled(busy) }
            }
        }
    }
    private func submit() async {
        guard let costText = checkedAmount(cost) else { showFormError(amountFormatMessage("Cost"), in: &error); return }
        busy = true; error = nil
        do { let _: OKResponse = try await api.call("equipment_service", body: ["equipment_id": itemId, "service_type": type, "service_date": APIDate.day(date), "notes": notes, "cost": costText]); onDone("Service logged."); dismiss() }
        catch { self.error = error.localizedDescription; busy = false }
    }
}

struct Reservation: Decodable, Identifiable {
    let id: Int; let status: String; let neededFrom: String?; let neededUntil: String?; let notes: String?
    let itemName: String; let itemStatus: String; let clientName: String; let clientId: Int
}
struct ReservationsResponse: Decodable { let reservations: [Reservation] }

struct ReservationsView: View {
    @EnvironmentObject var api: API
    @State private var reservations: [Reservation] = []
    /// Set once the list has loaded, so "No active reservations." is never claimed before then.
    @State private var loaded = false
    @State private var error: String?
    @State private var adding = false
    @State private var pendingCancel: Reservation?
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// After a cancel takes a reservation off the list, VoiceOver is put on the one that took
    /// its place (-1: the empty-list text; errorFocus: the error banner, after a failure).
    @AccessibilityFocusState private var focusedReservation: Int?
    private let errorFocus = -2
    private let errorRow = "reservationsError"
    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden)
                        .id(errorRow)
                        .accessibilityFocused($focusedReservation, equals: errorFocus)
                    // A button as well as pull-to-refresh, which needs a drag.
                    if reservations.isEmpty {
                        Button { Task { await load() } } label: { Label("Try loading reservations again", systemImage: "arrow.clockwise") }
                    }
                }
                if !loaded && error == nil { ProgressView("Loading reservations…") }
                if loaded && reservations.isEmpty && error == nil {
                    Text("No active reservations.").foregroundStyle(Color.secondaryText)
                        .accessibilityFocused($focusedReservation, equals: -1)
                }
                ForEach(reservations) { r in
                    VStack(alignment: .leading, spacing: 2) {
                        Text(r.itemName).font(.body.weight(.semibold))
                            .accessibilityAddTraits(.isHeader)
                            .accessibilityFocused($focusedReservation, equals: r.id)
                        // The 7:1 secondary-text colour (system secondary is about 3.4:1).
                        Text("\(r.clientName) · \(r.status)").font(.caption).foregroundStyle(Color.secondaryText)
                        if let f = r.neededFrom { Text("Needed from \(formatDate(f))").font(.caption).foregroundStyle(Color.secondaryText) }
                        // A visible single-tap way to cancel, as well as the swipe action below.
                        Button { pendingCancel = r } label: { Label("Cancel reservation", systemImage: "xmark.circle") }
                            .buttonStyle(.bordered)
                            .controlSize(.large)
                            .padding(.top, 4)
                            .accessibilityLabel("Cancel reservation of \(r.itemName) for \(r.clientName)")
                            .accessibilityInputLabels(["Cancel reservation", "Cancel reservation of \(r.itemName) for \(r.clientName)"])
                    }
                    .accessibilityElement(children: .contain)
                    // Confirmed first: a cancelled waitlist place cannot be given back.
                    .swipeActions(allowsFullSwipe: false) {
                        Button { pendingCancel = r } label: {
                            Label("Cancel reservation of \(r.itemName) for \(r.clientName)", systemImage: "xmark.circle")
                        }
                        // White on #9B1C1C is 8.2:1 (system red was 3.55:1).
                        .tint(Color.alertFill)
                    }
                }
            }
            // The banner is the list's first row: a failed cancel from a reservation further down
            // would otherwise leave it off screen, where a List never draws it, so it was neither
            // seen nor heard. Scroll it into view once it exists, then put VoiceOver on it.
            .onChange(of: error) { message in
                guard message != nil else { return }
                DispatchQueue.main.async { withAnimation { proxy.scrollTo(errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    focusedReservation = errorFocus
                }
            }
        }
        .confirmationDialog(
            "Cancel the reservation of \(pendingCancel?.itemName ?? "") for \(pendingCancel?.clientName ?? "")? A waitlist place cannot be given back.",
            isPresented: Binding(get: { pendingCancel != nil }, set: { if !$0 { pendingCancel = nil } }),
            titleVisibility: .visible
        ) {
            Button("Cancel reservation", role: .destructive) {
                if let r = pendingCancel { Task { await cancel(r.id) } }
                pendingCancel = nil
            }
            Button("Keep reservation", role: .cancel) { pendingCancel = nil }
        }
        .navigationTitle("Reservations")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await load() }
        .task { await load() }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { adding = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add reservation") } }
        .sheet(isPresented: $adding, onDismiss: announceSheetResult) { ReservationSheet(fixedItemId: nil) { message in sheetResult = message; Task { await load() } } }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { error = nil; let r: ReservationsResponse = try await api.call("reservations"); reservations = r.reservations; loaded = true }
        catch { self.error = error.localizedDescription }
    }
    private func cancel(_ id: Int) async {
        let index = reservations.firstIndex(where: { $0.id == id }) ?? 0
        do {
            error = nil
            let _: OKResponse = try await api.call("cancel_reservation", body: ["reservation_id": id])
            await load()
            // The cancelled row (where VoiceOver was) has gone: put VoiceOver on the reservation
            // now in its place, then confirm, after the focus move so it is not cut off.
            if !reservations.contains(where: { $0.id == id }) {
                focusedReservation = reservations.isEmpty ? -1 : reservations[min(index, reservations.count - 1)].id
            }
            announceAfterDismiss("Reservation cancelled.")
        }
        catch { self.error = error.localizedDescription }
    }
}

struct ReservationSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let fixedItemId: Int?
    /// Called with the message for the parent to announce once the sheet has gone.
    let onDone: (String) -> Void
    @State private var items: [EquipmentItem] = []
    @State private var itemsError: String?
    @State private var selectedItem: Int?
    @State private var selectedClient: Int?
    @State private var hasFrom = false; @State private var from = Date()
    @State private var notes = ""; @State private var busy = false; @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                if fixedItemId == nil {
                    Picker("Item", selection: $selectedItem) {
                        Text("Choose…").tag(nil as Int?)
                        ForEach(reservableItems) { i in Text("\(i.name) (\(i.status))").tag(i.id as Int?) }
                    }
                    if let itemsError {
                        ErrorBanner(message: itemsError)
                        Button { Task { await loadItems() } } label: { Label("Try loading items again", systemImage: "arrow.clockwise") }
                    }
                }
                NewRecordClientPicker(title: "Client (required)", selection: $selectedClient)
                Toggle("Needed from", isOn: $hasFrom)
                if hasFrom { DatePicker("From", selection: $from, displayedComponents: .date) }
                TextField("Notes", text: $notes)
            }
            .navigationTitle("Reserve item").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Reserve") { Task { await submit() } }
                        .disabled(busy || selectedClient == nil || (fixedItemId == nil && selectedItem == nil))
                        .accessibilityHint(fixedItemId == nil && selectedItem == nil ? "Choose an item first."
                            : (selectedClient == nil ? "Choose a client first." : ""))
                }
            }
            .task { if fixedItemId == nil && items.isEmpty { await loadItems() } }
        }
    }
    /// One entry per item (an item with two open loans is listed twice by the equipment action).
    private var reservableItems: [EquipmentItem] {
        var seen = Set<Int>()
        return items.filter { $0.status != "Retired" && seen.insert($0.id).inserted }
    }
    private func loadItems() async {
        do { itemsError = nil; let e: EquipmentResponse = try await api.call("equipment"); items = e.equipment }
        catch { itemsError = "Could not load the equipment list. \(error.localizedDescription)" }
    }
    private func submit() async {
        guard let cid = selectedClient else { return }
        let eid = fixedItemId ?? selectedItem ?? 0
        busy = true; error = nil
        var b: [String: Any] = ["equipment_id": eid, "client_id": cid, "notes": notes]
        if hasFrom { b["needed_from"] = APIDate.day(from) }
        do {
            let r: ReservationResult = try await api.call("add_reservation", body: b)
            // The server puts the request on the waitlist when the item is out or already held.
            onDone(r.status == "Waitlist"
                ? "Added to the waitlist. The item is not available now."
                : (r.status == "Reserved" ? "Item reserved." : "Reservation saved."))
            dismiss()
        }
        catch { self.error = error.localizedDescription; busy = false }
    }
}

/// add_reservation's reply: `status` is "Reserved" or "Waitlist".
struct ReservationResult: Decodable {
    let status: String?
    private enum CodingKeys: String, CodingKey { case status }
    // Tolerant: once the server says ok the reservation exists; an unexpected status must not
    // be shown as a failure that invites a duplicate.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        status = try? c.decodeIfPresent(String.self, forKey: .status)
    }
}

struct AddEquipmentSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var name = ""; @State private var category = "Other"; @State private var serial = ""
    @State private var condition = "Good"; @State private var value = ""; @State private var interval = ""; @State private var notes = ""
    @State private var busy = false; @State private var error: String?
    var body: some View {
        NavigationStack {
            Form {
                if let error { ErrorBanner(message: error) }
                // Visible names that stay once a field holds text.
                CaptionedField(label: "Item name (required)", text: $name)
                Picker("Category", selection: $category) { ForEach(equipmentCategoriesApp, id: \.self) { Text($0) } }
                CaptionedField(label: "Serial #", text: $serial)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                Picker("Condition", selection: $condition) { ForEach(equipmentConditions, id: \.self) { Text($0) } }
                KeyboardCaptionedField(label: "Value in USD (digits, like 12.50)", text: $value, keyboard: .decimalPad)
                // Any whole number of days, as on the website; left empty (or 0) for no schedule.
                KeyboardCaptionedField(label: "Service interval in days (optional)", text: $interval, keyboard: .numberPad)
                    .accessibilityHint("Whole days from 0 to 3650. Leave empty or enter 0 for no service schedule.")
                CaptionedField(label: "Notes", text: $notes)
            }
            .navigationTitle("Add equipment").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                // Left enabled: a missing name is explained by the error banner, not a dimmed button.
                ToolbarItem(placement: .confirmationAction) { Button("Add") { Task { await submit() } }.disabled(busy) }
            }
        }
    }
    private func submit() async {
        if name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Item name: enter the item's name.", in: &error); return
        }
        guard let valueText = checkedAmount(value) else { showFormError(amountFormatMessage("Value"), in: &error); return }
        let intervalDays: Int
        if interval.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { intervalDays = 0 }
        else if let days = parsedIntervalDays(interval) { intervalDays = days }
        else { showFormError(intervalFormatMessage, in: &error); return }
        busy = true; error = nil
        do { let _: OKResponse = try await api.call("add_equipment", body: ["name": name, "category": category, "serial": serial, "condition": condition, "value": valueText, "maintenance_interval_days": intervalDays, "notes": notes]); onDone(); dismiss() }
        // (confirmation + VoiceOver announce, after the sheet has gone, handled by the parent's AddEquipmentSheet closure)
        catch { self.error = error.localizedDescription; busy = false }
    }
}
