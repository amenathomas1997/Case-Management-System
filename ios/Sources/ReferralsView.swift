import SwiftUI

struct ReferralRow: Decodable, Identifiable {
    let id: Int
    let clientName: String
    let reason: String
    let status: String
    let createdAt: String
    let counselorName: String
    let counselorAgency: String
    let referralSource: String?
    let incomeBracket: String?
    let quotedFee: String?
    let assignedName: String?

    private enum CodingKeys: String, CodingKey { case id, clientName, reason, status, createdAt, counselorName, counselorAgency, referralSource, incomeBracket, quotedFee, assignedName }
    // Tolerant decode: a self-referral (no counselor) or any null field must not blank the
    // whole Referrals list for a VoiceOver user. Only id is required.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        reason = (try? c.decode(String.self, forKey: .reason)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        counselorName = (try? c.decode(String.self, forKey: .counselorName)) ?? ""
        counselorAgency = (try? c.decode(String.self, forKey: .counselorAgency)) ?? ""
        referralSource = try? c.decodeIfPresent(String.self, forKey: .referralSource)
        incomeBracket = try? c.decodeIfPresent(String.self, forKey: .incomeBracket)
        quotedFee = try? c.decodeIfPresent(String.self, forKey: .quotedFee)
        assignedName = try? c.decodeIfPresent(String.self, forKey: .assignedName)
    }

    var isSelfReferral: Bool { referralSource == "self" }
    var feeText: String {
        guard let fee = Double(quotedFee ?? "") else { return "" }
        return fee > 0 ? String(format: "$%.2f per hour", fee) : "Free"
    }
}

struct ReferralsResponse: Decodable {
    let referrals: [ReferralRow]
    /// How many referrals match in all (3.0.12 servers send at most 100 rows); nil from an
    /// older server, which does not say.
    let total: Int?
    private enum CodingKeys: String, CodingKey { case referrals, total }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        referrals = try c.decode([ReferralRow].self, forKey: .referrals)
        total = try? c.decodeIfPresent(Int.self, forKey: .total)
    }
}

struct ReferralDetail: Decodable {
    let id: Int
    let reason: String
    let noteType: String
    let subject: String
    let body: String
    let counselorName: String
    let counselorAgency: String
    let counselorEmail: String
    let counselorPhone: String
    let clientName: String
    let clientDob: String?
    let clientPhone: String?
    let clientEmail: String?
    let clientAddress: String?
    let status: String
    let assignedTo: Int?
    let assignedName: String?
    let clientId: Int?
    let createdAt: String
    let referralSource: String?
    let incomeBracket: String?
    let quotedFee: String?
    // School, family and other community referrals (shown as on the website).
    let school: String?
    let gradeLevel: String?
    let guardianName: String?
    let guardianPhone: String?
    let relationship: String?
    let contactFirst: String?
    let consentConfirmed: Bool?
    let preetsServices: String?

