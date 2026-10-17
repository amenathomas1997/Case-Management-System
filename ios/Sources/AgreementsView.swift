import SwiftUI

/// My agreements (4.15.0): the staff agreements to read and sign by typing your name, and copies
/// of those signed (api_more.php agreements, sign_agreement, agreement_copy).
struct AgreementsView: View {
    @EnvironmentObject var api: API
    @State private var items: [[String: Any]] = []
    @State private var loaded = false
    @State private var error: String?

    var body: some View {
        List {
            if let error { ErrorBanner(message: error).listRowSeparator(.hidden) }
            if !loaded && error == nil { ProgressView("Loading your agreements…") }
            if loaded {
                let needed = items.filter { ($0["state"] as? String) == "needed" }.count
                Text(items.isEmpty ? "There are no agreements for you to sign."
                     : needed > 0 ? "\(needed) of \(items.count) need your signature." : "Every agreement is signed and current.")
            }
            ForEach(Array(items.enumerated()), id: \.offset) { _, a in
                let id = a["id"] as? Int ?? 0
                let needs = (a["state"] as? String) == "needed"
                NavigationLink {
                    AgreementDetailView(id: id, onSigned: { Task { await load() } })
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(a["title"] as? String ?? "Agreement").font(.body.weight(.semibold))
                        Text(needs ? "Needs your signature: " + (a["why"] as? String ?? "").lowercased() : (a["why"] as? String ?? ""))
                            .font(.subheadline).foregroundStyle(Color.secondaryText)
                    }
                    .accessibilityElement(children: .combine)
                }
            }
        }
        .navigationTitle("My agreements")
        .task { await load() }
        .refreshable { await load() }
    }

    private func load() async {
        do {
            let r = try await api.callDict("agreements")
            items = r["agreements"] as? [[String: Any]] ?? []
            loaded = true; error = nil
        } catch { self.error = error.localizedDescription }
    }
}

struct AgreementDetailView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let id: Int
    let onSigned: () -> Void
    @State private var agreement: [String: Any]?
    @State private var agree = false
    @State private var name = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                if let error { ErrorBanner(message: error) }
                if let a = agreement {
                    Text(a["title"] as? String ?? "Agreement").font(.title2.weight(.semibold)).accessibilityAddTraits(.isHeader)
                    Text((a["why"] as? String ?? "") + ".")
                    KBBlocksView(text: a["body"] as? String ?? "")
                    if (a["state"] as? String) == "signed" {
                        if let sid = a["signature_id"] as? Int {
                            NavigationLink("Read your signed copy") { AgreementCopyView(id: sid) }
                        }
                    } else {
                        Text("Sign").font(.headline).accessibilityAddTraits(.isHeader).padding(.top, 8)
                        Toggle("I have read this agreement and agree to it", isOn: $agree)
                        CaptionedField(label: "Type your full name to sign, as it is on your account: \(api.userName)", text: $name)
                            .textContentType(.name)
                        Button {
                            Task { await sign(a) }
                        } label: {
                            Text(busy ? "Signing…" : "Sign the agreement").frame(maxWidth: .infinity, minHeight: 44)
                        }
                        .buttonStyle(.borderedProminent)
                        .disabled(busy || name.trimmingCharacters(in: .whitespaces).isEmpty)
                    }
                } else if error == nil {
                    ProgressView("Loading the agreement…")
                }
            }.padding()
        }
        .navigationTitle("Agreement")
        .navigationBarTitleDisplayMode(.inline)
        .task { await load() }
    }

    private func load() async {
        do {
            let r = try await api.callDict("agreements")
            agreement = (r["agreements"] as? [[String: Any]] ?? []).first { ($0["id"] as? Int) == id }
            if agreement == nil { error = "That agreement is no longer asked for." }
        } catch { self.error = error.localizedDescription }
    }

    private func sign(_ a: [String: Any]) async {
        guard agree else { error = "Turn on that you have read the agreement and agree to it."; announce(error!); return }
        busy = true; error = nil
        do {
            // The fingerprint of the text shown here: a change since it loaded is refused.
            let r = try await api.callDict("sign_agreement", body: ["id": id, "version": a["version"] as? Int ?? 0, "agree": true,
                                                                 "signed_name": name.trimmingCharacters(in: .whitespaces),
                                                                 "text_hash": a["text_hash"] as? String ?? ""])
            onSigned()
            announce(r["message"] as? String ?? "Signed.")
            dismiss()
        } catch {
            busy = false
            self.error = error.localizedDescription
            announce(error.localizedDescription)
            // Changed while open: load the new text, so it is read before signing.
            if error.localizedDescription.contains("changed while you had it open") { agree = false; await load() }
        }
    }
}

struct AgreementCopyView: View {
    @EnvironmentObject var api: API
    let id: Int
    @State private var copy: [String: Any]?
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                if let error { ErrorBanner(message: error) }
                if let c = copy {
                    Text((c["title"] as? String ?? "Agreement") + ": your signed copy").font(.title2.weight(.semibold)).accessibilityAddTraits(.isHeader)
                    Text("Signed by \(c["signed_name"] as? String ?? "") on \(formatDateTime(c["signed_at"] as? String ?? "")), version \(c["version"] as? Int ?? 0). This is the text exactly as it read when you signed.")
                    KBBlocksView(text: c["body"] as? String ?? "")
                } else if error == nil { ProgressView("Loading your signed copy…") }
            }.padding()
        }
        .navigationTitle("Signed copy")
        .navigationBarTitleDisplayMode(.inline)
        .task {
            do { copy = try await api.callDict("agreement_copy", query: ["id": String(id)])["copy"] as? [String: Any] }
            catch { self.error = error.localizedDescription }
        }
    }
}
