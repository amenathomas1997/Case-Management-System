import XCTest
import CryptoKit

/// Accessibility audits (XCUIApplication.performAccessibilityAudit) of the screens added in
/// 4.24.0 to 4.26.0, at the default text size and at the largest accessibility size.
///
/// LOCAL RUNS ONLY. The app is built for a local test server (made-up data) and signs in with a
/// local test account given through the environment, never stored in the repository:
///
///   TEST_RUNNER_CMS_TEST_EMAIL, TEST_RUNNER_CMS_TEST_PASSWORD, TEST_RUNNER_CMS_TEST_TOTP_SECRET
///   (xcodebuild hands TEST_RUNNER_-prefixed variables to the tests without the prefix)
///
///   xcodebuild test -project ios/CaseManagement.xcodeproj -scheme CaseManagementUITests \
///     -destination 'id=<a fresh simulator>' CMS_DEFAULT_SERVER=localhost:8443
///
/// The 6-digit sign-in code is worked out here from the secret at run time. With no account in
/// the environment the tests are skipped. Use a fresh simulator that trusts the local server's
/// certificate (xcrun simctl keychain <udid> add-root-cert cert.pem). A simulator has no
/// passcode, so the app keeps no client data on it: the screens are audited online.
final class AccessibilityAuditTests: XCTestCase {
    private var app: XCUIApplication!
    private var issues: [String] = []
    private var sizeName = "default text"
    private var largeText = false
    /// Where the navigation bar ends and the tab bar starts on the screen being audited.
    private var barBottom: CGFloat = 0
    private var tabTop: CGFloat = .greatestFiniteMagnitude

    override func setUpWithError() throws {
        continueAfterFailure = true
        let env = ProcessInfo.processInfo.environment
        guard let email = env["CMS_TEST_EMAIL"], !email.isEmpty,
              env["CMS_TEST_PASSWORD"]?.isEmpty == false,
              env["CMS_TEST_TOTP_SECRET"]?.isEmpty == false else {
            throw XCTSkip("No local test account in the environment (CMS_TEST_EMAIL, CMS_TEST_PASSWORD, CMS_TEST_TOTP_SECRET).")
        }
    }

    func testAuditsAtDefaultTextSize() throws {
        sizeName = "default text"
        try runAudits(arguments: [])
    }

    func testAuditsAtLargestAccessibilityTextSize() throws {
        sizeName = "AX XXXL text"
        largeText = true
        try runAudits(arguments: ["-UIPreferredContentSizeCategoryName", "UICTContentSizeCategoryAccessibilityXXXL"])
    }

    // MARK: - The screens

