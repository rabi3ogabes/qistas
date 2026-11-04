import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/router.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/core/l10n/formats.dart';
import 'package:qistas/data/sign_in_event.dart';
import 'package:qistas/features/settings/devices_screen.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Go-live basics, the phone's half of "Recent sign-ins": on the Devices screen, the person's own latest sign-ins in plain
/// words, newest first, with the failed ones marked in text. A server that does not have the list shows nothing at all.

final FakeClock clock = FakeClock(DateTime(2026, 10, 11, 9));

/// A moment [ago] before the clock, as the server writes it (UTC).
String at(Duration ago) => clock.now.subtract(ago).toUtc().toIso8601String();

Map<String, dynamic> deviceJson() => {
      'id': 'd1', 'name': 'Pixel 8', 'current': true, 'last_used_at': '2026-10-11T08:55:00Z', 'last_used_ip': '198.51.100.9', 'signed_in_at': '2026-10-01T08:00:00Z',
    };

Map<String, dynamic> words(String language) => jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;

FakeServer signInsServer(List<Map<String, dynamic>>? rows, {Map<String, Route> routes = const {}}) => workspaceServer(routes: {
      'GET /devices': always(json(200, {'data': [deviceJson()]})),
      if (rows != null) 'GET /account/sign-ins': always(json(200, {'data': rows})),
      ...routes,
    });

/// Opens the Devices screen, as Settings does.
Future<void> openDevices(WidgetTester tester, FakeServer server, {String language = 'en', Size size = const Size(412, 1800), double textScale = 1}) async {
  await pumpApp(tester, server, clock: clock, language: language, size: size, textScale: textScale);
  ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).read(routerProvider).go('/devices');
  await settle(tester);
}

Finder row(String id) => find.byKey(ValueKey('sign-in-$id'));

