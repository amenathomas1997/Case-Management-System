import SwiftUI

struct DeviceRow: Decodable, Identifiable {
    let id: Int; let deviceName: String; let category: String?; let status: String
    let authorizedAmount: Double?; let clientName: String; let clientId: Int
    private enum CodingKeys: String, CodingKey { case id, deviceName, category, status, authorizedAmount, clientName, clientId }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        deviceName = (try? c.decode(String.self, forKey: .deviceName)) ?? ""
        category = try? c.decodeIfPresent(String.self, forKey: .category)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        authorizedAmount = try? c.decodeIfPresent(Double.self, forKey: .authorizedAmount)
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
    }
}
struct DeviceRowsResponse: Decodable { let requests: [DeviceRow] }
struct DeviceQuote: Decodable, Identifiable { let id: Int; let vendor: String; let amount: Double; let quoteRef: String?; let notes: String? }
struct DeviceRequest: Decodable {
    let id: Int; let clientName: String; let deviceName: String; let category: String?
    let justification: String?; let status: String; let fundingSource: String?; let authNumber: String?
    let authorizedAmount: Double?; let selectedQuoteId: Int?; let poNumber: String?
    let deliveredAt: String?; let trainedAt: String?; let serial: String?
    var authorizedAt: String?; var orderedAt: String?; var warrantyUntil: String?
}
struct DeviceRequestResponse: Decodable { let request: DeviceRequest; let quotes: [DeviceQuote] }

var deviceCategories: [String] { FormOptions.list("device_request_categories", ["Braille Display", "Screen Reader", "Magnifier / CCTV", "AAC Device", "Tablet / Phone", "Computer", "Hearing / Amplification", "Switch / Access", "Software License", "Other"]) }

