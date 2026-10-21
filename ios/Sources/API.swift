import Foundation
import SwiftUI
#if os(iOS)
import LocalAuthentication
import UIKit
#endif

// MARK: - Models

// `error` is optional so an `ok: false` reply with no message is still treated as a failure
// (never decoded as a successful, tolerant result further down).
/// `processing` (4.29.0): the server's 409 "still being processed" (the same retry key is still running).
struct APIErrorResponse: Decodable { let ok: Bool; let error: String?; let processing: Bool? }

struct LoginStep: Decodable {
    let ok: Bool
    let step: String
    let totpAvailable: Bool
    /// False when the code email could not be sent (only an authenticator-app user gets this
    /// far then); nil from a server that does not report it.
    let emailSent: Bool?
    /// How many minutes the emailed code works for; nil from a server that does not report it.
    let codeExpiresMinutes: Int?
    /// Ties the verify step to this password step (3.0.12 servers): sent back with the code.
    /// nil from a server that does not issue one.
    let loginChallenge: String?
    /// True for an account with an authenticator app (4.27.0): only its code works, and no
    /// code is emailed. False (or not reported) as before.
    let totpRequired: Bool
    private enum CodingKeys: String, CodingKey { case ok, step, totpAvailable, emailSent, codeExpiresMinutes, loginChallenge, totpRequired }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        ok = try c.decode(Bool.self, forKey: .ok)
        step = try c.decode(String.self, forKey: .step)
        totpAvailable = try c.decode(Bool.self, forKey: .totpAvailable)
        // Only changes the wording on the code screen: an odd value must never stop a sign-in.
        emailSent = try? c.decodeIfPresent(Bool.self, forKey: .emailSent)
        codeExpiresMinutes = try? c.decodeIfPresent(Int.self, forKey: .codeExpiresMinutes)
        loginChallenge = try? c.decodeIfPresent(String.self, forKey: .loginChallenge)
        totpRequired = ((try? c.decodeIfPresent(Bool.self, forKey: .totpRequired)) ?? nil) ?? false
    }
}

struct VerifyResult: Decodable {
    let ok: Bool
    let token: String
    let name: String
    let role: String
    let userId: Int?
}

struct DashboardData: Decodable {
    let activeClients: Int
    let pendingClients: Int
    let newReferrals: Int
    let unbilledHours: Double
    let unbilledAmount: Double
    let expiring: [ExpiringTrack]
    /// How many authorizations expire within 30 days in all (the list holds at most 10);
    /// nil from a server that does not report it.
    let expiringTotal: Int?
    let recentReferrals: [RecentReferral]
    let recentNotes: [RecentNote]
    private enum CodingKeys: String, CodingKey { case activeClients, pendingClients, newReferrals, unbilledHours, unbilledAmount, expiring, expiringTotal, recentReferrals, recentNotes }
    // Tolerant decode so one absent field doesn't blank the entire home tab.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        activeClients = (try? c.decode(Int.self, forKey: .activeClients)) ?? 0
        pendingClients = (try? c.decode(Int.self, forKey: .pendingClients)) ?? 0
        newReferrals = (try? c.decode(Int.self, forKey: .newReferrals)) ?? 0
        unbilledHours = (try? c.decode(Double.self, forKey: .unbilledHours)) ?? 0
        unbilledAmount = (try? c.decode(Double.self, forKey: .unbilledAmount)) ?? 0
        expiring = (try? c.decode([ExpiringTrack].self, forKey: .expiring)) ?? []
        expiringTotal = try? c.decodeIfPresent(Int.self, forKey: .expiringTotal)
        recentReferrals = (try? c.decode([RecentReferral].self, forKey: .recentReferrals)) ?? []
        recentNotes = (try? c.decode([RecentNote].self, forKey: .recentNotes)) ?? []
    }
}

struct RecentReferral: Decodable, Identifiable {
    let id: Int
    let clientName: String
    let reason: String
    let status: String
    let createdAt: String
    private enum CodingKeys: String, CodingKey { case id, clientName, reason, status, createdAt }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        reason = (try? c.decode(String.self, forKey: .reason)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
    }
}

struct RecentNote: Decodable, Identifiable {
    let id: Int
    let subject: String
    let noteType: String
    let createdAt: String
    let clientId: Int
    let clientName: String
    private enum CodingKeys: String, CodingKey { case id, subject, noteType, createdAt, clientId, clientName }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        subject = (try? c.decode(String.self, forKey: .subject)) ?? ""
        noteType = (try? c.decode(String.self, forKey: .noteType)) ?? ""
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
    }
}

struct ExpiringTrack: Decodable, Identifiable {
    let id: Int
    let title: String
    let expirationDate: String?
    let client: String
    /// True (3.0.13 servers) for an authorization that has already lapsed while its track
    /// is still Active; nil from older servers, which list upcoming expirations only.
    let isExpired: Bool?
    private enum CodingKeys: String, CodingKey { case id, title, expirationDate, client, isExpired }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        expirationDate = try? c.decodeIfPresent(String.self, forKey: .expirationDate)
        client = (try? c.decode(String.self, forKey: .client)) ?? ""
        isExpired = try? c.decodeIfPresent(Bool.self, forKey: .isExpired)
    }
}

struct ClientRow: Decodable, Identifiable {
    let id: Int
    let name: String
    let status: String
    let phone: String?
    let email: String?
}

struct ClientsResponse: Decodable { let clients: [ClientRow] }

struct ClientInfo: Decodable {
    let id: Int
    let name: String
    let status: String
    let dob: String?
    let phone: String?
    let email: String?
    let address: String?
    let assignedTo: Int?
    let counselorName: String?
    let counselorEmail: String?
    let counselorPhone: String?
    let counselorAgency: String?
    var clientType: String?
    var a11yScreenReader: Bool?
    var a11yFormat: String?
    var a11yPrimaryTech: String?
    var a11yContact: String?
    var a11yNotes: String?
    /// False while the client's email came from the public referral form and staff have not
    /// confirmed it yet; nil from a server that does not report it.
    var emailVerified: Bool?
    /// Parent or guardian (3.0.37); nil from an older server.
    var guardianName: String?
    var guardianPhone: String?
    var guardianEmail: String?
    /// The plan (3.0.45): VR case number, IPE employment goal and dates ("YYYY-MM-DD").
    var vrCaseNumber: String?
    var ipeGoal: String?
    var ipeDate: String?
    var ipeAmendedOn: String?
    /// The client's school (4.0.0); nil from an older server.
    var school: String?
    /// Where they live (4.19.0): a two-letter state code, and a time zone only when one was picked.
    var state: String?
    var timeZone: String?
    /// When the record was last changed (4.28.0), sent back as loaded_at when it is saved, so a
    /// change someone else made since is not undone; nil from an older server.
    var updatedAt: String?
}

/// The choices the website's Lists and time limits page sets (4.5.0), kept on the phone from
/// the last form_options answer: names only, no client information. A list the server has
/// not sent (an older server, or before the first sign-in) uses the app's built-in one.
/// The app's name as the home screen shows it (4.6.0: CMS_DISPLAY_NAME), for "Settings › <name>".
let appDisplayName: String = Bundle.main.object(forInfoDictionaryKey: "CFBundleDisplayName") as? String ?? "this app"

enum FormOptions {
    static let key = "formOptionLists"
    /// Read once from UserDefaults and kept in memory (lists are read on every render).
    private final class Cache: @unchecked Sendable {
        let lock = NSLock()
        var lists: [String: [String]]?
    }
    private static let cache = Cache()
    /// Lists whose length the app relies on (0 to 4 scales): any other length uses the built-in one.
    private static let fixedLength: Set<String> = ["score_labels", "competency_levels"]

    static func list(_ name: String, _ builtIn: [String]) -> [String] {
        cache.lock.lock(); defer { cache.lock.unlock() }
        if cache.lists == nil {
            cache.lists = UserDefaults.standard.dictionary(forKey: key) as? [String: [String]] ?? [:]
        }
        let stored = cache.lists?[name] ?? []
        if stored.isEmpty || (fixedLength.contains(name) && stored.count != builtIn.count) { return builtIn }
        return stored
    }
    static func save(_ lists: [String: [String]]) {
        cache.lock.lock(); defer { cache.lock.unlock() }
        UserDefaults.standard.set(lists, forKey: key)
        cache.lists = lists
    }
    /// Back to the built-in lists: on sign-out, and for a server that does not send them.
    static func clear() {
        cache.lock.lock(); defer { cache.lock.unlock() }
        UserDefaults.standard.removeObject(forKey: key)
        cache.lists = [:]
    }
}

var clientTypes: [String] { FormOptions.list("client_types", ["VR", "Pre-ETS"]) }
let a11yFormats = ["Standard print", "Large print", "Plain text", "Braille-ready (BRF)", "Audio", "Accessible PDF"]
let a11yContacts = ["No preference", "Email", "Phone", "Client portal", "Postal mail"]

/// Client statuses: the server's list (4.11.0, an administrator can add some), else the built-in six.
var clientStatuses: [String] {
    FormOptions.list("client_statuses", ["Pending Assessment", "Waitlist", "Active Training", "On Hold", "Closed - Rehabilitated", "Closed - Other"])
}
var techTypes: [String] {
    FormOptions.list("tech_types", ["Screen Reader", "Magnification", "Braille Display", "Speech-to-Text",
                                    "Independent Living Skills", "Braille Literacy", "Low Vision Devices",
                                    "Rehabilitation Technology", "Other"])
}
let goalStatuses = ["Not Started", "In Progress", "Achieved"]
var docCategories: [String] { FormOptions.list("doc_categories", ["Authorization", "IPE", "Release", "Report", "Resource", "Other"]) }

struct Note: Decodable, Identifiable {
    let id: Int
    let noteType: String
    let subject: String
    let body: String
    let createdAt: String
    let updatedAt: String?
    let author: String?
    let editor: String?
    let canEdit: Bool
    var noteFormat: String? = "free"
    var trackId: Int?
    var trackTitle: String?
    var sections: [String]?
    /// Past the 30-day edit window (3.0.36): it cannot be edited; an addendum is added instead.
    var locked = false
    /// The note this one is an addendum to, if any.
    var addendumTo: Int?
    /// The saved version (4.29.0), sent back with update_note; nil from an older server.
    var noteVersion: String?
    private enum CodingKeys: String, CodingKey { case id, noteType, subject, body, createdAt, updatedAt, author, editor, canEdit, noteFormat, trackId, trackTitle, sections, locked, addendumTo, noteVersion }
    // Tolerant decode: a single note with a null field must not fail the whole client
    // record and blank the detail screen for a VoiceOver user. Only id is required.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        noteType = (try? c.decode(String.self, forKey: .noteType)) ?? "Case Note"
        subject = (try? c.decode(String.self, forKey: .subject)) ?? ""
        body = (try? c.decode(String.self, forKey: .body)) ?? ""
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        updatedAt = try? c.decodeIfPresent(String.self, forKey: .updatedAt)
        author = try? c.decodeIfPresent(String.self, forKey: .author)
        editor = try? c.decodeIfPresent(String.self, forKey: .editor)
        canEdit = (try? c.decode(Bool.self, forKey: .canEdit)) ?? false
        noteFormat = (try? c.decodeIfPresent(String.self, forKey: .noteFormat)) ?? "free"
        trackId = try? c.decodeIfPresent(Int.self, forKey: .trackId)
        trackTitle = try? c.decodeIfPresent(String.self, forKey: .trackTitle)
        sections = try? c.decodeIfPresent([String].self, forKey: .sections)
        locked = (try? c.decode(Bool.self, forKey: .locked)) ?? false
        addendumTo = try? c.decodeIfPresent(Int.self, forKey: .addendumTo)
        noteVersion = try? c.decodeIfPresent(String.self, forKey: .noteVersion)
    }
}

/// A release of information (3.0.36): who may receive what, and whether it is in force.
struct ClientRelease: Decodable, Identifiable {
    let id: Int
    let recipientType: String
    let recipientName: String
    let status: String
    let signedName: String?
    let expiresOn: String?
    let infoScope: String
    private enum CodingKeys: String, CodingKey { case id, recipientType, recipientName, status, signedName, expiresOn, infoScope }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        recipientType = (try? c.decode(String.self, forKey: .recipientType)) ?? ""
        recipientName = (try? c.decode(String.self, forKey: .recipientName)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        signedName = try? c.decodeIfPresent(String.self, forKey: .signedName)
        expiresOn = try? c.decodeIfPresent(String.self, forKey: .expiresOn)
        infoScope = (try? c.decode(String.self, forKey: .infoScope)) ?? ""
    }
}

