import 'dart:convert';

import 'package:flutter/widgets.dart' show ValueKey;
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Open contracts (Win Plan PP4): a running tab with "they took" and "they paid", the balance after every line, and
/// turning a scheduled contract into an open one after seeing exactly what happens.

Map<String, dynamic> accountWithOpenContracts({String role = 'owner'}) {
  final account = accountJson(role: role);
  account['entitlements'] = {
    ...Map<String, dynamic>.from(account['entitlements'] as Map),
    'open_contracts': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
  };

  return account;
}

Map<String, dynamic> tabLine(String id, String type, String amount, String after, {String? tag, String day = '2026-10-05'}) => {
      'id': id, 'type': type, 'method': type == 'charge' ? 'other' : 'cash', 'amount': amount, 'paid_at': '${day}T10:00:00Z',
      'note': null, 'tag': tag, 'voided': false, 'reverses_transaction_id': null, 'balance_after': after, 'created_by': {'id': 'u1', 'name': 'Layla Haddad'},
    };

Map<String, dynamic> openContractJson({String? creditLimit = '500.00'}) => {
      ...contractJson(),
      'type': 'open', 'principal': '0.00', 'down_payment': '0.00', 'financed': '0.00', 'markup_type': 'none', 'markup_value': '0.00',
      'markup_amount': '0.00', 'total': '0.00', 'installment_count': 0, 'credit_limit': creditLimit, 'owed': '225.50', 'paid': '0.00',
      'next_installment': null, 'installments': <Object?>[],
      'transactions': [
        tabLine('t3', 'charge', '45.50', '225.50', tag: 'unpaid', day: '2026-10-06'),
        tabLine('t2', 'payment', '120.00', '180.00', day: '2026-10-04'),
        tabLine('t1', 'charge', '300.00', '300.00', day: '2026-10-01'),
      ],
    };

Future<FakeServer> openTab(WidgetTester tester, {Map<String, Route> routes = const {}, String role = 'owner'}) async {
  final server = workspaceServer(account: accountWithOpenContracts(role: role), routes: {
    'GET /contracts/k1': always(json(200, {'data': openContractJson()})),
    ...routes,
  });
  await pumpApp(tester, server);
  await tapText(tester, 'Contracts', last: true);
  await tapText(tester, 'C-0007');

  return server;
}

Map<String, dynamic> body(FakeServer server, String route) => jsonDecode(server.requestsTo(route).single.data as String) as Map<String, dynamic>;

void main() {
  testWidgets('opens an open account from the form: no price, what they owe today and a credit limit', (tester) async {
    final server = workspaceServer(account: accountWithOpenContracts(), routes: {'POST /contracts': always(json(201, {'data': openContractJson()}))});
    await pumpApp(tester, server);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'New contract');
    await tapText(tester, 'Choose a customer');
    await tapText(tester, 'Ahmad Salem');

    await tapText(tester, 'Open account');
    expect(find.text('Sale price (SAR)'), findsNothing);
    await typeInto(tester, 'What they owe today (optional)', '90');
    await typeInto(tester, 'Credit limit (optional)', '500');
    await tapButton(tester, 'Open contract');

    final sent = body(server, 'POST /contracts');
    expect(sent['type'], 'open');
    expect(sent['opening_balance'], '90.00');
    expect(sent['credit_limit'], '500.00');
    expect(sent.containsKey('principal'), isFalse);
  });

  testWidgets('shows the balance, the two ways in and out, and the balance after every line', (tester) async {
    await openTab(tester);

    expect(find.text('SAR 225.50'), findsWidgets);
    expect(find.text('They took'), findsWidgets);
    expect(find.text('They paid'), findsWidgets);
    expect(find.text('Balance SAR 180.00'), findsOneWidget);
    expect(find.text('Unpaid'), findsOneWidget);
  });

  testWidgets('adds what they took, with a tag, safe to retry', (tester) async {
    final server = await openTab(tester, routes: {
      'POST /contracts/k1/charges': always(json(201, {'data': tabLine('t4', 'charge', '45.50', '271.00', tag: 'unpaid'), 'meta': {'balance': '271.00', 'over_credit_limit': false}})),
    });

    await tapButton(tester, 'They took');
    await typeIntoKey(tester, 'line-amount', '45.50');
    await tapText(tester, 'Unpaid', last: true);
    await tapButton(tester, 'Add to their balance');

    final request = server.requestsTo('POST /contracts/k1/charges').single;
    final sent = jsonDecode(request.data as String) as Map<String, dynamic>;
    expect(sent['amount'], '45.50');
    expect(sent['tag'], 'unpaid');
    expect(request.headers['Idempotency-Key'], isNotNull);
  });

  testWidgets('says so when the balance passes the credit limit, and still records it', (tester) async {
    final server = await openTab(tester, routes: {
      'POST /contracts/k1/charges': always(json(201, {'data': tabLine('t4', 'charge', '400.00', '625.50'), 'meta': {'balance': '625.50', 'over_credit_limit': true}})),
    });

    await tapButton(tester, 'They took');
    await typeIntoKey(tester, 'line-amount', '400');
    await tapButton(tester, 'Add to their balance');

    expect(server.calls('POST /contracts/k1/charges'), 1);
    expect(find.textContaining('past the credit limit'), findsOneWidget);
  });

  testWidgets('takes what they paid off the balance', (tester) async {
    final server = await openTab(tester, routes: {
      'POST /contracts/k1/payments': always(json(201, {'data': tabLine('t4', 'payment', '100.00', '125.50')})),
    });

    await tapButton(tester, 'They paid');
    await typeIntoKey(tester, 'line-amount', '100');
    await tapButton(tester, 'Take off their balance');

    final sent = body(server, 'POST /contracts/k1/payments');
    expect(sent['amount'], '100.00');
    expect(sent['method'], 'cash');
  });

  testWidgets('turns a scheduled contract into an open one only after showing exactly what happens', (tester) async {
    final server = workspaceServer(account: accountWithOpenContracts(), routes: {
      'POST /contracts/k1/convert-to-open': (request) => request.uri.queryParameters['preview'] == '1'
          ? json(200, {'data': {'superseded': 3, 'opening_balance': '825.00'}})
          : json(200, {'data': {'conversion': {'superseded': 3, 'opening_balance': '825.00'}, 'contract': openContractJson()}}),
    });
    await pumpApp(tester, server);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'C-0007');

    await tapButton(tester, 'Turn into an open contract');
    expect(find.text('Opening balance of the open contract: SAR 825.00'), findsOneWidget);
    expect(server.requestsTo('POST /contracts/k1/convert-to-open').single.uri.queryParameters['preview'], '1');

    await tapButton(tester, 'Make it open');
    expect(server.requestsTo('POST /contracts/k1/convert-to-open').last.uri.queryParameters['preview'], isNull);
  });

  testWidgets('does not offer a collector to turn a contract into an open one', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWithOpenContracts(role: 'collector')));
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'C-0007');

    expect(find.text('Turn into an open contract'), findsNothing);
    expect(find.byKey(const ValueKey('convert-to-open')), findsNothing);
  });
}
