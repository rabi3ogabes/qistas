import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter/semantics.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:qistas/core/l10n/formats.dart';
import 'package:qistas/data/models.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Payday learning (daily-habit wave A, H6) on the phone: the chip on a customer's page, and the offer of their usual payday
/// under the first-due date of a new contract and under the date of "move one date". The offer changes nothing by itself.

/// Left out of an answer altogether, as the server does with the feature off.
const _absent = Object();

/// A customer's own page, as `GET /customers/c1` answers: with [pattern] under `pay_pattern`, or without the key at all.
Map<String, dynamic> pageOf({Object? pattern = _absent, String name = 'Ahmad Salem'}) => {
      ...customerJson(name: name),
      'contracts': <Map<String, dynamic>>[],
      if (!identical(pattern, _absent)) 'pay_pattern': pattern,
    };

FakeServer paydayServer({
  Map<String, dynamic>? account,
  Object? pattern = _absent,
  String name = 'Ahmad Salem',
  Map<String, Route> routes = const {},
}) =>
    workspaceServer(account: account ?? accountWithPayday(), routes: {
      'GET /customers': always(json(200, pageJson([customerJson(name: name)], page: 1, last: 1, total: 1))),
      'GET /customers/c1': always(json(200, {'data': pageOf(pattern: pattern, name: name)})),
      'POST /contracts': always(json(201, {'data': contractJson()})),
      ...routes,
    });

/// The first-due date a new contract starts with: a month after today, the 31st clamped, as the form does.
DateTime plusMonth(DateTime day) {
  final lastOfNext = DateTime(day.year, day.month + 2, 0).day;

  return DateTime(day.year, day.month + 1, day.day > lastOfNext ? lastOfNext : day.day);
}

DateTime today() {
  final now = DateTime.now();

  return DateTime(now.year, now.month, now.day);
}

/// The first day on or after [from] that is the [day] of a month, worked out here by hand (the app's rule is not used to
/// say what the rule should give).
DateTime onOrAfter(DateTime from, int day) => from.day <= day ? DateTime(from.year, from.month, day) : DateTime(from.year, from.month + 1, day);

DateTime lastOfMonth(DateTime from) => DateTime(from.year, from.month + 1, 0);

/// A date as the sentence says it: the app's own date, in one piece.
String said(DateTime day, [String language = 'en']) => formatMoment(day, language).replaceAll(RegExp(r'\s'), ' ');

String iso(DateTime day) => isoDay(day);

Map<String, dynamic> arabic() => jsonDecode(File('assets/i18n/ar.json').readAsStringSync()) as Map<String, dynamic>;

const _suggestion = ValueKey('pay-suggestion');
const _suggestionText = ValueKey('pay-suggestion-text');
const _useIt = ValueKey('pay-suggestion-use');
const _chip = ValueKey('pay-chip');

String textOf(WidgetTester tester) => tester.widget<Text>(find.byKey(_suggestionText)).data!;

Future<void> openCustomer(WidgetTester tester) async {
  await tapText(tester, 'Customers', last: true);
  await tapText(tester, 'Ahmad Salem');
}

Future<void> openForm(WidgetTester tester) async {
  await tapText(tester, 'Contracts', last: true);
  await tapText(tester, 'New contract');
  await tapText(tester, 'Choose a customer');
  await tapText(tester, 'Ahmad Salem');
}

Map<String, dynamic> sent(FakeServer server, String route) => jsonDecode(server.requestsTo(route).last.data as String) as Map<String, dynamic>;