let noteFormats = ["free", "BIRP", "PAIP", "SOAP"]
let noteFormatLabels: [String: [String]] = [
    "free": [],
    "BIRP": ["Behavior", "Intervention", "Response", "Plan"],
    "PAIP": ["Purpose", "Assessment", "Intervention", "Plan"],
    "SOAP": ["Subjective", "Objective", "Assessment", "Plan"],
]

struct Track: Decodable, Identifiable {
    let id: Int
    let title: String
    let serviceType: String?
    let status: String
    let authNumber: String
    let authorizedHours: Double
    let hourlyRate: Double
    let expirationDate: String?
    let hoursWorked: Double
    let hoursRemaining: Double
    let pctUsed: Double
    private enum CodingKeys: String, CodingKey { case id, title, serviceType, status, authNumber, authorizedHours, hourlyRate, expirationDate, hoursWorked, hoursRemaining, pctUsed }
    // Tolerant decode: a null auth number (nullable column) or any absent numeric must
    // not fail the whole decode — Track is nested in the client record, so a failure
    // would blank the entire client screen for a VoiceOver user.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        serviceType = try? c.decodeIfPresent(String.self, forKey: .serviceType)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        authNumber = (try? c.decode(String.self, forKey: .authNumber)) ?? ""
        authorizedHours = (try? c.decode(Double.self, forKey: .authorizedHours)) ?? 0
        hourlyRate = (try? c.decode(Double.self, forKey: .hourlyRate)) ?? 0
        expirationDate = try? c.decodeIfPresent(String.self, forKey: .expirationDate)
        hoursWorked = (try? c.decode(Double.self, forKey: .hoursWorked)) ?? 0
        hoursRemaining = (try? c.decode(Double.self, forKey: .hoursRemaining)) ?? 0
        pctUsed = (try? c.decode(Double.self, forKey: .pctUsed)) ?? 0
    }
}

struct ClientDetail: Decodable {
    let client: ClientInfo
    let notes: [Note]
    let tracks: [Track]
    /// All of the client's notes; `notes` holds the newest 50 (older ones come from
    /// notes_offset). nil from a server that does not report it.
    let notesTotal: Int?
    /// Releases of information, newest first ([] from an older server).
    var releases: [ClientRelease] = []
    /// Whether the client agreed to texts (4.17.0; nil from an older server).
    var texting: ClientTexting?
    /// Office, custom fields, device counts and documents waiting to be signed (4.25.0), read
    /// from the same client object; empty from an older server.
    var extras = ClientExtras()
    private enum CodingKeys: String, CodingKey { case client, notes, tracks, notesTotal, releases, texting, updatedAt }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        var info = try c.decode(ClientInfo.self, forKey: .client)
        // updated_at in the client object, or beside it (4.28.0).
        if info.updatedAt == nil, let at = try? c.decodeIfPresent(String.self, forKey: .updatedAt) { info.updatedAt = at }
        client = info
        notes = try c.decode([Note].self, forKey: .notes)
        tracks = try c.decode([Track].self, forKey: .tracks)
        notesTotal = try? c.decodeIfPresent(Int.self, forKey: .notesTotal)
        releases = (try? c.decodeIfPresent([ClientRelease].self, forKey: .releases)) ?? []
        texting = try? c.decodeIfPresent(ClientTexting.self, forKey: .texting)
        extras = (try? c.decode(ClientExtras.self, forKey: .client)) ?? ClientExtras()
    }
}

/// The 4.25.0 additions to the client object. Every field is optional on the wire, so a
/// missing or odd one never fails the client record.
struct ClientExtras: Decodable {
    var officeId: Int?
    var office: String?
    var customFields: [CustomFieldValue] = []
    var customFieldsEdit: [CustomFieldEdit] = []
    /// Empty: the organisation uses no offices, so nothing about offices is shown.
    var offices: [OfficeChoice] = []
    var deviceCounts = DeviceCounts()
    var signaturesWaiting = 0
    /// The server has the 4.25.0 features (message files, document sharing, documents to sign,
    /// release requests): its client record carries device_counts. An older server would ignore them.
    var supports425 = false
    private enum CodingKeys: String, CodingKey { case officeId, office, customFields, customFieldsEdit, offices, deviceCounts, signaturesWaiting }
    init() {}
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        officeId = try? c.decodeIfPresent(Int.self, forKey: .officeId)
        office = try? c.decodeIfPresent(String.self, forKey: .office)
        customFields = LenientList.decode(CustomFieldValue.self, from: c, forKey: .customFields)
        customFieldsEdit = LenientList.decode(CustomFieldEdit.self, from: c, forKey: .customFieldsEdit)
        offices = LenientList.decode(OfficeChoice.self, from: c, forKey: .offices)
        deviceCounts = (try? c.decodeIfPresent(DeviceCounts.self, forKey: .deviceCounts)) ?? DeviceCounts()
        signaturesWaiting = (try? c.decodeIfPresent(Int.self, forKey: .signaturesWaiting)) ?? 0
        supports425 = c.contains(.deviceCounts)
    }
}

/// Whether the server has the 4.25.0 features, read from a client record alone (the message
/// thread asks before offering attachments).
struct ClientFeatureProbe: Decodable {
    let supports425: Bool
    private enum CodingKeys: String, CodingKey { case client }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        supports425 = (try? c.decode(ClientExtras.self, forKey: .client))?.supports425 ?? false
    }
}

/// A list whose unreadable elements are dropped instead of failing the whole list. A plain
/// Decodable struct (not generic itself), so tests/ios_models.py picks it up with the models.
struct LenientList: Decodable {
    init(from d: Decoder) throws {}
    struct Element<T: Decodable>: Decodable {
        let value: T?
        init(from d: Decoder) throws { value = try? T(from: d) }
    }
    static func decode<T: Decodable, K: CodingKey>(_ type: T.Type, from c: KeyedDecodingContainer<K>, forKey key: K) -> [T] {
        ((try? c.decodeIfPresent([Element<T>].self, forKey: key)) ?? []).compactMap(\.value)
    }
}

/// An answered custom field, for display.
struct CustomFieldValue: Decodable {
    let label: String
    let value: String
    private enum CodingKeys: String, CodingKey { case label, value }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        label = (try? c.decode(String.self, forKey: .label)) ?? ""
        value = (try? c.decode(String.self, forKey: .value)) ?? (try? c.decode(Double.self, forKey: .value)).map { String(format: "%g", $0) } ?? ""
    }
}

/// A custom field in use, with the client's answer ("" = not answered; a date is YYYY-MM-DD).
struct CustomFieldEdit: Decodable, Identifiable {
    let id: Int
    let label: String
    let hint: String
    /// "text", "textarea", "number", "date", "choice" or "yesno".
    let type: String
    let options: [String]
    let required: Bool
    let value: String
    private enum CodingKeys: String, CodingKey { case id, label, hint, type, options, required, value }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        label = (try? c.decode(String.self, forKey: .label)) ?? ""
        hint = (try? c.decode(String.self, forKey: .hint)) ?? ""
        type = (try? c.decode(String.self, forKey: .type)) ?? "text"
        options = (try? c.decode([String].self, forKey: .options)) ?? []
        required = (try? c.decode(Bool.self, forKey: .required)) ?? ((try? c.decode(Int.self, forKey: .required)) ?? 0 != 0)
        value = (try? c.decode(String.self, forKey: .value)) ?? (try? c.decode(Double.self, forKey: .value)).map { String(format: "%g", $0) } ?? ""
    }
}

struct OfficeChoice: Decodable, Identifiable {
    let id: Int
    let name: String
    private enum CodingKeys: String, CodingKey { case id, name }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? "Office \(id)"
    }
}

/// What the new client form offers (form_options, 4.25.0): the custom fields (values "") and the
/// offices (empty when none). Both empty from an older server.
struct NewClientOptions: Decodable {
    let customFields: [CustomFieldEdit]
    let offices: [OfficeChoice]
    private enum CodingKeys: String, CodingKey { case customFields, offices }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        customFields = LenientList.decode(CustomFieldEdit.self, from: c, forKey: .customFields)
        offices = LenientList.decode(OfficeChoice.self, from: c, forKey: .offices)
    }
}

/// The client's devices as the device requests record them, to start a closure's counts.
struct DeviceCounts: Decodable {
    var recommended = 0
    var acquired = 0
    private enum CodingKeys: String, CodingKey { case recommended, acquired }
    init() {}
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        recommended = (try? c.decode(Int.self, forKey: .recommended)) ?? 0
        acquired = (try? c.decode(Int.self, forKey: .acquired)) ?? 0
    }
}

// MARK: - Documents to sign and releases (4.25.0)

/// A document sent to sign, or waiting to be signed here.
struct SignatureRow: Decodable, Identifiable {
    let id: Int
    let kind: String
    let title: String
    /// "Awaiting signature", "Waiting to be signed here", "Signed", "Withdrawn" or "Link expired".
    let status: String
    /// "Email" or "In person".
    let method: String
    let signerName: String
    let signerEmail: String
    let sentAt: String?
    let signedAt: String?
    let signedName: String?
    let hasDrawnSignature: Bool
    private enum CodingKeys: String, CodingKey { case id, kind, title, status, method, signerName, signerEmail, sentAt, signedAt, signedName, hasDrawnSignature }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        kind = (try? c.decode(String.self, forKey: .kind)) ?? ""
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        method = (try? c.decode(String.self, forKey: .method)) ?? "Email"
        signerName = (try? c.decode(String.self, forKey: .signerName)) ?? ""
        signerEmail = (try? c.decode(String.self, forKey: .signerEmail)) ?? ""
        sentAt = try? c.decodeIfPresent(String.self, forKey: .sentAt)
        signedAt = try? c.decodeIfPresent(String.self, forKey: .signedAt)
        signedName = try? c.decodeIfPresent(String.self, forKey: .signedName)
        hasDrawnSignature = (try? c.decode(Bool.self, forKey: .hasDrawnSignature)) ?? ((try? c.decode(Int.self, forKey: .hasDrawnSignature)) ?? 0 != 0)
    }
    /// Still waiting for a signature, so it can be withdrawn.
    var isOpen: Bool { status == "Awaiting signature" || status == "Waiting to be signed here" }
}

/// An open equipment loan a loan agreement can be for.
struct SignatureLoan: Decodable, Identifiable {
    let id: Int
    let name: String
    let serial: String?
    let loanedAt: String?
    private enum CodingKeys: String, CodingKey { case id, name, serial, loanedAt }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = (try? c.decode(String.self, forKey: .name)) ?? "Item \(id)"
        serial = try? c.decodeIfPresent(String.self, forKey: .serial)
        loanedAt = try? c.decodeIfPresent(String.self, forKey: .loanedAt)
    }
}

struct SignaturesResponse: Decodable {
    var signatures: [SignatureRow]
    /// Kind key ("services", "loan", "document") to its name.
    let kinds: [String: String]
    let openLoans: [SignatureLoan]
    private enum CodingKeys: String, CodingKey { case signatures, kinds, openLoans }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        signatures = LenientList.decode(SignatureRow.self, from: c, forKey: .signatures)
        kinds = (try? c.decodeIfPresent([String: String].self, forKey: .kinds)) ?? [:]
        openLoans = LenientList.decode(SignatureLoan.self, from: c, forKey: .openLoans)
    }
}

/// A document to sign here, in person: what the signer reads (start_signature, or signature for one already started).
struct SigningDocument: Decodable, Identifiable {
    let id: Int
    let title: String
    /// Plain text: a blank line separates paragraphs; several lines in a paragraph are a list.
    let body: String
    /// "en" or "es" (nil when not known).
    let lang: String?
    let signerName: String
    let signerRole: String?
    let org: String
    /// When the server started it ("Y-m-d H:i:s", the server's time zone): start_signature's
    /// reply carries it (4.26.0); nil from an older server or the `signature` reply.
    let sentAt: String?
    private enum CodingKeys: String, CodingKey { case id, title, body, lang, signerName, signerRole, org, sentAt }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        body = (try? c.decode(String.self, forKey: .body)) ?? ""
        lang = try? c.decodeIfPresent(String.self, forKey: .lang)
        signerName = (try? c.decode(String.self, forKey: .signerName)) ?? ""
        signerRole = try? c.decodeIfPresent(String.self, forKey: .signerRole)
        org = (try? c.decode(String.self, forKey: .org)) ?? ""
        sentAt = try? c.decodeIfPresent(String.self, forKey: .sentAt)
    }
}

/// The reply to `signature`: the document under `signature`.
struct SigningDocumentResponse: Decodable { let signature: SigningDocument }

/// The reply to sign_in_person and withdraw_signature: the list as it now stands.
struct SignatureListReply: Decodable {
    let signatures: [SignatureRow]?
    private enum CodingKeys: String, CodingKey { case signatures }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        signatures = c.contains(.signatures) ? LenientList.decode(SignatureRow.self, from: c, forKey: .signatures) : nil
    }
}

