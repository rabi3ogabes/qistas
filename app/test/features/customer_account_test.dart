import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The customer's screen is where the day's work happens: one contract's lines with the balance after each, what is
/// unpaid where it fell due (one tap from being paid), and the totals pinned under the screen.

Map<String, dynamic> accountJson2({String late = '275.00'}) => {
      'contract_id': 'k1', 'open': false, 'takes_payments': true, 'next_installment_id': 'i2',
      'lines': [
        {'kind': 'sale', 'date': '2026-10-07', 'added': '1300.00', 'paid': null, 'balance': '1300.00', 'transaction_id': null, 'method': null, 'tag': null, 'note': null, 'installment': null},
        {'kind': 'down_payment', 'date': '2026-10-07', 'added': null, 'paid': '200.00', 'balance': '1100.00', 'transaction_id': 't0', 'method': 'cash', 'tag': null, 'note': null, 'installment': null},
        {'kind': 'payment', 'date': '2026-11-07', 'added': null, 'paid': '275.00', 'balance': '825.00', 'transaction_id': 't1', 'method': 'cash', 'tag': 'advance', 'note': null, 'installment': null},
        {'kind': 'unpaid', 'date': '2026-12-07', 'added': null, 'paid': null, 'balance': null, 'transaction_id': null, 'method': null, 'tag': null, 'note': null,
          'installment': {'id': 'i2', 'number': 2, 'due_date': '2026-12-07', 'remaining': '275.00'}},
      ],
      'totals': {'instalment': '275.00', 'paid_count': 1, 'left_count': 3, 'paid': '475.00', 'left': '825.00', 'late': late},
    };

FakeServer customerServer() => workspaceServer(routes: {
      'GET /customers/c1': always(json(200, {'data': {...customerJson(), 'contracts': [contractJson(state: 'late')]}})),
      'GET /contracts/k1/account': always(json(200, {'data': accountJson2()})),
    });

Future<void> openCustomer(WidgetTester tester, FakeServer server) async {
  await pumpApp(tester, server);
  await tapText(tester, 'Customers', last: true);
  await tapText(tester, 'Ahmad Salem');
}

void main() {
  testWidgets('shows the contract’s lines with what was added, what was paid and the balance after each', (tester) async {
    final server = customerServer();
    await openCustomer(tester, server);

    expect(server.calls('GET /contracts/k1/account'), greaterThan(0));
    expect(find.text('Transactions'), findsOneWidget);
    expect(find.byKey(const ValueKey('contract-chip-k1')), findsOneWidget);
    expect(find.text('1,300.00'), findsNWidgets(2));
    expect(find.text('Payment (Cash) · Advance'), findsOneWidget);
    expect(find.text('825.00'), findsWidgets);
  });

  testWidgets('marks the unpaid instalment where it fell due, and a tap opens the payment with its amount', (tester) async {
    final server = customerServer();
    await openCustomer(tester, server);

    expect(find.text('Unpaid. Record a payment'), findsOneWidget);
    await tester.ensureVisible(find.byKey(const ValueKey('unpaid-i2')));
    await tester.tap(find.byKey(const ValueKey('unpaid-i2')));
    await settle(tester);

    expect(server.calls('GET /contracts/k1'), greaterThan(0));
    expect(find.widgetWithText(TextField, '275.00'), findsOneWidget);
  });

  testWidgets('pins the totals under the screen, with the late amount marked', (tester) async {
    await openCustomer(tester, customerServer());

    final totals = find.byKey(const ValueKey('account-totals'));
    expect(totals, findsOneWidget);
    for (final label in ['Instalment', 'Instalments paid', 'Instalments left', 'Paid', 'Still owed', 'Late']) {
      expect(find.descendant(of: totals, matching: find.text(label)), findsOneWidget, reason: label);
    }
    expect(find.descendant(of: find.byKey(const ValueKey('total-late')), matching: find.text('275.00')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('total-left-count')), matching: find.text('3')), findsOneWidget);
  });

  testWidgets('someone who only views sees the unpaid line but cannot take money', (tester) async {
    final server = workspaceServer(account: accountJson(role: 'viewer'), routes: {
      'GET /customers/c1': always(json(200, {'data': {...customerJson(), 'contracts': [contractJson(state: 'late')]}})),
      'GET /contracts/k1/account': always(json(200, {'data': accountJson2()})),
    });
    await openCustomer(tester, server);

    expect(find.text('Unpaid. Record a payment'), findsNothing);
    expect(find.byKey(const ValueKey('account-record-payment')), findsNothing);
    expect(find.text('Unpaid'), findsWidgets);
  });
}
