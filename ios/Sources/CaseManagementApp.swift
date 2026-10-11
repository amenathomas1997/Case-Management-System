import SwiftUI
import LocalAuthentication

@main
struct CaseManagementApp: App {
    @StateObject private var api = API()
    @StateObject private var lock = AppLock()

    init() {
        // A document left in tmp by a crash or a kill during a preview must not outlive the launch.
        PreviewStore.sweep()
        // An unsaved note or hours draft not touched for two weeks is dropped (PHI).
        DraftStore.expire()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(api)
                .environmentObject(lock)
                .tint(.brand)
        }
    }
}

struct RootView: View {
    @EnvironmentObject var api: API
    @EnvironmentObject var lock: AppLock
    @StateObject private var reauth = ReauthPresenter()

    var body: some View {
        Group {
            if !api.isSignedIn {
                LoginView()
            } else if lock.hasUnlocked {
                // Once built, the tabs stay in the hierarchy while the app is locked: the lock
                // screen is drawn in its own window above them (see AppLock), so an open sheet
                // or a half-written note is still there after Face ID. A different account gets
                // tabs of its own (never the previous account's screens).
                MainTabs().id(api.userId)
            } else {
                // Signed in but not unlocked yet this launch: nothing to show under the lock.
                Color(.systemBackground).ignoresSafeArea()
            }
        }
        .onAppear { lock.start(signedIn: api.isSignedIn) }
        .onChange(of: api.isSignedIn) { lock.signedInChanged($0) }
        // An expired session asks for sign-in above everything that is open, instead of
        // replacing it, so nothing typed is lost (see API.expireSession).
        .onChange(of: api.reauthRequired) { needed in
            if needed { reauth.show(api) } else { reauth.hide() }
        }
        .onChange(of: lock.locked) { locked in
            // Unlocking gives the windows underneath back to VoiceOver; sign-in keeps them hidden.
            if !locked { reauth.reassert() }
        }
        .alert("This phone can't lock the app",
               isPresented: Binding(get: { lock.protectionWarning != nil },
                                    set: { if !$0 { lock.protectionWarning = nil } })) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(lock.protectionWarning ?? "")
        }
    }
}

/// App lock and app-switcher privacy cover.
///
/// Both are drawn in a separate window above everything the app presents — tabs, sheets,
/// dialogs, Quick Look — instead of replacing the tabs. That keeps unsaved work alive across a
/// re-lock while nothing underneath can be seen, touched, typed into or reached by VoiceOver.
@MainActor
final class AppLock: ObservableObject {
    /// True once this sign-in session has been unlocked; the tabs are only built from then on.
    @Published private(set) var hasUnlocked = false
    /// True while a signed-in session is waiting for Face ID / Touch ID / passcode.
    @Published private(set) var locked = false
    @Published private(set) var authFailed = false
    /// One-time notice shown when the device has nothing to lock the app with.
    @Published var protectionWarning: String?

    /// Re-lock only after this long in the background, so quick app switches
    /// (checking a text, copying a code) don't re-prompt.
    private static let graceSeconds: Int64 = 120
    private static let warnedKey = "noPasscodeWarningShown"

    private var signedIn = false
    private var unlocked = false
    private var started = false
    private var covered = false          // app is not active: hide content from the app switcher
    private var needsPrompt = false      // a fresh lock that should ask for Face ID by itself, once
    private var authenticating = false
    // Monotonic and counts time asleep, so changing the device clock cannot stretch the grace period.
    private var backgroundedAt: ContinuousClock.Instant?
    private var window: UIWindow?
    private weak var previousKeyWindow: UIWindow?
    private var concealedWindows: [UIWindow] = []
    private var lockEngaged = false      // the windows underneath are currently concealed
    /// Where VoiceOver was before the lock, so unlocking returns there (the note field being
    /// written) instead of to the top of the screen.
    private weak var focusBeforeLock: AnyObject?
    private var observers: [NSObjectProtocol] = []   // registered for the life of the app

