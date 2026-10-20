import SwiftUI
import LocalAuthentication
import SafariServices
import WidgetKit

@main
struct CaseManagementApp: App {
    @StateObject private var api = API()
    @StateObject private var lock = AppLock()

    @StateObject private var navigation = AppNavigation.shared

    init() {
        // A document left in tmp by a crash or a kill during a preview must not outlive the launch.
        PreviewStore.sweep()
        // An unsaved note or hours draft not touched for two weeks is dropped (PHI).
        DraftStore.expire()
        // A tapped reminder is handed to AppNavigation, also when the tap launched the app.
        LocalNotifications.shared.activate()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                // The secondary level of text (a LabeledContent's value, and anything else in
                // .secondary) in Color.secondaryText, 7:1, instead of the system's grey, about 4:1
                // (4.26.0 accessibility audit). A colour only: controls keep their own layout.
                .foregroundStyle(.primary, Color.secondaryText)
                // Section headings in the primary colour: the system's grey ones were under
                // 4.5:1 on the grouped background (4.26.0 accessibility audit).
                .headerProminence(.increased)
                .environmentObject(api)
                .environmentObject(lock)
                .environmentObject(navigation)
                .tint(.brand)
        }
    }
}

/// Where the app should go next, asked for from outside the screens: a tapped reminder
/// (LocalNotifications) or the voice assistant's "open …". The tab bar follows `tab`; the
/// Dashboard's stack opens `dashboardRoute`. A request made while the app is locked, or before
/// the tabs exist, is kept until MainTabs can act on it (and checks the permission for it).
@MainActor
final class AppNavigation: ObservableObject {
    static let shared = AppNavigation()

    enum Tab: Hashable { case dashboard, clients, referrals, schedule, equipment }

    /// A screen under the Dashboard's More tools.
    enum DashboardRoute: Hashable {
        case followups, messages, tasks, docReview
        @MainActor @ViewBuilder var screen: some View {
            switch self {
            case .followups: FollowupsView()
            case .messages: MessagesView()
            case .tasks: TasksView()
            case .docReview: DocReviewView()
            }
        }
    }

    /// A sheet a Siri shortcut (AppIntents.swift) asks the Dashboard to open.
    enum QuickAction: Hashable { case logHours, voice }

    /// Somewhere the app can go by itself.
    enum Destination: Hashable {
        case tab(Tab)
        case dashboard(DashboardRoute)
        case quick(QuickAction)
        static let schedule = Destination.tab(.schedule)
        static let followups = Destination.dashboard(.followups)
    }

    @Published var tab: Tab = .dashboard
    @Published var dashboardRoute: DashboardRoute?
    /// A sheet the Dashboard should present now (it sets this back to nil once it has).
    @Published var quickAction: QuickAction?
    /// Waiting for MainTabs (see `apply`).
    @Published private(set) var pending: Destination?

    func request(_ destination: Destination) { pending = destination }

