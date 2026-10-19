import SwiftUI

/// Finger-drawn signature pad. Renders the strokes into a PNG for upload.
struct SignaturePad: View {
    @Binding var strokes: [[CGPoint]]
    @Binding var padSize: CGSize
    /// Spoken as the pad's value, so VoiceOver can tell whether anything has been drawn.
    var status: String = ""
    /// Told when a stroke starts and ends, so a scroll view around the pad can hold still.
    var onDrawing: (Bool) -> Void = { _ in }
    @State private var current: [CGPoint] = []
    /// A stroke is in progress. SwiftUI resets this when the drag ends and also when it is
    /// cancelled (an incoming call, an alert, the scroll view taking the touch), which
    /// onEnded alone does not report, so the scroll view is never left held still.
    @GestureState private var stroking = false

    var body: some View {
        Canvas { context, _ in
            for stroke in strokes + (current.isEmpty ? [] : [current]) {
                var path = Path()
                guard let first = stroke.first else { continue }
                path.move(to: first)
                for p in stroke.dropFirst() { path.addLine(to: p) }
                context.stroke(path, with: .color(.black), lineWidth: 3)
            }
        }
        .background(Color.white)
        .clipped()
        .border(Color.gray, width: 1)
        .overlay(GeometryReader { g in
            Color.clear
                .onAppear { padSize = g.size }
                .onChange(of: g.size) { padSize = $0 }
        })
        .gesture(
            DragGesture(minimumDistance: 0)
                .updating($stroking) { _, state, _ in state = true }
                .onChanged { value in
                    // Kept on the pad: a finger that slides past the edge draws along it.
                    let p = value.location
                    if current.isEmpty { onDrawing(true) }     // at once, before the scroll view can move
                    current.append(CGPoint(x: min(max(p.x, 0), padSize.width), y: min(max(p.y, 0), padSize.height)))
                }
                .onEnded { _ in
                    if !current.isEmpty { strokes.append(current) }
                    current = []
                    onDrawing(false)    // also here: a quick tap may end before `stroking` is seen to change
                }
        )
        .onChange(of: stroking) { active in
            // A cancelled stroke keeps what was drawn (as onEnded would), so the next stroke
            // is never joined onto it.
            if !active && !current.isEmpty {
                strokes.append(current)
                current = []
            }
            onDrawing(active)
        }
        .accessibilityElement()
        .accessibilityLabel("Signature area")
        .accessibilityValue(status)
        .accessibilityHint("Draw the signature here with one finger. Touches on this area go straight to the pad while VoiceOver is on.")
        // Without this trait VoiceOver swallows the drag and nothing can be drawn.
        .accessibilityAddTraits(.allowsDirectInteraction)
    }
}

