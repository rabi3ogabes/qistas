import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

void main() {
  group('launching', () {
    testWidgets('a signed-in person lands on the dashboard with their figures', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);

      expect(find.text('Dashboard'), findsWidgets);
      expect(find.text('Al-Fares Electronics'), findsOneWidget);
      expect(find.text('SAR 5,000.00'), findsOneWidget);
      expect(find.text('Ahmad Salem'), findsOneWidget);
      expect(server.adapter.requests.first.headers['Authorization'], 'Bearer qst_test-token');
    });

    testWidgets('a person who is not signed in meets the sign-in screen, not the app', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server, signedIn: false);

      expect(find.text('Welcome back'), findsOneWidget);
      expect(find.text('Sign in'), findsWidgets);
      expect(server.calls('GET /dashboard'), 0);
    });

    testWidgets('a first launch shows the introduction before sign-in', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false, preferences: {'onboarded': false});

      expect(find.text('Welcome back'), findsNothing);
      expect(find.text('Skip'), findsOneWidget);
    });

    testWidgets('with no connection it shows what was last saved, and says so', (tester) async {
      final server = workspaceServer()..offline = true;
      await pumpApp(tester, server, preferences: {'account': jsonEncode(sampleAccount().toJson())});

      expect(find.textContaining('You are offline'), findsOneWidget);
      expect(find.text('Al-Fares Electronics'), findsOneWidget);
    });
  });

  group('signing in', () {
    testWidgets('signs in and goes to the dashboard', (tester) async {
      final server = workspaceServer(routes: {'POST /auth/login': always(json(200, {'data': sessionJson()}))});
      await pumpApp(tester, server, signedIn: false);

      await typeInto(tester, 'Email', 'layla@example.com');
      await typeInto(tester, 'Password', 'a-long-Password-1!');
      await tapText(tester, 'Sign in', last: true);

      expect(server.requestsTo('POST /auth/login').single.data, contains('layla@example.com'));
      expect(find.text('Al-Fares Electronics'), findsOneWidget);
      expect(find.text('Welcome back'), findsNothing);
    });

    testWidgets('asks for the code when two-step sign-in is on, then signs in with it', (tester) async {
      var attempts = 0;
      final server = workspaceServer(routes: {
        'POST /auth/login': (options) {
          attempts++;
          final body = jsonDecode(options.data as String) as Map<String, dynamic>;

          return body.containsKey('code') ? json(200, {'data': sessionJson()}) : apiError(422, 'two_factor_required', 'Enter the code from your authenticator app.');
        },
      });
      await pumpApp(tester, server, signedIn: false);

      await typeInto(tester, 'Email', 'layla@example.com');
      await typeInto(tester, 'Password', 'a-long-Password-1!');
      await tapText(tester, 'Sign in', last: true);

      expect(find.text('Authentication code'), findsOneWidget);
      expect(find.text('Al-Fares Electronics'), findsNothing);

      await typeInto(tester, 'Authentication code', '123456');
      await tapText(tester, 'Sign in', last: true);

      expect(attempts, 2);
      expect(jsonDecode(server.requestsTo('POST /auth/login').last.data as String), containsPair('code', '123456'));
      expect(find.text('Al-Fares Electronics'), findsOneWidget);
    });

    testWidgets('says what was wrong, and does not sign in, when the password is refused', (tester) async {
      final server = workspaceServer(routes: {
        'POST /auth/login': always(apiError(422, 'validation_failed', 'These credentials do not match our records.', extra: {'fields': {'email': ['These credentials do not match our records.']}})),
      });
      await pumpApp(tester, server, signedIn: false);

      await typeInto(tester, 'Email', 'layla@example.com');
      await typeInto(tester, 'Password', 'wrong');
      await tapText(tester, 'Sign in', last: true);

      expect(find.text('These credentials do not match our records.'), findsOneWidget);
      expect(find.text('Al-Fares Electronics'), findsNothing);
    });

    testWidgets('checks the form before asking the server', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server, signedIn: false);

      await tapText(tester, 'Sign in', last: true);

      expect(find.text('Enter your e-mail address.'), findsOneWidget);
      expect(find.text('Enter your password.'), findsOneWidget);
      expect(server.calls('POST /auth/login'), 0);
    });
  });

  group('an expired session', () {
    testWidgets('returns to sign-in once, with an explanation, however many requests were in flight', (tester) async {
      final server = workspaceServer(routes: {
        'GET /dashboard': always(apiError(401, 'unauthenticated', 'Unauthenticated.')),
        'GET /customers': always(apiError(401, 'unauthenticated', 'Unauthenticated.')),
      });
      await pumpApp(tester, server);

      expect(find.text('Welcome back'), findsOneWidget);
      expect(find.text('Your session ended. Please sign in again.'), findsOneWidget);
      expect(find.text('Al-Fares Electronics'), findsNothing);
    });
  });
}
