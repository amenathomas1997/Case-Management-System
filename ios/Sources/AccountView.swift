import SwiftUI
import SafariServices
import LocalAuthentication

// MARK: - Server replies (inc/api_account.php)

private struct AccountSummary: Decodable {
    let name: String
    let email: String
    let role: String
    let group: String?
    let totpEnabled: Bool
    /// Whether a calendar address exists; the address itself is shown only after the password.
    let hasCalFeed: Bool
    let canSchedule: Bool
    let passkeys: [AccountPasskey]
    let appSessions: Int
}

private struct AccountPasskey: Decodable, Identifiable {
    let id: Int
    let name: String
    let created: String
    let lastUsed: String?

    var displayName: String { name.isEmpty ? "Unnamed passkey" : name }
}

/// "added Oct 3, 2026, last used Oct 9, 2026", as on the website. Kept outside the
/// Decodable model so the model compiles on its own (tests/ios_models.py).
private func passkeyDates(_ p: AccountPasskey) -> String {
    "added " + formatDate(String(p.created.prefix(10)))
        + (p.lastUsed.map { ", last used " + formatDate(String($0.prefix(10))) } ?? "")
}

private struct TotpSetup: Decodable {
    let secret: String
    let otpauthUri: String
    let accountName: String
    let expiresMinutes: Int
}

private struct AccountMessage: Decodable { let message: String }
private struct CalFeedReply: Decodable { let address: String?; let message: String }

/// A page of the website, relative to the server the app signs in to.
private func siteURL(_ path: String) -> URL { URL(string: path, relativeTo: API.base)!.absoluteURL }

/// A website page shown inside the app, not in Safari: it stays under the app lock and the
/// app-switcher cover and is not kept in Safari's history (as DashboardView's record links).
private struct AccountWebPage: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}

private struct AccountWebView: UIViewControllerRepresentable {
    let url: URL
    let onDone: @MainActor () -> Void

    func makeUIViewController(context: Context) -> SFSafariViewController {
        let page = SFSafariViewController(url: url)
        page.delegate = context.coordinator
        return page
    }

    func updateUIViewController(_ page: SFSafariViewController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(onDone: onDone) }

    final class Coordinator: NSObject, SFSafariViewControllerDelegate {
        let onDone: @MainActor () -> Void
        init(onDone: @escaping @MainActor () -> Void) { self.onDone = onDone }
        /// Its Done button: the sheet that holds it closes too.
        func safariViewControllerDidFinish(_ controller: SFSafariViewController) {
            MainActor.assumeIsolated { onDone() }
        }
    }
}

/// CaptionedField for a password: a visible caption above the field, which carries the same
/// name for VoiceOver and Voice Control (so the caption is hidden from them).
private struct SecureCaptionedField: View {
    let label: String
    @Binding var text: String
    var content: UITextContentType = .password

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
                .accessibilityHidden(true)
            SecureField(label, text: $text, prompt: Text(label))
                .textContentType(content)
        }
    }
}

/// A section heading the Headings rotor finds.
private func sectionHeader(_ title: String) -> some View {
    Text(title).font(.headline).foregroundStyle(.primary).textCase(nil)
        .accessibilityAddTraits(.isHeader)
}

/// Says `message` after whatever VoiceOver is reading now (a focus move), instead of cutting it off.
@MainActor private func sayQueued(_ message: String) {
    UIAccessibility.post(notification: .announcement,
                         argument: NSAttributedString(string: message, attributes: [.accessibilitySpeechQueueAnnouncement: true]))
}

// MARK: - Account settings

/// The signed-in person's own settings, as My Account on the website: password, two-step
/// sign-in, passkeys and the calendar address, plus this iPhone's settings, signing out, help
/// and privacy. Every change re-checks the account password on the server.
struct AccountView: View {
    @EnvironmentObject var api: API
    @EnvironmentObject var lock: AppLock
    @Environment(\.openURL) private var openURL
    @AppStorage(SpeechController.pauseKey) private var pauseSeconds: Double = 1.8
    @AppStorage(SpeechController.viaVoiceOverKey) private var repliesViaVoiceOver = true
    @AppStorage(SpeechController.autoListenKey) private var voiceAutoListen = true
    @AppStorage(LocalNotifications.enabledKey) private var notificationsOn = false
    /// iOS refused reminders (turned off in Settings): shown under the toggle.
    @State private var notificationsRefused = false

