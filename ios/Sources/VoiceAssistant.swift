import SwiftUI
import Speech
import AVFoundation
import Combine

/// Native voice assistant: speech recognition (SFSpeechRecognizer — on this device whenever
/// the recognizer supports it, otherwise through Apple's servers) + spoken replies
/// (AVSpeechSynthesizer), driving the shared server interpreter
/// (api.php?action=voice_command → inc/voice.php, same logic as the website).
/// Hands-free: in conversation mode it re-listens after each spoken reply, and
/// after a confirmation question it listens for "yes"/"no". Every answer can also be given
/// with the on-screen buttons or typed, and a confirmation never runs out: it waits until it
/// is answered or cancelled.
@MainActor
final class SpeechController: NSObject, ObservableObject {
    enum Phase: Equatable { case idle, listening, thinking, speaking, confirming }
    enum ConfirmAnswer: Equatable { case yes, no, unclear }

    @Published var phase: Phase = .idle {
        didSet {
            guard phase != oldValue else { return }
            // The only spoken cue is "Working", posted once the microphone has closed.
            // Listening is cued with a haptic once the engine has started (see startListening):
            // a VoiceOver announcement there is played into the open microphone, and
            // "…yes or no" would answer the confirmation by itself.
            if phase == .thinking {
                UIAccessibility.post(notification: .announcement, argument: "Working")
                if UIAccessibility.isVoiceOverRunning { workingCueAt = Date() }
            }
            // Hands-free means nobody touches the screen, and the microphone and the voice do
            // not reset Auto-Lock: keep the phone awake while listening, thinking or speaking
            // (locking sends the app to the background, which stops everything), and hand
            // Auto-Lock back as soon as the assistant is idle.
            UIApplication.shared.isIdleTimerDisabled = phase != .idle
            // While it is not idle, a watch stops it once nobody has tapped for a while.
            if phase == .idle {
                unattendedWatch?.invalidate()
                unattendedWatch = nil
            } else if unattendedWatch == nil {
                unattendedWatch = Timer.scheduledTimer(withTimeInterval: 15, repeats: true) { [weak self] _ in
                    Task { @MainActor in self?.stopIfUnattended() }
                }
            }
        }
    }
    @Published var partial = ""                 // live transcript while listening
    @Published var log: [VoiceTurn] = []        // conversation history shown on screen
    @Published var conversation = false         // hands-free loop
    @Published var errorText: String?
    @Published var authDenied = false
    /// Set when a reply wants the app to open a client record (user taps to go).
    @Published var openClientId: Int?
    @Published var openClientName: String?
    @Published var searchQuery: String?
    /// Set when a reply wants to open a section the app has a screen for (user taps to go).
    @Published var openSection: VoiceSection?
    /// The client (and track) on the screen the assistant was opened from, so "her latest
    /// note" means that client. Sent with every request and told to the interpreter as the
    /// first turn of the conversation.
    private(set) var context: VoiceContext?
    /// A write is waiting for yes or no (the Accept / Decline buttons are shown).
    @Published private(set) var awaitingAnswer = false
    /// The waiting write is the retry of one whose reply was lost.
    @Published private(set) var answerIsRetry = false

    /// Settings (shown under Listening options). Seconds of silence after speech before what was
    /// heard is sent; 0 = only when the microphone button is tapped.
    nonisolated static let pauseKey = "voicePauseSeconds"
    /// With VoiceOver on, replies go through VoiceOver (so they also reach a braille display)
    /// instead of the assistant's own voice.
    nonisolated static let viaVoiceOverKey = "voiceRepliesViaVoiceOver"
    /// The microphone opens by itself when the assistant's screen opens (on unless turned off).
    nonisolated static let autoListenKey = "voiceAutoListen"
    private var pauseSeconds: Double {
        (UserDefaults.standard.object(forKey: Self.pauseKey) as? Double) ?? 1.8
    }
    private var repliesViaVoiceOver: Bool {
        UIAccessibility.isVoiceOverRunning && ((UserDefaults.standard.object(forKey: Self.viaVoiceOverKey) as? Bool) ?? true)
    }

    /// A write the server asked us to confirm, and how many unclear answers it has had.
    private struct PendingConfirm {
        let op: String
        let params: [String: Any]
        /// Sent with a retry-safe write (log_hours, complete_session) on every try of this
        /// confirmation, so a retry after a lost reply is recognised by the server instead of
        /// logging the hours twice.
        var idem = UUID().uuidString.lowercased()
        var unclearAnswers = 0
        /// The offer to retry a write whose outcome is unknown (it may already be saved).
        var isRetry = false
    }

    /// What is said when a pending confirmation is dropped. After a lost reply the write may
    /// already have been saved, so "nothing was saved" would not be true.
    private func droppedText(_ confirm: PendingConfirm?) -> String {
        confirm?.isRetry == true
            ? "Not retried. It may already have been saved, so check before trying again."
            : "Cancelled. Nothing was saved."
    }

    private let recognizer = SFSpeechRecognizer(locale: Locale(identifier: "en-US"))
    // A fresh engine per listen: its input format is read after the session's category and
    // route are final, so a stale cached format can't be handed to installTap.
    private var audioEngine: AVAudioEngine?
    private var tapInstalled = false
    private var request: SFSpeechAudioBufferRecognitionRequest?
    private var task: SFSpeechRecognitionTask?
    private let synth = AVSpeechSynthesizer()
    private var speaking: ObjectIdentifier?     // the utterance whose end drives the next step
    private var silenceTimer: Timer?
    /// The phone is kept awake while the assistant is busy (see phase), and nothing else ends a
    /// listen in "Until I tap the microphone", nor the hands-free loop while people talk nearby.
    /// So it stops once this long has passed since the last tap, typed request or answer button,
    /// and an unattended phone can sleep and the app lock engage.
    private static let unattendedLimit: TimeInterval = 180
    private var lastTouchAt = Date()
    private var unattendedWatch: Timer?
    private var heardSpeech = false
    private var pendingConfirm: PendingConfirm? {
        didSet {
            if awaitingAnswer != (pendingConfirm != nil) { awaitingAnswer = pendingConfirm != nil }
            if answerIsRetry != (pendingConfirm?.isRetry == true) { answerIsRetry = pendingConfirm?.isRetry == true }
        }
    }
    /// The last request or answer was typed or tapped: the microphone is not opened by itself
    /// for what follows (a confirmation waits for the buttons, typing or a tap of the microphone).
    private var typedTurn = false
    /// A reply being read by VoiceOver (repliesViaVoiceOver): its text, and a token that the
    /// end of the announcement (or the fallback timer) must still match to move on.
    private var announcing: UUID?
    private var announcedText = ""
    private var announceWatch: AnyCancellable?
    /// A confirmed write whose reply was lost (it may have been saved). Kept until a definite
    /// answer, so the same write said again goes out with the same key.
    private var uncertainWrite: PendingConfirm?
    private var endLoopAfterSpeaking = false
    private var workingCueAt: Date?
    private var interruptionWatch: AnyCancellable?
    private var routeWatch: AnyCancellable?
    private let listenCue = UIImpactFeedbackGenerator(style: .medium)
    private let confirmCue = UINotificationFeedbackGenerator()
    private weak var api: API?

