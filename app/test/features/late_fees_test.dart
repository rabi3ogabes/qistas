import 'dart:convert';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/data/late_fees.dart';
import 'package:qistas/data/models.dart';
import 'package:qistas/domain/payment_coverage.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Late fees in the app (Win Plan K4): each fee on its own line under the policy the contract was made with, waived
/// only by an owner or a manager and only with a reason; a payment covers what is due, then the fees, then the rest;
/// and the policy is set on the tools screen, its amounts typed with decimals.

Map<String, dynamic> feeJson({String status = 'open', String paid = '0.00', bool charity = false, String? reason}) => {
      'id': 'f1', 'installment_id': 'i2', 'installment_number': 2, 'period': 1, 'charged_on': '2026-10-08', 'amount': '10.00', 'paid_amount': paid,
      'remaining': status == 'open' ? '10.00' : '0.00', 'status': status, 'charity': charity, 'waived_reason': reason,
      'waived_at': status == 'waived' ? '2026-10-09T10:00:00Z' : null, 'waived_by': status == 'waived' ? {'id': 'u1', 'name': 'Layla Haddad'} : null,
    };

Map<String, dynamic> policyJson({String type = 'fixed', String amount = '10.00', String frequency = 'once', bool charity = false}) =>
    {'type': type, 'amount': amount, 'frequency': frequency, 'cap_installment': null, 'cap_contract': '50.00', 'charity': charity};

/// contractJson() with a 10.00 fee on instalment 2: 835.00 owed.
Map<String, dynamic> contractWithFee({Map<String, dynamic>? fee, Map<String, dynamic>? policy}) =>
    {...contractJson(), 'owed': '835.00', 'late_fee_policy': policy ?? policyJson(), 'late_fees': [fee ?? feeJson()]};

FakeServer feeServer({String role = 'owner', Map<String, dynamic>? contract, Map<String, Route> routes = const {}}) => workspaceServer(account: accountJson(role: role), routes: {
      'GET /contracts/k1': always(json(200, {'data': contract ?? contractWithFee()})),
      ...routes,
    });

Future<void> openContract(WidgetTester tester) async {
  await tapText(tester, 'Contracts', last: true);
  await tapText(tester, 'C-0007');
}

Future<void> showFees(WidgetTester tester) async {
  await tester.scrollUntilVisible(find.text('Instalment 2'), 300);
  await tester.pump();
}

Installment installment(int number, String due, String remaining) => Installment(
      id: 'i$number', number: number, dueDate: due, amount: Money.parse('100.00'), paidAmount: Money.parse('100.00') - Money.parse(remaining),
      remaining: Money.parse(remaining), status: remaining == '0.00' ? 'paid' : 'pending', state: 'upcoming',
    );

LateFee fee(String id, int number, String chargedOn, String remaining) => LateFee(
      id: id, installmentNumber: number, period: 1, chargedOn: chargedOn, amount: Money.parse(remaining), paidAmount: Money.zero, remaining: Money.parse(remaining), status: 'open',
    );

