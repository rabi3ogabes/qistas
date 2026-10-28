import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/core/push/background.dart';
import 'package:qistas/core/storage/token_store.dart';
import 'package:qistas/data/models.dart';
import 'package:qistas/features/glance/glance_privacy.dart';
import 'package:qistas/features/glance/glance_refresher.dart';
import 'package:qistas/features/security/app_lock.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/fake_api.dart';
import '../support/fake_glance_store.dart';
import '../support/glance_samples.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The widget's morning refresh (daily-habit wave A, H3): what runs when the server's silent push arrives and the app is
/// closed. No Riverpod, no screens: the token, the preferences and the cached account are all it has.

/// A phone with the app closed: a token in the secure store, the preferences and the account the app last saw on disk,
/// and a refresher wired to a fake server and a fake widget.
class Phone {
  Phone(this.refresher, this.store, this.server);

  final GlanceRefresher refresher;
  final FakeGlanceStore store;
  final FakeServer server;

  List<RequestOptions> get glanceCalls => server.requestsTo('GET /glance');
}

const String token = 'qst_test-token';

Map<String, dynamic> cachedAccount({bool requireAppLock = false}) {
  final account = accountWithWidget();
  (account['tenant'] as Map<String, dynamic>)['require_app_lock'] = requireAppLock;

  return account;
}

Future<Phone> phone({
  MemoryTokenStore? tokens,
  String? signedInWith = token,
  bool hasCachedAccount = true,
  bool businessRequiresLock = false,
  Map<String, Object> prefs = const {},
  Map<String, Route> routes = const {},
  bool supported = true,
}) async {
  SharedPreferences.setMockInitialValues({
    'language': 'en',
    if (hasCachedAccount) AuthController.accountKey: jsonEncode(Account.fromJson(cachedAccount(requireAppLock: businessRequiresLock)).toJson()),
    ...prefs,
  });
  final server = FakeServer({
    'GET /glance': (options) => json(200, {'data': glanceJson(names: options.queryParameters['names'].toString() == '1')}),
    ...routes,
  });
  final store = FakeGlanceStore();
  final refresher = GlanceRefresher(
    tokens: tokens ?? MemoryTokenStore(signedInWith),
    store: store,
    adapter: server.adapter,
    clock: () => moment,
    supported: supported,
    loadWords: (language) async => wordsFor(language),
  );

  return Phone(refresher, store, server);
}

/// Counts how often the widget was asked to refresh.
class CountingRefresher implements GlanceRefresher {
  CountingRefresher({this.fails = false});

  final bool fails;
  int runs = 0;

  @override
  Future<bool> run() async {
    runs++;
    if (fails) throw StateError('boom');

    return true;
  }
}