    /// Bumped whenever a listen ends or everything is cancelled. Recognition callbacks, the
    /// silence timer and network replies carry the value they started with and are dropped
    /// when it no longer matches, so nothing from a finished or cancelled cycle can act.
    private var generation = 0

    // History sent to the server for context (role/text), capped server-side.
    private var history: [[String: String]] = []

    override init() {
        super.init()
        synth.delegate = self
        // A phone call, Siri or an alarm takes the audio session: stop cleanly instead of
        // leaving the phase stuck on "speaking" or a dead microphone "listening".
        interruptionWatch = NotificationCenter.default
            .publisher(for: AVAudioSession.interruptionNotification)
            .sink { [weak self] note in
                guard let raw = note.userInfo?[AVAudioSessionInterruptionTypeKey] as? UInt,
                      AVAudioSession.InterruptionType(rawValue: raw) == .began else { return }
                Task { @MainActor in
                    guard let self, self.phase != .idle else { return }
                    self.cancelAll()
                }
            }
        // Headphones unplugged or earbuds dropped: iOS moves the audio to the phone's speaker,
        // where a note being read aloud would be heard by everyone nearby. Stop instead.
        routeWatch = NotificationCenter.default
            .publisher(for: AVAudioSession.routeChangeNotification)
            .sink { [weak self] note in
                guard let raw = note.userInfo?[AVAudioSessionRouteChangeReasonKey] as? UInt,
                      AVAudioSession.RouteChangeReason(rawValue: raw) == .oldDeviceUnavailable else { return }
                Task { @MainActor in
                    guard let self, self.phase != .idle else { return }
                    let outputs = AVAudioSession.sharedInstance().currentRoute.outputs
                    guard outputs.contains(where: { $0.portType == .builtInSpeaker || $0.portType == .builtInReceiver }) else { return }
                    // One notice: the headphones, and a confirmation that was waiting.
                    let dropped = self.pendingConfirm
                    self.pendingConfirm = nil
                    self.cancelAll()
                    self.noteAside("Stopped because the headphones disconnected." + (dropped != nil ? " " + self.droppedText(dropped) : ""))
                }
            }
        // A reply read by VoiceOver has finished: only then does the microphone open again.
        announceWatch = NotificationCenter.default
            .publisher(for: UIAccessibility.announcementDidFinishNotification)
            .sink { [weak self] note in
                let said = note.userInfo?[UIAccessibility.announcementStringValueUserInfoKey] as? String
                Task { @MainActor in
                    guard let self, let id = self.announcing, said == nil || said == self.announcedText else { return }
                    self.announcementEnded(id, timedOut: (note.userInfo?[UIAccessibility.announcementWasSuccessfulUserInfoKey] as? Bool) != true)
                }
            }
    }

    func attach(_ api: API, context: VoiceContext? = nil) {
        self.api = api
        guard let context, self.context == nil else { return }
        self.context = context
        // Said once, as the person's own first words, so the interpreter resolves "her",
        // "this client" and "this track" to what is on screen.
        remember(role: "user", text: context.spoken)
    }

    // MARK: - Permissions

    func requestAccess() async -> Bool {
        let status = await withCheckedContinuation { (c: CheckedContinuation<SFSpeechRecognizerAuthorizationStatus, Never>) in
            SFSpeechRecognizer.requestAuthorization { c.resume(returning: $0) }
        }
        if status != .authorized {
            authDenied = true
            setError("Speech Recognition is off. Turn it on in Settings › \(appDisplayName) › Speech Recognition, then try again.")
            return false
        }
        let micOK = await withCheckedContinuation { (c: CheckedContinuation<Bool, Never>) in
            AVAudioSession.sharedInstance().requestRecordPermission { ok in c.resume(returning: ok) }
        }
        if !micOK {
            authDenied = true
            setError("Microphone access is off. Turn it on in Settings › \(appDisplayName) › Microphone, then try again.")
            return false
        }
        authDenied = false
        if !(recognizer?.isAvailable ?? false) {
            setError("Speech recognition isn't available right now — it may need a network connection. Try again in a moment.")
            return false
        }
        // An earlier problem's banner stays until the microphone has actually opened
        // (startListening): cleared here, a repeat of the same failure would be silent.
        return true
    }

    /// One banner for every problem. ErrorBanner speaks when it appears or its text changes;
    /// tapping again into the same problem would be silent, so that repeat is announced here.
    private func setError(_ message: String) {
        if errorText == message { UIAccessibility.post(notification: .announcement, argument: message) }
        errorText = message
    }

    // MARK: - Listening

    /// The big button: start, finish, or — while a reply is being spoken — cut it short and listen.
    func micTapped() async {
        typedTurn = false
        lastTouchAt = Date()
        switch phase {
        case .listening, .confirming:
            finishListening(submit: true)
        case .speaking:
            startListening()            // stops the speech first; a pending yes/no stays pending
        case .thinking:
            break
        case .idle:
            let gen = generation
            // Ask for permission (if needed), then start — unless something cancelled meanwhile.
            guard await requestAccess(), gen == generation, phase == .idle else { return }
            startListening()
        }
    }

    func startListening() {
        // .speaking is allowed for the hands-free re-listen (started from the synthesizer's
        // didFinish, also after a confirmation question) and for interrupting a reply.
        guard phase == .idle || phase == .speaking || phase == .confirming else { return }
        stopSpeaking()
        stopAudio()
        let gen = generation
        partial = ""; heardSpeech = false
        // A new listen supersedes whatever the reply it interrupted meant to do afterwards
        // (an interrupted "Cancelled" reply used to end the hands-free loop one turn later).
        endLoopAfterSpeaking = false
        guard let recognizer, recognizer.isAvailable else {
            failToListen("Speech recognition isn't available right now — it may need a network connection. Try again in a moment.")
            return
        }
        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playAndRecord, mode: .spokenAudio,
                                    options: [.duckOthers, .defaultToSpeaker, .allowBluetoothHFP])
            try? session.setAllowHapticsAndSystemSoundsDuringRecording(true)
            try session.setActive(true)