void main() {
  group('on the contract', () {
    testWidgets('lists each fee under the policy the contract was made with', (tester) async {
      await pumpApp(tester, feeServer());
      await openContract(tester);
      await showFees(tester);

      expect(find.text('Late fees'), findsOneWidget);
      expect(find.textContaining('once, from the day after the grace days. At most SAR'), findsOneWidget);
      expect(find.text('Owed'), findsOneWidget);
      expect(find.text('Waive this fee'), findsOneWidget);
    });

    testWidgets('says who waived a fee and why, and marks a charity fee', (tester) async {
      await pumpApp(tester, feeServer(contract: contractWithFee(fee: feeJson(status: 'waived', charity: true, reason: 'Came in the next day'), policy: policyJson(type: 'percent', amount: '2.50', frequency: 'daily', charity: true))));
      await openContract(tester);
      await showFees(tester);

      expect(find.textContaining('2.5% of what is still owed on the instalment, every day it is late'), findsOneWidget);
      expect(find.textContaining('The fees go to charity.'), findsOneWidget);
      expect(find.text('For charity'), findsOneWidget);
      expect(find.textContaining('Waived by Layla Haddad on'), findsOneWidget);
      expect(find.textContaining('Came in the next day'), findsOneWidget);
      expect(find.text('Waive this fee'), findsNothing);
    });

    testWidgets('an owner waives a fee, and only with a reason', (tester) async {
      final server = feeServer(routes: {'POST /late-fees/f1/waive': always(json(200, {'data': feeJson(status: 'waived', reason: 'Came in the next day')}))});
      await pumpApp(tester, server);
      await openContract(tester);
      await showFees(tester);
      await tapText(tester, 'Waive this fee');

      final confirm = find.widgetWithText(TextButton, 'Waive fee');
      expect(tester.widget<TextButton>(confirm).onPressed, isNull, reason: 'no reason yet');
      await tester.enterText(find.descendant(of: find.byType(AlertDialog), matching: find.byType(EditableText)), 'Came in the next day');
      await tester.pump();
      await tester.tap(confirm);
      await settle(tester);

      expect(jsonDecode(server.requestsTo('POST /late-fees/f1/waive').single.data as String), {'reason': 'Came in the next day'});
      expect(find.text('Late fee waived.'), findsOneWidget);
    });

    testWidgets('a collector sees the fees but cannot waive them', (tester) async {
      await pumpApp(tester, feeServer(role: 'collector'));
      await openContract(tester);
      await showFees(tester);

      expect(find.text('Owed'), findsOneWidget);
      expect(find.text('Waive this fee'), findsNothing);
    });

    testWidgets('the payment preview says what goes to the fee, before the instalment not yet due', (tester) async {
      await pumpApp(tester, feeServer());
      await openContract(tester);
      await tapText(tester, 'Record a payment', last: true);

      await typeInto(tester, 'Amount (SAR)', '285');
      await settle(tester);

      expect(find.textContaining('Late fees: SAR'), findsOneWidget);
      expect(find.text('#2 in full'), findsOneWidget);
      expect(find.textContaining('Still owed after: SAR'), findsOneWidget);
    });

    testWidgets('the early settlement adds the fees whole to the payoff', (tester) async {
      final account = accountJson();
      account['entitlements'] = {
        ...Map<String, dynamic>.from(account['entitlements'] as Map),
        'early_settlement': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
      };
      await pumpApp(tester, workspaceServer(account: account, routes: {
        'GET /contracts/k1': always(json(200, {'data': contractWithFee()})),
        'GET /contracts/k1/early-settlement': always(json(200, {
              'data': {'remaining': '825.00', 'late_fees': '10.00', 'earned_profit': '25.00', 'unearned_profit': '75.00', 'rebate': '0.00', 'payoff': '835.00', 'capped': false, 'rebate_type': 'none', 'rebate_value': '0.00'},
            })),
      }));
      await openContract(tester);
      await tapButton(tester, 'Settle early');

      expect(find.text('Late fees, paid in full'), findsOneWidget);
    });
  });

  group('the tools screen', () {
    Map<String, dynamic> amountTool(Object value) =>
        {'key': 'late_fees.amount', 'feature': 'late_fees', 'type': 'amount', 'label': 'Fee amount or percent', 'help': 'Zero charges nothing.', 'value': value};

    testWidgets('an amount is typed with decimals and saved as the server writes it', (tester) async {
      final server = workspaceServer(routes: {
        'GET /settings/tools': always(json(200, {'data': [amountTool('0.00')], 'meta': {'can_edit': true}})),
        'PUT /settings/tools/late_fees.amount': always(json(200, {'data': amountTool('7.50')})),
      });
      await pumpApp(tester, server);
      await openSettings(tester);
      await tapText(tester, 'Instalment tools');

      expect(tester.widget<TextField>(find.byType(TextField)).keyboardType, const TextInputType.numberWithOptions(decimal: true));
      await tester.enterText(find.byType(TextField), '7.5');
      await tester.pump();
      await tapButton(tester, 'Save');

      expect(server.adapter.lastBody, {'value': '7.5'});
      expect(tester.widget<TextField>(find.byType(TextField)).controller!.text, '7.50');
      expect(find.text('Saved'), findsOneWidget);
    });
  });

  group('the order a payment follows (App\\Domain\\Ledger\\PaymentAllocator)', () {
    final schedule = [installment(1, '2026-09-01', '100.00'), installment(2, '2026-10-01', '100.00'), installment(3, '2026-11-01', '100.00')];
    final fees = [fee('f1', 1, '2026-09-02', '10.00'), fee('f2', 2, '2026-10-02', '10.00')];

    test('what is due, then the fees, then what is not due yet', () {
      final coverage = paymentCoverage(schedule, Money.parse('230.00'), fees: fees, today: '2026-10-11')!;

      expect(coverage.covers.map((c) => (c.number, c.amount.toDecimalString())).toList(), [(1, '100.00'), (2, '100.00'), (3, '10.00')]);
      expect(coverage.toFees?.toDecimalString(), '20.00');
      expect(coverage.owedAfter.toDecimalString(), '90.00');
    });

    test('leaves the fees for later when it only covers what is due', () {
      final coverage = paymentCoverage(schedule, Money.parse('150.00'), fees: fees, today: '2026-10-11')!;

      expect(coverage.toFees, isNull);
      expect(coverage.leftOnLast?.toDecimalString(), '50.00');
    });

    test('counts the fees in what is owed', () {
      expect(paymentCoverage(schedule, Money.parse('320.00'), fees: fees, today: '2026-10-11'), isNotNull);
      expect(paymentCoverage(schedule, Money.parse('320.01'), fees: fees, today: '2026-10-11'), isNull);
    });
  });
}