    @State private var summary: AccountSummary?
    @State private var error: String?
    @State private var busy = false
    @State private var webPage: AccountWebPage?
    @State private var changingPassword = false
    // Two-step sign-in
    @State private var setup: TotpSetup?
    @State private var totpPassword = ""
    @State private var totpCode = ""
    @State private var totpOffPassword = ""
    @AccessibilityFocusState private var setupKeyFocused: Bool
    // Passkeys
    @State private var removing: AccountPasskey?
    // Calendar
    @State private var calPassword = ""
    @State private var calAddress: String?
    @State private var confirmingReplace = false
    @AccessibilityFocusState private var calAddressFocused: Bool
    // Signing out
    @State private var confirmingSignOut = false
    @State private var everywherePassword = ""
    @State private var confirmingEverywhere = false

    init() {}

    /// The code's digits only, so a pasted "123 456" still counts as six.
    private var codeDigits: String { totpCode.filter { $0.isASCII && $0.isNumber } }

    var body: some View {
        ErrorFocusForm(error: error) {
            if let summary {
                accountSection(summary)
                passwordSection(summary)
                twoStepSection(summary)
                passkeySection(summary)
                if summary.canSchedule { calendarSection(summary) }
            } else if error == nil {
                ProgressView("Loading your account…")
            }
            deviceSection
            notificationsSection
            signOutSection
            helpSection
        }
        .navigationTitle("Account settings")
        .task { await load() }
        .refreshable { await load() }
        .sheet(item: $webPage) { page in
            AccountWebView(url: page.url) { webPage = nil }.ignoresSafeArea()
        }
        .sheet(isPresented: $changingPassword) {
            ChangePasswordSheet(passkeyCount: summary?.passkeys.count ?? 0, hasCalFeed: summary?.hasCalFeed ?? false) { message in
                passwordChanged(message)
            }
        }
        .confirmationDialog("Remove this passkey?",
                            isPresented: Binding(get: { removing != nil }, set: { if !$0 { removing = nil } }),
                            titleVisibility: .visible, presenting: removing) { passkey in
            Button("Remove “\(passkey.displayName)”", role: .destructive) { Task { await remove(passkey) } }
            Button("Keep it", role: .cancel) {}
        } message: { _ in
            Text("You can still sign in with your password and code. Remove it from your device's saved passwords too.")
        }
        .confirmationDialog("Replace the calendar address?", isPresented: $confirmingReplace, titleVisibility: .visible) {
            Button("Replace the address", role: .destructive) { Task { await calRegen() } }
            Button("Keep the current address", role: .cancel) {}
        } message: {
            Text("The old address stops working everywhere it was added. Subscribe your calendar to the new one.")
        }
        .confirmationDialog(api.pendingSync > 0
                                ? "Sign out? \(api.pendingSync) offline entr\(api.pendingSync == 1 ? "y is" : "ies are") still waiting to sync and will stay on this phone."
                                : "Sign out of the app on this iPhone?", isPresented: $confirmingSignOut, titleVisibility: .visible) {
            Button("Sign out", role: .destructive) { api.signOut() }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Anything you have not saved is discarded. Entries already saved offline stay on this phone.")
        }
        .confirmationDialog("Sign out of the app on every device?", isPresented: $confirmingEverywhere, titleVisibility: .visible) {
            Button("Sign out everywhere", role: .destructive) { Task { await logoutAll() } }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Every device signed in to the app, this iPhone too, has to sign in again. Anything you have not saved here is discarded. Website sessions are not affected.")
        }
    }

    // MARK: Sections

    private func accountSection(_ s: AccountSummary) -> some View {
        Section {
            LabeledContent("Name", value: s.name)
            LabeledContent("Email", value: s.email)
            LabeledContent("Role", value: roleText(s))
        } header: { sectionHeader("Your account") }
    }

    private func roleText(_ s: AccountSummary) -> String {
        let role = s.role == "admin" ? "Administrator" : "Staff"
        return s.group.map { "\(role), permission group \($0)" } ?? role
    }

