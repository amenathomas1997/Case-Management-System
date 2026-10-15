import SwiftUI
import UserNotifications

/// Local reminders (no push), scheduled from what the app has already fetched: a session that
/// starts in 30 minutes (from every schedule load) and, at 8:00, how many device follow-ups
/// are due today (from every follow-ups load). Off until the person turns it on in Account
/// settings. The text never names a client: it can show on the lock screen. Tapping one opens
/// the matching screen through AppNavigation.
@MainActor
final class LocalNotifications: NSObject, ObservableObject {
    static let shared = LocalNotifications()
    /// The setting (UserDefaults, read by AccountView's toggle too).
    nonisolated static let enabledKey = "localNotificationsEnabled"

    nonisolated private static let sessionPrefix = "session-"
    nonisolated private static let followupsId = "followups-morning"
    /// iOS keeps at most 64 pending requests per app: the soonest sessions are scheduled.
    nonisolated private static let maxSessions = 40

    var enabled: Bool { UserDefaults.standard.bool(forKey: Self.enabledKey) }

    private override init() { super.init() }

    /// Set at launch, so a tap on a reminder is handled for a cold start too.
    func activate() {
        UNUserNotificationCenter.current().delegate = self
    }

    /// Asks the system once; true when reminders may be shown.
    func requestPermission() async -> Bool {
        let center = UNUserNotificationCenter.current()
        let settings = await center.notificationSettings()
        switch settings.authorizationStatus {
        case .authorized, .provisional, .ephemeral: return true
        case .denied: return false
        default: break
        }
        return (try? await center.requestAuthorization(options: [.alert, .sound, .badge])) ?? false
    }

    /// Whether the system still allows them (the person may have turned them off in Settings).
    func systemAllows() async -> Bool {
        let status = await UNUserNotificationCenter.current().notificationSettings().authorizationStatus
        return status == .authorized || status == .provisional || status == .ephemeral
    }

    /// Replaces the session reminders with one for each upcoming session, 30 minutes before it
    /// starts (sessions that start sooner than that, or that have started, get none).
    func scheduleSessions(_ sessions: [Session]) {
        Task { @MainActor in
            let center = UNUserNotificationCenter.current()
            let pending = await center.pendingNotificationRequests()
            let old = pending.map(\.identifier).filter { $0.hasPrefix(Self.sessionPrefix) }
            center.removePendingNotificationRequests(withIdentifiers: old)
            guard self.enabled else { return }
            let now = Date()
            let upcoming = sessions
                .filter { $0.overdue != true && ($0.status == "Scheduled" || $0.status.isEmpty) }
                .compactMap { s -> (Session, Date)? in
                    guard let start = APIDate.parseDateTimeCentral(s.startAt) else { return nil }
                    let fireAt = start.addingTimeInterval(-30 * 60)
                    return fireAt > now ? (s, fireAt) : nil
                }
                .sorted { $0.1 < $1.1 }
                .prefix(Self.maxSessions)
            for (session, fireAt) in upcoming {
                let content = UNMutableNotificationContent()
                content.title = "Session reminder"
                content.body = "A session starts in 30 minutes."
                content.sound = .default
                content.userInfo = ["screen": "schedule"]
                let parts = Calendar.current.dateComponents([.year, .month, .day, .hour, .minute], from: fireAt)
                let trigger = UNCalendarNotificationTrigger(dateMatching: parts, repeats: false)
                try? await center.add(UNNotificationRequest(identifier: Self.sessionPrefix + String(session.id), content: content, trigger: trigger))
            }
        }
    }

    /// Replaces the morning summary: at the next 8:00, how many device follow-ups are due
    /// (none when nothing is due).
    func scheduleFollowups(dueCount: Int) {
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: [Self.followupsId])
        guard enabled, dueCount > 0 else { return }
        let content = UNMutableNotificationContent()
        content.title = "Device follow-ups"
        content.body = "\(dueCount) device follow-up\(dueCount == 1 ? " is" : "s are") due today."
        content.sound = .default
        content.userInfo = ["screen": "followups"]
        var at = DateComponents()
        at.hour = 8; at.minute = 0
        let request = UNNotificationRequest(identifier: Self.followupsId, content: content,
                                            trigger: UNCalendarNotificationTrigger(dateMatching: at, repeats: false))
        Task { try? await center.add(request) }
    }

    /// Turning the setting off, and sign-out: nothing scheduled or shown is kept.
    func clearScheduled() {
        let center = UNUserNotificationCenter.current()
        center.removeAllPendingNotificationRequests()
        center.removeAllDeliveredNotifications()
    }
}

extension LocalNotifications: UNUserNotificationCenterDelegate {
    /// A reminder arriving while the app is open is still shown (and read by VoiceOver).
    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .list, .sound]
    }

    /// Tapped: the matching screen opens once the app is unlocked (AppNavigation keeps the
    /// request until the tabs can act on it).
    nonisolated func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let screen = response.notification.request.content.userInfo["screen"] as? String
        await MainActor.run {
            switch screen {
            case "schedule": AppNavigation.shared.request(.schedule)
            case "followups": AppNavigation.shared.request(.followups)
            default: break
            }
        }
    }
}
