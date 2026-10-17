plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
}

fun cms(key: String): String = (project.findProperty("cms.$key") as String?) ?: ""
fun quoted(s: String): String = "\"" + s.replace("\\", "\\\\").replace("\"", "\\\"") + "\""

android {
    namespace = "org.casemanagement.app"
    compileSdk = 35

    defaultConfig {
        applicationId = cms("appId")
        minSdk = 26
        targetSdk = 35
        versionCode = cms("versionCode").toInt()
        versionName = cms("versionName")
        buildConfigField("String", "DEFAULT_SERVER", quoted(cms("defaultServer")))
        buildConfigField("String", "SPOKEN_NAME", quoted(cms("spokenName")))
        buildConfigField("String", "SIGNIN_TITLE", quoted(cms("signinTitle")))
        resValue("string", "app_name", cms("displayName"))
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures {
        compose = true
        buildConfig = true
    }
    testOptions { unitTests.isReturnDefaultValues = true }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.06.00")
    implementation(composeBom)
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-compose:1.9.2")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.2")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.2")
    implementation("androidx.navigation:navigation-compose:2.8.0")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-core")
    implementation("androidx.security:security-crypto:1.1.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.json:json:20240303")
}
