import SwiftUI
import WidgetKit

// The home-screen widget: how many sessions there are today, when the next one starts, and
// whether it is checked in. It reads the day summary the app saves when the schedule loads
// (TodaySnapshot, in the shared App Group container) and never a client's name: the home
// screen is seen by anyone holding the phone (PHI stays behind sign-in and the app lock).

struct TodayEntry: TimelineEntry {
    let date: Date
    /// Nil when the app has not saved today's schedule (not signed in, not opened today, or the
    /// shared container is not available to this build).
    let snapshot: TodaySnapshot?

    var count: Int { snapshot?.sessions.count ?? 0 }
    var next: TodaySnapshot.Entry? { snapshot?.next(at: date) }

    /// "3 sessions today" / "No sessions today".
    var countText: String {
        guard snapshot != nil else { return "Open the app to see today's sessions" }
        switch count {
        case 0: return "No sessions today"
        case 1: return "1 session today"
        default: return "\(count) sessions today"
        }
    }

    /// "Next at 2:00 PM" / "Started at 2:00 PM" / "All sessions have ended".
    var nextText: String? {
        guard snapshot != nil, count > 0 else { return nil }
        guard let next else { return "All sessions have ended" }
        let time = next.start.formatted(date: .omitted, time: .shortened)
        return next.start <= date ? "Started at \(time)" : "Next at \(time)"
    }

    var checkedInText: String? {
        guard let next else { return nil }
        return next.checkedIn ? "Checked in" : "Not checked in"
    }

    /// Everything the widget shows, as one VoiceOver sentence.
    var spoken: String {
        [countText, nextText, checkedInText].compactMap { $0 }.joined(separator: ". ")
    }
}

struct TodayProvider: TimelineProvider {
    func placeholder(in context: Context) -> TodayEntry {
        let now = Date()
        let sample = TodaySnapshot(day: TodaySnapshot.dayString(now),
                                   sessions: [.init(start: now.addingTimeInterval(3600), end: now.addingTimeInterval(7200), checkedIn: false),
                                              .init(start: now.addingTimeInterval(10800), end: nil, checkedIn: false)],
                                   savedAt: now)
        return TodayEntry(date: now, snapshot: sample)
    }

    func getSnapshot(in context: Context, completion: @escaping (TodayEntry) -> Void) {
        completion(context.isPreview ? placeholder(in: context) : current(Date()))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<TodayEntry>) -> Void) {
        let now = Date()
        let entry = current(now)
        // The text changes when a session starts or ends, and at midnight (the summary is for
        // one day); the app reloads the timeline itself whenever the schedule loads.
        var dates: [Date] = (entry.snapshot?.sessions ?? []).flatMap { [$0.start, $0.end].compactMap { $0 } }
            .filter { $0 > now }
        if let midnight = Calendar.current.nextDate(after: now, matching: DateComponents(hour: 0, minute: 0), matchingPolicy: .nextTime) {
            dates.append(midnight)
        }
        let entries = [entry] + dates.sorted().map { date in current(date) }
        completion(Timeline(entries: entries, policy: .atEnd))
    }

    private func current(_ date: Date) -> TodayEntry {
        let snapshot = TodaySnapshot.load()
        // A summary from another day says nothing about this one.
        return TodayEntry(date: date, snapshot: snapshot?.day == TodaySnapshot.dayString(date) ? snapshot : nil)
    }
}

struct TodayWidgetView: View {
    @Environment(\.widgetFamily) private var family
    @Environment(\.dynamicTypeSize) private var typeSize
    let entry: TodayEntry

    var body: some View {
        content
            .accessibilityElement(children: .ignore)
            .accessibilityLabel("Today's sessions")
            .accessibilityValue(entry.spoken)
    }

    @ViewBuilder
    private var content: some View {
        if #available(iOS 17.0, *) {
            layout.containerBackground(Color(.systemBackground), for: .widget)
        } else {
            layout.padding().background(Color(.systemBackground))
        }
    }

    /// Large text in the small widget (4.30.0): four lines did not fit and the last one, the
    /// check-in, was dropped without a sign. There the "Today" heading goes, every line may
    /// shrink a little, and the next time and check-in keep their room before the count.
    private var cramped: Bool { family == .systemSmall && typeSize >= .xxLarge }

    private var layout: some View {
        VStack(alignment: .leading, spacing: cramped ? 2 : 6) {
            if !cramped {
                Label("Today", systemImage: "calendar")
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(WidgetColors.secondaryText)
                    .minimumScaleFactor(0.7)
            }
            if entry.snapshot == nil {
                Text(entry.countText)
                    .font(.callout.weight(.medium))
                    .minimumScaleFactor(0.7)
            } else {
                Text(entry.countText)
                    .font(family == .systemSmall ? .headline : .title3.weight(.semibold))
                    .minimumScaleFactor(0.7)
                    .layoutPriority(1)
                if let next = entry.nextText {
                    Text(next).font(.callout)
                        .minimumScaleFactor(0.7)
                        .layoutPriority(2)
                }
                if let checked = entry.checkedInText {
                    Label(checked, systemImage: entry.next?.checkedIn == true ? "checkmark.circle.fill" : "circle")
                        .font(.callout.weight(.medium))
                        .foregroundStyle(entry.next?.checkedIn == true ? WidgetColors.checkedIn : WidgetColors.secondaryText)
                        .minimumScaleFactor(0.7)
                        .lineLimit(1)
                        .layoutPriority(3)
                }
            }
            Spacer(minLength: 0)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
    }
}

/// Text colours at 4.5:1 or better on the widget's background (4.27.0): the system's
/// .secondary and green are about 3.4:1 and 2.2:1 on white. Secondary #45454F (9.5:1 on white)
/// and #D1D1D6 (13.8:1 on black); checked in #116329 (7.4:1 on white) and #7EE787 (13.7:1 on black).
enum WidgetColors {
    static let secondaryText = Color(UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 209/255, green: 209/255, blue: 214/255, alpha: 1)
            : UIColor(red: 69/255, green: 69/255, blue: 79/255, alpha: 1)
    })
    static let checkedIn = Color(UIColor { traits in
        traits.userInterfaceStyle == .dark
            ? UIColor(red: 126/255, green: 231/255, blue: 135/255, alpha: 1)
            : UIColor(red: 17/255, green: 99/255, blue: 41/255, alpha: 1)
    })
}

@main
struct CaseManagementWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: TodaySnapshot.widgetKind, provider: TodayProvider()) { entry in
            TodayWidgetView(entry: entry)
        }
        .configurationDisplayName("Today's sessions")
        .description("How many sessions are scheduled today, when the next one starts, and whether it is checked in. Never shows a client's name.")
        .supportedFamilies([.systemSmall, .systemMedium])
    }
}
