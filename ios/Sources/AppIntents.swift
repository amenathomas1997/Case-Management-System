import AppIntents
import Foundation

// Siri and the Shortcuts app ("Log hours in <the app's name>…"). Each intent runs inside the app's
// process: the ones that open a screen hand AppNavigation the destination, which MainTabs acts
// on once the app is unlocked (and only if the account may open it). No intent says anything
// that identifies a client: Siri's reply can be spoken aloud anywhere.

struct LogHoursIntent: AppIntent {
    static var title: LocalizedStringResource = "Log hours"
    static var description = IntentDescription("Opens the Log hours form.")
    static var openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        AppNavigation.shared.request(.quick(.logHours))
        return .result()
    }
}

struct AddNoteIntent: AppIntent {
    static var title: LocalizedStringResource = "Add a note"
    static var description = IntentDescription("Opens the client list, to choose whose record the note goes on.")
    static var openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        // A case note belongs to one client's record: the Clients tab is where one is chosen.
        AppNavigation.shared.request(.tab(.clients))
        return .result()
    }
}

struct OpenVoiceAssistantIntent: AppIntent {
    static var title: LocalizedStringResource = "Open voice assistant"
    static var description = IntentDescription("Opens the hands-free voice assistant.")
    static var openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        AppNavigation.shared.request(.quick(.voice))
        return .result()
    }
}

struct TodaysSessionsIntent: AppIntent {
    static var title: LocalizedStringResource = "Today's sessions"
    static var description = IntentDescription("Says how many sessions are scheduled today and when the next one starts. Never names a client.")
    // Spoken by Siri without bringing the app forward (an opened app silences the reply).
    static var openAppWhenRun = false
    // Only once the phone is unlocked (4.27.0): the day's session times are not for whoever
    // is holding a locked phone.
    static var authenticationPolicy: IntentAuthenticationPolicy = .requiresAuthentication

    @MainActor
    func perform() async throws -> some IntentResult & ProvidesDialog {
        .result(dialog: IntentDialog(stringLiteral: Self.summary(TodaySnapshot.load(), now: Date())))
    }

    /// What Siri says: from the day summary the app saved when the schedule last loaded.
    static func summary(_ snapshot: TodaySnapshot?, now: Date) -> String {
        guard let snapshot, snapshot.isToday else {
            return "I don't have today's schedule yet. Open the app's Schedule tab to load it."
        }
        let count = snapshot.sessions.count
        if count == 0 { return "No sessions are scheduled today." }
        let sessions = count == 1 ? "1 session is" : "\(count) sessions are"
        guard let next = snapshot.next(at: now) else {
            return "\(sessions) scheduled today, and the last one has ended."
        }
        let time = next.start.formatted(date: .omitted, time: .shortened)
        let when = next.start <= now ? "The current one started at \(time)" : "The next one starts at \(time)"
        let checked = next.checkedIn ? ", and it is checked in" : ""
        return "\(sessions) scheduled today. \(when)\(checked)."
    }
}

struct CaseManagementShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(intent: LogHoursIntent(),
                    phrases: ["Log hours in \(.applicationName)", "Log my hours in \(.applicationName)"],
                    shortTitle: "Log hours", systemImageName: "clock.badge.checkmark")
        AppShortcut(intent: AddNoteIntent(),
                    phrases: ["Add a note in \(.applicationName)", "Add a case note in \(.applicationName)"],
                    shortTitle: "Add a note", systemImageName: "note.text.badge.plus")
        AppShortcut(intent: OpenVoiceAssistantIntent(),
                    phrases: ["Open the voice assistant in \(.applicationName)", "Open voice assistant in \(.applicationName)"],
                    shortTitle: "Voice assistant", systemImageName: "mic.fill")
        AppShortcut(intent: TodaysSessionsIntent(),
                    phrases: ["Today's sessions in \(.applicationName)", "What are today's sessions in \(.applicationName)"],
                    shortTitle: "Today's sessions", systemImageName: "calendar")
    }
}