    private(set) lazy var unlockHint: String = {
        let context = LAContext()
        _ = context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: nil)
        switch context.biometryType {
        case .faceID: return "Unlock with Face ID or your passcode."
        case .touchID: return "Unlock with Touch ID or your passcode."
        default: return "Unlock with your passcode."
        }
    }()

    init() {
        let center = NotificationCenter.default
        // queue: nil delivers synchronously on the posting (main) thread, so the cover is on
        // screen before iOS takes the app-switcher snapshot.
        observers = [
            center.addObserver(forName: UIApplication.willResignActiveNotification, object: nil, queue: nil) { [weak self] _ in
                MainActor.assumeIsolated { self?.appWillResignActive() }
            },
            center.addObserver(forName: UIApplication.didEnterBackgroundNotification, object: nil, queue: nil) { [weak self] _ in
                MainActor.assumeIsolated { self?.appDidEnterBackground() }
            },
            center.addObserver(forName: UIApplication.didBecomeActiveNotification, object: nil, queue: nil) { [weak self] _ in
                MainActor.assumeIsolated { self?.appDidBecomeActive() }
            },
            // iOS may disconnect a background scene and later connect a new one while the
            // process (and this lock) lives on: the overlay built for the old scene can no
            // longer cover anything, so it goes and the next refresh builds one on the new scene.
            center.addObserver(forName: UIScene.didDisconnectNotification, object: nil, queue: nil) { [weak self] note in
                MainActor.assumeIsolated {
                    guard let self, let scene = note.object as? UIScene,
                          self.window == nil || self.window?.windowScene == nil || self.window?.windowScene === scene else { return }
                    self.discardOverlayWindow()
                }
            },
            // A scene coming back (a new one after a disconnect) is covered before it is shown.
            center.addObserver(forName: UIScene.willEnterForegroundNotification, object: nil, queue: nil) { [weak self] _ in
                MainActor.assumeIsolated { self?.refresh() }
            },
        ]
    }

    // MARK: Session

    /// Called once when the root view appears. A session restored from the Keychain starts locked.
    func start(signedIn: Bool) {
        guard !started else { return }
        started = true
        self.signedIn = signedIn
        needsPrompt = signedIn
        refresh()
        promptIfNeeded()
    }

    func signedInChanged(_ nowSignedIn: Bool) {
        signedIn = nowSignedIn
        if nowSignedIn {
            // A fresh password + code sign-in is itself the unlock; don't ask for Face ID on top.
            markUnlocked()
            warnIfDeviceCannotLock()
        } else {
            unlocked = false
            hasUnlocked = false
            authFailed = false
            needsPrompt = false
            refresh()
        }
    }

    /// Locks at once, after the phone was handed to a client (the signature sheet): whoever
    /// holds it sees only the lock screen until the device owner unlocks. Work underneath is
    /// kept, as with any re-lock.
    func lockNow() {
        guard signedIn, unlocked else { return }
        unlocked = false
        authFailed = false
        needsPrompt = true
        refresh()
        // The signature sheet that had VoiceOver's focus closes behind the lock.
        focusBeforeLock = nil
        promptIfNeeded()
    }

    // MARK: App lifecycle

    private func appWillResignActive() {
        if !lockEngaged { focusBeforeLock = Self.voiceOverFocus(excluding: window) }
        covered = true
        refresh()
    }

    private func appDidEnterBackground() {
        if backgroundedAt == nil { backgroundedAt = .now }
    }

    private func appDidBecomeActive() {
        // Decide about re-locking before the cover comes off, so the records never flash.
        if let since = backgroundedAt, signedIn, unlocked,
           ContinuousClock.now - since > .seconds(Self.graceSeconds) {
            unlocked = false
            authFailed = false
            needsPrompt = true
        } else if backgroundedAt != nil, signedIn, !unlocked {
            // Coming back to an app that was already locked: offer Face ID again by itself.
            needsPrompt = true
        }
        backgroundedAt = nil
        covered = false
        refresh()
        promptIfNeeded()
    }

    // MARK: Unlocking

    private func promptIfNeeded() {
        guard signedIn, !unlocked, needsPrompt, !authenticating,
              UIApplication.shared.applicationState == .active else { return }
        needsPrompt = false
        Task { await authenticate() }
    }

    /// Face ID / Touch ID with device-passcode fallback. Also the Unlock button's action.
    func authenticate() async {
        guard signedIn, !unlocked, !authenticating else { return }
        authenticating = true
        defer { authenticating = false }
        let context = LAContext()
        var error: NSError?
        guard context.canEvaluatePolicy(.deviceOwnerAuthentication, error: &error) else {
            // The device has nothing to lock with. Don't lock the user out of their own app,
            // but say so (once) instead of silently unlocking.
            warnDeviceCannotLock(error)
            markUnlocked()
            return
        }
        UserDefaults.standard.removeObject(forKey: Self.warnedKey)
        var ok = false
        do {
            ok = try await context.evaluatePolicy(.deviceOwnerAuthentication,
                                                  localizedReason: "Unlock your client records")
        } catch {
            ok = false
        }
        guard signedIn else { return }
        if ok {
            markUnlocked()
        } else {
            authFailed = true
            // Posted a moment later: the app is still returning from the system prompt.
            Task {
                try? await Task.sleep(nanoseconds: 600_000_000)
                if self.locked { announce("Unlock didn't succeed. Use the Unlock button to try again.") }
            }
        }
    }

    private func markUnlocked() {
        unlocked = true
        hasUnlocked = true
        authFailed = false
        needsPrompt = false
        refresh()
    }

    private func warnIfDeviceCannotLock() {
        var error: NSError?
        if LAContext().canEvaluatePolicy(.deviceOwnerAuthentication, error: &error) {
            UserDefaults.standard.removeObject(forKey: Self.warnedKey)
        } else {
            warnDeviceCannotLock(error)
        }
    }

    private func warnDeviceCannotLock(_ error: NSError?) {
        guard !UserDefaults.standard.bool(forKey: Self.warnedKey) else { return }
        UserDefaults.standard.set(true, forKey: Self.warnedKey)
        if error?.code == LAError.Code.passcodeNotSet.rawValue {
            protectionWarning = "This phone has no passcode, so the app cannot lock itself. Anyone who picks up the phone can open client records. Set a passcode in Settings to protect them."
        } else {
            protectionWarning = "This phone could not check Face ID or a passcode, so the app is not locked. Check your passcode settings in Settings."
        }
    }

    // MARK: Lock window

    /// Brings the overlay window in line with the current state.
    private func refresh() {
        let shouldLock = signedIn && !unlocked
        if locked != shouldLock { locked = shouldLock }
        let overlay = (shouldLock || covered) ? overlayWindow() : window
        if shouldLock {
            // No window scene yet (only possible before the first frame, when there is nothing
            // on screen to hide): the next lifecycle event runs this again.
            guard let overlay else { return }
            if !lockEngaged { engageLock(over: overlay) }
            if overlay.isHidden || !overlay.isKeyWindow { overlay.makeKeyAndVisible() }
        } else {
            if lockEngaged { releaseLock() }
            guard let overlay else { return }
            if covered {
                overlay.isHidden = false
            } else if !overlay.isHidden {
                overlay.isHidden = true
            }
        }
    }

    private func engageLock(over overlay: UIWindow) {
        lockEngaged = true
        if focusBeforeLock == nil { focusBeforeLock = Self.voiceOverFocus(excluding: overlay) }
        let others = overlay.windowScene?.windows.filter { $0 !== overlay } ?? []
        previousKeyWindow = others.first(where: \.isKeyWindow) ?? others.first
        for other in others {
            // Close the keyboard (it floats above every window) and take the hidden screens
            // out of VoiceOver's reach; the overlay becomes the key window so a hardware
            // keyboard cannot drive what is underneath either.
            other.endEditing(true)
            other.accessibilityElementsHidden = true
        }
        concealedWindows = others
        DispatchQueue.main.async { UIAccessibility.post(notification: .screenChanged, argument: nil) }
    }

    private func releaseLock() {
        lockEngaged = false
        for other in concealedWindows { other.accessibilityElementsHidden = false }
        concealedWindows = []
        // Hand keyboard focus back to the app's own window before the overlay is hidden.
        let appWindow = previousKeyWindow
            ?? window?.windowScene?.windows.first(where: { $0 !== window && !$0.isHidden })
        appWindow?.makeKey()
        previousKeyWindow = nil
        // Back to where VoiceOver was before the lock, if that is still on screen; otherwise
        // the first element of the screen.
        let target = focusBeforeLock.flatMap { Self.isStillShown($0) ? $0 : nil }
        focusBeforeLock = nil
        DispatchQueue.main.async { UIAccessibility.post(notification: .screenChanged, argument: target) }
    }

    /// VoiceOver's focused element, unless it is in `excluded` (the lock's own window).
    static func voiceOverFocus(excluding excluded: UIWindow?) -> AnyObject? {
        guard UIAccessibility.isVoiceOverRunning,
              let element = UIAccessibility.focusedElement(using: .notificationVoiceOver).map({ $0 as AnyObject }) else { return nil }
        if let excluded, hostView(of: element)?.window === excluded { return nil }
        return element
    }

    /// Whether an element recorded earlier is still in a visible window. Elements that are not
    /// views are traced through their accessibility containers to the view that holds them.
    static func isStillShown(_ element: AnyObject) -> Bool {
        guard let view = hostView(of: element) else { return true }
        guard let window = view.window else { return false }
        return !window.isHidden
    }

    private static let containerGetter = NSSelectorFromString("accessibilityContainer")

    private static func hostView(of element: AnyObject) -> UIView? {
        var current: AnyObject? = element
        for _ in 0..<50 {
            guard let node = current else { return nil }
            if let view = node as? UIView { return view }
            if let element = node as? UIAccessibilityElement {
                current = element.accessibilityContainer.map { $0 as AnyObject }
            } else if let object = node as? NSObject, object.responds(to: containerGetter) {
                current = object.perform(containerGetter)?.takeUnretainedValue()
            } else {
                return nil
            }
        }
        return nil
    }

    private func overlayWindow() -> UIWindow? {
        if let window {
            // UIWindow.windowScene is weak: a window whose scene was disconnected (or is no
            // longer connected) is never shown again, so a lock drawn into it would hide nothing.
            if let scene = window.windowScene, scene.activationState != .unattached,
               UIApplication.shared.connectedScenes.contains(scene) { return window }
            discardOverlayWindow()
        }
        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
        guard let scene = scenes.first(where: { $0.activationState != .background && $0.activationState != .unattached })
                ?? scenes.first else { return nil }
        let host = UIHostingController(rootView: LockOverlay(lock: self).tint(Color.brand))
        host.view.backgroundColor = .systemBackground
        let overlay = UIWindow(windowScene: scene)
        overlay.rootViewController = host
        overlay.windowLevel = UIWindow.Level(rawValue: UIWindow.Level.alert.rawValue + 1)
        overlay.accessibilityViewIsModal = true
        overlay.isHidden = true
        window = overlay
        return overlay
    }

    /// Drops an overlay window whose scene is gone. The windows it concealed went with that
    /// scene, so the lock is engaged afresh over the new scene's windows on the next refresh.
    private func discardOverlayWindow() {
        window?.isHidden = true
        window = nil
        lockEngaged = false
        concealedWindows = []
        previousKeyWindow = nil
    }
}

