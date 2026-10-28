import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:qistas/app/app.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/app/shell.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/core/l10n/translations.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/core/storage/token_store.dart';
import 'package:qistas/features/glance/glance_data.dart';
import 'package:qistas/features/glance/glance_encoder.dart';
import 'package:qistas/features/glance/glance_privacy.dart';
import 'package:qistas/features/glance/glance_service.dart';
import 'package:qistas/features/glance/glance_store.dart';
import 'package:qistas/features/security/app_lock.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/fake_glance_store.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The home-screen widget, app side (daily-habit wave A, H3): the snapshot the app reads, the privacy rules that decide
/// what may reach a screen anyone can see, the exact strings the Android widget draws, and the settings to change them.

/// `GET /glance` as the server writes it: three instalments coming, with customers' names only when asked for.
Map<String, dynamic> glanceJson({bool names = false, int due = 3, String dueAmount = '825.00', int late = 2, String lateAmount = '400.00', int rows = 3}) => {
      'enabled': true,
      'currency': 'SAR',
      'due_count': due,
      'due_amount': dueAmount,
      'late_count': late,
      'late_amount': lateAmount,
      'next': [
        for (final row in [
          ('C-0007', '275.00', '2026-10-07', 'Ahmad Salem'),
          ('C-0011', '150.00', '2026-10-07', 'Badr Khan'),
          ('C-0003', '400.00', '2026-10-09', 'Dalia Amin'),
        ].take(rows))
          {'reference': row.$1, 'amount': row.$2, 'due_on': row.$3, if (names) 'name': row.$4},
      ],
      'generated_at': '2026-10-07T06:05:00Z',
    };

final DateTime moment = DateTime(2026, 10, 7, 9, 5);

Translations wordsFor(String language) {
  if (language == 'en') return const Translations.english();
  final table = jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;

  return Translations(language, {for (final entry in table.entries) entry.key: entry.value.toString()});
}

Map<String, String> encode(
  Map<String, dynamic> json, {
  GlancePrivacy privacy = const GlancePrivacy(),
  bool lock = false,
  String language = 'en',
  DateTime? now,
}) {
  final words = wordsFor(language);

  return encodeGlance(GlanceData.fromJson(json), privacy: privacy, appLockOn: lock, language: language, rtl: words.isRtl, words: words, now: now ?? moment);
}

/// The app and the widget store without the screens: sign in from a token, then ask the service for things.
class Rig {
  Rig(this.container, this.store, this.server);

  final ProviderContainer container;
  final FakeGlanceStore store;
  final FakeServer server;

  GlanceService get service => container.read(glanceServiceProvider);
}

Future<Rig> rig({
  Map<String, dynamic>? account,
  Map<String, Route> routes = const {},
  Map<String, Object> prefs = const {},
  DeviceAuth? deviceAuth,
}) async {
  SharedPreferences.setMockInitialValues({'language': 'en', ...prefs});
  final preferences = await SharedPreferences.getInstance();
  final server = workspaceServer(account: account ?? accountWithWidget(), routes: {
    'GET /glance': always(json(200, {'data': glanceJson()})),
    'POST /auth/logout': always(json(200, <String, dynamic>{})),
    ...routes,
  });
  final store = FakeGlanceStore();
  final container = ProviderContainer(overrides: [
    sharedPreferencesProvider.overrideWithValue(preferences),
    tokenStoreProvider.overrideWithValue(MemoryTokenStore('qst_test-token')),
    httpAdapterProvider.overrideWithValue(server.adapter),
    deviceAuthProvider.overrideWithValue(deviceAuth ?? FakeDeviceAuth.unavailable()),
    glanceStoreProvider.overrideWithValue(store),
    glanceSupportedProvider.overrideWithValue(true),
    clockProvider.overrideWithValue(() => moment),
    translationsProvider.overrideWith((ref) => Future.value(wordsFor(ref.watch(localeProvider)))),
  ]);
  addTearDown(container.dispose);
  await container.read(authProvider.future);

  return Rig(container, store, server);
}

List<Override> widgetOverrides(FakeGlanceStore store, {bool supported = true}) => [
      glanceStoreProvider.overrideWithValue(store),
      glanceSupportedProvider.overrideWithValue(supported),
    ];

