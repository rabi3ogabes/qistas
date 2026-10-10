import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Firebase pushes (Win Plan PP9) need the owner's Firebase project file. CI writes android/app/google-services.json from
// a GitHub secret just before a build; it is never in the repository. Without it the app builds and runs as before, and
// alerts wait in its inbox instead of arriving as pushes.
if (file("google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

// The owner's upload key for Google Play. CI writes android/key.properties (and the keystore it points to) from GitHub
// secrets just before a build; neither is ever in the repository (see android/.gitignore and docs/store). Without it
// the release build is signed with the debug key: a test build that installs from GitHub but cannot go to Play.
val uploadKeyProperties = Properties()
val uploadKeyFile = rootProject.file("key.properties")
val hasUploadKey = uploadKeyFile.exists()
if (hasUploadKey) {
    FileInputStream(uploadKeyFile).use { uploadKeyProperties.load(it) }
}

android {
    namespace = "com.qistas.qistas"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        applicationId = "com.qistas.qistas"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            if (hasUploadKey) {
                keyAlias = uploadKeyProperties["keyAlias"] as String
                keyPassword = uploadKeyProperties["keyPassword"] as String
                storeFile = file(uploadKeyProperties["storeFile"] as String)
                storePassword = uploadKeyProperties["storePassword"] as String
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hasUploadKey) signingConfigs.getByName("release") else signingConfigs.getByName("debug")
        }
    }
}

flutter {
    source = "../.."
}

dependencies {
    // The launch and normal themes are AppCompat themes, which the app lock's fingerprint prompt needs on older phones.
    implementation("androidx.appcompat:appcompat:1.7.0")
}
