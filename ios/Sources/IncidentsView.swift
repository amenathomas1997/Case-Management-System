import SwiftUI

/// Incident & safety log (web v2.54 parity).
struct IncidentsView: View {
    @EnvironmentObject var api: API
    @State private var data: IncidentsResponse?
    @State private var error: String?
    @State private var showClosed = false
    @State private var adding = false
    /// The privacy incident report being written (4.36.0).
    @State private var reportingPrivacy: PrivacyIncidentPrefill?
    /// A sheet's success message, announced once the sheet has gone (see announceAfterDismiss).
    @State private var sheetResult: String?
    /// An incident whose closing is waiting for confirmation: it leaves the open list.
    @State private var pendingClose: Incident?
    /// Incidents whose whole description is shown.
    @State private var expanded: Set<Int> = []
    /// After a status change, VoiceOver is put on that incident, or on the one that took its
    /// place when it left the list (-1: the empty-list text; errorFocus: the error banner,
    /// after a failure).
    @AccessibilityFocusState private var focusedIncident: Int?
    private let errorFocus = -2
    private let errorRow = "incidentsError"
    @Environment(\.dynamicTypeSize) private var typeSize

    /// " · reported by Jane Smith", as the website's incident list says it (4.34.0).
    private func reportedBy(_ inc: Incident) -> String {
        guard let who = inc.reporter, !who.isEmpty else { return "" }
        return " · reported by \(who)"
    }

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error {
                    ErrorBanner(message: error).listRowSeparator(.hidden)
                        .id(errorRow)
                        .accessibilityFocused($focusedIncident, equals: errorFocus)
                }
                // Visible buttons, not only the toolbar's Report (4.36.0): a privacy breach is
                // reported here too, as on the website's Privacy incidents page.
                Button { adding = true } label: { Label("Report an incident", systemImage: "plus") }
                    .disabled(data == nil)
                if api.can("clients") {
                    Button { reportingPrivacy = PrivacyIncidentPrefill() } label: {
                        Label("Report a privacy incident", systemImage: "lock.shield")
                    }
                }
                if let d = data {
                    if d.incidents.isEmpty {
                        Text(showClosed ? "No incidents." : "No open incidents.").foregroundStyle(Color.secondaryText)
                            .accessibilityFocused($focusedIncident, equals: -1)
                    }
                    ForEach(d.incidents) { inc in
                        VStack(alignment: .leading, spacing: 4) {
                            HStack {
                                Text(inc.type).font(.body.weight(.semibold))
                                severityBadge(inc.severity)
                                Spacer()
                                Text(inc.status).font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            Text("\(formatDate(inc.incidentDate))\(inc.clientName != nil ? " · \(inc.clientName!)" : "")\(inc.location != nil && !inc.location!.isEmpty ? " · \(inc.location!)" : "")\(reportedBy(inc))")
                                .font(.caption).foregroundStyle(Color.secondaryText)
                            // Shown in full at the accessibility text sizes; otherwise a long description
                            // is cut to 5 lines with a button that shows the rest.
                            let whole = typeSize.isAccessibilitySize || expanded.contains(inc.id)
                            Text(inc.description).font(.callout).lineLimit(whole ? nil : 5)
                            if !typeSize.isAccessibilitySize && isLong(inc.description) {
                                let open = expanded.contains(inc.id)
                                Button(open ? "Show less" : "Show full description") {
                                    if open { expanded.remove(inc.id) } else { expanded.insert(inc.id) }
                                }
                                .buttonStyle(.borderless)
                                .font(.callout)
                                .frame(minHeight: 44, alignment: .leading)
                                .accessibilityLabel(open ? "Show less of the \(inc.type) description" : "Show full description of \(inc.type) on \(formatDate(inc.incidentDate))")
                                .accessibilityInputLabels([open ? "Show less" : "Show full description"])
                            }
                            if let a = inc.actionTaken, !a.isEmpty {
                                Text("Action: \(a)").font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            if inc.canEdit {
                                // A menu of explicit choices (as on the bookings screen); closing an
                                // incident, which takes it off the open list, is confirmed first.
                                Menu {
                                    ForEach(d.statuses, id: \.self) { s in
                                        Button {
                                            if s == inc.status { return }
                                            if s == "Closed" && !showClosed { pendingClose = inc } else { Task { await setStatus(inc, s) } }
                                        } label: {
                                            if s == inc.status { Label(s, systemImage: "checkmark") } else { Text(s) }
                                        }
                                    }
                                } label: {
                                    // Body-size text in a 44-point-tall target.
                                    Label("Status: \(inc.status)", systemImage: "chevron.up.chevron.down")
                                        .frame(minHeight: 44, alignment: .leading)
                                        .contentShape(Rectangle())
                                }
                                // Names the incident, so the Form Controls rotor and Voice Control
                                // can tell one row's status from another's; "Status" stays speakable.
                                .accessibilityLabel("Status for \(inc.type) on \(formatDate(inc.incidentDate))\(inc.clientName.map { " for \($0)" } ?? "")")
                                .accessibilityValue(inc.status)
                                .accessibilityHint("Opens a menu to change the status.")
                                .accessibilityInputLabels(["Status", "Status for \(inc.type)"])
                            }
                        }.padding(.vertical, 2)
                        // One VoiceOver stop per incident, with the whole description (never cut
                        // short), and each status change in the Actions rotor (the same code as the
                        // status menu, so closing still asks first).
                        .accessibilityElement(children: VoiceOverRows.children)
                        .accessibilityLabel(incidentSpoken(inc))
                        .accessibilityFocused($focusedIncident, equals: inc.id)
                        .accessibilityActions {
                            if inc.canEdit {
                                ForEach(d.statuses.filter { $0 != inc.status }, id: \.self) { s in
                                    Button("Mark \(s)") {
                                        if s == "Closed" && !showClosed { pendingClose = inc } else { Task { await setStatus(inc, s) } }
                                    }
                                }
                            }
                        }
                    }
                } else if error == nil { ProgressView() }
            }
            // The banner is the list's first row: a failed status change on an incident further
            // down would otherwise leave it off screen, where a List never draws it, so it was
            // neither seen nor heard. Scroll it into view once it exists, then put VoiceOver on it.
            .onChange(of: error) { message in
                guard let message else { return }
                claimFormErrorFocus(message)   // VoiceOver is put on the banner, which reads it
                DispatchQueue.main.async { withAnimation(UIAccessibility.isReduceMotionEnabled ? nil : .default) { proxy.scrollTo(errorRow, anchor: .top) } }
                Task { @MainActor in
                    try? await Task.sleep(nanoseconds: 400_000_000)
                    focusedIncident = errorFocus
                }
            }
        }
        .confirmationDialog(
            "Close this incident? It moves to the closed incidents, shown with Show closed.",
            isPresented: Binding(get: { pendingClose != nil }, set: { if !$0 { pendingClose = nil } }),
            titleVisibility: .visible
        ) {
            Button("Close incident") {
                if let inc = pendingClose { Task { await setStatus(inc, "Closed") } }
                pendingClose = nil
            }
            Button("Keep open", role: .cancel) { pendingClose = nil }
        }
        .navigationTitle("Incidents")
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                // Offered once the incident list has loaded: the Type and Severity choices come
                // with it, and without them a report would be saved as Other / Low.
                Button { adding = true } label: { Label("Report", systemImage: "plus") }
                    .disabled(data == nil)
            }
            ToolbarItem(placement: .secondaryAction) {
                Button(showClosed ? "Hide closed" : "Show closed") { showClosed.toggle(); Task { await load(announceView: true) } }
            }
        }
        .sheet(isPresented: $adding, onDismiss: announceSheetResult) {
            AddIncidentSheet(types: data?.types ?? [], severities: data?.severities ?? []) { sheetResult = "Incident saved."; Task { await load() } }
        }
        .sheet(item: $reportingPrivacy, onDismiss: announceSheetResult) { prefill in
            PrivacyIncidentSheet(prefill: prefill) { message in sheetResult = message }
        }
        .refreshable { await load() }
        .task { await load() }
    }

    private func announceSheetResult() {
        if let message = sheetResult { announceAfterDismiss(message) }
        sheetResult = nil
    }

    /// The incident as VoiceOver reads it: type, severity, status, date, client, location,
    /// the whole description and the action taken.
    private func incidentSpoken(_ inc: Incident) -> String {
        var parts = [inc.type, "\(inc.severity) severity", inc.status, formatDate(inc.incidentDate)]
        if let client = inc.clientName { parts.append(client) }
        if let location = inc.location, !location.isEmpty { parts.append(location) }
        parts.append(inc.description)
        if let a = inc.actionTaken, !a.isEmpty { parts.append("Action: \(a)") }
        return parts.joined(separator: ", ")
    }

    /// More than 5 lines' worth at the standard text sizes.
    private func isLong(_ s: String) -> Bool {
        s.count > 120 || s.split(separator: "\n", omittingEmptySubsequences: false).count > 4
    }

    private func severityBadge(_ s: String) -> some View {
        // Solid dark fills, so the white caption text is at least 7:1 in both appearances
        // (Color.alertFill #9B1C1C 8.2:1, #7A4100 amber 8.1:1, #555555 grey 7.5:1); system
        // orange and red were about 2.2–3.5:1.
        let color: Color = (s == "Critical" || s == "High") ? Color.alertFill
            : (s == "Medium" ? Color(red: 122/255, green: 65/255, blue: 0) : Color(red: 85/255, green: 85/255, blue: 85/255))
        return Text(s).font(.caption2.weight(.semibold)).foregroundStyle(.white)
            .padding(.horizontal, 6).padding(.vertical, 2)
            .background(color).clipShape(Capsule())
    }

    private func setStatus(_ inc: Incident, _ to: String) async {
        guard to != inc.status else { return }
        let index = data?.incidents.firstIndex(where: { $0.id == inc.id }) ?? 0
        // Cleared first so a repeat of the same failure is shown (and announced) again.
        error = nil
        do {
            let _: OKResponse = try await api.call("incident_update", body: ["id": inc.id, "to": to])
            await load()
            // A predictable place for VoiceOver: the same incident, or (once a closed one has
            // left the list) the one now in its place, then the result after the focus move.
            if let list = data?.incidents {
                if list.contains(where: { $0.id == inc.id }) { focusedIncident = inc.id }
                else if !list.isEmpty { focusedIncident = list[min(index, list.count - 1)].id }
                else { focusedIncident = -1 }
            }
            announceAfterDismiss("Incident marked \(to).")
        } catch { showFormError(error.localizedDescription, in: &self.error) }
    }

    private func load(announceView: Bool = false) async {
        // A late reply for the other setting must not fill the list under the wrong menu label.
        let wantsClosed = showClosed
        do {
            error = nil
            let r: IncidentsResponse = try await api.call("incidents", query: wantsClosed ? ["all": "1"] : [:])
            guard wantsClosed == showClosed else { return }
            data = r
            if announceView { announce(wantsClosed ? "Showing all incidents, including closed." : "Showing open incidents only.") }
        } catch {
            guard wantsClosed == showClosed else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

struct AddIncidentSheet: View {
    @EnvironmentObject var api: API
    /// Sent as idem_key (4.28.0), so a retry after a lost reply does not save it twice.
    @State private var idem = IdemKey()
    @Environment(\.dismiss) private var dismiss
    let types: [String]
    let severities: [String]
    let onSaved: () -> Void

    @State private var date = Date()
    @State private var type: String
    @State private var severity: String
    @State private var location = ""
    @State private var description = ""
    @State private var actionTaken = ""
    @State private var clientId: Int?
    @State private var busy = false
    @State private var error: String?
    @State private var confirmingDiscard = false

    /// Typed text that closing the sheet would throw away.
    private var hasTypedText: Bool {
        [description, location, actionTaken].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }

    // The defaults are set here, before anything is shown, so nothing that arrives later can
    // replace a Type or Severity the user has already chosen.
    init(types: [String], severities: [String], onSaved: @escaping () -> Void) {
        self.types = types
        self.severities = severities
        self.onSaved = onSaved
        _type = State(initialValue: types.first ?? "Other")
        _severity = State(initialValue: severities.first ?? "Low")
    }

    var body: some View {
        NavigationStack {
            // The error banner is the form's first row; it is scrolled into view and given
            // VoiceOver focus when a problem is reported (see ErrorFocusForm).
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section {
                    // A date picker, not typed text: a mistyped date used to be saved as today.
                    // Up to today, as on the website: an incident has already happened.
                    DatePicker("Incident date", selection: $date, in: ...Date(), displayedComponents: .date)
                    Picker("Type", selection: $type) { ForEach(types, id: \.self) { Text($0) } }
                    Picker("Severity", selection: $severity) { ForEach(severities, id: \.self) { Text($0) } }
                    NewRecordClientPicker(title: "Client (optional)", noneLabel: "None", includeClosed: true, selection: $clientId)
                    CaptionedField(label: "Location", text: $location)
                }
                // Required in words, not marked with an unexplained asterisk.
                Section("What happened (required)") {
                    TextField("Description", text: $description, axis: .vertical).lineLimit(3...10)
                        .accessibilityLabel("What happened, required")
                        .accessibilityInputLabels(["Description", "What happened"])
                        .errorField("What happened")
                }
                Section("Action taken") {
                    TextField("Action taken", text: $actionTaken, axis: .vertical).lineLimit(1...6)
                }
            }
            .navigationTitle("Report incident")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Not while saving: the result (or a failure) must be shown on this sheet.
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { requestClose() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    // Left enabled: a missing description is explained by the error banner, not
                    // a dimmed button.
                    Button("Log") { Task { await save() } }
                        .disabled(busy)
                }
            }
            // A swipe down cannot close the sheet while saving or once text has been typed;
            // Cancel and the VoiceOver escape gesture ask first.
            .interactiveDismissDisabled(busy || hasTypedText)
            .accessibilityAction(.escape) { requestClose() }
            .confirmationDialog("Discard this incident report? What you typed will be lost.",
                                isPresented: $confirmingDiscard, titleVisibility: .visible) {
                Button("Discard report", role: .destructive) { dismiss() }
                Button("Keep editing", role: .cancel) {}
            }
        }
    }

    private func requestClose() {
        guard !busy else { return }
        if hasTypedText { confirmingDiscard = true } else { dismiss() }
    }

    private func save() async {
        if description.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            showFormError("What happened: describe the incident before logging it.", in: &error); return
        }
        busy = true; error = nil
        var body: [String: Any] = ["incident_date": APIDate.day(date), "type": type, "severity": severity,
                                   "location": location, "description": description, "action_taken": actionTaken]
        if let clientId { body["client_id"] = clientId }
        do {
            body["idem_key"] = idem.key(for: body)
            let _: OKResponse = try await api.call("add_incident", body: body)
            idem.answered()
            // The list announces the result once this sheet has gone.
            onSaved(); dismiss()
        } catch APIError.network {
            // The request may have arrived: it isn't known whether the incident was logged, and
            // logging it again would record it twice.
            busy = false
            showFormError("The connection dropped before the server answered, so it isn't known whether this incident was logged. Check the incidents list for \(type) on \(date.formatted(date: .abbreviated, time: .omitted)) before logging it again.", in: &self.error)
        } catch { idem.failed(error); busy = false; showFormError(IdemKey.message(for: error), in: &self.error) }
    }
}