struct ProcurementView: View {
    @EnvironmentObject var api: API
    @State private var rows: [DeviceRow] = []
    /// Set once a list has loaded, so "No device requests yet." is never claimed before then.
    @State private var loaded = false
    @State private var error: String?
    @State private var showNew = false
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if loaded && rows.isEmpty {
                Text("No device requests yet.").foregroundStyle(Color.secondaryText)
            } else if !loaded && error == nil {
                ProgressView("Loading device requests…")
            }
            ForEach(rows) { r in
                NavigationLink {
                    DeviceRequestDetail(id: r.id, onChanged: { Task { await load() } })
                } label: {
                    VStack(alignment: .leading, spacing: 3) {
                        Text(r.deviceName).font(.body.weight(.semibold))
                        Text("\(r.clientName)\(r.category != nil ? " · \(r.category!)" : "")").font(.caption).foregroundStyle(Color.secondaryText)
                        Text(r.status).font(.caption2.weight(.semibold)).foregroundStyle(Color.brand)
                    }
                }
            }
        }
        .navigationTitle("Procurement")
        .refreshable { await load() }
        .task { await load() }
        .toolbar { ToolbarItem(placement: .primaryAction) { Button { showNew = true } label: { Image(systemName: "plus") }.accessibilityLabel("Add device request") } }
        .sheet(isPresented: $showNew, onDismiss: announceSheetResult) {
            DeviceNewSheet { sheetResult = "Request created."; Task { await load() } }
        }
    }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    private func load() async {
        do { error = nil; let r: DeviceRowsResponse = try await api.call("device_requests"); rows = r.requests; loaded = true }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct DeviceRequestDetail: View {
    @EnvironmentObject var api: API
    let id: Int
    let onChanged: () -> Void
    @State private var req: DeviceRequest?
    @State private var quotes: [DeviceQuote] = []
    @State private var error: String?
    @State private var action: DeviceAction?
    @State private var addingQuote = false
    @State private var pendingClose: DeviceClose?
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// After a Pick, VoiceOver is put on the chosen quote: its Pick button is gone by then.
    @AccessibilityFocusState private var focusedQuote: Int?
    @Environment(\.dynamicTypeSize) private var typeSize

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let r = req {
                Section {
                    LabeledContent("Device", value: r.deviceName)
                    LabeledContent("Client", value: r.clientName)
                    LabeledContent("Status", value: r.status)
                    if let f = r.fundingSource, !f.isEmpty { LabeledContent("Funding", value: f) }
                    if let a = r.authNumber, !a.isEmpty { LabeledContent("Auth #", value: a) }
                    if let amt = r.authorizedAmount { LabeledContent("Authorized amount", value: money(amt)) }
                    if let d = r.authorizedAt, !d.isEmpty { LabeledContent("Authorized on", value: formatDate(d)) }
                    if let po = r.poNumber, !po.isEmpty { LabeledContent("PO #", value: po) }
                    if let d = r.orderedAt, !d.isEmpty { LabeledContent("Ordered on", value: formatDate(d)) }
                    if let d = r.deliveredAt, !d.isEmpty { LabeledContent("Delivered on", value: formatDate(d)) }
                    if let s = r.serial, !s.isEmpty { LabeledContent("Serial", value: s) }
                    if let d = r.warrantyUntil, !d.isEmpty { LabeledContent("Warranty until", value: formatDate(d)) }
                    if let d = r.trainedAt, !d.isEmpty { LabeledContent("Training completed", value: formatDate(d)) }
                }
                if let j = r.justification, !j.isEmpty {
                    Section("Justification") { Text(j).font(.callout) }
                }
                Section("Quotes") {
                    ForEach(quotes) { q in
                        // At accessibility text sizes the vendor, amount and Pick button stack, so
                        // the vendor column is not squeezed into a narrow strip beside them.
                        let layout = typeSize.isAccessibilitySize
                            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 6)) : AnyLayout(HStackLayout())
                        layout {
                            VStack(alignment: .leading) {
                                Text(q.vendor)
                                if let n = q.notes, !n.isEmpty { Text(n).font(.caption).foregroundStyle(Color.secondaryText) }
                            }
                            if !typeSize.isAccessibilitySize { Spacer() }
                            Text(money(q.amount)).font(.callout)
                            if r.selectedQuoteId == q.id {
                                Image(systemName: "checkmark.seal.fill").foregroundStyle(Color.brandTeal)
                                    .accessibilityLabel("Recommended quote")
                            } else if r.status != "Trained" && r.status != "Denied" && r.status != "Cancelled" {
                                // A large control: the regular bordered capsule is under 44 points tall.
                                Button("Pick") {
                                    Task { await update(["op": "select_quote", "quote_id": q.id], done: "\(q.vendor) quote recommended.", focusQuote: q.id) }
                                }
                                .buttonStyle(.bordered).controlSize(.large)
                                .accessibilityLabel("Pick \(q.vendor) quote")
                                .accessibilityInputLabels(["Pick", "Pick \(q.vendor) quote"])
                            }
                        }
                        // VoiceOver: one stop per quote (vendor, notes, amount, whether it is the
                        // recommended one); Pick is on the Actions rotor.
                        .accessibilityElement(children: VoiceOverRows.children)
                        .accessibilityLabel([q.vendor, q.notes.flatMap { $0.isEmpty ? nil : $0 }, money(q.amount),
                                             r.selectedQuoteId == q.id ? "Recommended quote" : nil]
                            .compactMap { $0 }.joined(separator: ", "))
                        .accessibilityActions {
                            if r.selectedQuoteId != q.id && r.status != "Trained" && r.status != "Denied" && r.status != "Cancelled" {
                                Button("Pick") {
                                    Task { await update(["op": "select_quote", "quote_id": q.id], done: "\(q.vendor) quote recommended.", focusQuote: q.id) }
                                }
                            }
                        }
                        .accessibilityFocused($focusedQuote, equals: q.id)
                    }
                    // The server refuses a new quote on a closed request (reopen it first).
                    if r.status != "Trained" && r.status != "Denied" && r.status != "Cancelled" {
                        Button { addingQuote = true } label: { Label("Add quote", systemImage: "plus") }
                    }
                }
                if r.status != "Trained" && r.status != "Denied" && r.status != "Cancelled" {
                    // Every step stays available while the request is open, as on the website, so
                    // a skipped or wrong authorization, order or delivery can still be recorded.
                    // The server only ever moves the status forward, never back.
                    Section("Advance") {
                        Button(isSet(r.authorizedAt) ? "Edit authorization" : "Record authorization") { action = .authorize }
                        Button(isSet(r.orderedAt) ? "Edit order" : "Record order") { action = .order }
                        Button(isSet(r.deliveredAt) ? "Edit delivery" : "Record delivery") { action = .deliver }
                        // Closing steps cannot be undone from here (training) or end the request,
                        // so each takes a second, deliberate step.
                        Button("Complete training") { pendingClose = .train }
                        Button("Deny", role: .destructive) { pendingClose = .deny }
                        Button("Cancel request", role: .destructive) { pendingClose = .cancel }
                    }
                } else if r.status == "Denied" || r.status == "Cancelled" {
                    Section {
                        Button("Reopen request") { Task { await update(["op": "status", "to": "Requested"], done: "Request reopened.") } }
                    }
                }
            } else if error == nil { ProgressView() }
        }
        .navigationTitle("Device request")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
        // Each of these moves the status on the server, so the list behind is refreshed too.
        .sheet(isPresented: $addingQuote, onDismiss: announceSheetResult) {
            DeviceQuoteSheet(requestId: id) { sheetResult = "Quote added."; Task { await load(); onChanged() } }
        }
        .sheet(item: $action, onDismiss: announceSheetResult) { act in
            if let r = req { DeviceActionSheet(request: r, action: act) { sheetResult = act.savedMessage; Task { await load(); onChanged() } } }
        }
        .confirmationDialog(pendingClose?.question ?? "", isPresented: Binding(get: { pendingClose != nil }, set: { if !$0 { pendingClose = nil } }),
                            titleVisibility: .visible) {
            if let close = pendingClose {
                Button(close.button, role: close == .train ? nil : .destructive) {
                    pendingClose = nil
                    Task { await update(close.body, done: close.done) }
                }
            }
            Button("Keep request open", role: .cancel) { pendingClose = nil }
        }
    }

    private func isSet(_ day: String?) -> Bool { !(day ?? "").isEmpty }
    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }
    /// True when the request was read; false leaves the error banner saying why.
    @discardableResult private func load() async -> Bool {
        do { error = nil; let r: DeviceRequestResponse = try await api.call("device_request", query: ["id": String(id)]); req = r.request; quotes = r.quotes; return true }
        catch { showFormError(error.localizedDescription, in: &self.error); return false }
    }
    /// `done` says what the step did; the status read back afterwards is added, so the outcome
    /// is heard rather than a generic "updated". `focusQuote` puts VoiceOver on that quote once
    /// the list has been rebuilt.
    private func update(_ body: [String: Any], done: String, focusQuote: Int? = nil) async {
        var b = body; b["id"] = id
        do {
            error = nil
            let _: OKResponse = try await api.call("device_request_update", body: b)
            let reloaded = await load(); onChanged()
            if reloaded, let focusQuote { focusedQuote = focusQuote }
            // Said after the reload and the focus move, so neither cuts it off. The saved step is
            // still reported when the refresh fails; only the status is left out then.
            announceAfterDismiss(reloaded && req != nil ? "\(done) Status: \(req!.status)." : done)
        }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
}