            let req = SFSpeechAudioBufferRecognitionRequest()
            req.shouldReportPartialResults = true
            req.addsPunctuation = true
            // Keep the audio (client names included) on the phone whenever the recognizer can.
            if recognizer.supportsOnDeviceRecognition { req.requiresOnDeviceRecognition = true }

            let engine = AVAudioEngine()
            audioEngine = engine
            let input = engine.inputNode
            let format = input.outputFormat(forBus: 0)
            // A 0-channel / 0-rate input means there's no usable microphone
            // (common on the Simulator) — recognition would capture nothing.
            if format.channelCount == 0 || format.sampleRate == 0 {
                failToListen("No microphone input is available right now.")
                return
            }
            // Capture the request directly so the audio thread never reaches
            // through the main-actor-isolated property.
            input.installTap(onBus: 0, bufferSize: 1024, format: format) { [req] buffer, _ in
                req.append(buffer)
            }
            tapInstalled = true
            engine.prepare()
            try engine.start()
            request = req
            // The microphone is open: only now is an earlier problem's banner cleared and the
            // turn-taking cue played — a haptic, never speech (VoiceOver's voice would be heard
            // by the recognizer). A failure above leaves the banner, so a repeat is announced,
            // and never follows a "listening" cue.
            errorText = nil
            if pendingConfirm != nil { confirmCue.notificationOccurred(.warning) } else { listenCue.impactOccurred() }
            // "Listening for your answer" after a confirmation question; otherwise a normal listen.
            phase = pendingConfirm != nil ? .confirming : .listening