/// Sign-in after the server refused the session (API.reauthRequired), drawn in its own window
/// above the app and below the app lock, so every open screen and sheet, and anything typed in
/// them, stays underneath. While it is up the windows underneath are out of reach of VoiceOver
/// and the keyboard; when it goes, VoiceOver returns to where it was.
@MainActor
final class ReauthPresenter: ObservableObject {
    private var window: UIWindow?
    private weak var focusBefore: AnyObject?

    func show(_ api: API) {
        if window == nil {
            let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
            guard let scene = scenes.first(where: { $0.activationState == .foregroundActive })
                    ?? scenes.first(where: { $0.activationState != .unattached }) ?? scenes.first else { return }
            focusBefore = AppLock.voiceOverFocus(excluding: nil)
            let host = UIHostingController(rootView: LoginView(reauth: true).environmentObject(api).tint(Color.brand))
            host.view.backgroundColor = .systemBackground
            let sheet = UIWindow(windowScene: scene)
            sheet.rootViewController = host
            // Above the app's own sheets and alerts, below the app lock (alert + 1).
            sheet.windowLevel = UIWindow.Level(rawValue: UIWindow.Level.alert.rawValue + 0.5)
            sheet.accessibilityViewIsModal = true
            window = sheet
        }
        reassert()
        DispatchQueue.main.async { UIAccessibility.post(notification: .screenChanged, argument: nil) }
    }

