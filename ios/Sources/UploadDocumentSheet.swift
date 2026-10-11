import SwiftUI
import PhotosUI
import UniformTypeIdentifiers

struct UploadDocumentSheet: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    /// The result to tell the person, said by the client screen once the sheet has gone
    /// (closing the sheet moves VoiceOver focus, which cut off "Document uploaded." said here).
    let onSaved: (String) -> Void

    @State private var category = "Other"
    @State private var title = ""
    @State private var photoItem: PhotosPickerItem?
    @State private var pendingData: Data?
    @State private var pendingFilename: String?
    @State private var showingCamera = false
    @State private var showingFileImporter = false
    @State private var busy = false
    @State private var error: String?
    /// Identifies the latest choice of photo, file or scan, so a slower earlier photo load (an
    /// iCloud original) can't replace what was chosen after it.
    @State private var photoLoad = UUID()
    /// A library photo is being read (an iCloud original can take seconds): shown and said.
    @State private var loadingPhoto = false

    /// The file types the server accepts (api.php upload_document), checked before anything is
    /// sent so a refused type is never uploaded in full first.
    private static let allowedExtensions = ["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "txt", "rtf", "csv", "png", "jpg", "jpeg", "heic"]
    private static let importTypes: [UTType] = allowedExtensions.compactMap { UTType(filenameExtension: $0) }
    /// The server's limit (api.php upload_document, as on the website). The file goes up
    /// base64-encoded inside JSON (a third larger, about 33.3 MB), which the server's request
    /// body limit (post_max_size 36M in public/.user.ini) still fits.
    private static let maxBytes = 25 * 1024 * 1024

    var body: some View {
        NavigationStack {
            ErrorFocusForm(error: error) {
                Picker("Category", selection: $category) {
                    ForEach(docCategories, id: \.self) { Text($0) }
                }
                // Said in words: the portal lists every Resource document for the client.
                Text(category == "Resource"
                     ? "This document will be shared on the client's portal."
                     : "Documents in the Resource category are shared on the client's portal.")
                    .font(.footnote).foregroundStyle(Color.secondaryText)
                // The name and the note about leaving it blank stay visible once a title is typed.
                CaptionedField(label: "Title (optional; the file name is used when blank)", text: $title, prompt: "File name")

                Section("File") {
                    if loadingPhoto {
                        ProgressView("Loading photo…")
                    }
                    if let filename = pendingFilename {
                        Label(filename, systemImage: "doc.fill")
                            .foregroundStyle(Color.brandTeal)
                            .accessibilityLabel("Selected file: \(filename)")
                    }
                    if UIImagePickerController.isSourceTypeAvailable(.camera) {
                        Button { showingCamera = true } label: {
                            Label("Take a photo (scan)", systemImage: "camera")
                        }
                    }
                    PhotosPicker(selection: $photoItem, matching: .images) {
                        Label("Choose from photo library", systemImage: "photo.on.rectangle")
                    }
                    Button { showingFileImporter = true } label: {
                        Label("Choose a file (PDF, Word…)", systemImage: "folder")
                    }
                }
            }
            .navigationTitle("Upload document")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                // An upload can't be called back once sent, so the sheet stays until it finishes:
                // closing it used to leave the upload running and file the document anyway.
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(busy) }
                ToolbarItem(placement: .confirmationAction) {
                    // One button whose content changes, so starting the upload never replaces the
                    // focused control (and VoiceOver focus) with another view.
                    Button { Task { await upload() } } label: {
                        if busy { ProgressView() } else { Text("Upload") }
                    }
                    .disabled(pendingData == nil || busy)
                    .accessibilityLabel(busy ? "Uploading" : "Upload")
                }
            }
            .interactiveDismissDisabled(busy)
            .onChange(of: photoItem) { item in
                guard let item else { return }
                let load = UUID()
                photoLoad = load
                loadingPhoto = true
                Task {
                    // Said once the photo picker has closed, if the photo is still loading.
                    try? await Task.sleep(nanoseconds: 700_000_000)
                    if photoLoad == load && loadingPhoto { announce("Loading photo.") }
                }
                Task {
                    let jpeg = await jpegData(from: item)
                    guard photoLoad == load else { return }   // a newer pick replaced this one
                    loadingPhoto = false
                    // Cleared, so picking the same photo again (after a failure) is a new pick.
                    photoItem = nil
                    if let jpeg, jpeg.count <= Self.maxBytes {
                        attached(jpeg, as: "photo_\(timestamp()).jpg")
                    } else if jpeg != nil {
                        pickerError("That photo is larger than 25 MB, the most that can be uploaded. Choose a smaller one.")
                    } else {
                        // A photo that can't be read (for example one still in iCloud) used to
                        // fail without a word, leaving Upload disabled for no stated reason.
                        pickerError("Could not read that photo. Choose another, or take a new one.")
                    }
                }
            }
            .fullScreenCover(isPresented: $showingCamera) {
                CameraCapture { image in
                    supersedePhotoLoad()
                    if let jpeg = image.jpegData(compressionQuality: 0.7) {
                        attached(jpeg, as: "scan_\(timestamp()).jpg")
                    } else {
                        pickerError("Could not use that photo. Try taking it again.")
                    }
                }
                .ignoresSafeArea()
            }
            .fileImporter(isPresented: $showingFileImporter, allowedContentTypes: Self.importTypes) { result in
                switch result {
                case .success(let url): Task { await importFile(url) }
                case .failure: pickerError("Could not open that file.")
                }
            }
        }
    }

    /// Checks the type and size first, then reads the file off the main thread, so a large or
    /// refused file never stalls the screen (or VoiceOver) and is never uploaded to be refused.
    private func importFile(_ url: URL) async {
        let load = supersedePhotoLoad()
        guard Self.allowedExtensions.contains(url.pathExtension.lowercased()) else {
            pickerError("That type of file can't be uploaded. Choose a PDF, Word, Excel, PowerPoint, text, RTF or CSV file, or a PNG, JPEG or HEIC image.")
            return
        }
        let accessed = url.startAccessingSecurityScopedResource()
        defer { if accessed { url.stopAccessingSecurityScopedResource() } }
        if let size = try? url.resourceValues(forKeys: [.fileSizeKey]).fileSize, size > Self.maxBytes {
            pickerError("That file is larger than 25 MB, the most that can be uploaded.")
            return
        }
        let data = await Task.detached(priority: .userInitiated) { try? Data(contentsOf: url) }.value
        guard photoLoad == load else { return }   // something chosen after this file replaced it
        guard let data, data.count <= Self.maxBytes else {
            pickerError("Could not read that file (25 MB maximum).")
            return
        }
        guard !data.isEmpty else {
            pickerError("That file is empty. Choose another.")
            return
        }
        attached(data, as: url.lastPathComponent)
    }

    /// A file or scan was chosen: a library photo still loading from an earlier pick is dropped
    /// when it arrives, instead of replacing this choice. Returns the new choice's token.
    @discardableResult
    private func supersedePhotoLoad() -> UUID {
        let load = UUID()
        photoLoad = load
        loadingPhoto = false
        photoItem = nil     // picking that photo again is then a new pick
        return load
    }

    /// The picked library photo as a JPEG; the decode and re-encode run off the main thread.
    private func jpegData(from item: PhotosPickerItem) async -> Data? {
        guard let data = try? await item.loadTransferable(type: Data.self) else { return nil }
        return await Task.detached(priority: .userInitiated) {
            UIImage(data: data)?.jpegData(compressionQuality: 0.7)
        }.value
    }

    /// A refusal of the file, photo or scan just picked. Shown a beat later, as `attached` is:
    /// the camera, photo picker or file browser is still closing, and VoiceOver speaking the
    /// control it returns to cut the message off. The form then moves VoiceOver to it.
    private func pickerError(_ message: String) {
        Task {
            try? await Task.sleep(nanoseconds: 700_000_000)
            showFormError(message, in: &error)
        }
    }

    /// A file is attached and ready: the only on-screen change is a label in the File
    /// section, so it is announced.
    private func attached(_ data: Data, as filename: String) {
        pendingData = data
        pendingFilename = filename
        error = nil
        // A beat later: the camera, photo picker or file browser is still closing.
        Task {
            try? await Task.sleep(nanoseconds: 700_000_000)
            announce("\(filename) attached. Ready to upload.")
        }
    }

    private func timestamp() -> String {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.calendar = Calendar(identifier: .gregorian)
        formatter.dateFormat = "yyyyMMdd_HHmmss"
        return formatter.string(from: Date())
    }

    private func upload() async {
        guard let data = pendingData, let filename = pendingFilename else { return }
        busy = true; error = nil
        announce("Uploading document.")
        do {
            let _: OKResponse = try await api.call("upload_document", body: [
                "client_id": clientId, "category": category,
                "title": title, "filename": filename,
                "data_b64": data.base64EncodedString(),
            ])
            onSaved("Document uploaded.")
            dismiss()
        } catch {
            self.error = error.localizedDescription
            busy = false
        }
    }
}

/// Minimal camera wrapper — returns the captured photo.
struct CameraCapture: UIViewControllerRepresentable {
    @Environment(\.dismiss) private var dismiss
    let onCapture: (UIImage) -> Void

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ controller: UIImagePickerController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        let parent: CameraCapture
        init(_ parent: CameraCapture) { self.parent = parent }

        func imagePickerController(_ picker: UIImagePickerController,
                                   didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]) {
            if let image = info[.originalImage] as? UIImage {
                parent.onCapture(image)
            }
            parent.dismiss()
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) {
            parent.dismiss()
        }
    }
}