            task = recognizer.recognitionTask(with: req) { [weak self] result, error in
                let text = result?.bestTranscription.formattedString
                let isFinal = result?.isFinal ?? false
                let failed = error != nil
                Task { @MainActor in
                    // Callbacks keep arriving after a listen is stopped or cancelled (the
                    // cancellation itself reports an error): only the live listen may act.
                    guard let self, gen == self.generation else { return }
                    if let text {
                        self.partial = text
                        if !text.isEmpty { self.heardSpeech = true }
                        self.resetSilenceTimer(gen)
                        if isFinal { self.finishListening(submit: true); return }
                    }
                    if failed {
                        if self.partial.isEmpty {
                            self.endedWithNothing("I couldn't make that out. Tap the microphone to try again.")
                        } else {
                            self.finishListening(submit: true)
                        }
                    }
                }
            }
            resetSilenceTimer(gen)
        } catch {
            failToListen("Couldn't start the microphone: \(error.localizedDescription)")
        }
    }

    /// The microphone could not be opened. A confirmation waiting on it is dropped, not left armed.
    private func failToListen(_ message: String) {
        stopAudio()
        var text = message
        if pendingConfirm != nil {
            // The confirmation keeps waiting: the buttons or typing can still answer it.
            text += " Nothing is saved yet. Choose Accept and save, or Decline."
        }
        endLoopAfterSpeaking = false
        phase = .idle
        setError(text)
        releaseAudioSession()
    }

    /// End-of-speech detection. Gives a long window to START talking, then a trailing-silence
    /// cutoff once speech has begun, of the length chosen under Listening options (1.8 seconds
    /// unless changed). "Until I tap" sets no timer: the listen ends with the microphone button.
    private func resetSilenceTimer(_ gen: Int) {
        silenceTimer?.invalidate()
        silenceTimer = nil
        let pause = pauseSeconds
        guard pause > 0 else { return }
        let interval: TimeInterval = heardSpeech ? pause : max(7.0, pause * 3)
        silenceTimer = Timer.scheduledTimer(withTimeInterval: interval, repeats: false) { [weak self] _ in
            Task { @MainActor in
                guard let self, gen == self.generation else { return }
                if self.heardSpeech {
                    self.finishListening(submit: true)
                } else {
                    // Listened the full window and heard nothing audible.
                    self.endedWithNothing("I didn't hear anything. Tap the microphone and speak after the tap. If this keeps happening, check that Microphone is on in Settings › \(appDisplayName).")
                }
            }
        }
    }

    /// A listen ended with nothing to act on. The hands-free loop stops here; a confirmation
    /// that was waiting for an answer keeps waiting for the buttons (never against a clock).
    private func endedWithNothing(_ message: String) {
        stopAudio()
        partial = ""
        if pendingConfirm != nil {
            waitForAnswer(because: "I didn't hear an answer.")
        } else {
            phase = .idle
            setError(message)
            releaseAudioSession()
        }
    }

    private func stopAudio() {
        generation += 1     // whatever is still in flight from this listen is now stale
        silenceTimer?.invalidate(); silenceTimer = nil
        if let engine = audioEngine {
            if tapInstalled { engine.inputNode.removeTap(onBus: 0) }
            if engine.isRunning { engine.stop() }
        }
        tapInstalled = false
        audioEngine = nil
        request?.endAudio(); task?.cancel()
        request = nil; task = nil
    }

    private func stopSpeaking() {
        speaking = nil      // its didFinish / didCancel must not drive anything
        announcing = nil    // nor the end of a reply VoiceOver is reading
        if synth.isSpeaking { synth.stopSpeaking(at: .immediate) }
    }

    private func announcementEnded(_ id: UUID, timedOut: Bool = false) {
        guard announcing == id else { return }
        announcing = nil
        // Only the fallback timer ended it: VoiceOver may still be reading the question (slow
        // rate, or queued behind other speech), and an open microphone would hear its own
        // "Say yes to save" as the answer. A pending confirmation waits for the buttons instead.
        // Say how to answer, since the question VoiceOver just read asks for a spoken yes.
        if repliesViaVoiceOver && pendingConfirm != nil && phase == .speaking && !timedOut {
            waitForAnswer(because: "Waiting for your answer.")
            return
        }
        if timedOut { endLoopAfterSpeaking = true }
        if phase == .speaking { afterSpeaking() }
    }

    /// Ends the current listen. Synchronous on purpose: the phase has left listening by the
    /// time this returns, so a second tap or a late callback cannot run it twice.
    func finishListening(submit: Bool) {
        guard phase == .listening || phase == .confirming else { return }
        let text = partial.trimmingCharacters(in: .whitespacesAndNewlines)
        stopAudio()
        partial = ""
        if submit, !text.isEmpty {
            handle(text)
        } else if pendingConfirm != nil {
            waitForAnswer(because: "I didn't hear an answer.")
        } else {
            phase = .idle
            releaseAudioSession()
        }
    }

    /// The microphone closed with a confirmation still unanswered: it stays waiting, the
    /// microphone stays closed, and the person is told how to answer (the mic is closed, so
    /// VoiceOver can say it).
    private func waitForAnswer(because reason: String) {
        stopAudio()
        partial = ""
        endLoopAfterSpeaking = false
        phase = .idle
        noteAside("\(reason) Nothing is saved yet. Choose Accept and save, or Decline, or tap the microphone to answer by voice.")
        releaseAudioSession()
    }

    /// The Accept and save / Decline buttons: the same as saying yes or no.
    func answerConfirm(_ accept: Bool) {
        guard pendingConfirm != nil, phase != .thinking else { return }
        lastTouchAt = Date()
        stopAudio()
        stopSpeaking()
        partial = ""
        endLoopAfterSpeaking = false
        typedTurn = true
        errorText = nil
        handle(accept ? "Yes" : "No")
    }

    /// A typed request (or a typed yes / no to a waiting confirmation), handled as if said.
    func submitTyped(_ raw: String) {
        let text = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, phase != .thinking else { return }
        lastTouchAt = Date()
        stopAudio()
        stopSpeaking()
        partial = ""
        endLoopAfterSpeaking = false
        typedTurn = true
        errorText = nil
        handle(text)
    }

    /// The watch set up in `phase`: the assistant has gone on with nobody touching the phone for
    /// `unattendedLimit`. A reply being read out is let finish (a long note can take minutes);
    /// the next check after it stops the loop.
    private func stopIfUnattended() {
        guard phase != .idle, phase != .speaking,
              Date().timeIntervalSince(lastTouchAt) >= Self.unattendedLimit else { return }
        // One notice: the stop, and a confirmation that was waiting.
        let dropped = pendingConfirm
        pendingConfirm = nil
        cancelAll()
        noteAside("Stopped after 3 minutes without a tap, so the phone can lock."
                  + (dropped != nil ? " " + droppedText(dropped) : "")
                  + " Tap the microphone to carry on.")
    }

    /// The Stop button while a reply is read out. A confirmation being asked keeps waiting for
    /// an answer; anything else stops as Done does.
    func stopTapped() {
        guard pendingConfirm != nil else { cancelAll(); return }
        conversation = false
        stopAudio()
        stopSpeaking()
        partial = ""
        endLoopAfterSpeaking = false
        phase = .idle
        releaseAudioSession()
    }

    /// Stop, Done, leaving the screen, the app going to the background, an audio interruption.
    func cancelAll() {
        conversation = false
        stopAudio()         // also makes any late recognition callback or network reply stale
        partial = ""
        stopSpeaking()
        endLoopAfterSpeaking = false
        let dropped = pendingConfirm
        pendingConfirm = nil
        phase = .idle
        if dropped != nil {
            // The microphone is closed, so VoiceOver can say this safely.
            noteAside(droppedText(dropped))
        }
        releaseAudioSession()
    }

    /// Hands the audio session back (other apps' audio un-ducks) once the assistant is idle —
    /// a beat later, because the session is still busy just after the engine or voice stops.
    private func releaseAudioSession() {
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 400_000_000)
            guard self.phase == .idle else { return }
            try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
        }
    }

    /// Something the person must know that is not part of the spoken turn-taking: shown in the
    /// transcript and announced by VoiceOver (only ever called with the microphone closed).
    private func noteAside(_ text: String) {
        log.append(VoiceTurn(role: .assistant, text: text))
        UIAccessibility.post(notification: .announcement, argument: text)
    }

    // MARK: - Confirmation answers

    /// Reads a spoken answer to "shall I save this?". Whole words only, negatives first: any
    /// "no"-word cancels whatever else was said ("no, don't save it", "that's not correct").
    /// A yes only counts in a short answer (four words or fewer) made only of yes-words and
    /// the few words said around them ("yes please", "okay, go ahead", "that's correct").
    /// Everything else is unclear, so the question is asked again.
    nonisolated static func confirmAnswer(_ utterance: String) -> ConfirmAnswer {
        let lowered = utterance.lowercased().replacingOccurrences(of: "’", with: "'")
        let tokens = lowered.split(whereSeparator: { !($0.isLetter || $0.isNumber || $0 == "'") }).map(String.init)
        let words = tokens.map { $0.replacingOccurrences(of: "'", with: "") }.filter { !$0.isEmpty }
        guard !words.isEmpty else { return .unclear }

        let negatives: Set<String> = ["no", "nope", "not", "dont", "cancel", "stop", "never", "nevermind", "wrong", "incorrect"]
        // "don't", "isn't", "can't", "wasn't" …: every "-n't" is a negative ("do not" is caught by "not").
        if tokens.contains(where: { $0.hasSuffix("n't") }) || words.contains(where: negatives.contains) { return .no }

        // The recognizer adds punctuation: "Is it okay?" is a question, not an answer.
        guard words.count <= 4, !utterance.contains("?") else { return .unclear }
        // Every word must be on this list. A correction said with a yes ("OK, make it three",
        // "yes, Tuesday instead", "correct the time"), a number, a hedge or a question ("wait,
        // which one?", "sure, what's that for?") is never taken as a yes (as on the web). Nor is
        // speech the microphone picks up from VoiceOver: "say yes to save" (the end of our own
        // question heard back) or an earlier row read as "You said: Yes".
        let affirmatives: Set<String> = ["yes", "yeah", "yep", "ok", "okay", "sure", "confirm", "correct"]
        let around: Set<String> = ["please", "thanks", "thank", "you", "go", "ahead", "do", "it", "save",
                                   "that", "thats", "is", "right", "fine", "i", "im"]
        guard words.allSatisfy({ affirmatives.contains($0) || around.contains($0) }) else { return .unclear }
        if words.contains(where: affirmatives.contains) { return .yes }
        let phrases = [["go", "ahead"], ["do", "it"], ["save", "it"]]
        for i in words.indices.dropLast() where phrases.contains([words[i], words[i + 1]]) { return .yes }
        return .unclear
    }

    // MARK: - Command handling

    private func handle(_ text: String) {
        log.append(VoiceTurn(role: .user, text: text))

        // Waiting on a yes/no to a confirmation: resolve it here, and never by guessing.
        if var confirm = pendingConfirm {
            switch Self.confirmAnswer(text) {
            case .yes:
                pendingConfirm = nil
                startExecute(confirm)
            case .no:
                pendingConfirm = nil
                respond(action: "answer", speak: confirm.isRetry
                        ? "Okay, not retried. It may already have been saved, so check before trying again."
                        : "Okay, cancelled. Nothing was saved.")
            case .unclear:
                confirm.unclearAnswers += 1
                pendingConfirm = confirm
                if confirm.unclearAnswers == 1 {
                    // Keep the action pending and ask once more.
                    respond(action: "confirm", speak: "Please say yes to save, or no to cancel.")
                } else {
                    // Still pending, but the microphone is not reopened again: the buttons answer it.
                    endLoopAfterSpeaking = true
                    respond(action: "confirm", speak: "I still couldn't tell if that was a yes or a no. Nothing is saved yet. Choose Accept and save, or Decline, or tap the microphone to try again.")
                }
            }
            return
        }

        phase = .thinking
        remember(role: "user", text: text)
        let gen = generation
        Task { await interpret(text, gen: gen) }
    }

    private func remember(role: String, text: String) {
        history.append(["role": role, "text": text])
        if history.count > 40 { history.removeFirst(history.count - 40) }   // only the last 8 are sent
    }

    private func interpret(_ text: String, gen: Int) async {
        do {
            var body: [String: Any] = ["transcript": text]
            if let context { body["context"] = context.params }
            // handle() has just remembered this request, which goes as the transcript: the
            // history is the turns before it.
            let earlier = history.dropLast()
            if !earlier.isEmpty { body["history"] = Array(earlier.suffix(8)) }
            let r = try await api?.callDict("voice_command", body: body) ?? [:]
            guard gen == generation else { return }     // cancelled while waiting: say nothing
            dispatch(r)
        } catch {
            guard gen == generation else { return }
            respond(action: "answer", speak: (error as? APIError)?.errorDescription ?? "Something went wrong.")
        }
    }

    /// Commit a confirmed write by calling back with do=<op> + its params.
    private func startExecute(_ confirm: PendingConfirm) {
        phase = .thinking
        let gen = generation
        Task { await execute(confirm, gen: gen) }
    }

    /// Confirmed writes that are safe to send again after a lost reply: log_hours carries the
    /// confirmation's key, which the server recognises, and complete_session only ever
    /// completes a session that is still open.
    private static let retrySafeOps: Set<String> = ["log_hours", "complete_session"]

    private func execute(_ confirm: PendingConfirm, gen: Int) async {
        guard let api else {
            if gen == generation { respond(action: "answer", speak: "That didn't save. Please try again.") }
            return
        }
        var body = confirm.params
        body["do"] = confirm.op
        // Idempotent write: the confirmation's own key, the same on every retry.
        if Self.retrySafeOps.contains(confirm.op) { body["idem"] = confirm.idem }
        do {
            let r = try await api.callDict("voice_command", body: body)
            if uncertainWrite?.idem == confirm.idem { uncertainWrite = nil }    // a definite answer
            // Cancelled while the save was on its way: no more turn-taking, but the outcome
            // of a write is never dropped silently.
            guard gen == generation else { noteAside((r["speak"] as? String) ?? "Saved."); return }
            dispatch(r)
        } catch APIError.network, APIError.outcomeUnknown {
            // No reply, a timeout, a server fault or an unreadable reply: the write may have
            // been saved. Never said as "try again" for a write that is not safe to repeat.
            guard Self.retrySafeOps.contains(confirm.op) else {
                let message = "I couldn't confirm that was saved because the reply was lost. Check before trying again."
                guard gen == generation else { noteAside(message); return }
                respond(action: "answer", speak: message)
                return
            }
            // A retry sends the same key, so it is offered (and asked first); the key is kept
            // until a definite answer, also if the person says the command again instead.
            uncertainWrite = confirm
            let what = confirm.op == "log_hours" ? "the hours were logged" : "the session was completed"
            guard gen == generation else {
                noteAside("I couldn't confirm \(what) because the reply was lost. Check before trying again.")
                return
            }
            var again = confirm
            again.unclearAnswers = 0
            again.isRetry = true
            pendingConfirm = again
            let once = confirm.op == "log_hours" ? "they won't be logged twice" : "it won't be completed twice"
            respond(action: "confirm", speak: "I couldn't confirm \(what) because the reply was lost. Say yes to try again; \(once). Or say no to stop.")
        } catch APIError.server(let refusal) where confirm.isRetry && confirm.op == "complete_session"
                    && (refusal == "That session is not open." || refusal == "That session was already completed.") {
            // A retry after a lost reply finds the session already closed. The server answers a
            // retry whose first try completed it with hours logged by this user as a success, so
            // this refusal means no such log exists (no track, too short, someone else closed or
            // cancelled it): the first try may have completed it, but no hours are claimed.
            if uncertainWrite?.idem == confirm.idem { uncertainWrite = nil }
            let message = "That session is no longer open; the first try may have completed it. Check the schedule, and its hours, before trying anything else."
            guard gen == generation else { noteAside(message); return }
            respond(action: "answer", speak: message)
        } catch {
            // A refusal is a definite answer: nothing was saved by this try.
            if uncertainWrite?.idem == confirm.idem { uncertainWrite = nil }
            let message = (error as? APIError)?.errorDescription ?? "That didn't save."
            guard gen == generation else { noteAside(message); return }
            respond(action: "answer", speak: message)
        }
    }

    private func dispatch(_ r: [String: Any]) {
        let action = (r["action"] as? String) ?? "answer"
        let speak = (r["speak"] as? String) ?? "Okay."
        // Links offered by an earlier turn go: only this reply's client, search or section is offered.
        openClientId = nil; openClientName = nil; searchQuery = nil; openSection = nil

        switch action {
        case "confirm":
            if let op = r["do"] as? String {
                let params = (r["params"] as? [String: Any]) ?? [:]
                var pending = PendingConfirm(op: op, params: params)
                // The same write said again after a lost reply keeps that try's key, so the
                // server recognises it if the first try was saved.
                if let earlier = uncertainWrite, earlier.op == op, NSDictionary(dictionary: earlier.params).isEqual(to: params) {
                    pending.idem = earlier.idem
                    pending.isRetry = true
                }
                pendingConfirm = pending
                respond(action: action, speak: speak)   // speaks, then listens for yes/no
            } else {
                respond(action: "answer", speak: speak)
            }
        case "navigate":
            // The app can open a client record or a search. A website section can't be opened
            // from here, so the server's "Opening …" is not said for something that won't open.
            if let cid = r["client_id"] as? Int {
                openClientId = cid; openClientName = r["client_name"] as? String
                respond(action: action, speak: clientLinkSpeech(speak, name: openClientName, url: (r["url"] as? String) ?? ""))
            } else if let q = r["query"] as? String, !q.isEmpty {
                searchQuery = q
                respond(action: action, speak: speak)
            } else if let section = r["section"] as? String, !section.isEmpty {
                // A section the app has a screen for is offered below the conversation, as a
                // client record is; one it does not have is on the website.
                if let screen = VoiceSection.from(section) {
                    openSection = screen
                    respond(action: action, speak: "To go to \(screen.title), tap Open \(screen.title), below the conversation.")
                } else {
                    respond(action: "answer", speak: "I can't open \(section) from the assistant: that page is on the website. Tap Done, then find it under More tools on the Dashboard, or on the website.")
                }
            } else {
                respond(action: action, speak: speak)
            }
        default: // answer, ask, help, none
            // A write the assistant can't finish is answered (nothing saved) with the client
            // it was for: the link to that client's record is still offered, and said.
            if action == "answer", let cid = r["client_id"] as? Int {
                openClientId = cid; openClientName = r["client_name"] as? String
                let record = openClientName.map { "\($0)'s record" } ?? "the client's record"
                respond(action: action, speak: "\(speak) To go to \(record), tap Open \(record), below the conversation.")
            } else {
                respond(action: action, speak: speak)
            }
        }
    }

    /// The server's wording is the website's, where the page opens by itself ("Opening …").
    /// Here nothing opens: a link to the client's record is offered below the conversation, so
    /// the reply says that instead, and says so when the page meant is only on the website.
    private func clientLinkSpeech(_ speak: String, name: String?, url: String) -> String {
        var lead = speak
        if let opening = speak.range(of: "Opening ") { lead = String(speak[..<opening.lowerBound]) }
        lead = lead.trimmingCharacters(in: .whitespaces)
        let record = name.map { "\($0)'s record" } ?? "the client's record"
        var parts = lead.isEmpty ? [] : [lead]
        if !url.isEmpty && !url.hasPrefix("client.php") {
            parts.append("That page is on the website; the app can't open it.")
        }
        parts.append("To go to \(record), tap Open \(record), below the conversation.")
        return parts.joined(separator: " ")
    }

    /// Log + speak a reply. After speaking: in conversation mode (or when a
    /// confirmation is pending) we re-listen hands-free.
    private func respond(action: String, speak: String) {
        log.append(VoiceTurn(role: .assistant, text: speak))
        remember(role: "assistant", text: speak)
        speakOut(speak)
    }

    private func speakOut(_ text: String) {
        stopSpeaking()
        phase = .speaking
        guard !text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else {
            // Nothing to say — and the synthesizer may never report the end of an empty utterance.
            afterSpeaking()
            return
        }
        if repliesViaVoiceOver {
            // Through VoiceOver, so the reply also reaches a braille display (synthesizer audio
            // never does), queued after "Working". The microphone opens again only once
            // VoiceOver has finished it; a fallback timer covers an announcement VoiceOver drops.
            let id = UUID()
            announcing = id
            announcedText = text
            workingCueAt = nil
            UIAccessibility.post(notification: .announcement,
                                 argument: NSAttributedString(string: text, attributes: [.accessibilitySpeechQueueAnnouncement: true]))
            // Generous (about 4 characters a second, plus time queued behind "Working"), so
            // it covers slow VoiceOver rates; it never opens the microphone for a confirmation.
            let fallback = min(180, 6 + Double(text.count) / 4)
            Task { @MainActor in
                try? await Task.sleep(nanoseconds: UInt64(fallback * 1_000_000_000))
                if self.announcing == id { self.announcementEnded(id, timedOut: true) }
            }
            return
        }
        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playback, mode: .spokenAudio, options: [.duckOthers])
            try session.setActive(true)
        } catch { /* best-effort; still attempt to speak */ }
        let u = AVSpeechUtterance(string: text)
        u.voice = AVSpeechSynthesisVoice(language: "en-US")
        u.rate = AVSpeechUtteranceDefaultSpeechRate
        // With VoiceOver on, speak in the person's own VoiceOver voice and rate…
        u.prefersAssistiveTechnologySettings = true
        // …and let VoiceOver finish saying "Working" instead of talking over it.
        if UIAccessibility.isVoiceOverRunning, let cue = workingCueAt {
            let remaining = 1.0 - Date().timeIntervalSince(cue)
            if remaining > 0 { u.preUtteranceDelay = remaining }
        }
        workingCueAt = nil
        speaking = ObjectIdentifier(u)
        synth.speak(u)
    }

    /// The reply has been spoken: listen again (pending yes/no, or hands-free), or go idle. After
    /// a typed or tapped turn the microphone stays closed: a confirmation waits for the buttons.
    fileprivate func afterSpeaking() {
        let endLoop = endLoopAfterSpeaking
        endLoopAfterSpeaking = false
        if !endLoop && !typedTurn && (pendingConfirm != nil || conversation) {
            startListening()
        } else {
            phase = .idle
            releaseAudioSession()
        }
    }

    fileprivate func utteranceEnded(_ id: ObjectIdentifier, cancelled: Bool) {
        guard speaking == id else { return }    // an utterance we stopped or replaced ourselves
        speaking = nil
        if cancelled {
            // Stopped by the system, not by us: end cleanly rather than stay on "Speaking…".
            if phase == .speaking { cancelAll() }
        } else {
            afterSpeaking()
        }
    }
}