    /// Conceals the windows underneath again (also after the app lock gave them back) and keeps
    /// the sign-in window in front.
    func reassert() {
        guard let window else { return }
        for other in windowsBelow() {
            other.endEditing(true)
            other.accessibilityElementsHidden = true
        }
        if window.isHidden || !window.isKeyWindow { window.makeKeyAndVisible() }
    }

    func hide() {
        guard let window else { return }
        let below = windowsBelow()
        for other in below { other.accessibilityElementsHidden = false }
        below.first(where: { !$0.isHidden })?.makeKey()
        window.isHidden = true
        self.window = nil      // the next refused session starts a fresh sign-in screen
        let target = focusBefore.flatMap { AppLock.isStillShown($0) ? $0 : nil }
        focusBefore = nil
        DispatchQueue.main.async { UIAccessibility.post(notification: .screenChanged, argument: target) }
    }

    /// The app's own windows (its screens, sheets and alerts are all presented in them). The
    /// keyboard's windows are left alone: the sign-in fields need the keyboard.
    private func windowsBelow() -> [UIWindow] {
        guard let window else { return [] }
        return window.windowScene?.windows.filter { $0 !== window && $0.windowLevel == .normal } ?? []
    }
}

/// What the overlay window shows: the lock screen, or a plain cover while the app is inactive.
struct LockOverlay: View {
    @ObservedObject var lock: AppLock

