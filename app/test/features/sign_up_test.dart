import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/core/device_hint.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The words of a language, as the app reads them.
Map<String, dynamic> words(String language) => jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;

/// The sign-up screen, reached from sign-in the way a person reaches it.
Future<void> openSignUp(WidgetTester tester, FakeServer server, {String language = 'en', Size size = const Size(412, 915), double textScale = 1}) async {
  await pumpApp(tester, server, signedIn: false, language: language, size: size, textScale: textScale);
  await tapText(tester, language == 'en' ? 'Create a free account' : words(language)['Create a free account'] as String);
}

Future<void> tickTerms(WidgetTester tester) async {
  await tester.ensureVisible(find.byType(Checkbox));
  await tester.pump();
  await tester.tap(find.byType(Checkbox));
  await tester.pump();
}

Future<void> fillIn(WidgetTester tester, {String confirmation = 'a-long-Password-1!'}) async {
  await typeInto(tester, 'Your name', 'Layla Haddad');
  await typeInto(tester, 'Email', 'layla@example.com');
  await typeInto(tester, 'Password', 'a-long-Password-1!');
  await typeInto(tester, 'Confirm password', confirmation);
  await tickTerms(tester);
}

Map<String, dynamic> bodyOf(FakeServer server, String route) => jsonDecode(server.requestsTo(route).single.data as String) as Map<String, dynamic>;

/// The sign-up field that carries [label].
Finder fieldLabelled(String label) => find.byWidgetPredicate((widget) => widget is QField && widget.label == label);

FakeServer signUpServer({Map<String, Route> routes = const {}}) => workspaceServer(routes: {'POST /auth/register': always(json(201, {'data': sessionJson()})), ...routes});

