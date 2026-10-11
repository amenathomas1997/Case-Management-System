import Foundation
import SwiftUI

// MARK: - Models

// `error` is optional so an `ok: false` reply with no message is still treated as a failure
// (never decoded as a successful, tolerant result further down).
struct APIErrorResponse: Decodable { let ok: Bool; let error: String? }

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
    private enum CodingKeys: String, CodingKey { case ok, step, totpAvailable, emailSent, codeExpiresMinutes, loginChallenge }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        ok = try c.decode(Bool.self, forKey: .ok)
        step = try c.decode(String.self, forKey: .step)
        totpAvailable = try c.decode(Bool.self, forKey: .totpAvailable)
        // Only changes the wording on the code screen: an odd value must never stop a sign-in.
        emailSent = try? c.decodeIfPresent(Bool.self, forKey: .emailSent)
        codeExpiresMinutes = try? c.decodeIfPresent(Int.self, forKey: .codeExpiresMinutes)
        loginChallenge = try? c.decodeIfPresent(String.self, forKey: .loginChallenge)
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
}

let clientTypes = ["VR", "Pre-ETS"]
let a11yFormats = ["Standard print", "Large print", "Plain text", "Braille-ready (BRF)", "Audio", "Accessible PDF"]
let a11yContacts = ["No preference", "Email", "Phone", "Client portal", "Postal mail"]

let clientStatuses = ["Pending Assessment", "Waitlist", "Active Training", "On Hold", "Closed - Rehabilitated", "Closed - Other"]
let techTypes = ["Screen Reader", "Magnification", "Braille Display", "Speech-to-Text",
                 "Independent Living Skills", "Braille Literacy", "Low Vision Devices",
                 "Rehabilitation Technology", "Other"]
let goalStatuses = ["Not Started", "In Progress", "Achieved"]
let docCategories = ["Authorization", "IPE", "Release", "Report", "Resource", "Other"]

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
    private enum CodingKeys: String, CodingKey { case id, noteType, subject, body, createdAt, updatedAt, author, editor, canEdit, noteFormat, trackId, trackTitle, sections }
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
    private enum CodingKeys: String, CodingKey { case client, notes, tracks, notesTotal }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        client = try c.decode(ClientInfo.self, forKey: .client)
        notes = try c.decode([Note].self, forKey: .notes)
        tracks = try c.decode([Track].self, forKey: .tracks)
        notesTotal = try? c.decodeIfPresent(Int.self, forKey: .notesTotal)
    }
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
    private enum CodingKeys: String, CodingKey { case id, startAt, endAt, location, notes, status, trackId, client, instructor, isRemote, meetingUrl, overdue }
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
    }
}

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
}

let serviceTypes = ["Assistive Technology", "Rehab Teaching", "Counseling", "Pre-ETS", "Other"]

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
    private enum CodingKeys: String, CodingKey { case id, clientId, equipmentId, intervalDays, dueDate, status, inUse, satisfaction, barriers, notes, completedAt, clientName, equipmentName, assetTag }
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
}
struct ThreadResponse: Decodable {
    let clientName: String
    let counselorName: String?
    let channel: String
    let messages: [ThreadMessage]
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

    /// Keeps the fields; a draft with nothing typed in it is removed instead.
    static func save(_ fields: [String: String], key: String, owner: Int) {
        guard let url = url(key, owner: owner) else { return }
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

    var errorDescription: String? {
        switch self {
        case .server(let message): return message
        case .network: return "Could not reach the server. Check your connection."
        case .outcomeUnknown: return "The server's reply was lost, so it isn't known whether that was saved. Check before trying again."
        }
    }
}

@MainActor
final class API: ObservableObject {
    static let base = URL(string: "https://cms.example.org/api.php")!

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
    func aiHas(_ key: String) -> Bool { aiEnabled && aiFeatures.contains(key) }

    init() {
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
            permsLoaded = true
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
        action.hasPrefix("ai_") || action == "at_ai_draft" || body?["ai_draft"] != nil
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
                (data, response) = try await URLSession.shared.data(for: request)
            } catch {
                guard token == sessionToken else { throw APIError.server(accountChanged) }
                if isAI, (error as? URLError)?.code == .timedOut { throw API.aiTimedOut }
                throw APIError.network
            }
            guard token == sessionToken else { throw APIError.server("Your account changed. Please reload this screen.") }
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
        userId = newId ?? 0
        userName = name
        token = newToken   // flips RootView to the main tabs
    }