enum DeviceAction: String, Identifiable {
    case authorize, order, deliver
    var id: String { rawValue }
    /// Announced once the step's sheet has closed.
    var savedMessage: String {
        switch self {
        case .authorize: return "Authorization saved."
        case .order: return "Order saved."
        case .deliver: return "Delivery saved."
        }
    }
}

/// The closing steps that are confirmed before they are sent.
enum DeviceClose {
    case train, deny, cancel
    var question: String {
        switch self {
        case .train: return "Mark training complete and close this request? A trained request cannot be reopened."
        case .deny: return "Mark this request denied? It is closed until someone reopens it."
        case .cancel: return "Cancel this request? It is closed until someone reopens it."
        }
    }
    var button: String {
        switch self {
        case .train: return "Complete training"
        case .deny: return "Mark denied"
        case .cancel: return "Cancel request"
        }
    }
    /// What the step did, announced with the status read back afterwards.
    var done: String {
        switch self {
        case .train: return "Training recorded and request closed."
        case .deny: return "Request denied."
        case .cancel: return "Request cancelled."
        }
    }
    var body: [String: Any] {
        switch self {
        case .train: return ["op": "train"]
        case .deny: return ["op": "status", "to": "Denied"]
        case .cancel: return ["op": "status", "to": "Cancelled"]
        }
    }
}

