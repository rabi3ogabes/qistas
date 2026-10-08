import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/data/models.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

FakeServer demoServer({Map<String, Route> routes = const {}}) => workspaceServer(routes: {
      'GET /demo': always(json(200, {'data': demoOfferJson()})),
      ...routes,
    });

Map<String, dynamic> demoSession(String plan) => {
      'token': 'qst_demo-$plan',
      'token_type': 'Bearer',
      'expires_at': '2026-10-09T01:00:00Z',
      ...demoAccountJson(plan: plan),
    };

void main() {
  group('the offer', () {
    testWidgets('is shown on the sign-in screen when the server has the demo on', (tester) async {
      await pumpApp(tester, demoServer(), signedIn: false);

      expect(find.text('Just looking around?'), findsOneWidget);
      expect(find.text('Enter as admin'), findsOneWidget);
      expect(find.text('Every feature, on the Pro plan'), findsOneWidget);
      expect(find.text('Enter as user'), findsOneWidget);
      expect(find.text('The Free plan, with its limits'), findsOneWidget);
    });

    testWidgets('is not shown, and takes no room, when the demo is off', (tester) async {
      await pumpApp(tester, workspaceServer(), signedIn: false);

      expect(find.text('Just looking around?'), findsNothing);
      expect(find.text('Enter as admin'), findsNothing);
    });

    testWidgets('is not shown when the server cannot be asked, and the rest of sign-in still works', (tester) async {
      final server = workspaceServer()..offline = true;
      await pumpApp(tester, server, signedIn: false);

      expect(find.text('Welcome back'), findsOneWidget);
      expect(find.text('Enter as admin'), findsNothing);
    });

    testWidgets('is not shown by an older server that does not know the demo', (tester) async {
      final server = workspaceServer(routes: {'GET /demo': always(apiError(404, 'not_found', 'Not found.'))});
      await pumpApp(tester, server, signedIn: false);

      expect(find.text('Enter as admin'), findsNothing);
    });

    testWidgets('speaks the server’s words in the app’s language', (tester) async {
      final server = demoServer(routes: {
        'GET /demo': (options) => json(200, {'data': {...demoOfferJson(), 'personas': [
          {'key': 'admin', 'label': 'ادخل كمشرف', 'description': 'كل الميزات، على خطة Pro', 'plan': 'pro'},
        ]}}),
      });
      await pumpApp(tester, server, signedIn: false, language: 'ar');

      expect(find.text('ادخل كمشرف'), findsOneWidget);
      expect(server.requestsTo('GET /demo').first.headers['Accept-Language'], 'ar');
    });
  });

  group('entering the demo', () {
    testWidgets('as an admin: signs in with the Pro workspace and says it is a demo', (tester) async {
      final server = demoServer(routes: {'POST /demo/admin': always(json(201, {'data': demoSession('pro')}))});
      await pumpApp(tester, server, signedIn: false);

      await tapText(tester, 'Enter as admin');

      expect(server.calls('POST /demo/admin'), 1);
      expect(jsonDecode(server.requestsTo('POST /demo/admin').single.data as String), containsPair('device_name', isA<String>()));
      expect(find.text('Dashboard'), findsWidgets);
      expect(find.textContaining('Demo workspace', findRichText: true), findsOneWidget);
      expect(find.text('Create my free account'), findsOneWidget);
    });

    testWidgets('as a user: opens the Free workspace', (tester) async {
      final server = demoServer(routes: {
        'POST /demo/user': always(json(201, {'data': demoSession('free')})),
        'GET /me': always(json(200, {'data': demoAccountJson(plan: 'free')})),
      });
      await pumpApp(tester, server, signedIn: false);

      await tapText(tester, 'Enter as user');

      expect(server.calls('POST /demo/user'), 1);
      expect(find.text('Free'), findsWidgets);
      expect(find.textContaining('Demo workspace', findRichText: true), findsOneWidget);
    });

    testWidgets('one press makes one account, however many fingers', (tester) async {
      final server = demoServer(routes: {
        'POST /demo/admin': (options) async {
          await Future<void>.delayed(const Duration(milliseconds: 300));

          return json(201, {'data': demoSession('pro')});
        },
      });
      await pumpApp(tester, server, signedIn: false);

      final where = tester.getCenter(find.text('Enter as admin'));
      await tester.tapAt(where);
      await tester.pump(const Duration(milliseconds: 20));
      await tester.tapAt(where);
      await tester.tapAt(tester.getCenter(find.text('Enter as user')));
      await settle(tester);

      expect(server.calls('POST /demo/admin'), 1);
      expect(server.calls('POST /demo/user'), 0);
    });

    testWidgets('says so, and stays on sign-in, when the demo is full', (tester) async {
      final server = demoServer(routes: {
        'POST /demo/user': always(apiError(503, 'demo_busy', 'The demo is busy right now. Please try again in a few minutes.')),
      });
      await pumpApp(tester, server, signedIn: false);

      await tapText(tester, 'Enter as user');

      expect(find.text('The demo is busy right now. Please try again in a few minutes.'), findsOneWidget);
      expect(find.text('Welcome back'), findsOneWidget);
      // The buttons are usable again.
      expect(find.text('Enter as user'), findsOneWidget);
    });

    testWidgets('says so when there are too many demos from this address', (tester) async {
      final server = demoServer(routes: {
        'POST /demo/admin': always(apiError(429, 'rate_limited', 'Too many requests. Please wait a moment and try again.', extra: {'retry_after': 600})),
      });
      await pumpApp(tester, server, signedIn: false);

      await tapText(tester, 'Enter as admin');

      expect(find.text('Too many requests. Please wait a moment and try again.'), findsOneWidget);
    });

    testWidgets('keeps the token the server gave, so the next launch is still in the demo', (tester) async {
      final server = demoServer(routes: {'POST /demo/admin': always(json(201, {'data': demoSession('pro')}))});
      await pumpApp(tester, server, signedIn: false);

      await tapText(tester, 'Enter as admin');
      await tapText(tester, 'Customers', last: true);

      expect(server.requestsTo('GET /customers').first.headers['Authorization'], 'Bearer qst_demo-pro');
    });
  });

  group('leaving the demo', () {
    testWidgets('“Create my free account” signs out of the demo and opens sign-up', (tester) async {
      final server = workspaceServer(account: demoAccountJson(), routes: {'POST /auth/logout': always(json(200, {'data': <String, dynamic>{}}))});
      await pumpApp(tester, server);

      await tapText(tester, 'Create my free account');

      expect(server.calls('POST /auth/logout'), 1);
      expect(find.text('Create your free account'), findsOneWidget);
    });

    testWidgets('a customer’s own workspace is never called a demo', (tester) async {
      await pumpApp(tester, workspaceServer());

      expect(find.textContaining('Demo workspace', findRichText: true), findsNothing);
      expect(Account.fromJson(accountJson()).isDemo, isFalse);
    });
  });
}