    /// MainTabs: goes where was asked, if the account may open it; otherwise the request is dropped.
    func apply(_ api: API) {
        guard let destination = pending else { return }
        pending = nil
        switch destination {
        case .tab(let target):
            let allowed: Bool
            switch target {
            case .dashboard: allowed = true
            case .clients: allowed = api.can("clients")
            case .referrals: allowed = api.can("referrals")
            case .schedule: allowed = api.can("schedule")
            case .equipment: allowed = api.can("equipment")
            }
            if allowed { tab = target }
        case .dashboard(let route):
            guard api.can("clients") else { return }
            tab = .dashboard
            dashboardRoute = route
        case .quick(let action):
            // The same permissions as the Dashboard's own buttons for these.
            switch action {
            case .logHours: guard api.can("clients") else { return }
            case .voice: guard api.aiHas("voice"), api.can("clients") else { return }
            }
            tab = .dashboard
            dashboardRoute = nil
            quickAction = action
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
        .onAppear {
            lock.start(signedIn: api.isSignedIn)
            // A scene iOS connected afresh (after disconnecting the old one in the background)
            // has a new presenter with no window: a sign-in that was already being asked for is
            // shown again here, since onChange below does not fire for a value already set.
            if api.reauthRequired { reauth.show(api) }
        }
        .onChange(of: api.isSignedIn) { signedIn in
            lock.signedInChanged(signedIn)
            // Signed out: the home-screen widget's day summary goes with the account.
            if !signedIn { TodaySnapshot.clear(); WidgetCenter.shared.reloadTimelines(ofKind: TodaySnapshot.widgetKind) }
        }
        // Website pages opened inside the app (Action center) keep their own cookies: they go
        // whenever the account changes or signs out, so the next person is not signed in to the
        // website as the last one.
        .onChange(of: api.userId) { _ in
            SFSafariViewController.DataStore.default.clearWebsiteData()
            // Another account's day is not this one's: the widget waits for this account's schedule.
            TodaySnapshot.clear()
            WidgetCenter.shared.reloadTimelines(ofKind: TodaySnapshot.widgetKind)
        }
        // An expired session asks for sign-in above everything that is open, instead of
        // replacing it, so nothing typed is lost (see API.expireSession).
        .onChange(of: api.reauthRequired) { needed in
            if needed { reauth.show(api) } else { reauth.hide() }
        }
        .onChange(of: lock.locked) { locked in
            // Unlocking gives the windows underneath back to VoiceOver; sign-in keeps them hidden.
            if !locked { reauth.reassert() }
        }
        // VoiceOver started or stopped: every screen observes the API, so this redraws their
        // rows (VoiceOverRows.children) for the one now running, without rebuilding them.
        .onReceive(NotificationCenter.default.publisher(for: UIAccessibility.voiceOverStatusDidChangeNotification)) { _ in
            api.objectWillChange.send()
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
    /// Why Face ID or the passcode cannot be checked right now (shown on the lock screen, which
    /// stays up); nil when they can.
    @Published private(set) var unavailableReason: String?
    /// One-time notice shown when the device has nothing to lock the app with.
    @Published var protectionWarning: String?
    /// The phone is in a signer's hands (4.28.0: a signature sheet or in-person signing is up,
    /// or closed behind the lock): nothing naming other entries is announced and no offline
    /// upload starts until the device owner unlocks.
    @Published private(set) var handedOver = false

    /// Called when a screen the signer uses opens.
    func handOver() { handedOver = true }
    /// Called only when that screen has gone (it closes behind the lock). An unlock while it is
    /// still open does not end the hand-over.
    func endHandOver() { handedOver = false }

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
            unavailableReason = nil
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
            guard error?.code == LAError.Code.passcodeNotSet.rawValue else {
                // Face ID and the passcode can't be checked just now for another reason (too
                // many attempts, or Stolen Device Protection withholding the passcode away from
                // a familiar place): the records stay locked, the lock screen says why, and
                // Unlock tries again.
                let reason = "This phone can't check Face ID or your passcode right now, so your client records stay locked. Unlock the phone itself with its passcode, or wait a while, then choose Unlock."
                authFailed = true
                unavailableReason = reason
                Task {
                    try? await Task.sleep(nanoseconds: 600_000_000)
                    if self.locked { announce(reason) }
                }
                return
            }
            // The device has no passcode, so there is nothing to lock with. Don't lock the user
            // out of their own app, but say so (once) instead of silently unlocking.
            warnDeviceCannotLock(error)
            markUnlocked()
            return
        }
        unavailableReason = nil
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
        unavailableReason = nil
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
            protectionWarning = "This phone could not check Face ID or a passcode just now. When the app next locks, it stays locked until the phone can check them again. Check your passcode settings in Settings."
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
                LockScreen(hint: lock.unlockHint, authFailed: lock.authFailed,
                           unavailableReason: lock.unavailableReason) {
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
    var unavailableReason: String? = nil
    let retry: () -> Void

    /// Scrolls (4.28.0): at the largest text sizes, and in landscape, the words are taller than
    /// the screen; nothing is cut off and the Unlock button can always be reached. With room to
    /// spare it is centred as before.
    var body: some View {
        GeometryReader { geometry in
            ScrollView {
                content
                    .frame(maxWidth: .infinity, minHeight: geometry.size.height)
            }
        }
    }

    private var content: some View {
        VStack(spacing: 20) {
            Spacer(minLength: 0)
            Image(systemName: "lock.shield")
                .font(.system(size: 56))
                .foregroundStyle(Color.brand)
                .accessibilityHidden(true)
            Text("Client records are locked")
                .font(.title2.bold())
                .accessibilityAddTraits(.isHeader)
            Text(hint)
                .foregroundStyle(Color.secondaryText)
            if let unavailableReason {
                Text(unavailableReason)
                    .font(.callout.weight(.medium))
            } else if authFailed {
                Text("Unlock didn't succeed. Try again.")
                    .font(.callout.weight(.medium))
            }
            // Always available, so a prompt that was dismissed or never appeared can be retried.
            Button("Unlock", action: retry)
                .buttonStyle(.borderedProminent)
                .controlSize(.large)
                .onBrandLabel()
            Spacer(minLength: 0)
        }
        .fixedSize(horizontal: false, vertical: true)
        .multilineTextAlignment(.center)
        .padding()
    }
}

struct MainTabs: View {
    @EnvironmentObject var api: API
    @EnvironmentObject var lock: AppLock
    @EnvironmentObject var navigation: AppNavigation
    @State private var showingOfflineEntries = false
    /// A sync notice that changed while the app was locked: said once the lock is gone.
    @State private var unannouncedSync: String?

    var body: some View {
        // Tabs mirror the website's permission groups; the server enforces
        // access either way, this just hides what the user can't open.
        TabView(selection: $navigation.tab) {
            DashboardView()
                .tabItem { Label("Dashboard", systemImage: "gauge.medium") }
                .tag(AppNavigation.Tab.dashboard)
            if api.can("clients") {
                ClientsView()
                    .tabItem { Label("Clients", systemImage: "person.2") }
                    .tag(AppNavigation.Tab.clients)
            }
            if api.can("referrals") {
                ReferralsView()
                    .tabItem { Label("Referrals", systemImage: "tray.and.arrow.down") }
                    .tag(AppNavigation.Tab.referrals)
            }
            if api.can("schedule") {
                ScheduleView()
                    .tabItem { Label("Schedule", systemImage: "calendar") }
                    .tag(AppNavigation.Tab.schedule)
            }
            if api.can("equipment") {
                EquipmentView()
                    .tabItem { Label("Equipment", systemImage: "shippingbox") }
                    .tag(AppNavigation.Tab.equipment)
            }
        }
        .task {
            await api.refreshMe()
            await api.flushQueue()
            // A reminder tapped to launch the app: go there now that the permissions are known.
            if !lock.locked { navigation.apply(api) }
        }
        .onChange(of: scenePhase) { phase in
            // Coming back to the foreground retries anything written offline (not while the
            // phone is in a signer's hands: it uploads once the owner unlocks).
            if phase == .active, !lock.handedOver { Task { await api.flushQueue() } }
        }
        // The connection coming back (4.26.0) sends what was kept with no signal, such as a
        // document signed in a client's home, without waiting for the app to be reopened.
        .onReceive(ConnectionWatcher.shared.$reconnects.dropFirst()) { _ in
            if scenePhase == .active, !lock.handedOver { Task { await api.flushQueue() } }
        }
        // A tapped reminder or a voice "open …" while the app is unlocked; while locked it
        // waits for the unlock below.
        .onChange(of: navigation.pending) { pending in
            if pending != nil, !lock.locked { navigation.apply(api) }
        }
        .onChange(of: lock.locked) { locked in
            // The tabs are no longer rebuilt on every unlock (which used to re-run the task
            // above), so the name and permission set are refreshed here instead.
            if !locked {
                // Anything that waited while the phone was handed over uploads now (not while a
                // signing screen is still open in the signer's hands).
                let handedOver = lock.handedOver
                Task {
                    await api.refreshMe()
                    if !handedOver { await api.flushQueue() }
                }
                navigation.apply(api)
                if !lock.handedOver, let message = unannouncedSync {
                    unannouncedSync = nil
                    // After VoiceOver has settled back on the screen (AppLock restores its place).
                    Task {
                        try? await Task.sleep(nanoseconds: 1_500_000_000)
                        if api.syncError == message, !api.syncNoticeDismissed { announce(message) }
                    }
                }
            }
        }
        // The signing screen has gone (4.28.0): what waited uploads, and a notice is said, once
        // the app is also unlocked.
        .onChange(of: lock.handedOver) { handedOver in
            guard !handedOver, !lock.locked else { return }
            Task { await api.flushQueue() }
            if let message = unannouncedSync {
                unannouncedSync = nil
                Task {
                    try? await Task.sleep(nanoseconds: 1_500_000_000)
                    if api.syncError == message, !api.syncNoticeDismissed { announce(message) }
                }
            }
        }
        // The sync notice is spoken when its text changes, never again just because the banner
        // was redrawn (returning to the app re-runs the upload); not over the lock screen.
        .onChange(of: api.syncError) { message in
            guard let message, !api.syncNoticeDismissed else { unannouncedSync = nil; return }
            if lock.locked || lock.handedOver { unannouncedSync = message } else { announce(message) }
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
    /// The zone the server stores and interprets date-times in: the one its `me` reply reported
    /// (kept by API.refreshMe), or America/Chicago, the server's default, until a server reports one.
    static var central: TimeZone {
        UserDefaults.standard.string(forKey: API.serverTimeZoneKey).flatMap(TimeZone.init(identifier:))
            ?? TimeZone(identifier: "America/Chicago") ?? .current
    }

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

/// Text whose parts carry a language (AttributedString.languageIdentifier) as VoiceOver speech:
/// each part is read with its own language's voice (4.28.0: Spanish in-person signing).
func speechString(_ text: AttributedString) -> NSAttributedString {
    let out = NSMutableAttributedString()
    for run in text.runs {
        var attributes: [NSAttributedString.Key: Any] = [:]
        if let lang = run.languageIdentifier { attributes[.accessibilitySpeechLanguage] = lang }
        out.append(NSAttributedString(string: String(text[run.range].characters), attributes: attributes))
    }
    return out
}

/// An announcement read in the language its parts are marked with.
@MainActor func announce(_ message: AttributedString) {
    UIAccessibility.post(notification: .announcement, argument: speechString(message))
}

/// For the result of a sheet, called once the sheet has gone (a sheet's onDismiss): closing
/// it moves VoiceOver focus, which cuts off an announcement posted while it was still open.
@MainActor func announceAfterDismiss(_ message: String) {
    Task { @MainActor in
        try? await Task.sleep(nanoseconds: 500_000_000)
        announce(message)
    }
}

/// The message showFormError has just announced (or a screen has just put VoiceOver on), and
/// when: the banner that then shows it does not say it a second time.
@MainActor private var formErrorAnnounced: (message: String, at: Date)?
/// Counts showFormError calls; formErrorClaimed is the last one whose message a screen is
/// reading out itself by moving VoiceOver onto its banner or field (claimFormErrorFocus).
@MainActor private var formErrorSerial = 0
@MainActor private var formErrorClaimed = 0

/// Puts an error in a screen's error banner and announces it, every time (an identical repeat
/// too), unless the screen moves VoiceOver onto the banner or the field the error is about
/// (ErrorFocusForm and the list screens with an error row call claimFormErrorFocus as the error
/// arrives): that focus move reads the message, and an announcement as well said it twice
/// (4.30.0). The announcement waits only a moment for that claim, not for the banner: a banner
/// that is a List or Form row is never drawn while the person is scrolled away from it, so its
/// own announcement would never come.
@MainActor func showFormError(_ message: String, in error: inout String?, field: String? = nil) {
    formErrorField = field.map { (message, $0) }
    formErrorSerial += 1
    let serial = formErrorSerial
    formErrorAnnounced = (message, Date())
    error = message
    Task { @MainActor in
        try? await Task.sleep(nanoseconds: 250_000_000)
        guard formErrorClaimed != serial else { return }
        announce(message)
    }
}

/// showFormError for a message marked with its language (shown with ErrorBanner's `spoken`).
@MainActor func showFormError(_ message: AttributedString, in error: inout AttributedString?) {
    formErrorSerial += 1
    let serial = formErrorSerial
    formErrorAnnounced = (String(message.characters), Date())
    error = message
    Task { @MainActor in
        try? await Task.sleep(nanoseconds: 250_000_000)
        guard formErrorClaimed != serial else { return }
        announce(message)
    }
}

/// The field an error is about when its message does not start with the field's name (a
/// custom field whose name is long, say): read by ErrorFocusing (formErrorField(for:)).
@MainActor private var formErrorField: (message: String, field: String)?

/// The field showFormError was told `message` is about, if it was.
@MainActor func formErrorField(for message: String) -> String? {
    guard let named = formErrorField, named.message == message else { return nil }
    return named.field
}

/// Called by a screen as an error arrives when it is about to move VoiceOver onto the error's
/// banner or field: the focus move reads the message, so neither showFormError nor the banner
/// announces it as well.
///
/// If VoiceOver is not on the banner or field a moment later (a sheet covers the screen, or the
/// banner could not be drawn), the message is announced after all, so an error is never silent.
@MainActor func claimFormErrorFocus(_ message: String) {
    formErrorClaimed = formErrorSerial
    formErrorAnnounced = (message, Date())
    guard UIAccessibility.isVoiceOverRunning else { return }
    Task { @MainActor in
        // After the screens' focus moves (400 ms, or 700 ms when ErrorFocusForm falls back
        // from a field to its banner) and VoiceOver has settled on the element.
        try? await Task.sleep(nanoseconds: 1_300_000_000)
        if !voiceOverIsOnError(message) { announce(message) }
    }
}

/// Whether VoiceOver's focused element reads `message`: the banner ("Error: <message>") or the
/// field it is about (its label ends "Error: <the message after the field's name>").
@MainActor private func voiceOverIsOnError(_ message: String) -> Bool {
    guard let element = UIAccessibility.focusedElement(using: .notificationVoiceOver) as? NSObject else { return false }
    let label = (element.accessibilityLabel ?? "").lowercased()
    let text = message.lowercased()
    if label.contains(text) { return true }
    if let colon = text.range(of: ": ") {
        let detail = text[colon.upperBound...].trimmingCharacters(in: .whitespaces)
        if !detail.isEmpty, label.contains(detail) { return true }
    }
    return false
}

struct ErrorBanner: View {
    let message: String
    /// False where the owner announces changes itself (the offline-sync notice, which is
    /// redrawn on every return to the app and must not be read out each time).
    var announces = true
    /// The message marked with its language (4.28.0), read with that language's voice; nil:
    /// the phone's own language.
    var spoken: AttributedString? = nil

    /// A banner set directly (not through showFormError) announces itself when it appears or its
    /// text changes; one showFormError has just announced is not said twice.
    @MainActor private func announceIfNeeded(_ text: String) {
        guard announces else { return }
        if let said = formErrorAnnounced, said.message == text, Date().timeIntervalSince(said.at) < 2 { return }
        if let spoken, String(spoken.characters) == text { announce(spoken); return }
        UIAccessibility.post(notification: .announcement, argument: text)
    }

    /// "Error: " and the message, the prefix in the message's own language.
    private var label: Text {
        guard let spoken else { return Text("Error: \(message)") }
        var prefix = AttributedString("Error: ")
        prefix.languageIdentifier = spoken.runs.first?.languageIdentifier
        return Text(prefix + spoken)
    }

    var body: some View {
        Group { if let spoken { Text(spoken) } else { Text(message) } }
            .font(.callout.weight(.medium))
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(12)
            // A solid dark red (#9B1C1C): white text on it is 8.2:1 in both appearances (7:1 is
            // needed for callout text; 85% system red was about 3.1:1 in light mode).
            .background(Color.alertFill, in: RoundedRectangle(cornerRadius: 10))
            .accessibilityLabel(label)
            .onAppear { announceIfNeeded(message) }
            // A banner that is already on screen and gets a new message must be heard too.
            .onChange(of: message) { announceIfNeeded($0) }
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
