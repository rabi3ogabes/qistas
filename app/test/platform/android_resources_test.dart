import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter_test/flutter_test.dart';

/// The Android files that decide what a person sees before the app draws anything: the icon on the home screen, its
/// name, and the launch screen. They are generated (tool/icons.py, then flutter_launcher_icons), and a careless re-run
/// once left Flutter's own logo in the APK, so these tests read the files themselves.

const res = 'android/app/src/main/res';

String read(String path) => File(path).readAsStringSync();

/// The colour of one pixel of a PNG, as (r, g, b, a).
Future<(int, int, int, int)> pixel(WidgetTester tester, String path, int x, int y) async {
  return (await tester.runAsync(() async {
    final codec = await ui.instantiateImageCodec(File(path).readAsBytesSync());
    final image = (await codec.getNextFrame()).image;
    final bytes = (await image.toByteData(format: ui.ImageByteFormat.rawRgba))!;
    final i = (y * image.width + x) * 4;

    return (bytes.getUint8(i), bytes.getUint8(i + 1), bytes.getUint8(i + 2), bytes.getUint8(i + 3));
  }))!;
}

bool isNavy((int, int, int, int) p) => p.$1 < 40 && p.$2 < 70 && p.$3 > 50 && p.$3 < 130 && p.$4 == 255;

void main() {
  group('the home-screen icon', () {
    testWidgets('is the Qistas mark on navy at every density, not Flutter\'s logo', (tester) async {
      for (final density in ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi']) {
        final path = '$res/mipmap-$density/ic_launcher.png';
        expect(isNavy(await pixel(tester, path, 3, 3)), isTrue, reason: '$path corner is not navy');
      }
    });

    test('is adaptive, with the mark in front of navy and a single-colour layer for themed icons', () {
      final xml = read('$res/mipmap-anydpi-v26/ic_launcher.xml');

      expect(xml, contains('@color/ic_launcher_background'));
      expect(xml, contains('@drawable/ic_launcher_foreground'));
      expect(xml, contains('@drawable/ic_launcher_monochrome'));
      // The layers already keep the mark in the safe zone; an extra inset makes it small.
      expect(xml, isNot(contains('android:inset="16%"')));
      expect(read('$res/values/colors.xml'), contains('<color name="ic_launcher_background">#0B1F44</color>'));
    });

    test('is called Qistas, and قسطاس on a phone in Arabic or Urdu', () {
      expect(read('android/app/src/main/AndroidManifest.xml'), contains('android:label="@string/app_name"'));
      expect(read('$res/values/strings.xml'), contains('<string name="app_name">Qistas</string>'));
      expect(read('$res/values-ar/strings.xml'), contains('<string name="app_name">قسطاس</string>'));
      expect(read('$res/values-ur/strings.xml'), contains('<string name="app_name">قسطاس</string>'));
    });
  });

  group('the launch screen', () {
    test('is the app\'s own canvas with the mark, by day and by night, on every Android version', () {
      for (final drawable in ['drawable', 'drawable-v21']) {
        final xml = read('$res/$drawable/launch_background.xml');
        expect(xml, contains('@color/launch_canvas'));
        expect(xml, contains('@drawable/launch_mark'));
      }

      expect(read('$res/values/colors.xml'), contains('<color name="launch_canvas">#F7F3EA</color>'));
      expect(read('$res/values-night/colors.xml'), contains('<color name="launch_canvas">#071634</color>'));

      for (final folder in ['values-v31', 'values-night-v31']) {
        final xml = read('$res/$folder/styles.xml');
        expect(xml, contains('android:windowSplashScreenBackground">@color/launch_canvas'));
        expect(xml, contains('android:windowSplashScreenAnimatedIcon">@drawable/splash_mark'));
      }
    });

    testWidgets('has a navy mark for the light canvas and an ivory one for the dark', (tester) async {
      // The middle of the stem of the q, where the mark is solid.
      final day = await pixel(tester, '$res/drawable-xxxhdpi/launch_mark.png', 250, 100);
      final night = await pixel(tester, '$res/drawable-night-xxxhdpi/launch_mark.png', 250, 100);

      expect(isNavy(day), isTrue, reason: 'day mark $day');
      expect(night.$1 > 230 && night.$2 > 230 && night.$3 > 220, isTrue, reason: 'night mark $night');
    });
  });
}
