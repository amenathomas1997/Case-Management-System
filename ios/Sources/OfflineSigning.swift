import SwiftUI
import Network

// MARK: - Signing with no signal (4.26.0)

/// A document started online ("Prepare to sign") and kept on this phone, so it can be read and
/// signed in person later with no connection (in a client's home). Kept like the other client
/// data the app keeps (OfflineCache: Application Support, complete file protection, not backed
/// up, only on a phone with a passcode) and removed with it at sign-out, wipe and server change.
struct PreparedSignature: Codable, Identifiable, Equatable {
    let id: Int
    let clientId: Int
    let clientName: String
    let title: String
    let body: String
    let lang: String?
    let signerName: String
    let signerRole: String?
    let org: String
    /// When it was prepared, by this phone's clock.
    let preparedAt: Date
    /// When the server started it (its sent_at), which the two days are counted from; nil from
    /// a server that did not send it.
    let startedAt: Date?

    /// The last moment it can be signed: two days after the server started it, less a minute.
    var expiresAt: Date { SigningWindow.signBy(startedAt: startedAt ?? preparedAt) }

    init(document: SigningDocument, clientId: Int, clientName: String, preparedAt: Date = Date()) {
        id = document.id
        self.clientId = clientId
        self.clientName = clientName
        title = document.title
        body = document.body
        lang = document.lang
        signerName = document.signerName
        signerRole = document.signerRole
        org = document.org
        self.preparedAt = preparedAt
        startedAt = SigningWindow.serverTime(document.sentAt)
    }

    private enum CodingKeys: String, CodingKey { case id, clientId, clientName, title, body, lang, signerName, signerRole, org, preparedAt, startedAt }
    init(from d: Decoder) throws {
        let c = try d.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        clientId = (try? c.decode(Int.self, forKey: .clientId)) ?? 0
        clientName = (try? c.decode(String.self, forKey: .clientName)) ?? ""
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        body = (try? c.decode(String.self, forKey: .body)) ?? ""
        lang = try? c.decodeIfPresent(String.self, forKey: .lang)
        signerName = (try? c.decode(String.self, forKey: .signerName)) ?? ""
        signerRole = try? c.decodeIfPresent(String.self, forKey: .signerRole)
        org = (try? c.decode(String.self, forKey: .org)) ?? ""
        // An entry with no readable time is treated as already too old to sign.
        preparedAt = (try? c.decode(Date.self, forKey: .preparedAt)) ?? .distantPast
        startedAt = try? c.decodeIfPresent(Date.self, forKey: .startedAt)
    }

    var document: SigningDocument {
        SigningDocument(id: id, title: title, body: body, lang: lang, signerName: signerName, signerRole: signerRole, org: org,
                        sentAt: nil)
    }
}

extension SigningDocument {
    init(id: Int, title: String, body: String, lang: String?, signerName: String, signerRole: String?, org: String, sentAt: String?) {
        self.id = id; self.title = title; self.body = body; self.lang = lang
        self.signerName = signerName; self.signerRole = signerRole; self.org = org; self.sentAt = sentAt
    }
}

/// The server takes a signature made in person up to two days after it started the document,
/// counted by the server's clock from its own start time (sent_at, in the server's time zone).
/// The phone allows a minute less, so a signature made at the last moment is not refused.
enum SigningWindow {
    static let lifetime: TimeInterval = 2 * 24 * 3600
    static let margin: TimeInterval = 60

    /// The server's "Y-m-d H:i:s" (its time zone, APIDate.central) as a moment.
    static func serverTime(_ raw: String?) -> Date? {
        guard let raw, !raw.isEmpty else { return nil }
        return APIDate.parseDateTimeCentral(String(raw.prefix(19)))
    }

    static func signBy(startedAt: Date) -> Date {
        startedAt.addingTimeInterval(lifetime - margin)
    }
}

/// The documents prepared on this phone for the signed-in account.
enum PreparedSignatures {
    private static let key = "prepared-signatures"

    /// The file's shape: unreadable entries are dropped, not the whole list.
    private struct Stored: Codable {
        var items: [PreparedSignature]
        init(items: [PreparedSignature]) { self.items = items }
        private enum CodingKeys: String, CodingKey { case items }
        init(from d: Decoder) throws {
            let c = try d.container(keyedBy: CodingKeys.self)
            items = LenientList.decode(PreparedSignature.self, from: c, forKey: .items)
        }
    }

    /// Every prepared document still in time to sign (an expired one is removed).
    static func all(owner: Int) -> [PreparedSignature] {
        guard owner > 0, let saved = OfflineCache.load(key: key, owner: owner),
              let stored = try? JSONDecoder().decode(Stored.self, from: saved.data) else { return [] }
        let now = Date()
        let current = stored.items.filter { $0.expiresAt > now }
        if current.count != stored.items.count { write(current, owner: owner) }
        return current
    }

    /// How many are kept on this phone, of every account (4.27.0: said before a server change
    /// removes them).
    static func countAllAccounts() -> Int {
        OfflineCache.owners().reduce(0) { $0 + all(owner: $1).count }
    }

