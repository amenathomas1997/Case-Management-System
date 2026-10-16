import SwiftUI

/// The last server reply for a list the app can show with no signal (the client list, the
/// schedule, due follow-ups), kept on this phone for the account that loaded it. Protected
/// like the offline queue and drafts: Application Support, complete file protection, not
/// backed up; removed at sign-out (API.signOut). Read only when the server can't be reached
/// (API.callCached), and shown read-only with when it was saved.
enum OfflineCache {
    private static func url(_ key: String, owner: Int) -> URL? {
        guard owner > 0, !key.isEmpty else { return nil }
        let safe = String(key.unicodeScalars.map {
            CharacterSet.alphanumerics.contains($0) || $0 == "-" || $0 == "_" ? Character($0) : "_"
        })
        var dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("OfflineCache", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        try? dir.setResourceValues(values)
        dir.appendPathComponent(String(owner), isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent(safe + ".json")
    }

    static func save(_ data: Data, key: String, owner: Int) {
        // Nothing is kept on a phone without a passcode (3.0.39).
        guard API.phoneCanKeepClientData, let url = url(key, owner: owner) else { return }
        #if os(iOS)
        try? data.write(to: url, options: [.atomic, .completeFileProtection])
        #else
        try? data.write(to: url, options: [.atomic])
        #endif
    }

    /// The saved reply and when it was saved, or nil when there is none for this account.
    static func load(key: String, owner: Int) -> (data: Data, savedAt: Date)? {
        guard let url = url(key, owner: owner), let data = try? Data(contentsOf: url) else { return nil }
        let savedAt = (try? FileManager.default.attributesOfItem(atPath: url.path))?[.modificationDate] as? Date ?? Date()
        // A copy over 30 days old is not shown: the client may have moved to someone else (4.0.0).
        if savedAt < Date().addingTimeInterval(-30 * 24 * 3600) { try? FileManager.default.removeItem(at: url); return nil }
        return (data, savedAt)
    }

    /// One kept copy, removed when the server refuses it (a client no longer on this caseload).
    static func remove(key: String, owner: Int) {
        guard let url = url(key, owner: owner) else { return }
        try? FileManager.default.removeItem(at: url)
    }

    /// Everything kept for one account: sign-out removes it with the drafts.
    static func removeAll(owner: Int) {
        guard owner > 0 else { return }
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
            .appendingPathComponent("OfflineCache", isDirectory: true)
            .appendingPathComponent(String(owner), isDirectory: true)
        try? FileManager.default.removeItem(at: dir)
    }
}

/// The notice over a list shown from the copy kept on this phone: when it was saved, and that
/// nothing can be changed. Said when it appears (and again when a later load still fails).
struct OfflineNotice: View {
    let savedAt: Date

    var text: String {
        "Showing data saved at \(savedAt.formatted(date: .abbreviated, time: .shortened)); changes are not possible offline"
    }

    var body: some View {
        Label { Text(text) } icon: { Image(systemName: "wifi.slash").accessibilityHidden(true) }
            .font(.callout.weight(.medium))
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(12)
            .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
            .accessibilityElement(children: .combine)
            .onAppear { announce(text) }
            .onChange(of: savedAt) { _ in announce(text) }
    }
}
