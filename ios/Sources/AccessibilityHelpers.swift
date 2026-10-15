import SwiftUI

extension View {
    /// A stepper as one VoiceOver element: swipe up or down to change it, instead of
    /// flicking past its label to separate Decrement and Increment buttons.
    func voiceOverAdjustable<V: Strideable>(_ label: String, value: String, binding: Binding<V>,
                                            in range: ClosedRange<V>, step: V.Stride) -> some View {
        accessibilityElement(children: .ignore)
            .accessibilityLabel(label)
            .accessibilityValue(value)
            .accessibilityHint("Swipe up or down to change.")
            .accessibilityAdjustableAction { direction in
                switch direction {
                case .increment: binding.wrappedValue = Swift.min(range.upperBound, binding.wrappedValue.advanced(by: step))
                case .decrement: binding.wrappedValue = Swift.max(range.lowerBound, binding.wrappedValue.advanced(by: -step))
                @unknown default: break
                }
            }
    }
}

/// Hours as VoiceOver should say them: "1 and a half hours", not "1.50".
func hoursSpoken(_ hours: Double) -> String {
    let whole = Int(hours), part = hours - Double(whole)
    let fraction = part == 0.25 ? "a quarter" : part == 0.5 ? "a half" : part == 0.75 ? "three quarters" : nil
    if let fraction { return whole == 0 ? "\(fraction) of an hour" : "\(whole) and \(fraction) hours" }
    return whole == 1 ? "1 hour" : "\(whole) hours"
}
