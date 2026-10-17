import Foundation
import Security

/// Minimal Keychain wrapper for the API session token.
/// Hardware-encrypted, this-device-only, excluded from backups, and readable only while the
/// phone is unlocked (the app has no background work that needs it while locked).
enum Keychain {
    /// The app's own ID (4.6.0: CMS_BUNDLE_ID), so each organisation's build keeps its own items.
    private static let service = Bundle.main.bundleIdentifier ?? "cms"

    static func save(_ value: String, for key: String) {
        let base: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
        ]
        SecItemDelete(base as CFDictionary)
        guard !value.isEmpty, let data = value.data(using: .utf8) else { return }
        var attributes = base
        attributes[kSecValueData as String] = data
        attributes[kSecAttrAccessible as String] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly
        SecItemAdd(attributes as CFDictionary, nil)
    }

    static func load(_ key: String) -> String {
        let base: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
        ]
        var query = base
        query[kSecReturnData as String] = true
        query[kSecReturnAttributes as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var result: AnyObject?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let item = result as? [String: Any],
              let data = item[kSecValueData as String] as? Data,
              let value = String(data: data, encoding: .utf8) else { return "" }
        // An item saved by an earlier build could be read while the phone was locked: it moves
        // to the when-unlocked class in place (if that fails it stays as it was, and is tried
        // again at the next launch).
        if (item[kSecAttrAccessible as String] as? String) != (kSecAttrAccessibleWhenUnlockedThisDeviceOnly as String) {
            SecItemUpdate(base as CFDictionary,
                          [kSecAttrAccessible as String: kSecAttrAccessibleWhenUnlockedThisDeviceOnly] as CFDictionary)
        }
        return value
    }

    static func delete(_ key: String) {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
        ]
        SecItemDelete(query as CFDictionary)
    }
}
