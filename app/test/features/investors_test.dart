import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Investors (Win Plan PP3): who funds the business, each one's money and profit as customers pay, money put in and
/// taken out, and who funds a new contract. Collectors never see them.

Future<void> openInvestors(WidgetTester tester) async {
  await openSettings(tester);
  await tester.ensureVisible(find.byKey(const ValueKey('settings-investors')));
  await tester.tap(find.byKey(const ValueKey('settings-investors')));
  await settle(tester);
}

void main() {
  testWidgets('lists each investor with the money in their wallet and what they have earned', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWithInvestors(), routes: {'GET /investors': always(json(200, {'data': investorsJson()}))}));
    await openInvestors(tester);

    expect(find.text('Own capital'), findsOneWidget);
    expect(find.text('SAR 9,580.00'), findsOneWidget);
    expect(find.text('SAR 80.00'), findsOneWidget);
  });

  testWidgets('keeps investors out of a collector’s settings', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWithInvestors(role: 'collector')));
    await openSettings(tester);

    expect(find.byKey(const ValueKey('settings-investors')), findsNothing);
  });

  testWidgets('shows an investor’s figures, profit by month, contracts and money in and out', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWithInvestors(), routes: {
      'GET /investors': always(json(200, {'data': investorsJson()})),
      'GET /investors/i1': always(json(200, {'data': investorDetailJson()})),
    }));
    await openInvestors(tester);
    await tapText(tester, 'Own capital');

    expect(find.text('Profit, month by month'), findsOneWidget);
    expect(find.text('C-0007'), findsWidgets);
    expect(find.text('Ahmad Salem'), findsOneWidget);
    expect(find.text('Opening cash'), findsOneWidget);
    expect(find.text('Profit from a payment'), findsOneWidget);
  });

  testWidgets('records money put in from the sheet', (tester) async {
    final server = workspaceServer(account: accountWithInvestors(), routes: {
      'GET /investors': always(json(200, {'data': investorsJson()})),
      'GET /investors/i1': always(json(200, {'data': investorDetailJson()})),
      'POST /investors/i1/entries': always(json(201, {'data': {'id': 'e3', 'type': 'deposit', 'amount': '5000.00', 'occurred_on': '2026-10-10', 'reversed': false, 'reversible': true}})),
    });
    await pumpApp(tester, server);
    await openInvestors(tester);
    await tapText(tester, 'Own capital');

    await tapButton(tester, 'Record money in or out');
    await typeInto(tester, 'Amount', '5,000');
    await typeInto(tester, 'Note (optional)', 'From savings');
    await tapButton(tester, 'Record');

    final body = jsonDecode(server.requestsTo('POST /investors/i1/entries').single.data as String) as Map<String, dynamic>;
    expect(body['type'], 'deposit');
    expect(body['amount'], '5000.00');
    expect(body['note'], 'From savings');
  });

  testWidgets('reverses a mistaken deposit only after asking', (tester) async {
    final server = workspaceServer(account: accountWithInvestors(), routes: {
      'GET /investors': always(json(200, {'data': investorsJson()})),
      'GET /investors/i1': always(json(200, {'data': investorDetailJson()})),
      'POST /investor-entries/e1/reverse': always(json(201, {'data': {'id': 'e4', 'type': 'deposit', 'amount': '-10000.00', 'occurred_on': '2026-10-10', 'reverses_entry_id': 'e1', 'reversed': false, 'reversible': false}})),
    });
    await pumpApp(tester, server);
    await openInvestors(tester);
    await tapText(tester, 'Own capital');

    await tapText(tester, 'Opening cash');
    expect(server.calls('POST /investor-entries/e1/reverse'), 0);
    await tapText(tester, 'Reverse this entry', last: true);

    expect(server.calls('POST /investor-entries/e1/reverse'), 1);
  });

  testWidgets('on the Free plan, adding a partner offers the upgrade instead', (tester) async {
    final server = workspaceServer(account: accountWithInvestors(), routes: {'GET /investors': always(json(200, {'data': investorsJson()}))});
    await pumpApp(tester, server);
    await openInvestors(tester);

    await tapTooltip(tester, 'Add an investor');

    expect(find.text('You have reached your plan limit'), findsOneWidget);
    expect(server.calls('POST /investors'), 0);
  });

  testWidgets('adds a partner with the money they start with', (tester) async {
    final server = workspaceServer(account: accountWithInvestors(plan: 'pro'), routes: {
      'GET /investors': always(json(200, {'data': investorsJson(limit: null)})),
      'POST /investors': always(json(201, {'data': investorJson(id: 'i2', name: 'Khalid Al-Harbi', main: false)})),
      'GET /investors/i2': always(json(200, {'data': {...investorDetailJson(), ...investorJson(id: 'i2', name: 'Khalid Al-Harbi', main: false)}})),
    });
    await pumpApp(tester, server);
    await openInvestors(tester);

    await tapTooltip(tester, 'Add an investor');
    await typeInto(tester, 'Name', 'Khalid Al-Harbi');
    await typeInto(tester, 'Money they start with (optional)', '20000');
    await typeInto(tester, 'Commission (optional)', '15');
    await tapButton(tester, 'Add investor');

    final body = jsonDecode(server.requestsTo('POST /investors').single.data as String) as Map<String, dynamic>;
    expect(body, {'name': 'Khalid Al-Harbi', 'opening_capital': '20000.00', 'commission_percent': '15'});
  });

  testWidgets('asks who funds a new contract once there are partners, and sends the choice', (tester) async {
    final server = workspaceServer(account: accountWithInvestors(plan: 'pro'), routes: {
      'GET /investors': always(json(200, {'data': investorsJson(partner: true, limit: null)})),
      'POST /contracts': always(json(201, {'data': contractJson()})),
    });
    await pumpApp(tester, server);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'New contract');
    await tapText(tester, 'Choose a customer');
    await tapText(tester, 'Ahmad Salem');
    await typeInto(tester, 'Sale price (SAR)', '1200');

    await tapText(tester, 'Own capital');
    await tapText(tester, 'Khalid Al-Harbi', last: true);
    await tapButton(tester, 'Open contract');

    final body = jsonDecode(server.requestsTo('POST /contracts').single.data as String) as Map<String, dynamic>;
    expect(body['investor_id'], 'i2');
  });

  testWidgets('does not ask who funds a contract while the business has only its own capital', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWithInvestors(), routes: {'GET /investors': always(json(200, {'data': investorsJson()}))}));
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'New contract');

    expect(find.text('Funded by'), findsNothing);
  });
}
