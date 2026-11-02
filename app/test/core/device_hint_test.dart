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

    test('names the system, the model and the app version, and nothing else', () {
      final hint = deviceHint(shortestSide: 412);

      expect(hint.keys.toSet(), {'type', 'os', 'model', 'app_version'});
      expect(hint['os'], Platform.operatingSystem);
      expect(hint['model'], deviceName());
      expect(hint['app_version'], AppConfig.appVersion);
    });

    test('lets a caller pin the system, the model and the version', () {
      final hint = deviceHint(shortestSide: 700, os: 'ios', model: 'Layla’s iPad', appVersion: '9.9.9');

      expect(hint, {'type': 'tablet', 'os': 'ios', 'model': 'Layla’s iPad', 'app_version': '9.9.9'});
    });

    test('the model is what the list of signed-in devices already shows', () {
      // Not a real handset name: the app has no plugin to ask the phone, so it says what it knows.
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