    var body: some View {
        ZStack {
            Color(.systemBackground).ignoresSafeArea()
            if lock.locked {
                LockScreen(hint: lock.unlockHint, authFailed: lock.authFailed) {
                    Task { await lock.authenticate() }
                }
            } else {
                Image(systemName: "lock.shield")
                    .font(.system(size: 56))
                    .foregroundStyle(Color.brand)
                    .accessibilityHidden(true)
            }
        }
    }
}

struct LockScreen: View {
    let hint: String
    let authFailed: Bool
    let retry: () -> Void

    var body: some View {
        VStack(spacing: 20) {
            Spacer()
            Image(systemName: "lock.shield")
                .font(.system(size: 56))
                .foregroundStyle(Color.brand)
                .accessibilityHidden(true)
            Text("Client records are locked")
                .font(.title2.bold())
                .accessibilityAddTraits(.isHeader)
            Text(hint)
                .foregroundStyle(Color.secondaryText)
            if authFailed {
                Text("Unlock didn't succeed. Try again.")
                    .font(.callout.weight(.medium))
            }
            // Always available, so a prompt that was dismissed or never appeared can be retried.
            Button("Unlock", action: retry)
                .buttonStyle(.borderedProminent)
                .controlSize(.large)
                .onBrandLabel()
            Spacer()
        }
        .multilineTextAlignment(.center)
        .padding()
    }
}

struct MainTabs: View {
    @EnvironmentObject var api: API
    @EnvironmentObject var lock: AppLock
    @State private var showingOfflineEntries = false
    /// A sync notice that changed while the app was locked: said once the lock is gone.
    @State private var unannouncedSync: String?

