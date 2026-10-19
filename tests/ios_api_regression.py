"""Run the production API queue logic on macOS with isolated storage/network adapters."""
from pathlib import Path
import re
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]

# Static checks on public/api.php for rules the app relies on (no database needed).
api_php = (root / 'public/api.php').read_text()


def api_case(name):
    """Source of one `case 'name':` block of api.php's action switch."""
    start = api_php.index("\n    case '%s':" % name)
    following = re.search(r"\n    (?:case '|default:)", api_php[start + 1:])
    return api_php[start:start + 1 + following.start()] if following else api_php[start:]


def static_check(ok, label):
    if not ok:
        raise SystemExit('FAIL: ' + label)
    print('PASS: ' + label)


no_rate = re.compile(r"hourly_rate.*?<= 0\)\s*\{?\s*fail\('This track has no hourly rate\.[^']*', 422\)", re.S)
choices = api_case('client_choices')
static_check(re.search(r"\$choicesRefOnly = !can\('clients'\) && !can\('schedule'\) && !can\('equipment'\) && !can\('procurement'\)", choices)
             and re.search(r"if \(\$choicesRefOnly\) \{.*?r\.status = 'Converted'", choices, re.S),
             'client_choices gives a referrals-only account only clients converted from referrals')
static_check(re.search(r"if \(\$cid <= 0\) fail\([^;]*, 422\);\s*api_require_client\(\$cid, \$userId\)", api_case('ai_generate_note')),
             'ai_generate_note refuses a missing client_id (needed to redact that client) with a 422')
static_check(no_rate.search(api_case('log_hours')), 'log_hours refuses a track with no hourly rate with a 422')
static_check(no_rate.search(api_case('complete_session')), 'complete_session refuses a track with no hourly rate with a 422')
too_big = re.search(r"\(int\)\(\$_SERVER\['CONTENT_LENGTH'\] \?\? 0\) > \$postMax\) \{\s*fail\([^;]*, 413\)", api_php)
static_check(too_big and too_big.start() < api_php.index("file_get_contents('php://input')"),
             'api.php answers 413 when the request is larger than post_max_size, before reading it')
draft = api_case('ai_draft')
static_check("'truncated' => !empty($res['truncated'])" in draft and not re.search(r",\s*900\s*,", draft),
             'ai_draft reports a truncated draft and sizes its output budget instead of a fixed 900 tokens')
log_hours = api_case('log_hours')
static_check('$sigPath ? ($signedNameIn' not in log_hours,
             'log_hours keeps a typed signed name even when no signature is drawn')
static_check(re.search(r"fail\('RSA category this visit: .*?is not offered on this track\..*?, 422\)", log_hours, re.S),
             'log_hours refuses an RSA category the track does not offer with a 422, naming the field')
static_check("fail('Not found.', 404)" not in api_php,
             'api.php never answers a bare "Not found." 404 (the message names what was not found)')
static_check('### ' not in api_case('ai_kb'),
             'ai_kb sends article context without markdown headings')
