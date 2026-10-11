import 'dart:convert';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/data/appearance.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';

/// Win Plan PP17 in the app: Help. Short answers to search in the person's language; Message us opens WhatsApp with the
/// workspace's support code and the app version (never a password), or the person writes in the app; the reply promise
/// is on the screen; the team's replies show under each question. What's new appears once after an update.

Map<String, dynamic> supportJson({String? whatsapp}) => {'whatsapp': whatsapp, 'code': 'Q-3F9A2C', 'reply_hours': 24, 'missing_data_hours': 4};

FakeServer helpServer({String? whatsapp, List<Map<String, dynamic>> tickets = const [], Map<String, Route> routes = const {}}) => workspaceServer(routes: {
      'GET /support': always(json(200, {'data': supportJson(whatsapp: whatsapp)})),
      'GET /support/tickets': always(json(200, {'data': tickets})),
      'POST /support/tickets': always(json(201, {'data': {'id': 't1', 'category': 'problem', 'message': 'x', 'status': 'open', 'created_at': '2026-10-11T09:00:00Z', 'replies': <Object?>[]}})),
      'GET /release-notes': always(json(200, {'data': <Object?>[]})),
      ...routes,
    });

Future<void> openHelp(WidgetTester tester) async {
  await openSettings(tester);
  await tapText(tester, 'Get help');
}

void main() {
  group('help', () {
    testWidgets('finds an answer by searching, in the person’s words', (tester) async {
      await pumpApp(tester, helpServer());
      await openHelp(tester);

      expect(find.text('Add your first customer'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('help-search')), 'late');
      await settle(tester);

      expect(find.text('See who is late and remind them'), findsOneWidget);
      expect(find.text('Add your first customer'), findsNothing);
    });

    testWidgets('shows the reply promise', (tester) async {
      await pumpApp(tester, helpServer());
      await openHelp(tester);

      expect(find.text('We reply within 24 hours, and within 4 hours if data looks missing.'), findsOneWidget);
    });

    testWidgets('sends a question with the app version and the platform, and nothing else', (tester) async {
      final server = helpServer();
      await pumpApp(tester, server);
      await openHelp(tester);

      await tapButton(tester, 'Write to us');
      await tapText(tester, 'Something is wrong');
      await typeInto(tester, 'Your message', 'A payment I recorded yesterday is not on the contract.');
      await tapButton(tester, 'Send');

      final body = jsonDecode(server.requestsTo('POST /support/tickets').single.data as String) as Map<String, dynamic>;
      expect(body, {'category': 'problem', 'message': 'A payment I recorded yesterday is not on the contract.', 'app_version': AppConfig.appVersion, 'platform': 'android'});
      expect(find.text('Sent. We reply here and on your phone.'), findsOneWidget);
    });

    testWidgets('Message us opens WhatsApp with the support code and the app version, never a password', (tester) async {
      final opened = <Uri>[];
      await pumpApp(tester, helpServer(whatsapp: '966501234567'), overrides: [
        openExternalProvider.overrideWithValue((uri) async {
          opened.add(uri);

          return true;
        }),
      ]);
      await openHelp(tester);

      await tapButton(tester, 'Message us on WhatsApp');

      final uri = opened.single;
      expect(uri.host, 'wa.me');
      expect(uri.path, '/966501234567');
      final message = uri.queryParameters['text']!;
      expect(message, contains('Q-3F9A2C'));
      expect(message, contains(AppConfig.appVersion));
      expect(message.toLowerCase(), isNot(anyOf(contains('password'), contains('qst_'), contains('token'))));
    });

    testWidgets('without a WhatsApp number, the person writes in the app', (tester) async {
      await pumpApp(tester, helpServer());
      await openHelp(tester);

      expect(find.text('Message us on WhatsApp'), findsNothing);
      expect(find.text('Write to us'), findsOneWidget);
    });

    testWidgets('shows the team’s replies under the person’s questions', (tester) async {
      await pumpApp(tester, helpServer(tickets: [
        {
          'id': 't1', 'category': 'missing_data', 'message': 'Three customers are gone.', 'status': 'answered', 'created_at': '2026-10-11T09:00:00Z',
          'replies': [{'body': 'They were archived by a team member; they are back now.', 'created_at': '2026-10-11T10:00:00Z'}],
        },
      ]));
      await openHelp(tester);

      expect(find.text('Three customers are gone.'), findsOneWidget);
      expect(find.text('They were archived by a team member; they are back now.'), findsOneWidget);
      expect(find.text('Answered'), findsOneWidget);
    });
  });

  group('what’s new', () {
    final notes = {'data': [{'version': '1.16.0', 'language': 'en', 'body': 'Help inside the app, and a calculator.'}]};

    testWidgets('appears once after an update, with the notes since the version last seen', (tester) async {
      final server = helpServer(routes: {'GET /release-notes': always(json(200, notes))});
      await pumpApp(tester, server, preferences: {'whats_new_seen': '1.15.0'});

      expect(find.text('Help inside the app, and a calculator.'), findsOneWidget);
      expect(server.requestsTo('GET /release-notes').single.queryParameters['since'], '1.15.0');
      await tapButton(tester, 'Got it');
      expect(find.text('Help inside the app, and a calculator.'), findsNothing);
    });

    testWidgets('stays quiet on a first install and once seen', (tester) async {
      final server = helpServer(routes: {'GET /release-notes': always(json(200, notes))});
      await pumpApp(tester, server);
      expect(find.text('Help inside the app, and a calculator.'), findsNothing);
      expect(server.requestsTo('GET /release-notes'), isEmpty);
    });

    testWidgets('stays quiet when the version has not changed', (tester) async {
      final server = helpServer(routes: {'GET /release-notes': always(json(200, notes))});
      await pumpApp(tester, server, preferences: {'whats_new_seen': AppConfig.appVersion});

      expect(find.text('Help inside the app, and a calculator.'), findsNothing);
      expect(server.requestsTo('GET /release-notes'), isEmpty);
    });
  });

  testWidgets('the + button offers the calculator and help', (tester) async {
    await pumpApp(tester, helpServer());

    await tapTooltip(tester, 'Quick actions');

    expect(find.text('Calculator'), findsOneWidget);
    expect(find.text('Help'), findsOneWidget);
  });
}