    private enum CodingKeys: String, CodingKey { case id, reason, noteType, subject, body, counselorName, counselorAgency, counselorEmail, counselorPhone, clientName, clientDob, clientPhone, clientEmail, clientAddress, status, assignedTo, assignedName, clientId, createdAt, referralSource, incomeBracket, quotedFee, school, gradeLevel, guardianName, guardianPhone, relationship, contactFirst, consentConfirmed, preetsServices }
    // Tolerant decode: a self-referral nulls the counselor fields; that must not blank the
    // referral detail screen. Only id is required.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        reason = (try? c.decode(String.self, forKey: .reason)) ?? ""
        noteType = (try? c.decode(String.self, forKey: .noteType)) ?? ""
        subject = (try? c.decode(String.self, forKey: .subject)) ?? ""
        body = (try? c.decode(String.self, forKey: .body)) ?? ""
        counselorName = (try? c.decode(String.self, forKey: .counselorName)) ?? ""
        counselorAgency = (try? c.decode(String.self, forKey: .counselorAgency)) ?? ""
        counselorEmail = (try? c.decode(String.self, forKey: .counselorEmail)) ?? ""
        counselorPhone = (try? c.decode(String.self, forKey: .counselorPhone)) ?? ""
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        clientDob = try? c.decodeIfPresent(String.self, forKey: .clientDob)
        clientPhone = try? c.decodeIfPresent(String.self, forKey: .clientPhone)
        clientEmail = try? c.decodeIfPresent(String.self, forKey: .clientEmail)
        clientAddress = try? c.decodeIfPresent(String.self, forKey: .clientAddress)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        assignedTo = try? c.decodeIfPresent(Int.self, forKey: .assignedTo)
        assignedName = try? c.decodeIfPresent(String.self, forKey: .assignedName)
        clientId = try? c.decodeIfPresent(Int.self, forKey: .clientId)
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        referralSource = try? c.decodeIfPresent(String.self, forKey: .referralSource)
        incomeBracket = try? c.decodeIfPresent(String.self, forKey: .incomeBracket)
        quotedFee = try? c.decodeIfPresent(String.self, forKey: .quotedFee)
        school = try? c.decodeIfPresent(String.self, forKey: .school)
        gradeLevel = try? c.decodeIfPresent(String.self, forKey: .gradeLevel)
        guardianName = try? c.decodeIfPresent(String.self, forKey: .guardianName)
        guardianPhone = try? c.decodeIfPresent(String.self, forKey: .guardianPhone)
        relationship = try? c.decodeIfPresent(String.self, forKey: .relationship)
        contactFirst = try? c.decodeIfPresent(String.self, forKey: .contactFirst)
        // A tinyint: 0/1 as a number, a string or a boolean, depending on the database driver.
        if let flag = try? c.decode(Bool.self, forKey: .consentConfirmed) { consentConfirmed = flag }
        else if let number = try? c.decode(Int.self, forKey: .consentConfirmed) { consentConfirmed = number != 0 }
        else if let text = try? c.decode(String.self, forKey: .consentConfirmed) { consentConfirmed = text == "1" }
        else { consentConfirmed = nil }
        preetsServices = try? c.decodeIfPresent(String.self, forKey: .preetsServices)
    }

    var isSelfReferral: Bool { referralSource == "self" }
    /// counselor (the default), self, school, medical, family, agency or other.
    var source: String { (referralSource ?? "").isEmpty ? "counselor" : referralSource! }
    var feeText: String {
        guard let fee = Double(quotedFee ?? "") else { return "" }
        return fee > 0 ? String(format: "$%.2f per hour", fee) : "Free"
    }
}

/// convert_referral's reply: the held flags are absent from an older server.
struct ConvertReferralResponse: Decodable {
    let ok: Bool
    let counselorEmailHeld: Bool?
    let clientEmailHeld: Bool?
    /// True (3.0.21 servers) when the client's email was copied but waits for staff to confirm it.
    let clientEmailUnconfirmed: Bool?
}

struct ReferralDetailResponse: Decodable {
    let referral: ReferralDetail
    /// Existing clients or other referrals that may be the same person ("Existing client: …"),
    /// sent beside the referral: empty when nothing matches, nil from a server that does not check.
    let possibleDuplicates: [String]?
    private enum CodingKeys: String, CodingKey { case referral, possibleDuplicates }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        referral = try c.decode(ReferralDetail.self, forKey: .referral)
        possibleDuplicates = try? c.decodeIfPresent([String].self, forKey: .possibleDuplicates)
    }
}

struct StaffMember: Decodable, Identifiable {
    let id: Int
    let name: String
    /// Whether the person may work the referral queue (nil from a server that does not say).
    let canReferrals: Bool?
    /// Whether the person may open client records, so only they are offered as a client's
    /// assigned instructor (nil from a server that does not say).
    let canClients: Bool?
    private enum CodingKeys: String, CodingKey { case id, name, canReferrals, canClients }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? ""
        canReferrals = try? c.decodeIfPresent(Bool.self, forKey: .canReferrals)
        canClients = try? c.decodeIfPresent(Bool.self, forKey: .canClients)
    }
}
struct StaffResponse: Decodable { let staff: [StaffMember] }

func statusColor(_ status: String) -> Color {
    switch status {
    case "New": return .orange
    case "Reviewed": return .brand
    case "Converted": return .brandTeal
    default: return .secondary
    }
}

