import SwiftUI

struct LoginView: View {
    @EnvironmentObject var api: API
    /// Shown above the open app after the server refused the session (see ReauthPresenter):
    /// the same account signs in again and carries on where it was.
    var reauth = false
    @State private var email = ""
    /// The organisation's web address, asked for only by a build that names no server (4.6.0).
    @State private var serverAddress = UserDefaults.standard.string(forKey: API.serverKey) ?? ""
    /// Not while signing in again to the same account: the session belongs to its server.
    private var asksForServer: Bool { API.builtInServer == nil && !reauth }
    /// The sign-in line this build sets (CMS_SIGNIN_TITLE), else the app's name.
    private var signInTitle: String {
        let t = (Bundle.main.object(forInfoDictionaryKey: "CMSSignInTitle") as? String ?? "").trimmingCharacters(in: .whitespaces)
        if !t.isEmpty && !t.contains("$(") { return t }
        return Bundle.main.object(forInfoDictionaryKey: "CFBundleDisplayName") as? String ?? "Case Management"
    }
    @State private var password = ""
    @State private var code = ""
    @State private var awaitingCode = false
    @State private var totpAvailable = false
    /// False when the server could not send the code email (the account has an authenticator app).
    @State private var emailSent = true
    /// The account signs in with its authenticator app only (4.27.0): nothing is emailed, so
    /// the code step says so and offers no new emailed code.
    @State private var totpRequired = false
    /// How long the emailed code works, when the server says (nil: not reported).
    @State private var codeMinutes: Int?
    /// From the password step; sent back with the code so the server knows it is this sign-in.
    @State private var loginChallenge: String?
    @State private var busy = false
    @State private var error: String?
    /// Set after "Send a new code" worked, so the screen says that the earlier code is gone.
    @State private var resentNotice: String?
    @State private var confirmingSignOut = false
    /// Offline entries saved for another server that signing in to this one would remove
    /// (4.27.0): asked about first, saying how many. Nil when nothing is being asked.
    @State private var serverChange: ServerChange?
    /// The server the person agreed to sign in to despite those entries.
    @State private var confirmedServer: String?

    private struct ServerChange {
        let entries: Int
        let drafts: Int
        let prepared: Int
        let from: String
        let to: URL

        /// "2 offline entries, 1 draft and 1 document prepared to sign", naming only what is there.
        var what: String {
            func n(_ count: Int, _ one: String, _ many: String) -> String? { count == 0 ? nil : "\(count) \(count == 1 ? one : many)" }
            let parts = [n(entries, "offline entry", "offline entries"), n(drafts, "unsent draft", "unsent drafts"),
                         n(prepared, "document prepared to sign", "documents prepared to sign")].compactMap { $0 }
            return parts.count > 1 ? parts.dropLast().joined(separator: ", ") + " and " + parts.last! : parts.joined()
        }
        var total: Int { entries + drafts + prepared }
    }
    // Keyboard and VoiceOver focus for the code field, so the step change isn't silent.
    @FocusState private var codeFieldFocused: Bool
    @AccessibilityFocusState private var codeFieldVoiceOverFocused: Bool

    /// The code's digits only: a code pasted as "123 456" or with a trailing newline still
    /// counts as six digits (the server strips everything else too).
    private var codeDigits: String { code.filter { $0.isASCII && $0.isNumber } }

    private var codeInstruction: String {
        if totpRequired { return "Enter the 6-digit code from your authenticator app." }
        if !emailSent {
            return "The code email could not be sent. Enter the 6-digit code from your authenticator app."
        }
        let lead = totpAvailable
            ? "Enter the 6-digit code from your email or authenticator app."
            : "We emailed you a 6-digit code."
        let expiry = codeMinutes.map { "The emailed code works for \($0) minute\($0 == 1 ? "" : "s") after it is sent." }
            ?? "The emailed code works for a limited time after it is sent."
        return "\(lead) \(expiry) If it has run out, choose Send a new code."
    }

    private var reauthExplanation: String {
        let who = api.userName.isEmpty ? "the same account" : api.userName
        return "Your session ended, so sign in again to carry on. Sign in as \(who): your open screens and anything you typed are kept, and anything you were saving is sent once you are signed in. Signing in as someone else closes them without saving."
    }

