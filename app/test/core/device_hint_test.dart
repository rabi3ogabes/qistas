import 'dart:io' show Platform;

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/core/device_hint.dart';

void main() {
  group('the device hint', () {
    test('is a tablet from 600 logical pixels on the short side, and a phone below', () {
      expect(deviceHint(shortestSide: 600)['type'], 'tablet');
      expect(deviceHint(shortestSide: 834)['type'], 'tablet');
      expect(deviceHint(shortestSide: 599)['type'], 'mobile');
      expect(deviceHint(shortestSide: 599.9)['type'], 'mobile');
      expect(deviceHint(shortestSide: 320)['type'], 'mobile');
    });

    test('names the system and the app version, and no model: the app has no way to ask the phone for one', () {
      final hint = deviceHint(shortestSide: 412);

      expect(hint.keys.toSet(), {'type', 'os', 'app_version'});
      expect(hint['os'], Platform.operatingSystem);
      expect(hint['app_version'], AppConfig.appVersion);
      expect(hint.values, isNot(contains(deviceName())), reason: 'the Devices list’s name for this phone is not a model');
    });

    test('lets a caller pin the system, the model and the version', () {
      final hint = deviceHint(shortestSide: 700, os: 'ios', model: 'Layla’s iPad', appVersion: '9.9.9');

      expect(hint, {'type': 'tablet', 'os': 'ios', 'model': 'Layla’s iPad', 'app_version': '9.9.9'});
    });

    test('the Devices list still names this phone, though the sign-in history is not told it', () {
      expect(deviceName(), contains('Qistas'));
      expect(deviceName().length, lessThanOrEqualTo(100));
    });
  });

  group('the offset from UTC', () {
    test('is whole minutes east of UTC, within what the server accepts', () {
      final offset = utcOffsetMinutes();

      expect(offset, DateTime.now().timeZoneOffset.inMinutes);
      expect(offset, inInclusiveRange(-840, 840));
    });

    test('follows the moment it is given', () {
      expect(utcOffsetMinutes(DateTime.utc(2026, 11, 1, 12)), 0);
      expect(utcOffsetMinutes(DateTime.utc(2026, 11, 1, 12)), isA<int>());
    });
  });
}