struct DeviceActionSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let request: DeviceRequest; let action: DeviceAction; let onDone: () -> Void
    // Filled from the stored request (as the website's forms are), so recording a step again
    // to correct one detail never blanks the others.
    @State private var text1 = ""; @State private var text2 = ""; @State private var amount = ""
    @State private var warranty = ""
    @State private var date = Date(); @State private var busy = false; @State private var error: String?
    @State private var filled = false
    @State private var askingDiscard = false
    /// The values filled in from the stored request, so only real edits ask before they are discarded.
    @State private var initial: [String] = ["", "", "", ""]
    @State private var initialDate = Date()

    private var hasChanges: Bool {
        filled && ([text1, text2, amount, warranty] != initial || !Calendar.current.isDate(date, inSameDayAs: initialDate))
    }

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(error: error) {
                switch action {
                // Each field keeps a visible name above it: they are filled in from the stored
                // request, and a placeholder alone disappears once a field holds a value.
                case .authorize:
                    CaptionedField(label: "Funding source", text: $text1)
                    CaptionedField(label: "Authorization #", text: $text2)
                    KeyboardCaptionedField(label: "Authorized amount", text: $amount, keyboard: .decimalPad)
                    DatePicker("Authorized on", selection: $date, displayedComponents: .date)
                case .order:
                    CaptionedField(label: "PO #", text: $text1)
                    DatePicker("Ordered on", selection: $date, displayedComponents: .date)
                case .deliver:
                    CaptionedField(label: "Serial #", text: $text1)
                        .textInputAutocapitalization(.never).autocorrectionDisabled()
                    DatePicker("Delivered on", selection: $date, displayedComponents: .date)
                    OptionalDayField(title: "Warranty until", text: $warranty)
                }
            }
            .onAppear {
                guard !filled else { return }
                filled = true
                let r = request
                switch action {
                case .authorize:
                    text1 = r.fundingSource ?? ""; text2 = r.authNumber ?? ""
                    amount = r.authorizedAmount.map { String(format: "%.2f", $0) } ?? ""
                    if let d = r.authorizedAt, let day = ymd(d) { date = day }
                case .order:
                    text1 = r.poNumber ?? ""
                    if let d = r.orderedAt, let day = ymd(d) { date = day }
                case .deliver:
                    text1 = r.serial ?? ""
                    warranty = String((r.warrantyUntil ?? "").prefix(10))
                    if let d = r.deliveredAt, let day = ymd(d) { date = day }
                }
                initial = [text1, text2, amount, warranty]; initialDate = date
            }
            .navigationTitle(action.rawValue.capitalized)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the step would still be recorded, with no word of it here.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                ToolbarItem(placement: .confirmationAction) { Button("Save") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard your changes to this step?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }
    private func submit() async {
        guard let amountText = checkedAmount(amount) else { showFormError(amountFormatMessage("Authorized amount"), in: &error); return }
        if action == .deliver && !warranty.isEmpty && APIDate.strictDay(warranty) == nil {
            showFormError("Warranty until: choose a valid date.", in: &error); return
        }
        busy = true; error = nil
        let day = APIDate.day(date)
        var b: [String: Any] = ["id": request.id]
        switch action {
        case .authorize: b["op"] = "authorize"; b["funding_source"] = text1; b["auth_number"] = text2; b["authorized_amount"] = amountText; b["authorized_at"] = day
        case .order: b["op"] = "order"; b["po_number"] = text1; b["ordered_at"] = day
        case .deliver: b["op"] = "deliver"; b["serial"] = text1; b["delivered_at"] = day; b["warranty_until"] = warranty
        }
        // The presenter announces the result once the sheet has gone (a message posted while it
        // is still open is cut off when it closes).
        do { let _: OKResponse = try await api.call("device_request_update", body: b); onDone(); dismiss() }
        catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}

