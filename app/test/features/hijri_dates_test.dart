import 'dart:convert';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/l10n/hijri.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Hijri dates in the app (Win Plan CT26): every date shown as the workspace asks, a day picked in Hijri when it is on,
/// and the day sent to the server always Gregorian.

Map<String, dynamic> accountWith(String hijri) {
  final account = accountJson();
  account['tenant'] = {...Map<String, dynamic>.from(account['tenant'] as Map), 'hijri_dates': hijri};

  return account;
}

String hijriOf(DateTime day) => HijriDate.fromGregorian(day)!.format('en');

Future<void> openContract(WidgetTester tester) async {
  await tapText(tester, 'Contracts', last: true);
  await tapText(tester, 'C-0007');
}

void main() {
  tearDown(() => HijriDisplay.mode = 'off');

  testWidgets('a contract shows each date in the Gregorian calendar alone while it is off', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWith('off')));
    await openContract(tester);

    expect(find.textContaining(' AH'), findsNothing);
  });

  testWidgets('a contract shows the Hijri day beside each date when the workspace asks', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWith('beside')));
    await openContract(tester);

    // contractJson(): the next instalment is due on 7 December 2026.
    expect(find.textContaining('(${hijriOf(DateTime(2026, 12, 7))})'), findsWidgets);
  });

  testWidgets('a payment dated in Hijri is sent as its Gregorian day', (tester) async {
    final server = workspaceServer(account: accountWith('first'), routes: {
      'POST /contracts/k1/payments': always(json(201, {'data': lineJson()})),
    });
    await pumpApp(tester, server);
    await openContract(tester);
    await tapText(tester, 'Record a payment', last: true);
    await tester.tap(find.byIcon(Icons.calendar_today_outlined));
    await settle(tester);

    // The workspace leads with Hijri, so the picker opens on it: choose the 1st of this Hijri month.
    final now = HijriDate.fromGregorian(DateTime.now())!;
    final first = HijriDate.gregorian(now.year, now.month, 1)!;
    // The menu scrolls to the day chosen, so the 1st may not be built: choose it the way the menu does.
    final dayField = find.descendant(of: find.byKey(const ValueKey('hijri-day')), matching: find.byType(DropdownButtonFormField<int>));
    tester.widget<DropdownButtonFormField<int>>(dayField).onChanged!(1);
    await settle(tester);
    expect(tester.widget<Text>(find.byKey(const ValueKey('day-other'))).data, isNot(contains('AH')));
    await tapButton(tester, 'Choose');
    await tapButton(tester, 'Record payment');

    final sent = jsonDecode(server.requestsTo('POST /contracts/k1/payments').single.data as String) as Map<String, dynamic>;
    final day = '${first.year.toString().padLeft(4, '0')}-${first.month.toString().padLeft(2, '0')}-${first.day.toString().padLeft(2, '0')}';
    expect(sent['paid_at'], day);
  });

  testWidgets('with Hijri dates off, the phone’s own date picker opens as before', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountWith('off')));
    await openContract(tester);
    await tapText(tester, 'Record a payment', last: true);
    await tester.tap(find.byIcon(Icons.calendar_today_outlined));
    await settle(tester);

    expect(find.byType(DatePickerDialog), findsOneWidget);
    expect(find.byKey(const ValueKey('hijri-day')), findsNothing);
  });

  testWidgets('choosing how dates are shown reads the account again, so every date follows', (tester) async {
    Map<String, dynamic> tool(String value) => {
          'key': 'dates.hijri', 'feature': 'hijri_dates', 'type': 'select', 'label': 'Hijri dates', 'help': '', 'value': value,
          'options': [
            {'value': 'off', 'label': 'Gregorian only'},
            {'value': 'beside', 'label': 'Hijri beside the Gregorian date'},
            {'value': 'first', 'label': 'Hijri first'},
          ],
        };
    final server = workspaceServer(routes: {
      'GET /settings/tools': always(json(200, {'data': [tool('off')], 'meta': {'can_edit': true}})),
      'PUT /settings/tools/dates.hijri': always(json(200, {'data': tool('first')})),
    });
    await pumpApp(tester, server);
    await openSettings(tester);
    await tapText(tester, 'Instalment tools');
    final before = server.requestsTo('GET /me').length;

    await tapText(tester, 'Hijri first');

    expect(jsonDecode(server.requestsTo('PUT /settings/tools/dates.hijri').single.data as String), {'value': 'first'});
    expect(server.requestsTo('GET /me').length, greaterThan(before));
  });
}
