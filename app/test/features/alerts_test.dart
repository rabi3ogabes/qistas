import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart' show RequestOptions;
import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/push/push_service.dart';
import 'package:qistas/data/appearance.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan PP9: the morning summary and instalment alerts land in an inbox (and on the phone once Firebase is set up),
/// each person chooses what to be told, and "Remind everyone" walks the day's customers one WhatsApp message at a time.

Map<String, dynamic> alertsAccount({String role = 'owner'}) {
  final account = accountJson(role: role);
  Map<String, dynamic> on() => {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false};
  account['entitlements'] = {...Map<String, dynamic>.from(account['entitlements'] as Map), 'instalment_alerts': on(), 'daily_digest': on()};

  return account;
}

Map<String, dynamic> reminderJson(String name, String whatsapp, {String reference = 'C-0007', int? late}) => {
      'installment_id': 'i-$name', 'contract_id': 'k1', 'contract_reference': reference, 'customer_id': 'c-$name', 'customer_name': name,
      'customer_phone': '+$whatsapp', 'amount_due': '275.00', 'due_date': '2026-10-07', 'whatsapp': whatsapp,
      'message': 'Hello $name, a friendly reminder. Al-Fares Electronics',
      'days_late': ?late,
    };

Map<String, dynamic> notificationJson(String title, {bool read = false, String type = 'daily_digest'}) => {
      'id': 'n-$title', 'type': type, 'title': title, 'body': 'Due today: 1 (SAR 275.00). Late: 0 (SAR 0.00).', 'data': {'route': '/'},
      'read': read, 'created_at': '2026-10-07T06:00:00Z',
    };

Map<String, dynamic> preferencesJson({String repeat = 'weekly'}) => {
      'data': {
        'daily_digest': {'enabled': true, 'time': '09:00'},
        'instalment_due': {'enabled': false},
        'instalment_late': {'enabled': true, 'repeat': repeat},
        'quiet_hours': {'enabled': false, 'from': '22:00', 'to': '08:00'},
      },
    };

Map<String, dynamic> sentBody(FakeServer server, String route) => jsonDecode(server.requestsTo(route).last.data as String) as Map<String, dynamic>;

class FakePushService implements PushService {
  FakePushService(this.token);

  final String? token;
  final tapped = StreamController<String>.broadcast();

  @override
  Future<String?> start() async => token;

  @override
  Stream<String> get tokenRefresh => const Stream.empty();

  @override
  Stream<String> get taps => tapped.stream;

  @override
  Stream<void> get arrivals => const Stream.empty();
}

FakeServer alertsServer({Map<String, dynamic>? account, Map<String, Route> routes = const {}}) => workspaceServer(account: account ?? alertsAccount(), routes: {
      'GET /notifications': always(json(200, {'data': [notificationJson('Today’s collections'), notificationJson('Late: Badr', type: 'instalment_late')], 'meta': {'unread': 2}})),
      'POST /notifications/read': always(json(200, {'data': {'unread': 0}})),
      'GET /notifications/preferences': always(json(200, preferencesJson())),
      'PUT /notifications/preferences': (RequestOptions options) => json(200, {'data': jsonDecode(options.data as String)}),
      'GET /reminders/due-today': (RequestOptions options) => json(200, {
            'data': options.queryParameters['scope'] == 'late'
                ? [reminderJson('Badr', '966502223334', late: 6)]
                : [reminderJson('Amal', '966501112223'), reminderJson('Dalia', '966504445556')],
          }),
      'POST /push-tokens': always(json(201, {'data': {'id': 'p1', 'platform': 'android'}})),
      'DELETE /push-tokens/fcm-token': always(json(204, <String, dynamic>{})),
      'POST /auth/logout': always(json(200, {'data': <String, dynamic>{}})),
      ...routes,
    });