    var body: some View {
        // Tabs mirror the website's permission groups; the server enforces
        // access either way, this just hides what the user can't open.
        TabView {
            DashboardView()
                .tabItem { Label("Dashboard", systemImage: "gauge.medium") }
            if api.can("clients") {
                ClientsView()
                    .tabItem { Label("Clients", systemImage: "person.2") }
            }
            if api.can("referrals") {
                ReferralsView()
                    .tabItem { Label("Referrals", systemImage: "tray.and.arrow.down") }
            }
            if api.can("schedule") {
                ScheduleView()
                    .tabItem { Label("Schedule", systemImage: "calendar") }
            }
            if api.can("equipment") {
                EquipmentView()
                    .tabItem { Label("Equipment", systemImage: "shippingbox") }
            }
        }
        .task {
            await api.refreshMe()
            await api.flushQueue()
        }
        .onChange(of: scenePhase) { phase in
            // Coming back to the foreground retries anything written offline.
            if phase == .active { Task { await api.flushQueue() } }
        }
        .onChange(of: lock.locked) { locked in
            // The tabs are no longer rebuilt on every unlock (which used to re-run the task
            // above), so the name and permission set are refreshed here instead.
            if !locked {
                Task { await api.refreshMe() }
                if let message = unannouncedSync {
                    unannouncedSync = nil
                    // After VoiceOver has settled back on the screen (AppLock restores its place).
                    Task {
                        try? await Task.sleep(nanoseconds: 1_500_000_000)
                        if api.syncError == message, !api.syncNoticeDismissed { announce(message) }
                    }
                }
            }
        }
        // The sync notice is spoken when its text changes, never again just because the banner
        // was redrawn (returning to the app re-runs the upload); not over the lock screen.
        .onChange(of: api.syncError) { message in
            guard let message, !api.syncNoticeDismissed else { unannouncedSync = nil; return }
            if lock.locked { unannouncedSync = message } else { announce(message) }
        }
        .safeAreaInset(edge: .top) {
            // The person can dismiss the whole sync notice; a new offline save or sign-in re-enables it,
            // so a kept-but-failed entry can't trap a permanent, re-asserting banner for a VoiceOver user.
            if !api.syncNoticeDismissed {
                if let error = api.syncError {
                    VStack(alignment: .leading, spacing: 6) {
                        HStack(alignment: .top, spacing: 4) {
                            ErrorBanner(message: error, announces: false)
                            Button { api.syncNoticeDismissed = true } label: {
                                // 44 x 44 points to touch, the glyph centred in it.
                                Image(systemName: "xmark.circle.fill").font(.title3)
                                    .frame(minWidth: 44, minHeight: 44)
                                    .contentShape(Rectangle())
                            }
                            .accessibilityLabel("Dismiss offline sync notice")
                        }
                        Button { showingOfflineEntries = true } label: {
                            Text("Show offline entries").frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                        .controlSize(.large)
                        .accessibilityHint("Lists the entries kept on this phone, with what the server said, to retry, copy or discard.")
                    }
                    .padding(.horizontal, 8)
                    .padding(.bottom, 6)
                    .background(Color(.systemBackground))
                } else if api.pendingSync > 0 {
                    Button { showingOfflineEntries = true } label: {
                        Text("\(api.pendingSync) entr\(api.pendingSync == 1 ? "y" : "ies") saved offline — will sync automatically")
                            .font(.caption.weight(.semibold))
                            .frame(maxWidth: .infinity, minHeight: 44)
                            .padding(.horizontal, 6)
                            .background(Color.orange.opacity(0.9))
                            .foregroundStyle(.black)
                    }
                    .buttonStyle(.plain)
                    .accessibilityHint("Shows the entries waiting on this phone.")
                }
            }
        }
        .sheet(isPresented: $showingOfflineEntries) {
            NavigationStack { OfflineEntriesView(inSheet: true) }
        }
    }

    @Environment(\.scenePhase) private var scenePhase
}

// Shared helpers

/// Every date the app sends to, or reads from, the API goes through here. The locale and
/// calendar are fixed (en_US_POSIX, Gregorian) so a phone set to a Buddhist or Japanese
/// calendar, or to 12-hour time, cannot change what goes over the wire.
enum APIDate {
    /// The server stores and interprets date-times in America/Chicago.
    static let central = TimeZone(identifier: "America/Chicago") ?? .current

    private static func formatter(_ format: String, zone: TimeZone) -> DateFormatter {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.calendar = Calendar(identifier: .gregorian)
        f.timeZone = zone
        f.dateFormat = format
        return f
    }

    /// yyyy-MM-dd for the calendar day a date-only DatePicker shows (the device's time zone).
    static func day(_ date: Date) -> String { formatter("yyyy-MM-dd", zone: .current).string(from: date) }