/// What a release of information can name: recipient types and kinds of information.
struct ReleaseChoices: Decodable {
    let recipientTypes: [String]
    let infoItems: [String]
    let validityDays: Int?
    private enum CodingKeys: String, CodingKey { case recipientTypes, infoItems, validityDays }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        recipientTypes = (try? c.decode([String].self, forKey: .recipientTypes)) ?? []
        infoItems = (try? c.decode([String].self, forKey: .infoItems)) ?? []
        validityDays = try? c.decodeIfPresent(Int.self, forKey: .validityDays)
    }
}

/// Referral deadlines over the last 90 days (the dashboard's figure).
struct ReferralDeadlines: Decodable {
    let referrals: Int
    let contactedOnTime: Int
    private enum CodingKeys: String, CodingKey { case referrals, contactedOnTime }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        referrals = (try? c.decode(Int.self, forKey: .referrals)) ?? 0
        contactedOnTime = (try? c.decode(Int.self, forKey: .contactedOnTime)) ?? 0
    }
}

/// A client's agreement to text messages (4.17.0), as the website's client page says it.
struct ClientTexting: Decodable {
    let enabled: Bool
    let agreed: Bool
    let optedOut: Bool
    let source: String?
    let words: String
    let blocked: String?
}

struct Session: Decodable, Identifiable {
    let id: Int
    let startAt: String
    let endAt: String
    let location: String?
    let notes: String?
    let status: String
    let trackId: Int?
    let client: String
    let instructor: String?
    var isRemote: Bool? = false
    var meetingUrl: String?
    /// True when the session has ended but is still Scheduled (no outcome recorded yet);
    /// nil from a server that does not report it.
    var overdue: Bool?
    /// When the session was checked in ("YYYY-MM-DD HH:MM:SS", server time), nil when it has
    /// not been, or from a server that does not report it (3.0.28 adds check_in_session).
    var checkedInAt: String?
    /// Part of a weekly series, or of a group (3.0.43): the rest can be cancelled in one step.
    var inSeries = false
    var groupUid: String?
    /// The client and the instructor (4.0.0): for the client record link and "My sessions".
    var clientId: Int?
    var userId: Int?
    /// A Completed session whose case note is not written yet (4.31.0); nil from a server that
    /// does not report it.
    var notePending: Bool?
    private enum CodingKeys: String, CodingKey { case id, startAt, endAt, location, notes, status, trackId, client, instructor, isRemote, meetingUrl, overdue, checkedInAt, inSeries, groupUid, clientId, userId, notePending }
    // Tolerant decode: endAt is never displayed, so a null on any row must not blank the
    // whole Schedule tab. Only id is required.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        startAt = (try? c.decode(String.self, forKey: .startAt)) ?? ""
        endAt = (try? c.decode(String.self, forKey: .endAt)) ?? ""
        location = try? c.decodeIfPresent(String.self, forKey: .location)
        notes = try? c.decodeIfPresent(String.self, forKey: .notes)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        trackId = try? c.decodeIfPresent(Int.self, forKey: .trackId)
        client = (try? c.decode(String.self, forKey: .client)) ?? ""
        instructor = try? c.decodeIfPresent(String.self, forKey: .instructor)
        isRemote = (try? c.decodeIfPresent(Bool.self, forKey: .isRemote)) ?? false
        meetingUrl = try? c.decodeIfPresent(String.self, forKey: .meetingUrl)
        overdue = try? c.decodeIfPresent(Bool.self, forKey: .overdue)
        let checked = (try? c.decodeIfPresent(String.self, forKey: .checkedInAt))?.trimmingCharacters(in: .whitespaces)
        checkedInAt = (checked?.isEmpty ?? true) ? nil : checked
        inSeries = (try? c.decodeIfPresent(Bool.self, forKey: .inSeries)) ?? false
        groupUid = try? c.decodeIfPresent(String.self, forKey: .groupUid)
        clientId = try? c.decodeIfPresent(Int.self, forKey: .clientId)
        userId = try? c.decodeIfPresent(Int.self, forKey: .userId)
        notePending = SessionNoteTarget.flag(c, .notePending)
    }
}

/// A completed session whose case note is to be written (4.31.0): what notes_to_write lists,
/// and what the case-note editor opens with after a completion. Tolerant: only the session
/// and the client are required.
struct SessionNoteTarget: Decodable, Identifiable, Hashable {
    let sessionId: Int
    let clientId: Int
    let clientName: String
    let trackId: Int?
    /// The session's day, yyyy-MM-dd ("" when not known).
    let sessionDate: String
    let startAt: String?
    /// "Session on Oct 20", in the organisation's date format (the server's), or the app's own.
    let noteSubject: String
    var id: Int { sessionId }

    private enum CodingKeys: String, CodingKey { case sessionId, clientId, clientName, trackId, sessionDate, startAt, noteSubject }

    init(sessionId: Int, clientId: Int, clientName: String, trackId: Int?, sessionDate: String, startAt: String? = nil, noteSubject: String?) {
        self.sessionId = sessionId
        self.clientId = clientId
        self.clientName = clientName
        self.trackId = (trackId ?? 0) > 0 ? trackId : nil
        self.sessionDate = sessionDate
        self.startAt = startAt
        let subject = (noteSubject ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        self.noteSubject = subject.isEmpty ? Self.fallbackSubject(day: sessionDate) : subject
    }

    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        guard let sid = Self.int(c, .sessionId), let cid = Self.int(c, .clientId) else {
            throw DecodingError.dataCorruptedError(forKey: .sessionId, in: c, debugDescription: "no session or client")
        }
        let start = (try? c.decodeIfPresent(String.self, forKey: .startAt)) ?? nil
        let day = (try? c.decodeIfPresent(String.self, forKey: .sessionDate)) ?? nil
        self.init(sessionId: sid, clientId: cid,
                  clientName: ((try? c.decodeIfPresent(String.self, forKey: .clientName)) ?? nil) ?? "",
                  trackId: Self.int(c, .trackId),
                  sessionDate: String((day ?? start ?? "").prefix(10)),
                  startAt: start,
                  noteSubject: (try? c.decodeIfPresent(String.self, forKey: .noteSubject)) ?? nil)
    }

    /// An id sent as a number or as text.
    static func int<K: CodingKey>(_ c: KeyedDecodingContainer<K>, _ key: K) -> Int? {
        if let n = try? c.decodeIfPresent(Int.self, forKey: key) { return n }
        if let t = try? c.decodeIfPresent(String.self, forKey: key) { return Int(t) }
        return nil
    }

    /// A flag sent as true/false, 1/0 or "1"/"0".
    static func flag<K: CodingKey>(_ c: KeyedDecodingContainer<K>, _ key: K) -> Bool? {
        if let b = try? c.decodeIfPresent(Bool.self, forKey: key) { return b }
        if let n = try? c.decodeIfPresent(Int.self, forKey: key) { return n != 0 }
        if let t = try? c.decodeIfPresent(String.self, forKey: key) { return t == "1" || t.lowercased() == "true" }
        return nil
    }

    /// "Session on Oct 20" for a server older than 4.31.0, which sends no note_subject; "Session"
    /// for a day that cannot be read.
    static func fallbackSubject(day: String) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.calendar = Calendar(identifier: .gregorian)
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd"
        guard day.count >= 10, let date = f.date(from: String(day.prefix(10))) else { return "Session" }
        f.dateFormat = "MMM d"
        return "Session on " + f.string(from: date)
    }

    /// Where the editor is, said and shown at its top: "Case note for Jane Doe, session on Oct 20".
    var contextLine: String {
        let name = clientName.isEmpty ? "this client" : clientName
        let what = noteSubject.hasPrefix("Session") ? "s" + noteSubject.dropFirst() : noteSubject
        return "Case note for \(name), \(what)"
    }

    /// The editor's target after complete_session: the reply's fields (4.31.0), else the
    /// session's own (an older server). nil when the client is not known.
    static func afterCompletion(_ reply: CompleteSessionReply, session: Session) -> SessionNoteTarget? {
        guard let clientId = reply.clientId ?? session.clientId, clientId > 0 else { return nil }
        let day = reply.sessionDate ?? String(session.startAt.prefix(10))
        return SessionNoteTarget(sessionId: reply.sessionId ?? session.id, clientId: clientId,
                                 clientName: reply.clientName ?? session.client,
                                 trackId: reply.trackId ?? session.trackId, sessionDate: day,
                                 startAt: session.startAt, noteSubject: reply.noteSubject)
    }
}

/// notes_to_write (4.31.0): the signed-in person's completed sessions still without a case
/// note, newest first. The list is read from "notes" (or "sessions" / "items"); a row that
/// cannot be read is left out.
struct NotesToWriteResponse: Decodable {
    let notes: [SessionNoteTarget]
    let total: Int
    private enum CodingKeys: String, CodingKey { case notes, sessions, items, total }
    private struct Lenient: Decodable {
        let value: SessionNoteTarget?
        init(from d: Decoder) throws { value = try? SessionNoteTarget(from: d) }
    }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        var rows: [SessionNoteTarget] = []
        for key in [CodingKeys.notes, .sessions, .items] {
            if let list = try? c.decodeIfPresent([Lenient].self, forKey: key) { rows = list.compactMap(\.value); break }
        }
        notes = rows
        total = SessionNoteTarget.int(c, .total) ?? rows.count
    }
}

/// complete_session's reply. Tolerant: once the server says ok, a missing detail must not turn
/// a completed (and billed) session into an error the user would retry. The note fields
/// (4.31.0) are nil from an older server.
struct CompleteSessionReply: Decodable {
    let hoursLogged: Double
    /// The hours were logged outside the track's authorization (expired, not yet started, or
    /// the track not Active): the server's sentence saying so (3.0.21).
    let warning: String?
    let clientId: Int?
    let clientName: String?
    let trackId: Int?
    let sessionId: Int?
    let sessionDate: String?
    let noteSubject: String?
    let notePending: Bool?
    private enum CodingKeys: String, CodingKey { case ok, hoursLogged, warning, clientId, clientName, trackId, sessionId, sessionDate, noteSubject, notePending }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard (try? c.decode(Bool.self, forKey: .ok)) == true else {
            throw DecodingError.dataCorruptedError(forKey: .ok, in: c, debugDescription: "not ok")
        }
        hoursLogged = (try? c.decode(Double.self, forKey: .hoursLogged)) ?? 0
        let text = (try? c.decodeIfPresent(String.self, forKey: .warning))?
            .trimmingCharacters(in: .whitespacesAndNewlines)
        warning = (text?.isEmpty ?? true) ? nil : text
        clientId = SessionNoteTarget.int(c, .clientId)
        let name = (try? c.decodeIfPresent(String.self, forKey: .clientName)) ?? nil
        clientName = (name?.isEmpty ?? true) ? nil : name
        trackId = SessionNoteTarget.int(c, .trackId)
        sessionId = SessionNoteTarget.int(c, .sessionId)
        let day = (try? c.decodeIfPresent(String.self, forKey: .sessionDate)) ?? nil
        sessionDate = (day?.count ?? 0) >= 10 ? day.map { String($0.prefix(10)) } : nil
        noteSubject = (try? c.decodeIfPresent(String.self, forKey: .noteSubject)) ?? nil
        notePending = SessionNoteTarget.flag(c, .notePending)
    }
}

/// move_session's reply (3.0.43): moved, or the warnings to confirm first.
/// `warningsHash` (4.28.0): sent back as confirm_hash with Move anyway, so it approves exactly
/// the warnings shown for this time.
struct MoveSessionResult: Decodable { let moved: Bool?; let warnings: [String]?; let message: String?; var warningsHash: String? }

struct ScheduleResponse: Decodable { let sessions: [Session] }

struct TrackOption: Decodable, Identifiable, Hashable {
    let id: Int
    let title: String
    let serviceType: String?
    let program: String?
    let visitCategories: [String]?
    let client: String
    var clientId: Int?   // match tracks to a client by id, not display name
}

struct MeResponse: Decodable {
    let ok: Bool
    let id: Int
    let name: String
    let perms: [String]?
    let scoped: Bool?
    let aiEnabled: Bool?
    let aiFeatures: [String]?
    /// What must be finished before client records open (4.16.0); nil from an older server.
    let accessHold: [String]?
    /// The server's time zone, such as "America/Chicago"; nil from a server that does not report it.
    let timezone: String?
    /// The server's release, such as "3.0.21"; nil from a server that does not report it.
    let serverVersion: String?
}

var serviceTypes: [String] { FormOptions.list("service_types", ["Assistive Technology", "Rehab Teaching", "Counseling", "Pre-ETS", "Other"]) }

struct TracksResponse: Decodable { let tracks: [TrackOption] }