void main() {
  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    for (final code in AppConfig.locales) {
      await initializeDateFormatting(code);
    }
  });

  group('the refresher', () {
    test('it fetches, encodes with the saved privacy and writes', () async {
      final p = await phone(prefs: {GlancePrivacyController.namesKey: true});

      expect(await p.refresher.run(), isTrue);

      expect(p.glanceCalls, hasLength(1));
      expect(p.glanceCalls.single.queryParameters['names'].toString(), '1', reason: 'the owner allowed names and nothing locks the screen');
      expect(p.glanceCalls.single.headers['Authorization'], 'Bearer $token');
      expect(p.store.writes, hasLength(1));
      expect(p.store.last, encode(glanceJson(names: true), privacy: const GlancePrivacy(showNames: true)));
      expect(p.store.last['next_1'], 'Ahmad Salem · SAR 275.00 · Oct 7');
      expect(p.store.last['due_line'], 'Due today: 3');
      expect(p.store.last['updated'], '09:05');
      expect(p.store.last['updated_epoch_ms'], '${moment.millisecondsSinceEpoch}');
      expect(p.store.redraws, 1, reason: 'the widget is told to draw what it was handed');
      expect(p.store.clears, 0);
    });

    test('it keeps amounts and names off the widget when the owner chose to hide them', () async {
      final p = await phone(prefs: {GlancePrivacyController.amountsKey: false, GlancePrivacyController.namesKey: true});

      expect(await p.refresher.run(), isTrue);

      expect(p.glanceCalls.single.queryParameters['names'].toString(), '0', reason: 'a name never travels to the phone when it may not be shown');
      expect(p.store.last, encode(glanceJson(), privacy: const GlancePrivacy(showAmounts: false, showNames: true)));
      expect(p.store.last['amount_line'], '');
      expect(p.store.last['next_1'], '');
      expect(p.store.last['due_line'], 'Due today: 3');
    });

    test('it asks for no names by default', () async {
      final p = await phone();

      await p.refresher.run();

      expect(p.glanceCalls.single.queryParameters['names'].toString(), '0');
      expect(p.store.last['next_1'], 'C-0007 · SAR 275.00 · Oct 7');
    });

    for (final (name, businessRequiresLock, hasCachedAccount, prefs) in <(String, bool, bool, Map<String, Object>)>[
      ('the lock is on for this phone', false, true, {AppLockController.enabledKey: true}),
      ('the business requires the lock', true, true, <String, Object>{}),
      ('the phone cannot tell whether the lock is on', false, false, <String, Object>{}),
    ]) {
      test('it writes only the counts when $name', () async {
        final p = await phone(businessRequiresLock: businessRequiresLock, hasCachedAccount: hasCachedAccount, prefs: {GlancePrivacyController.namesKey: true, ...prefs});

        expect(await p.refresher.run(), isTrue);

        expect(p.glanceCalls.single.queryParameters['names'].toString(), '0');
        expect(p.store.last, encode(glanceJson(), lock: true, privacy: const GlancePrivacy(showNames: true)));
        expect(p.store.last['due_line'], 'Due today: 3');
        expect(p.store.last['late_line'], 'Late: 2');
        expect(p.store.last['amount_line'], '');
        expect([p.store.last['next_1'], p.store.last['next_2'], p.store.last['next_3']], everyElement(''));
        expect(p.store.last.values.join(' '), isNot(contains('Ahmad')));
      });
    }

    test('it writes in the app’s language and direction', () async {
      final p = await phone(prefs: {'language': 'ar'});

      await p.refresher.run();

      expect(p.store.last, encode(glanceJson(), language: 'ar'));
      expect(p.store.last['rtl'], '1');
      expect(p.store.last['due_line'], startsWith('الأقساط المستحقة اليوم'));
      expect(p.glanceCalls.single.headers['Accept-Language'], 'ar');
    });

    test('it does nothing without a token', () async {
      final p = await phone(signedInWith: null);

      expect(await p.refresher.run(), isFalse);

      expect(p.server.adapter.requests, isEmpty);
      expect(p.store.writes, isEmpty);
      expect(p.store.clears, 0);
      expect(p.store.redraws, 0);
    });

    test('it does nothing on a phone that has no widget', () async {
      final p = await phone(supported: false);

      expect(await p.refresher.run(), isFalse);

      expect(p.server.adapter.requests, isEmpty);
      expect(p.store.writes, isEmpty);
      expect(p.store.redraws, 0);
    });

    test('it writes the disabled state when the server says so', () async {
      final p = await phone(routes: {'GET /glance': always(json(200, {'data': {'enabled': false}}))});

      expect(await p.refresher.run(), isTrue);

      expect(p.store.last['enabled'], '0');
      expect(p.store.last['due_line'], 'Open Qistas to refresh');
      expect(p.store.last['late_line'], '');
      expect(p.store.last['amount_line'], '');
      expect([p.store.last['next_1'], p.store.last['next_2'], p.store.last['next_3']], everyElement(''));
      expect(p.store.redraws, 1);
    });

    for (final (name, route) in <(String, Route)>[
      ('a server error', always(apiError(500, 'server_error', 'Something went wrong'))),
      ('an answer nobody can read', always(json(200, {'data': 'garbage'}))),
      ('a refusal that is not about the sign-in', always(apiError(403, 'forbidden', 'No'))),
    ]) {
      test('it survives $name without clearing the last snapshot', () async {
        final p = await phone(routes: {'GET /glance': route});

        expect(await p.refresher.run(), isFalse);

        expect(p.glanceCalls, hasLength(1));
        expect(p.store.writes, isEmpty);
        expect(p.store.clears, 0);
        expect(p.store.redraws, 0);
      });
    }

    test('it survives a network error without clearing the last snapshot', () async {
      final p = await phone();
      p.server.offline = true;

      expect(await p.refresher.run(), isFalse);

      expect(p.store.writes, isEmpty);
      expect(p.store.clears, 0);
      expect(p.store.redraws, 0);
    });

    test('it empties the widget when the server no longer knows the token', () async {
      final p = await phone(routes: {'GET /glance': always(apiError(401, 'unauthenticated', 'Unauthenticated.'))});

      expect(await p.refresher.run(), isFalse);

      expect(p.store.clears, 1, reason: 'a session that is over leaves nothing of the business on the home screen');
      expect(p.store.redraws, 1);
      expect(p.store.writes, isEmpty);
    });

    test('it drops an answer that arrives after the person signed out', () async {
      final tokens = MemoryTokenStore(token);
      final p = await phone(tokens: tokens, routes: {
        'GET /glance': (options) async {
          await tokens.clear();

          return json(200, {'data': glanceJson()});
        },
      });

      expect(await p.refresher.run(), isFalse);

      expect(p.glanceCalls, hasLength(1));
      expect(p.store.writes, isEmpty, reason: 'the home screen was emptied by the sign-out and must stay empty');
      expect(p.store.redraws, 0);
    });

    test('it never logs the token or any name', () async {
      final lines = <String>[];
      final original = debugPrint;
      debugPrint = (message, {wrapWidth}) => lines.add(message ?? '');
      addTearDown(() => debugPrint = original);

      await runZoned(
        () async {
          // Every way a run can end, with names allowed so there is something to leak.
          final prefs = {GlancePrivacyController.namesKey: true};
          await (await phone(prefs: prefs)).refresher.run();
          await (await phone(prefs: prefs, signedInWith: null)).refresher.run();
          await (await phone(prefs: prefs, supported: false)).refresher.run();
          final offline = await phone(prefs: prefs);
          offline.server.offline = true;
          await offline.refresher.run();
          await (await phone(prefs: prefs, routes: {'GET /glance': always(apiError(401, 'unauthenticated', 'Unauthenticated.'))})).refresher.run();
          await (await phone(prefs: prefs, routes: {'GET /glance': always(apiError(500, 'server_error', 'Ahmad Salem'))})).refresher.run();
        },
        zoneSpecification: ZoneSpecification(print: (_, _, _, line) => lines.add(line)),
      );

      final everything = lines.join('\n');
      expect(lines, isNotEmpty, reason: 'the log is captured: a run says how it ended');
      for (final secret in [token, 'Bearer', 'Ahmad', 'Badr', 'Dalia', 'Layla', 'layla@example.com', 'Al-Fares']) {
        expect(everything, isNot(contains(secret)), reason: 'the log must not hold $secret');
      }
    });
  });

  group('the silent push', () {
    const channel = MethodChannel('home_widget');
    final calls = <MethodCall>[];

    setUp(() {
      calls.clear();
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(channel, (call) async {
        calls.add(call);

        return true;
      });
    });

    tearDown(() => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(channel, null));

    test('it ignores a message that is not glance.refresh', () async {
      final refresher = CountingRefresher();

      for (final message in [const RemoteMessage(), const RemoteMessage(data: {'type': 'alert.instalment'}), const RemoteMessage(data: {'type': 'glance.refreshed'}), const RemoteMessage(data: {'route': '/inbox'})]) {
        await qistasBackgroundMessage(message);
        await refreshGlanceFor(message, () => refresher);
      }

      expect(refresher.runs, 0);
      expect(calls, isEmpty, reason: 'nothing on the phone was touched');
    });

    test('it refreshes the widget for a glance.refresh message', () async {
      final refresher = CountingRefresher();

      await refreshGlanceFor(const RemoteMessage(data: {'type': 'glance.refresh'}), () => refresher);

      expect(refresher.runs, 1);
    });

    test('it runs on a phone without the widget as a harmless no-op', () async {
      // The real entry point, as Firebase calls it: this machine is not an Android phone, so nothing is read or drawn.
      await qistasBackgroundMessage(const RemoteMessage(data: {'type': 'glance.refresh'}));

      expect(calls, isEmpty);
    });

    test('it never lets a failed refresh reach the push service', () async {
      final refresher = CountingRefresher(fails: true);

      await refreshGlanceFor(const RemoteMessage(data: {'type': 'glance.refresh'}), () => refresher);

      expect(refresher.runs, 1);
    });

    test('a build without Firebase starts all the same', () async {
      await registerBackgroundMessages();
    });
  });
}