/// CaptionedField (ClientEditSheet.swift) for a field that needs its own keyboard, such as a
/// decimal pad for an amount: the name stays visible above the field once it holds a value.
struct KeyboardCaptionedField: View {
    let label: String
    @Binding var text: String
    var keyboard: UIKeyboardType = .default
    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            // The field itself carries the same name for VoiceOver, so the caption is not repeated.
            Text(label).font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText).accessibilityHidden(true)
            TextField(label, text: $text).keyboardType(keyboard)
        }
    }
}

struct DeviceQuoteSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let requestId: Int; let onDone: () -> Void
    @State private var vendor = ""; @State private var amount = ""; @State private var ref = ""; @State private var notes = ""
    @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false

    private var hasChanges: Bool {
        [vendor, amount, ref, notes].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(error: error) {
                // Visible names that stay once a field is filled; the required ones say so.
                CaptionedField(label: "Vendor (required)", text: $vendor)
                KeyboardCaptionedField(label: "Amount (required, digits, like 1250.00)", text: $amount, keyboard: .decimalPad)
                CaptionedField(label: "Quote #", text: $ref)
                CaptionedField(label: "Notes", text: $notes)
            }
            .navigationTitle("Add quote")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the quote would still be added, with no word of it here.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                // Left enabled: a missing vendor or amount is explained by the error banner, not a dimmed button.
                ToolbarItem(placement: .confirmationAction) { Button("Add") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this quote?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }
    private func submit() async {
        if vendor.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Vendor: enter the vendor's name.", in: &error); return
        }
        // The server needs the quoted price; said here rather than after a round trip.
        if amount.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Amount: enter the quoted price in digits, like 1250.00.", in: &error); return
        }
        guard let amountText = checkedAmount(amount) else { showFormError(amountFormatMessage("Amount"), in: &error); return }
        busy = true; error = nil
        // The presenter announces the result once the sheet has gone.
        do { let _: OKResponse = try await api.call("device_request_update", body: ["id": requestId, "op": "add_quote", "vendor": vendor, "amount": amountText, "quote_ref": ref, "notes": notes]); onDone(); dismiss() }
        catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}

struct DeviceNewSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let onDone: () -> Void
    @State private var selectedClient: Int?
    @State private var deviceName = ""; @State private var category = deviceCategories.contains("Other") ? "Other" : (deviceCategories.first ?? "Other"); @State private var justification = ""
    @State private var busy = false; @State private var error: String?
    @State private var askingDiscard = false

    private var hasChanges: Bool {
        selectedClient != nil || category != "Other"
            || [deviceName, justification].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(error: error) {
                NewRecordClientPicker(title: "Client (required)", selection: $selectedClient)
                CaptionedField(label: "Device / technology (required)", text: $deviceName)
                Picker("Category", selection: $category) { ForEach(deviceCategories, id: \.self) { Text($0) } }
                Section("Justification") { TextEditor(text: $justification).frame(minHeight: 80).accessibilityLabel("Justification") }
            }
            .navigationTitle("New request")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the request would still be created, with no word of it here.
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
                // Left enabled: what is missing is explained by the error banner, not a dimmed button.
                ToolbarItem(placement: .confirmationAction) { Button("Create") { Task { await submit() } }.disabled(busy) }
            }
            .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard this new request?",
                                 asking: $askingDiscard) { dismiss() }
        }
    }
    private func submit() async {
        guard let cid = selectedClient else { showFormError("Client: choose the client this request is for.", in: &error); return }
        if deviceName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("Device / technology: enter the device or technology requested.", in: &error); return
        }
        busy = true; error = nil
        // The presenter announces the result once the sheet has gone.
        do { let _: OKResponse = try await api.call("add_device_request", body: ["client_id": cid, "device_name": deviceName, "category": category, "justification": justification]); onDone(); dismiss() }
        catch { busy = false; showFormError(error.localizedDescription, in: &self.error) }
    }
}

func money(_ v: Double) -> String {
    let f = NumberFormatter(); f.numberStyle = .currency; f.currencyCode = "USD"
    return f.string(from: NSNumber(value: v)) ?? String(format: "$%.2f", v)
}