FakeServer widgetServer({Map<String, Route> routes = const {}}) =>
    workspaceServer(account: accountWithWidget(), routes: {'GET /glance': always(json(200, {'data': glanceJson()})), ...routes});

void main() {
  setUpAll(() async {
    for (final code in AppConfig.locales) {
      await initializeDateFormatting(code);
    }
  });

  group('reading the server’s answer', () {
    test('keeps amounts exact and customers’ names only when they came', () {
      final data = GlanceData.fromJson(glanceJson(dueAmount: '1200.50', lateAmount: '0.10', names: true));

      expect(data.enabled, isTrue);
      expect(data.currency, 'SAR');
      expect(data.dueCount, 3);
      expect(data.dueAmount, Money.parse('1200.50'));
      expect(data.lateAmount.cents, BigInt.from(10));
      expect(data.next.map((row) => row.reference), ['C-0007', 'C-0011', 'C-0003']);
      expect(data.next.first.amount, Money.parse('275.00'));
      expect(data.next.first.dueOn, '2026-10-07');
      expect(data.next.first.name, 'Ahmad Salem');
      expect(GlanceData.fromJson(glanceJson()).next.first.name, isNull);
    });

    test('reads a workspace without the feature as only "off"', () {
      final data = GlanceData.fromJson({'enabled': false});

      expect(data.enabled, isFalse);
      expect(data.next, isEmpty);
      expect(data.dueAmount.isZero, isTrue);
    });
  });

  group('what reaches the widget', () {
    test('shows counts and amounts by default and no names', () {
      final lines = encode(glanceJson(names: true));

      expect(lines, {
        'enabled': '1',
        'rtl': '0',
        'due_line': 'Due today: 3',
        'late_line': 'Late: 2',
        'amount_line': 'Due SAR 825.00 · Late SAR 400.00',
        'next_1': 'C-0007 · SAR 275.00 · Oct 7',
        'next_2': 'C-0011 · SAR 150.00 · Oct 7',
        'next_3': 'C-0003 · SAR 400.00 · Oct 9',
        'updated': '09:05',
        'updated_epoch_ms': '${moment.millisecondsSinceEpoch}',
      });
      expect(lines.values.join(' '), isNot(contains('Ahmad')), reason: 'names stay off the home screen unless the owner allowed them');
    });

    test('shows counts only when the app lock is on', () {
      final lines = encode(glanceJson(names: true), privacy: const GlancePrivacy(showAmounts: true, showNames: true), lock: true);

      expect(lines['enabled'], '1');
      expect(lines['due_line'], 'Due today: 3');
      expect(lines['late_line'], 'Late: 2');
      for (final key in ['amount_line', 'next_1', 'next_2', 'next_3']) {
        expect(lines[key], '', reason: '$key is empty behind the app lock');
      }
      final everything = lines.values.join(' ');
      for (final secret in ['SAR', '825', '275', 'Ahmad', 'Badr', 'C-0007']) {
        expect(everything, isNot(contains(secret)), reason: '"$secret" must not reach a locked phone’s home screen');
      }
    });

    test('hides amounts when the owner chose to', () {
      final lines = encode(glanceJson(names: true), privacy: const GlancePrivacy(showAmounts: false, showNames: true));

      expect(lines['due_line'], 'Due today: 3');
      expect(lines['amount_line'], '');
      for (final key in ['next_1', 'next_2', 'next_3']) {
        expect(lines[key], '');
      }
      expect(lines.values.join(' '), allOf(isNot(contains('SAR')), isNot(contains('Ahmad'))), reason: 'names need amounts: hidden amounts hide names too');
    });

    test('shows names only when allowed, amounts shown, lock off', () {
      const allowed = GlancePrivacy(showAmounts: true, showNames: true);
      final data = glanceJson(names: true);

      expect(encode(data, privacy: allowed)['next_1'], 'Ahmad Salem · SAR 275.00 · Oct 7');
      expect(encode(data, privacy: allowed)['next_3'], 'Dalia Amin · SAR 400.00 · Oct 9');

      expect(encode(data, privacy: const GlancePrivacy(showAmounts: true, showNames: false))['next_1'], isNot(contains('Ahmad')));
      expect(encode(data, privacy: const GlancePrivacy(showAmounts: false, showNames: true))['next_1'], '');
      expect(encode(data, privacy: allowed, lock: true)['next_1'], '');
    });

    test('falls back to the contract number when the server sent no name', () {
      expect(encode(glanceJson(), privacy: const GlancePrivacy(showAmounts: true, showNames: true))['next_1'], 'C-0007 · SAR 275.00 · Oct 7');
    });

    test('leaves a row empty when there are fewer than three, and tells the widget how many', () {
      final lines = encode(glanceJson(rows: 1));

      expect(lines['next_1'], isNotEmpty);
      expect(lines['next_2'], '');
      expect(lines['next_3'], '');
    });

    test('marks right-to-left for Arabic and Urdu', () {
      expect(encode(glanceJson(), language: 'ar')['rtl'], '1');
      expect(encode(glanceJson(), language: 'ur')['rtl'], '1');
      for (final language in ['en', 'fr', 'es']) {
        expect(encode(glanceJson(), language: language)['rtl'], '0', reason: language);
      }
    });

    test('writes the lines in the app’s language, with Western digits', () {
      final lines = encode(glanceJson(), language: 'ar');

      expect(lines['due_line'], 'الأقساط المستحقة اليوم: 3');
      expect(lines['late_line'], isNot(contains('Late')));
      expect(lines['amount_line'], contains('SAR 825.00'));
    });

    test('writes the updated time in the app’s language', () {
      expect(encode(glanceJson())['updated'], '09:05');
      expect(encode(glanceJson(), language: 'ar')['updated'], '09:05', reason: 'Western digits in every language, as everywhere in the app');
      expect(encode(glanceJson(), language: 'es')['updated'], '9:05', reason: 'Spanish writes the hour without a leading zero');
      expect(encode(glanceJson(), now: DateTime(2026, 10, 7, 21, 30))['updated'], '21:30');
      expect(encode(glanceJson())['updated_epoch_ms'], '${moment.millisecondsSinceEpoch}');
    });

    test('says "off" with every figure blanked when the feature is not enabled', () {
      final lines = encode({'enabled': false});

      expect(lines['enabled'], '0');
      expect(lines['due_line'], 'Open Qistas to refresh');
      for (final key in ['late_line', 'amount_line', 'next_1', 'next_2', 'next_3']) {
        expect(lines[key], '', reason: key);
      }
      expect(lines.keys, unorderedEquals(glanceKeys));
    });
  });

  group('the service', () {
    test('writes the snapshot after a successful read', () async {
      final r = await rig();

      await r.service.refresh();

      expect(r.server.calls('GET /glance'), 1);
      expect(r.store.writes, hasLength(1));
      expect(r.store.last['enabled'], '1');
      expect(r.store.last['due_line'], 'Due today: 3');
      expect(r.store.last['amount_line'], 'Due SAR 825.00 · Late SAR 400.00');
      expect(r.store.last['updated'], '09:05');
      expect(r.store.redraws, 1, reason: 'the widget is told to draw what was written');
      expect(r.store.clears, 0);
    });

    test('hands the widget the app’s language and direction', () async {
      final r = await rig(prefs: {'language': 'ar'});

      await r.service.refresh();

      expect(r.store.last['rtl'], '1');
      expect(r.store.last['due_line'], 'الأقساط المستحقة اليوم: 3');
    });

    test('asks the server for names only when the privacy rules allow', () async {
      final cases = <(String, Map<String, Object>, String)>[
        ('by default', {}, '0'),
        ('when the owner allows names', {GlancePrivacyController.namesKey: true}, '1'),
        ('when names are allowed but amounts are hidden', {GlancePrivacyController.namesKey: true, GlancePrivacyController.amountsKey: false}, '0'),
        ('when names are allowed but the app lock is on', {GlancePrivacyController.namesKey: true, AppLockController.enabledKey: true}, '0'),
      ];

      for (final (label, prefs, expected) in cases) {
        final r = await rig(prefs: prefs, routes: {'GET /glance': (options) => json(200, {'data': glanceJson(names: options.queryParameters['names'].toString() == '1')})});
        await r.service.refresh();

        expect(r.server.requestsTo('GET /glance').single.queryParameters['names'].toString(), expected, reason: label);
        if (expected == '0') expect(r.store.last.values.join(' '), isNot(contains('Ahmad')), reason: label);
      }
    });

    test('a business that requires the app lock keeps names and amounts off the widget too', () async {
      final locked = accountWithWidget();
      (locked['tenant'] as Map<String, dynamic>)['require_app_lock'] = true;
      final r = await rig(account: locked, prefs: {GlancePrivacyController.namesKey: true});

      await r.service.refresh();

      expect(r.server.requestsTo('GET /glance').single.queryParameters['names'].toString(), '0');
      expect(r.store.last['amount_line'], '');
    });

    test('writes the disabled state when the server says enabled false', () async {
      final r = await rig(routes: {'GET /glance': always(json(200, {'data': {'enabled': false}}))});

      await r.service.refresh();

      expect(r.store.writes, hasLength(1));
      expect(r.store.last['enabled'], '0');
      expect(r.store.last['amount_line'], '');
      expect(r.store.last['next_1'], '');
      expect(r.store.last['due_line'], 'Open Qistas to refresh');
      expect(r.store.clears, 0);
    });

    test('does not ask the server while the workspace lacks the feature, and tells the widget once', () async {
      final r = await rig(account: accountJson());

      await r.service.refresh();
      await r.service.refresh();

      expect(r.server.calls('GET /glance'), 0);
      expect(r.store.writes, hasLength(1));
      expect(r.store.last['enabled'], '0');
    });

    test('keeps the last snapshot when offline', () async {
      final r = await rig();
      await r.service.refresh();
      expect(r.store.writes, hasLength(1));

      r.server.offline = true;
      await r.service.refresh();

      expect(r.store.writes, hasLength(1), reason: 'nothing new is written');
      expect(r.store.clears, 0, reason: 'and nothing is wiped');
    });

    test('keeps the last snapshot when the server fails', () async {
      final r = await rig();
      await r.service.refresh();

      r.server.on('GET /glance', always(apiError(500, 'server_error', 'Something went wrong')));
      await r.service.refresh();
      r.server.on('GET /glance', always(json(200, {'data': 'garbage'})));
      await r.service.refresh();

      expect(r.store.writes, hasLength(1));
      expect(r.store.clears, 0);
    });

    test('clears everything on sign-out', () async {
      final r = await rig();
      await r.service.refresh();

      await r.container.read(authProvider.notifier).signOut();

      expect(r.store.clears, 1);
      expect(r.store.redraws, 2, reason: 'the widget is told once when it was filled and once when it was emptied');
    });

    test('does not draw an answer that arrives after the person signed out', () async {
      final answer = Completer<void>();
      final r = await rig(routes: {
        'GET /glance': (options) async {
          await answer.future;
          return json(200, {'data': glanceJson()});
        },
      });

      final reading = r.service.refresh();
      await pumpEventQueue();
      expect(r.server.calls('GET /glance'), 1);
      await r.container.read(authProvider.notifier).signOut();
      answer.complete();
      await reading;

      expect(r.store.writes, isEmpty, reason: 'a late answer must not refill a widget that sign-out just emptied');
      expect(r.store.clears, 1);
    });

    test('a refresh asked for while one is running runs once more afterwards', () async {
      final first = Completer<void>();
      var served = 0;
      final r = await rig(routes: {
        'GET /glance': (options) async {
          final mine = ++served;
          if (mine == 1) await first.future;
          return json(200, {'data': glanceJson(due: mine)});
        },
      });

      final one = r.service.refresh();
      await pumpEventQueue();
      final two = r.service.refresh();
      final three = r.service.refresh();
      expect(r.server.calls('GET /glance'), 1, reason: 'one read at a time');

      first.complete();
      await Future.wait([one, two, three]);

      expect(r.server.calls('GET /glance'), 2, reason: 'what was asked for in the meantime is read once, not twice');
      expect(r.store.last['due_line'], 'Due today: 2');
    });

    test('tightens the widget at once when the app lock turns on, even with no connection', () async {
      final r = await rig(
        prefs: {GlancePrivacyController.namesKey: true},
        deviceAuth: FakeDeviceAuth(),
        routes: {'GET /glance': (options) => json(200, {'data': glanceJson(names: options.queryParameters['names'].toString() == '1')})},
      );
      await r.service.refresh();
      expect(r.store.last['next_1'], 'Ahmad Salem · SAR 275.00 · Oct 7');

      r.server.offline = true;
      expect(await r.container.read(appLockProvider.notifier).turnOn('Confirm'), isTrue);
      await r.service.restyle();

      expect(r.store.last['due_line'], 'Due today: 3', reason: 'the counts stay');
      expect(r.store.last['amount_line'], '');
      expect(r.store.last['next_1'], '');
      expect(r.store.last.values.join(' '), allOf(isNot(contains('SAR')), isNot(contains('Ahmad'))));
    });

    test('draws again in the new language when the app’s language changes', () async {
      final r = await rig();
      await r.service.refresh();
      expect(r.store.last['due_line'], 'Due today: 3');

      r.server.offline = true;
      await r.container.read(localeProvider.notifier).choose('fr');
      await r.service.restyle();

      expect(r.store.last['due_line'], wordsFor('fr').t('Due today: :count', {'count': 3}));
      expect(r.store.last['due_line'], isNot('Due today: 3'));
    });
  });

  group('when the widget is read again', () {
    testWidgets('refreshes after the dashboard loads, after a payment is recorded and on resume', (tester) async {
      final store = FakeGlanceStore();
      final server = widgetServer(routes: {'POST /contracts/k1/payments': always(json(201, {'data': lineJson()}))});
      await pumpApp(tester, server, overrides: [...widgetOverrides(store), staleAfterProvider.overrideWithValue(Duration.zero)]);

      // The dashboard has loaded: the widget was read and filled.
      expect(server.calls('GET /dashboard'), 1);
      expect(server.calls('GET /glance'), 1);
      expect(store.writes, hasLength(1));

      // From here the dashboard fails, so only the other two triggers can read the widget again.
      server.on('GET /dashboard', always(apiError(500, 'server_error', 'Something went wrong')));

      var before = server.calls('GET /glance');
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 20)));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(tester);
      expect(server.calls('GET /glance'), greaterThan(before), reason: 'coming back after a while');

      before = server.calls('GET /glance');
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapText(tester, 'Record a payment', last: true);
      await tapButton(tester, 'Record payment');
      expect(find.text('Payment recorded'), findsOneWidget);
      expect(server.calls('GET /glance'), greaterThan(before), reason: 'a payment was recorded');
    });

    testWidgets('a quick look away and back asks for nothing', (tester) async {
      final store = FakeGlanceStore();
      final server = widgetServer();
      await pumpApp(tester, server, overrides: widgetOverrides(store));
      final before = server.calls('GET /glance');

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(tester);

      expect(server.calls('GET /glance'), before);
    });

    testWidgets('turning the app lock on blanks the amounts and names on the home screen at once', (tester) async {
      final store = FakeGlanceStore();
      final server = widgetServer(routes: {'GET /glance': (options) => json(200, {'data': glanceJson(names: options.queryParameters['names'].toString() == '1')})});
      await pumpApp(tester, server, deviceAuth: FakeDeviceAuth(), overrides: widgetOverrides(store), preferences: {GlancePrivacyController.namesKey: true});
      expect(store.last['next_1'], 'Ahmad Salem · SAR 275.00 · Oct 7');

      server.offline = true;
      final container = ProviderScope.containerOf(tester.element(find.byType(QistasApp)));
      await tester.runAsync(() => container.read(appLockProvider.notifier).turnOn('Confirm'));
      await settle(tester);
      // The widget is drawn again, then read again (there is no connection), in the real event loop: let both finish.
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));

      expect(store.last['amount_line'], '');
      expect(store.last['next_1'], '');
      expect(store.last.values.join(' '), isNot(contains('Ahmad')));
    });

    testWidgets('does nothing on a phone that cannot have the widget', (tester) async {
      final store = FakeGlanceStore();
      final server = widgetServer();
      await pumpApp(tester, server, overrides: widgetOverrides(store, supported: false));

      expect(server.calls('GET /dashboard'), 1);
      expect(server.calls('GET /glance'), 0);
      expect(store.writes, isEmpty);
    });
  });

  group('settings', () {
    testWidgets('the widget row appears only when the feature is on', (tester) async {
      final store = FakeGlanceStore();
      final server = workspaceServer();
      await pumpApp(tester, server, overrides: widgetOverrides(store));
      await openSettings(tester);
      expect(find.byKey(const ValueKey('settings-widget')), findsNothing);
      expect(find.text('Home-screen widget'), findsNothing);

      // The platform switches it on; the next refresh of the account shows it.
      server.on('GET /me', always(json(200, {'data': accountWithWidget()})));
      final container = ProviderScope.containerOf(tester.element(find.byType(QistasApp)));
      await tester.runAsync(() => container.read(authProvider.notifier).refresh());
      await settle(tester);

      expect(find.byKey(const ValueKey('settings-widget')), findsOneWidget);
      expect(find.text('Home-screen widget'), findsOneWidget);
    });

    testWidgets('the widget row stays away on a phone that cannot have the widget', (tester) async {
      await pumpApp(tester, widgetServer(), overrides: widgetOverrides(FakeGlanceStore(), supported: false));
      await openSettings(tester);

      expect(find.byKey(const ValueKey('settings-widget')), findsNothing);
    });

    testWidgets('the two switches save to this phone', (tester) async {
      final store = FakeGlanceStore();
      final server = widgetServer(routes: {'GET /glance': (options) => json(200, {'data': glanceJson(names: options.queryParameters['names'].toString() == '1')})});
      await pumpApp(tester, server, overrides: widgetOverrides(store));
      await openSettings(tester);
      await tapText(tester, 'Home-screen widget');

      Switch switchOf(String key) => tester.widget<Switch>(find.descendant(of: find.byKey(ValueKey(key)), matching: find.byType(Switch)));
      expect(switchOf('widget-amounts').value, isTrue, reason: 'amounts are on until the owner turns them off');
      expect(switchOf('widget-names').value, isFalse, reason: 'names are off until the owner allows them');

      await tapText(tester, 'Show customer names');
      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getBool('glance.show_names'), isTrue);
      expect(switchOf('widget-names').value, isTrue);
      expect(server.requestsTo('GET /glance').last.queryParameters['names'].toString(), '1', reason: 'the widget is read again with names');
      expect(store.last['next_1'], 'Ahmad Salem · SAR 275.00 · Oct 7');

      await tapText(tester, 'Show amounts');
      expect(prefs.getBool('glance.show_amounts'), isFalse);
      expect(switchOf('widget-amounts').value, isFalse);
      expect(store.last['next_1'], '');
      expect(store.last['amount_line'], '');
      expect(store.last['due_line'], 'Due today: 3', reason: 'the counts always stay');
    });

    testWidgets('the names switch is disabled with a reason while the app lock is on', (tester) async {
      final store = FakeGlanceStore();
      await pumpApp(tester, widgetServer(), overrides: widgetOverrides(store), deviceAuth: FakeDeviceAuth(), preferences: {AppLockController.enabledKey: true, GlancePrivacyController.namesKey: true});
      await openSettings(tester);
      await tapText(tester, 'Home-screen widget');

      final names = tester.widget<SwitchListTile>(find.byKey(const ValueKey('widget-names')));
      expect(names.onChanged, isNull);
      expect(names.value, isFalse, reason: 'it shows what the widget will do, not what was chosen before the lock');
      expect(find.text('Names stay hidden while the app lock is on'), findsOneWidget);
      expect(tester.widget<SwitchListTile>(find.byKey(const ValueKey('widget-amounts'))).onChanged, isNull);
      expect(find.text('Amounts stay hidden while the app lock is on'), findsOneWidget);

      await tapText(tester, 'Show customer names');
      expect((await SharedPreferences.getInstance()).getBool('glance.show_names'), isTrue, reason: 'a tap on a disabled switch changes nothing: the stored choice is as it was');
    });

    testWidgets('the names switch says why it is off while amounts are hidden', (tester) async {
      await pumpApp(tester, widgetServer(), overrides: widgetOverrides(FakeGlanceStore()), preferences: {GlancePrivacyController.amountsKey: false});
      await openSettings(tester);
      await tapText(tester, 'Home-screen widget');

      expect(tester.widget<SwitchListTile>(find.byKey(const ValueKey('widget-names'))).onChanged, isNull);
      expect(find.text('Names stay hidden while amounts are hidden'), findsOneWidget);
      expect(tester.widget<SwitchListTile>(find.byKey(const ValueKey('widget-amounts'))).onChanged, isNotNull);
    });
  });
}
