import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/config.dart';

/// The iPhone project (Sprint 2 Task 9, PL02): what Apple asks of it before any developer account exists. Only a Mac can
/// build it, so these checks run everywhere and the iPhone workflow builds it on GitHub's Macs.
void main() {
  final plist = File('ios/Runner/Info.plist').readAsStringSync();
  final project = File('ios/Runner.xcodeproj/project.pbxproj').readAsStringSync();
  String? plistString(String key) => RegExp('<key>$key</key>\\s*<string>([^<]*)</string>').firstMatch(plist)?.group(1);

  // The camera (scanning a product's code, photographing a contract or a notebook page), the photo library (a logo or a
  // photo already taken) and Face ID (the app lock). Picking a contact needs no permission on iOS.
  const reasons = ['NSCameraUsageDescription', 'NSPhotoLibraryUsageDescription', 'NSFaceIDUsageDescription'];

  test('explains every permission its parts ask for: the camera, the photos and Face ID', () {
    for (final key in reasons) {
      expect(plistString(key), isNotNull, reason: key);
      expect(plistString(key)!.length, greaterThan(20), reason: key);
    }
  });

  test('explains them in every language the app speaks, and names itself in each', () {
    for (final language in AppConfig.locales) {
      final strings = File('ios/Runner/$language.lproj/InfoPlist.strings').readAsStringSync();
      for (final key in [...reasons, 'CFBundleDisplayName']) {
        expect(RegExp('^"$key" = "[^"]+";\$', multiLine: true).hasMatch(strings), isTrue, reason: '$language: $key');
      }
      expect(project, contains('path = $language.lproj/InfoPlist.strings;'), reason: language);
      expect(RegExp('knownRegions = \\([^)]*\\b$language,', multiLine: true).hasMatch(project), isTrue, reason: language);
      expect(plist, contains('<string>$language</string>'), reason: 'CFBundleLocalizations: $language');
    }
  });

  test('targets iOS 15, which Firebase needs, in the project and in the Podfile', () {
    expect(RegExp(r'IPHONEOS_DEPLOYMENT_TARGET = ([\d.]+);').allMatches(project).map((m) => m.group(1)).toSet(), {'15.0'});
    expect(File('ios/Podfile').readAsStringSync(), contains("platform :ios, '15.0'"));
  });

  test('can take pushes, and says it uses only standard encryption', () {
    expect(plist, contains('<string>remote-notification</string>'));
    expect(RegExp(r'<key>ITSAppUsesNonExemptEncryption</key>\s*<false/>').hasMatch(plist), isTrue);
    expect(File('ios/Runner/Runner.entitlements').readAsStringSync(), contains('<key>aps-environment</key>'));
    // Debug, Release and Profile.
    expect('CODE_SIGN_ENTITLEMENTS = Runner/Runner.entitlements;'.allMatches(project), hasLength(3));
    // The Firebase settings are copied in when a build has them, and a build without them still works.
    expect(project, contains('GoogleService-Info.plist'));
  });

  test('has the same id as the Android app', () {
    expect(project, contains('PRODUCT_BUNDLE_IDENTIFIER = com.qistas.qistas;'));
  });

  test('is built on every change, and signed for TestFlight only once the owner’s secrets exist', () {
    final workflow = File('../.github/workflows/ios.yml').readAsStringSync();
    expect(workflow, contains('flutter build ios --release --no-codesign'));
    expect(workflow, contains("if: needs.build.outputs.signing == 'true'"));
    final export = File('ios/ExportOptions.plist').readAsStringSync();
    expect(export, contains('<string>app-store-connect</string>'));
    expect(export, contains('<string>upload</string>'));
  });
}