source = (root / 'ios/Sources/API.swift').read_text()
source = source.split('extension Color {')[0]
source = source.replace('import SwiftUI', '').replace(': ObservableObject', '')
source = source.replace('@Published ', '')
source = re.sub(r'@AppStorage\("[^" ]+"\) ', '', source)
source = source.replace('URLSession.shared', 'testSession')
a = source.index('    private var queueURL: URL {')
b = source.index('    private func loadQueue()', a)
source = source[:a] + '    private var queueURL: URL { testQueueURL }\n' + source[b:]
# Do not inspect or migrate any real Application Support or Documents files.
source = source.replace('FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]', 'testQueueURL.deletingLastPathComponent().appendingPathComponent("legacy")')
# Nor any real Application Support folder (the lost-phone wipe removes Drafts and OfflineCache there).
source = source.replace('FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]', 'testQueueURL.deletingLastPathComponent().appendingPathComponent("support")')
source = source.replace('UserDefaults.standard', 'testDefaults')
prefix = r'''
import Foundation
let testDefaults = UserDefaults(suiteName: "cms-queue-tests-" + UUID().uuidString)!
let testQueueURL = URL(fileURLWithPath: ProcessInfo.processInfo.environment["CMS_QUEUE_TEST_PATH"]!)
enum Keychain {
    static func save(_ value: String, for key: String) {}
    static func load(_ key: String) -> String { "" }
}
final class StubProtocol: URLProtocol, @unchecked Sendable {
    static var status = 200
    static var count = 0
    static var json: String?            // a reply body other than the default ones
    static var urls: [String] = []      // every request's address, in order
    static var offline = false          // fail every request as with no signal
    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
    override func startLoading() {
        Self.count += 1
        Self.urls.append(request.url?.absoluteString ?? "")
        if Self.offline {
            client?.urlProtocol(self, didFailWithError: URLError(.notConnectedToInternet))
            return
        }
        let response = HTTPURLResponse(url: request.url!, statusCode: Self.status, httpVersion: nil, headerFields: nil)!
        client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
        let json = Self.json ?? (Self.status == 200 ? #"{"ok":true}"# : #"{"ok":false,"error":"Rejected for test"}"#)
        client?.urlProtocol(self, didLoad: Data(json.utf8))
        client?.urlProtocolDidFinishLoading(self)
    }
    override func stopLoading() {}
}
let testSession: URLSession = {
    let config = URLSessionConfiguration.ephemeral
    config.protocolClasses = [StubProtocol.self]
    return URLSession(configuration: config)
}()
'''
# The voice assistant's reading of a spoken answer to "shall I save this?", taken
# unchanged from VoiceAssistant.swift into a stand-in type (the real one needs the
# speech and audio frameworks), so it is checked as the app ships it.
voice = (root / 'ios/Sources/VoiceAssistant.swift').read_text()
c = voice.index('    nonisolated static func confirmAnswer(_ utterance: String) -> ConfirmAnswer {')
d = voice.index('\n    }\n', c) + len('\n    }\n')
# Stand-ins for the offline read cache and local notifications (API.swift only calls these
# members; the real types need UIKit and UserNotifications).
prefix += '''
enum OfflineCache {
    static func save(_ data: Data, key: String, owner: Int) {}
    static func load(key: String, owner: Int) -> (data: Data, savedAt: Date)? { nil }
    static func removeAll(owner: Int) {}
    static func remove(key: String, owner: Int) {}
}
final class LocalNotifications { static let shared = LocalNotifications(); func clearScheduled() {} }
'''
prefix += ('enum SpeechController {\n    enum ConfirmAnswer: Equatable { case yes, no, unclear }\n'
           + voice[c:d].replace('nonisolated static func', 'static func', 1) + '}\n')