    func call<T: Decodable>(_ action: String, method: String = "GET",
                            query: [String: String] = [:], body: [String: Any]? = nil) async throws -> T {
        let (data, _) = try await send(action, method: method, query: query, body: body,
                                       accountChanged: "Your account changed. Sign in again before saving this entry.")
        if let errorBody = try? decoder.decode(APIErrorResponse.self, from: data), !errorBody.ok {
            throw APIError.server(errorBody.error ?? "Something went wrong.")
        }
        do {
            return try decoder.decode(T.self, from: data)
        } catch {
            throw APIError.server("Unexpected response from the server.")
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
        if (obj["ok"] as? Bool) == false { throw APIError.server((obj["error"] as? String) ?? "Something went wrong.") }
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
    @Published var syncNoticeDismissed = false  // the person cleared the sync notice this session
    @AppStorage("userId") var userId: Int = 0
    private var isFlushing = false

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
        // A migrated file, or one written by an earlier build, gets the same protection.
        #if os(iOS)
        try? FileManager.default.setAttributes([.protectionKey: FileProtectionType.complete], ofItemAtPath: queueURL.path)
        #endif
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

    /// Report success only after the record is durably stored for a known account.
    func enqueue(_ action: String, body: [String: Any], label: String) throws {
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
        guard !isFlushing, userId > 0, !token.isEmpty, !reauthRequired else { return }
        isFlushing = true
        defer { isFlushing = false }
        let owner = userId
        let sessionToken = token
        var transient: String?
        let notice: String?
        do {
            let q = try loadQueue()
            pendingSync = ownPending(q)
            var done: Set<UUID> = []
            var refused: [UUID: String] = [:]
            for item in q {
                guard token == sessionToken, userId == owner else { break }
                guard item.owner == owner else { continue }
                var components = URLComponents(url: API.base, resolvingAgainstBaseURL: false)!
                components.queryItems = [URLQueryItem(name: "action", value: item.action)]
                var request = URLRequest(url: components.url!)
                request.httpMethod = "POST"
                request.setValue("Bearer \(sessionToken)", forHTTPHeaderField: "Authorization")
                request.setValue("application/json", forHTTPHeaderField: "Content-Type")
                request.httpBody = item.body
                do {
                    let (data, response) = try await URLSession.shared.data(for: request)
                    guard let http = response as? HTTPURLResponse else { break }
                    if http.statusCode == 401 {
                        // Sign-in is asked for above the open screens; the entries upload after it.
                        if token == sessionToken { expireSession() }
                        break
                    }
                    if (200..<300).contains(http.statusCode),
                       let ok = try? decoder.decode(OKResponse.self, from: data), ok.ok {
                        done.insert(item.id)
                    } else if http.statusCode >= 500 || http.statusCode == 429 {
                        // Transient server/rate-limit error: keep the entry and stop; it retries later.
                        transient = "“\(item.label)” could not sync yet and is still saved on this phone. "
                            + ((try? decoder.decode(APIErrorResponse.self, from: data))?.error ?? "It will retry automatically when you reconnect.")
                        break
                    } else {
                        // A rejected upload is not a confirmed save. Keep the original work available —
                        // the rejection may be resolvable (permission granted, track reopened) on a later
                        // retry — and keep the reason with it, so the person can see which entry it is.
                        refused[item.id] = (try? decoder.decode(APIErrorResponse.self, from: data))?.error
                            ?? "The server did not accept it."
                    }
                } catch { break }
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

    func clearIdentity() {
        // Nothing a previous session downloaded for preview may outlive it.
        PreviewStore.sweep()
        token = ""
        userName = ""
        userId = 0
        perms = []
        permsLoaded = false
        scoped = false
        aiEnabled = false
        aiFeatures = []
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

    func signOut() {
        let old = token
        // Chosen and confirmed: unsaved note and hours drafts go with the account.
        DraftStore.removeAll(owner: userId)
        clearIdentity()
        guard !old.isEmpty else { return }
        Task {
            var request = URLRequest(url: URL(string: API.base.absoluteString + "?action=logout")!)
            request.httpMethod = "POST"
            request.setValue("Bearer \(old)", forHTTPHeaderField: "Authorization")
            _ = try? await URLSession.shared.data(for: request)
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