    private func passwordSection(_ s: AccountSummary) -> some View {
        Section {
            Button { changingPassword = true } label: {
                Label("Change password", systemImage: "key")
                    .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
            }
            .disabled(busy)
            .accessibilityHint(passwordEffects(passkeys: s.passkeys.count, calFeed: s.hasCalFeed))
        } header: { sectionHeader("Password") } footer: {
            Text(passwordEffects(passkeys: s.passkeys.count, calFeed: s.hasCalFeed)).foregroundStyle(Color.secondaryText)
        }
    }

    @ViewBuilder private func twoStepSection(_ s: AccountSummary) -> some View {
        Section {
            LabeledContent("Authenticator app", value: s.totpEnabled ? "On" : (setup != nil ? "Being set up" : "Off"))
            if s.totpEnabled {
                Text("At sign-in you can enter a code from your authenticator app or the emailed code. Both work.")
                SecureCaptionedField(label: "Account password, to turn it off", text: $totpOffPassword)
                Button(role: .destructive) { Task { await totpDisable() } } label: {
                    Text("Turn off authenticator app").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy || totpOffPassword.isEmpty)
                .accessibilityHint("Sign-in codes then arrive by email only.")
            } else if let setup {
                Text("In your authenticator app (Microsoft Authenticator, Google Authenticator, 1Password and others), choose Add account, then Enter a setup key, and enter the key below. Or choose Open in authenticator app.")
                LabeledContent("Account name", value: setup.accountName)
                setupKeyRow(setup)
                if let url = URL(string: setup.otpauthUri) {
                    Button { openAuthenticator(url) } label: {
                        Label("Open in authenticator app", systemImage: "arrow.up.forward.app")
                            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    }
                    .accessibilityRemoveTraits(.isButton)
                    .accessibilityAddTraits(.isLink)
                    .accessibilityHint("Adds this account to an authenticator app on this iPhone. Then come back here to enter its code.")
                }
                // The password first and the timed code last, so the code is entered just before
                // confirming, inside its 30-second window (as on the website).
                SecureCaptionedField(label: "Account password, to confirm", text: $totpPassword)
                CaptionedField(label: "6-digit code from the app", text: $totpCode, keyboard: .numberPad)
                    .textContentType(.oneTimeCode)
                Text("Codes change every 30 seconds: enter the code the app shows right before you choose Confirm and enable. This setup key works for \(setup.expiresMinutes) minutes.")
                    .font(.callout).foregroundStyle(Color.secondaryText)
                Button { Task { await totpConfirm() } } label: {
                    Text("Confirm and enable").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy || totpPassword.isEmpty || codeDigits.count != 6)
                Button { cancelSetup() } label: {
                    Text("Cancel setup").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy)
            } else {
                Text("Add an authenticator app as a second way to get sign-in codes. Email codes keep working either way.")
                Button { Task { await totpBegin() } } label: {
                    Text("Set up authenticator app").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy)
            }
        } header: { sectionHeader("Two-step sign-in") }
    }

    /// The setup key in groups of four, as one VoiceOver stop that spells it out; Copy is in the
    /// Actions rotor (and on screen).
    private func setupKeyRow(_ setup: TotpSetup) -> some View {
        let grouped = stride(from: 0, to: setup.secret.count, by: 4).map { start -> String in
            let from = setup.secret.index(setup.secret.startIndex, offsetBy: start)
            let to = setup.secret.index(from, offsetBy: min(4, setup.secret.count - start))
            return String(setup.secret[from..<to])
        }.joined(separator: " ")
        return VStack(alignment: .leading, spacing: 6) {
            Text("Setup key (spaces don't matter)").font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
            Text(grouped).font(.body.monospaced())
            Button { copySetupKey(setup.secret) } label: {
                Label("Copy setup key", systemImage: "doc.on.doc")
            }
            .buttonStyle(.bordered)
            .controlSize(.large)
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: VoiceOverRows.children)
        .accessibilityLabel("Setup key")
        .accessibilityValue(Text(setup.secret).speechSpellsOutCharacters())
        .accessibilityHint("Copy setup key is in the Actions rotor.")
        .accessibilityAction { announceRowActions() }
        .accessibilityActions {
            Button("Copy setup key") { copySetupKey(setup.secret) }
        }
        .accessibilityFocused($setupKeyFocused)
    }

