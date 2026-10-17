# The app reads the server's replies with org.json (part of Android), so nothing of its own needs keeping.
# The encrypted storage (androidx.security, built on Tink) names compile-time-only annotations
# that are not in the app; they are never used at run time.
-dontwarn com.google.errorprone.annotations.**
-dontwarn javax.annotation.**
