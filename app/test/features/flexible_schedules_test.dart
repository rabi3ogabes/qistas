import 'dart:convert';

import 'package:flutter/widgets.dart' show ValueKey;
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan PP5: daily to yearly plans, the shop's own dates, up to 600 instalments and grace days, when the platform
/// has flexible schedules on. Without it the form is exactly as before.
void main() {
  Map<String, dynamic> flexibleAccount() => flexibleAccountJson();

  Future<FakeServer> openForm(WidgetTester tester, {Map<String, dynamic>? account}) async {
    final server = workspaceServer(account: account, routes: {'POST /contracts': always(json(201, {'data': contractJson()}))});
    await pumpApp(tester, server);
    await tapText(tester, 'Contracts', last: true);
    await tapText(tester, 'New contract');
    await tapText(tester, 'Choose a customer');
    await tapText(tester, 'Ahmad Salem');

    return server;
  }

  Map<String, dynamic> sent(FakeServer server) => jsonDecode(server.requestsTo('POST /contracts').single.data as String) as Map<String, dynamic>;

  testWidgets('without the switch, the form offers weekly, two-weekly and monthly plans only, as before', (tester) async {
    await openForm(tester);

    expect(find.text('Month'), findsOneWidget);
    expect(find.text('Three months'), findsNothing);
    expect(find.text('My own dates'), findsNothing);
    expect(find.text('Grace days'), findsNothing);
  });

  testWidgets('sends a quarterly plan with grace days, and says when the last payment falls', (tester) async {
    final server = await openForm(tester, account: flexibleAccount());

    await typeInto(tester, 'Sale price (SAR)', '1200');
    await tapText(tester, 'Three months');
    for (var i = 0; i < 3; i++) {
      await tapTooltip(tester, 'More grace days');
    }

    expect(find.text('Last payment'), findsOneWidget);
    await tapButton(tester, 'Open contract');

    final body = sent(server);
    expect(body['frequency'], 'quarterly');
    expect(body['installment_count'], 6);
    expect(body['grace_days'], 3);
  });

  testWidgets('the shop’s own dates start from the plan on screen and say how much is left to place', (tester) async {
    await openForm(tester, account: flexibleAccount());

    await typeInto(tester, 'Sale price (SAR)', '600');
    await tapText(tester, 'My own dates');

    expect(find.byKey(const ValueKey('custom-amount-5')), findsOneWidget);
    expect(find.text('Number of instalments'), findsNothing);

    await tapTooltip(tester, 'Remove this date');

    expect(find.byKey(const ValueKey('custom-amount-5')), findsNothing);
    expect(find.text('Still to place: SAR 100.00'), findsOneWidget);
  });

  testWidgets('sends the shop’s own dates as rows once they add up to the total', (tester) async {
    final server = await openForm(tester, account: flexibleAccount());

    await typeInto(tester, 'Sale price (SAR)', '600');
    await tapText(tester, 'My own dates');
    await tapTooltip(tester, 'Remove this date');
    await typeIntoKey(tester, 'custom-amount-0', '200');

    expect(find.text('The dates add up to the total.'), findsOneWidget);
    await tapButton(tester, 'Open contract');

    final body = sent(server);
    final rows = (body['custom_schedule'] as List<dynamic>).cast<Map<String, dynamic>>();
    expect(body['frequency'], 'custom');
    expect(body.containsKey('installment_count'), isFalse);
    expect(body.containsKey('first_due_date'), isFalse);
    expect(rows, hasLength(5));
    expect(rows.first['amount'], '200.00');
    expect(rows.last['amount'], '100.00');
  });

  testWidgets('will not send the shop’s own dates while they do not add up', (tester) async {
    final server = await openForm(tester, account: flexibleAccount());

    await typeInto(tester, 'Sale price (SAR)', '600');
    await tapText(tester, 'My own dates');
    await tapTooltip(tester, 'Remove this date');
    await tapButton(tester, 'Open contract');

    expect(server.calls('POST /contracts'), 0);
    expect(find.text('Still to place: SAR 100.00'), findsOneWidget);
  });

  testWidgets('a long plan can be typed in, up to 600', (tester) async {
    final server = await openForm(tester, account: flexibleAccount());

    await typeInto(tester, 'Sale price (SAR)', '60000');
    await tapTooltip(tester, 'Type the number of instalments');
    await typeIntoKey(tester, 'count-input', '600');
    await tapText(tester, 'Done');
    await tapButton(tester, 'Open contract');

    expect(sent(server)['installment_count'], 600);
  });
}
