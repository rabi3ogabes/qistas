import 'dart:convert';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/billing/store_gateway.dart';
import 'package:qistas/core/design/widgets.dart';

import '../support/fake_api.dart';
import '../support/fake_store.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Win Plan PP13 in the app: Pro through Google Play. The owner picks monthly or yearly at the store's own price,
/// Google takes the payment, and Pro turns on only after the server has checked the purchase with Google; the store is
/// told it was received only then. Restore brings a purchase to a new phone. The app never mentions paying by bank
/// transfer or on the website (Google Play's payments policy).

Map<String, dynamic> billingJson({bool store = true, bool canBuy = true, String plan = 'free', String? source, String? until, bool? autoRenew, String? period}) => {
      'plan': {'key': plan, 'name': plan == 'pro' ? 'Pro' : 'Free'},
      'status': plan == 'pro' ? 'active' : null,
      'until': until,
      'source': source,
      'auto_renew': autoRenew,
      'period': period,
      'can_buy': canBuy,
      'store': {
        'available': store && canBuy,
        'account_ref': store && canBuy ? 'ref-abc' : null,
        'products': store && canBuy ? [{'plan_key': 'pro', 'product_id': 'qistas_pro'}] : <Object?>[],
      },
    };

FakeServer billingServer({Map<String, dynamic>? billing, Map<String, dynamic>? account, Map<String, Route> routes = const {}}) => workspaceServer(account: account, routes: {
      'GET /plans': always(json(200, {'data': plansJson()})),
      'GET /billing': always(json(200, {'data': billing ?? billingJson()})),
      'POST /billing/play/verify': always(json(200, {'data': billingJson(plan: 'pro', source: 'play', until: '2026-11-11', autoRenew: true)})),
      ...routes,
    });

Future<void> openPlans(WidgetTester tester) async {
  await openSettings(tester);
  await tapButton(tester, 'Upgrade to Pro');
}

Map<String, dynamic> sent(FakeServer server, String route) => jsonDecode(server.requestsTo(route).single.data as String) as Map<String, dynamic>;

/// Every sentence on screen, lower-cased.
Iterable<String> wordsOnScreen(WidgetTester tester) => tester.widgetList<Text>(find.byType(Text)).map((t) => (t.data ?? t.textSpan?.toPlainText() ?? '').toLowerCase());