/// Sheet shown when completing a session: capture the client's signature
/// (proof of service on the invoice), complete without one, or cancel.
/// The sheet stays open until the completion is saved: if it fails, the signature is still on
/// the pad for a retry, so the client is never asked to sign again.
/// The phone is handed to the client here, so however the sheet closes the app locks: the
/// rest of the app (other clients' records) opens again only for the device owner. Cancel
/// included (4.27.0): once the sheet has been shown, the phone may be in the client's hands
/// whether or not they drew on the pad (as InPersonSigningView).
struct SignatureSheet: View {
    let clientName: String
    let onDone: (_ signatureB64: String?, _ signedName: String?) async throws -> Void
    @EnvironmentObject private var lock: AppLock
    @Environment(\.dismiss) private var dismiss
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.dynamicTypeSize) private var typeSize

    @State private var strokes: [[CGPoint]] = []
    @State private var padSize = CGSize(width: 600, height: 220)
    @State private var signedName = ""
    @State private var busy = false
    @State private var error: String?
    /// A stroke is being drawn: the scroll view holds still so the pen isn't taken for a scroll.
    @State private var drawing = false

    /// Total drawn distance — an accidental tap or tiny mark isn't a signature.
    private var inkLength: CGFloat {
        strokes.reduce(0) { total, s in
            total + zip(s, s.dropFirst()).reduce(0) { $0 + hypot($1.1.x - $1.0.x, $1.1.y - $1.0.y) }
        }
    }
    private var hasSignature: Bool { inkLength >= 60 }
    /// The typed "name as signed": kept with a drawn signature, or on its own as the client's
    /// acknowledgement when they cannot draw on the pad.
    private var typedName: String { signedName.trimmingCharacters(in: .whitespacesAndNewlines) }

    private var padStatus: String {
        if strokes.isEmpty { return "Empty" }
        return hasSignature ? "Signature drawn" : "Mark too short to save"
    }

    /// A fixed pad height: it depends on the orientation and text size, never on the keyboard,
    /// so typing the name can't squeeze the pad (and crop or enlarge the saved signature).
    private var padHeight: CGFloat {
        if verticalSizeClass == .compact { return 130 }
        return typeSize.isAccessibilitySize ? 160 : 220
    }

    /// Closes the sheet behind the app lock, however it closes (see the type's note).
    private func close() {
        lock.lockNow()
        dismiss()
    }

    var body: some View {
        NavigationStack {
            // Scrolls when the content is taller than the screen (landscape, larger text); the
            // pad keeps its fixed size, and scrolling is off while a stroke is drawn on it.
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    if let error { ErrorBanner(message: error) }
                    Text("Hand the phone to \(clientName) to sign — the signature appears on the invoice as proof of service. When this closes, the app locks until you unlock it.")
                        .font(.callout)
                        .fixedSize(horizontal: false, vertical: true)
                    // Above the pad, so the keyboard never covers it (it slides over the pad instead).
                    // A caption that stays visible once typing starts (a placeholder alone goes);
                    // the field carries the same name for VoiceOver, so it is not read twice.
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Name as signed (optional)")
                            .font(.caption.weight(.semibold))
                            .foregroundStyle(Color.secondaryText)
                            .accessibilityHidden(true)
                        TextField("Name as signed (optional)", text: $signedName,
                                  prompt: Text("The client's name"))
                            .textFieldStyle(.roundedBorder)
                            // No name content type and no suggestions: AutoFill would offer the
                            // phone owner's (the staff member's) own name as the client's.
                            .autocorrectionDisabled()
                            .submitLabel(.done)
                    }
                    Text("A client who cannot draw on the pad can type their name here instead: it is kept as their acknowledgement of the session.")
                        .font(.caption)
                        .foregroundStyle(Color.secondaryText)
                        .fixedSize(horizontal: false, vertical: true)
                    SignaturePad(strokes: $strokes, padSize: $padSize, status: padStatus,
                                 onDrawing: { drawing = $0 })
                        .frame(maxWidth: .infinity)
                        .frame(height: padHeight)
                    HStack {
                        Button("Clear") { strokes = [] }
                            .buttonStyle(.bordered)
                            .disabled(strokes.isEmpty || busy)
                        Spacer()
                        Button {
                            Task { await submit(withSignature: true) }
                        } label: {
                            if busy { ProgressView().accessibilityLabel("Saving") } else { Text("Save signature") }
                        }
                        .buttonStyle(.borderedProminent)
                        .onBrandLabel()
                        .disabled(!hasSignature || busy)
                    }
                    if !hasSignature {
                        Text("Save signature becomes available once a signature has been drawn.")
                            .font(.caption).foregroundStyle(Color.secondaryText)
                    }
                    // Completing still logs the session's hours — it is not the way to back out
                    // (that is Cancel, in the toolbar).
                    // With a typed name and no drawing, the name is sent on its own (never dropped).
                    Button {
                        Task { await submit(withSignature: false) }
                    } label: {
                        Text(typedName.isEmpty ? "Complete without signature" : "Complete with typed name only")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .disabled(busy)
                    .accessibilityHint(typedName.isEmpty
                        ? "Marks the session completed, logs its hours with no signature, and locks the app."
                        : "Marks the session completed, logs its hours with the typed name as the client's acknowledgement and no drawn signature, and locks the app.")
                }
                .padding()
            }
            .scrollDisabled(drawing)
            .scrollDismissesKeyboard(.interactively)
            .navigationTitle("Client signature")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // Backs out: the session stays scheduled and nothing is logged. The app locks
                // (4.27.0: always, as the phone may have been handed over before any drawing).
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { close() }
                        .disabled(busy)
                        .accessibilityHint("Leaves the session scheduled and locks the app.")
                }
            }
            // No swipe-down: every way out goes through the lock.
            .interactiveDismissDisabled(true)
            .onChange(of: strokes.count) { count in
                if count == 0 {
                    announce("Signature cleared.")
                } else if count == 1 {
                    announce(hasSignature ? "Signature drawn. Save signature is available."
                                          : "Mark recorded. Keep signing — it is too short to save yet.")
                }
            }
            .onChange(of: hasSignature) { ready in
                if ready && strokes.count > 1 { announce("Signature drawn. Save signature is available.") }
            }
        }
    }

    private func submit(withSignature: Bool) async {
        let name = typedName
        // The server keeps at most 120 characters; a longer name is refused here, by name,
        // before anything is sent (it used to fail after the sheet had closed).
        if name.unicodeScalars.count > 120 {
            showFormError("Name as signed: use 120 characters or fewer.", in: &error)
            return
        }
        busy = true; error = nil
        do {
            // A typed name goes with either choice: the server keeps it with a drawing, or on
            // its own as a typed acknowledgement.
            try await onDone(withSignature ? renderPNGBase64() : nil, name.isEmpty ? nil : name)
            close()
        } catch {
            showFormError(error.localizedDescription, in: &self.error)
            busy = false
        }
    }

    private func renderPNGBase64() -> String? {
        guard hasSignature else { return nil }
        let size = CGSize(width: 600, height: 220)
        // Everything drawn is kept: the area rendered is the pad as measured, widened to take in
        // any ink outside it (the pad is shorter in landscape, so a rotation after signing could
        // otherwise cut off part of the signature).
        let ink = strokes.joined()
        let area = CGRect(x: 0, y: 0,
                          width: max(padSize.width, ink.map(\.x).max() ?? 0),
                          height: max(padSize.height, ink.map(\.y).max() ?? 0))
        // ONE scale factor for both axes, so the signature keeps its shape on any phone.
        let scale = min(size.width / max(area.width, 1), size.height / max(area.height, 1))
        let renderer = UIGraphicsImageRenderer(size: size)
        let image = renderer.image { ctx in
            UIColor.white.setFill()
            ctx.fill(CGRect(origin: .zero, size: size))
            UIColor.black.setStroke()
            for stroke in strokes {
                guard let first = stroke.first else { continue }
                let path = UIBezierPath()
                path.lineWidth = 3
                path.move(to: CGPoint(x: first.x * scale, y: first.y * scale))
                for p in stroke.dropFirst() { path.addLine(to: CGPoint(x: p.x * scale, y: p.y * scale)) }
                path.stroke()
            }
        }
        return image.pngData()?.base64EncodedString()
    }
}
