import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:qistas/app/app.dart';
import 'package:qistas/app/chrome.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/core/l10n/translations.dart';
import 'package:qistas/core/push/push_service.dart';
import 'package:qistas/core/storage/token_store.dart';
import 'package:qistas/features/security/app_lock.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'fake_api.dart';
import 'fake_device_auth.dart';
import 'samples.dart';
import 'visual.dart';

typedef Route = FutureOr<FakeResponse> Function(RequestOptions options);

/// The API, in memory: a table of "GET /customers" to an answer. A request nobody planned for is a 404, and is
/// remembered so a test can say "this was never asked".
class FakeServer {
  FakeServer(Map<String, Route> routes) : _routes = Map.of(routes);

  final Map<String, Route> _routes;
  late final FakeAdapter adapter = FakeAdapter(_answer);

  /// While true every request fails to connect, as with no signal.
  bool offline = false;

  /// Replace or add an answer while a test runs.
  void on(String route, Route handler) => _routes[route] = handler;

  FutureOr<FakeResponse> _answer(RequestOptions options) {
    if (offline) throw DioException.connectionError(requestOptions: options, reason: 'offline');

    final path = _pathOf(options.path);
    final handler = _routes['${options.method} $path'];

    return handler == null ? apiError(404, 'not_found', 'Nothing planned for ${options.method} $path') : handler(options);
  }

  /// How many times a route was called.
  int calls(String route) => adapter.requests.where((r) => '${r.method} ${_pathOf(r.path)}' == route).length;

  List<RequestOptions> requestsTo(String route) => adapter.requests.where((r) => '${r.method} ${_pathOf(r.path)}' == route).toList();

  /// The path a request was for: a relative one as it is, an absolute link (a signed download) by its path alone.
  static String _pathOf(String path) {
    if (path.startsWith('http://') || path.startsWith('https://')) return Uri.parse(path).path;

    return path.startsWith('/') ? path : '/$path';
  }
}

FutureOr<FakeResponse> Function(RequestOptions) always(FakeResponse response) => (_) => response;

/// A workspace with a dashboard, one customer, one contract and one payment. [routes] add to or replace any of it.
FakeServer workspaceServer({Map<String, dynamic>? account, Map<String, Route> routes = const {}}) => FakeServer({
      'GET /demo': always(json(200, {'data': {'enabled': false, 'hours': 12, 'personas': <Object?>[]}})),
      'GET /me': always(json(200, {'data': account ?? accountJson()})),
      'GET /dashboard': always(json(200, {'data': dashboardJson()})),
      'GET /customers': always(json(200, pageJson([customerJson()], page: 1, last: 1, total: 1))),
      'GET /customers/c1': always(json(200, {'data': {...customerJson(), 'contracts': <Map<String, dynamic>>[]}})),
      'GET /contracts': always(json(200, contractPageJson())),
      'GET /contracts/k1': always(json(200, {'data': contractJson()})),
      'GET /payments': always(json(200, pageJson([lineJson()], page: 1, last: 1, total: 1))),
      ...routes,
    });

