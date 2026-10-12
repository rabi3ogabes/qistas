import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/l10n/formats.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/domain/schedule_generator.dart';

import '../support/harness.dart';

/// Win Plan PP15 in the app: the calculator under the + button. With a customer in front of them, the shop types the
/// price, the down payment, the profit and the months, and sees each instalment and the last date at once, worked out by
/// the same engine as the contract form; "Create a contract from this" carries the numbers into a new contract.

String day(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

DateTime nextMonth() {
  final now = DateTime.now();
  final lastOfNext = DateTime(now.year, now.month + 2, 0).day;

  return DateTime(now.year, now.month + 1, now.day > lastOfNext ? lastOfNext : now.day);
}

Future<void> openCalculator(WidgetTester tester) async {
  await tapTooltip(tester, 'Quick actions');
  await tapText(tester, 'Calculator');
}

Future<void> typePlan(WidgetTester tester) async {
  await typeIntoKey(tester, 'calc-price', '5000');
  await typeIntoKey(tester, 'calc-down', '1000');
  await typeIntoKey(tester, 'calc-markup', '15');
  await typeIntoKey(tester, 'calc-count', '12');
  await settle(tester);
}

void main() {
  testWidgets('shows each instalment, the total and the last date, as the contract engine works them out', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openCalculator(tester);
    await typePlan(tester);

    final expected = ScheduleGenerator().generate(ScheduleRequest(
      principal: '5000.00', downPayment: '1000.00', markupType: 'percent', markupValue: '15.00', count: 12, frequency: 'monthly', firstDueDate: day(nextMonth()),
    ));

    expect(find.text(expected.installments.first.amount.format('SAR')), findsWidgets);
    expect(find.text(expected.total.format('SAR')), findsWidgets);
    expect(find.text(formatDay(expected.installments.last.dueDate, 'en')), findsOneWidget);
    expect(expected.total, Money.parse('4600.00'));
  });

  testWidgets('keeps the total, the profit and the last date apart on a phone, however long the amounts', (tester) async {
    await pumpApp(tester, workspaceServer(), size: const Size(390, 800));
    await openCalculator(tester);
    await typeIntoKey(tester, 'calc-price', '3600');
    await typeIntoKey(tester, 'calc-down', '600');
    await typeIntoKey(tester, 'calc-markup', '10');
    await settle(tester);

    final total = tester.getRect(find.text('SAR 3,300.00').first);
    final profit = tester.getRect(find.text('SAR 300.00').first);
    expect(profit.left - total.right, greaterThanOrEqualTo(8));
  });

  testWidgets('says what is wrong instead of showing a plan that cannot be', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openCalculator(tester);

    await typeIntoKey(tester, 'calc-price', '500');
    await typeIntoKey(tester, 'calc-down', '600');
    await settle(tester);

    expect(find.text('The down payment must be less than the price.'), findsOneWidget);
    expect(find.text('Create a contract from this'), findsNothing);
  });

  testWidgets('carries the plan into a new contract, whose schedule is the same', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openCalculator(tester);
    await typePlan(tester);
    final each = ScheduleGenerator().generate(ScheduleRequest(
      principal: '5000.00', downPayment: '1000.00', markupType: 'percent', markupValue: '15.00', count: 12, frequency: 'monthly', firstDueDate: day(nextMonth()),
    )).installments.first.amount.format('SAR');

    await tapButton(tester, 'Create a contract from this');

    expect(find.text('New contract'), findsWidgets);
    expect(find.widgetWithText(TextField, '5000.00'), findsOneWidget);
    expect(find.widgetWithText(TextField, '1000.00'), findsOneWidget);
    expect(find.text(each), findsWidgets, reason: 'the contract form previews the same instalment');
  });
}
