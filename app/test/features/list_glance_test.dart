import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';
import 'investors_test.dart' show openInvestors;

/// The report's small gaps, closed in the app: the book's totals over the customer list, swiping a customer to call
/// or take a payment (and the same from a long press), searching investors, and where an investor's money is.

FakeServer customersWithContract({Map<String, dynamic>? account}) => workspaceServer(account: account, routes: {
      'GET /customers/c1': always(json(200, {'data': {...customerJson(), 'contracts': [contractJson(state: 'late')]}})),
    });

Future<void> openCustomers(WidgetTester tester, FakeServer server) async {
  await pumpApp(tester, server);
  await tapText(tester, 'Customers', last: true);
}

void main() {
  group('the customer list', () {
    testWidgets('shows the book at a glance: customers, this month’s instalments, still owed and late', (tester) async {
      await openCustomers(tester, workspaceServer());

      final totals = find.byKey(const ValueKey('book-totals'));
      expect(totals, findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('book-customers')), matching: find.text('3')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('book-due-this-month')), matching: find.text('SAR 1,920.00')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('book-outstanding')), matching: find.text('SAR 5,000.00')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('book-overdue')), matching: find.text('SAR 300.00')), findsOneWidget);
    });

    testWidgets('a swipe towards the start takes a payment on the contract that needs it', (tester) async {
      final server = customersWithContract();
      await openCustomers(tester, server);

      await tester.drag(find.text('Ahmad Salem'), const Offset(-320, 0));
      await settle(tester);

      expect(server.calls('GET /customers/c1'), greaterThan(0));
      expect(server.calls('GET /contracts/k1'), greaterThan(0));
      expect(find.widgetWithText(TextField, '275.00'), findsOneWidget);
      // The row springs back: nobody is ever swiped out of the list.
      Navigator.of(tester.element(find.widgetWithText(TextField, '275.00'))).pop();
      await settle(tester);
      expect(find.text('Ahmad Salem'), findsOneWidget);
    });

    testWidgets('a long press offers the same, and more', (tester) async {
      await openCustomers(tester, customersWithContract());

      await tester.longPress(find.text('Ahmad Salem'));
      await settle(tester);

      for (final key in ['quick-call', 'quick-whatsapp', 'quick-pay', 'quick-open', 'quick-pin']) {
        expect(find.byKey(ValueKey(key)), findsOneWidget, reason: key);
      }
    });

    testWidgets('someone who only views can reach the customer but not take money', (tester) async {
      final server = customersWithContract(account: accountJson(role: 'viewer'));
      await openCustomers(tester, server);

      await tester.longPress(find.text('Ahmad Salem'));
      await settle(tester);
      expect(find.byKey(const ValueKey('quick-call')), findsOneWidget);
      expect(find.byKey(const ValueKey('quick-pay')), findsNothing);
      expect(find.byKey(const ValueKey('quick-pin')), findsNothing);
      await tester.tapAt(const Offset(20, 20));
      await settle(tester);

      await tester.drag(find.text('Ahmad Salem'), const Offset(-320, 0));
      await settle(tester);
      expect(server.calls('GET /contracts/k1'), 0);
    });
  });

  group('investors', () {
    Future<void> withPartner(WidgetTester tester) async {
      await pumpApp(tester, workspaceServer(account: accountWithInvestors(plan: 'pro'), routes: {
        'GET /investors': always(json(200, {'data': investorsJson(partner: true, limit: null)})),
      }));
      await openInvestors(tester);
    }

    testWidgets('can be searched by name, and say when nothing matches', (tester) async {
      await withPartner(tester);

      await tester.enterText(find.byKey(const ValueKey('investor-search')), 'khalid');
      await settle(tester);
      expect(find.text('Khalid Al-Harbi'), findsOneWidget);
      expect(find.text('Own capital'), findsNothing);

      await tester.enterText(find.byKey(const ValueKey('investor-search')), 'zzz');
      await settle(tester);
      expect(find.text('No investors match “zzz”'), findsOneWidget);
    });

    testWidgets('show no search while there is only the business’s own capital', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountWithInvestors(), routes: {'GET /investors': always(json(200, {'data': investorsJson()}))}));
      await openInvestors(tester);

      expect(find.byKey(const ValueKey('investor-search')), findsNothing);
    });

    testWidgets('an investor’s page shows where the money is', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountWithInvestors(), routes: {
        'GET /investors': always(json(200, {'data': investorsJson()})),
        'GET /investors/i1': always(json(200, {'data': investorDetailJson()})),
      }));
      await openInvestors(tester);
      await tapText(tester, 'Own capital');

      expect(find.text('Where the money is'), findsOneWidget);
      expect(find.text('At work'), findsOneWidget);
      expect(find.text('SAR 10,080'), findsOneWidget); // 500.00 out in contracts + 9,580.00 in the wallet
      expect(find.textContaining('5%'), findsWidgets);
      expect(find.textContaining('95%'), findsOneWidget);
    });
  });
}