struct OKResponse: Decodable { let ok: Bool }

// MARK: - Case-note generator (v2.64 parity)

struct GenHomework: Decodable, Identifiable, Hashable {
    var id = UUID()
    var title: String
    var instructions: String
    var due: String
    var include: Bool = true
    enum CodingKeys: String, CodingKey { case title, instructions, due }
}

struct GenerateNoteResponse: Decodable {
    let format: String
    let labels: [String]
    let sections: [String]
    let questions: [String]
    let homework: [GenHomework]
}

// MARK: - Device follow-ups (v2.45 parity)

struct Followup: Decodable, Identifiable {
    let id: Int
    let clientId: Int
    let equipmentId: Int
    let intervalDays: Int
    let dueDate: String
    let status: String
    let inUse: Int?
    let satisfaction: Int?
    let barriers: String?
    let notes: String?
    let completedAt: String?
    let clientName: String
    let equipmentName: String
    let assetTag: String?
    /// The client's counselor (4.28.0; nil from a server that does not send it): the 8:00
    /// reminder counts only the signed-in person's own follow-ups.
    let assignedTo: Int?
    private enum CodingKeys: String, CodingKey { case id, clientId, equipmentId, intervalDays, dueDate, status, inUse, satisfaction, barriers, notes, completedAt, clientName, equipmentName, assetTag, assignedTo }
    // Tolerant decode so one malformed follow-up can't blank the whole list.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        equipmentId = (try? c.decode(Int.self, forKey: .equipmentId)) ?? 0
        intervalDays = (try? c.decode(Int.self, forKey: .intervalDays)) ?? 0
        dueDate = (try? c.decode(String.self, forKey: .dueDate)) ?? ""
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        inUse = try? c.decodeIfPresent(Int.self, forKey: .inUse)
        satisfaction = try? c.decodeIfPresent(Int.self, forKey: .satisfaction)
        barriers = try? c.decodeIfPresent(String.self, forKey: .barriers)
        notes = try? c.decodeIfPresent(String.self, forKey: .notes)
        completedAt = try? c.decodeIfPresent(String.self, forKey: .completedAt)
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        equipmentName = (try? c.decode(String.self, forKey: .equipmentName)) ?? ""
        assetTag = try? c.decodeIfPresent(String.self, forKey: .assetTag)
        assignedTo = (try? c.decodeIfPresent(Int.self, forKey: .assignedTo)) ?? nil
    }
}

struct FollowupsResponse: Decodable {
    let retention: Int?
    let abandoned: Int
    let completed: Int
    let due: [Followup]
    let upcoming: [Followup]
    let recent: [Followup]
}

// MARK: - Secure messages (v2.53 parity)

struct MessageThread: Decodable, Identifiable {
    var id: Int { clientId }
    let clientId: Int
    let clientName: String
    let total: Int
    let unread: Int
    let lastAt: String?
}
struct MessageThreadsResponse: Decodable { let threads: [MessageThread] }

struct ThreadMessage: Decodable, Identifiable {
    let id: Int
    let channel: String
    let sender: String
    let body: String
    let createdAt: String
    let staffName: String?
    /// "text" when it went (or came) by text message (4.17.0).
    let via: String?
    /// The file sent with it (4.25.0), opened with download_document; nil when none.
    let documentId: Int?
    let documentTitle: String?
    private enum CodingKeys: String, CodingKey { case id, channel, sender, body, createdAt, staffName, via, documentId, documentTitle }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        channel = (try? c.decode(String.self, forKey: .channel)) ?? "client"
        sender = (try? c.decode(String.self, forKey: .sender)) ?? ""
        body = (try? c.decode(String.self, forKey: .body)) ?? ""
        createdAt = (try? c.decode(String.self, forKey: .createdAt)) ?? ""
        staffName = try? c.decodeIfPresent(String.self, forKey: .staffName)
        via = try? c.decodeIfPresent(String.self, forKey: .via)
        documentId = try? c.decodeIfPresent(Int.self, forKey: .documentId)
        documentTitle = try? c.decodeIfPresent(String.self, forKey: .documentTitle)
    }
}
struct ThreadResponse: Decodable {
    let clientName: String
    let counselorName: String?
    let channel: String
    let messages: [ThreadMessage]
    /// Texting (4.17.0): set up, and why this client cannot be texted (nil: they can).
    let textEnabled: Bool?
    let textBlocked: String?
}

// MARK: - Incidents (v2.54 parity)

struct Incident: Decodable, Identifiable {
    let id: Int
    let clientId: Int?
    let incidentDate: String
    let location: String?
    let type: String
    let severity: String
    let description: String
    let actionTaken: String?
    let status: String
    let clientName: String?
    let reporter: String?
    let canEdit: Bool
    private enum CodingKeys: String, CodingKey { case id, clientId, incidentDate, location, type, severity, description, actionTaken, status, clientName, reporter, canEdit }
    // Tolerant decode so one malformed incident can't blank the whole Incidents list.
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        clientId = try? c.decodeIfPresent(Int.self, forKey: .clientId)
        incidentDate = (try? c.decode(String.self, forKey: .incidentDate)) ?? ""
        location = try? c.decodeIfPresent(String.self, forKey: .location)
        type = (try? c.decode(String.self, forKey: .type)) ?? ""
        severity = (try? c.decode(String.self, forKey: .severity)) ?? ""
        description = (try? c.decode(String.self, forKey: .description)) ?? ""
        actionTaken = try? c.decodeIfPresent(String.self, forKey: .actionTaken)
        status = (try? c.decode(String.self, forKey: .status)) ?? ""
        clientName = try? c.decodeIfPresent(String.self, forKey: .clientName)
        reporter = try? c.decodeIfPresent(String.self, forKey: .reporter)
        canEdit = (try? c.decode(Bool.self, forKey: .canEdit)) ?? false
    }
}
struct IncidentsResponse: Decodable {
    let incidents: [Incident]
    let statuses: [String]
    let types: [String]
    let severities: [String]
}

// MARK: - Utilization (v2.56 parity)

struct UtilRow: Decodable, Identifiable {
    var id: String { name }
    let name: String
    let weekly: Double
    let delivered: Double
    let value: Double
    let clients: Int
    let expected: Double
    let util: Int
}
struct UtilizationResponse: Decodable {
    let caseloadScoped: Bool?
    let from: String
    let to: String
    let orgUtil: Int
    let totalDelivered: Double
    let totalValue: Double
    let rows: [UtilRow]
}

// MARK: - Amendment burn-down (v2.52 parity)

struct AmendmentResponse: Decodable {
    let client: String
    let title: String
    let worked: Double
    let authorized: Double
    let remaining: Double
    let pct: Int
    let weeklyRate: Double
    let runOut: String?
    let expiration: String?
    let suggestAdd: Int
    /// Set (3.0.11 servers) when mileage and expenses use the authorized funds up faster
    /// than the hours: runOut is then the date the funds run out and the letter asks for
    /// suggestFunds dollars, not suggestAdd hours. Optional so older servers still decode.
    let fundsLimit: Bool?
    /// "funds" when runOut is the date the funds run out, "hours" when it is the date the
    /// hours do (3.0.12 servers); nil from older servers.
    let runOutBasis: String?
    /// True (3.0.13 servers) when the authorization has already expired: runOut is then
    /// nil and the letter says the authorization expired on that date.
    let expired: Bool?
    let fundsAuthorized: Double?
    let fundsUsed: Double?
    let fundsRemaining: Double?
    let fundsPct: Int?
    let weeklySpend: Double?
    let suggestFunds: Double?
    let counselorName: String?
    let counselorEmail: String?
    let letter: String
    let aiNote: String
}

// MARK: - Document previews

/// Where a downloaded client document lives while it is being previewed: one known folder
/// in tmp, so anything a crash, an expired session or a sign-out left behind can be removed
/// in one sweep (at launch and whenever the signed-in identity is cleared).
enum PreviewStore {
    static var directory: URL {
        FileManager.default.temporaryDirectory.appendingPathComponent("CaseManagement-DocumentPreviews", isDirectory: true)
    }
    static func sweep() {
        try? FileManager.default.removeItem(at: directory)
        // Previews kept before 4.31.1 sat in a folder under the app's former name: remove those too.
        let tmp = FileManager.default.temporaryDirectory
        for name in (try? FileManager.default.contentsOfDirectory(atPath: tmp.path)) ?? []
        where name.hasSuffix("-DocumentPreviews") && name != directory.lastPathComponent {
            try? FileManager.default.removeItem(at: tmp.appendingPathComponent(name, isDirectory: true))
        }
    }
}