void main() {
  group('remind everyone', () {
    testWidgets('walks the list, opening each customer’s WhatsApp with the message ready', (tester) async {
      final opened = <Uri>[];
      final server = alertsServer();
      await pumpApp(tester, server, overrides: [
        openExternalProvider.overrideWithValue((uri) async {
          opened.add(uri);
          return true;
        }),
      ]);

      await tapButton(tester, 'Remind everyone');
      expect(find.text('0 of 2 reminded'), findsOneWidget);

      await tapButton(tester, 'Next: Amal');
      expect(opened.single.toString(), 'https://wa.me/966501112223?text=${Uri.encodeComponent('Hello Amal, a friendly reminder. Al-Fares Electronics')}');
      expect(find.text('1 of 2 reminded'), findsOneWidget);

      await tapButton(tester, 'Next: Dalia');
      expect(opened.last.host, 'wa.me');
      expect(opened.last.path, '/966504445556');
      expect(find.text('Everyone here is reminded.'), findsOneWidget);
    });

    testWidgets('asks for the messages in the app’s language, and has the late ones on their own tab', (tester) async {
      final server = alertsServer();
      await pumpApp(tester, server);

      await tapButton(tester, 'Remind everyone');
      expect(server.requestsTo('GET /reminders/due-today').last.queryParameters, {'scope': 'due', 'language': 'en'});

      await tapText(tester, 'Late');
      expect(server.requestsTo('GET /reminders/due-today').last.queryParameters['scope'], 'late');
      expect(find.text('Badr'), findsOneWidget);
    });

    testWidgets('is not offered while the platform has it switched off', (tester) async {
      await pumpApp(tester, alertsServer(account: accountJson()));

      expect(find.text('Remind everyone'), findsNothing);
      expect(find.byTooltip('Alerts'), findsNothing);
    });
  });

  testWidgets('the reminder for one customer uses the business’s own words', (tester) async {
    await pumpApp(tester, alertsServer(routes: {
      'GET /message-templates': always(json(200, {
            'data': [
              {'key': 'reminder_late', 'language': 'en', 'body': 'Dear :name, :amount has waited since :date (:reference). :business', 'default_body': 'x', 'custom': true},
            ],
          })),
    }));

    await tapText(tester, 'Remind');

    expect(find.textContaining('Dear Youssef Mansour, SAR'), findsOneWidget);
    expect(find.textContaining('(C-0003). Al-Fares Electronics'), findsOneWidget);
  });

  group('the inbox', () {
    testWidgets('shows how many are unread, lists them, and marks them read once seen', (tester) async {
      final server = alertsServer();
      await pumpApp(tester, server);

      expect(find.text('2'), findsWidgets);
      await tapTooltip(tester, 'Alerts');

      expect(find.text('Today’s collections'), findsOneWidget);
      expect(find.text('Late: Badr'), findsOneWidget);
      expect(server.calls('POST /notifications/read'), 1);
    });
  });

  group('alert settings', () {
    testWidgets('saves each choice as it is made', (tester) async {
      final server = alertsServer();
      await pumpApp(tester, server);
      await openSettings(tester);
      await tapText(tester, 'Alert settings');

      expect(find.text('Morning summary'), findsOneWidget);
      await tapText(tester, 'Every day');

      expect(sentBody(server, 'PUT /notifications/preferences')['instalment_late'], {'enabled': true, 'repeat': 'daily'});
    });

    testWidgets('owners change the reminder wording; a collector does not see it', (tester) async {
      final server = alertsServer(routes: {
        'GET /message-templates': always(json(200, {
              'data': [
                for (final key in ['reminder_due', 'reminder_late'])
                  for (final language in ['en', 'ar', 'fr', 'es', 'ur'])
                    {'key': key, 'language': language, 'body': 'Hello :name ($key $language)', 'default_body': 'Hello :name ($key $language)', 'custom': false},
              ],
            })),
        'PUT /message-templates/reminder_due': (RequestOptions options) => json(200, {'data': {...jsonDecode(options.data as String) as Map<String, dynamic>, 'key': 'reminder_due', 'default_body': 'x', 'custom': true}}),
      });
      await pumpApp(tester, server);
      await openSettings(tester);
      await tapText(tester, 'Reminder wording');

      expect(find.text('Hello :name (reminder_due en)'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Hello :name (reminder_due en)'), 'Dear :name, :amount please.');
      await tapButton(tester, 'Save');

      expect(sentBody(server, 'PUT /message-templates/reminder_due'), {'language': 'en', 'body': 'Dear :name, :amount please.'});
    });

    testWidgets('a collector does not see the wording', (tester) async {
      await pumpApp(tester, alertsServer(account: alertsAccount(role: 'collector')));
      await openSettings(tester);

      expect(find.text('Alert settings'), findsOneWidget);
      expect(find.text('Reminder wording'), findsNothing);
    });
  });

  group('pushes', () {
    testWidgets('the phone registers for pushes once signed in, and stops on signing out', (tester) async {
      final server = alertsServer();
      await pumpApp(tester, server, push: FakePushService('fcm-token'));

      expect(sentBody(server, 'POST /push-tokens'), containsPair('token', 'fcm-token'));
      expect(sentBody(server, 'POST /push-tokens')['platform'], 'android');

      await openSettings(tester);
      await tapText(tester, 'Sign out');
      await tester.tap(find.widgetWithText(TextButton, 'Sign out'));
      await settle(tester);

      expect(server.calls('DELETE /push-tokens/fcm-token'), 1);
    });

    testWidgets('a phone that takes no pushes registers nothing', (tester) async {
      final server = alertsServer();
      await pumpApp(tester, server, push: FakePushService(null));

      expect(server.calls('POST /push-tokens'), 0);
    });

    testWidgets('tapping a push opens where it points', (tester) async {
      final push = FakePushService('fcm-token');
      await pumpApp(tester, alertsServer(), push: push);

      push.tapped.add('/remind');
      await settle(tester);

      expect(find.text('0 of 2 reminded'), findsOneWidget);
    });
  });
}