    private func passkeySection(_ s: AccountSummary) -> some View {
        Section {
            if s.passkeys.isEmpty {
                Text("No passkeys on this account.")
            }
            ForEach(s.passkeys) { passkey in
                HStack(spacing: 12) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(passkey.displayName).font(.body.weight(.semibold))
                        Text(passkeyDates(passkey).prefix(1).uppercased() + passkeyDates(passkey).dropFirst())
                            .font(.caption).foregroundStyle(Color.secondaryText)
                    }
                    Spacer()
                    Button(role: .destructive) { removing = passkey } label: { Text("Remove") }
                        .buttonStyle(.bordered)
                        .controlSize(.large)
                        .disabled(busy)
                }
                // One VoiceOver stop per passkey; Remove is in the Actions rotor (it still asks first).
                .accessibilityElement(children: VoiceOverRows.children)
                .accessibilityLabel("\(passkey.displayName), \(passkeyDates(passkey))")
                .accessibilityActions {
                    if !busy { Button("Remove") { removing = passkey } }
                }
            }
            webLink("Add a passkey on the website", path: "account.php#passkeys", icon: "person.badge.key",
                    hint: "Opens My Account on the website inside the app, where you may need to sign in. Your device creates the passkey there.")
        } header: { sectionHeader("Passkeys") } footer: {
            Text("Sign in with Face ID, Touch ID or your password manager instead of a password and code.")
                .foregroundStyle(Color.secondaryText)
        }
    }

    private func calendarSection(_ s: AccountSummary) -> some View {
        Section {
            LabeledContent("Calendar address", value: s.hasCalFeed ? "Created" : "Not created yet")
            if let calAddress {
                VStack(alignment: .leading, spacing: 6) {
                    Text(calAddress).font(.callout.monospaced())
                    Button { copyCalAddress(calAddress) } label: {
                        Label("Copy address", systemImage: "doc.on.doc")
                    }
                    .buttonStyle(.bordered)
                    .controlSize(.large)
                }
                .padding(.vertical, 4)
                .accessibilityElement(children: VoiceOverRows.children)
                .accessibilityLabel("Calendar address")
                .accessibilityValue(calAddress)
                .accessibilityHint("Copy address is in the Actions rotor.")
                .accessibilityActions {
                    Button("Copy address") { copyCalAddress(calAddress) }
                }
                .accessibilityFocused($calAddressFocused)
            }
            SecureCaptionedField(label: "Account password, for the calendar address", text: $calPassword)
            if s.hasCalFeed {
                if calAddress == nil {
                    Button { Task { await calShow() } } label: {
                        Text("Show the address").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    }
                    .disabled(busy || calPassword.isEmpty)
                }
                Button(role: .destructive) { confirmingReplace = true } label: {
                    Text("Replace the address").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy || calPassword.isEmpty)
                .accessibilityHint("Asks first. The old address stops working.")
            } else {
                Button { Task { await calRegen() } } label: {
                    Text("Create my calendar address").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy || calPassword.isEmpty)
            }
        } header: { sectionHeader("Calendar subscription") } footer: {
            Text("Subscribe your Outlook, Apple or Google calendar to this private address and your sessions appear and update by themselves. Client names are shortened to first name and last initial, and in-person locations are left out. Your password is checked before the address is shown or changed.")
                .foregroundStyle(Color.secondaryText)
        }
    }

    /// Whether this iPhone has a passcode (and so Face ID, Touch ID or the passcode can lock the app).
    private var deviceCanLock: Bool {
        LAContext().canEvaluatePolicy(.deviceOwnerAuthentication, error: nil)
    }

    private var deviceSection: some View {
        Section {
            LabeledContent("App lock", value: deviceCanLock ? "On" : "Not available")
            Text(deviceCanLock
                 ? "\(lock.unlockHint) The app locks when it opens, after 2 minutes away from it, and when you hand the phone to a client to sign. It uses this iPhone's own Face ID, Touch ID or passcode, which you set in the iPhone's Settings."
                 : "This iPhone has no passcode, so the app cannot lock. Set a passcode in the iPhone's Settings to protect client records.")
                .font(.callout)
            Picker("Voice assistant: pause before sending", selection: $pauseSeconds) {
                Text("1.8 seconds").tag(1.8)
                Text("3 seconds").tag(3.0)
                Text("5 seconds").tag(5.0)
                Text("Until I tap the microphone").tag(0.0)
            }
            .accessibilityHint("How long a pause in speech sends what was heard.")
            Toggle("Voice assistant: read replies with VoiceOver", isOn: $repliesViaVoiceOver)
                .accessibilityHint("When VoiceOver is on, replies are read by VoiceOver and shown on a braille display instead of in the assistant's own voice.")
            Toggle("Voice assistant: start listening when opened", isOn: $voiceAutoListen)
                .accessibilityHint("The microphone opens by itself when the assistant opens, so no tap is needed first.")
            NavigationLink { OfflineEntriesView() } label: {
                LabeledContent("Offline entries", value: api.pendingSync == 0 ? "None waiting" : "\(api.pendingSync) waiting")
            }
        } header: { sectionHeader("This iPhone") }
    }

    /// Local reminders, scheduled on this iPhone from what the app has already loaded. Off until
    /// the person turns them on; iOS asks for permission then.
    private var notificationsSection: some View {
        Section {
            Toggle("Reminders on this iPhone", isOn: Binding(get: { notificationsOn }, set: { on in
                Task { await setNotifications(on) }
            }))
            .accessibilityHint("A session starting in 30 minutes, and at 8:00 how many device follow-ups are due today.")
            if notificationsRefused {
                Text("iOS is not allowing reminders from this app. Turn on Notifications for CMS in the iPhone's Settings, then turn this on again.")
                    .font(.callout)
            }
        } header: { sectionHeader("Notifications") } footer: {
            Text("Reminders never name a client, so they are safe on the lock screen. They come from the schedule and follow-ups the app last loaded: open those screens now and then to keep them current. Tapping a reminder opens that screen.")
                .foregroundStyle(Color.secondaryText)
        }
    }

    private func setNotifications(_ on: Bool) async {
        guard on else {
            notificationsOn = false
            notificationsRefused = false
            LocalNotifications.shared.clearScheduled()
            announce("Reminders off. Scheduled reminders were removed.")
            return
        }
        let allowed = await LocalNotifications.shared.requestPermission()
        notificationsRefused = !allowed
        notificationsOn = allowed
        if allowed {
            announce("Reminders on. They are scheduled the next time the schedule and follow-ups load.")
        } else {
            showFormError("iOS is not allowing reminders from this app. Turn on Notifications for CMS in the iPhone's Settings, then turn this on again.", in: &error)
        }
    }

    private var signOutSection: some View {
        Group {
            Section {
                Button(role: .destructive) { confirmingSignOut = true } label: {
                    Label("Sign out", systemImage: "rectangle.portrait.and.arrow.right")
                        .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy)
                .accessibilityHint("Asks first. Signs out of the app on this iPhone only.")
            } header: { sectionHeader("Sign out") }
            Section {
                Text(everywhereText)
                SecureCaptionedField(label: "Account password, to sign out everywhere", text: $everywherePassword)
                Button(role: .destructive) { confirmingEverywhere = true } label: {
                    Text("Sign out everywhere").frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(busy || everywherePassword.isEmpty)
                .accessibilityHint("Asks first.")
            } header: { sectionHeader("Sign out everywhere") }
        }
    }

    private var everywhereText: String {
        let count = summary?.appSessions ?? 0
        let now = count > 0 ? " The app is signed in on \(count) device\(count == 1 ? "" : "s") now." : ""
        return "Ends the app's sign-in on every device, this iPhone too, if a phone was lost or you think someone else signed in.\(now) Website sessions are not affected."
    }

    private var helpSection: some View {
        Section {
            webLink("User guide", path: "help.php#staff-guide", icon: "book",
                    hint: "Opens the website's user guide inside the app.")
            webLink("Privacy policy", path: "privacy.php", icon: "hand.raised",
                    hint: "Opens the privacy policy inside the app.")
        } header: { sectionHeader("Help and privacy") }
    }

    /// A website page opened inside the app, announced as a link.
    private func webLink(_ title: String, path: String, icon: String, hint: String) -> some View {
        Button { webPage = AccountWebPage(url: siteURL(path)) } label: {
            Label(title, systemImage: icon).frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
        }
        .accessibilityRemoveTraits(.isButton)
        .accessibilityAddTraits(.isLink)
        .accessibilityHint(hint)
    }

    // MARK: Actions

    private func load() async {
        do {
            let s: AccountSummary = try await api.call("account")
            summary = s
            error = nil
            if !s.hasCalFeed { calAddress = nil }
            if s.totpEnabled { setup = nil }
        } catch APIError.server(let message) where message == "Unknown action." {
            showFormError("This server does not offer account settings in the app yet. Use My Account on the website.", in: &error)
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
    }

    /// Runs one change: nothing else starts meanwhile, and a refusal goes to the error banner
    /// (focused and announced).
    private func perform(_ work: () async throws -> Void) async {
        guard !busy else { return }
        busy = true
        error = nil
        do { try await work() } catch {
            showFormError(error.localizedDescription, in: &self.error)
        }
        busy = false
    }

    private func totpBegin() async {
        await perform {
            let started: TotpSetup = try await api.call("totp_begin", body: [:])
            totpPassword = ""
            totpCode = ""
            setup = started
            // VoiceOver goes to the key (and reads it); what to do next is said after it.
            try? await Task.sleep(nanoseconds: 600_000_000)
            setupKeyFocused = true
            try? await Task.sleep(nanoseconds: 600_000_000)
            sayQueued("Add this key to your authenticator app, then enter your account password and the app's 6-digit code, and choose Confirm and enable.")
        }
    }

    private func totpConfirm() async {
        await perform {
            do {
                let result: AccountMessage = try await api.call("totp_confirm", body: ["password": totpPassword, "code": codeDigits])
                setup = nil
                totpPassword = ""
                totpCode = ""
                await load()
                announce(result.message)
            } catch APIError.server(let message) where message.hasPrefix("The authenticator setup had ended") {
                setup = nil
                throw APIError.server(message)
            }
        }
    }

    private func cancelSetup() {
        setup = nil
        totpPassword = ""
        totpCode = ""
        announce("Authenticator setup cancelled. Nothing was changed.")
    }

    private func totpDisable() async {
        await perform {
            let result: AccountMessage = try await api.call("totp_disable", body: ["password": totpOffPassword])
            totpOffPassword = ""
            await load()
            announce(result.message)
        }
    }

    private func openAuthenticator(_ url: URL) {
        openURL(url) { accepted in
            if !accepted {
                showFormError("No authenticator app on this iPhone opened the link. Copy the setup key and enter it in your authenticator app instead.", in: &error)
            }
        }
    }

    private func remove(_ passkey: AccountPasskey) async {
        removing = nil
        await perform {
            do {
                let result: AccountMessage = try await api.call("remove_passkey", body: ["id": passkey.id])
                await load()
                announce(result.message)
            } catch {
                // Already removed (another device, the website): the list is brought up to date.
                await load()
                throw error
            }
        }
    }

    private func calShow() async {
        await perform {
            let result: CalFeedReply = try await api.call("cal_feed_show", body: ["password": calPassword])
            calPassword = ""
            calAddress = result.address
            if result.address == nil {
                await load()
                announce(result.message)
                return
            }
            try? await Task.sleep(nanoseconds: 400_000_000)
            calAddressFocused = true
            try? await Task.sleep(nanoseconds: 600_000_000)
            sayQueued("Copy address is in the Actions rotor.")
        }
    }

    private func calRegen() async {
        await perform {
            let result: CalFeedReply = try await api.call("cal_feed_regen", body: ["password": calPassword])
            calPassword = ""
            await load()
            calAddress = result.address
            announce(result.message)
        }
    }

    private func copySetupKey(_ key: String) {
        api.copyProtected(key)
        announce("Setup key copied. The copy is cleared from the clipboard in 10 minutes.")
    }

    private func copyCalAddress(_ address: String) {
        api.copyProtected(address)
        announce("Calendar address copied. The copy is cleared from the clipboard in 10 minutes.")
    }

    /// The server ended every app session with the password change: this one is signed out as
    /// sign-out does, and the sign-in screen says why.
    private func passwordChanged(_ message: String) {
        changingPassword = false
        let api = self.api
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 400_000_000)
            api.signOut()
            api.sessionNotice = "You were signed out because your password was changed. " + message
        }
    }

    private func logoutAll() async {
        await perform {
            let result: AccountMessage = try await api.call("logout_all", body: ["password": everywherePassword])
            everywherePassword = ""
            api.signOut()
            api.sessionNotice = result.message
        }
    }
}