extension SpeechController: AVSpeechSynthesizerDelegate {
    nonisolated func speechSynthesizer(_ synthesizer: AVSpeechSynthesizer, didFinish utterance: AVSpeechUtterance) {
        let id = ObjectIdentifier(utterance)
        Task { @MainActor in self.utteranceEnded(id, cancelled: false) }
    }
    nonisolated func speechSynthesizer(_ synthesizer: AVSpeechSynthesizer, didCancel utterance: AVSpeechUtterance) {
        let id = ObjectIdentifier(utterance)
        Task { @MainActor in self.utteranceEnded(id, cancelled: true) }
    }
}

struct VoiceTurn: Identifiable {
    enum Role { case user, assistant }
    let id = UUID()
    let role: Role
    let text: String
}

/// The record the assistant was opened from (a client screen, or one of their tracks).
struct VoiceContext: Equatable {
    let clientId: Int
    let clientName: String
    var trackId: Int? = nil
    var trackTitle: String? = nil

    /// Sent with each request (`context`), for a server that reads it.
    var params: [String: Any] {
        var p: [String: Any] = ["client_id": clientId, "client_name": clientName]
        if let trackId { p["track_id"] = trackId }
        if let trackTitle { p["track_title"] = trackTitle }
        return p
    }

    /// Told to the interpreter as the person's first words.
    var spoken: String {
        var text = "I'm looking at \(clientName)'s record"
        if let trackTitle, !trackTitle.isEmpty { text += ", on the training track \(trackTitle)" }
        return text + "."
    }
}

