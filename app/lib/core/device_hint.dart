import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';

import 'config.dart';

/// What this phone is called in the list of signed-in devices.
String deviceName() => kIsWeb ? 'Qistas (web browser)' : 'Qistas app (${defaultTargetPlatform.name})';

/// A short description of this device for the person's sign-in history, sent with sign-in and sign-up: a phone or a
/// tablet (a tablet has at least 600 logical pixels on its short side), the system, a name and the app's version.
/// Nothing in it identifies the device; the app has no plugin to ask the phone for more than it already knows.
///
/// [os], [model] and [appVersion] are the real ones unless a caller pins them.
Map<String, dynamic> deviceHint({required double shortestSide, String? os, String? model, String? appVersion}) => {
      'type': shortestSide >= 600 ? 'tablet' : 'mobile',
      'os': os ?? (kIsWeb ? 'web' : Platform.operatingSystem),
      'model': model ?? deviceName(),
      'app_version': appVersion ?? AppConfig.appVersion,
    };

/// Whole minutes this phone's clock is east of UTC right now (negative to the west), which tells the server where the
/// person is without asking for their location.
int utcOffsetMinutes([DateTime? now]) => (now ?? DateTime.now()).timeZoneOffset.inMinutes;