// MARK: - Privacy incidents (4.36.0)

/// What a privacy incident report starts with (from Account after a phone is reported lost).
struct PrivacyIncidentPrefill: Identifiable {
    let id = UUID()
    var kind = ""
    var what = ""
}

/// privacy_incident_choices: the website's kinds and the clients this person may name.
struct PrivacyIncidentChoices: Decodable {
    let kinds: [String]
    let clients: [PrivacyIncidentClient]
    private enum CodingKeys: String, CodingKey { case kinds, clients }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        kinds = try c.decode([String].self, forKey: .kinds)
        clients = (try? c.decode([PrivacyIncidentClient].self, forKey: .clients)) ?? []
    }
}

struct PrivacyIncidentClient: Decodable {
    let id: Int
    let name: String
}

struct PrivacyIncidentReply: Decodable {
    let id: Int?
    let noticeDeadline: String?
    let message: String
}

/// Report a possible privacy breach, as the website's Privacy incidents form: an administrator
/// is told and assesses it there (assessment, notices and closing stay on the website).
struct PrivacyIncidentSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    var prefill = PrivacyIncidentPrefill()
    let onReported: (String) -> Void

    @State private var choices: PrivacyIncidentChoices?
    @State private var kind = ""
    @State private var discovered = Date()
    @State private var knowsOccurred = false
    @State private var occurred = Date()
    @State private var clientId: Int?
    @State private var what = ""
    @State private var info = ""
    @State private var idem = IdemKey()
    @State private var busy = false
    @State private var error: String?
    @State private var started = false
    @State private var confirmingDiscard = false

    private var hasTypedText: Bool {
        [what, info].contains { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }
    }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(inNavigationStack: true, error: error) {
                Section {
                    Text("Report a possible privacy breach here: a report or email sent to the wrong person, a lost phone or laptop, someone seeing a record they should not, or a wrong portal link. An administrator assesses it.")
                }
                if let choices {
                    Section {
                        Picker("Kind (required)", selection: $kind) {
                            Text("Choose a kind").tag("")
                            ForEach(choices.kinds, id: \.self) { Text($0).tag($0) }
                        }
                        .errorField("Kind")
                        DatePicker("Date discovered (required)", selection: $discovered, in: ...Date(), displayedComponents: .date)
                            .errorField("Date discovered")
                        Toggle("Date it happened, if known", isOn: $knowsOccurred)
                        if knowsOccurred {
                            DatePicker("Date it happened", selection: $occurred, in: ...Date(), displayedComponents: .date)
                                .errorField("Date it happened")
                        }
                        NewRecordClientPicker(title: "Client affected, if one", noneLabel: "None", includeClosed: true,
                                              selection: $clientId, errorName: "Client affected",
                                              preset: choices.clients.map { ClientRow(id: $0.id, name: $0.name, status: "", phone: nil, email: nil) })
                        CaptionedField(label: "What happened (required)", text: $what, lines: 3...10, errorName: "What happened")
                        CaptionedField(label: "Information involved", text: $info, lines: 1...4, errorName: "Information involved")
                    }
                    Section {
                        Button { Task { await save() } } label: {
                            Text(busy ? "Reporting…" : "Report incident").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                        }
                        .disabled(busy)
                        .accessibilityLabel("Report incident")
                    }
                } else if error == nil {
                    ProgressView("Loading…")
                }
            }
            .navigationTitle("Report a privacy incident")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { requestClose() }.disabled(busy) }
            }
            .interactiveDismissDisabled(busy || hasTypedText)
            .accessibilityAction(.escape) { requestClose() }
            .confirmationDialog("Discard this privacy incident report? What you typed will be lost.",
                                isPresented: $confirmingDiscard, titleVisibility: .visible) {
                Button("Discard report", role: .destructive) { dismiss() }
                Button("Keep editing", role: .cancel) {}
            }
            .task {
                guard !started else { return }
                started = true
                what = prefill.what
                await load()
            }
        }
    }

    private func requestClose() {
        guard !busy else { return }
        // One rule for Cancel, the escape gesture and swipe-down: any text in the report (typed or
        // prefilled) asks first.
        if hasTypedText { confirmingDiscard = true } else { dismiss() }
    }

    private func load() async {
        do {
            let r: PrivacyIncidentChoices = try await api.call("privacy_incident_choices")
            choices = r
            if !prefill.kind.isEmpty, r.kinds.contains(prefill.kind) { kind = prefill.kind }
        } catch APIError.server(let message) where message == "Unknown action." {
            showFormError("This server does not offer privacy incident reports from the app yet.", in: &error)
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func save() async {
        let description = what.trimmingCharacters(in: .whitespacesAndNewlines)
        let involved = info.trimmingCharacters(in: .whitespacesAndNewlines)
        // The server's own sentences, said here first so a slip needs no round trip.
        if kind.isEmpty { showFormError("Choose what kind of incident it is.", in: &error, field: "Kind"); return }
        if description.isEmpty { showFormError("Describe what happened.", in: &error, field: "What happened"); return }
        if description.count > 5000 { showFormError("What happened is too long (5,000 characters maximum).", in: &error, field: "What happened"); return }
        if involved.count > 500 { showFormError("Keep the information involved to 500 characters or fewer.", in: &error, field: "Information involved"); return }
        busy = true
        error = nil
        defer { busy = false }
        var body: [String: Any] = ["kind": kind, "discovered_on": APIDate.day(discovered),
                                   "occurred_on": knowsOccurred ? APIDate.day(occurred) : "",
                                   "client_id": clientId ?? 0, "description": description, "info_involved": involved]
        body["idem_key"] = idem.key(for: body)
        var refusedFields: [String: String] = [:]
        do {
            let r: PrivacyIncidentReply = try await api.call("report_privacy_incident", method: "POST", body: body, refusal: { reply in
                refusedFields = (reply["errors"] as? [String: String]) ?? [:]
            })
            idem.answered()
            onReported(r.message)
            dismiss()
        } catch APIError.server(let message) {
            idem.answered()
            let names = ["kind": "Kind", "discovered_on": "Date discovered", "occurred_on": "Date it happened",
                         "client_id": "Client affected", "description": "What happened", "info_involved": "Information involved"]
            let order = ["kind", "discovered_on", "occurred_on", "client_id", "description", "info_involved"]
            showFormError(message, in: &self.error, field: order.first { refusedFields[$0] != nil }.flatMap { names[$0] })
        } catch {
            idem.failed(error)
            showFormError(IdemKey.message(for: error), in: &self.error)
        }
    }
}