/// Unsaved text of a note or an hours entry, kept on this phone for the account that typed it,
/// so a forced sign-in (an expired session) or the app being closed never loses it. Protected
/// like the offline queue: Application Support, complete file protection, not backed up. A form
/// saves its fields as they change, restores them when it opens and clears them once saved.
enum DraftStore {
    private static func url(_ key: String, owner: Int) -> URL? {
        guard owner > 0, !key.isEmpty else { return nil }
        let safe = String(key.unicodeScalars.map {
            CharacterSet.alphanumerics.contains($0) || $0 == "-" || $0 == "_" ? Character($0) : "_"
        })
        var dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("Drafts", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try? dir.setResourceValues(values)
        dir.appendPathComponent(String(owner), isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent(safe + ".json")
    }

    /// Keeps the fields; a draft with nothing typed in it is removed instead. Nothing is kept on
    /// a phone without a passcode (3.0.39): its files would not be protected.
    static func save(_ fields: [String: String], key: String, owner: Int) {
        guard API.phoneCanKeepClientData, let url = url(key, owner: owner) else { return }
        guard fields.values.contains(where: { !$0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty }) else {
            try? FileManager.default.removeItem(at: url)
            return
        }
        guard let data = try? JSONEncoder().encode(fields) else { return }
        #if os(iOS)
        try? data.write(to: url, options: [.atomic, .completeFileProtection])
        #else
        try? data.write(to: url, options: [.atomic])
        #endif
    }

    /// A draft untouched for this long is dropped rather than restored (client text is not kept
    /// on the phone indefinitely).
    static let maxAge: TimeInterval = 14 * 24 * 60 * 60

    static func load(key: String, owner: Int) -> [String: String]? {
        guard let url = url(key, owner: owner) else { return nil }
        if let saved = (try? FileManager.default.attributesOfItem(atPath: url.path))?[.modificationDate] as? Date,
           Date().timeIntervalSince(saved) > maxAge {
            try? FileManager.default.removeItem(at: url)
            return nil
        }
        guard let data = try? Data(contentsOf: url) else { return nil }
        return try? JSONDecoder().decode([String: String].self, from: data)
    }

    static func clear(key: String, owner: Int) {
        guard let url = url(key, owner: owner) else { return }
        try? FileManager.default.removeItem(at: url)
    }

    /// Every draft of one account: an explicit, confirmed sign-out discards them, as its
    /// dialog says. (An expired session keeps them: signing in again restores them.)
    static func removeAll(owner: Int) {
        guard owner > 0 else { return }
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("Drafts", isDirectory: true)
            .appendingPathComponent(String(owner), isDirectory: true)
        try? FileManager.default.removeItem(at: dir)
    }

    /// How many drafts this phone keeps, of every account (4.27.0: said before a server change
    /// removes them).
    /// The file TrackCache keeps here: a copy of the server's track list, not a draft.
    static let notDraftFile = "track-cache.json"
    static func countAllAccounts() -> Int {
        let root = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("Drafts", isDirectory: true)
        guard let files = FileManager.default.enumerator(at: root, includingPropertiesForKeys: nil) else { return 0 }
        var count = 0
        // The kept track list (TrackCache, 4.28.0) is not typed work, so it is not a draft.
        for case let url as URL in files where url.pathExtension == "json" && url.lastPathComponent != notDraftFile { count += 1 }
        return count
    }

    /// At launch: a draft of any account that has not changed for `maxAge` is removed,
    /// so an abandoned case note or hours entry does not stay on the phone just because
    /// that client's sheet is never opened again (load() drops it only when it is).
    static func expire() {
        let root = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("Drafts", isDirectory: true)
        guard let files = FileManager.default.enumerator(at: root, includingPropertiesForKeys: [.contentModificationDateKey]) else { return }
        for case let url as URL in files where url.pathExtension == "json" {
            if let saved = try? url.resourceValues(forKeys: [.contentModificationDateKey]).contentModificationDate,
               Date().timeIntervalSince(saved) > maxAge {
                try? FileManager.default.removeItem(at: url)
            }
        }
    }
}

// MARK: - API client

enum APIError: LocalizedError {
    case server(String)
    case network
    /// A confirmed voice write whose reply was a server fault or unreadable: it may have been
    /// saved, so it is neither a success nor a definite failure.
    case outcomeUnknown
    /// The server is still working on an earlier try with the same retry key (4.29.0, its 409
    /// with processing true): the key must be kept, so trying again is recognised.
    case processing(String)

    var errorDescription: String? {
        switch self {
        case .server(let message): return message
        case .network: return "Could not reach the server. Check your connection."
        case .outcomeUnknown: return "The server's reply was lost, so it isn't known whether that was saved. Check before trying again."
        case .processing(let message): return message
        }
    }
}

/// Refuses a redirect to another host, another port or a plain http address (4.27.0), so the
/// Authorization header is never sent anywhere but the server the request was made to.
final class RedirectGuard: NSObject, URLSessionTaskDelegate, Sendable {
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse,
                    newRequest request: URLRequest) async -> URLRequest? {
        guard let from = task.originalRequest?.url ?? response.url, let to = request.url,
              to.scheme?.lowercased() == "https",
              from.host?.lowercased() == to.host?.lowercased(),
              RedirectGuard.port(of: from) == RedirectGuard.port(of: to) else { return nil }
        return request
    }

    /// The port, with none written meaning the scheme's own (443 for https, 80 for http).
    static func port(of url: URL) -> Int {
        if let port = url.port { return port }
        return url.scheme?.lowercased() == "http" ? 80 : 443
    }
}

@MainActor
final class API: ObservableObject {
    /// The organisation's server (4.6.0): the address chosen at sign-in, else the one this build
    /// names (CMS_DEFAULT_SERVER in project.yml). A build with neither asks at sign-in.
    nonisolated static var base: URL {
        let stored = UserDefaults.standard.string(forKey: serverKey)
        // A build that names its server always uses it; the sign-in choice is only for builds without one.
        if let s = builtInServer ?? stored, let url = apiURL(fromAddress: s) { return url }
        return URL(string: "https://example.invalid/api.php")!
    }
    /// Where the address chosen at sign-in is kept (kept at sign-out, so it is not typed again).
    nonisolated static let serverKey = "serverAddress"
    /// The server this build names, or nil.
    nonisolated static var builtInServer: String? {
        let s = (Bundle.main.object(forInfoDictionaryKey: "CMSDefaultServer") as? String ?? "").trimmingCharacters(in: .whitespaces)
        return s.isEmpty || s.contains("$(") ? nil : s
    }
    /// "cms.example.org", "example.org/cms" or "https://example.org/cms/login.php" as the API's
    /// address (https://…/api.php); nil for anything that is not https or has no host.
    nonisolated static func apiURL(fromAddress raw: String) -> URL? {
        var s = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        // No other scheme, no user name or password part, no backslash: an address like
        // "good.org@evil.com" would quietly send everything to evil.com.
        if s.isEmpty || s.contains("@") || s.contains("\\") || s.contains(" ") { return nil }
        let lower = s.lowercased()
        if lower.contains("://") && !lower.hasPrefix("https://") { return nil }
        if lower.hasPrefix("https://") {
            s = "https://" + s.dropFirst(8)
        } else if lower.hasPrefix("http:") || lower.hasPrefix("https:") || lower.hasPrefix("http//") || lower.hasPrefix("https//") {
            return nil   // a mistyped or insecure scheme, never guessed at
        } else {
            s = "https://" + s
        }
        guard var c = URLComponents(string: s), c.scheme == "https", let host = c.host?.lowercased(), !host.isEmpty, host.contains(".") || host == "localhost" else { return nil }
        c.host = host
        // 4.28.0: https's own port written out (":443") is the same server as no port, so the
        // two spellings give one address (a sign-in never takes them for different servers).
        if c.port == 443 { c.port = nil }
        var path = c.path
        if path.lowercased().hasSuffix("/api.php") { path = String(path.dropLast(8)) + "/api.php" }
        if !path.hasSuffix("/api.php") {
            if path.hasSuffix(".php") { path = (path as NSString).deletingLastPathComponent }
            if !path.hasSuffix("/") { path += "/" }
            path += "api.php"
        }
        c.path = path; c.query = nil; c.fragment = nil; c.user = nil; c.password = nil
        return c.url
    }
    /// Where refreshMe keeps the server's time zone for APIDate.
    nonisolated static let serverTimeZoneKey = "serverTimeZone"
    /// Set at the first launch of an install: its absence means the app was just installed.
    private static let installedKey = "installMarker"
    /// This build as "<version> (<build>)", such as "3.0.21 (29)".
    nonisolated static let appVersion: String = {
        let info = Bundle.main.infoDictionary ?? [:]
        let version = info["CFBundleShortVersionString"] as? String ?? "unknown"
        let build = info["CFBundleVersion"] as? String ?? "unknown"
        return "\(version) (\(build))"
    }()
    /// Every request says which build of the app sent it (X-App-Version), so the server can tell
    /// an out-of-date install apart.
    nonisolated static func identifyApp(_ request: inout URLRequest) {
        request.setValue(appVersion, forHTTPHeaderField: "X-App-Version")
    }

    /// Every request to the server goes through this session (4.27.0): a redirect is followed
    /// only to the same host over https, so the session token is never sent to another server
    /// or over an unencrypted connection (a refused redirect comes back as the reply itself).
    nonisolated static let session = URLSession(configuration: URLSession.shared.configuration,
                                                     delegate: RedirectGuard(), delegateQueue: nil)

    /// Where the offline entries, drafts and kept lists on this phone were saved for (4.27.0):
    /// the API address of the server last signed in to. Signing in to a different one removes them.
    nonisolated static let dataServerKey = "dataServer"
    /// That address as apiURL writes it today (4.28.0: one kept by 4.27.0 with ":443" written
    /// out is the same server as without it), or the current server when none is kept.
    nonisolated static var dataServer: String {
        guard let kept = UserDefaults.standard.string(forKey: dataServerKey) else { return base.absoluteString }
        return apiURL(fromAddress: kept)?.absoluteString ?? kept
    }

    /// Said when the server's reply was a redirect the app refused (RedirectGuard), instead of
    /// "Unexpected response from the server."
    nonisolated static let redirectRefused = "The server answered by sending the app to another address, which the app does not follow, so nothing was sent there. Your organisation's server address may have changed: check it with your administrator."
    nonisolated static func isRedirect(_ response: URLResponse) -> Bool {
        guard let http = response as? HTTPURLResponse else { return false }
        return (300..<400).contains(http.statusCode)
    }

    /// Session token lives in the iOS Keychain (hardware-encrypted, this-device-only).
    @Published var token: String {
        didSet { Keychain.save(token, for: "apiToken") }
    }
    @AppStorage("userName") var userName: String = ""

    /// Permission keys from the user's group. Until the first successful
    /// fetch, the UI shows everything (the server enforces access anyway);
    /// once fetched, an empty list genuinely means "nothing extra".
    @Published var perms: [String] {
        didSet { UserDefaults.standard.set(perms, forKey: "userPerms") }
    }
    @Published var permsLoaded: Bool {
        didSet { UserDefaults.standard.set(permsLoaded, forKey: "userPermsLoaded") }
    }
    /// True when the account works only its own caseload. The server enforces it either way;
    /// the app uses it to leave out choices the server would ignore (assigning a client to
    /// someone else).
    @Published var scoped: Bool {
        didSet { UserDefaults.standard.set(scoped, forKey: "userScoped") }
    }
    /// Why the app went back to Sign in by itself (an expired session), shown there once.
    @Published var sessionNotice: String?
    /// The server refused this session (expired or revoked) while a known account was signed in.
    /// Sign-in is asked for above the open screens, which stay as they are (a half-written note
    /// is kept); requests refused meanwhile wait for it and are sent again once the same account
    /// is signed in (see send).
    @Published var reauthRequired = false
    private var reauthWaiters: [CheckedContinuation<Bool, Never>] = []
    /// Whether the AI drafting assistant is configured server-side.
    @Published var aiEnabled: Bool = false
    /// Enabled AI feature keys (recommend, intake, progress, remediate, ask, kb, translate, curriculum, risk).
    @Published var aiFeatures: [String] = []
    /// The agreements and training to finish before client records open (4.16.0).
    @Published var accessHold: [String] = []
    /// The website's release (4.0.0), to say when this app is older than it.
    @Published var serverVersion: String?
    /// "Please update" when the website is a newer major release than this app.
    var updateNotice: String? {
        guard let server = serverVersion,
              let app = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String,
              let sMajor = Int(server.split(separator: ".").first ?? ""),
              let aMajor = Int(app.split(separator: ".").first ?? ""), sMajor > aMajor else { return nil }
        return "The website is version \(server) and this app is \(app). Please update the app to use everything the website offers."
    }
    func aiHas(_ key: String) -> Bool { aiEnabled && aiFeatures.contains(key) }

    init() {
        // Deleting the app removes its settings but not its Keychain items. A fresh install (no
        // marker, and none of the settings a signed-in build writes) drops a token left by an
        // earlier install and asks the server to end it, so the next person to install the app on
        // this phone is not signed in as the last one. An upgrade from a build with no marker
        // keeps its sign-in.
        let defaults = UserDefaults.standard
        if !defaults.bool(forKey: API.installedKey) {
            let upgrade = ["apiToken", "userId", "userName", "userPerms", "userPermsLoaded", "userScoped"]
                .contains { defaults.object(forKey: $0) != nil }
            if !upgrade {
                let stale = Keychain.load("apiToken")
                Keychain.save("", for: "apiToken")   // saving nothing deletes the item
                if !stale.isEmpty { API.revoke(stale) }
            }
            defaults.set(true, forKey: API.installedKey)
        }
        // One-time migration: builds ≤6 kept the token in UserDefaults.
        if let legacy = UserDefaults.standard.string(forKey: "apiToken"), !legacy.isEmpty {
            Keychain.save(legacy, for: "apiToken")
            UserDefaults.standard.removeObject(forKey: "apiToken")
        }
        token = Keychain.load("apiToken")
        perms = UserDefaults.standard.stringArray(forKey: "userPerms") ?? []
        permsLoaded = UserDefaults.standard.bool(forKey: "userPermsLoaded")
        scoped = UserDefaults.standard.bool(forKey: "userScoped")
        pendingSync = ownPending((try? loadQueue()) ?? [])
    }

    func can(_ perm: String) -> Bool { !permsLoaded || perms.contains(perm) }

    /// Refresh name + permission set; called after sign-in and on app start.
    func refreshMe() async {
        let sessionToken = token
        if let me: MeResponse = try? await call("me"), token == sessionToken, !token.isEmpty {
            userName = me.name
            userId = me.id
            perms = me.perms ?? []
            scoped = me.scoped ?? false
            aiEnabled = me.aiEnabled ?? false
            aiFeatures = me.aiFeatures ?? []
            accessHold = me.accessHold ?? []
            serverVersion = me.serverVersion
            permsLoaded = true
            // Appointment times are sent and shown in the server's zone (APIDate); a server that
            // does not report it keeps the zone known before (America/Chicago by default).
            if let zone = me.timezone, TimeZone(identifier: zone) != nil {
                UserDefaults.standard.set(zone, forKey: API.serverTimeZoneKey)
            }
            // The organisation's own lists (4.5.0); an older server answers "Unknown action".
            if let obj = try? await callDict("form_options"), let lists = obj["lists"] as? [String: [String]] {
                if token == sessionToken { FormOptions.save(lists) }
            } else if token == sessionToken {
                FormOptions.clear()   // an older server, or none sent: the built-in lists
            }
        }
    }

    var isSignedIn: Bool { !token.isEmpty }

    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    /// The request URL for an action. URLComponents leaves a "+" in a query value as it is,
    /// and PHP reads a literal "+" as a space, so a search for "jane+cms@example.org" or
    /// "+1 555" would reach the server altered: every "+" is sent as %2B instead.
    private static func url(for action: String, query: [String: String]) -> URL {
        var components = URLComponents(url: API.base, resolvingAgainstBaseURL: false)!
        var items = [URLQueryItem(name: "action", value: action)]
        for (k, v) in query { items.append(URLQueryItem(name: k, value: v)) }
        components.queryItems = items
        components.percentEncodedQuery = components.percentEncodedQuery?.replacingOccurrences(of: "+", with: "%2B")
        return components.url!
    }

    /// Actions that wait on the AI provider, which the server allows 90 seconds: longer than
    /// URLRequest's default 60-second timeout, so they get 100 seconds instead of being cut
    /// off (and reported as a lost connection) while the answer is still coming.
    /// A confirmed voice write (voice_command with "do") involves no AI: its timeout is a lost
    /// reply, reported as one so the assistant can offer a retry with the same key.
    private static func waitsOnAI(_ action: String, body: [String: Any]?) -> Bool {
        action.hasPrefix("ai_") || action == "at_ai_draft" || action == "workflow_briefing" || body?["ai_draft"] != nil
            || (action == "voice_command" && body?["do"] == nil)
    }
    private static let aiTimeout: TimeInterval = 100
    private static let aiTimedOut = APIError.server("The AI service took too long to answer. Try again.")

