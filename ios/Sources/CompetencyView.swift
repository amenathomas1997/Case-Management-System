import SwiftUI

struct Curriculum: Decodable, Identifiable { let id: Int; let name: String; let tech: String? }
struct CurriculaResponse: Decodable { let curricula: [Curriculum] }
struct CompetencySkill: Decodable, Identifiable { let id: Int; let skill: String; let level: Int?; let assessedAt: String? }
struct CompetencyResponse: Decodable { let skills: [CompetencySkill] }
/// `saved` is how many ratings the server wrote (absent on older servers).
struct CompetencySaveResponse: Decodable { let ok: Bool; let saved: Int? }

var competencyLevels: [String] { FormOptions.list("competency_levels", ["Not started", "Introduced", "Practicing", "Proficient", "Mastered"]) }

struct CompetencyView: View {
    @EnvironmentObject var api: API
    @Environment(\.dismiss) private var dismiss
    let clientId: Int
    @State private var curricula: [Curriculum] = []
    @State private var selected: Int?
    @State private var skills: [CompetencySkill] = []
    @State private var levels: [Int: Int] = [:]
    /// The levels as last loaded from the server: only skills that differ from these are saved.
    @State private var loadedLevels: [Int: Int] = [:]
    /// The curriculum whose skills are on screen (nil while a different one is loading).
    @State private var loadedCurriculum: Int?
    @State private var loadingSkills = false
    @State private var error: String?
    @State private var busy = false
    /// "Competency saved." or "Nothing changed."; cleared as soon as a level is changed again.
    @State private var statusLine: String?
    /// Leaving (another curriculum, or Back) while levels are unsaved: asked about first, so
    /// changed ratings are never thrown away without a word.
    @State private var pendingLeave: PendingLeave?
    private enum PendingLeave { case curriculum(Int?), back }

    var mastered: Int { levels.values.filter { $0 == 4 }.count }
    /// Skills whose level was changed here and not saved yet.
    private var unsavedCount: Int {
        guard loadedCurriculum != nil else { return 0 }
        return levels.filter { loadedLevels[$0.key] != $0.value }.count
    }

    var body: some View {
        ScrollViewReader { proxy in
            List {
                if let error { ErrorBanner(message: error).listRowSeparator(.hidden).id("competencyError") }
                if let statusLine { Text(statusLine).font(.callout.weight(.medium)).listRowSeparator(.hidden) }
                Picker("Curriculum", selection: Binding(get: { selected }, set: { choice in
                    guard choice != selected else { return }
                    if unsavedCount > 0 { pendingLeave = .curriculum(choice) } else { selected = choice }
                })) {
                    Text("Choose…").tag(nil as Int?)
                    ForEach(curricula) { c in Text(c.tech != nil ? "\(c.name) (\(c.tech!))" : c.name).tag(c.id as Int?) }
                }
                .onChange(of: selected) { _ in Task { await loadSkills() } }

                if let cur = selected, loadedCurriculum == cur {
                    if skills.isEmpty {
                        Text("This curriculum has no skills.")
                    } else {
                        Section("\(mastered) of \(skills.count) skills mastered") {
                            ProgressView(value: Double(mastered), total: Double(skills.count))
                        }
                        Section("Skills") {
                            ForEach(skills) { s in
                                VStack(alignment: .leading) {
                                    // Both read as part of the picker's name: one stop per skill.
                                    Text(s.skill).font(.callout).accessibilityHidden(true)
                                    // Primary, not secondary: grey caption text is about 3.4:1 on a white row.
                                    if let at = s.assessedAt { Text("Last rated \(formatDate(at))").font(.caption2).accessibilityHidden(true) }
                                    Picker("Level", selection: Binding(get: { levels[s.id] ?? 0 }, set: { levels[s.id] = $0; statusLine = nil })) {
                                        ForEach(0..<5, id: \.self) { Text(competencyLevels[$0]).tag($0) }
                                    }
                                    .pickerStyle(.menu)
                                    .accessibilityLabel("Level for \(s.skill)" + (s.assessedAt.map { ", last rated \(formatDate($0))" } ?? ""))
                                    // Locked while saving: the reload after the save would quietly put
                                    // back a level changed in the meantime.
                                    .disabled(busy)
                                }
                            }
                        }
                        Section {
                            Button { Task { await save() } } label: { Label("Save competency levels", systemImage: "checkmark.circle") }
                                .disabled(busy || loadingSkills)
                        }
                    }
                } else if selected != nil && loadingSkills {
                    ProgressView("Loading skills…")
                }
            }
            // The banner is the list's first row: a failed save from the button at the bottom
            // would otherwise leave it off screen, where it is neither seen nor announced.
            .onChange(of: error) { if $0 != nil { proxy.scrollTo("competencyError", anchor: .top) } }
        }
        // Reloads the skills without throwing away levels changed here and not saved yet.
        .refreshable {
            guard !busy else { return }
            await loadCurricula()
            if let removed = await reloadKeepingChanges(), !removed.isEmpty {
                announce("Removed from this curriculum on the website: \(removed.joined(separator: ", ")).")
            }
        }
        .navigationTitle("Competency")
        .navigationBarTitleDisplayMode(.inline)
        // With unsaved levels, Back (and the VoiceOver escape gesture) asks first.
        .navigationBarBackButtonHidden(unsavedCount > 0)
        .toolbar {
            if unsavedCount > 0 {
                ToolbarItem(placement: .navigationBarLeading) {
                    Button { pendingLeave = .back } label: { Label("Back", systemImage: "chevron.backward").labelStyle(.titleAndIcon) }
                        .accessibilityHint("Asks what to do with your unsaved levels first.")
                }
            }
        }
        .accessibilityAction(.escape) { if unsavedCount > 0 { pendingLeave = .back } else { dismiss() } }
        .confirmationDialog(
            "\(unsavedCount) skill level\(unsavedCount == 1 ? " is" : "s are") not saved yet.",
            isPresented: Binding(get: { pendingLeave != nil }, set: { if !$0 { pendingLeave = nil } }),
            titleVisibility: .visible,
            presenting: pendingLeave
        ) { leave in
            Button("Save, then continue") { Task { if await save() { proceed(leave) } } }
            Button("Discard changes", role: .destructive) { proceed(leave) }
            Button("Keep editing", role: .cancel) { }
        }
        .task { await loadCurricula() }
    }