/// What a password change also does, said before it is made (it cannot be undone).
private func passwordEffects(passkeys: Int, calFeed: Bool) -> String {
    var effects = ["signs you out on every device, including this app, and discards unsaved drafts on this iPhone"]
    if passkeys > 0 { effects.append("removes your \(passkeys) passkey\(passkeys == 1 ? "" : "s") (you can add them again afterwards)") }
    if calFeed { effects.append("turns off your calendar subscription address") }
    let last = effects.removeLast()
    return "Changing your password " + (effects.isEmpty ? last : effects.joined(separator: ", ") + " and " + last) + "."
}

// MARK: - Change password

private struct ChangePasswordSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let passkeyCount: Int
    let hasCalFeed: Bool
    let onChanged: (String) -> Void

    @State private var current = ""
    @State private var newPassword = ""
    @State private var confirm = ""
    @State private var acknowledged = false
    @State private var busy = false
    @State private var error: String?
    @State private var askingDiscard = false

    private var hasChanges: Bool { !current.isEmpty || !newPassword.isEmpty || !confirm.isEmpty }

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                Section {
                    SecureCaptionedField(label: "Current password", text: $current, content: .password)
                    SecureCaptionedField(label: "New password, at least 10 characters", text: $newPassword, content: .newPassword)
                    SecureCaptionedField(label: "Confirm new password", text: $confirm, content: .newPassword)
                } footer: {
                    Text(passwordEffects(passkeys: passkeyCount, calFeed: hasCalFeed) + " You then sign in again with the new password.")
                        .foregroundStyle(Color.secondaryText)
                }
                if passkeyCount > 0 {
                    Section {
                        Toggle("I understand my \(passkeyCount) passkey\(passkeyCount == 1 ? "" : "s") will be removed (required)", isOn: $acknowledged)
                    }
                }
                Section {
                    Button { Task { await save() } } label: {
                        Text(busy ? "Changing password…" : "Change password")
                            .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                    }
                    .disabled(busy || current.isEmpty || newPassword.isEmpty || confirm.isEmpty)
                    .accessibilityLabel("Change password")
                    .accessibilityValue(busy ? "Working" : "")
                }
            }
            .navigationTitle("Change password")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { if hasChanges { askingDiscard = true } else { dismiss() } }
                        .disabled(busy)
                }
            }
        }
        .discardChangesGuard(hasChanges: hasChanges, busy: busy, question: "Discard the password change? Nothing has been changed yet.",
                             asking: $askingDiscard) { dismiss() }
    }

    private func save() async {
        // The server checks all of this too; said here first, every problem at once, so a
        // typing slip does not need a round trip.
        var problems: [String] = []
        if newPassword.count < 10 { problems.append("The new password must be at least 10 characters.") }
        else if newPassword != confirm { problems.append("The confirmation does not match the new password. Type the same new password in both boxes.") }
        if passkeyCount > 0 && !acknowledged {
            problems.append("Turn on “I understand my \(passkeyCount) passkey\(passkeyCount == 1 ? "" : "s") will be removed”, or keep your current password.")
        }
        if !problems.isEmpty {
            showFormError(problems.joined(separator: " "), in: &error)
            return
        }
        busy = true
        error = nil
        do {
            let result: AccountMessage = try await api.call("change_password", body: [
                "current_password": current, "new_password": newPassword, "confirm_password": confirm, "ack_passkeys": acknowledged,
            ])
            busy = false
            onChanged(result.message)
        } catch {
            busy = false
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
}