void main() {
  group('the customer screen', () {
    testWidgets('the customer screen shows the chip', (tester) async {
      await pumpApp(tester, paydayServer(pattern: payPatternJson()));
      await openCustomer(tester);

      expect(find.byKey(_chip), findsOneWidget);
      expect(find.text('Usually pays around day 27 of the month'), findsOneWidget);
      expect(find.text('Seen in 83% of recent months'), findsOneWidget);
    });

    testWidgets('says it at the end of the month as the server worded it, and builds no wording of its own', (tester) async {
      await pumpApp(tester, paydayServer(pattern: payPatternJson(endOfMonth: true, within: 71, label: 'Pays when the month ends', basis: 'Most months')));
      await openCustomer(tester);

      expect(find.text('Pays when the month ends'), findsOneWidget);
      expect(find.text('Most months'), findsOneWidget);
      expect(find.textContaining('Usually pays'), findsNothing);
      expect(find.textContaining('71'), findsNothing);
    });

    testWidgets('an answer without the key keeps the pattern: pinning does not clear the chip', (tester) async {
      // The pin answer is the customer without `pay_pattern` (only the customer's own page carries it).
      final server = paydayServer(pattern: payPatternJson(), routes: {
        'POST /customers/c1/pin': always(json(200, {'data': customerJson()})),
      });
      await pumpApp(tester, server);
      await openCustomer(tester);
      expect(find.byKey(_chip), findsOneWidget);
      final pages = server.calls('GET /customers/c1');

      await tapTooltip(tester, 'Pin to the top');

      expect(server.calls('POST /customers/c1/pin'), 1);
      expect(server.calls('GET /customers/c1'), pages + 1);
      expect(find.byKey(_chip), findsOneWidget);
      expect(find.text('Usually pays around day 27 of the month'), findsOneWidget);
    });

    test('the key is optional: an answer without it is a customer with no pattern read, and one that is malformed is the same', () {
      expect(Customer.fromJson(customerJson()).payPattern, isNull);
      expect(Customer.fromJson({...customerJson(), 'pay_pattern': null}).payPattern, isNull);
      expect(Customer.fromJson({...customerJson(), 'pay_pattern': {'day': 'soon'}}).payPattern, isNull);
      expect(Customer.fromJson({...customerJson(), 'pay_pattern': payPatternJson()}).payPattern?.day, 27);
    });
  });

  group('the contract form', () {
    testWidgets('the contract form suggests the day and changes the date only when the button is pressed', (tester) async {
      final server = paydayServer(pattern: payPatternJson());
      await pumpApp(tester, server, size: const Size(412, 1500));
      await openForm(tester);

      final first = plusMonth(today());
      final suggested = onOrAfter(first, 27);
      expect(find.byKey(_suggestion), findsOneWidget);
      expect(textOf(tester), first.day == 27 ? 'Ahmad Salem usually pays around day 27. This date is already their payday.' : 'Ahmad Salem usually pays around day 27. Use ${said(suggested)}?');
      // Offered, not applied: the field still holds the month-later date it started with.
      expect(find.text(formatMoment(first, 'en')), findsOneWidget);

      if (first.day == 27) return;
      await tapText(tester, 'Use it');

      expect(find.text(formatMoment(suggested, 'en')), findsOneWidget);
      expect(find.text(formatMoment(first, 'en')), findsNothing);
      // The date now is their payday, so the offer says so and the button goes.
      expect(textOf(tester), 'Ahmad Salem usually pays around day 27. This date is already their payday.');
      expect(find.text('Use it'), findsNothing);
      expect(server.calls('POST /contracts'), 0);
    });

    testWidgets('sends the contract on the day that was offered, and not before', (tester) async {
      final server = paydayServer(pattern: payPatternJson(day: 3));
      await pumpApp(tester, server, size: const Size(412, 1800));
      await openForm(tester);
      await typeInto(tester, 'Sale price (SAR)', '1200');
      final first = plusMonth(today());
      final suggested = onOrAfter(first, 3);
      // Unless the form already starts on the 3rd, the day is offered but the field is not changed.
      expect(find.text(formatMoment(first, 'en')), findsWidgets);
      if (first.day != 3) {
        await tapText(tester, 'Use it');
      }

      await tapButton(tester, 'Open contract');

      expect(sent(server, 'POST /contracts')['first_due_date'], iso(suggested));
    });

    testWidgets('end of the month: offers the last day of the month, in words that fit', (tester) async {
      final server = paydayServer(pattern: payPatternJson(endOfMonth: true));
      await pumpApp(tester, server, size: const Size(412, 1500));
      await openForm(tester);

      final first = plusMonth(today());
      final suggested = lastOfMonth(first);
      expect(
        textOf(tester),
        first.day == suggested.day ? 'Ahmad Salem usually pays at the end of the month. This date is already their payday.' : 'Ahmad Salem usually pays at the end of the month. Use ${said(suggested)}?',
      );
    });

    testWidgets('picking another date changes only the sentence, never the date they picked', (tester) async {
      final server = paydayServer(pattern: payPatternJson());
      await pumpApp(tester, server, size: const Size(412, 1500));
      await openForm(tester);
      final first = plusMonth(today());

      await tapText(tester, formatMoment(first, 'en'));
      await tester.tap(find.text('3').last);
      await tester.pump();
      await tester.tap(find.text('OK'));
      await settle(tester);

      final picked = DateTime(first.year, first.month, 3);
      expect(find.text(formatMoment(picked, 'en')), findsOneWidget);
      expect(textOf(tester), 'Ahmad Salem usually pays around day 27. Use ${said(DateTime(first.year, first.month, 27))}?');
      expect(find.text(formatMoment(picked, 'en')), findsOneWidget);
    });

    testWidgets('the whole name is used, and a name that looks like a placeholder is left as it is', (tester) async {
      final server = paydayServer(pattern: payPatternJson(), name: 'Ali :day :date Abdulrahman Mohammed Al-Ghamdi');
      await pumpApp(tester, server, size: const Size(360, 1500));
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      await tapText(tester, 'Choose a customer');
      await tapText(tester, 'Ali :day :date Abdulrahman Mohammed Al-Ghamdi');

      expect(textOf(tester), startsWith('Ali :day :date Abdulrahman Mohammed Al-Ghamdi usually pays around day 27. '));
    });

    testWidgets('a cash sale and the shop’s own dates have no first-due date to suggest for', (tester) async {
      final server = paydayServer(pattern: payPatternJson(), account: accountWithPayday()..['entitlements'] = {
        ...Map<String, dynamic>.from(accountWithPayday()['entitlements'] as Map),
        'flexible_schedules': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
      });
      await pumpApp(tester, server, size: const Size(412, 1800));
      await openForm(tester);
      expect(find.byKey(_suggestion), findsOneWidget);

      await tapText(tester, 'My own dates');
      expect(find.byKey(_suggestion), findsNothing);

      await tapText(tester, 'Cash sale');
      expect(find.byKey(_suggestion), findsNothing);
    });

    testWidgets('a screen reader hears the button and can press it', (tester) async {
      final handle = tester.ensureSemantics();
      final server = paydayServer(pattern: payPatternJson(day: 3));
      await pumpApp(tester, server, size: const Size(412, 1500));
      await openForm(tester);
      final first = plusMonth(today());
      if (first.day == 3) {
        handle.dispose();
        return;
      }

      final button = find.semantics.byLabel('Use it');
      expect(button, findsOneWidget);
      final data = button.evaluate().single.getSemanticsData();
      expect(data.label, 'Use it');
      expect(data.hint, formatMoment(onOrAfter(first, 3), 'en'));
      expect(data.hasAction(SemanticsAction.tap), isTrue);
      expect(data.flagsCollection.isButton, isTrue);
      expect(tester.getSize(find.byKey(_useIt)).height, greaterThanOrEqualTo(48));

      tester.semantics.tap(button);
      await settle(tester);

      expect(find.text(formatMoment(onOrAfter(first, 3), 'en')), findsOneWidget);
      handle.dispose();
    });
  });

  group('nothing shows', () {
    group('nothing shows without a pattern or with the feature off', () {
      for (final (why, account, pattern) in <(String, Map<String, dynamic>, Object?)>[
        ('the feature is on and there is not enough to say yet', accountWithPayday(), null),
        ('the server does not list the feature', accountWithPayday(listed: false), payPatternJson()),
        ('the platform has it switched off', accountWithPayday(status: 'platform_off'), payPatternJson()),
        ('the plan does not include it', accountWithPayday(status: 'plan_locked'), payPatternJson()),
      ]) {
        // Even when a stale answer still carries a pattern, the entitlement decides.
        testWidgets(why, (tester) async {
          await pumpApp(tester, paydayServer(account: account, pattern: pattern), size: const Size(412, 1500));
          await openCustomer(tester);
          expect(find.byKey(_chip), findsNothing);
          expect(find.textContaining('Usually pays'), findsNothing);
          expect(find.text('Ahmad Salem'), findsWidgets);

          await tester.pageBack();
          await settle(tester);
          await openForm(tester);
          expect(find.byKey(_suggestion), findsNothing);
          expect(find.textContaining('usually pays'), findsNothing);
          expect(find.text('Sale price (SAR)'), findsOneWidget);
        });
      }
    });

    testWidgets('a viewer is offered nothing, and the page is not read for it', (tester) async {
      final server = paydayServer(account: accountWithPayday(role: 'viewer'), pattern: payPatternJson());
      await pumpApp(tester, server, size: const Size(412, 1500));
      GoRouter.of(tester.element(find.byType(Scaffold).first)).go('/contracts/new?customer=c1');
      await settle(tester);

      expect(find.text('Ahmad Salem'), findsWidgets);
      expect(find.byKey(_suggestion), findsNothing);
      // The customer was loaded once, for the form's own sake; the offer asked for no second copy.
      expect(server.calls('GET /customers/c1'), 1);
    });

    group('a pay_pattern that is malformed is ignored: the page and the form carry on', () {
      for (final broken in <Object?>[
        {'day': 'soon'},
        'every month',
        [27],
        {...payPatternJson(), 'day': 31},
        {...payPatternJson(), 'label': ''},
      ]) {
        testWidgets('$broken', (tester) async {
          await pumpApp(tester, paydayServer(pattern: broken), size: const Size(412, 1500));
          await openCustomer(tester);
          expect(tester.takeException(), isNull);
          expect(find.byKey(_chip), findsNothing);
          expect(find.text('Ahmad Salem'), findsWidgets);
          await tester.pageBack();
          await settle(tester);
          await openForm(tester);
          expect(tester.takeException(), isNull);
          expect(find.byKey(_suggestion), findsNothing);
          expect(find.text('Sale price (SAR)'), findsOneWidget);
        });
      }
    });

    testWidgets('with the feature off the form asks for no page of the customer', (tester) async {
      final server = paydayServer(account: accountWithPayday(listed: false), pattern: payPatternJson());
      await pumpApp(tester, server, size: const Size(412, 1500));
      await openForm(tester);
      await typeInto(tester, 'Sale price (SAR)', '1200');
      await typeInto(tester, 'Down payment (optional)', '100');

      expect(server.calls('GET /customers/c1'), 0);
      expect(server.calls('GET /customers'), greaterThan(0));
    });

    testWidgets('with the feature on the form asks for the customer’s page once, however much is typed', (tester) async {
      final server = paydayServer(pattern: payPatternJson());
      await pumpApp(tester, server, size: const Size(412, 1500));
      await openForm(tester);
      await typeInto(tester, 'Sale price (SAR)', '1200');
      await typeInto(tester, 'Down payment (optional)', '100');

      expect(server.calls('GET /customers/c1'), 1);
      expect(find.byKey(_suggestion), findsOneWidget);
    });
  });

  group('the change-plan screen', () {
    Map<String, dynamic> accountForPlans({String status = 'on'}) {
      final account = accountWithPayday(status: status);
      account['entitlements'] = {
        ...Map<String, dynamic>.from(account['entitlements'] as Map),
        'reschedule': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
      };

      return account;
    }

    /// A contract whose first two untouched instalments fall on [due] and a month after it.
    Map<String, dynamic> contractDueOn(DateTime due) => {
          ...contractJson(),
          'installments': [
            installmentJson(1, state: 'paid', paid: '275.00', status: 'paid'),
            {...installmentJson(2), 'due_date': iso(due)},
            {...installmentJson(3), 'due_date': iso(plusMonth(due))},
          ],
        };

    FakeServer planServer({Object? pattern = _absent, DateTime? due, Map<String, dynamic>? account}) => paydayServer(
          account: account ?? accountForPlans(),
          pattern: pattern,
          routes: {
            'GET /contracts/k1': always(json(200, {'data': contractDueOn(due ?? today().add(const Duration(days: 40)))})),
            'POST /contracts/k1/amendments/preview': always(json(200, {'data': {'old_schedule': <Object?>[], 'new_schedule': <Object?>[]}})),
          },
        );

    Future<void> openMove(WidgetTester tester) async {
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapButton(tester, 'Change the payment plan');
      await tapText(tester, 'Move one date');
    }

    testWidgets('the change-plan screen offers the same', (tester) async {
      final due = today().add(const Duration(days: 40));
      final server = planServer(pattern: payPatternJson(), due: due);
      await pumpApp(tester, server, size: const Size(412, 1500));
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapButton(tester, 'Change the payment plan');

      // Not on the other two options.
      expect(find.byKey(_suggestion), findsNothing);

      await tapText(tester, 'Move one date');

      final suggested = onOrAfter(due, 27);
      expect(find.text(formatDay(iso(due), 'en')), findsWidgets);
      expect(textOf(tester), due.day == 27 ? 'Ahmad Salem usually pays around day 27. This date is already their payday.' : 'Ahmad Salem usually pays around day 27. Use ${said(suggested)}?');
      expect(server.calls('POST /contracts/k1/amendments/preview'), 0);
      if (due.day == 27) return;

      await tapText(tester, 'Use it');

      expect(find.text(formatDay(iso(suggested), 'en')), findsOneWidget);
      expect(textOf(tester), 'Ahmad Salem usually pays around day 27. This date is already their payday.');
      await tapButton(tester, 'Preview');
      expect(sent(server, 'POST /contracts/k1/amendments/preview'), {'type': 'move', 'installment_id': 'i2', 'due_date': iso(suggested)});
    });

    testWidgets('an instalment already late is offered the next payday from today, never one in the past', (tester) async {
      final server = planServer(pattern: payPatternJson(), due: DateTime(2020, 1, 7));
      await pumpApp(tester, server, size: const Size(412, 1500));
      await openMove(tester);

      final now = today();
      final suggested = onOrAfter(now, 27);
      expect(textOf(tester), now.day == 27 ? 'Ahmad Salem usually pays around day 27. Use ${said(now)}?' : 'Ahmad Salem usually pays around day 27. Use ${said(suggested)}?');
      expect(find.text('Use it'), findsOneWidget);
    });

    group('shows nothing without a pattern or with the feature off', () {
      Map<String, dynamic> withoutTheFeature() {
        final account = accountForPlans();
        account['entitlements'] = {...Map<String, dynamic>.from(account['entitlements'] as Map)}..remove('payday_learning');

        return account;
      }

      for (final (why, build) in <(String, FakeServer Function())>[
        ('not enough to say yet', () => planServer(pattern: null)),
        ('the plan does not include it', () => planServer(pattern: payPatternJson(), account: accountForPlans(status: 'plan_locked'))),
        ('the server does not list the feature', () => planServer(pattern: payPatternJson(), account: withoutTheFeature())),
      ]) {
        testWidgets(why, (tester) async {
          await pumpApp(tester, build(), size: const Size(412, 1500));
          await openMove(tester);

          expect(find.text('Move one date'), findsOneWidget);
          expect(find.text('New date (to move one)'), findsOneWidget);
          expect(find.byKey(_suggestion), findsNothing);
        });
      }
    });

    // The contract's own page already reads the customer (for their phone), and Riverpod keeps that one page for both screens.
    for (final (status, phrase) in [('platform_off', 'with the feature off'), ('on', 'with the feature on')]) {
      testWidgets('$phrase the change-plan screen asks for no page of the customer of its own', (tester) async {
        final server = planServer(pattern: payPatternJson(), account: accountForPlans(status: status));
        await pumpApp(tester, server, size: const Size(412, 1500));
        await tapText(tester, 'Contracts', last: true);
        await tapText(tester, 'C-0007');
        final onTheContract = server.calls('GET /customers/c1');
        expect(onTheContract, 1);

        await tapButton(tester, 'Change the payment plan');
        await tapText(tester, 'Move one date');

        expect(server.calls('GET /customers/c1'), onTheContract);
        expect(find.byKey(_suggestion), status == 'on' ? findsOneWidget : findsNothing);
      });
    }
  });

  group('layout', () {
    for (final language in ['en', 'ar']) {
      testWidgets(language == 'ar' ? 'Arabic layout has no overflow at 200% text' : 'English layout has no overflow at 200% text', (tester) async {
        // The server writes the words in the language of the request; the sentence is the app's own.
        final label = language == 'ar' ? 'يدفع عادةً حوالي اليوم 27 من الشهر' : 'Usually pays around day 27 of the month';
        final basis = language == 'ar' ? 'لوحظ في 83% من الأشهر الأخيرة' : 'Seen in 83% of recent months';
        final name = language == 'ar' ? 'عبد الرحمن بن محمد بن عبد الله الغامدي الحربي' : 'Abdulrahman Mohammed Abdullah Al-Ghamdi Al-Harbi';
        final words = language == 'ar' ? arabic() : <String, dynamic>{};
        String word(String english) => (words[english] ?? english) as String;

        final due = today().add(const Duration(days: 40));
        final account = accountWithPayday();
        account['entitlements'] = {
          ...Map<String, dynamic>.from(account['entitlements'] as Map),
          'reschedule': {'type': 'toggle', 'enabled': true, 'status': 'on', 'limit': null, 'used': null, 'remaining': null, 'unlimited': false},
        };
        final server = paydayServer(account: account, pattern: payPatternJson(label: label, basis: basis), name: name, routes: {
          'GET /contracts/k1': always(json(200, {
            'data': {
              ...contractJson(),
              'customer': {'id': 'c1', 'name': name},
              'installments': [
                installmentJson(1, state: 'paid', paid: '275.00', status: 'paid'),
                {...installmentJson(2), 'due_date': iso(due)},
              ],
            },
          })),
        });
        await pumpApp(tester, server, language: language, size: const Size(360, 640), textScale: 2);
        final router = GoRouter.of(tester.element(find.byType(Scaffold).first));

        // The customer's page, with the chip.
        router.go('/customers/c1');
        await settle(tester);
        expect(find.byKey(_chip), findsOneWidget);
        expect(find.text(label), findsOneWidget);
        expect(tester.takeException(), isNull, reason: 'overflow on the customer page');

        // A new contract, with the offer under the first due date.
        router.go('/contracts/new?customer=c1');
        await settle(tester);
        await tester.ensureVisible(find.byKey(_suggestion));
        await tester.pump();
        expect(find.byKey(_suggestion), findsOneWidget);
        expect(find.byKey(_useIt), findsOneWidget);
        expect(tester.getSize(find.byKey(_useIt)).height, greaterThanOrEqualTo(48));
        expect(textOf(tester), contains(name));
        expect(tester.takeException(), isNull, reason: 'overflow on the contract form');
        // The sentence and the button sit inside the screen.
        expect(tester.getRect(find.byKey(_suggestion)).right, lessThanOrEqualTo(360));
        expect(tester.getRect(find.byKey(_suggestion)).left, greaterThanOrEqualTo(0));

        // Move one date.
        router.go('/contracts/k1/plan');
        await settle(tester);
        await tapText(tester, word('Move one date'));
        await tester.ensureVisible(find.byKey(_suggestion));
        await tester.pump();
        expect(find.byKey(_suggestion), findsOneWidget);
        expect(tester.takeException(), isNull, reason: 'overflow on the change-plan screen');
      });
    }
  });
}