    /// The date / time parts of an appointment, pinned to the server's zone so a phone in
    /// another time zone can't skew the stored appointment or billing time.
    static func dayCentral(_ date: Date) -> String { formatter("yyyy-MM-dd", zone: central).string(from: date) }
    static func timeCentral(_ date: Date) -> String { formatter("HH:mm", zone: central).string(from: date) }
    static func dateTimeCentral(_ date: Date) -> String { formatter("yyyy-MM-dd HH:mm:ss", zone: central).string(from: date) }

    /// Reads the leading yyyy-MM-dd of a server value as that calendar day on this device.
    /// (Noon, not midnight: some zones have no midnight on a clock-change day.)
    static func parseDay(_ raw: String) -> Date? {
        formatter("yyyy-MM-dd HH", zone: .current).date(from: String(raw.prefix(10)) + " 12")
    }

    /// As `parseDay`, but only for text that is exactly one real calendar day.
    static func strictDay(_ raw: String) -> Date? {
        guard raw.count == 10, let date = parseDay(raw), day(date) == raw else { return nil }
        return date
    }

    static func parseDateTimeCentral(_ raw: String) -> Date? {
        formatter("yyyy-MM-dd HH:mm:ss", zone: central).date(from: raw)
    }
}

func formatDateTime(_ raw: String) -> String {
    guard let date = APIDate.parseDateTimeCentral(raw) else { return raw }
    let output = DateFormatter()
    output.dateStyle = .medium
    output.timeStyle = .short
    return output.string(from: date)
}

func formatDate(_ raw: String?) -> String {
    guard let raw, !raw.isEmpty else { return "—" }
    guard let date = APIDate.parseDay(raw) else { return raw }
    let output = DateFormatter()
    output.dateStyle = .medium
    return output.string(from: date)
}

/// Money / number entry in the one shape the server accepts (api.php api_money): plain digits
/// with an optional one- or two-digit fraction ("12", "12.5", "12.50") — no thousands
/// separators, signs or exponents. A decimal pad set to a comma region (Germany, France…)
/// offers only a comma, so one comma with no period is read as the decimal mark ("12,50");
/// any other comma ("1,250") is refused. Returns the text to send, with a period ("" when the
/// field was left blank), or nil when what was typed is not in that shape — never a silent 0.
func checkedAmount(_ raw: String, maxWholeDigits: Int = 7) -> String? {
    var text = raw.trimmingCharacters(in: .whitespacesAndNewlines)
    if text.isEmpty { return "" }
    if !text.contains("."), text.filter({ $0 == "," }).count == 1 {
        text = text.replacingOccurrences(of: ",", with: ".")
    }
    let pattern = "^[0-9]{1,\(maxWholeDigits)}(\\.[0-9]{1,2})?$"
    return text.range(of: pattern, options: .regularExpression) != nil ? text : nil
}

/// The message for a value `checkedAmount` refused, naming the field and its limits. `money`:
/// a currency amount (cents); otherwise a plain number such as mileage.
func amountFormatMessage(_ field: String, money: Bool = true, maxWholeDigits: Int = 7) -> String {
    money
        ? "\(field): enter the amount in digits only, up to \(maxWholeDigits) before the decimal point and at most 2 after it (the cents), like 12.50. Use a period or comma for the cents; no thousands separators, signs or currency symbols."
        : "\(field): enter a number in digits only, up to \(maxWholeDigits) before the decimal point and at most 2 after it, like 12.5. Use a period or comma for the decimal point; no thousands separators, units or signs."
}

/// The label colour for a .borderedProminent button on the brand tint: on the dark
/// appearance's light-blue fill, white text would be about 2:1, so it is dark navy there
/// (Color.onBrand). A disabled button's label is the secondary text colour on the system's
/// grey fill. One view either way, so enabling the button never replaces it (or VoiceOver focus).
struct OnBrandLabel: ViewModifier {
    @Environment(\.isEnabled) private var isEnabled
    func body(content: Content) -> some View {
        content.foregroundStyle(isEnabled ? AnyShapeStyle(Color.onBrand) : AnyShapeStyle(HierarchicalShapeStyle.secondary))
    }
}

extension View {
    /// Apply before `.disabled(…)`, so a disabled button is left to the system's style.
    func onBrandLabel() -> some View { modifier(OnBrandLabel()) }
}

/// Post a VoiceOver announcement for a change that has no visible focus move
/// (e.g. a successful action that just refreshes a list). No-op when VoiceOver
/// isn't running.
@MainActor func announce(_ message: String) {
    UIAccessibility.post(notification: .announcement, argument: message)
}

/// For the result of a sheet, called once the sheet has gone (a sheet's onDismiss): closing
/// it moves VoiceOver focus, which cuts off an announcement posted while it was still open.
@MainActor func announceAfterDismiss(_ message: String) {
    Task { @MainActor in
        try? await Task.sleep(nanoseconds: 500_000_000)
        announce(message)
    }
}

/// Puts a validation message in a form's error banner. ErrorBanner announces itself when it
/// appears or its text changes; an identical repeat would otherwise be silent, so that case
/// is announced here.
@MainActor func showFormError(_ message: String, in error: inout String?) {
    if error == message { announce(message) }
    error = message
}

struct ErrorBanner: View {
    let message: String
    /// False where the owner announces changes itself (the offline-sync notice, which is
    /// redrawn on every return to the app and must not be read out each time).
    var announces = true
    var body: some View {
        Text(message)
            .font(.callout.weight(.medium))
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(12)
            // A solid dark red (#9B1C1C): white text on it is 8.2:1 in both appearances (7:1 is
            // needed for callout text; 85% system red was about 3.1:1 in light mode).
            .background(Color.alertFill, in: RoundedRectangle(cornerRadius: 10))
            .accessibilityLabel("Error: \(message)")
            .onAppear { if announces { UIAccessibility.post(notification: .announcement, argument: message) } }
            // A banner that is already on screen and gets a new message must be heard too.
            .onChange(of: message) { if announces { UIAccessibility.post(notification: .announcement, argument: $0) } }
    }
}

/// An optional calendar day held as the API's yyyy-MM-dd text ("" = none), edited with a
/// switch and a date picker instead of typed free text.
struct OptionalDayField: View {
    let title: String
    @Binding var text: String

