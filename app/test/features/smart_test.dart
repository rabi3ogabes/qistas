import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/app/shell.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:qistas/data/models.dart';
import 'package:qistas/features/reminders/reminders.dart';
import 'package:qistas/features/search/search_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

Future<void> tapFinder(WidgetTester tester, Finder finder) async {
  expect(finder, findsWidgets);
  await tester.ensureVisible(finder.first);
  await tester.tap(finder.first);
  await settle(tester);
}

/// Today's page of the dashboard, with the lists replaced.
Map<String, dynamic> board({List<Map<String, dynamic>>? late, List<Map<String, dynamic>>? today, List<Map<String, dynamic>>? upcoming}) => {
      ...dashboardJson(),
      'overdue_list': late ?? <Map<String, dynamic>>[],
      'due_today': today ?? <Map<String, dynamic>>[],
      'upcoming': upcoming ?? <Map<String, dynamic>>[],
    };

void main() {
  group('the numbers the app works out', () {
    test('a phone number becomes what WhatsApp wants, with the business’s own country code when written locally', () {
      expect(whatsappNumber('+966 50 123 4567', 'SA'), '966501234567');
      expect(whatsappNumber('0501234567', 'SA'), '966501234567');
      expect(whatsappNumber('00966501234567', 'SA'), '966501234567');
      expect(whatsappNumber('0501234567', 'ZZ'), '0501234567');
      expect(whatsappNumber('12345', 'SA'), isNull);
    });

    test('the dashboard puts what is late before what is due today, and says how far behind last month it is', () {
      final data = Dashboard.fromJson(dashboardJson());

      expect(data.needsYou.map((i) => i.customerName), ['Youssef Mansour', 'Ahmad Salem']);
      expect(data.collectionFraction, closeTo(0.625, 1e-9));
      expect(data.beatLastMonth, isFalse);
      expect(data.toMatchLastMonth.cents, BigInt.from(30000));
    });

    test('a month that has taken as much as the last one is ahead, with nothing left to match', () {
      final data = Dashboard.fromJson({...dashboardJson(), 'collected_this_month': '1500.00'});

      expect(data.beatLastMonth, isTrue);
      expect(data.toMatchLastMonth.isZero, isTrue);
    });

    test('with no instalments due this month there is no collection rate to show', () {
      final data = Dashboard.fromJson({...dashboardJson(), 'collection_rate': null});

      expect(data.collectionFraction, isNull);
    });

    test('a contract knows how much of it has been paid', () {
      expect(Contract.fromJson(contractJson()).paidFraction, closeTo(0.25, 1e-9));
    });

    test('search remembers the last six things opened, newest first, once each, and keeps them for next time', () async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      ProviderContainer open() => ProviderContainer(overrides: [sharedPreferencesProvider.overrideWithValue(prefs)]);

      final first = open();
      addTearDown(first.dispose);
      final recents = first.read(recentSearchesProvider.notifier);
      for (var i = 0; i < 8; i++) {
        await recents.remember(RecentItem(kind: 'customer', id: '$i', title: 'Name $i', subtitle: ''));
      }
      await recents.remember(const RecentItem(kind: 'customer', id: '5', title: 'Name 5', subtitle: ''));

      expect(first.read(recentSearchesProvider).map((e) => e.id), ['5', '7', '6', '4', '3', '2']);

      final next = open();
      addTearDown(next.dispose);
      expect(next.read(recentSearchesProvider).map((e) => e.id), ['5', '7', '6', '4', '3', '2']);
    });
  });

  group('the dashboard briefing', () {
    testWidgets('says what needs the owner today, with the latest first', (tester) async {
      await pumpApp(tester, workspaceServer());

      expect(find.text('2 instalments need you today'), findsOneWidget);
      expect(find.text('6 days late'), findsOneWidget);
      expect(find.text('Due today'), findsOneWidget);
      expect(tester.getTopLeft(find.text('Youssef Mansour')).dy, lessThan(tester.getTopLeft(find.text('Ahmad Salem')).dy));
      expect(find.text('In 2 days'), findsOneWidget);
      expect(find.text('63% of this month’s instalments are paid'), findsOneWidget);
    });

    testWidgets('says so when there is nothing to do', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /dashboard': always(json(200, {'data': board()}))}));

      expect(find.text('You are all caught up.'), findsOneWidget);
      expect(find.text('Needs you'), findsNothing);
    });

    testWidgets('tells a quiet week from a busy one, and one instalment from several', (tester) async {
      final one = [dueJson(contract: 'k3', reference: 'C-0002', name: 'Noura', due: '2026-10-09', until: 1)];
      await pumpApp(tester, workspaceServer(routes: {'GET /dashboard': always(json(200, {'data': board(upcoming: one)}))}));
      expect(find.text('Nothing is late. 1 instalment falls due this week.'), findsOneWidget);
      expect(find.text('Tomorrow'), findsOneWidget);

      final two = [...one, dueJson(contract: 'k4', reference: 'C-0001', name: 'Samir', due: '2026-10-10', until: 2)];
      await pumpApp(tester, workspaceServer(routes: {'GET /dashboard': always(json(200, {'data': board(upcoming: two)}))}));
      expect(find.text('Nothing is late. 2 instalments fall due this week.'), findsOneWidget);
    });

    testWidgets('shows a new business how to begin', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountJson(customersUsed: 0)));

      expect(find.text('Get started in three steps'), findsOneWidget);
    });

    testWidgets('shows an established business on the Free plan how far it has come', (tester) async {
      await pumpApp(tester, workspaceServer());

      expect(find.text('Get started in three steps'), findsNothing);
      expect(find.text('Your plan'), findsOneWidget);
      expect(find.text('Upgrade your plan'), findsOneWidget);
    });

    testWidgets('does not sell a plan to a business that is already on Pro', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountJson(plan: 'pro')));

      expect(find.text('Your plan'), findsNothing);
    });
  });

  group('the bar along the bottom', () {
    testWidgets('has four sections and no More: settings open from the initials', (tester) async {
      await pumpApp(tester, workspaceServer());

      for (final section in ['Dashboard', 'Customers', 'Contracts', 'Payments']) {
        expect(find.text(section), findsWidgets, reason: section);
      }
      expect(find.text('More'), findsNothing);

      await openSettings(tester);
      expect(find.text('Sign out'), findsWidgets);
      expect(find.text('Language'), findsOneWidget);
    });

    testWidgets('the gold plus offers what is done most', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tapTooltip(tester, 'Quick actions');

      expect(find.text('What would you like to do?'), findsOneWidget);
      for (final action in ['Record a payment', 'New customer', 'New contract', 'Search']) {
        expect(find.text(action), findsWidgets, reason: action);
      }
    });

    testWidgets('a viewer is offered only what a viewer can do', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountJson(role: 'viewer')));
      await tapTooltip(tester, 'Quick actions');

      expect(find.text('What would you like to do?'), findsOneWidget);
      expect(find.text('New customer'), findsNothing);
      expect(find.text('Record a payment'), findsNothing);
      expect(find.text('Find a customer or contract'), findsOneWidget);
    });

    testWidgets('Record a payment asks who paid, then opens that contract with the payment ready', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tapTooltip(tester, 'Quick actions');
      await tapText(tester, 'Record a payment');

      expect(find.text('Who paid?'), findsOneWidget);
      await tapFinder(tester, find.widgetWithText(ListTile, 'Ahmad Salem'));

      expect(find.widgetWithText(QButton, 'Record payment'), findsOneWidget);
    });
  });

  group('a reminder that is ready to send', () {
    testWidgets('writes the message for a late instalment, firmly, with the business’s name', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tapFinder(tester, find.widgetWithText(OutlinedButton, 'Remind'));

      expect(find.text('Remind Youssef Mansour'), findsOneWidget);
      expect(find.textContaining('has been overdue since'), findsOneWidget);
      expect(find.textContaining('Al-Fares Electronics'), findsOneWidget);
      expect(tester.widget<QButton>(find.widgetWithText(QButton, 'Send on WhatsApp')).onPressed, isNotNull);
    });

    testWidgets('is friendlier when the instalment is only due', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tapFinder(tester, find.widgetWithText(OutlinedButton, 'Remind').last);

      expect(find.text('Remind Ahmad Salem'), findsOneWidget);
      expect(find.textContaining('a friendly reminder'), findsOneWidget);
    });

    testWidgets('can be copied to send some other way', (tester) async {
      final copied = <String>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'Clipboard.setData') copied.add((call.arguments as Map<Object?, Object?>)['text'] as String);

        return null;
      });
      addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));

      await pumpApp(tester, workspaceServer());
      await tapFinder(tester, find.widgetWithText(OutlinedButton, 'Remind'));
      await tapButton(tester, 'Copy message');

      expect(copied.single, contains('Youssef Mansour'));
      expect(copied.single, contains('overdue since'));
      expect(find.text('Message copied.'), findsOneWidget);
    });

    testWidgets('cannot be sent to someone with no phone number, and says why', (tester) async {
      final nobody = {...dueJson(contract: 'k2', reference: 'C-0003', name: 'Youssef Mansour', amount: '180.00', due: '2026-10-01', late: 6), 'customer_phone': ''};
      await pumpApp(tester, workspaceServer(routes: {'GET /dashboard': always(json(200, {'data': board(late: [nobody])}))}));
      await tapFinder(tester, find.widgetWithText(OutlinedButton, 'Remind'));

      expect(find.text('This customer has no phone number yet. Add one to send a reminder.'), findsOneWidget);
      expect(tester.widget<QButton>(find.widgetWithText(QButton, 'Send on WhatsApp')).onPressed, isNull);
      expect(tester.widget<QButton>(find.widgetWithText(QButton, 'Copy message')).onPressed, isNotNull);
    });
  });

  group('taking a payment from the dashboard', () {
    testWidgets('Record payment on a due instalment opens that contract with the sheet already up', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);

      await tapFinder(tester, find.widgetWithText(FilledButton, 'Record payment').last);

      expect(server.calls('GET /contracts/k1'), greaterThan(0));
      expect(find.widgetWithText(QButton, 'Record payment'), findsOneWidget);
    });

    testWidgets('afterwards says what was received and what is left, and offers the receipt', (tester) async {
      final server = workspaceServer(routes: {'POST /contracts/k1/payments': always(json(201, {'data': lineJson()}))});
      await pumpApp(tester, server);
      await tapFinder(tester, find.widgetWithText(FilledButton, 'Record payment').last);
      final reads = server.calls('GET /contracts/k1');

      await tapButton(tester, 'Record payment');

      expect(find.text('Payment recorded'), findsOneWidget);
      expect(find.text('Still owed on C-0007: SAR 825.00'), findsOneWidget);
      expect(find.text('25% of this contract is paid'), findsOneWidget);
      expect(find.widgetWithText(QButton, 'Send receipt on WhatsApp'), findsOneWidget);
      expect(server.calls('GET /contracts/k1'), greaterThan(reads), reason: 'the contract is read again so it shows the new balance');

      await tapButton(tester, 'Done');
      expect(find.text('Payment recorded'), findsNothing);
    });

    testWidgets('a viewer is never taken to the payment sheet', (tester) async {
      final server = workspaceServer(account: accountJson(role: 'viewer'));
      await pumpApp(tester, server);
      await tapFinder(tester, find.widgetWithText(FilledButton, 'Record payment').last);

      expect(find.widgetWithText(QButton, 'Record payment'), findsNothing);
    });
  });

  group('search', () {
    testWidgets('invites a name, a phone number or a contract number', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tapTooltip(tester, 'Search');

      expect(find.text('Find anyone in a moment'), findsOneWidget);
    });

    testWidgets('finds customers and contracts as the person types, and remembers what was opened', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);
      await tapTooltip(tester, 'Search');

      await tester.enterText(find.byType(TextField), 'ahm');
      await settle(tester);
      expect(server.requestsTo('GET /customers').last.queryParameters['q'], 'ahm');
      expect(server.requestsTo('GET /contracts').last.queryParameters['q'], 'ahm');
      expect(find.text('Customers'), findsOneWidget);
      expect(find.text('Contracts'), findsWidgets);
      expect(find.text('C-0007'), findsOneWidget);

      await tapFinder(tester, find.widgetWithText(ListTile, 'Ahmad Salem'));
      expect(find.text('Owes'), findsOneWidget, reason: 'the customer opened');

      await tester.pageBack();
      await settle(tester);
      expect(find.text('Still to collect'), findsOneWidget, reason: 'Back returns to where search was started');

      await tapTooltip(tester, 'Search');
      expect(find.text('Recent'), findsOneWidget);
      expect(find.widgetWithText(ListTile, 'Ahmad Salem'), findsOneWidget);
    });

    testWidgets('says what it looked for when there is nothing, and how to look again', (tester) async {
      final none = pageJson(<Map<String, dynamic>>[], page: 1, last: 1, total: 0);
      await pumpApp(tester, workspaceServer(routes: {'GET /customers': always(json(200, none)), 'GET /contracts': always(json(200, none))}));
      await tapTooltip(tester, 'Search');

      await tester.enterText(find.byType(TextField), 'zzz');
      await settle(tester);

      expect(find.text('Nothing found for “zzz”'), findsOneWidget);
    });
  });

  group('coming back to the app', () {
    testWidgets('after a while away it shows today’s figures', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server, overrides: [staleAfterProvider.overrideWithValue(Duration.zero)]);
      final board = server.calls('GET /dashboard');
      final me = server.calls('GET /me');

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 20)));
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(tester);

      expect(server.calls('GET /dashboard'), greaterThan(board));
      expect(server.calls('GET /me'), greaterThan(me));
    });

    testWidgets('a moment away changes nothing and asks for nothing', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);
      final board = server.calls('GET /dashboard');

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle(tester);

      expect(server.calls('GET /dashboard'), board);
    });
  });
}
