import Foundation

/// What the home-screen widget (and the "Today's sessions" Siri shortcut) may know about the
/// day: how many sessions there are, when each starts and ends, and whether it is checked in.
/// Never a client name, location or note — the home screen is not a signed-in screen (PHI).
/// Written by the app whenever the schedule loads from the server (ScheduleView.load), read
/// by the widget extension from the shared App Group container; removed at sign-out.
/// Compiled into both the app and the widget target.
struct TodaySnapshot: Codable {
    struct Entry: Codable {
        let start: Date
        let end: Date?
        let checkedIn: Bool
    }
    /// The local day the entries are for ("2026-10-15"): a snapshot from another day says nothing.
    let day: String
    let sessions: [Entry]
    let savedAt: Date

    /// group.<the app's ID> (4.6.0: from CMS_BUNDLE_ID), the same from the app and its widget.
    static let groupId: String = {
        var id = Bundle.main.bundleIdentifier ?? "cms"
        if id.hasSuffix(".widget") { id = String(id.dropLast(".widget".count)) }
        return "group." + id
    }()
    static let widgetKind = "CaseManagementWidget"
    private static let fileName = "today.json"

    /// The day as the snapshot records it, in the phone's own calendar.
    static func dayString(_ date: Date = Date()) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.calendar = Calendar(identifier: .gregorian)
        f.timeZone = .current
        f.dateFormat = "yyyy-MM-dd"
        return f.string(from: date)
    }

    var isToday: Bool { day == Self.dayString() }

    /// The next session still to come at `now`: the first that has not ended (its end, or
    /// its start when the end is unknown, is still ahead).
    func next(at now: Date = Date()) -> Entry? {
        sessions.sorted { $0.start < $1.start }.first { ($0.end ?? $0.start) >= now }
    }

    private static var fileURL: URL? {
        FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: groupId)?
            .appendingPathComponent(fileName)
    }

    private static var encoder: JSONEncoder {
        let e = JSONEncoder()
        e.dateEncodingStrategy = .iso8601
        return e
    }

    private static var decoder: JSONDecoder {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601
        return d
    }

    /// Nil when the App Group container is not available to this build, or nothing was saved.
    static func load() -> TodaySnapshot? {
        guard let url = fileURL, let data = try? Data(contentsOf: url) else { return nil }
        return try? decoder.decode(TodaySnapshot.self, from: data)
    }

    /// Saved with protection until first unlock, not complete protection: WidgetKit builds the
    /// widget's timeline in the background, also while the phone is locked, and the widget
    /// would otherwise show nothing until the app is next opened. It holds times and check-in
    /// marks only, never a name. Best effort when the container is not available.
    func save() {
        guard let url = Self.fileURL, let data = try? Self.encoder.encode(self) else { return }
        try? data.write(to: url, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
    }

    static func clear() {
        guard let url = fileURL else { return }
        try? FileManager.default.removeItem(at: url)
    }
}