    /// Sends one request with the current session. A 401 (the session expired or was revoked)
    /// asks for sign-in above the open screens and waits for it; once the same account is signed
    /// in again the request goes out once more with the new session. The server answers a 401
    /// before the action runs, so a write refused that way was not saved, and sending it again
    /// cannot save it twice.
    private func send(_ action: String, method: String, query: [String: String], body: [String: Any]?,
                      accountChanged: String) async throws -> (Data, URLResponse) {
        let isAI = API.waitsOnAI(action, body: body)
        var resent = 0
        while true {
            let sessionToken = token
            var request = URLRequest(url: API.url(for: action, query: query))
            API.identifyApp(&request)
            request.httpMethod = body != nil ? "POST" : method
            if isAI { request.timeoutInterval = API.aiTimeout }
            if !sessionToken.isEmpty {
                request.setValue("Bearer \(sessionToken)", forHTTPHeaderField: "Authorization")
            }
            if let body {
                request.setValue("application/json", forHTTPHeaderField: "Content-Type")
                request.httpBody = try JSONSerialization.data(withJSONObject: body)
            }
            let data: Data
            let response: URLResponse
            do {
                (data, response) = try await API.session.data(for: request)
            } catch {
                guard token == sessionToken else { throw APIError.server(accountChanged) }
                if isAI, (error as? URLError)?.code == .timedOut { throw API.aiTimedOut }
                throw APIError.network
            }
            // A phone reported lost (3.0.39): remove what the app keeps here and sign out, even
            // when the account changed meanwhile (the phone itself was reported).
            if let refusal = wipeIfReportedLost(response, data: data, sessionToken: sessionToken) {
                throw APIError.server(refusal)
            }
            guard token == sessionToken else { throw APIError.server("Your account changed. Please reload this screen.") }
            if API.isRedirect(response) { throw APIError.server(API.redirectRefused) }
            if let http = response as? HTTPURLResponse, http.statusCode == 401, action != "login", action != "verify",
               resent < 2, try await waitForSignIn() {
                resent += 1
                continue
            }
            return (data, response)
        }
    }

    /// The session was refused (401). With a known account this waits for sign-in above the open
    /// screens and returns true once the same account is back. With no known account it returns
    /// false (the app goes back to Sign in, which says why, and the refusal is reported as before).
    private func waitForSignIn() async throws -> Bool {
        expireSession()
        guard reauthRequired else { return false }
        let signedBackIn = await withCheckedContinuation { (waiter: CheckedContinuation<Bool, Never>) in
            reauthWaiters.append(waiter)
        }
        guard signedBackIn else {
            throw APIError.server("This was not sent, because the same account did not sign in again. Nothing from it was saved.")
        }
        return true
    }

    private func resumeReauthWaiters(_ signedBackIn: Bool) {
        let waiters = reauthWaiters
        reauthWaiters = []
        for waiter in waiters { waiter.resume(returning: signedBackIn) }
    }

    /// A completed sign-in (password and code). After a refused session, the same account picks
    /// up where it was: its screens stay, waiting requests are sent again and offline entries
    /// upload. Any other sign-in starts afresh (RootView rebuilds the tabs for the new account)
    /// and nothing that was waiting is sent for it.
    func finishSignIn(token newToken: String, userId newId: Int?, name: String) {
        if reauthRequired, let newId, newId > 0, newId == userId {
            token = newToken
            userName = name
            reauthRequired = false
            sessionNotice = nil
            resumeReauthWaiters(true)
            Task {
                await refreshMe()
                await flushQueue()
            }
            return
        }
        clearIdentity()
        // Signed in to a server other than the one what is kept here was saved for (4.27.0):
        // only now, once the sign-in worked, does that go (the person was asked first when
        // entries were waiting; see LoginView). The same server, however typed, keeps it all.
        let here = API.base.absoluteString
        if UserDefaults.standard.string(forKey: API.dataServerKey) != nil, API.dataServer != here {
            removeEveryAccountsData()
        }
        UserDefaults.standard.set(here, forKey: API.dataServerKey)
        userId = newId ?? 0
        userName = name
        token = newToken   // flips RootView to the main tabs
    }

