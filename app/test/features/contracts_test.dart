import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart' show Icons, OutlinedButton, Scaffold, Size, TextButton, TextSelection;
import 'package:flutter/rendering.dart' show RenderParagraph;
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:qistas/core/design/widgets.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

void main() {
  group('the contract list', () {
    testWidgets('shows each contract with who owes what and when the next instalment is due', (tester) async {
      await pumpApp(tester, workspaceServer());

      await tapText(tester, 'Contracts', last: true);

      expect(find.text('C-0007'), findsOneWidget);
      expect(find.text('Ahmad Salem'), findsOneWidget);
      expect(find.text('SAR 825.00'), findsOneWidget);
      expect(find.textContaining('Next: SAR 275.00 on'), findsOneWidget);
    });

    testWidgets('asks the server for late contracts when the Late filter is chosen', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'Late');

      expect(server.requestsTo('GET /contracts').last.queryParameters['status'], 'late');
    });

    testWidgets('on a full plan, New contract answers with the upgrade sheet, not a form', (tester) async {
      final account = accountJson();
      final entitlements = Map<String, dynamic>.from(account['entitlements'] as Map);
      entitlements['active_contracts'] = {'type': 'limit', 'enabled': true, 'limit': 5, 'used': 5, 'remaining': 0, 'unlimited': false};
      account['entitlements'] = entitlements;
      final server = workspaceServer(account: account);
      await pumpApp(tester, server);

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');

      expect(find.text('You have reached your plan limit'), findsOneWidget);
      expect(find.text('Sale price (SAR)'), findsNothing);
    });
  });

  group('one contract', () {
    testWidgets('shows the terms, the schedule and the payments', (tester) async {
      await pumpApp(tester, workspaceServer());

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');

      expect(find.text('SAR 825.00'), findsWidgets);
      expect(find.text('Schedule'), findsOneWidget);
      expect(find.text('Paid'), findsWidgets);
      expect(find.text('Upcoming'), findsWidgets);
      expect(find.text('Cash'), findsOneWidget);
    });

    testWidgets('voids a payment only after being asked, with the reason that was typed', (tester) async {
      final server = workspaceServer(routes: {
        'POST /payments/t1/void': always(json(200, {'data': lineJson(type: 'reversal', amount: '-275.00')})),
      });
      await pumpApp(tester, server);

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapText(tester, 'Void payment');
      expect(find.text('Void this payment?'), findsOneWidget);
      expect(server.calls('POST /payments/t1/void'), 0);

      await typeInto(tester, 'Reason (optional)', 'Typed twice');
      await tester.tap(find.widgetWithText(TextButton, 'Void payment').last);
      await settle(tester);

      final body = jsonDecode(server.requestsTo('POST /payments/t1/void').single.data as String) as Map<String, dynamic>;
      expect(body['reason'], 'Typed twice');
    });

    testWidgets('says why the server refused a void, not only that something was not valid', (tester) async {
      // The phone still thinks this person is a manager; the server, which has the last word, says the day is closed.
      final server = workspaceServer(routes: {
        'POST /payments/t1/void': always(apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {
          'fields': {'transaction': ['That day is closed. Ask the owner or a manager.']},
        })),
      });
      await pumpApp(tester, server);

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapText(tester, 'Void payment');
      await tester.tap(find.widgetWithText(TextButton, 'Void payment').last);
      await settle(tester);

      expect(find.text('That day is closed. Ask the owner or a manager.'), findsOneWidget);
      expect(find.text('Some of the information is not valid.'), findsNothing);
    });

    testWidgets('backing out of the void dialog sends nothing', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapText(tester, 'Void payment');
      await tapText(tester, 'Cancel');

      expect(server.calls('POST /payments/t1/void'), 0);
    });

    testWidgets('a collector cannot void a payment or cancel the contract', (tester) async {
      await pumpApp(tester, workspaceServer(account: accountJson(role: 'collector')));

      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');

      expect(find.text('Void payment'), findsNothing);
      expect(find.text('Cancel contract'), findsNothing);
      expect(find.text('Record a payment'), findsWidgets);
    });
  });

  group('opening a contract', () {
    Future<void> chooseCustomer(WidgetTester tester) async {
      await tapText(tester, 'Choose a customer');
      await tapText(tester, 'Ahmad Salem');
    }

    testWidgets('works out the schedule on the phone as the numbers are typed, exactly as the server would', (tester) async {
      await pumpApp(tester, workspaceServer());
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      await chooseCustomer(tester);

      await typeInto(tester, 'Sale price (SAR)', '1,200');
      await typeInto(tester, 'Down payment (optional)', '200');
      await tapText(tester, 'Percent');
      await typeInto(tester, 'Markup (% of the financed amount)', '10');
      await tapTooltip(tester, 'Fewer instalments');
      await tapTooltip(tester, 'Fewer instalments');

      expect(find.text('Schedule preview'), findsOneWidget);
      expect(find.text('SAR 1,000.00'), findsOneWidget);
      expect(find.text('SAR 100.00'), findsOneWidget);
      expect(find.text('SAR 1,100.00'), findsOneWidget);
      expect(find.text('SAR 275.00'), findsNWidgets(4));
    });

    testWidgets('sends the contract as the server expects it and shows it', (tester) async {
      final server = workspaceServer(routes: {'POST /contracts': always(json(201, {'data': contractJson()}))});
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      await chooseCustomer(tester);

      await typeInto(tester, 'Sale price (SAR)', '1200');
      await typeInto(tester, 'Down payment (optional)', '200');
      await tapText(tester, 'Percent');
      await typeInto(tester, 'Markup (% of the financed amount)', '10');
      await tapTooltip(tester, 'Fewer instalments');
      await tapTooltip(tester, 'Fewer instalments');
      await tapButton(tester, 'Open contract');

      final body = jsonDecode(server.requestsTo('POST /contracts').single.data as String) as Map<String, dynamic>;
      expect(body['customer_id'], 'c1');
      expect(body['type'], 'scheduled');
      expect(body['principal'], '1200.00');
      expect(body['down_payment'], '200.00');
      expect(body['markup_type'], 'percent');
      expect(body['markup_value'], '10.00');
      expect(body['installment_count'], 4);
      expect(body['frequency'], 'monthly');
      expect(find.text('Schedule'), findsOneWidget);
    });

    testWidgets('does not ask the server until a customer and a price are given', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');

      await tapButton(tester, 'Open contract');

      expect(find.text('Choose who this contract is for.'), findsOneWidget);
      expect(find.text('Enter a price greater than zero.'), findsOneWidget);
      expect(server.calls('POST /contracts'), 0);
    });

    testWidgets('names the down payment as the problem when it is as large as the price', (tester) async {
      final server = workspaceServer();
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      await chooseCustomer(tester);

      await typeInto(tester, 'Sale price (SAR)', '500');
      await typeInto(tester, 'Down payment (optional)', '500');

      expect(find.text('The down payment must be less than the price.'), findsOneWidget);
      expect(find.text('Schedule preview'), findsNothing);
    });

    testWidgets('a cash sale needs no schedule', (tester) async {
      final server = workspaceServer(routes: {'POST /contracts': always(json(201, {'data': contractJson()}))});
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      await chooseCustomer(tester);

      await tapText(tester, 'Cash sale');
      await typeInto(tester, 'Sale price (SAR)', '300');
      await tapButton(tester, 'Open contract');

      final body = jsonDecode(server.requestsTo('POST /contracts').single.data as String) as Map<String, dynamic>;
      expect(body['type'], 'cash');
      expect(body.containsKey('installment_count'), isFalse);
    });

    testWidgets('shows the plan limit sheet when the server refuses', (tester) async {
      final server = workspaceServer(routes: {
        'POST /contracts': always(apiError(402, 'limit_reached', 'Your plan includes up to 5 active contracts.', extra: {'feature': 'active_contracts', 'limit': 5, 'used': 5})),
      });
      await pumpApp(tester, server);
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      await chooseCustomer(tester);
      await typeInto(tester, 'Sale price (SAR)', '1200');
      await tapButton(tester, 'Open contract');

      expect(find.text('You have reached your plan limit'), findsOneWidget);
      expect(find.byType(QButton), findsWidgets);
    });

    group('a contract date the server refuses', () {
      const closed = 'That day is closed. Ask the owner or a manager.';
      // What the server says in Arabic (web/lang/app/ar.json): the app shows the server's words as they come.
      const closedArabic = 'هذا اليوم مغلق. اطلب ذلك من المالك أو أحد المديرين.';

      FakeServer refusing(String message) => workspaceServer(routes: {
            'POST /contracts': always(apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {
              'fields': {'start_date': [message]},
            })),
          });

      Finder contractDate() => find.ancestor(of: find.byIcon(Icons.calendar_today_outlined), matching: find.byType(OutlinedButton));

      testWidgets('shows the reason under the contract date, with the banner above', (tester) async {
        await pumpApp(tester, refusing(closed));
        await tapText(tester, 'Contracts', last: true);
        await tapText(tester, 'New contract');
        await chooseCustomer(tester);
        await typeInto(tester, 'Sale price (SAR)', '1200');
        await typeInto(tester, 'Down payment (optional)', '200');
        await tapButton(tester, 'Open contract');

        expect(find.text('Check the highlighted fields and try again.'), findsOneWidget);
        // Once, and right under the contract date: above the first instalment's date, not beside another field.
        expect(find.text(closed), findsOneWidget);
        final reason = tester.getRect(find.text(closed));
        expect(reason.top, greaterThanOrEqualTo(tester.getRect(contractDate()).bottom));
        expect(reason.bottom, lessThanOrEqualTo(tester.getRect(find.text('First instalment due')).top));
        // The other fields say nothing.
        expect(find.text('Choose who this contract is for.'), findsNothing);
        expect(find.text('Enter a price greater than zero.'), findsNothing);
      });

      for (final language in ['en', 'ar']) {
        testWidgets(language == 'ar' ? 'the reason wraps at 200% text in Arabic' : 'the reason wraps at 200% text in English', (tester) async {
          final words = language == 'ar' ? jsonDecode(File('assets/i18n/ar.json').readAsStringSync()) as Map<String, dynamic> : <String, dynamic>{};
          String word(String english) => (words[english] ?? english) as String;
          final message = language == 'ar' ? closedArabic : closed;

          await pumpApp(tester, refusing(message), language: language, size: const Size(360, 640), textScale: 2);
          GoRouter.of(tester.element(find.byType(Scaffold).first)).go('/contracts/new?customer=c1');
          await settle(tester);
          // A cash sale: the contract date and its reason are drawn the same for every kind of sale, and a sale with a
          // schedule adds the schedule preview, whose rows do not fit a 360 dp screen at 200% (a separate matter).
          await tapText(tester, word('Cash sale'));
          await typeInto(tester, word('Sale price (:currency)').replaceAll(':currency', 'SAR'), '1200');
          await tapButton(tester, word('Open contract'));

          expect(find.text(message), findsOneWidget);
          await tester.ensureVisible(find.text(message));
          await tester.pump();
          final paragraph = tester.renderObject<RenderParagraph>(find.text(message));
          final lines = {for (final box in paragraph.getBoxesForSelection(TextSelection(baseOffset: 0, extentOffset: message.length))) box.top.round()};
          expect(lines.length, greaterThan(1), reason: 'the reason is written on several lines, not cut');
          expect(tester.getRect(find.text(message)).left, greaterThanOrEqualTo(0));
          expect(tester.getRect(find.text(message)).right, lessThanOrEqualTo(360));
          expect(tester.takeException(), isNull);
        });
      }
    });
  });
}
