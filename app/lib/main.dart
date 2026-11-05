import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'app/app.dart';
import 'app/providers.dart';
import 'core/api/api_client.dart';
import 'core/config.dart';
import 'core/errors/error_reporter.dart';
import 'core/push/background.dart';
import 'core/storage/token_store.dart';
import 'data/qistas_api.dart';

void main() {
  // Before anything can fail: crash reports (Settings > Send crash reports, on by default, never in a browser). It tells the
  // server the kind of error and a cleaned trace, and nothing of the person's. The reporter has an API client of its own: a
  // stale token must never sign anyone out from here.
  QistasApi? api;
  final reporting = installErrorReporting(
    preferences: SharedPreferences.getInstance,
    send: (report) => (api ??= QistasApi(ApiClient(baseUrl: AppConfig.apiBaseUrl, tokens: SecureTokenStore(), language: () => 'en', onUnauthorized: () {}))).reportError(report),
  );

  reporting.run(_start);
}

Future<void> _start() async {
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