    /// `refusal`, when given, is handed the whole refusal (ok false) before it is thrown, for a
    /// screen that needs more than its message (4.27.0: schedule_session's authorization_hash).
    func call<T: Decodable>(_ action: String, method: String = "GET",
                            query: [String: String] = [:], body: [String: Any]? = nil,
                            refusal: (([String: Any]) -> Void)? = nil) async throws -> T {
        let (data, _) = try await send(action, method: method, query: query, body: body,
                                       accountChanged: "Your account changed. Sign in again before saving this entry.")
        if let errorBody = try? decoder.decode(APIErrorResponse.self, from: data), !errorBody.ok {
            if let refusal, let fields = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] { refusal(fields) }
            if errorBody.processing == true {
                throw APIError.processing(errorBody.error ?? "This is still being processed. Try again in a moment.")
            }
            throw APIError.server(errorBody.error ?? "Something went wrong.")
        }
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError.server("Unexpected response from the server.")
        }
    }

    /// `call` for a read-only list the app keeps a copy of (the client list, today's schedule,
    /// due follow-ups): the server's reply is kept on this phone for the signed-in account
    /// (OfflineCache), and when the server cannot be reached the last copy is returned instead,
    /// with when it was saved, so the screen can show it read-only. Any other failure is thrown.
    func callCached<T: Decodable>(_ action: String, query: [String: String] = [:],
                                  cacheKey: String) async throws -> (value: T, savedAt: Date?) {
        let owner = userId
        do {
            let (data, _) = try await send(action, method: "GET", query: query, body: nil,
                                           accountChanged: "Your account changed. Please reload this screen.")
            if let errorBody = try? decoder.decode(APIErrorResponse.self, from: data), !errorBody.ok {
                // Refused (no longer on this caseload, or removed): the copy kept here goes too.
                if owner > 0 { OfflineCache.remove(key: cacheKey, owner: owner) }
                throw APIError.server(errorBody.error ?? "Something went wrong.")
            }
            let value: T
            do { value = try decoder.decode(T.self, from: data) }
            catch { throw APIError.server("Unexpected response from the server.") }
            if owner > 0, userId == owner { OfflineCache.save(data, key: cacheKey, owner: owner) }
            return (value, nil)
        } catch APIError.network {
            guard let saved = OfflineCache.load(key: cacheKey, owner: owner),
                  let value = try? decoder.decode(T.self, from: saved.data) else { throw APIError.network }
            return (value, saved.savedAt)
        }
    }

    /// Like `call`, but returns the parsed JSON object as a dictionary. Used by
    /// the voice assistant, whose replies carry a free-form `params` object that
    /// doesn't fit a fixed Decodable shape. Honors the same auth + 401 handling.
    func callDict(_ action: String, method: String = "GET",
                  query: [String: String] = [:], body: [String: Any]? = nil) async throws -> [String: Any] {
        let confirmedWrite = action == "voice_command" && body?["do"] != nil
        let (data, response) = try await send(action, method: method, query: query, body: body,
                                              accountChanged: "Your account changed. Please reload this screen.")
        // A server fault or an unreadable reply to a confirmed write does not say whether it
        // was saved (only a 4xx refusal does), so it is not reported as a plain failure.
        if confirmedWrite, let http = response as? HTTPURLResponse, http.statusCode >= 500 {
            throw APIError.outcomeUnknown
        }
        guard let obj = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            throw confirmedWrite ? APIError.outcomeUnknown : APIError.server("Unexpected response from the server.")
        }
        if (obj["ok"] as? Bool) == false {
            // 4.29.0: still being processed (the same key is running): the caller keeps its key.
            if (obj["processing"] as? Bool) == true {
                throw APIError.processing((obj["error"] as? String) ?? "This is still being processed. Try again in a moment.")
            }
            throw APIError.server((obj["error"] as? String) ?? "Something went wrong.")
        }
        return obj
    }

    // MARK: - Offline queue (notes and hours written with no signal)

    struct PendingCall: Codable, Identifiable {
        var id = UUID()          // stable identity; also sent as the idem_key
        let action: String
        let body: Data
        let label: String
        var owner: Int = 0       // user id the entry belongs to (0 = pre-upgrade)
        var savedAt: Date?       // when it was kept on the phone (nil from an earlier build)
        var lastError: String?   // why the server last refused it (nil: not refused yet)

        enum CodingKeys: String, CodingKey { case id, action, body, label, owner, savedAt, lastError }
        init(id: UUID = UUID(), action: String, body: Data, label: String, owner: Int = 0,
             savedAt: Date? = nil, lastError: String? = nil) {
            self.id = id; self.action = action; self.body = body; self.label = label; self.owner = owner
            self.savedAt = savedAt; self.lastError = lastError
        }
        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            id = try c.decode(UUID.self, forKey: .id)
            action = try c.decode(String.self, forKey: .action)
            body = try c.decode(Data.self, forKey: .body)
            label = try c.decode(String.self, forKey: .label)
            // Synthesized Decodable does not apply a property's default for missing keys.
            owner = try c.decodeIfPresent(Int.self, forKey: .owner) ?? 0
            savedAt = try? c.decodeIfPresent(Date.self, forKey: .savedAt)
            lastError = try? c.decodeIfPresent(String.self, forKey: .lastError)
        }

        /// What kind of entry it is, in words.
        var kind: String {
            switch action {
            case "add_note": return "Case note"
            case "log_hours": return "Hours"
            case "save_followup": return "Device follow-up"
            case "check_in_session": return "Session check-in"
            case "sign_in_person": return "Signed in person with no signal"
            default: return "Entry"
            }
        }

        /// What the entry says, as plain text: read on the Offline entries screen, or copied to
        /// enter it on the website.
        var readableText: String {
            guard let obj = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any] else { return label }
            func value(_ key: String) -> String? {
                let text: String
                if let s = obj[key] as? String { text = s }
                else if let n = obj[key] as? NSNumber { text = n.stringValue }
                else { return nil }
                let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
                return trimmed.isEmpty ? nil : trimmed
            }
            var lines: [String] = []
            switch action {
            case "add_note":
                if let v = value("note_type") { lines.append("Type: \(v)") }
                if let v = value("subject") { lines.append("Subject: \(v)") }
                if let names = noteFormatLabels[value("note_format") ?? "free"], names.count == 4 {
                    for (i, name) in names.enumerated() {
                        if let v = value("sec\(i + 1)") { lines.append("\(name): \(v)") }
                    }
                }
                if let v = value("body") { lines.append(v) }
                for item in (obj["homework"] as? [[String: Any]]) ?? [] {
                    if let title = item["title"] as? String, !title.isEmpty { lines.append("Homework: \(title)") }
                }
            case "log_hours":
                if let v = value("date") { lines.append("Date: \(v)") }
                if let v = value("hours") { lines.append("Hours: \(v)") }
                if let v = value("service_type") { lines.append("Service: \(v)") }
                if let v = value("rsa_category") { lines.append("RSA category: \(v)") }
                if let v = value("mileage"), v != "0" { lines.append("Mileage: \(v)") }
                if let v = value("description") { lines.append("Description: \(v)") }
            case "save_followup":
                if let v = value("in_use") { lines.append("Still in use: \(v == "yes" ? "Yes" : v == "no" ? "No" : "Unknown")") }
                if let v = value("satisfaction") { lines.append("Satisfaction: \(v) of 5") }
                if let v = value("barriers") { lines.append("Barriers: \(v)") }
                if let v = value("notes") { lines.append("Notes: \(v)") }
            case "sign_in_person":
                // 4.26.0: signed in person with no signal. The drawn signature is not text: it is
                // only said whether one was drawn.
                lines.append(label)
                if let v = value("typed_name") { lines.append("Signed by typing the name: \(v)") }
                if let v = value("signed_at") {
                    let when = ISO8601DateFormatter().date(from: v)
                    lines.append("Signed on this phone: " + (when?.formatted(date: .abbreviated, time: .shortened) ?? v))
                }
                lines.append(value("signature_b64") == nil ? "No drawn signature" : "With a drawn signature")
            default:
                for key in obj.keys.sorted() where key != "idem_key" {
                    if let v = value(key) { lines.append("\(key): \(v)") }
                }
            }
            return lines.isEmpty ? label : lines.joined(separator: "\n")
        }
    }
    @Published var pendingSync: Int = 0
    @Published var syncError: String?
    @Published var syncNoticeDismissed = false {  // the person cleared the sync notice this session
        didSet { if syncNoticeDismissed { expiredNotice = nil } }
    }
    /// Offline entries of this account removed for being too old (4.27.0), said in the sync
    /// notice until the person dismisses it: told once, not on every return to the app.
    private var expiredNotice: String?
    /// An offline entry not sent within this long is removed, like an old draft: client text is
    /// not kept on the phone indefinitely (4.27.0).
    static let queueMaxAge: TimeInterval = 30 * 24 * 60 * 60
    /// How many of an account's entries expired while another account was signed in, told at
    /// its next upload (a count only: UserDefaults is not protected like the queue).
    private static func expiredCountKey(_ owner: Int) -> String { "expiredOfflineEntries.\(owner)" }
    @AppStorage("userId") var userId: Int = 0
    private var isFlushing = false
    /// Callers that asked for an upload while one was already running (Retry now while the upload
    /// started by returning to the app is still going): they wait for it to end, so what they
    /// report afterwards is its outcome, not the queue as it was half-way through.
    private var flushWaiters: [CheckedContinuation<Void, Never>] = []

    /// Application Support (not Documents): never user-visible, excluded from backups, and
    /// unreadable while the phone is locked (complete protection: the queue is only read or
    /// written with the app in the foreground) — queued notes are case records.
    private var queueURL: URL {
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        let url = dir.appendingPathComponent("pending_sync.json")
        return url
    }
    private func loadQueue() throws -> [PendingCall] {
        let legacy = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("pending_sync.json")
        if FileManager.default.fileExists(atPath: legacy.path), !FileManager.default.fileExists(atPath: queueURL.path) {
            try FileManager.default.moveItem(at: legacy, to: queueURL)
        }
        guard FileManager.default.fileExists(atPath: queueURL.path) else { return [] }
        // A migrated file, or one written by an earlier build, gets the same protection and is
        // kept out of backups straight away, not only once it is next rewritten (a queue holding
        // only entries with no owner never is).
        #if os(iOS)
        try? FileManager.default.setAttributes([.protectionKey: FileProtectionType.complete], ofItemAtPath: queueURL.path)
        #endif
        var stored = queueURL
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try? stored.setResourceValues(values)
        return try JSONDecoder().decode([PendingCall].self, from: Data(contentsOf: queueURL))
    }
    private func saveQueue(_ q: [PendingCall]) throws {
        let data = try JSONEncoder().encode(q)
        #if os(iOS)
        try data.write(to: queueURL, options: [.atomic, .completeFileProtection])
        #else
        try data.write(to: queueURL, options: [.atomic])   // the queue unit tests run on macOS
        #endif
        var url = queueURL
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try url.setResourceValues(values)
        pendingSync = ownPending(q)
    }

    /// Entries the signed-in account will upload. Another account's entries (and pre-upgrade
    /// ones with no owner) never sync for this one, so they are not counted as "will sync
    /// automatically" or as waiting in the sign-out question.
    private func ownPending(_ q: [PendingCall]) -> Int {
        userId > 0 ? q.filter { $0.owner == userId }.count : 0
    }

    /// How long after it was kept on the phone a queued note still carries when it was written
    /// (4.29.0; the server accepts recorded_at up to 7 days back). Older, the server's own time is used.
    nonisolated static let noteRecordedAtMaxAge: TimeInterval = 7 * 86400 - 3600

    /// What an offline entry sends (4.29.0): as stored, and for a case note the time it was saved
    /// on the phone as recorded_at (ISO 8601), so a note written with no signal is dated when it
    /// was written, not when it synced. Left out when that time is unknown, in the future or
    /// too old for the server to accept, so the note is never refused for it.
    nonisolated static func uploadBody(_ item: PendingCall, now: Date = Date()) -> Data {
        guard item.action == "add_note", let saved = item.savedAt,
              saved <= now, now.timeIntervalSince(saved) < noteRecordedAtMaxAge,
              var fields = (try? JSONSerialization.jsonObject(with: item.body)) as? [String: Any],
              fields["recorded_at"] == nil else { return item.body }
        let format = ISO8601DateFormatter()
        format.formatOptions = [.withInternetDateTime]
        fields["recorded_at"] = format.string(from: saved)
        return (try? JSONSerialization.data(withJSONObject: fields)) ?? item.body
    }

    /// Report success only after the record is durably stored for a known account.
    func enqueue(_ action: String, body: [String: Any], label: String) throws {
        guard API.phoneCanKeepClientData else {
            throw APIError.server("This iPhone has no passcode, so the app keeps nothing on it to send later. Save again when you have a signal, or set a passcode in Settings.")
        }
        guard userId > 0, !token.isEmpty else {
            throw APIError.server("Could not verify the account for offline saving. Keep this form open and reconnect before saving.")
        }
        // A fresh offline save re-enables the stuck-entry notice (new activity worth surfacing).
        syncNoticeDismissed = false
        var payload = body
        let id = (body["idem_key"] as? String).flatMap(UUID.init(uuidString:)) ?? UUID()
        payload["idem_key"] = id.uuidString
        let data = try JSONSerialization.data(withJSONObject: payload)
        var q = try loadQueue()
        if !q.contains(where: { $0.id == id && $0.owner == userId }) {
            q.append(PendingCall(id: id, action: action, body: data, label: label, owner: userId, savedAt: Date()))
        }
        try saveQueue(q)
    }

    /// Keep every unconfirmed entry, including validation and permission failures.
    /// Capture the account and token so switching accounts cannot reassign a write.
    func flushQueue() async {
        if isFlushing {
            await withCheckedContinuation { (waiter: CheckedContinuation<Void, Never>) in
                flushWaiters.append(waiter)
            }
            return
        }
        guard userId > 0, !token.isEmpty, !reauthRequired else { return }
        isFlushing = true
        defer {
            isFlushing = false
            let waiters = flushWaiters
            flushWaiters = []
            for waiter in waiters { waiter.resume() }
        }
        let owner = userId
        let sessionToken = token
        var transient: String?
        // 4.28.0: whether every try got a real answer from the server. A network error, a 5xx or
        // 429, a refused redirect or a refused session stops the upload without reaching it, and
        // then nothing is expired: an old entry is never dropped because the phone had no signal.
        var reachedServer = true
        let notice: String?
        do {
            let q = try loadQueue()
            pendingSync = ownPending(q)
            var done: Set<UUID> = []
            var refused: [UUID: String] = [:]
            for item in q {
                guard token == sessionToken, userId == owner else { break }
                guard item.owner == owner else { continue }
                // 4.28.0: an entry discarded on Offline entries while this upload was running is
                // not sent from the copy loaded at the start (re-read just before each send).
                guard (try? loadQueue())?.contains(where: { $0.id == item.id && $0.owner == owner }) == true else { continue }
                var components = URLComponents(url: API.base, resolvingAgainstBaseURL: false)!
                components.queryItems = [URLQueryItem(name: "action", value: item.action)]
                var request = URLRequest(url: components.url!)
                API.identifyApp(&request)
                request.httpMethod = "POST"
                request.setValue("Bearer \(sessionToken)", forHTTPHeaderField: "Authorization")
                request.setValue("application/json", forHTTPHeaderField: "Content-Type")
                request.httpBody = API.uploadBody(item)
                do {
                    let (data, response) = try await API.session.data(for: request)
                    guard let http = response as? HTTPURLResponse else { reachedServer = false; break }
                    if http.statusCode == 401 {
                        reachedServer = false
                        // A phone reported lost (4.27.0: here too): everything goes, nothing more is sent.
                        if wipeIfReportedLost(response, data: data, sessionToken: sessionToken) != nil { return }
                        // Sign-in is asked for above the open screens; the entries upload after it.
                        if token == sessionToken { expireSession() }
                        break
                    }
                    if (200..<300).contains(http.statusCode),
                       let ok = try? decoder.decode(OKResponse.self, from: data), ok.ok {
                        done.insert(item.id)
                    } else if http.statusCode == 409,
                              (try? decoder.decode(APIErrorResponse.self, from: data))?.processing == true {
                        // 4.29.0: an earlier try of this entry is still being processed: kept as it is
                        // (same key) and tried again later, not marked refused.
                        transient = "“\(item.label)” is still being processed by the server and is still saved on this phone. It will be checked again automatically."
                        reachedServer = false
                        break
                    } else if http.statusCode == 409, item.action == "check_in_session" {
                        // Already checked in (from another phone, or an earlier try whose reply
                        // was lost): the check-in is recorded, so the entry is done.
                        done.insert(item.id)
                    } else if API.isRedirect(response) {
                        // Refused redirect: nothing reached the server; kept, and said why.
                        transient = "“\(item.label)” could not sync yet and is still saved on this phone. " + API.redirectRefused
                        reachedServer = false
                        break
                    } else if http.statusCode >= 500 || http.statusCode == 429 {
                        // Transient server/rate-limit error: keep the entry and stop; it retries later.
                        transient = "“\(item.label)” could not sync yet and is still saved on this phone. "
                            + ((try? decoder.decode(APIErrorResponse.self, from: data))?.error ?? "It will retry automatically when you reconnect.")
                        reachedServer = false
                        break
                    } else {
                        // A rejected upload is not a confirmed save. Keep the original work available —
                        // the rejection may be resolvable (permission granted, track reopened) on a later
                        // retry — and keep the reason with it, so the person can see which entry it is.
                        refused[item.id] = (try? decoder.decode(APIErrorResponse.self, from: data))?.error
                            ?? "The server did not accept it."
                    }
                } catch { reachedServer = false; break }
            }
            // Reload so entries added during an upload survive. Only confirmed uploads
            // are removable; unknown-owner legacy records remain for recovery.
            var current = try loadQueue()
            let before = current.count
            current.removeAll { $0.owner == owner && done.contains($0.id) }
            var changed = current.count != before
            for i in current.indices where current[i].owner == owner {
                if let reason = refused[current[i].id], current[i].lastError != reason {
                    current[i].lastError = reason
                    changed = true
                }
            }
            if changed { try saveQueue(current) } else { pendingSync = ownPending(current) }
            // Only what is still here after sending is expired (4.27.0), and only after the server
            // was actually reached (4.28.0): an old entry that could be sent just now was sent,
            // not dropped, and one that could not be tried (no signal, server fault) is kept.
            if reachedServer, token == sessionToken, userId == owner, expireQueue(owner: owner) { current = try loadQueue() }
            notice = syncNotice(current, transient: transient)
        } catch {
            notice = "Could not read or update offline entries. They have not been discarded."
        }
        // Set once, and only when it changes: the notice is announced when its text changes, so
        // clearing it first and setting the same text again would repeat it on every return.
        if token == sessionToken, userId == owner, syncError != notice { syncError = notice }
    }

    /// The sync notice for the queue as stored: names the refused entry (or the first of them)
    /// and says where to deal with it.
    private func syncNotice(_ q: [PendingCall], transient: String?) -> String? {
        var parts: [String] = []
        if let expiredNotice { parts.append(expiredNotice) }
        if let transient { parts.append(transient) }
        let refused = userId > 0 ? q.filter { $0.owner == userId && $0.lastError != nil } : []
        if let first = refused.first {
            let others = refused.count - 1
            if others == 0 {
                parts.append("“\(first.label)” could not upload and is still saved on this phone (\(first.lastError ?? "")). Open Offline entries to retry, copy the text or discard it.")
            } else {
                parts.append("“\(first.label)” and \(others) other offline entr\(others == 1 ? "y" : "ies") could not upload and are still saved on this phone. Open Offline entries to see why, retry, copy the text or discard them.")
            }
        }
        let legacy = q.filter { $0.owner == 0 }.count
        if legacy > 0 {
            parts.append("\(legacy) older offline entr\(legacy == 1 ? "y has" : "ies have") no verified account owner and remain saved for recovery.")
        }
        return parts.isEmpty ? nil : parts.joined(separator: " ")
    }

    /// Removes offline entries older than `queueMaxAge` (every account's: client text is not kept
    /// indefinitely for anyone) and says once what went for `owner`. An entry from a build that
    /// did not record when it was saved is dated now, so it gets the full time from here.
    @discardableResult
    private func expireQueue(owner: Int) -> Bool {
        guard var q = try? loadQueue(), !q.isEmpty else { return false }
        let now = Date()
        var changed = false
        for i in q.indices where q[i].savedAt == nil {
            q[i].savedAt = now
            changed = true
        }
        let expired = q.filter { now.timeIntervalSince($0.savedAt ?? now) > API.queueMaxAge }
        if !expired.isEmpty {
            q.removeAll { now.timeIntervalSince($0.savedAt ?? now) > API.queueMaxAge }
            changed = true
            for (other, entries) in Dictionary(grouping: expired.filter { $0.owner > 0 && $0.owner != owner }, by: \.owner) {
                let key = API.expiredCountKey(other)
                UserDefaults.standard.set(UserDefaults.standard.integer(forKey: key) + entries.count, forKey: key)
            }
        }
        if changed { try? saveQueue(q) }
        let own = expired.filter { $0.owner == owner }.map(\.label)
        let key = API.expiredCountKey(owner)
        let earlier = UserDefaults.standard.integer(forKey: key)
        UserDefaults.standard.removeObject(forKey: key)
        let total = own.count + earlier
        guard total > 0 else { return changed }
        let days = Int(API.queueMaxAge / 86400)
        let named = own.count == 1 && earlier == 0 ? "“\(own[0])” was" : "\(total) offline entries were"
        expiredNotice = "\(named) not sent within \(days) days, so \(total == 1 ? "it was" : "they were") removed from this phone. Enter \(total == 1 ? "it" : "them") again if still needed."
        syncNoticeDismissed = false
        return changed
    }

    /// How many offline entries would be removed by signing in to the server at `next` (4.27.0):
    /// none when it is the server they were saved for, however its address was typed.
    func entriesLostBySigningIn(to next: URL) -> Int {
        guard next.absoluteString != API.dataServer else { return 0 }
        return (try? loadQueue().count) ?? 0
    }

    /// The signed-in account's offline entries, oldest first (the Offline entries screen).
    func ownQueuedEntries() throws -> [PendingCall] {
        guard userId > 0 else { return [] }
        return try loadQueue().filter { $0.owner == userId }
    }

    /// Removes one of the signed-in account's own offline entries, once the person confirmed it.
    func discardQueued(_ id: UUID) throws {
        guard userId > 0 else { return }
        let q = try loadQueue()
        let kept = q.filter { !($0.id == id && $0.owner == userId) }
        guard kept.count != q.count else { return }
        try saveQueue(kept)
        let notice = syncNotice(kept, transient: nil)
        if syncError != notice { syncError = notice }
    }

    #if os(iOS)
    /// The clipboard's changeCount just after the app last copied client text, or nil. Sign-out
    /// clears the clipboard only while that copy is still the last thing on it, so whatever the
    /// person copied since (in any app) is left alone.
    private var protectedCopyChangeCount: Int?

    /// Copies client text: kept on this phone (never offered to other devices), cleared from the
    /// clipboard after 10 minutes, and cleared at sign-out if it is still there.
    func copyProtected(_ text: String) {
        UIPasteboard.general.setItems([["public.utf8-plain-text": text]],
                                      options: [.localOnly: true, .expirationDate: Date().addingTimeInterval(600)])
        protectedCopyChangeCount = UIPasteboard.general.changeCount
    }
    #endif

    func clearIdentity() {
        // Nothing a previous session downloaded for preview may outlive it.
        PreviewStore.sweep()
        // Nor another organisation's lists (4.5.0).
        FormOptions.clear()
        #if os(iOS)
        // Nor client text the app copied, unless something else has been copied since.
        if let count = protectedCopyChangeCount, UIPasteboard.general.changeCount == count {
            UIPasteboard.general.items = []
        }
        protectedCopyChangeCount = nil
        #endif
        // Nor the previous account's session reminders (4.27.0).
        LocalNotifications.shared.clearScheduled()
        expiredNotice = nil
        token = ""
        userName = ""
        userId = 0
        perms = []
        permsLoaded = false
        scoped = false
        aiEnabled = false
        aiFeatures = []
        accessHold = []
        syncError = nil
        syncNoticeDismissed = false
        sessionNotice = nil
        pendingSync = 0     // no account: nothing of "yours" is waiting (recounted at the next sign-in)
        reauthRequired = false
        resumeReauthWaiters(false)    // nothing waiting is sent for whoever signs in next
    }

    /// The server stopped accepting this session (expired or revoked). With a known account,
    /// sign-in is asked for above the open screens (reauthRequired), so nothing typed is lost and
    /// a refused write is sent again afterwards (see send). Without one: back to Sign in, which
    /// says why. A 401 is answered before the action runs, so nothing from it was saved.
    func expireSession() {
        if userId > 0, !token.isEmpty {
            if !reauthRequired { reauthRequired = true }
            return
        }
        clearIdentity()
        sessionNotice = "Your session expired, so you were signed out. If you were saving something just then, it was not saved. Sign in again to continue."
    }

    /// Clients a picker may offer (scheduling, loans, reservations, bookings, device requests):
    /// in scope, not archived and not closed. `client_choices` is allowed for every area that
    /// picks a client, so a group without the clients permission (such as Front Desk) can still
    /// choose one; a server that predates it falls back to the clients list. Errors are thrown,
    /// not swallowed, so a picker can say why it is empty.
    func clientPickerChoices() async throws -> [ClientRow] {
        let rows: [ClientRow]
        do {
            let response: ClientsResponse = try await call("client_choices")
            rows = response.clients
        } catch APIError.server(let message) where message == "Unknown action." {
            let response: ClientsResponse = try await call("clients")
            rows = response.clients
        }
        return rows.filter { !$0.status.hasPrefix("Closed") }
    }

    /// Whether this phone can keep client information between launches (drafts, the offline queue,
    /// saved lists): only with a passcode, so iOS encrypts those files (3.0.39).
    nonisolated static var phoneCanKeepClientData: Bool {
        #if os(iOS)
        return LAContext().canEvaluatePolicy(.deviceOwnerAuthentication, error: nil)
        #else
        return true
        #endif
    }

    /// The name the server lists this phone under, so it can be signed out or wiped on its own.
    @MainActor static var deviceName: String {
        #if os(iOS)
        return "\(UIDevice.current.name) (\(UIDevice.current.model), iOS \(UIDevice.current.systemVersion))"
        #else
        return "Mac"
        #endif
    }

    /// When a refusal (401) says this phone was reported lost, wipes it and returns what to say;
    /// nil for any other reply. Every request path checks this (4.27.0: the offline queue and
    /// document downloads too), so the wipe is never taken for an expired session.
    @discardableResult
    func wipeIfReportedLost(_ response: URLResponse, data: Data, sessionToken: String) -> String? {
        guard let http = response as? HTTPURLResponse, http.statusCode == 401,
              let refusal = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              refusal["wipe"] as? Bool == true else { return nil }
        wipeThisPhone(confirming: sessionToken)
        return (refusal["error"] as? String) ?? "This phone was reported lost, so the app removed what it kept here and signed out."
    }

    /// The server reported this phone lost: every account's drafts, prepared signatures, kept
    /// lists and offline entries go (4.27.0: the whole Drafts and OfflineCache folders, not only
    /// the signed-in account's), scheduled reminders are cleared, and the app signs out (3.0.39).
    /// Once all of it is gone the server is told (wipe_done with the old session), so it stops
    /// answering "wipe"; until then it keeps answering it, and the app wipes again.
    func wipeThisPhone(confirming sessionToken: String) {
        let wiped = removeEveryAccountsData()
        LocalNotifications.shared.clearScheduled()
        clearIdentity()
        sessionNotice = "This phone was reported lost, so the app removed everything it kept here and signed out. Sign in again only if this phone is back with you."
        if wiped, !sessionToken.isEmpty { API.confirmWipe(sessionToken) }
    }

    /// Removes the offline entries, drafts and kept lists (prepared signatures among them) of
    /// every account on this phone. True when none of it is left.
    @discardableResult
    private func removeEveryAccountsData() -> Bool {
        let fm = FileManager.default
        let support = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        let legacyQueue = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0].appendingPathComponent("pending_sync.json")
        let paths = [support.appendingPathComponent("Drafts", isDirectory: true),
                     support.appendingPathComponent("OfflineCache", isDirectory: true),
                     queueURL, legacyQueue]
        for url in paths { try? fm.removeItem(at: url) }
        pendingSync = 0
        PreviewStore.sweep()
        return !paths.contains { fm.fileExists(atPath: $0.path) }
    }

    /// Tells the server the wipe is done (best effort, errors ignored), to the server the
    /// session belongs to, taken now.
    private static func confirmWipe(_ sessionToken: String) {
        let doneURL = URL(string: API.base.absoluteString + "?action=wipe_done")!
        Task {
            var request = URLRequest(url: doneURL)
            API.identifyApp(&request)
            request.httpMethod = "POST"
            request.setValue("Bearer \(sessionToken)", forHTTPHeaderField: "Authorization")
            _ = try? await API.session.data(for: request)
        }
    }

    /// Point the app at another organisation's server (4.6.0, builds that ask at sign-in). When
    /// it changes, the session is ended at the old server. What is kept on the phone (offline
    /// entries, drafts, kept lists) stays until a sign-in to a really different server works
    /// (4.27.0: finishSignIn), so a mistyped address or a failed sign-in loses nothing.
    func switchServer(to address: String) {
        guard let next = API.apiURL(fromAddress: address) else { return }
        if next.absoluteString != API.base.absoluteString {
            // An install from before 4.27.0 never recorded it: what is here was saved for the
            // server in use until now.
            if UserDefaults.standard.string(forKey: API.dataServerKey) == nil {
                UserDefaults.standard.set(API.base.absoluteString, forKey: API.dataServerKey)
            }
            let old = token
            clearIdentity()
            if !old.isEmpty { API.revoke(old) }   // to the old server: the address is taken now
        }
        UserDefaults.standard.set(address.trimmingCharacters(in: .whitespacesAndNewlines), forKey: API.serverKey)
    }

    func signOut() {
        let old = token
        // Chosen and confirmed: unsaved note and hours drafts go with the account, as do the
        // lists kept for reading offline and any reminders scheduled from them.
        DraftStore.removeAll(owner: userId)
        OfflineCache.removeAll(owner: userId)
        LocalNotifications.shared.clearScheduled()
        clearIdentity()
        guard !old.isEmpty else { return }
        API.revoke(old)
    }

    /// Asks the server to end a session (best effort: the reply is not waited for or reported).
    private static func revoke(_ sessionToken: String) {
        // The address is taken now: a server change right after signing out must never send
        // this token to the new server.
        let logoutURL = URL(string: API.base.absoluteString + "?action=logout")!
        Task {
            var request = URLRequest(url: logoutURL)
            API.identifyApp(&request)
            request.httpMethod = "POST"
            request.setValue("Bearer \(sessionToken)", forHTTPHeaderField: "Authorization")
            _ = try? await API.session.data(for: request)
        }
    }
}