suffix = r'''
@main struct QueueTests {
    @MainActor static func main() async throws {
        func check(_ ok: Bool, _ label: String) {
            guard ok else { fatalError(label) }
            print("PASS: " + label)
        }
        // A spoken answer counts as yes only when it is a short yes and nothing else: a
        // correction, a question, or our own question heard back is asked again.
        // The organisation's address (4.6.0): https only, never another host than the one typed.
        let addr: (String) -> String? = { API.apiURL(fromAddress: $0)?.absoluteString }
        check(addr("cms.example.org") == "https://cms.example.org/api.php" && addr("example.org/cms") == "https://example.org/cms/api.php"
              && addr("HTTPS://Example.ORG/cms/login.php") == "https://example.org/cms/api.php" && addr("example.org/cms/API.PHP") == "https://example.org/cms/api.php"
              && addr("example.org:8443") == "https://example.org:8443/api.php", "An organisation's address becomes its https API address")
        // 4.28.0: https's own port written out is the same server as no port.
        check(addr("cms.example.org:443") == "https://cms.example.org/api.php" && addr("https://CMS.example.org:443/cms/") == "https://cms.example.org/cms/api.php",
              "\":443\" typed with the address gives the same server address as without it")
        for bad in ["http://cms.example.org", "ftp://x.org", "https:/x.org", "http//x.org", "good.org@evil.com", "x.org\\@evil.com", "", "cms example org", "localhostish"] {
            check(addr(bad) == nil, "\"\(bad)\" is refused as an organisation's address")
        }
        for said in ["OK, make it three", "Correct the time", "Yes, Tuesday instead", "You said: Yes", "Say yes to save", "Is it okay?"] {
            check(SpeechController.confirmAnswer(said) == .unclear, "\"\(said)\" is not taken as a yes to save")
        }
        for said in ["Yes please", "Okay, go ahead", "That's correct"] {
            check(SpeechController.confirmAnswer(said) == .yes, "\"\(said)\" confirms the save")
        }
        for said in ["No, don't save it", "That's not correct"] {
            check(SpeechController.confirmAnswer(said) == .no, "\"\(said)\" cancels the save")
        }
        let api = API()
        let body: [String: Any] = ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic test"]
        do { try api.enqueue("add_note", body: body, label: "Test"); fatalError("Unverified enqueue accepted") }
        catch { check(true, "Offline writes require a verified account") }
        api.userId = 1; api.token = "test-one"
        try api.enqueue("add_note", body: body, label: "Test")
        try api.enqueue("add_note", body: body, label: "Test")
        check(api.pendingSync == 1, "Duplicate enqueue retains one stable entry")
        api.syncNoticeDismissed = true
        try api.enqueue("add_note", body: body, label: "Test")
        check(!api.syncNoticeDismissed, "A new offline save restores the dismissed sync notice")
        StubProtocol.status = 403
        await api.flushQueue()
        check(api.pendingSync == 1 && api.syncError != nil, "Rejected entries remain stored with a visible error")
        let attempts = StubProtocol.count
        api.userId = 2; api.token = "test-two"
        await api.flushQueue()
        check(StubProtocol.count == attempts, "Another account cannot replay the original user's entries")
        check(api.pendingSync == 0, "Another account's entries are not counted as pending for this one")
        api.userId = 1; api.token = "test-one"
        StubProtocol.status = 200
        await api.flushQueue()
        check(api.pendingSync == 0, "Confirmed success removes the queued entry")
        let legacy = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Legacy", owner: 0)
        var legacyJSON = try JSONSerialization.jsonObject(with: JSONEncoder().encode([legacy])) as! [[String: Any]]
        legacyJSON[0].removeValue(forKey: "owner")
        try JSONSerialization.data(withJSONObject: legacyJSON).write(to: testQueueURL)
        let beforeLegacy = StubProtocol.count
        await api.flushQueue()
        // An entry with no owner is never this account's to upload, so it is not
        // counted as pending; it stays in the queue file and is reported.
        let storedLegacy = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [[String: Any]]
        check(storedLegacy.count == 1 && api.pendingSync == 0 && api.syncError != nil && StubProtocol.count == beforeLegacy, "Unknown-owner entries remain stored without being uploaded")
        try Data("broken queue".utf8).write(to: testQueueURL)
        do { try api.enqueue("add_note", body: body, label: "Test"); fatalError("Corrupt queue overwritten") }
        catch { check(try String(contentsOf: testQueueURL, encoding: .utf8) == "broken queue", "Corrupt queue is preserved instead of silently replaced") }
        // A write that fails (the queue's folder is read-only, so the atomic write cannot
        // create its file) is returned to the form, and the stored queue is left as it was.
        // The queue reads back fine, so only the write can fail here.
        try Data("[]".utf8).write(to: testQueueURL)
        let queueFolder = testQueueURL.deletingLastPathComponent().path
        let pendingBeforeWrite = api.pendingSync
        try FileManager.default.setAttributes([.posixPermissions: 0o500], ofItemAtPath: queueFolder)
        var writeRefused = false
        do { try api.enqueue("add_note", body: body, label: "Test") } catch { writeRefused = true }
        try FileManager.default.setAttributes([.posixPermissions: 0o700], ofItemAtPath: queueFolder)
        let storedAfterWrite = try String(contentsOf: testQueueURL, encoding: .utf8)
        if getuid() == 0 {
            print("SKIPPED: a failed queue write cannot be simulated as root")
        } else {
            check(writeRefused && storedAfterWrite == "[]" && api.pendingSync == pendingBeforeWrite,
                  "A failed queue write is returned to the form and leaves the stored queue unchanged")
        }
        api.aiEnabled = true; api.aiFeatures = ["draft"]
        api.syncNoticeDismissed = true
        api.clearIdentity()
        check(!api.syncNoticeDismissed, "Signing out resets notice dismissal for the next account")
        check(api.userId == 0 && api.token.isEmpty && !api.aiEnabled && api.aiFeatures.isEmpty, "Sign-out clears identity and cached AI permissions")

        // A refused upload keeps its reason; the notice names the entry and is not re-set.
        try FileManager.default.removeItem(at: testQueueURL)
        let refusedId = UUID().uuidString
        api.userId = 2; api.token = "test-two"
        try api.enqueue("add_note", body: ["idem_key": refusedId, "client_id": 1, "subject": "Synthetic other"], label: "Other account note")
        api.userId = 1; api.token = "test-one"
        try api.enqueue("add_note", body: ["idem_key": refusedId, "client_id": 1, "subject": "Synthetic refused"], label: "Refused note")
        try api.enqueue("add_note", body: ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic second"], label: "Second note")
        StubProtocol.status = 403
        await api.flushQueue()
        let refusedEntries = try api.ownQueuedEntries()
        check(refusedEntries.count == 2 && refusedEntries.allSatisfy { $0.lastError == "Rejected for test" }, "A refused upload keeps the server's reason with the entry")
        check(api.syncError?.contains("Refused note") == true, "The sync notice names the refused entry")
        let firstNotice = api.syncError
        await api.flushQueue()
        let afterSecondFlush = try api.ownQueuedEntries()
        check(api.syncError == firstNotice && afterSecondFlush.count == 2, "The same refusal twice leaves the sync notice unchanged")
        try api.discardQueued(UUID(uuidString: refusedId)!)
        let afterDiscard = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [[String: Any]]
        let ownAfterDiscard = try api.ownQueuedEntries()
        check(afterDiscard.count == 2 && ownAfterDiscard.map(\.label) == ["Second note"]
              && afterDiscard.contains { ($0["label"] as? String) == "Other account note" },
              "Discarding an entry removes only the signed-in account's entry with that id")
        check(api.syncError?.contains("Second note") == true && api.syncError?.contains("Refused note") == false, "The sync notice follows the discard")

        // A refused session (401) asks for sign-in again without losing the account or its queue.
        StubProtocol.status = 401
        await api.flushQueue()
        let afterRefusedSession = try api.ownQueuedEntries()
        check(api.reauthRequired && api.userId == 1 && api.token == "test-one" && afterRefusedSession.count == 1,
              "A 401 during upload asks for sign-in again and keeps the account and its offline entries")
        // No await follows: the refresh-and-upload task started by a same-account sign-in never runs here.
        api.finishSignIn(token: "test-one-again", userId: 1, name: "Test")
        let afterSameSignIn = try api.ownQueuedEntries()
        check(!api.reauthRequired && api.userId == 1 && api.token == "test-one-again" && afterSameSignIn.count == 1,
              "The same account signing in again clears the sign-in request and keeps its offline entries")
        api.reauthRequired = true
        api.finishSignIn(token: "test-three", userId: 3, name: "Other")
        let afterSwitch = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [[String: Any]]
        check(!api.reauthRequired && api.userId == 3 && api.token == "test-three" && api.syncError == nil
              && api.pendingSync == 0 && afterSwitch.count == 2,
              "Another account signing in starts afresh and leaves the first account's entries stored")

        // 4.27.0: an old offline entry is sent first; only what is still here afterwards (refused,
        // or another account's) is removed once over 30 days old, and the notice names it.
        let fortyDays = Date().addingTimeInterval(-40 * 86400)
        let oldSent = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Old sendable note",
                                      owner: 3, savedAt: fortyDays)
        try JSONEncoder().encode([oldSent]).write(to: testQueueURL)
        StubProtocol.status = 200
        StubProtocol.urls = []
        await api.flushQueue()
        check(try api.ownQueuedEntries().isEmpty && StubProtocol.urls.filter { $0.contains("action=add_note") }.count == 1 && api.syncError == nil,
              "An offline entry over 30 days old is still sent when the server can be reached, not dropped first")
        let oldRefused = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Old note",
                                         owner: 3, savedAt: fortyDays)
        let otherOld = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Someone else's note",
                                       owner: 9, savedAt: fortyDays)
        try JSONEncoder().encode([oldRefused, otherOld]).write(to: testQueueURL)
        StubProtocol.status = 403
        StubProtocol.urls = []
        await api.flushQueue()
        let leftAfterExpiry = try JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as! [Any]
        check(leftAfterExpiry.isEmpty && StubProtocol.urls.filter { $0.contains("action=add_note") }.count == 1
              && api.syncError?.contains("Old note") == true && api.syncError?.contains("30 days") == true,
              "An offline entry over 30 days old that is still not accepted is removed after the try, and the notice says so")

        // 4.28.0: with no signal, a server fault (5xx), a 429 or a refused redirect the server was
        // never reached, so an old entry is kept, not expired.
        for (label, status, offline) in [("no signal", 200, true), ("a 503", 503, false), ("a 429", 429, false), ("a refused redirect", 302, false)] {
            let oldUnsent = API.PendingCall(action: "add_note", body: try JSONSerialization.data(withJSONObject: body), label: "Old unsent note",
                                            owner: 3, savedAt: fortyDays)
            try JSONEncoder().encode([oldUnsent]).write(to: testQueueURL)
            StubProtocol.status = status
            StubProtocol.offline = offline
            StubProtocol.urls = []
            api.syncNoticeDismissed = true
            await api.flushQueue()
            StubProtocol.offline = false
            let kept = try api.ownQueuedEntries()
            check(kept.map(\.label) == ["Old unsent note"] && StubProtocol.urls.filter { $0.contains("action=add_note") }.count == 1
                  && api.syncError?.contains("30 days") != true,
                  "An offline entry over 30 days old is kept after an upload that got \(label) (the server was not reached)")
        }
        try FileManager.default.removeItem(at: testQueueURL)
        StubProtocol.status = 200

        // 4.27.0: a redirect is followed only to the same https host and port (none written is 443),
        // and a refused one is explained instead of "Unexpected response from the server."
        check(RedirectGuard.port(of: URL(string: "https://a.example.org/x")!) == RedirectGuard.port(of: URL(string: "https://a.example.org:443/y")!)
              && RedirectGuard.port(of: URL(string: "https://a.example.org:8443/x")!) != 443,
              "A redirect's port written or not counts as the same when it is https's own")

        // 4.27.0: changing the server address keeps the offline entries until a sign-in to a
        // really different server works; the same server however typed loses nothing.
        try api.enqueue("add_note", body: ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic kept"], label: "Kept note")
        check(api.entriesLostBySigningIn(to: API.apiURL(fromAddress: "HTTPS://Example.INVALID/")!) == 0,
              "The same server typed differently would remove no offline entries")
        api.switchServer(to: "other.example.org")
        check(api.token.isEmpty && FileManager.default.fileExists(atPath: testQueueURL.path)
              && api.entriesLostBySigningIn(to: API.apiURL(fromAddress: "other.example.org")!) == 1,
              "Changing the server address ends the session and keeps the offline entries before sign-in")
        api.finishSignIn(token: "test-four", userId: 4, name: "New server")
        check(((try? JSONSerialization.jsonObject(with: Data(contentsOf: testQueueURL)) as? [Any]) ?? []).isEmpty,
              "Signing in to a different server removes the previous server's offline entries")

        StubProtocol.status = 302
        do { let _: OKResponse = try await api.call("me"); fatalError("A refused redirect was taken for an answer") }
        catch { check(error.localizedDescription == API.redirectRefused, "A refused redirect says the server's address may have changed") }
        StubProtocol.status = 200

        // 4.27.0: a 401 that says this phone was reported lost wipes it during an upload too,
        // and the server is told once the wipe is done.
        try api.enqueue("add_note", body: ["idem_key": UUID().uuidString, "client_id": 1, "subject": "Synthetic wiped"], label: "Wiped note")
        StubProtocol.status = 401
        StubProtocol.json = #"{"ok":false,"wipe":true,"error":"This phone was reported lost."}"#
        StubProtocol.urls = []
        await api.flushQueue()
        try await Task.sleep(nanoseconds: 500_000_000)
        StubProtocol.json = nil
        check(api.userId == 0 && api.token.isEmpty && !api.reauthRequired && !FileManager.default.fileExists(atPath: testQueueURL.path)
              && api.sessionNotice?.contains("reported lost") == true,
              "A wipe answer during an upload removes the offline entries and signs out, instead of asking to sign in again")
        check(StubProtocol.urls.contains { $0.contains("action=wipe_done") && $0.hasPrefix("https://other.example.org/") },
              "After a wipe the app tells the server (wipe_done)")
    }
}
'''
with tempfile.TemporaryDirectory(prefix='cms-swift-tests-') as tmp:
    path = Path(tmp)
    swift = path / 'QueueTests.swift'
    swift.write_text(prefix + source + suffix)
    subprocess.run(['swiftc', '-module-cache-path', str(path / 'modules'), '-parse-as-library', str(swift), '-o', str(path / 'tests')], check=True)
    import os
    # The queue sits in a folder of its own, which the test makes read-only for a moment.
    (path / 'queue').mkdir()
    subprocess.run([str(path / 'tests')], env={**os.environ, 'CMS_QUEUE_TEST_PATH': str(path / 'queue' / 'queue.json')}, check=True)