    private func proceed(_ leave: PendingLeave) {
        switch leave {
        case .curriculum(let choice): selected = choice
        case .back: dismiss()
        }
    }

    private func loadCurricula() async {
        do { error = nil; let r: CurriculaResponse = try await api.call("curricula"); curricula = r.curricula }
        catch { showFormError(error.localizedDescription, in: &self.error) }
    }
    /// `clear` empties the screen first (a different curriculum was chosen) so the previous
    /// curriculum's skills are never left on screen, or saved, under the new name.
    private func loadSkills(clear: Bool = true) async {
        let cur = selected
        if clear { skills = []; levels = [:]; loadedLevels = [:]; loadedCurriculum = nil; statusLine = nil }
        guard let cur else { loadingSkills = false; return }
        loadingSkills = true
        defer { if selected == cur { loadingSkills = false } }
        do {
            error = nil
            let r: CompetencyResponse = try await api.call("client_competency", query: ["client": String(clientId), "curriculum": String(cur)])
            guard selected == cur else { return }   // another curriculum was chosen meanwhile
            let loadedNow = Dictionary(uniqueKeysWithValues: r.skills.map { ($0.id, $0.level ?? 0) })
            skills = r.skills
            levels = loadedNow
            loadedLevels = loadedNow
            loadedCurriculum = cur
            // A newly chosen curriculum's skills appear below the picker with nothing spoken.
            if clear {
                announce(skills.isEmpty ? "This curriculum has no skills."
                         : "\(skills.count) skill\(skills.count == 1 ? "" : "s") loaded, \(mastered) mastered.")
            }
        } catch {
            guard selected == cur else { return }
            showFormError(error.localizedDescription, in: &self.error)
        }
    }
    /// True once the levels are stored (or nothing needed saving); false when the save failed.
    @discardableResult private func save() async -> Bool {
        guard let cur = loadedCurriculum else { return false }
        // Only the skills whose level was changed on this screen: an untouched or never-rated
        // skill must not get a new, re-dated rating (or a level-0 one) every time Save is used.
        var dict: [String: Int] = [:]
        for (k, v) in levels where loadedLevels[k] != v { dict[String(k)] = v }
        guard !dict.isEmpty else {
            error = nil
            statusLine = "Nothing changed. Choose a new level for a skill, then save."
            announce("Nothing changed.")
            return true
        }
        busy = true; error = nil; statusLine = nil
        do {
            let r: CompetencySaveResponse = try await api.call("save_competency", body: ["client_id": clientId, "levels": dict])
            let n = r.saved ?? dict.count
            announce("Competency saved. \(n) skill\(n == 1 ? "" : "s") updated.")
            // What was just saved is now the stored level, even if the refresh below fails.
            for (k, v) in dict { if let skillId = Int(k) { loadedLevels[skillId] = v } }
            await loadSkills(clear: false)
            // loadSkills() can clear the line, so it is set after the reload, not before.
            if selected == cur { statusLine = "Competency saved." }
            busy = false
            return true
        } catch {
            let message = error.localizedDescription
            // A skill removed on the website while this screen was open refuses the whole save.
            // The skills are reloaded, keeping the levels changed here for those still in the
            // curriculum, so the save can be tried again without rating every skill again.
            if let removed = await reloadKeepingChanges(), !removed.isEmpty {
                showFormError("Nothing was saved: \(removed.joined(separator: ", ")) \(removed.count == 1 ? "was" : "were") removed from this curriculum on the website. The list is reloaded and your other changes are kept, so save again.", in: &self.error)
            } else {
                showFormError(message, in: &self.error)
            }
        }
        busy = false
        return false
    }
    /// Reloads the chosen curriculum's skills, keeping each level changed here and not saved for
    /// the skills still in it. Returns the skills that were on screen and are gone, or nil when
    /// the reload failed or another curriculum was chosen (the screen is then left as it was).
    private func reloadKeepingChanges() async -> [String]? {
        guard let cur = loadedCurriculum, selected == cur else { return nil }
        do {
            let r: CompetencyResponse = try await api.call("client_competency", query: ["client": String(clientId), "curriculum": String(cur)])
            guard selected == cur, loadedCurriculum == cur else { return nil }
            let stillThere = Set(r.skills.map(\.id))
            let removed = skills.filter { !stillThere.contains($0.id) }.map(\.skill)
            let unsaved = levels.filter { loadedLevels[$0.key] != $0.value && stillThere.contains($0.key) }
            let loadedNow = Dictionary(uniqueKeysWithValues: r.skills.map { ($0.id, $0.level ?? 0) })
            skills = r.skills
            loadedLevels = loadedNow
            levels = loadedNow.merging(unsaved) { _, mine in mine }
            return removed
        } catch { return nil }
    }
}