extension Color {
    /// Brand colours come from the asset catalog with a light, a dark and an Increase Contrast
    /// appearance, so small text in them keeps 7:1 (WCAG 1.4.6) on white, #F2F2F7 and #1C1C1E
    /// rows and on their own 15% tint over those: navy #1E3A8A (10.4:1 on white, 7.2:1 on its
    /// tint over #F2F2F7) / light blue #C2D0FB (11.1:1 on #1C1C1E, 7.8:1 on its tint); teal
    /// #074A44 (10.1:1 on white, 7.1:1 on its tint over #F2F2F7) / light teal #5EEAD4 (11.5:1,
    /// 8.0:1 on its tint). Increase Contrast darkens (light) or lightens (dark) them further.
    static let brand = Color("CMSBrand")
    static let brandTeal = Color("CMSTeal")
    /// Informative secondary text (dates, statuses, captions, hints). The system's .secondary is
    /// about 3.4:1 in light mode and 6:1 in dark; this is #45454F (9.5:1 on white, 7.8:1 on a
    /// quaternary tile over #F2F2F7) and #D1D1D6 in dark (11.2:1 on #1C1C1E, 7.5:1 on #3A3A3C).
    /// Keep .secondary / .tertiary for decorative glyphs only.
    static let secondaryText = Color("CMSSecondaryText")
    /// A text field's outline: 4.5:1 or more against the screen in both appearances (WCAG 1.4.11).
    static let fieldBorder = Color("CMSFieldBorder")
    /// ErrorBanner's fill (#9B1C1C): white text on it is 8.2:1 in both appearances.
    static let alertFill = Color(red: 155/255, green: 28/255, blue: 28/255)
    /// Text drawn on a brand-tinted fill (a prominent button): white on navy, dark navy on
    /// the dark appearance's light blue.
    static let onBrand = Color("CMSOnBrand")
    /// A fill that stays navy in both appearances, for white text drawn on it (chat bubbles,
    /// badges): 10.4:1 either way.
    static let brandFill = Color(red: 30/255, green: 58/255, blue: 138/255)
    /// Red for small warning text that stays readable at 7:1: #9B1C1C in light mode (8.2:1 on
    /// white, 7.3:1 on #F2F2F7, as ErrorBanner's fill) and #FF9A92 in dark mode (8.3:1 on a
    /// #1C1C1E row). System red caption text is about 3.5:1.
    static let alertRed = Color(UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 255/255, green: 154/255, blue: 146/255, alpha: 1)
            : UIColor(red: 155/255, green: 28/255, blue: 28/255, alpha: 1)
    })
}
