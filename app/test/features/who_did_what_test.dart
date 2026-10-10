import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Who did what (Win Plan PP16): who recorded each payment and who voided it and why, what a payment will cover before
/// it is taken, and the phones signed in to the account.

Map<String, dynamic> devicesJson() => {
      'data': [
        {'id': 1, 'name': 'Qistas app (android)', 'current': true, 'last_used_at': '2026-10-10T09:00:00Z', 'last_used_ip': '203.0.113.7', 'signed_in_at': '2026-10-01T09:00:00Z'},
        {'id': 2, 'name': 'Qistas app (iOS)', 'current': false, 'last_used_at': '2026-09-20T18:30:00Z', 'last_used_ip': '198.51.100.4', 'signed_in_at': '2026-09-01T09:00:00Z'},
      ],
    };

void main() {
  testWidgets('a payment names who recorded it, and a voided one who voided it and why', (tester) async {
    final contract = contractJson();
    contract['transactions'] = [
      {
        ...lineJson(voided: true),
        'recorded_by': {'id': 'u2', 'name': 'Sara Hamdan'},
        'recorded_at': '2026-10-07T11:32:00Z',
        'reversal': {'id': 't9', 'by': {'id': 'u1', 'name': 'Layla Haddad'}, 'reason': 'Entered twice', 'at': '2026-10-08T09:00:00Z'},
      },
    ];
    await pumpApp(tester, workspaceServer(routes: {'GET /contracts/k1': always(json(200, {'data': contract}))}));
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'C-0007');

    expect(find.textContaining('Recorded by Sara Hamdan'), findsOneWidget);
    expect(find.textContaining('Voided by Layla Haddad'), findsOneWidget);
    expect(find.textContaining('Entered twice'), findsOneWidget);
  });

  testWidgets('the payment sheet says what the amount covers before it is taken', (tester) async {
    await pumpApp(tester, workspaceServer());
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'C-0007');
    await tapButton(tester, 'Record a payment');

    final field = find.descendant(of: find.byType(BottomSheet), matching: find.byType(EditableText)).first;
    await tester.enterText(field, '300');
    await tester.pump();

    expect(find.text('#2 in full'), findsOneWidget);
    expect(find.text('#3: SAR 25.00 of SAR 275.00'), findsOneWidget);
    expect(find.text('Still owed after: SAR 525.00'), findsOneWidget);
  });

  testWidgets('lists the phones signed in, marks this one, and signs another out', (tester) async {
    final server = workspaceServer(routes: {
      'GET /devices': always(json(200, devicesJson())),
      'DELETE /devices/2': always(FakeResponse(204, '')),
    });
    await pumpApp(tester, server);
    await openSettings(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('settings-devices')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('settings-devices')));
    await settle(tester);

    expect(find.text('Qistas app (android)'), findsOneWidget);
    expect(find.text('This device'), findsOneWidget);
    expect(find.textContaining('198.51.100.4'), findsOneWidget);

    await tapTooltip(tester, 'Sign out Qistas app (iOS)');
    await tapText(tester, 'Sign out', last: true);

    expect(server.calls('DELETE /devices/2'), 1);
    expect(server.calls('DELETE /devices/1'), 0);
  });
}