    private var title: String {
        if awaitingCode { return "Enter your code" }
        return reauth ? "Sign in again" : "Sign in"
    }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 20) {
                    ZStack {
                        Circle()
                            .stroke(Color.white, lineWidth: 9)
                            .frame(width: 74, height: 74)
                        Circle()
                            .fill(Color.brandTeal)
                            .frame(width: 16, height: 16)
                            .offset(y: -37)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(28)
                    .background(Color.brandFill, in: RoundedRectangle(cornerRadius: 24))
                    .accessibilityHidden(true)

                    // The screen's only heading (the navigation bar is hidden), so the Headings
                    // rotor finds it and says which step this is.
                    Text(title)
                        .font(.largeTitle.bold())
                        .accessibilityAddTraits(.isHeader)

                    if let error {
                        ErrorBanner(message: error)
                    } else if let notice = api.sessionNotice, !awaitingCode, !reauth {
                        // Why the app came back here by itself (announced once, below).
                        Label(notice, systemImage: "exclamationmark.circle")
                            .font(.callout.weight(.medium))
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(12)
                            .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
                    }
                    if reauth && !awaitingCode {
                        Label(reauthExplanation, systemImage: "exclamationmark.circle")
                            .font(.callout.weight(.medium))
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(12)
                            .background(.quaternary, in: RoundedRectangle(cornerRadius: 10))
                    }

                    if !awaitingCode {
                        Text(signInTitle)
                            .foregroundStyle(Color.secondaryText)
                        if asksForServer {
                            SignInField(label: "Your organisation's web address") {
                                TextField("Your organisation's web address", text: $serverAddress)
                                    .textContentType(.URL)
                                    .keyboardType(.URL)
                                    .textInputAutocapitalization(.never)
                                    .autocorrectionDisabled()
                            }
                        }
                        SignInField(label: "Email address") {
                            TextField("Email address", text: $email)
                                .textContentType(.username)
                                .keyboardType(.emailAddress)
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled()
                        }
                        SignInField(label: "Password") {
                            SecureField("Password", text: $password)
                                .textContentType(.password)
                        }
                        Button(action: { Task { await login() } }) {
                            BusyLabel(title: "Continue", busy: busy)
                        }
                        .buttonStyle(.borderedProminent)
                        .controlSize(.large)
                        .onBrandLabel()
                        .disabled(busy || email.isEmpty || password.isEmpty)
                        .accessibilityLabel("Continue")
                        .accessibilityValue(busy ? "Signing in" : "")
                        Link(destination: URL(string: "forgot.php", relativeTo: API.base)!.absoluteURL) {
                            Text("Forgot your password? Reset it on the website")
                                .frame(maxWidth: .infinity, minHeight: 44)
                                .contentShape(Rectangle())
                        }
                        .accessibilityHint("Opens the website in Safari.")
                        // How the server signed in to here handles personal information, readable
                        // before signing in.
                        Link(destination: URL(string: "privacy.php", relativeTo: API.base)!.absoluteURL) {
                            Text("Privacy policy")
                                .frame(maxWidth: .infinity, minHeight: 44)
                                .contentShape(Rectangle())
                        }
                        .accessibilityHint("Opens the website in Safari.")
                    } else {
                        Text(codeInstruction)
                            .foregroundStyle(Color.secondaryText)
                            .fixedSize(horizontal: false, vertical: true)
                        if let resentNotice {
                            Text(resentNotice)
                                .font(.callout.weight(.medium))
                        }
                        SignInField(label: "6-digit code") {
                            TextField("6-digit code", text: $code)
                                .textContentType(.oneTimeCode)
                                .keyboardType(.numberPad)
                                .font(.title2.monospaced())
                                .focused($codeFieldFocused)
                                .accessibilityFocused($codeFieldVoiceOverFocused)
                                // No hint (4.30.0): the instruction is said once, after focus
                                // lands here (focusCodeField), and is the text above the field.
                        }
                        Button(action: { Task { await verify() } }) {
                            BusyLabel(title: "Verify and sign in", busy: busy)
                        }
                        .buttonStyle(.borderedProminent)
                        .controlSize(.large)
                        .onBrandLabel()
                        .disabled(busy || codeDigits.count != 6)
                        .accessibilityLabel("Verify and sign in")
                        .accessibilityValue(busy ? "Working" : "")
                        // A fresh code without typing the password again (it is still held here).
                        // Not for an authenticator-app account: no code is emailed to it.
                        if !totpRequired {
                            Button { Task { await resend() } } label: {
                                Text("Send a new code").frame(maxWidth: .infinity)
                            }
                            .buttonStyle(.bordered)
                            .controlSize(.large)
                            .disabled(busy)
                            .accessibilityHint("Sends a new code to your email. The earlier code stops working.")
                        }
                        Button {
                            awaitingCode = false
                            code = ""
                            password = ""
                            loginChallenge = nil
                            error = nil
                            resentNotice = nil
                            // This button is gone with the code step, and the move off it cut an
                            // announcement short: say the new step once VoiceOver has settled,
                            // and start it at the top of the sign-in screen.
                            Task {
                                try? await Task.sleep(nanoseconds: 500_000_000)
                                guard !awaitingCode else { return }
                                UIAccessibility.post(notification: .screenChanged,
                                                     argument: "Sign in. Enter your email address and password.")
                            }
                        } label: {
                            Text("Start over").frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                        .controlSize(.large)
                        .disabled(busy)
                        .accessibilityHint("Goes back to the email address and password.")
                    }
                    if reauth {
                        Button(role: .destructive) { confirmingSignOut = true } label: {
                            Text("Sign out instead").frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                        .controlSize(.large)
                        .disabled(busy)
                    }
                }
                .padding(24)
            }
            .navigationBarHidden(true)
            .confirmationDialog("Sign out without signing in again?", isPresented: $confirmingSignOut,
                                titleVisibility: .visible) {
                Button("Sign out", role: .destructive) { api.signOut() }
                Button("Cancel", role: .cancel) {}
            } message: {
                Text("Anything you have not saved is discarded. Entries already saved offline stay on this phone."
                     + signOutRemoves(prepared: PreparedSignatures.all(owner: api.userId).count))
            }
            .alert("Offline entries are waiting", isPresented: Binding(get: { serverChange != nil },
                                                                     set: { if !$0 { serverChange = nil } }),
                   presenting: serverChange) { change in
                Button("Sign in to \(change.to.host ?? "the new server")", role: .destructive) {
                    confirmedServer = change.to.absoluteString
                    Task { await login() }
                }
                Button("Cancel", role: .cancel) {}
            } message: { change in
                Text("This phone keeps \(change.what) for \(change.from), not sent yet. Signing in to \(change.to.host ?? "the new server") "
                     + "removes \(change.total == 1 ? "it" : "all of them") from this phone, for every account. "
                     + "To keep \(change.total == 1 ? "it" : "them"), cancel and sign in to \(change.from) instead.")
            }
        }
        .task {
            // An expired session swapped the whole app for this screen (or put this one above
            // it): say why, once, a beat after the switch so VoiceOver doesn't drop it while the
            // new screen settles.
            if reauth {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                if api.reauthRequired && !awaitingCode { announce(reauthExplanation) }
                return
            }
            guard let notice = api.sessionNotice else { return }
            try? await Task.sleep(nanoseconds: 800_000_000)
            if api.sessionNotice == notice { announce(notice) }
        }
    }

    private func apply(_ step: LoginStep) {
        totpAvailable = step.totpAvailable
        totpRequired = step.totpRequired
        emailSent = step.emailSent ?? true
        codeMinutes = step.codeExpiresMinutes.flatMap { $0 > 0 ? $0 : nil }
        loginChallenge = step.loginChallenge
    }

    /// Puts VoiceOver (and the keyboard) on the code field once the screen has settled, then
    /// says `message`, queued behind VoiceOver reading the field: said before the move, it
    /// was cut off by it (and the field's hint is not read when hints are turned off).
    private func focusCodeField(thenSay message: String) {
        Task {
            try? await Task.sleep(nanoseconds: 700_000_000)
            guard awaitingCode else { return }
            codeFieldFocused = true
            codeFieldVoiceOverFocused = true
            try? await Task.sleep(nanoseconds: 600_000_000)
            guard awaitingCode else { return }
            UIAccessibility.post(notification: .announcement,
                                 argument: NSAttributedString(string: message, attributes: [.accessibilitySpeechQueueAnnouncement: true]))
        }
    }

    private func login() async {
        busy = true; error = nil
        api.sessionNotice = nil
        if asksForServer {
            // Kept before signing in, since every request goes to it; checked first (https only).
            guard let next = API.apiURL(fromAddress: serverAddress) else {
                busy = false
                error = "Enter your organisation's web address, such as cms.example.org. It must be a secure (https) address."
                return
            }
            // Entries saved for another server are removed only once this sign-in works, and
            // only after the person agreed (4.27.0).
            // Drafts and documents prepared to sign go too (finishSignIn), so they are counted.
            let waiting = api.entriesLostBySigningIn(to: next)
            let differs = next.absoluteString != API.dataServer
            let change = ServerChange(entries: waiting, drafts: differs ? DraftStore.countAllAccounts() : 0,
                                      prepared: differs ? PreparedSignatures.countAllAccounts() : 0,
                                      from: URL(string: UserDefaults.standard.string(forKey: API.dataServerKey) ?? "")?.host ?? API.base.host ?? "the previous server",
                                      to: next)
            if change.total > 0, confirmedServer != next.absoluteString {
                busy = false
                serverChange = change
                return
            }
            api.switchServer(to: serverAddress)
        }
        // Said and shown: the person hears which server gets their password.
        announce(asksForServer ? "Signing in to \(API.base.host ?? "your organisation")" : "Signing in")
        do {
            let step: LoginStep = try await api.call("login", body: ["email": email, "password": password])
            apply(step)
            resentNotice = nil
            awaitingCode = true
            // Put VoiceOver (and the keyboard) on the code field — the Continue button that had
            // focus no longer exists — and then say that the screen moved on and what to enter.
            focusCodeField(thenSay: "Password accepted. " + codeInstruction)
        } catch {
            self.error = (asksForServer ? "Signing in to \(API.base.host ?? "your organisation") did not work. Check the address if it is new. " : "") + error.localizedDescription
        }
        busy = false
    }

    /// Repeats the password step with what was typed, which sends a new code and cancels the
    /// earlier one, without going back to the start.
    private func resend() async {
        busy = true; error = nil
        announce("Sending a new code")
        do {
            let step: LoginStep = try await api.call("login", body: ["email": email, "password": password])
            apply(step)
            code = ""
            let notice = emailSent
                ? "A new code was sent. Codes sent earlier no longer work."
                : "The code email could not be sent. Enter the code from your authenticator app."
            resentNotice = notice
            focusCodeField(thenSay: notice)
        } catch {
            self.error = error.localizedDescription
        }
        busy = false
    }

    private func verify() async {
        busy = true; error = nil
        announce("Checking the code")
        do {
            var body: [String: Any] = ["email": email, "code": codeDigits, "device_name": API.deviceName]
            if let loginChallenge, !loginChallenge.isEmpty { body["login_challenge"] = loginChallenge }
            let result: VerifyResult = try await api.call("verify", body: body)
            // The same account after an expired session carries on where it was; otherwise
            // this flips RootView to (fresh) main tabs.
            api.finishSignIn(token: result.token, userId: result.userId, name: result.name)
        } catch {
            self.error = error.localizedDescription
        }
        busy = false
    }
}

/// A sign-in field with a visible label above it (a placeholder alone disappears once typing
/// starts and is about 1.7:1) and an outline of 4.5:1 or more against the screen.
private struct SignInField<Field: View>: View {
    let label: String
    @ViewBuilder let field: () -> Field

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            // The field carries the same name for VoiceOver, so the visible label is not read twice.
            Text(label)
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(.primary)
                .accessibilityHidden(true)
            field()
                .padding(14)
                .background(Color(.systemBackground), in: RoundedRectangle(cornerRadius: 10))
                .overlay(RoundedRectangle(cornerRadius: 10).stroke(Color.fieldBorder, lineWidth: 1.5))
        }
    }
}

/// A button's text with a spinner beside it while it works: the name never changes, so the
/// focused button is still "Continue" (its value says what is happening).
private struct BusyLabel: View {
    let title: String
    let busy: Bool

    var body: some View {
        HStack(spacing: 8) {
            if busy { ProgressView().accessibilityHidden(true) }
            Text(title)
        }
        .frame(maxWidth: .infinity)
    }
}