/// A website section the server's "navigate" names that the app has a screen of its own for.
enum VoiceSection: Equatable {
    case tab(AppNavigation.Tab)
    case dashboard(AppNavigation.DashboardRoute)

    /// The server's section name (voice_sections in inc/voice.php), or nil for one the app lacks.
    static func from(_ section: String) -> VoiceSection? {
        switch section.lowercased().trimmingCharacters(in: .whitespaces) {
        case "dashboard": return .tab(.dashboard)
        case "clients": return .tab(.clients)
        case "referrals": return .tab(.referrals)
        case "schedule": return .tab(.schedule)
        case "equipment": return .tab(.equipment)
        case "follow-ups", "followups", "device follow-ups": return .dashboard(.followups)
        case "messages": return .dashboard(.messages)
        case "tasks": return .dashboard(.tasks)
        default: return nil
        }
    }

    var title: String {
        switch self {
        case .tab(.dashboard): return "Dashboard"
        case .tab(.clients): return "Clients"
        case .tab(.referrals): return "Referrals"
        case .tab(.schedule): return "Schedule"
        case .tab(.equipment): return "Equipment"
        case .dashboard(.followups): return "Device follow-ups"
        case .dashboard(.messages): return "Messages"
        case .dashboard(.tasks): return "Tasks"
        case .dashboard(.docReview): return "Documentation review"
        }
    }

