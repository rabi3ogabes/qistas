import 'dart:convert';

import 'package:dio/dio.dart' show RequestOptions;
import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan PP6 in the app: settle early with the payoff in front of you, change the plan with the old and the new
/// schedules side by side, choose which instalment a payment covers, and take one payment across a customer's contracts.

Map<String, dynamic> planAccount({String role = 'owner'}) {
  final account = accountJson(role: role);
  Map<String, dynamic> on() => {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false};
  account['entitlements'] = {
    ...Map<String, dynamic>.from(account['entitlements'] as Map),
    'early_settlement': on(),
    'reschedule': on(),
    'payment_targeting': on(),
  };

  return account;
}

/// contractJson(): 1,100.00 in four instalments of 275.00, the first paid, 825.00 still owed.
Map<String, dynamic> quoteJson(String type) => type == 'percent'
    ? {'remaining': '825.00', 'earned_profit': '25.00', 'unearned_profit': '75.00', 'rebate': '37.50', 'payoff': '787.50', 'capped': false, 'rebate_type': 'percent', 'rebate_value': '50.0000'}
    : {'remaining': '825.00', 'earned_profit': '25.00', 'unearned_profit': '75.00', 'rebate': '0.00', 'payoff': '825.00', 'capped': false, 'rebate_type': 'none', 'rebate_value': '0.00'};

Map<String, dynamic> sent(FakeServer server, String route) => jsonDecode(server.requestsTo(route).last.data as String) as Map<String, dynamic>;

FakeServer planServer({Map<String, dynamic>? account, Map<String, Route> routes = const {}}) => workspaceServer(account: account ?? planAccount(), routes: {
      'GET /contracts/k1/early-settlement': (RequestOptions options) => json(200, {'data': quoteJson((options.queryParameters['rebate_type'] ?? 'none').toString())}),
      'POST /contracts/k1/early-settlement': always(json(201, {'data': contractJson(status: 'settled', state: 'settled')})),
      'POST /contracts/k1/amendments/preview': always(json(200, {
            'data': {
              'old_schedule': [
                {'number': 2, 'due_date': '2026-12-07', 'amount': '275.00'},
                {'number': 3, 'due_date': '2027-01-07', 'amount': '275.00'},
                {'number': 4, 'due_date': '2027-02-07', 'amount': '275.00'},
              ],
              'new_schedule': [
                for (var n = 0; n < 6; n++) {'due_date': '2027-0${n + 1}-07', 'amount': n == 5 ? '137.50' : '137.50'},
              ],
            },
          })),
      'POST /contracts/k1/amendments': always(json(201, {'data': {'amendment': {'id': 'a1', 'type': 'reschedule'}, 'contract': contractJson()}})),
      ...routes,
    });

Future<void> openContract(WidgetTester tester) async {
  await tapText(tester, 'Contracts', last: true);
  await tapText(tester, 'C-0007');
}

