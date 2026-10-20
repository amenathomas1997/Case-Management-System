import SwiftUI

// 4.31.0: confirming a session leads straight to its case note. Completing a session (the
// Schedule's Mark completed) opens the full case-note editor for that client, filled in with
// the session's track, the subject "Session on Oct 20" and the link to the session. A note not
// written then is listed under Notes to write (Schedule and Dashboard) with Write it now.

/// The case-note editor to open for a completed session, and what is said as it opens.
struct SessionNoteRequest: Identifiable {
    let target: SessionNoteTarget
    /// Said once VoiceOver is on the first field: after a completion, its result and then where
    /// the person is ("Session completed. Case note for Jane Doe, session on Oct 20.").
    let arrival: String
    var id: Int { target.sessionId }
}

/// The full case-note editor (AddNoteSheet) for a completed session. The client's tracks are
/// read first (from the copy kept on the phone with no signal), so the track picker can show
/// the session's track already chosen; when they cannot be read the note is still attached to
/// that track.
struct SessionNoteSheet: View {
    @EnvironmentObject var api: API
    let request: SessionNoteRequest
    /// Called with the result once the note is saved (or kept offline); not called when the
    /// person leaves without saving.
    let onSaved: (String) -> Void
    @State private var tracks: [Track]?
    @Environment(\.dismiss) private var dismiss
    /// How long the client record may take before the editor opens without it.
    static let tracksTimeout: UInt64 = 8_000_000_000

    var body: some View {
        if let tracks {
            let target = request.target
            AddNoteSheet(clientId: target.clientId, tracks: tracks, initialTrackId: target.trackId ?? 0,
                         existing: nil, initialSubject: target.noteSubject, sessionId: target.sessionId,
                         sessionContext: target.contextLine, arrivalAnnouncement: request.arrival,
                         onSaved: onSaved)
        } else {
            NavigationStack {
                ProgressView("Opening the case note…")
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .navigationTitle("Case note")
                    .navigationBarTitleDisplayMode(.inline)
                    .toolbar {
                        // Never stuck here: Cancel goes back (the note stays under Notes to write).
                        ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                    }
            }
            .interactiveDismissDisabled()
            .task { await loadTracks() }
            // A slow connection: after a few seconds the editor opens without the track list.
            // The note is still attached to the session's track (sent as its track_id).
            .task {
                try? await Task.sleep(nanoseconds: Self.tracksTimeout)
                if tracks == nil && !Task.isCancelled { tracks = [] }
            }
        }
    }

    private func loadTracks() async {
        let id = request.target.clientId
        let loaded: (value: ClientDetail, savedAt: Date?)? = try? await api.callCached("client", query: ["id": String(id)], cacheKey: "client-\(id)")
        guard tracks == nil else { return }   // the timeout has already opened the editor
        tracks = loaded?.value.tracks ?? []
    }
}

/// notes_to_write (4.31.0) for the signed-in person, kept on the phone so the list (and Write
/// it now, whose note is kept offline with its session) works with no signal. nil when the
/// server is older (Unknown action), the account cannot write notes, or it cannot be read.
@MainActor func loadNotesToWrite(_ api: API) async -> NotesToWriteResponse? {
    guard api.can("clients") else { return nil }
    return try? await api.callCached("notes_to_write", cacheKey: "notes_to_write").value
}

/// A completed session whose note is not written yet: who and when, "Note not written yet", and
/// Write it now. One VoiceOver stop, a button that opens the note.
struct NoteToWriteRow: View {
    let item: SessionNoteTarget
    let onWrite: () -> Void

    var body: some View {
        Button(action: onWrite) {
            VStack(alignment: .leading, spacing: 4) {
                Text(item.clientName).font(.body.weight(.semibold))
                Text(item.noteSubject).font(.callout)
                Text("Note not written yet").font(.caption.weight(.semibold)).foregroundStyle(Color.secondaryText)
                Text("Write it now")
                    .font(.callout.weight(.medium))
                    .foregroundStyle(Color.brandTeal)
                    .frame(minHeight: 44, alignment: .leading)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel("\(item.clientName), \(item.noteSubject.prefix(1).lowercased() + item.noteSubject.dropFirst()). Note not written yet. Write it now")
        .accessibilityInputLabels(["Write it now", "Write the note for \(item.clientName)"])
    }
}