void main() {
  group('buying Pro through Google Play', () {
    testWidgets('subscribes at the store’s price, and Pro turns on once the server has checked the purchase', (tester) async {
      final store = FakeStoreGateway();
      final server = billingServer();
      await pumpApp(tester, server, store: store);
      await openPlans(tester);

      expect(find.text('14 days free, then SAR 19.00 a month'), findsOneWidget);
      expect(find.text('SAR 149.00 a year'), findsOneWidget);
      expect(find.text('Billed through Google Play. Cancel any time in the Play Store.'), findsOneWidget);
      expect(store.askedFor, {'qistas_pro'});

      await tapButton(tester, 'Start the free trial');

      expect(store.bought.single.$1.key, 'monthly:trial');
      expect(store.bought.single.$2, 'ref-abc', reason: 'bought with the workspace’s account reference');
      expect(sent(server, 'POST /billing/play/verify'), {'product_id': 'qistas_pro', 'purchase_token': 'tok-monthly'});
      expect(store.completed, ['tok-monthly'], reason: 'the store is told only once the server has the purchase');
      expect(find.text('Pro is on. Thank you.'), findsOneWidget);
    });

    testWidgets('takes the yearly plan when the owner picks it', (tester) async {
      final store = FakeStoreGateway();
      await pumpApp(tester, billingServer(), store: store);
      await openPlans(tester);

      await tapText(tester, 'Yearly');
      await tapButton(tester, 'Subscribe');

      expect(store.bought.single.$1.key, 'yearly:');
    });

    testWidgets('unlocks nothing and tells the store nothing when the server refuses the purchase', (tester) async {
      final store = FakeStoreGateway();
      final server = billingServer(routes: {
        'POST /billing/play/verify': always(apiError(409, 'purchase_elsewhere', 'This purchase belongs to another workspace.')),
      });
      await pumpApp(tester, server, store: store);
      await openPlans(tester);

      await tapButton(tester, 'Start the free trial');

      expect(find.text('This purchase belongs to another workspace.'), findsOneWidget);
      expect(store.completed, isEmpty);
      expect(find.text('Pro is on. Thank you.'), findsNothing);
    });

    testWidgets('waits while Google completes a payment, then turns Pro on', (tester) async {
      final store = FakeStoreGateway(reportsPurchases: false);
      final server = billingServer();
      await pumpApp(tester, server, store: store);
      await openPlans(tester);
      await tapButton(tester, 'Start the free trial');

      store.report([const StorePurchaseUpdate(productId: 'qistas_pro', token: 'tok-slow', status: StorePurchaseStatus.pending)]);
      await settle(tester);
      expect(find.text('Google Play is completing the payment. Pro turns on as soon as it is done.'), findsOneWidget);
      expect(server.requestsTo('POST /billing/play/verify'), isEmpty);

      store.report([const StorePurchaseUpdate(productId: 'qistas_pro', token: 'tok-slow', status: StorePurchaseStatus.purchased)]);
      await settle(tester);
      expect(sent(server, 'POST /billing/play/verify')['purchase_token'], 'tok-slow');
      expect(find.text('Pro is on. Thank you.'), findsOneWidget);
    });

    testWidgets('restores a purchase on a new phone, and says when there is nothing to restore', (tester) async {
      final store = FakeStoreGateway();
      final server = billingServer();
      await pumpApp(tester, server, store: store);
      await openPlans(tester);

      await tapButton(tester, 'Restore purchases');
      expect(find.text('Nothing to restore for this Google account.'), findsOneWidget);

      store.restorable = const [StorePurchaseUpdate(productId: 'qistas_pro', token: 'tok-old', status: StorePurchaseStatus.restored)];
      await tapButton(tester, 'Restore purchases');
      expect(sent(server, 'POST /billing/play/verify')['purchase_token'], 'tok-old');
      expect(store.completed, ['tok-old']);
    });
  });

  group('what the plans screen offers', () {
    testWidgets('nothing to buy while the platform has the store off', (tester) async {
      final store = FakeStoreGateway();
      await pumpApp(tester, billingServer(billing: billingJson(store: false)), store: store);
      await openPlans(tester);

      expect(find.text('Start the free trial'), findsNothing);
      expect(find.text('Subscribe'), findsNothing);
      expect(store.askedFor, isNull);
    });

    testWidgets('nothing to buy unless the server says the store is open, whatever else it lists', (tester) async {
      final store = FakeStoreGateway();
      final billing = billingJson();
      billing['store'] = {...(billing['store'] as Map<String, dynamic>), 'available': false};
      await pumpApp(tester, billingServer(billing: billing), store: store);
      await openPlans(tester);

      expect(find.text('Start the free trial'), findsNothing);
      expect(store.askedFor, isNull);
    });

    testWidgets('a word for someone who is not the owner, and no way to buy', (tester) async {
      final store = FakeStoreGateway();
      await pumpApp(tester, billingServer(billing: billingJson(canBuy: false), account: accountJson(role: 'manager')), store: store);
      await openPlans(tester);

      expect(find.text('Only the owner of the workspace can change its plan.'), findsOneWidget);
      expect(find.text('Start the free trial'), findsNothing);
    });

    testWidgets('a plan paid through Google Play, with where to manage it', (tester) async {
      final store = FakeStoreGateway();
      await pumpApp(tester, billingServer(billing: billingJson(plan: 'pro', source: 'play', until: '2026-11-11', autoRenew: true), account: accountJson(plan: 'pro')), store: store);
      await openSettings(tester);
      await tapText(tester, 'Pro is on');

      expect(find.textContaining('Through Google Play, renews'), findsOneWidget);
      expect(find.text('Manage in the Play Store'), findsOneWidget);
      expect(find.text('Start the free trial'), findsNothing);
    });

    testWidgets('a plan paid outside the app: when it renews, and nothing to buy, no price and no word of how it was paid', (tester) async {
      final store = FakeStoreGateway();
      await pumpApp(
        tester,
        billingServer(billing: billingJson(plan: 'pro', source: 'stripe', until: '2026-12-09', autoRenew: true, period: 'year', store: false), account: accountJson(plan: 'pro')),
        store: store,
      );
      await openSettings(tester);
      await tapText(tester, 'Pro is on');

      expect(find.text('Renews Dec 9, 2026'), findsOneWidget);
      expect(find.text('Subscribe'), findsNothing);
      expect(find.text('Start the free trial'), findsNothing);
      expect(find.text('Manage in the Play Store'), findsNothing);
      expect(find.byType(MoneyText), findsNothing, reason: 'no price beside a plan paid outside the store');
      expect(find.text('per month'), findsNothing);
      for (final words in wordsOnScreen(tester)) {
        expect(words, isNot(anyOf(contains('card'), contains('website'), contains('stripe'), contains('bank'))), reason: 'shown: "$words"');
      }
      expect(store.askedFor, isNull);
    });

    testWidgets('a plan paid outside the app that will not renew says until when', (tester) async {
      await pumpApp(
        tester,
        billingServer(billing: billingJson(plan: 'pro', source: 'stripe', until: '2026-12-09', autoRenew: false, period: 'month', store: false), account: accountJson(plan: 'pro')),
        store: FakeStoreGateway(),
      );
      await openSettings(tester);
      await tapText(tester, 'Pro is on');

      expect(find.text('Until Dec 9, 2026'), findsOneWidget);
      expect(find.textContaining('Renews'), findsNothing);
    });

    testWidgets('a plan the team gave with no end shows no date and no price', (tester) async {
      await pumpApp(tester, billingServer(billing: billingJson(plan: 'pro', source: 'admin', store: false), account: accountJson(plan: 'pro')), store: FakeStoreGateway());
      await openSettings(tester);
      await tapText(tester, 'Pro is on');

      expect(find.textContaining('Until'), findsNothing);
      expect(find.textContaining('Renews'), findsNothing);
      expect(find.byType(MoneyText), findsNothing);
    });

    for (final (name, billing) in [
      ('with the store on', billingJson()),
      ('with the store off', billingJson(store: false)),
      ('to someone who is not the owner', billingJson(canBuy: false)),
    ]) {
      testWidgets('never mentions a bank transfer or the website, $name', (tester) async {
        await pumpApp(tester, billingServer(billing: billing), store: FakeStoreGateway());
        await openPlans(tester);

        for (final words in wordsOnScreen(tester)) {
          expect(words, isNot(anyOf(contains('bank'), contains('transfer'), contains('website'))), reason: 'shown: "$words"');
        }
      });
    }

    testWidgets('the upgrade sheet points to the plans, never to the website', (tester) async {
      final account = accountJson();
      final entitlements = Map<String, dynamic>.from(account['entitlements'] as Map);
      entitlements['active_contracts'] = {'type': 'limit', 'enabled': true, 'limit': 5, 'used': 5, 'remaining': 0, 'unlimited': false};
      account['entitlements'] = entitlements;
      await pumpApp(tester, billingServer(account: account), store: FakeStoreGateway());

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');

      expect(find.text('You have reached your plan limit'), findsOneWidget);
      for (final words in wordsOnScreen(tester)) {
        expect(words, isNot(anyOf(contains('website'), contains('bank'), contains('transfer'))), reason: 'shown: "$words"');
      }
      expect(find.text('See Pro'), findsOneWidget);
    });
  });
}