    var destination: AppNavigation.Destination {
        switch self {
        case .tab(let tab): return .tab(tab)
        case .dashboard(let route): return .dashboard(route)
        }
    }
}

// MARK: - UI

struct VoiceView: View {
    @EnvironmentObject var api: API
    @EnvironmentObject var navigation: AppNavigation
    @Environment(\.dismiss) private var dismiss
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    /// The client (and track) on the screen this was opened from, if any.
    var context: VoiceContext? = nil
    @StateObject private var ctrl = SpeechController()
    @State private var started = false
    @State private var typed = ""
    @AppStorage(SpeechController.pauseKey) private var pauseSeconds: Double = 1.8
    @AppStorage(SpeechController.viaVoiceOverKey) private var repliesViaVoiceOver = true
    @AppStorage(SpeechController.autoListenKey) private var autoListen = true

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                // One banner (and so one announcement) per problem; the permission case adds a way out.
                if let err = ctrl.errorText { ErrorBanner(message: err).padding(.horizontal) }
                if ctrl.authDenied {
                    Button {
                        if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
                    } label: {
                        Label("Open Settings", systemImage: "gear")
                    }
                    .buttonStyle(.bordered)
                    .controlSize(.large)
                    .padding(.top, 8)
                }

                ScrollViewReader { proxy in
                    ScrollView {
                        LazyVStack(alignment: .leading, spacing: 10) {
                            if ctrl.log.isEmpty {
                                Text(context.map { "Asking about \($0.clientName)\($0.trackTitle.map { ", \($0)" } ?? ""). " } ?? ""
                                     + "Tap the microphone and speak, or type below. Try: “What’s my next appointment?”, “Read me Jane’s latest note”, “Log 2 hours for Jane, screen-reader training”, or “What can you do?”")
                                    .font(.callout).foregroundStyle(Color.secondaryText).padding(.top, 8)
                            }
                            ForEach(ctrl.log) { turn in
                                turnRow(turn)
                            }
                            if !ctrl.partial.isEmpty {
                                // Full contrast: "not sent yet" is said in words, not by fading it.
                                turnRow(VoiceTurn(role: .user, text: ctrl.partial), draft: true)
                            }
                            // Offer to open a client the assistant resolved.
                            if let cid = ctrl.openClientId {
                                NavigationLink(value: cid) {
                                    Label("Open \(ctrl.openClientName.map { "\($0)’s" } ?? "the client’s") record", systemImage: "person.crop.circle")
                                }
                                .buttonStyle(.bordered)
                                .controlSize(.large)
                            }
                            if let q = ctrl.searchQuery {
                                NavigationLink { SearchView(initialQuery: q) } label: {
                                    Label("Search for “\(q)”", systemImage: "magnifyingglass")
                                }
                                .buttonStyle(.bordered)
                                .controlSize(.large)
                            }
                            // A section the app has a screen for: this sheet closes and the app goes there.
                            if let section = ctrl.openSection {
                                Button {
                                    ctrl.cancelAll()
                                    navigation.request(section.destination)
                                    dismiss()
                                } label: {
                                    Label("Open \(section.title)", systemImage: "arrow.right.circle")
                                }
                                .buttonStyle(.bordered)
                                .controlSize(.large)
                                .accessibilityHint("Closes the assistant and opens that screen.")
                            }
                            Color.clear.frame(height: 1).id("bottom")
                        }
                        .padding(.horizontal)
                    }
                    .onChange(of: ctrl.log.count) { _ in
                        if reduceMotion {
                            proxy.scrollTo("bottom", anchor: .bottom)
                        } else {
                            withAnimation { proxy.scrollTo("bottom", anchor: .bottom) }
                        }
                    }
                }

