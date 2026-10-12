import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/features/billing/upgrade_sheet.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Reports in the app (Win Plan K8, RP14, RP15, CT27): the forecast, ageing and profit, the same figures as the
/// website's, narrowed by period; a Free workspace is offered Pro; collectors do not see them.

Map<String, dynamic> forecastJson() => {
      'as_of': '2026-10-12', 'already_due': '150.00', 'late_fees': '10.00',
      'windows': {'days_30': '600.00', 'days_60': '1200.00', 'days_90': '1800.00'},
      'weeks': [for (var w = 0; w < 13; w++) {'from': '2026-10-${(12 + w).clamp(12, 31)}', 'to': '2026-10-31', 'amount': w.isEven ? '150.00' : '0.00'}],
    };

Map<String, dynamic> ageingJson() => {
      'as_of': '2026-10-12',
      'buckets': {'current': '1500.00', 'days_1_30': '275.00', 'days_31_60': '0.00', 'days_61_90': '0.00', 'days_90_plus': '110.00'},
      'total': '1885.00',
      'contracts': [
        {'id': 'k1', 'reference': 'C-0007', 'customer': {'id': 'c1', 'name': 'Ahmad Salem'}, 'current': '550.00', 'days_1_30': '275.00', 'days_31_60': '0.00', 'days_61_90': '0.00', 'days_90_plus': '110.00', 'total': '935.00'},
      ],
    };

Map<String, dynamic> profitJson() => {
      'from': '2025-11-01', 'to': '2026-10-31', 'total': '425.00', 'collection_rate': '82.5',
      'months': [for (var m = 0; m < 12; m++) {'month': m < 2 ? '2025-${11 + m}' : '2026-${(m - 1).toString().padLeft(2, '0')}', 'profit': m == 11 ? '125.00' : '25.00'}],
      'investors': [
        {'id': 'v1', 'name': 'Own capital', 'profit': '300.00'},
        {'id': 'v2', 'name': 'Saeed', 'profit': '125.00'},
      ],
      'products': [
        {'id': null, 'name': 'Not from the products list', 'profit': '425.00'},
      ],
      'late_fees': {'shop': '20.00', 'charity': '5.00'},
    };

FakeServer reportsServer({Map<String, dynamic>? account, Map<String, Route> routes = const {}}) => workspaceServer(account: account, routes: {
      'GET /reports/forecast': always(json(200, {'data': forecastJson()})),
      'GET /reports/ageing': always(json(200, {'data': ageingJson()})),
      'GET /reports/profit': always(json(200, {'data': profitJson()})),
      ...routes,
    });

Future<void> openReports(WidgetTester tester) async {
  await openSettings(tester);
  await tester.ensureVisible(find.byKey(const ValueKey('settings-reports')));
  await tester.tap(find.byKey(const ValueKey('settings-reports')));
  await settle(tester);
}

void main() {
  testWidgets('shows the forecast, ageing and profit with their charts', (tester) async {
    await pumpApp(tester, reportsServer());
    await openReports(tester);

    expect(find.text('Expected in the next 30 days'), findsOneWidget);
    expect(find.text('SAR 600.00'), findsOneWidget);
    expect(find.byKey(const ValueKey('forecast-chart')), findsOneWidget);
    expect(find.textContaining('Already due and not paid: SAR 150.00.'), findsOneWidget);

    await tester.scrollUntilVisible(find.text('Over 90 days late'), 300);
    expect(find.text('SAR 110.00'), findsWidgets);
    expect(find.text('Ahmad Salem'), findsOneWidget);

    await tester.scrollUntilVisible(find.text('By product'), 300);
    expect(find.text('SAR 425.00'), findsWidgets);
    expect(find.text('82.5%'), findsOneWidget);
    expect(find.text('And SAR 5.00 for charity, kept apart'), findsOneWidget);
    expect(find.text('Saeed'), findsOneWidget);
    expect(find.byKey(const ValueKey('profit-chart')), findsOneWidget);
  });

  testWidgets('a period chosen asks every report for it', (tester) async {
    final server = reportsServer();
    await pumpApp(tester, server);
    await openReports(tester);

    await tapText(tester, 'This month');

    for (final report in ['forecast', 'ageing', 'profit']) {
      expect(server.requestsTo('GET /reports/$report').last.queryParameters['period'], 'this_month');
    }
  });

  testWidgets('a late contract opens from the ageing list', (tester) async {
    await pumpApp(tester, reportsServer());
    await openReports(tester);

    await tester.scrollUntilVisible(find.text('Ahmad Salem'), 300);
    await tapText(tester, 'Ahmad Salem');

    // The contract's own page, not the list: it offers to take a payment.
    expect(find.text('Record a payment'), findsWidgets);
  });

  testWidgets('a Free workspace is offered Pro', (tester) async {
    final locked = always(apiError(402, 'feature_locked', 'Reports are part of Pro.', extra: {'feature': 'advanced_reports'}));
    await pumpApp(tester, reportsServer(routes: {'GET /reports/forecast': locked, 'GET /reports/ageing': locked, 'GET /reports/profit': locked}));
    await openReports(tester);

    expect(find.byType(UpgradeSheet), findsOneWidget);
    await tester.tapAt(const Offset(10, 10));
    await settle(tester);
    expect(find.text('Reports come with Pro'), findsOneWidget);
  });

  testWidgets('collectors do not see them', (tester) async {
    await pumpApp(tester, reportsServer(account: accountJson(role: 'collector')));
    await openSettings(tester);

    expect(find.byKey(const ValueKey('settings-reports')), findsNothing);
  });

  testWidgets('the dashboard leads to them', (tester) async {
    await pumpApp(tester, reportsServer());

    await tester.scrollUntilVisible(find.byKey(const ValueKey('dashboard-reports')), 300);
    await tester.tap(find.byKey(const ValueKey('dashboard-reports')));
    await settle(tester);

    expect(find.text('Expected in the next 30 days'), findsOneWidget);
  });
}