/// Starts the real app on a phone-sized screen with the network faked.
///
/// [signedIn] puts a token in the secure store (as after a previous launch); [cachedAccount] is the copy kept in
/// preferences for launching offline.
Future<void> pumpApp(
  WidgetTester tester,
  FakeServer server, {
  bool signedIn = true,
  String language = 'en',
  Size size = const Size(412, 915),
  double textScale = 1,
  Map<String, Object> preferences = const {},
  bool realFonts = false,
  List<Override> overrides = const [],
  DeviceAuth? deviceAuth,
  FakeClock? clock,
  PushService? push,
}) async {
  if (realFonts) await loadBrandFonts();
  for (final code in AppConfig.locales) {
    await initializeDateFormatting(code);
  }
  SharedPreferences.setMockInitialValues({'onboarded': true, 'language': language, ...preferences});
  final prefs = await SharedPreferences.getInstance();

  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  tester.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(() {
    tester.view.reset();
    tester.platformDispatcher.clearTextScaleFactorTestValue();
  });

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        tokenStoreProvider.overrideWithValue(MemoryTokenStore(signedIn ? 'qst_test-token' : null)),
        httpAdapterProvider.overrideWithValue(server.adapter),
        splashDurationProvider.overrideWithValue(Duration.zero),
        brandFontsProvider.overrideWithValue(realFonts),
        // A phone with no fingerprint or screen lock unless a test gives one, so the app lock stays out of the way.
        deviceAuthProvider.overrideWithValue(deviceAuth ?? FakeDeviceAuth.unavailable()),
        if (clock != null) clockProvider.overrideWithValue(clock.call),
        // No Firebase in tests: a phone that takes no pushes unless a test gives one.
        pushServiceProvider.overrideWithValue(push ?? NoPushService()),
        ...overrides,
        // The words are read from disk at once, so a test never waits on asset loading that fake time cannot advance.
        translationsProvider.overrideWith((ref) {
          final language = ref.watch(localeProvider);
          if (language == 'en') return Future.value(const Translations.english());
          final table = jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;

          return Future.value(Translations(language, {for (final entry in table.entries) entry.key: entry.value.toString()}));
        }),
      ],
      child: RepaintBoundary(key: screenshotKey, child: const QistasApp()),
    ),
  );
  await settle(tester);
}

/// Lets the app finish what it is doing. (A spinner never settles, so this waits a bounded amount of time.)
Future<void> settle(WidgetTester tester, {int frames = 30}) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

/// Taps the widget showing [text] and lets the result arrive.
Future<void> tapText(WidgetTester tester, String text, {bool last = false}) async {
  final finder = find.text(text);
  expect(finder, findsWidgets, reason: 'no "$text" on screen');
  await tester.ensureVisible(last ? finder.last : finder.first);
  // Scrolling lands on the next frame; tapping before it would tap where the widget used to be.
  await tester.pump();
  await tester.tap(last ? finder.last : finder.first);
  await settle(tester);
}

/// Types into the field that carries [label].
Future<void> typeInto(WidgetTester tester, String label, String value) async {
  final field = find.ancestor(of: find.text(label), matching: find.byType(Column)).first;
  final input = find.descendant(of: field, matching: find.byType(EditableText)).first;
  await tester.ensureVisible(input);
  await tester.enterText(input, value);
  await tester.pump();
}

/// Types into the field with the key [key] (for fields whose label carries a name or a number).
Future<void> typeIntoKey(WidgetTester tester, String key, String value) async {
  final input = find.descendant(of: find.byKey(ValueKey(key)), matching: find.byType(EditableText)).first;
  await tester.ensureVisible(input);
  await tester.enterText(input, value);
  await tester.pump();
}

/// Taps the app's button labelled [label] (not a heading or app-bar title that happens to say the same).
Future<void> tapButton(WidgetTester tester, String label) async {
  final finder = find.widgetWithText(QButton, label);
  expect(finder, findsWidgets, reason: 'no "$label" button on screen');
  await tester.ensureVisible(finder.first);
  await tester.tap(finder.first);
  await settle(tester);
}

/// Opens settings from the initials at the top of a screen, the way a person does.
Future<void> openSettings(WidgetTester tester) async {
  final finder = find.byType(AccountButton);
  expect(finder, findsWidgets, reason: 'no account button on screen');
  await tester.tap(finder.first);
  await settle(tester);
}

/// Taps an icon button by its tooltip (an icon has no text to find it by).
Future<void> tapTooltip(WidgetTester tester, String message) async {
  final finder = find.byTooltip(message);
  expect(finder, findsWidgets, reason: 'no "$message" button on screen');
  await tester.ensureVisible(finder.first);
  await tester.tap(finder.first);
  await settle(tester);
}

/// The preferences the app wrote while the test ran.
Future<SharedPreferences> preferences() => SharedPreferences.getInstance();