                statusLine
                if ctrl.awaitingAnswer { answerButtons }
                typedEntry
                controls
                options
            }
            .navigationTitle("Voice assistant")
            .navigationBarTitleDisplayMode(.inline)
            .navigationDestination(for: Int.self) { id in ClientDetailView(clientId: id) }
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Done") { ctrl.cancelAll(); dismiss() }
                }
            }
            .task {
                ctrl.attach(api, context: context)
                if !started {
                    started = true
                    let allowed = await ctrl.requestAccess()
                    // Start listening when opened (Account settings): the microphone opens by
                    // itself, once VoiceOver has read the title, so a tap is not needed first.
                    if allowed && autoListen && ctrl.phase == .idle {
                        // Longer with VoiceOver, so its reading of the title is not heard as the request.
                        try? await Task.sleep(nanoseconds: UIAccessibility.isVoiceOverRunning ? 2_000_000_000 : 800_000_000)
                        if ctrl.phase == .idle { ctrl.startListening() }
                    }
                }
            }
            .onDisappear { ctrl.cancelAll() }
            // The tabs (and this sheet) now stay alive behind the app lock, so the microphone
            // and the voice must stop when the app leaves the foreground.
            .onReceive(NotificationCenter.default.publisher(for: UIApplication.didEnterBackgroundNotification)) { _ in
                ctrl.cancelAll()
            }
        }
    }

    private func turnRow(_ turn: VoiceTurn, draft: Bool = false) -> some View {
        HStack {
            if turn.role == .user { Spacer(minLength: 40) }
            VStack(alignment: turn.role == .user ? .trailing : .leading, spacing: 4) {
                Text(turn.text)
                    .font(.callout)
                    .italic(draft)
                    .foregroundStyle(turn.role == .user ? .white : .primary)
                    .padding(10)
                    // White text on a fill that stays navy in both appearances.
                    .background(turn.role == .user ? Color.brandFill : Color(.secondarySystemBackground),
                                in: RoundedRectangle(cornerRadius: 12))
                    .overlay {
                        if draft {
                            RoundedRectangle(cornerRadius: 9)
                                .strokeBorder(Color.white, style: StrokeStyle(lineWidth: 1.5, dash: [5, 4]))
                                .padding(3)
                        }
                    }
                if draft {
                    Text("Still listening — not sent yet")
                        .font(.caption)
                        .foregroundStyle(.primary)
                }
            }
            if turn.role == .assistant { Spacer(minLength: 40) }
        }
        .accessibilityElement(children: .combine)
        .accessibilityLabel(draft ? "Heard so far, not sent yet: \(turn.text)"
                            : turn.role == .user ? "You said: \(turn.text)" : "Assistant said: \(turn.text)")
    }

    @ViewBuilder private var statusLine: some View {
        let (txt, sym): (String, String) = {
            switch ctrl.phase {
            case .listening:  return ("Listening…", "waveform")
            // Read aloud by VoiceOver if touched while the microphone is open, so no answer words here.
            case .confirming: return ("Listening for your answer…", "waveform")
            case .thinking:   return ("Thinking…", "ellipsis")
            case .speaking:   return ("Speaking…", "speaker.wave.2")
            case .idle:
                if ctrl.awaitingAnswer { return ("Waiting for your answer — nothing is saved yet", "questionmark.circle") }
                return (ctrl.conversation ? "Hands-free on — tap to start" : "Tap to speak", "mic")
            }
        }()
        Label(txt, systemImage: sym)
            .font(.subheadline.weight(.medium))
            .foregroundStyle(.primary)
            .padding(.vertical, 6)
            .padding(.horizontal)
            .accessibilityLabel(txt)
    }

    /// Answers to a waiting confirmation without speech. VoiceOver reads these names aloud while
    /// the microphone may be open, so they avoid the words that answer by voice ("yes", "save
    /// it", "no", "cancel"…): heard by the recogniser they are unclear, never an answer.
    @ViewBuilder private var answerButtons: some View {
        HStack(spacing: 12) {
            Button { ctrl.answerConfirm(true) } label: {
                Text(ctrl.answerIsRetry ? "Accept and try again" : "Accept and save").frame(maxWidth: .infinity)
            }
            .buttonStyle(.borderedProminent)
            .controlSize(.large)
            .onBrandLabel()
            .accessibilityHint(ctrl.answerIsRetry ? "Sends the entry again with the same key." : "Saves what the assistant just described.")
            Button { ctrl.answerConfirm(false) } label: {
                Text("Decline").frame(maxWidth: .infinity)
            }
            .buttonStyle(.bordered)
            .controlSize(.large)
            .accessibilityHint("Nothing more is sent.")
        }
        .disabled(ctrl.phase == .thinking)
        .padding(.horizontal)
        .padding(.bottom, 6)
    }

    /// A request (or an answer) typed instead of spoken.
    @ViewBuilder private var typedEntry: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("Type a request").font(.caption.weight(.semibold)).accessibilityHidden(true)
            HStack(spacing: 8) {
                TextField("Type a request", text: $typed)
                    .textFieldStyle(.roundedBorder)
                    .submitLabel(.send)
                    .onSubmit(sendTyped)
                Button("Send", action: sendTyped)
                    .buttonStyle(.bordered)
                    .controlSize(.large)
                    .disabled(typed.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty || ctrl.phase == .thinking)
                    .accessibilityLabel("Send typed request")
            }
        }
        .padding(.horizontal)
    }

    private func sendTyped() {
        let text = typed.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, ctrl.phase != .thinking else { return }
        typed = ""
        ctrl.submitTyped(text)
    }

    @ViewBuilder private var controls: some View {
        let active = ctrl.phase == .listening || ctrl.phase == .confirming
        let pulsing = active && !reduceMotion
        HStack(spacing: 24) {
            if ctrl.phase == .speaking {
                Button { ctrl.stopTapped() } label: {
                    Label("Stop", systemImage: "stop.circle.fill")
                        .font(.title2)
                        .frame(minWidth: 44, minHeight: 44)
                        .contentShape(Rectangle())
                }
                .buttonStyle(.bordered)
            }
            Button {
                Task { await ctrl.micTapped() }
            } label: {
                Image(systemName: active ? "stop.circle.fill" : "mic.circle.fill")
                    .resizable().frame(width: 72, height: 72)
                    .foregroundStyle(active ? Color.red : Color.brand)
                    .scaleEffect(pulsing ? 1.08 : 1.0)
                    .animation(pulsing ? .easeInOut(duration: 0.7).repeatForever(autoreverses: true) : .default, value: pulsing)
            }
            .disabled(ctrl.phase == .thinking)
            .accessibilityLabel(micLabel)
        }
        .padding(.bottom, 8).padding(.top, 6)
    }

    /// Hands-free (with its name on screen), how long a pause ends what is being said, and
    /// whether replies go through VoiceOver.
    @ViewBuilder private var options: some View {
        VStack(alignment: .leading, spacing: 4) {
            Toggle("Hands-free", isOn: $ctrl.conversation)
                .accessibilityHint("Listens again after each reply, without tapping the microphone.")
            DisclosureGroup("Listening options") {
                LabeledContent("Pause before sending") {
                    Picker("Pause before sending", selection: $pauseSeconds) {
                        Text("1.8 seconds").tag(1.8)
                        Text("3 seconds").tag(3.0)
                        Text("5 seconds").tag(5.0)
                        Text("Until I tap the microphone").tag(0.0)
                    }
                    .labelsHidden()
                }
                .accessibilityHint("How long a pause in speech sends what was heard.")
                Toggle("Read replies with VoiceOver", isOn: $repliesViaVoiceOver)
                    .accessibilityHint("When VoiceOver is on, replies are read by VoiceOver and shown on a braille display instead of in the assistant's own voice.")
                Toggle("Start listening when opened", isOn: $autoListen)
                    .accessibilityHint("The microphone opens by itself when the assistant opens.")
            }
        }
        .padding(.horizontal)
        .padding(.bottom, 8)
    }

    /// What the big button does right now. The confirming label avoids answer words: VoiceOver
    /// reads it aloud when the button is touched, and the microphone is open at that moment.
    private var micLabel: String {
        switch ctrl.phase {
        case .listening: return "Stop listening and send"
        case .confirming: return "Send answer"
        case .speaking: return "Stop speaking and listen"
        case .thinking, .idle: return ctrl.awaitingAnswer ? "Answer by voice" : "Start listening"
        }
    }
}