void main() {
  group('the form', () {
    testWidgets('has five things to fill in: name, e-mail, password, its confirmation and the terms', (tester) async {
      await openSignUp(tester, signUpServer());

      expect(find.text('Create your free account'), findsOneWidget);
      expect(find.byType(EditableText), findsNWidgets(4));
      expect(find.byType(Checkbox), findsOneWidget);
      for (final label in ['Your name', 'Email', 'Password', 'Confirm password']) {
        expect(fieldLabelled(label), findsOneWidget, reason: '"$label" is missing');
      }
      expect(find.text('I accept the terms and the privacy policy.'), findsOneWidget);
    });

    testWidgets('does not ask for a business name or a country, and has no country picker', (tester) async {
      await openSignUp(tester, signUpServer());

      expect(find.text('Business name'), findsNothing);
      expect(find.text('Country'), findsNothing);
      expect(find.text('Saudi Arabia'), findsNothing);
      expect(find.byType(OutlinedButton), findsNothing);
      expect(find.byIcon(Icons.arrow_drop_down), findsNothing);
    });

    testWidgets('checks the form before asking the server', (tester) async {
      final server = signUpServer();
      await openSignUp(tester, server);

      await tapButton(tester, 'Start free');

      expect(find.text('Enter your name.'), findsOneWidget);
      expect(find.text('Enter your e-mail address.'), findsOneWidget);
      expect(find.text('Choose a password.'), findsOneWidget);
      expect(find.text('Please accept the terms to continue.'), findsOneWidget);
      expect(find.text('Enter your business name.'), findsNothing);
      expect(server.calls('POST /auth/register'), 0);
    });

    testWidgets('says so, next to the confirmation, when the two passwords differ, and asks nothing of the server', (tester) async {
      final server = signUpServer();
      await openSignUp(tester, server);

      await fillIn(tester, confirmation: 'a-different-Password-2!');
      await tapButton(tester, 'Start free');

      expect(find.descendant(of: fieldLabelled('Confirm password'), matching: find.text('The two passwords are not the same.')), findsOneWidget);
      expect(server.calls('POST /auth/register'), 0);
    });
  });

  group('signing up', () {
    testWidgets('posts the person, the language, the offset from UTC and the device, and no business name or country', (tester) async {
      final server = signUpServer();
      await openSignUp(tester, server);

      await fillIn(tester);
      await tapButton(tester, 'Start free');

      final body = bodyOf(server, 'POST /auth/register');
      expect(body.keys.toSet(), {'name', 'email', 'password', 'password_confirmation', 'terms', 'locale', 'device_name', 'utc_offset', 'device'});
      expect(body, isNot(contains('business_name')));
      expect(body, isNot(contains('country')));
      expect(body['name'], 'Layla Haddad');
      expect(body['email'], 'layla@example.com');
      expect(body['password'], 'a-long-Password-1!');
      expect(body['password_confirmation'], 'a-long-Password-1!');
      expect(body['terms'], isTrue);
      expect(body['locale'], 'en');
      expect(body['device_name'], deviceName());
      expect(body['utc_offset'], DateTime.now().timeZoneOffset.inMinutes);
      expect(body['device'], {'type': 'mobile', 'os': Platform.operatingSystem, 'model': deviceName(), 'app_version': AppConfig.appVersion});
      // No place and no time zone name: the server finds the place itself, and the app has no plugin for the zone's name.
      expect(body, isNot(contains('timezone')));
    });

    testWidgets('on a tablet says so', (tester) async {
      final server = signUpServer();
      await openSignUp(tester, server, size: const Size(820, 1180));

      await fillIn(tester);
      await tapButton(tester, 'Start free');

      expect((bodyOf(server, 'POST /auth/register')['device'] as Map<String, dynamic>)['type'], 'tablet');
    });

    testWidgets('speaks the app’s language to the server', (tester) async {
      final server = signUpServer();
      await openSignUp(tester, server, language: 'ar');
      final arabic = words('ar');

      await typeInto(tester, arabic['Your name'] as String, 'ليلى');
      await typeInto(tester, arabic['Email'] as String, 'layla@example.com');
      await typeInto(tester, arabic['Password'] as String, 'a-long-Password-1!');
      await typeInto(tester, arabic['Confirm password'] as String, 'a-long-Password-1!');
      await tickTerms(tester);
      await tapButton(tester, arabic['Start free'] as String);

      expect(bodyOf(server, 'POST /auth/register')['locale'], 'ar');
    });

    testWidgets('lands on the dashboard with the account the server made', (tester) async {
      await openSignUp(tester, signUpServer());

      await fillIn(tester);
      await tapButton(tester, 'Start free');

      expect(find.text('Create your free account'), findsNothing);
      expect(find.textContaining(', Layla'), findsOneWidget);
    });

    testWidgets('shows what the server found wrong with the e-mail next to the e-mail', (tester) async {
      final server = signUpServer(routes: {
        'POST /auth/register': always(apiError(422, 'validation_failed', 'The email has already been taken.', extra: {'fields': {'email': ['The email has already been taken.']}})),
      });
      await openSignUp(tester, server);

      await fillIn(tester);
      await tapButton(tester, 'Start free');

      expect(find.descendant(of: fieldLabelled('Email'), matching: find.text('The email has already been taken.')), findsOneWidget);
      expect(find.byType(QNotice), findsNothing, reason: 'a message about a field is not repeated as a banner');
      expect(find.text('Create your free account'), findsOneWidget);
      // The person's words are still there, and the button is usable again.
      expect(find.text('layla@example.com'), findsOneWidget);
      await tapButton(tester, 'Start free');
      expect(server.calls('POST /auth/register'), 2);
    });

    testWidgets('puts a complaint about something the form does not show in a banner', (tester) async {
      final server = signUpServer(routes: {
        'POST /auth/register': always(apiError(422, 'validation_failed', 'The business name field is required.', extra: {'fields': {'business_name': ['The business name field is required.']}})),
      });
      await openSignUp(tester, server);

      await fillIn(tester);
      await tapButton(tester, 'Start free');

      expect(find.text('The business name field is required.'), findsOneWidget);
      expect(find.byType(QNotice), findsOneWidget);
    });

    testWidgets('says so when there is no connection', (tester) async {
      final server = signUpServer();
      await openSignUp(tester, server);
      server.offline = true;

      await fillIn(tester);
      await tapButton(tester, 'Start free');

      expect(find.text('No connection. Check your internet and try again.'), findsOneWidget);
    });
  });

  group('large text', () {
    for (final language in ['en', 'ar']) {
      testWidgets('the sign-up form fits a 320 dp wide phone at 200% text in $language', (tester) async {
        final server = signUpServer();
        await openSignUp(tester, server, language: language, size: const Size(320, 568), textScale: 2);
        final title = language == 'en' ? 'Create your free account' : words(language)['Create your free account'] as String;
        final start = language == 'en' ? 'Start free' : words(language)['Start free'] as String;

        expect(find.text(title), findsOneWidget);
        expect(find.text(language == 'en' ? 'Confirm password' : words(language)['Confirm password'] as String), findsOneWidget);
        expect(tester.takeException(), isNull, reason: 'overflow in the empty form');

        // Every message at once is the tallest the form gets.
        await tapButton(tester, start);

        expect(tester.takeException(), isNull, reason: 'overflow with every message showing');
        expect(find.byType(EditableText), findsNWidgets(4));
        expect(server.calls('POST /auth/register'), 0);
      });
    }
  });
}