    static func forClient(_ clientId: Int, owner: Int) -> [PreparedSignature] {
        all(owner: owner).filter { $0.clientId == clientId }.sorted { $0.preparedAt > $1.preparedAt }
    }

    /// Keeps one; false when this phone keeps nothing (no passcode) or it could not be written.
    @discardableResult
    static func add(_ item: PreparedSignature, owner: Int) -> Bool {
        guard owner > 0, API.phoneCanKeepClientData else { return false }
        var list = all(owner: owner).filter { $0.id != item.id }
        list.append(item)
        write(list, owner: owner)
        return all(owner: owner).contains { $0.id == item.id }
    }

    static func remove(id: Int, owner: Int) {
        let list = all(owner: owner)
        guard list.contains(where: { $0.id == id }) else { return }
        write(list.filter { $0.id != id }, owner: owner)
    }

    /// The server's list for a client says which prepared documents can still be signed here:
    /// one it no longer shows as waiting (signed, withdrawn, or gone) is removed from the phone.
    static func reconcile(clientId: Int, rows: [SignatureRow], owner: Int) {
        let list = all(owner: owner)
        let waiting = Set(rows.filter { $0.status == "Waiting to be signed here" }.map(\.id))
        let kept = list.filter { $0.clientId != clientId || waiting.contains($0.id) }
        if kept.count != list.count { write(kept, owner: owner) }
    }

    private static func write(_ list: [PreparedSignature], owner: Int) {
        if list.isEmpty { OfflineCache.remove(key: key, owner: owner); return }
        guard let data = try? JSONEncoder().encode(Stored(items: list)) else { return }
        OfflineCache.save(data, key: key, owner: owner)
    }
}

/// Signatures made here with no signal, waiting in the offline queue (API.enqueue) to be sent
/// as sign_in_person with the time they were signed.
enum OfflineSignatures {
    /// The signing time as the server takes it: ISO 8601 with the phone's offset.
    static func timestamp(_ date: Date = Date()) -> String {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        f.timeZone = .current
        return f.string(from: date)
    }

    /// As the server's typed_name_key: letters and digits only, case and accents ignored.
    static func nameKey(_ s: String) -> String {
        let folded = s.folding(options: [.caseInsensitive, .diacriticInsensitive, .widthInsensitive], locale: nil)
        return String(folded.unicodeScalars.filter { CharacterSet.letters.contains($0) || CharacterSet.decimalDigits.contains($0) }.map(Character.init))
    }

    /// Whether the typed name would be accepted for the name on the document.
    static func nameMatches(_ want: String, _ typed: String) -> Bool {
        let typed = typed.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !typed.isEmpty, typed.count <= 120 else { return false }
        let key = nameKey(want)
        if key.isEmpty {
            return typed.folding(options: .caseInsensitive, locale: nil) == want.trimmingCharacters(in: .whitespaces).folding(options: .caseInsensitive, locale: nil)
        }
        return nameKey(typed) == key
    }

    /// One of this account's queued signatures, read back for the Documents to sign list.
    struct Waiting: Identifiable {
        let id: UUID
        let documentId: Int
        let title: String
        let typedName: String
        let signedAt: Date?
        let lastError: String?
    }

    /// The queued signatures for one client.
    @MainActor
    static func waiting(clientId: Int, api: API) -> [Waiting] {
        guard let entries = try? api.ownQueuedEntries() else { return [] }
        return entries.compactMap { entry in
            guard entry.action == "sign_in_person",
                  let obj = (try? JSONSerialization.jsonObject(with: entry.body)) as? [String: Any],
                  (obj["client_id"] as? NSNumber)?.intValue == clientId else { return nil }
            let prefix = "Signed in person: "
            let title = entry.label.hasPrefix(prefix) ? String(entry.label.dropFirst(prefix.count)) : entry.label
            return Waiting(id: entry.id, documentId: (obj["id"] as? NSNumber)?.intValue ?? 0, title: title,
                           typedName: obj["typed_name"] as? String ?? "",
                           signedAt: (obj["signed_at"] as? String).flatMap { ISO8601DateFormatter().date(from: $0) },
                           lastError: entry.lastError)
        }
    }
}

/// Counts each time the phone's connection comes back after being lost, so writes kept with no
/// signal are sent then (MainTabs), not only when the app returns to the foreground.
@MainActor
final class ConnectionWatcher: ObservableObject {
    static let shared = ConnectionWatcher()
    @Published private(set) var reconnects = 0
    private let monitor = NWPathMonitor()
    private var wasOnline: Bool?

    private init() {
        monitor.pathUpdateHandler = { [weak self] path in
            let online = path.status == .satisfied
            Task { @MainActor in self?.update(online) }
        }
        monitor.start(queue: DispatchQueue(label: "cms.connection-watcher"))
    }

    private func update(_ online: Bool) {
        if online, wasOnline == false { reconnects += 1 }
        wasOnline = online
    }
}