struct ReferralsView: View {
    @EnvironmentObject var api: API
    @State private var referrals: [ReferralRow] = []
    /// How many referrals the server has in all, when it sent only some of them.
    @State private var total: Int?
    @State private var loaded = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
                // "Nothing here" only once the list has actually arrived.
                if !loaded && error == nil {
                    ProgressView("Loading referrals…")
                } else if loaded && referrals.isEmpty && error == nil {
                    Text("No referrals yet. Counselors submit them through the public referral portal.")
                        .foregroundStyle(Color.secondaryText)
                }
                // The server sends the newest 100 (open ones first): said, so the list is not
                // taken for all of them.
                if loaded, let total, total > referrals.count {
                    Text("Showing \(referrals.count) of \(total) referrals; the rest are on the website's Referrals page.")
                        .font(.callout).foregroundStyle(Color.secondaryText)
                }
                ForEach(referrals) { referral in
                    NavigationLink(value: referral.id) {
                        VStack(alignment: .leading, spacing: 3) {
                            HStack {
                                Text(referral.clientName).font(.body.weight(.semibold))
                                Spacer()
                                // The status is in words, in the primary text colour (orange text
                                // was 2.2:1); the tinted capsule only decorates it.
                                Text(referral.status)
                                    .font(.caption.weight(.semibold))
                                    .padding(.horizontal, 8).padding(.vertical, 3)
                                    .background(statusColor(referral.status).opacity(0.15), in: Capsule())
                                    .foregroundStyle(.primary)
                            }
                            Text(referral.reason).font(.callout).foregroundStyle(Color.secondaryText).lineLimit(2)
                            if referral.isSelfReferral {
                                Text("Self-referral · \(referral.feeText)")
                                    .font(.caption.weight(.semibold)).foregroundStyle(Color.brandTeal)
                            } else {
                                Text("\(referral.counselorName) · \(referral.counselorAgency)")
                                    .font(.caption).foregroundStyle(Color.secondaryText)
                            }
                            if let assigned = referral.assignedName {
                                Text("Assigned to \(assigned)").font(.caption).foregroundStyle(Color.brandTeal)
                            }
                        }
                        .padding(.vertical, 2)
                        // One stop per referral, read in order with pauses between the parts.
                        .accessibilityElement(children: .combine)
                    }
                }
            }
            .navigationTitle("Referrals")
            .toolbar {
                // Managing counselor portal access needs the admin permission (as on the server).
                if api.can("admin") {
                    NavigationLink { CounselorsView() } label: {
                        Label("Counselor portal", systemImage: "person.crop.rectangle.badge.plus")
                    }
                }
            }
            .navigationDestination(for: Int.self) { id in
                // The list is refreshed after a review, assignment or conversion: going back does
                // not reload it, and a referral handed to a colleague must leave it.
                ReferralDetailView(referralId: id) { Task { await load() } }
            }
            .refreshable { await load() }
            .task { await load() }
        }
    }

    private func load() async {
        do {
            error = nil
            let response: ReferralsResponse = try await api.call("referrals")
            referrals = response.referrals
            total = response.total
            loaded = true
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}

struct ReferralDetailView: View {
    @EnvironmentObject var api: API
    let referralId: Int
    /// Called after the referral changed (reviewed, assigned, converted), so the list follows.
    var onChanged: () -> Void = {}
    @State private var detail: ReferralDetail?
    /// The server's duplicate check for this referral (nil from a server that does not check).
    @State private var duplicates: [String]?
    @State private var staff: [StaffMember] = []
    @State private var error: String?
    @State private var confirmation: String?
    @State private var showConvertConfirm = false
    /// The converter has checked the counselor email from the public form (as the website's
    /// tick box): only then is it made the counselor of record that receives reports.
    @State private var confirmCounselorEmail = false
    @State private var busy = false
    /// A caseload-only account handed the referral to a colleague: it can no longer open it,
    /// so the screen keeps only the confirmation.
    @State private var handedOff = false
    /// The assignee picked in "Assigned to", waiting for the person to confirm the change.
    @State private var pendingAssignee: Int?
    /// After Mark as reviewed or Convert removes the button VoiceOver was on, VoiceOver is put
    /// on the referral's status rather than left wherever it lands.
    @AccessibilityFocusState private var statusFocused: Bool
    /// After a hand-off removes every row (and the picker VoiceOver was on), VoiceOver is put
    /// on the confirmation, the only row left.
    @AccessibilityFocusState private var confirmationFocused: Bool

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if let confirmation {
                Text(confirmation).font(.callout.weight(.medium)).foregroundStyle(Color.brandTeal)
                    .listRowSeparator(.hidden)
                    .accessibilityFocused($confirmationFocused)
            }
            if let detail, !handedOff {
                // As on the website: anyone this might already be, before converting.
                // Not after converting: the new client would match the referral it came from.
                if let dupes = duplicates, !dupes.isEmpty, detail.status != "Converted" {
                    Section("Possible duplicate — check before converting") {
                        // By position: two matches can read the same (same name, status and day).
                        ForEach(Array(dupes.enumerated()), id: \.offset) { Text($0.element) }
                    }
                } else if duplicates != nil && detail.status != "Converted" {
                    Section("Duplicate check") {
                        Text("No existing client or other referral matches this name, phone or email.")
                    }
                }
                Section("Referral") {
                    LabeledContent("Status", value: detail.status)
                        .accessibilityFocused($statusFocused)
                    LabeledContent("Received", value: formatDateTime(detail.createdAt))
                    LabeledContent("Reason", value: detail.reason)
                    LabeledContent("Type", value: detail.noteType)
                    VStack(alignment: .leading, spacing: 4) {
                        Text(detail.subject).font(.body.weight(.semibold))
                        Text(detail.body).font(.callout)
                    }
                    .accessibilityElement(children: .combine)
                }
                if detail.isSelfReferral {
                    Section("Self-referral") {
                        LabeledContent("Source", value: "Referred themselves")
                        LabeledContent("Income", value: detail.incomeBracket ?? "—")
                        LabeledContent("Fee determination", value: detail.feeText)
                    }
                } else if detail.source == "counselor" {
                    Section("Counselor") {
                        LabeledContent("Name", value: detail.counselorName)
                        LabeledContent("Agency", value: detail.counselorAgency)
                        LabeledContent("Email", value: detail.counselorEmail)
                        LabeledContent("Phone", value: detail.counselorPhone)
                    }
                } else {
                    // School, family, medical, agency or other: who referred, and for a minor or a
                    // family referral, who to contact first (as the website shows it).
                    Section("\(detail.source.capitalized) referral") {
                        LabeledContent("Referred by", value: [detail.counselorName, detail.counselorAgency].filter { !$0.isEmpty }.joined(separator: " — "))
                        if !detail.counselorEmail.isEmpty { LabeledContent("Email", value: detail.counselorEmail) }
                        if !detail.counselorPhone.isEmpty { LabeledContent("Phone", value: detail.counselorPhone) }
                        if detail.source == "school" {
                            LabeledContent("School", value: (detail.school ?? "").isEmpty ? "—"
                                           : detail.school! + ((detail.gradeLevel ?? "").isEmpty ? "" : " · Grade \(detail.gradeLevel!)"))
                            LabeledContent("Parent/guardian", value: (detail.guardianName ?? "").isEmpty ? "—"
                                           : detail.guardianName! + ((detail.guardianPhone ?? "").isEmpty ? "" : " · \(detail.guardianPhone!)"))
                            if let services = detail.preetsServices, !services.isEmpty {
                                LabeledContent("Pre-ETS services", value: services)
                            }
                        }
                        if detail.source == "family" {
                            LabeledContent("Relationship", value: (detail.relationship ?? "").isEmpty ? "—" : detail.relationship!)
                            LabeledContent("Contact first", value: detail.contactFirst == "referrer" ? "The referrer" : "The client")
                        }
                        LabeledContent("Consent", value: detail.consentConfirmed == true
                                       ? "Client or guardian aware and agreed to contact"
                                       : (detail.source == "school" ? "Via guardian contact" : "Not confirmed"))
                    }
                }
                Section("Prospective client") {
                    LabeledContent("Name", value: detail.clientName)
                    if let dob = detail.clientDob { LabeledContent("DOB", value: formatDate(dob)) }
                    if let phone = detail.clientPhone, !phone.isEmpty { LabeledContent("Phone", value: phone) }
                    if let email = detail.clientEmail, !email.isEmpty { LabeledContent("Email", value: email) }
                    if let address = detail.clientAddress, !address.isEmpty { LabeledContent("Address", value: address) }
                }
                Section("Actions") {
                    if detail.status == "Converted" {
                        Text("This referral follows the assigned instructor on the client record.")
                    } else {
                        Picker("Assigned to", selection: assignmentBinding) {
                            Text("Unassigned").tag(0)
                            // The current assignee stays shown even if no longer active (or no longer
                            // has the referral queue).
                            if let current = detail.assignedTo, current != 0, !referralStaff.contains(where: { $0.id == current }) {
                                Text(detail.assignedName ?? "Current assignee").tag(current)
                            }
                            ForEach(referralStaff) { member in
                                Text(member.name).tag(member.id)
                            }
                        }
                    }
                    if detail.status == "New" {
                        Button("Mark as reviewed") { Task { await review() } }
                    }
                    if detail.status != "Converted" {
                        if hasCounselorEmailToConfirm {
                            VStack(alignment: .leading, spacing: 4) {
                                Toggle("I have confirmed that \(detail.counselorEmail.trimmingCharacters(in: .whitespacesAndNewlines)) belongs to this person's VR counselor",
                                       isOn: $confirmCounselorEmail)
                                Text("This email was typed into the public referral form and has not been verified. Once confirmed on the client record, it receives monthly progress reports and can be invited to the counselor portal. Check it against the authorization paperwork or the agency first; leave this off to convert with it unconfirmed.")
                                    .font(.caption).foregroundStyle(Color.secondaryText)
                            }
                        }
                        Button("Convert to client") { showConvertConfirm = true }
                            .font(.body.weight(.semibold))
                    } else {
                        Text("Converted to a client record ✓").foregroundStyle(Color.brandTeal)
                    }
                }
            } else if error == nil && !handedOff {
                ProgressView()
            }
        }
        .navigationTitle(detail?.clientName ?? "Referral")
        // Picking a name only proposes it: a stray double-tap while browsing the list must not
        // reassign the referral (for a caseload-only account, out of reach for good).
        .confirmationDialog(assignQuestion,
                            isPresented: Binding(get: { pendingAssignee != nil }, set: { if !$0 { pendingAssignee = nil } }),
                            titleVisibility: .visible, presenting: pendingAssignee) { userId in
            Button(userId == 0 ? "Unassign" : "Assign to \(staffName(userId))") { Task { await assign(userId) } }
            Button("Keep the current assignment", role: .cancel) {}
        }
        .confirmationDialog("Convert this referral into a client record? The referral is saved as the client's intake note.",
                            isPresented: $showConvertConfirm, titleVisibility: .visible) {
            Button("Convert to client") { Task { await convert() } }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text(convertNote)
        }
        .refreshable { await load() }
        .task { await load() }
        .disabled(busy)
    }

    /// Only staff who can work the referral queue (the server refuses anyone else, as the
    /// website's list does); an older server that does not say keeps everyone offered.
    private var referralStaff: [StaffMember] {
        staff.filter { $0.canReferrals != false }
    }

    /// A VR counselor referral with an email: converting keeps it unconfirmed unless the
    /// converter ticks the confirmation (the server's own test: source counselor, email set).
    private var hasCounselorEmailToConfirm: Bool {
        guard let detail else { return false }
        return detail.source == "counselor" && !detail.counselorEmail.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    /// The convert question's detail: the duplicate check, then what happens to the counselor email.
    private var convertNote: String {
        guard hasCounselorEmailToConfirm else { return duplicateCheckNote }
        return duplicateCheckNote + (confirmCounselorEmail
            ? " The counselor email is confirmed as genuine."
            : " The counselor email is kept unconfirmed.")
    }

    /// Converting always creates a new client: say what the duplicate check found.
    private var duplicateCheckNote: String {
        guard let dupes = duplicates else {
            return "Check the Clients tab for an existing record of this person first."
        }
        return dupes.isEmpty
            ? "No existing client or other referral matches this name, phone or email."
            : "Possible duplicate: " + dupes.joined(separator: "; ") + ". Check before converting."
    }

    /// The picker shows the saved assignee; a new choice waits for the confirmation dialog.
    private var assignmentBinding: Binding<Int> {
        Binding(
            get: { detail?.assignedTo ?? 0 },
            set: { newValue in
                guard newValue != (detail?.assignedTo ?? 0) else { return }
                pendingAssignee = newValue
            }
        )
    }

    private func staffName(_ userId: Int) -> String {
        staff.first(where: { $0.id == userId })?.name
            ?? (userId == detail?.assignedTo ? detail?.assignedName : nil)
            ?? "the chosen staff member"
    }

    private var assignQuestion: String {
        guard let userId = pendingAssignee else { return "" }
        if userId == 0 { return "Unassign this referral? It goes back to the unassigned queue." }
        var question = "Assign this referral to \(staffName(userId))?"
        if api.scoped && userId != api.userId {
            question += " It leaves your list, and you won't be able to open it again."
        }
        return question
    }

    private func load() async {
        do {
            error = nil
            let response: ReferralDetailResponse = try await api.call("referral", query: ["id": String(referralId)])
            detail = response.referral
            duplicates = response.possibleDuplicates
            if staff.isEmpty {
                let s: StaffResponse = try await api.call("staff")
                staff = s.staff
            }
        } catch {
            // Said as well as shown: the banner is the list's first row, not drawn (or heard)
            // while the person is down at the actions.
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    private func assign(_ userId: Int) async {
        busy = true
        confirmation = nil
        do {
            error = nil
            let _: OKResponse = try await api.call("assign_referral", body: ["id": referralId, "user_id": userId])
            // Only what is known: the server emails the assignee only in some cases and does
            // not report whether the email went.
            let name = staffName(userId)
            var message = userId == 0 ? "Unassigned." : "Assigned to \(name)."
            if api.scoped && userId != 0 && userId != api.userId {
                // A caseload-only account can't open a referral assigned to someone else, so a
                // reload would only fail: the hand-off is the last thing this screen shows.
                message += " It is now on their caseload and leaves your list."
                handedOff = true
                confirmation = message
                onChanged()
                busy = false
                // The picker VoiceOver was on is gone with the other rows: VoiceOver is put on the
                // confirmation, which reads it (announced before that, the focus move cut it off).
                try? await Task.sleep(nanoseconds: 150_000_000)   // let the remaining row appear first
                confirmationFocused = true
                return
            } else {
                confirmation = message
                announce(message)
                await load()
            }
            onChanged()
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }

    private func review() async {
        busy = true
        confirmation = nil
        do {
            error = nil
            let _: OKResponse = try await api.call("review_referral", body: ["id": referralId])
            confirmation = "Marked as reviewed."
            await load()
            onChanged()
            busy = false
            await confirmAfterReload(confirmation!)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }

    /// The reload took away the button VoiceOver was on (Mark as reviewed, Convert to client):
    /// VoiceOver is put on the status, then the result is said. Said before that focus move,
    /// it was cut off by it.
    private func confirmAfterReload(_ message: String) async {
        try? await Task.sleep(nanoseconds: 150_000_000)   // let the reloaded rows appear first
        statusFocused = true
        announceAfterDismiss(message)
    }

    private func convert() async {
        busy = true
        confirmation = nil
        do {
            error = nil
            var payload: [String: Any] = ["id": referralId]
            if hasCounselorEmailToConfirm && confirmCounselorEmail { payload["confirm_counselor_email"] = 1 }
            // Only `ok` is required; requiring the new client's id could report a completed
            // conversion as a failure. The held flags say what was not copied as it stood.
            let r: ConvertReferralResponse = try await api.call("convert_referral", body: payload)
            var message = "Client created — find them on the Clients tab."
            if r.counselorEmailHeld == true {
                message += " The counselor's email is kept unconfirmed: no progress reports or portal link go to it until it is confirmed on the client's edit page on the website."
            }
            if r.clientEmailHeld == true {
                message += " The referral's client email was not one valid address, so it was left off the client record."
            }
            if r.clientEmailUnconfirmed == true {
                message += " The client's email is kept unconfirmed: no reminders, surveys or portal link go to it until it is confirmed on the client's record."
            }
            confirmation = message
            await load()
            onChanged()
            busy = false
            await confirmAfterReload(confirmation!)
        } catch { showFormError(error.localizedDescription, in: &self.error) }
        busy = false
    }
}
