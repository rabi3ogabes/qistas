import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart' show TextButton;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/data/models.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// A free workspace that already has five customers: the next one is over the limit.
Map<String, dynamic> fullAccount() => accountJson(customersUsed: 5);

void main() {
  group('customers', () {
    testWidgets('lists the customers and opens one', (tester) async {
      await pumpApp(tester, workspaceServer());

      await tapText(tester, 'Customers', last: true);
      expect(find.text('Ahmad Salem'), findsOneWidget);
      expect(find.text('SAR 200.00'), findsOneWidget);

      await tapText(tester, 'Ahmad Salem');
      expect(find.text('+966501234567'), findsWidgets);
      expect(find.text('Open a contract'), findsWidgets);
    });

    testWidgets('on a full plan, Add customer answers with the upgrade sheet and never opens the form', (tester) async {
      final server = workspaceServer(account: fullAccount());
      await pumpApp(tester, server);

      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');

      expect(find.text('You have reached your plan limit'), findsOneWidget);
      expect(find.textContaining('up to 5 customers'), findsOneWidget);
      expect(find.text('Full name'), findsNothing);
      expect(server.calls('POST /customers'), 0);
    });

    testWidgets('a server 402 when saving opens the same sheet instead of a generic error', (tester) async {
      final server = workspaceServer(routes: {
        'POST /customers': always(apiError(402, 'limit_reached', 'Your plan includes up to 5 customers.', extra: {'feature': 'customers', 'limit': 5, 'used': 5})),
      });
      await pumpApp(tester, server);

      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      await typeInto(tester, 'Full name', 'Sara Al-Qahtani');
      await typeInto(tester, 'Phone', '+966541110099');
      await tapButton(tester, 'Add customer');

      expect(server.calls('POST /customers'), 1);
      expect(find.text('You have reached your plan limit'), findsOneWidget);
    });

    testWidgets('checks the form before asking the server', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);

      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      await tapButton(tester, 'Add customer');

      expect(find.text('Enter the customer’s name.'), findsOneWidget);
      expect(find.text('Enter a phone number we can reach them on.'), findsOneWidget);
      expect(server.calls('POST /customers'), 0);
    });

    testWidgets('saves a customer and shows their page', (tester) async {
      final server = workspaceServer(routes: {
        'POST /customers': always(json(201, {'data': customerJson(id: 'c2', name: 'Sara Al-Qahtani')})),
        'GET /customers/c2': always(json(200, {'data': {...customerJson(id: 'c2', name: 'Sara Al-Qahtani'), 'contracts': <Map<String, dynamic>>[]}})),
      });
      await pumpApp(tester, server);

      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Add customer');
      await typeInto(tester, 'Full name', '  Sara Al-Qahtani ');
      await typeInto(tester, 'Phone', '+966541110099');
      await tapButton(tester, 'Add customer');

      final sent = jsonDecode(server.requestsTo('POST /customers').single.data as String) as Map<String, dynamic>;
      expect(sent['name'], 'Sara Al-Qahtani');
      expect(sent['phone'], '+966541110099');
      expect(find.text('Sara Al-Qahtani'), findsWidgets);
    });

    testWidgets('a viewer can look but not add', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountJson(role: 'viewer')));

      await tapText(tester, 'Customers', last: true);

      expect(find.text('Ahmad Salem'), findsOneWidget);
      expect(find.text('Add customer'), findsNothing);
    });
  });

  group('taking a payment', () {
    Future<void> openRecordSheet(WidgetTester tester) async {
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapText(tester, 'Record a payment', last: true);
    }

    testWidgets('a double tap sends one request', (tester) async {
      final server = workspaceServer(routes: {
        'POST /contracts/k1/payments': (options) async {
          await Future<void>.delayed(const Duration(milliseconds: 300));

          return json(201, {'data': lineJson()});
        },
      });
      await pumpApp(tester, server);
      await openRecordSheet(tester);

      final button = find.widgetWithText(QButton, 'Record payment');
      await tester.ensureVisible(button);
      final where = tester.getCenter(button);
      await tester.tapAt(where);
      await tester.pump(const Duration(milliseconds: 20));
      // The button is now a spinner; a second finger on the same spot must not send the payment again.
      await tester.tapAt(where);
      await settle(tester);

      expect(server.calls('POST /contracts/k1/payments'), 1);
      expect(find.textContaining('recorded'), findsOneWidget);
    });

    testWidgets('a retry after a dropped connection reuses the key; a changed amount gets a new one', (tester) async {
      var attempt = 0;
      final server = workspaceServer(routes: {
        'POST /contracts/k1/payments': (options) {
          attempt++;

          return attempt == 1 ? throw DioException.connectionError(requestOptions: options, reason: 'down') : json(201, {'data': lineJson()});
        },
      });
      await pumpApp(tester, server);
      await openRecordSheet(tester);

      await tapButton(tester, 'Record payment');
      expect(find.text('No connection. Check your internet and try again.'), findsOneWidget);
      await tapButton(tester, 'Record payment');

      final keys = server.requestsTo('POST /contracts/k1/payments').map((r) => r.headers['Idempotency-Key']).toList();
      expect(keys, hasLength(2));
      expect(keys.first, isNotNull);
      expect(keys.first, keys.last);
    });

    testWidgets('refuses an amount above what is owed, without asking the server', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);
      await openRecordSheet(tester);

      await typeInto(tester, 'Amount (SAR)', '900');
      await tapButton(tester, 'Record payment');

      expect(find.textContaining('more than the SAR 825.00 still owed'), findsOneWidget);
      expect(server.calls('POST /contracts/k1/payments'), 0);
    });

    testWidgets('sends the amount as the server expects it', (tester) async {
      final server = workspaceServer(routes: {'POST /contracts/k1/payments': always(json(201, {'data': lineJson()}))});
      await pumpApp(tester, server);
      await openRecordSheet(tester);

      await typeInto(tester, 'Amount (SAR)', '275,5');
      await tapText(tester, 'Bank transfer');
      await tapButton(tester, 'Record payment');

      final body = jsonDecode(server.requestsTo('POST /contracts/k1/payments').single.data as String) as Map<String, dynamic>;
      expect(body['amount'], '275.50');
      expect(body['method'], 'bank_transfer');
    });
  });

  group('the workspace', () {
    testWidgets('marks an admin’s test workspace', (tester) async {
      final account = accountJson();
      account['tenant'] = {...Map<String, dynamic>.from(account['tenant'] as Map), 'is_test': true};
      await pumpApp(tester, workspaceServer(account: account));

      expect(find.textContaining('Test workspace', findRichText: true), findsOneWidget);
      expect(Account.fromJson(account).isTest, isTrue);
    });

    testWidgets('shows a cached dashboard banner offline and recovers with Try again', (tester) async {
      final server = workspaceServer()..offline = true;
      await pumpApp(tester, server, preferences: {'account': jsonEncode(sampleAccount().toJson())});
      expect(find.textContaining('You are offline'), findsOneWidget);

      server.offline = false;
      await tester.tap(find.widgetWithText(TextButton, 'Try again').first);
      await settle(tester);

      expect(find.textContaining('You are offline'), findsNothing);
    });

    testWidgets('signs out from More and returns to sign-in', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'POST /auth/logout': always(json(200, {'data': <String, dynamic>{}}))}));

      await tapText(tester, 'More', last: true);
      await tapButton(tester, 'Sign out');
      await tester.tap(find.widgetWithText(TextButton, 'Sign out'));
      await settle(tester);

      expect(find.text('Welcome back'), findsOneWidget);
    });
  });
}