    /// "Set due date" for "Due date"; an acronym at the start ("IPE date") keeps its capitals.
    private var switchTitle: String {
        let chars = Array(title)
        guard chars.count > 1, chars[1].isUppercase else { return "Set " + title.prefix(1).lowercased() + title.dropFirst() }
        return "Set " + title
    }

    var body: some View {
        let day = APIDate.strictDay(text)
        if text.isEmpty || day != nil {
            // Named for what it does, so it is not a second control called just "Due date".
            Toggle(switchTitle, isOn: Binding(get: { day != nil },
                                              set: { text = $0 ? APIDate.day(Date()) : "" }))
                .accessibilityHint(day != nil ? "Turning this off clears the date." : "Turning this on sets the date to today, then you can change it.")
            if let day {
                DatePicker(title, selection: Binding(get: { day }, set: { text = APIDate.day($0) }),
                           displayedComponents: .date)
            }
        } else {
            // A stored value that is not a calendar day: keep it editable rather than dropping it.
            TextField(title, text: $text)
                .autocorrectionDisabled()
                .textInputAutocapitalization(.never)
                .accessibilityLabel("\(title), year, month, day")
        }
    }
}

/// Accessible authorization progress bar: spoken as hours used / remaining.
struct AuthProgressBar: View {
    let hoursWorked: Double
    let authorizedHours: Double
    let hoursRemaining: Double
    let pctUsed: Double

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ProgressView(value: min(pctUsed, 100), total: 100)
            Text(String(format: "%.2f of %.2f hours used · %.2f remaining",
                        hoursWorked, authorizedHours, hoursRemaining))
                .font(.caption)
                .foregroundStyle(Color.secondaryText)
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Authorization usage")
        .accessibilityValue(String(format: "%.0f percent used. %.2f of %.2f hours used, %.2f remaining.",
                                   pctUsed, hoursWorked, authorizedHours, hoursRemaining))
    }
}