void main() {
  group('the list', () {
    testWidgets('comes after the devices, newest first, one calm row each', (tester) async {
      final server = signInsServer([
        signInJson(id: 's1', createdAt: at(const Duration(minutes: 5))),
        signInJson(id: 's2', deviceType: 'mobile', os: 'Android', browser: 'Chrome', createdAt: at(const Duration(hours: 3))),
        signInJson(id: 's3', deviceType: 'tablet', os: 'iPadOS', browser: 'Safari', createdAt: at(const Duration(days: 2))),
      ]);
      await openDevices(tester, server);

      expect(find.text('Recent sign-ins'), findsOneWidget);
      expect(find.text('If one of these is not you, change your password.'), findsOneWidget);
      final tops = [for (final id in ['s1', 's2', 's3']) tester.getTopLeft(row(id)).dy];
      expect(tops, [...tops]..sort(), reason: 'newest first, as the server wrote them');
      expect(tester.getTopLeft(find.text('Recent sign-ins')).dy, greaterThan(tester.getTopLeft(find.text('Pixel 8')).dy), reason: 'under the devices');
      expect(find.descendant(of: row('s1'), matching: find.text('Desktop · Chrome on Windows · Qatar')), findsOneWidget);
      expect(find.descendant(of: row('s1'), matching: find.text('Proxy not detected · 5 minutes ago')), findsOneWidget);
      expect(find.descendant(of: row('s2'), matching: find.text('Mobile · Chrome on Android · Qatar')), findsOneWidget);
      expect(find.descendant(of: row('s2'), matching: find.text('Proxy not detected · 3 hours ago')), findsOneWidget);
      expect(find.descendant(of: row('s3'), matching: find.text('Tablet · Safari on iPadOS · Qatar')), findsOneWidget);
      expect(find.descendant(of: row('s3'), matching: find.text('Proxy not detected · 2 days ago')), findsOneWidget);
    });

    testWidgets('names the phone app by its own name and version, and the web by its type, system and browser', (tester) async {
      final server = signInsServer([
        signInJson(id: 'a1', channel: 'app', deviceType: 'mobile', os: 'Android', browser: null, appVersion: '1.29.0', deviceName: 'Pixel 8', createdAt: at(const Duration(minutes: 1))),
        signInJson(id: 'a2', channel: 'app', deviceType: 'mobile', os: 'iOS', browser: null, appVersion: null, deviceName: null, ipCountry: null, createdAt: at(const Duration(minutes: 2))),
        signInJson(id: 'w1', deviceType: 'unknown', os: null, browser: null, ipCountry: null, createdAt: at(const Duration(minutes: 3))),
        signInJson(id: 'w2', deviceType: 'desktop', os: null, browser: 'Firefox', ipCountry: 'SA', createdAt: at(const Duration(minutes: 4))),
        signInJson(id: 'w3', deviceType: 'desktop', os: 'Linux', browser: null, ipCountry: 'ZZ', createdAt: at(const Duration(minutes: 5))),
      ]);
      await openDevices(tester, server);

      expect(find.descendant(of: row('a1'), matching: find.text('Pixel 8 · Android · App 1.29.0 · Qatar')), findsOneWidget);
      expect(find.descendant(of: row('a2'), matching: find.text('Mobile · iOS · App')), findsOneWidget);
      expect(find.descendant(of: row('w1'), matching: find.text('Unknown device')), findsOneWidget);
      expect(find.descendant(of: row('w2'), matching: find.text('Desktop · Firefox · Saudi Arabia')), findsOneWidget);
      expect(find.descendant(of: row('w3'), matching: find.text('Desktop · Linux · ZZ')), findsOneWidget, reason: 'a country the app has no name for is shown by its code');
    });

    testWidgets('says the proxy verdict in words and never says certain', (tester) async {
      final server = signInsServer([
        signInJson(id: 'p1', proxy: 'detected', signals: ['tor'], createdAt: at(const Duration(minutes: 1))),
        signInJson(id: 'p2', proxy: 'possible', signals: ['timezone_country_mismatch'], createdAt: at(const Duration(minutes: 2))),
        signInJson(id: 'p3', proxy: 'not_detected', createdAt: at(const Duration(minutes: 3))),
        signInJson(id: 'p4', proxy: 'something_new', createdAt: at(const Duration(minutes: 4))),
      ]);
      await openDevices(tester, server);

      expect(find.descendant(of: row('p1'), matching: find.textContaining('Proxy detected')), findsOneWidget);
      expect(find.descendant(of: row('p2'), matching: find.textContaining('Proxy possible')), findsOneWidget);
      expect(find.descendant(of: row('p3'), matching: find.textContaining('Proxy not detected')), findsOneWidget);
      expect(find.descendant(of: row('p4'), matching: find.textContaining('Proxy not detected')), findsOneWidget, reason: 'a verdict the app does not know is not made worse than the web makes it');
      expect(find.textContaining('certain', findRichText: true), findsNothing);
    });

    testWidgets('marks a failed sign-in in text, and only that one', (tester) async {
      final server = signInsServer([
        signInJson(id: 's1', outcome: 'failed', ipCountry: 'RU', proxy: 'possible', createdAt: at(const Duration(minutes: 5))),
        signInJson(id: 's2', createdAt: at(const Duration(minutes: 9))),
      ]);
      await openDevices(tester, server);

      expect(find.descendant(of: row('s1'), matching: find.text('Wrong password')), findsOneWidget);
      expect(find.descendant(of: row('s2'), matching: find.text('Wrong password')), findsNothing);
      expect(find.text('Wrong password'), findsOneWidget);
    });

    testWidgets('never shows the address of the connection', (tester) async {
      final server = signInsServer([signInJson(id: 's1', createdAt: at(const Duration(minutes: 5)))]);
      await openDevices(tester, server);

      expect(find.textContaining('203.0.113.7', findRichText: true), findsNothing);
      expect(find.textContaining('198.51.100.9'), findsOneWidget, reason: 'the devices list is as it was');
    });

    testWidgets('says how long ago in the units that fit, and gives a date once it is a week', (tester) async {
      final server = signInsServer([
        signInJson(id: 't0', createdAt: at(const Duration(seconds: 20))),
        signInJson(id: 't1', createdAt: at(const Duration(minutes: 1, seconds: 10))),
        signInJson(id: 't2', createdAt: at(const Duration(minutes: 59))),
        signInJson(id: 't3', createdAt: at(const Duration(hours: 1, minutes: 20))),
        signInJson(id: 't4', createdAt: at(const Duration(hours: 23))),
        signInJson(id: 't5', createdAt: at(const Duration(days: 1, hours: 2))),
        signInJson(id: 't6', createdAt: at(const Duration(days: 6))),
        signInJson(id: 't7', createdAt: at(const Duration(days: 12))),
        signInJson(id: 't8', createdAt: at(const Duration(minutes: -3))),
        signInJson(id: 't9', createdAt: 'not a date'),
      ]);
      await openDevices(tester, server, size: const Size(412, 3200));

      Finder when(String id, String text) => find.descendant(of: row(id), matching: find.text('Proxy not detected · $text'));
      expect(when('t0', 'Just now'), findsOneWidget);
      expect(when('t1', '1 minute ago'), findsOneWidget);
      expect(when('t2', '59 minutes ago'), findsOneWidget);
      expect(when('t3', '1 hour ago'), findsOneWidget);
      expect(when('t4', '23 hours ago'), findsOneWidget);
      expect(when('t5', '1 day ago'), findsOneWidget);
      expect(when('t6', '6 days ago'), findsOneWidget);
      expect(when('t7', formatMoment(clock.now.subtract(const Duration(days: 12)), 'en')), findsOneWidget);
      expect(when('t8', 'Just now'), findsOneWidget, reason: 'a clock that runs a little behind the server’s');
      expect(find.descendant(of: row('t9'), matching: find.text('Proxy not detected')), findsOneWidget, reason: 'no time to say, so only the verdict');
    });

    testWidgets('says so in a sentence when there is nothing to list', (tester) async {
      await openDevices(tester, signInsServer(const []));

      expect(find.text('Recent sign-ins'), findsOneWidget);
      expect(find.text('No sign-ins are recorded yet.'), findsOneWidget);
      expect(find.text('If one of these is not you, change your password.'), findsNothing);
    });
  });

  group('a server without the list', () {
    testWidgets('shows nothing at all and no error (an older server answers 404)', (tester) async {
      final server = signInsServer(null);
      await openDevices(tester, server);

      expect(server.calls('GET /account/sign-ins'), 1);
      expect(find.text('Recent sign-ins'), findsNothing);
      expect(find.text('No sign-ins are recorded yet.'), findsNothing);
      expect(find.text('Try again'), findsNothing);
      expect(find.byType(SnackBar), findsNothing);
      expect(find.text('Pixel 8'), findsOneWidget, reason: 'the devices are there as they were');
      expect(find.byType(QSkeleton), findsNothing);
    });
  });

  group('while it loads and when it fails', () {
    testWidgets('shows placeholders under the heading while the list is on its way', (tester) async {
      final gate = Completer<FakeResponse>();
      final server = signInsServer(null, routes: {'GET /account/sign-ins': (_) => gate.future});
      await openDevices(tester, server);

      expect(find.text('Recent sign-ins'), findsOneWidget);
      expect(find.byType(QSkeleton), findsWidgets);

      gate.complete(json(200, {'data': [signInJson(id: 's1', createdAt: at(const Duration(minutes: 5)))]}));
      await settle(tester);

      expect(find.byType(QSkeleton), findsNothing);
      expect(row('s1'), findsOneWidget);
    });

    testWidgets('says what went wrong under the heading and tries again on request', (tester) async {
      var calls = 0;
      final server = signInsServer(null, routes: {
        'GET /account/sign-ins': (_) => ++calls == 1
            ? apiError(500, 'server_error', 'Server Error')
            : json(200, {'data': [signInJson(id: 's1', createdAt: at(const Duration(minutes: 5)))]}),
      });
      await openDevices(tester, server);

      expect(find.text('Recent sign-ins'), findsOneWidget);
      expect(find.text('Something went wrong on our side. Please try again.'), findsOneWidget);
      expect(find.text('Pixel 8'), findsOneWidget, reason: 'the devices stay');

      await tapText(tester, 'Try again');

      expect(find.text('Something went wrong on our side. Please try again.'), findsNothing);
      expect(row('s1'), findsOneWidget);
    });

    testWidgets('says there is no connection in our own words', (tester) async {
      final server = signInsServer([signInJson(id: 's1', createdAt: at(const Duration(minutes: 5)))]);
      await openDevices(tester, server);
      // The list came; now the line drops before it is asked for again.
      server.offline = true;
      ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).invalidate(signInsProvider);
      await settle(tester);

      expect(find.text('No connection. Check your internet and try again.'), findsOneWidget);
    });

    testWidgets('is not asked for until the Devices screen is opened', (tester) async {
      final server = signInsServer([signInJson(createdAt: at(const Duration(minutes: 5)))]);
      await pumpApp(tester, server, clock: clock);

      expect(server.calls('GET /account/sign-ins'), 0);
    });
  });

  group('the words', () {
    testWidgets('are the website’s in Arabic, right to left, with nothing running off a small screen at 200% text', (tester) async {
      final ar = words('ar');
      final server = signInsServer([
        signInJson(id: 's1', outcome: 'failed', proxy: 'possible', createdAt: at(const Duration(minutes: 5))),
        signInJson(id: 's2', channel: 'app', deviceType: 'mobile', os: 'Android', browser: null, appVersion: '1.29.0', deviceName: 'Samsung Galaxy S24 Ultra Premium Edition', createdAt: at(const Duration(hours: 3))),
        signInJson(id: 's3', proxy: 'detected', createdAt: at(const Duration(days: 3))),
      ]);
      await openDevices(tester, server, language: 'ar', size: const Size(320, 3000), textScale: 2);

      expect(find.text(ar['Recent sign-ins'] as String), findsOneWidget);
      expect(find.descendant(of: row('s1'), matching: find.text(ar['Wrong password'] as String)), findsOneWidget);
      expect(find.descendant(of: row('s1'), matching: find.textContaining(ar['Proxy possible'] as String)), findsOneWidget);
      expect(find.descendant(of: row('s3'), matching: find.textContaining(ar['Proxy detected'] as String)), findsOneWidget);
      expect(Directionality.of(tester.element(row('s1'))), TextDirection.rtl);
    });

    testWidgets('read in English at 200% text on a small screen without overflow', (tester) async {
      final server = signInsServer([
        signInJson(id: 's1', outcome: 'failed', proxy: 'possible', createdAt: at(const Duration(minutes: 5))),
        signInJson(id: 's2', channel: 'app', deviceType: 'mobile', os: 'Android', browser: null, appVersion: '1.29.0', deviceName: 'Samsung Galaxy S24 Ultra Premium Edition', ipCountry: 'GB', createdAt: at(const Duration(hours: 3))),
      ]);
      await openDevices(tester, server, size: const Size(320, 2400), textScale: 2);

      expect(row('s1'), findsOneWidget);
      expect(row('s2'), findsOneWidget);
    });

    for (final language in ['fr', 'es', 'ur']) {
      testWidgets('are the website’s in $language', (tester) async {
        final table = words(language);
        final server = signInsServer([signInJson(id: 's1', outcome: 'failed', createdAt: at(const Duration(minutes: 5)))]);
        await openDevices(tester, server, language: language);

        expect(find.text(table['Recent sign-ins'] as String), findsOneWidget);
        expect(find.descendant(of: row('s1'), matching: find.text(table['Wrong password'] as String)), findsOneWidget);
      });
    }

    testWidgets('say how long ago for any count in Arabic', (tester) async {
      final ar = words('ar');
      final server = signInsServer([
        for (final (id, ago) in [('a', const Duration(minutes: 3)), ('b', const Duration(minutes: 11)), ('c', const Duration(hours: 4)), ('d', const Duration(days: 5))])
          signInJson(id: id, createdAt: at(ago)),
      ]);
      await openDevices(tester, server, language: 'ar', size: const Size(412, 2400));

      for (final (id, key, count) in [('a', ':count minutes ago', '3'), ('b', ':count minutes ago', '11'), ('c', ':count hours ago', '4'), ('d', ':count days ago', '5')]) {
        final said = (ar[key] as String).replaceAll(':count', count);
        expect(find.descendant(of: row(id), matching: find.textContaining(said)), findsOneWidget, reason: '$count in $key');
      }
    });
  });

  group('the Arabic relative times', () {
    test('are worded as the app words counts elsewhere (the number, then the unit as a label), with no plural form to get wrong', () {
      final ar = words('ar');

      expect(ar[':count minutes ago'], 'منذ :count دقيقة');
      expect(ar[':count hours ago'], 'منذ :count ساعة');
      expect(ar[':count days ago'], 'منذ :count يوم');
      for (final key in [':count minutes ago', ':count hours ago', ':count days ago']) {
        expect(ar[key], isNot(contains('من ال')), reason: '$key must not read "since 5 of the days"');
      }
    });
  });

  group('SignInEvent', () {
    test('reads a line of the list', () {
      final event = SignInEvent.fromJson(signInJson(id: 's9', channel: 'app', outcome: 'failed', deviceType: 'mobile', os: 'Android', browser: null, appVersion: '1.29.0', deviceName: 'Pixel 8', proxy: 'possible', ipCountry: 'qa', createdAt: '2026-10-11T08:55:00Z'));

      expect(event.id, 's9');
      expect(event.isApp, isTrue);
      expect(event.failed, isTrue);
      expect(event.deviceType, 'mobile');
      expect(event.os, 'Android');
      expect(event.browser, isNull);
      expect(event.appVersion, '1.29.0');
      expect(event.deviceName, 'Pixel 8');
      expect(event.proxy, 'possible');
      expect(event.ipCountry, 'QA');
      expect(event.createdAt!.toUtc(), DateTime.utc(2026, 10, 11, 8, 55));
    });

    test('reads a line with nothing in it without failing', () {
      final event = SignInEvent.fromJson(const {});

      expect(event.id, '');
      expect(event.isApp, isFalse);
      expect(event.failed, isFalse);
      expect(event.deviceType, 'unknown');
      expect(event.proxy, 'not_detected');
      expect(event.ipCountry, isNull);
      expect(event.createdAt, isNull);
    });

    test('treats empty words as no words', () {
      final event = SignInEvent.fromJson(signInJson(os: '  ', browser: '', ipCountry: '', createdAt: '2026-10-11T08:55:00Z'));

      expect(event.os, isNull);
      expect(event.browser, isNull);
      expect(event.ipCountry, isNull);
    });
  });
}