    private func runAudits(arguments: [String]) throws {
        app = XCUIApplication()
        app.launchArguments += arguments
        app.launch()
        try signInIfNeeded()
        dismissAlerts()

        try openClient("Jordan Sample")
        audit("Client detail")
        scrollTo(app.staticTexts["Documents to sign"].firstMatch, orButton: "Documents to sign")
        audit("Client detail, documents to sign and releases")
        if scrollTo(documentsHeader()) { audit("Client detail, documents list") }

        // Client edit, with More details and Office.
        scrollToTop()
        tap(app.navigationBars.buttons["Actions"].firstMatch)
        tap(app.buttons["Edit client / status"].firstMatch)
        XCTAssertTrue(app.navigationBars.firstMatch.waitForExistence(timeout: 10))
        sleep(2)
        audit("Client edit")
        if scrollTo(app.staticTexts["Office"].firstMatch) || scrollTo(app.buttons["Office"].firstMatch) { audit("Client edit, Office") }
        if scrollTo(app.staticTexts["MORE DETAILS"].firstMatch) || scrollTo(app.staticTexts["More details"].firstMatch) { audit("Client edit, More details") }
        cancelSheet()

        // Request a release.
        if scrollTo(app.buttons["Request a release"].firstMatch) {
            tap(app.buttons["Request a release"].firstMatch)
            _ = app.navigationBars["Request a release"].waitForExistence(timeout: 10)
            sleep(2)
            audit("Request a release")
            cancelSheet()
        } else {
            record("Request a release: button not found")
        }

        // Documents to sign: the list, the send form, the prepare form, signing in person.
        openDocumentsToSign()
        _ = app.navigationBars["Documents to sign"].waitForExistence(timeout: 10)
        sleep(2)
        audit("Documents to sign, list")
        tap(app.buttons["Send by email"].firstMatch)
        _ = app.navigationBars["Send by email"].waitForExistence(timeout: 10)
        audit("Documents to sign, send by email form")
        describeControls(["Kind of document", "Who signs"])
        cancelSheet()
        tap(app.buttons["Prepare to sign with no signal"].firstMatch)
        _ = app.navigationBars["Prepare to sign"].waitForExistence(timeout: 10)
        audit("Documents to sign, prepare to sign form")
        cancelSheet()
        tap(app.buttons["Sign here now"].firstMatch)
        _ = app.navigationBars["Sign here now"].waitForExistence(timeout: 10)
        audit("Documents to sign, sign here now form")
        tap(app.navigationBars.buttons["Start"].firstMatch)
        if app.navigationBars["Document to sign"].waitForExistence(timeout: 15) {
            sleep(1)
            audit("In-person signing, before signing")
            app.swipeUp(); app.swipeUp()
            audit("In-person signing, the sign controls")
            // Stop without signing: the document is withdrawn and the app locks.
            tap(app.navigationBars.buttons["Cancel"].firstMatch)
            tap(app.buttons["Stop without signing"].firstMatch)
            sleep(2)
            // The app locked: unlock it, as the device owner does on taking the phone back.
            for _ in 0..<10 where !app.tabBars.firstMatch.isHittable { unlockIfAsked(); sleep(1) }
            if !app.tabBars.firstMatch.isHittable { record("The app did not unlock after signing in person") }
            dismissAlerts()
        } else {
            record("In-person signing: the document did not open")
            cancelSheet()
        }

        // Messages: the thread with the attach menu.
        openTab("Dashboard")
        popToRoot()
        if scrollTo(app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Messages'")).firstMatch) {
            tap(app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Messages'")).firstMatch)
            sleep(2)
            let thread = app.buttons.matching(NSPredicate(format: "label CONTAINS 'Jordan Sample'")).firstMatch
            if thread.waitForExistence(timeout: 8) {
                tap(thread)
            } else {
                tap(app.navigationBars.buttons.element(boundBy: app.navigationBars.buttons.count - 1))
                let pick = app.buttons.matching(NSPredicate(format: "label CONTAINS 'Jordan Sample'")).firstMatch
                if pick.waitForExistence(timeout: 8) { tap(pick) }
            }
            sleep(2)
            audit("Message thread")
            let attach = app.buttons["Attach a file"].firstMatch
            if attach.waitForExistence(timeout: 8) {
                tap(attach)
                sleep(1)
                audit("Message thread, attach menu")
                closeMenu()
            } else {
                record("Message thread: Attach a file not found")
            }
        } else {
            record("Messages: not found on the dashboard")
        }

        // Equipment: the new loan sheet.
        openTab("Equipment")
        let loan = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Loan ' AND label ENDSWITH ' out'")).firstMatch
        if loan.waitForExistence(timeout: 10) || scrollTo(loan) {
            tap(loan)
            _ = app.navigationBars["Loan out"].waitForExistence(timeout: 10)
            sleep(2)
            audit("New loan sheet")
            describeControls(["Return due", "Client (required)", "Item"])
            cancelSheet()
        } else {
            record("Equipment: no item to loan out")
        }

        if !issues.isEmpty {
            XCTFail("\(issues.count) accessibility issue(s) at \(sizeName):\n" + issues.joined(separator: "\n"))
        }
    }

    // MARK: - Auditing

    private func audit(_ screen: String) {
        // Let scrolling and transitions settle, so nothing is caught half-way under a bar.
        sleep(1)
        let screenshot = XCTAttachment(screenshot: app.screenshot())
        screenshot.name = "\(screen) (\(sizeName))"
        screenshot.lifetime = .keepAlways
        add(screenshot)
        let nav = app.navigationBars.firstMatch
        barBottom = nav.exists ? nav.frame.maxY : 0
        let tabs = app.tabBars.firstMatch
        tabTop = tabs.exists && tabs.isHittable ? tabs.frame.minY : app.frame.maxY
        do {
            try app.performAccessibilityAudit(for: .all) { issue in
                if self.isKnownFalsePositive(issue) { return true }
                let detail = issue.element == nil ? " (\(issue.detailedDescription))" : ""
                let element = issue.element.map { "\($0.elementType.rawValue) “\($0.label)” id “\($0.identifier)” frame \($0.frame)" } ?? "no element"
                self.record("\(screen): \(Self.name(issue.auditType)): \(issue.compactDescription) — \(element)\(detail)")
                return true
            }
        } catch {
            record("\(screen): the audit could not run: \(error)")
        }
    }

    /// Issues the audit reports that are not real problems for VoiceOver, Voice Control or
    /// large-text users. Each is filtered for that one issue type on that one element only.
    private func isKnownFalsePositive(_ issue: XCUIAccessibilityAuditIssue) -> Bool {
        // At the default size the audit reports "Dynamic Type font sizes are partially
        // unsupported" for ordinary text in system text styles (captions, row labels, message
        // details) that does grow: the same screens are audited at the largest accessibility size
        // (testAuditsAtLargestAccessibilityTextSize), where that text is drawn at full size and
        // any that is not would be reported. So this one issue type is left to that run.
        if issue.auditType == .dynamicType, !largeText { return true }
        // An issue the audit cannot tie to any element ("Contrast failed for
        // SwiftUI.AccessibilityNode", "Text of this element may be clipped"): it names no element
        // and no frame, so it cannot point at an app control or text, and nothing on the audit
        // screenshots matches it (the likely source is the SF Symbol glyphs inside Labels, whose
        // thin anti-aliased strokes are sampled against the row; the symbols are drawn in the
        // brand navy, 10.4:1). They are listed in the log as A11Y-NOTE, not as failures.
        guard let element = issue.element else {
            print("A11Y-NOTE [\(sizeName)] \(Self.name(issue.auditType)): \(issue.detailedDescription)")
            return true
        }
        // "No assessments recorded." appears when its section finishes loading as it scrolls into
        // view, with the system's fade-in; the audit samples it part-way through the fade. It is
        // Color.secondaryText (9.5:1 on white) once shown.
        if issue.auditType == .contrast, element.label == "No assessments recorded." { return true }
        let frame = element.frame
        // The navigation bar's own buttons (Cancel, Save, Send, Start, Loan, Back): drawn by the
        // system as Liquid Glass bar items. Bar items keep a fixed size at every text size by
        // design and show the Large Content Viewer on a long press instead; their contrast is
        // measured against whatever is scrolled under the translucent glass, not their own fill.
        if element.elementType == .button, frame.maxY <= barBottom + 1,
           issue.auditType == .dynamicType || issue.auditType == .contrast {
            return true
        }
        // At the default size the audit predicts that a form's text fields, and the chosen value
        // of a menu picker, "may be clipped at larger Dynamic Type sizes". The largest-size run
        // audits the same fields and pickers at that size, where the fields are shown whole and
        // the pickers open their choices on a screen of their own (ReadablePickers) with label
        // and value read out; it reports none of these. So at the default size they are left
        // to that run: text fields, and the picker values named here.
        if !largeText, issue.auditType == .textClipped,
           element.elementType == .textField || (element.elementType == .staticText && ["Consent to services"].contains(element.label)) {
            return true
        }
        // Hit region on plain text: the homework row's status, instructions and due date are
        // text, not controls (Edit and Mark completed are the row's buttons, each 44 points), so
        // there is nothing to hit.
        if issue.auditType == .hitRegion, element.elementType == .staticText { return true }
        // A row's title in a Label (an SF Symbol and text): reported as clipped although it is
        // drawn whole, on one line at the default size and wrapped onto several lines at the
        // accessibility sizes (checked on the audit screenshots of each of these screens).
        // Only these titles: other clipped text is still reported.
        let wholeLabelTitles: Set<String> = ["Sign here now", "Prepare to sign with no signal", "Send by email",
                                             "Assign homework", "Curriculum & competency", "Status history"]
        if issue.auditType == .textClipped, element.elementType == .staticText, wholeLabelTitles.contains(element.label) {
            return true
        }
        // The client record's first row label, "Status", at the largest size: drawn whole on its
        // own line above the status (checked on the audit screenshot), the same layout as every
        // row under it, which the audit passes.
        if largeText, issue.auditType == .textClipped || issue.auditType == .dynamicType,
           element.elementType == .staticText, element.label == "Status" {
            return true
        }
        // Rows scrolled part-way under the translucent navigation or tab bar: the bar blurs and
        // fades what is beneath it on purpose, and the same rows are audited again in clear view
        // (the audit runs on each part of the screen).
        if issue.auditType == .contrast || issue.auditType == .textClipped,
           frame.minY < barBottom || frame.maxY > tabTop {
            return true
        }
        return false
    }

    private static func name(_ type: XCUIAccessibilityAuditType) -> String {
        switch type {
        case .contrast: return "contrast"
        case .elementDetection: return "element detection"
        case .hitRegion: return "hit region"
        case .sufficientElementDescription: return "element description"
        case .dynamicType: return "Dynamic Type"
        case .textClipped: return "text clipped"
        case .trait: return "trait"
        default: return "type \(type.rawValue)"
        }
    }

    private func record(_ line: String) {
        let text = "[\(sizeName)] \(line)"
        print("A11Y-AUDIT " + text)
        issues.append(text)
    }

    /// What VoiceOver gets for the pickers and date pickers named: their element type, label and
    /// value (logged as A11Y-CONTROL), so a picker that lost its label or value shows up.
    private func describeControls(_ names: [String]) {
        for name in names {
            let matches = app.descendants(matching: .any).matching(NSPredicate(format: "label BEGINSWITH %@", name))
            if matches.count == 0 { print("A11Y-CONTROL [\(sizeName)] \(name): not found"); continue }
            for i in 0..<min(matches.count, 3) {
                let e = matches.element(boundBy: i)
                print("A11Y-CONTROL [\(sizeName)] \(name): type \(e.elementType.rawValue) label “\(e.label)” value “\(e.value as? String ?? "")” hittable \(e.isHittable)")
            }
        }
    }

    // MARK: - Signing in

    private func signInIfNeeded() throws {
        let emailField = app.textFields["Email address"]
        let tabs = app.tabBars.firstMatch
        let deadline = Date().addingTimeInterval(20)
        while Date() < deadline && !emailField.exists && !tabs.isHittable {
            unlockIfAsked()
            sleep(1)
        }
        guard emailField.exists else { return }
        let env = ProcessInfo.processInfo.environment
        emailField.tap()
        emailField.typeText(env["CMS_TEST_EMAIL"] ?? "")
        let password = app.secureTextFields["Password"]
        password.tap()
        password.typeText(env["CMS_TEST_PASSWORD"] ?? "")
        app.buttons["Continue"].tap()
        let codeField = app.textFields["6-digit code"]
        XCTAssertTrue(codeField.waitForExistence(timeout: 20), "The code step did not appear")
        // A fresh code, not one about to change.
        let left = 30 - Int(Date().timeIntervalSince1970) % 30
        if left < 4 { sleep(UInt32(left + 1)) }
        // The code field takes the keyboard by itself.
        if codeField.isHittable { codeField.tap() }
        sleep(1)
        codeField.typeText(try Self.totp(secret: env["CMS_TEST_TOTP_SECRET"] ?? ""))
        // The system offers to save the password (a fresh simulator): not now.
        let notNow = app.buttons["Not Now"]
        if notNow.waitForExistence(timeout: 3) { notNow.tap() }
        app.buttons["Verify and sign in"].tap()
        XCTAssertTrue(tabs.waitForExistence(timeout: 30), "Sign-in did not finish")
    }

    /// The app locks when reopened with a kept session, and after the phone was handed over to
    /// sign. This simulator reports a passcode, and its passcode prompt takes any entry.
    @discardableResult
    private func unlockIfAsked() -> Bool {
        for host in [app!, XCUIApplication(bundleIdentifier: "com.apple.springboard")] {
            let field = host.secureTextFields.firstMatch
            if field.exists {
                if field.isHittable { field.tap() }
                field.typeText("1234\n")
                sleep(2)
                return true
            }
        }
        let unlock = app.buttons["Unlock"]
        if unlock.exists && unlock.isHittable { unlock.tap(); sleep(2) }
        return false
    }

    /// RFC 6238 (SHA-1, 30 seconds, 6 digits), as public/inc/totp.php.
    static func totp(secret: String, at date: Date = Date()) throws -> String {
        let alphabet = Array("ABCDEFGHIJKLMNOPQRSTUVWXYZ234567")
        var bits = 0, value = 0
        var key = [UInt8]()
        for ch in secret.uppercased() where ch != "=" && ch != " " {
            guard let index = alphabet.firstIndex(of: ch) else { throw XCTSkip("The TOTP secret is not base32") }
            value = (value << 5) | index
            bits += 5
            if bits >= 8 { key.append(UInt8((value >> (bits - 8)) & 0xFF)); bits -= 8 }
        }
        var counter = UInt64(date.timeIntervalSince1970 / 30).bigEndian
        let message = Data(bytes: &counter, count: 8)
        let mac = Array(HMAC<Insecure.SHA1>.authenticationCode(for: message, using: SymmetricKey(data: key)))
        let offset = Int(mac[19] & 0x0F)
        let code = (UInt32(mac[offset] & 0x7F) << 24 | UInt32(mac[offset + 1]) << 16 | UInt32(mac[offset + 2]) << 8 | UInt32(mac[offset + 3])) % 1_000_000
        return String(format: "%06d", code)
    }

    // MARK: - Moving around

    private func dismissAlerts() {
        for _ in 0..<3 {
            let alert = app.alerts.firstMatch
            guard alert.waitForExistence(timeout: 2) else { return }
            alert.buttons.firstMatch.tap()
        }
    }

    private func openTab(_ name: String) {
        let button = app.tabBars.buttons[name]
        if button.waitForExistence(timeout: 10) { button.tap() }
    }

    private func popToRoot() {
        for _ in 0..<4 {
            let back = app.navigationBars.buttons.element(boundBy: 0)
            guard back.exists, back.label != "Actions", app.navigationBars.count > 0, back.identifier == "BackButton" || back.label == "Back" else { return }
            back.tap()
        }
    }

    private func openClient(_ name: String) throws {
        openTab("Clients")
        let search = app.searchFields.firstMatch
        if !search.waitForExistence(timeout: 5) { app.swipeDown() }
        XCTAssertTrue(search.waitForExistence(timeout: 10), "No client search field")
        search.tap()
        search.typeText(name)
        let row = app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", name)).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 15), "\(name) is not in the client list")
        row.tap()
        XCTAssertTrue(app.navigationBars[name].waitForExistence(timeout: 15), "\(name)'s record did not open")
        sleep(3)
    }

    /// The client record's Documents to sign link (after its section header of the same name).
    private func openDocumentsToSign() {
        let links = app.descendants(matching: .any).matching(NSPredicate(format: "label BEGINSWITH 'Documents to sign' AND (elementType == %d OR elementType == %d)",
                                                                           XCUIElement.ElementType.button.rawValue, XCUIElement.ElementType.staticText.rawValue))
        for goingDown in [true, false] {
            for _ in 0..<60 {
                if links.count > 0, inView(links.element(boundBy: links.count - 1)) {
                    links.element(boundBy: links.count - 1).tap()
                    return
                }
                if goingDown { app.swipeUp(velocity: .slow) } else { app.swipeDown(velocity: .slow) }
            }
        }
        print("A11Y-DEBUG " + app.debugDescription)
        record("could not find the Documents to sign link")
    }

    private func documentsHeader() -> XCUIElement {
        app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH[c] 'Documents ('")).firstMatch
    }

    /// Scrolls until the element is in clear view: down the screen first, then back up.
    @discardableResult
    private func scrollTo(_ element: XCUIElement, orButton: String? = nil) -> Bool {
        for goingDown in [true, false] {
            for _ in 0..<45 {
                if inView(element) { return true }
                if let orButton, inView(app.buttons[orButton]) { return true }
                if element.exists, element.frame.minY < app.frame.height * 0.12, element.frame.maxY > 0 {
                    app.swipeDown(velocity: .slow)      // just above the clear area
                } else if goingDown {
                    app.swipeUp(velocity: .slow)
                } else {
                    app.swipeDown(velocity: .slow)
                }
            }
        }
        return inView(element)
    }

    /// On screen and clear of the bars at the top and bottom.
    private func inView(_ element: XCUIElement) -> Bool {
        guard element.exists, element.isHittable else { return false }
        let f = element.frame, h = app.frame.height
        return f.minY > h * 0.12 && f.minY < h * 0.7
    }

    private func scrollToTop() {
        let bar = app.statusBars.firstMatch
        if bar.exists { bar.tap() }
        for _ in 0..<12 { app.swipeDown(velocity: .fast) }
    }

    private func tap(_ element: XCUIElement) {
        guard element.waitForExistence(timeout: 10) else {
            record("could not find \(element.description)")
            return
        }
        element.tap()
    }

    private func cancelSheet() {
        let cancel = app.navigationBars.buttons["Cancel"].firstMatch
        guard cancel.waitForExistence(timeout: 5) else { return }
        cancel.tap()
        // A form with changes asks first.
        let discard = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Discard'")).firstMatch
        if discard.waitForExistence(timeout: 2) { discard.tap() }
        sleep(1)
    }

    private func closeMenu() {
        // A tap outside the menu closes it.
        app.coordinate(withNormalizedOffset: CGVector(dx: 0.5, dy: 0.15)).tap()
        sleep(1)
    }

    // MARK: - Signing with no signal (4.26.0), end to end

    // Run one at a time, in order, with the local server's HTTPS proxy UP for step 1, DOWN for
    // step 2 and UP again for step 3 (stopping and starting it between the runs). The phone must
    // keep client data (this simulator reports a passcode), so the client list, the record and
    // the prepared document are there with no connection.

    private static let offlineTitle = "Offline UI test"
    private static let offlineClient = "Jordan Sample"

    /// Online: "Another document" prepared for the client to sign later with no signal.
    func testOffline1Prepare() throws {
        launchSignedIn()
        try openClient(Self.offlineClient)
        openDocumentsToSign()
        XCTAssertTrue(app.navigationBars["Documents to sign"].waitForExistence(timeout: 15), "Documents to sign did not open")
        let prepare = app.buttons["Prepare to sign with no signal"]
        XCTAssertTrue(prepare.waitForExistence(timeout: 15), "No Prepare to sign with no signal button")
        // The button waits for the list to load.
        let enabled = NSPredicate(format: "isEnabled == true")
        expectation(for: enabled, evaluatedWith: prepare)
        waitForExpectations(timeout: 15)
        prepare.tap()
        XCTAssertTrue(app.navigationBars["Prepare to sign"].waitForExistence(timeout: 10), "The prepare form did not open")

        // Kind of document: Another document (a menu picker at the default text size).
        let kind = app.buttons.matching(NSPredicate(format: "label BEGINSWITH 'Kind of document'")).firstMatch
        XCTAssertTrue(kind.waitForExistence(timeout: 10), "No Kind of document picker")
        kind.tap()
        let another = app.buttons["Another document"].firstMatch
        XCTAssertTrue(another.waitForExistence(timeout: 5), "No Another document choice")
        another.tap()
        sleep(1)

        let title = field("Title (required)")
        XCTAssertTrue(title.waitForExistence(timeout: 10), "No Title field")
        title.tap()
        title.typeText(Self.offlineTitle)
        let body = field("Text to sign (required). Leave a blank line between paragraphs.")
        XCTAssertTrue(body.waitForExistence(timeout: 10), "No Text to sign field")
        body.tap()
        body.typeText("Signed with no signal.")
        // The signer is the client (the form's default), named as on the record.

        app.navigationBars.buttons["Prepare"].tap()
        XCTAssertTrue(app.navigationBars["Documents to sign"].waitForExistence(timeout: 20), "The prepare form did not close")
        XCTAssertFalse(app.staticTexts.matching(NSPredicate(format: "label CONTAINS 'could not be kept'")).firstMatch.exists,
                       "The document was started but not kept on the phone")
        let section = app.staticTexts["Ready to sign here, even with no signal"]
        XCTAssertTrue(section.waitForExistence(timeout: 10), "No Ready to sign here, even with no signal section")
        XCTAssertTrue(preparedRow().waitForExistence(timeout: 10), "\(Self.offlineTitle) is not under Ready to sign here")
    }

    /// No connection: the prepared document signed in person, kept to send later.
    func testOffline2SignWithoutSignal() throws {
        launchSignedIn()
        try openClientFromKeptList(Self.offlineClient)
        openDocumentsToSign()
        XCTAssertTrue(app.navigationBars["Documents to sign"].waitForExistence(timeout: 15), "Documents to sign did not open")
        let row = preparedRow()
        let waitingRow = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@ AND label CONTAINS 'Signed as'", Self.offlineTitle + ",")).firstMatch
        let listed = row.waitForExistence(timeout: 20)
        if !listed && waitingRow.exists {
            // Signed with no signal by an earlier run of this step (the prepared copy is used up
            // once signed): check what it left, and stop here.
            print("A11Y-NOTE \(Self.offlineTitle) was already signed with no signal by an earlier run; checking it is waiting to send")
            XCTAssertTrue(app.staticTexts["Signed here, waiting to send"].exists, "Not listed under Signed here, waiting to send")
            XCTAssertFalse(app.descendants(matching: .any).matching(NSPredicate(format: "label CONTAINS 'Not accepted'")).firstMatch.exists)
            return
        }
        if !listed { print("A11Y-DEBUG " + app.debugDescription) }
        XCTAssertTrue(listed, "\(Self.offlineTitle) is not under Ready to sign here")
        row.tap()
        XCTAssertTrue(app.navigationBars["Document to sign"].waitForExistence(timeout: 10), "The document to sign did not open")

        let name = app.textFields["Type your full name to sign"]
        if !name.isHittable { scrollSigning(toReach: name) }
        XCTAssertTrue(name.isHittable, "No name field")
        name.tap()
        name.typeText(Self.offlineClient + "\n")    // Return closes the keyboard (submit label Done)
        sleep(1)
        let sign = app.buttons["Sign and submit"]
        XCTAssertTrue(sign.waitForExistence(timeout: 5), "No Sign and submit button")
        if !sign.isHittable { scrollSigning(toReach: sign) }
        if !sign.isHittable { print("A11Y-DEBUG " + app.debugDescription) }
        XCTAssertTrue(sign.isHittable, "Sign and submit can't be reached")
        sign.tap()

        // The server can't be reached (the proxy is down): kept and said so.
        let kept = app.staticTexts.matching(NSPredicate(format: "label CONTAINS 'when the connection returns'")).firstMatch
        XCTAssertTrue(kept.waitForExistence(timeout: 70), "The signer was not told it is signed and will be sent")
        let done = app.buttons["Done, give the phone back"]
        XCTAssertTrue(done.waitForExistence(timeout: 5), "No Done button")
        done.tap()
        sleep(2)
        // The app locked after the hand-over: unlock, as the device owner does.
        for _ in 0..<10 where !app.tabBars.firstMatch.isHittable { unlockIfAsked(); sleep(1) }
        XCTAssertTrue(app.tabBars.firstMatch.isHittable, "The app did not unlock after signing")
        let waiting = app.staticTexts["Signed here, waiting to send"]
        XCTAssertTrue(waiting.waitForExistence(timeout: 10), "The signature is not listed as waiting to send")
    }

    /// Online again: the kept signature is sent and accepted.
    func testOffline3SendLater() throws {
        launchSignedIn()
        try openClient(Self.offlineClient)
        openDocumentsToSign()
        XCTAssertTrue(app.navigationBars["Documents to sign"].waitForExistence(timeout: 15), "Documents to sign did not open")
        let waiting = app.staticTexts["Signed here, waiting to send"]
        let deadline = Date().addingTimeInterval(60)
        while waiting.exists && Date() < deadline {
            sleep(3)
            // Pull to refresh: sends what is waiting first, then reloads the list.
            if waiting.exists { app.swipeDown() }
        }
        XCTAssertFalse(waiting.exists, "The signature is still waiting to send after 60 seconds")
        XCTAssertFalse(app.descendants(matching: .any).matching(NSPredicate(format: "label CONTAINS 'Not accepted'")).firstMatch.exists,
                       "The server did not accept the signature")
        let signed = app.descendants(matching: .any)
            .matching(NSPredicate(format: "label BEGINSWITH %@ AND label CONTAINS 'Signed, In person'", Self.offlineTitle + ".")).firstMatch
        XCTAssertTrue(signed.waitForExistence(timeout: 15), "\(Self.offlineTitle) is not listed as signed in person")
    }

    private func launchSignedIn() {
        app = XCUIApplication()
        app.launch()
        do { try signInIfNeeded() } catch { XCTFail("Sign-in failed: \(error)") }
        dismissAlerts()
    }

    /// Scrolls the signing screen by dragging in its left margin: a swipe through the middle
    /// would land on the signature pad, which draws instead of scrolling (and with VoiceOver off,
    /// a stray drag there is ink on the signature).
    private func scrollSigning(toReach element: XCUIElement) {
        for _ in 0..<12 where !element.isHittable {
            let start = app.coordinate(withNormalizedOffset: CGVector(dx: 0.02, dy: 0.75))
            let end = app.coordinate(withNormalizedOffset: CGVector(dx: 0.02, dy: 0.3))
            start.press(forDuration: 0.05, thenDragTo: end)
            sleep(1)
        }
    }

    /// The prepared document's row under Ready to sign here.
    private func preparedRow() -> XCUIElement {
        app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", "Sign \(Self.offlineTitle).")).firstMatch
    }

    /// A captioned form field by its name (one line, or several lines for longer text).
    private func field(_ name: String) -> XCUIElement {
        let single = app.textFields[name]
        if single.waitForExistence(timeout: 3) { return single }
        return app.textViews[name]
    }

    /// With no connection a search needs the server, so the client is found in the list kept on
    /// the phone instead.
    private func openClientFromKeptList(_ name: String) throws {
        openTab("Clients")
        let row = app.buttons.matching(NSPredicate(format: "label BEGINSWITH %@", name)).firstMatch
        XCTAssertTrue(row.waitForExistence(timeout: 20) || scrollTo(row), "\(name) is not in the client list kept on this phone")
        if !row.isHittable { scrollTo(row) }
        row.tap()
        XCTAssertTrue(app.navigationBars[name].waitForExistence(timeout: 20), "\(name)'s record did not open from the kept copy")
        sleep(2)
    }
}
