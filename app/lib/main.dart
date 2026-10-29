import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app/app.dart';
import 'app/providers.dart';
import 'core/config.dart';
import 'core/push/background.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Not awaited: the first frame must not wait for a Firebase round trip. The function swallows every failure itself.
  unawaited(registerBackgroundMessages());

  final preferences = await SharedPreferences.getInstance();
  for (final code in AppConfig.locales) {
    await initializeDateFormatting(code);
  }

  runApp(
    ProviderScope(
      overrides: [sharedPreferencesProvider.overrideWithValue(preferences)],
      child: const QistasApp(),
    ),
  );
}