void main() {
  group('settling early', () {
    testWidgets('shows the payoff, recomputes it for a discount, and settles at the figure shown', (tester) async {
      final server = planServer();
      await pumpApp(tester, server);
      await openContract(tester);

      await tapButton(tester, 'Settle early');
      expect(find.text('SAR 825.00'), findsWidgets);

      await tapText(tester, 'Percent');
      expect(server.requestsTo('GET /contracts/k1/early-settlement').last.queryParameters, {'rebate_type': 'percent', 'rebate_value': '50'});
      expect(find.text('SAR 787.50'), findsWidgets);
      expect(find.text('The customer saves SAR 37.50.'), findsOneWidget);

      await tapButton(tester, 'Settle for SAR 787.50');

      final request = server.requestsTo('POST /contracts/k1/early-settlement').single;
      expect(jsonDecode(request.data as String), {'rebate_type': 'percent', 'rebate_value': '50', 'payoff': '787.50', 'method': 'cash'});
      expect(request.headers['Idempotency-Key'], isNotNull);
    });

    testWidgets('a figure that went stale is fetched again and said so; nothing is settled', (tester) async {
      final server = planServer(routes: {
        'POST /contracts/k1/early-settlement': always(apiError(409, 'quote_changed', 'The figure has changed since it was shown: money came in meanwhile. Here is the new one.')),
      });
      await pumpApp(tester, server);
      await openContract(tester);
      await tapButton(tester, 'Settle early');
      final fetched = server.calls('GET /contracts/k1/early-settlement');

      await tapButton(tester, 'Settle for SAR 825.00');

      expect(find.textContaining('money came in meanwhile'), findsOneWidget);
      expect(server.calls('GET /contracts/k1/early-settlement'), fetched + 1);
    });
  });

  group('changing the plan', () {
    testWidgets('previews the old and the new schedule, then confirms with the reason', (tester) async {
      final server = planServer();
      await pumpApp(tester, server);
      await openContract(tester);

      await tapButton(tester, 'Change the payment plan');
      await typeInto(tester, 'Number of instalments', '6');
      await tapButton(tester, 'Preview');

      expect(sent(server, 'POST /contracts/k1/amendments/preview'), {'type': 'reschedule', 'count': 6, 'first_due_date': '2026-12-07'});
      expect(find.text('Before and after'), findsOneWidget);
      expect(find.text('SAR 137.50'), findsNWidgets(6));

      await typeInto(tester, 'Why is the plan changing?', 'Lost his job');
      await tapButton(tester, 'Change the payment plan');

      expect(sent(server, 'POST /contracts/k1/amendments'), {'type': 'reschedule', 'count': 6, 'first_due_date': '2026-12-07', 'reason': 'Lost his job'});
    });

    testWidgets('will not confirm without a reason', (tester) async {
      final server = planServer();
      await pumpApp(tester, server);
      await openContract(tester);
      await tapButton(tester, 'Change the payment plan');
      await typeInto(tester, 'Number of instalments', '6');
      await tapButton(tester, 'Preview');

      await tapButton(tester, 'Change the payment plan');

      expect(find.text('Say why the plan is changing.'), findsOneWidget);
      expect(server.calls('POST /contracts/k1/amendments'), 0);
    });
  });

  group('who sees what', () {
    testWidgets('a collector takes payments but neither settles early nor changes the plan', (tester) async {
      await pumpApp(tester, planServer(account: planAccount(role: 'collector')));
      await openContract(tester);

      expect(find.text('Settle early'), findsNothing);
      expect(find.text('Change the payment plan'), findsNothing);
    });

    testWidgets('nothing is offered while the platform has the switches off', (tester) async {
      await pumpApp(tester, planServer(account: accountJson()));
      await openContract(tester);

      expect(find.text('Settle early'), findsNothing);
      expect(find.text('Change the payment plan'), findsNothing);
    });

    testWidgets('the contract says how far ahead it is paid, and keeps every plan change', (tester) async {
      await pumpApp(tester, planServer(routes: {
        'GET /contracts/k1': always(json(200, {
              'data': {
                ...contractJson(),
                'paid_ahead_until': '2026-12-07',
                'amendments': [
                  {
                    'id': 'a1', 'type': 'skip', 'reason': 'Eid', 'created_at': '2026-10-08T10:00:00Z', 'approved_by': {'id': 'u1', 'name': 'Layla Haddad'},
                    'old_schedule': [{'number': 2, 'due_date': '2026-12-07', 'amount': '275.00'}],
                    'new_schedule': [{'number': 5, 'due_date': '2027-01-07', 'amount': '275.00'}],
                  },
                ],
              },
            })),
      }));
      await openContract(tester);

      expect(find.textContaining('Paid ahead until'), findsOneWidget);
      await tester.scrollUntilVisible(find.text('Plan changes'), 300);
      expect(find.text('Eid'), findsOneWidget);
    });
  });

  group('payments', () {
    testWidgets('a payment can go to the instalment chosen first', (tester) async {
      final server = planServer(routes: {
        'POST /contracts/k1/payments': always(json(201, {'data': lineJson()})),
      });
      await pumpApp(tester, server);
      await openContract(tester);
      await tapText(tester, 'Record a payment', last: true);

      await tester.tap(find.byKey(const ValueKey('apply-to')));
      await settle(tester);
      // The last choice is the last instalment (i4).
      await tester.tap(find.byType(DropdownMenuItem<String?>).last);
      await settle(tester);
      await tapButton(tester, 'Record payment');

      expect(sent(server, 'POST /contracts/k1/payments')['apply_to'], 'i4');
    });

    testWidgets('one payment across a customer’s contracts, from their page', (tester) async {
      final second = {...contractJson(), 'id': 'k2', 'reference': 'C-0008', 'number': 8};
      final server = planServer(routes: {
        'GET /customers/c1': always(json(200, {'data': {...customerJson(), 'owed': '1650.00', 'running_contracts': 2, 'contracts': [contractJson(), second]}})),
        'POST /customers/c1/payments': always(json(201, {'data': {'id': 'b1', 'amount': '1650.00', 'method': 'cash', 'payments': [lineJson(), lineJson()]}})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Customers', last: true);
      await tapText(tester, 'Ahmad Salem');

      await tapButton(tester, 'Take a payment across contracts');
      await typeInto(tester, 'Amount (SAR)', '400');
      await tapButton(tester, 'Record payment');

      final request = server.requestsTo('POST /customers/c1/payments').single;
      expect(jsonDecode(request.data as String), {'amount': '400.00', 'method': 'cash'});
      expect(request.headers['Idempotency-Key'], isNotNull);
    });

    group('the amount it starts at', () {
      String day(int offset) {
        final d = DateTime.now().add(Duration(days: offset));
        return '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
      }

      Map<String, dynamic> running(String id, int number, String due, String remaining) =>
          {...contractJson(), 'id': id, 'reference': 'C-000$number', 'number': number, 'next_installment': {'number': 1, 'due_date': due, 'remaining': remaining}};

      Future<String> startingAmount(WidgetTester tester, List<Map<String, dynamic>> contracts) async {
        final server = planServer(routes: {
          'GET /customers/c1': always(json(200, {'data': {...customerJson(), 'owed': '2425.50', 'running_contracts': contracts.length, 'contracts': contracts}})),
        });
        await pumpApp(tester, server);
        await tapText(tester, 'Customers', last: true);
        await tapText(tester, 'Ahmad Salem');
        await tapButton(tester, 'Take a payment across contracts');

        final field = find.ancestor(of: find.text('Amount (SAR)'), matching: find.byType(Column)).first;

        return tester.widget<EditableText>(find.descendant(of: field, matching: find.byType(EditableText)).first).controller.text;
      }

      testWidgets('what is due by today across the running contracts, never the whole balance', (tester) async {
        final tab = {...contractJson(), 'id': 'k4', 'reference': 'C-0004', 'number': 4, 'type': 'open', 'owed': '900.00', 'next_installment': null};

        final amount = await startingAmount(tester, [running('k1', 1, day(-10), '275.00'), running('k2', 2, day(0), '125.50'), running('k3', 3, day(20), '300.00'), tab]);

        expect(amount, '400.50');
        // Still owed on what it can pay off: the three running contracts (825.00 each), not the open account's 900.00.
        expect(find.text('SAR 2,475.00'), findsOneWidget);
      });

      testWidgets('the soonest instalment when nothing is due yet', (tester) async {
        final amount = await startingAmount(tester, [running('k1', 1, day(30), '275.00'), running('k2', 2, day(12), '125.50')]);

        expect(amount, '125.50');
      });
    });
  });
}
