import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:qistas/core/l10n/formats.dart';
import 'package:qistas/core/l10n/hijri.dart';
import 'package:qistas/data/models.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// Payday learning (daily-habit wave A, H6) on the phone: the chip on a customer's page, and the offer of their usual payday
/// under the first-due date of a new contract and under the date of "move one date". The offer changes nothing by itself.
///
/// The app's day is fixed by a [FakeClock] in every test, so each one runs the same on every day of the year; the days that
/// matter (the pattern's own day, the day after it, the 31st) each have a test of their own.

/// Left out of an answer altogether, as the server does with the feature off.
const _absent = Object();

/// An ordinary day for the app: a new contract starts a month later, on Saturday 14 November 2026.
final DateTime _ordinary = DateTime(2026, 10, 14, 9);

/// The nbsp the sentence keeps a date in one piece with.
const _nbsp = '\u00a0';

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

/// Starts the app on the day [on] (an ordinary day when not given).
Future<void> start(
  WidgetTester tester,
  FakeServer server, {
  DateTime? on,
  Size size = const Size(412, 1500),
  String language = 'en',
  double textScale = 1,
  bool realFonts = false,
}) =>
    pumpApp(tester, server, clock: FakeClock(on ?? _ordinary), size: size, language: language, textScale: textScale, realFonts: realFonts);

/// The first-due date a new contract starts with: a month after the day, the 31st clamped, as the form does.
DateTime plusMonth(DateTime day) {
  final lastOfNext = DateTime(day.year, day.month + 2, 0).day;

  return DateTime(day.year, day.month + 1, day.day > lastOfNext ? lastOfNext : day.day);
}

/// The first day on or after [from] that is the [day] of a month, worked out here by hand (the app's rule is not used to
/// say what the rule should give).
DateTime onOrAfter(DateTime from, int day) => from.day <= day ? DateTime(from.year, from.month, day) : DateTime(from.year, from.month + 1, day);

DateTime lastOfMonth(DateTime from) => DateTime(from.year, from.month + 1, 0);

/// A date as the sentence says it with Hijri dates off: the app's own date, in one piece.
String said(DateTime day, [String language = 'en']) => formatMoment(day, language).replaceAll(RegExp(r'\s'), _nbsp);

String iso(DateTime day) => isoDay(day);

Map<String, dynamic> arabic() => jsonDecode(File('assets/i18n/ar.json').readAsStringSync()) as Map<String, dynamic>;

const _suggestion = ValueKey('pay-suggestion');
const _suggestionText = ValueKey('pay-suggestion-text');
const _useIt = ValueKey('pay-suggestion-use');
const _chip = ValueKey('pay-chip');

String textOf(WidgetTester tester) => tester.widget<Text>(find.byKey(_suggestionText)).data!;

String useSentence(String name, int day, DateTime date) => '$name usually pays around day $day. Use ${said(date)}?';

String onPaydaySentence(String name, int day) => '$name usually pays around day $day. This date is already their payday.';

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

/// How many lines a text is drawn on: the rows its letters sit on.
int linesOf(WidgetTester tester, String text) {
  final paragraph = tester.renderObject<RenderParagraph>(find.text(text));
  final boxes = paragraph.getBoxesForSelection(TextSelection(baseOffset: 0, extentOffset: text.length));

  return {for (final box in boxes) box.top.round()}.length;
}

Map<String, dynamic> toggle(String status) => {'type': 'toggle', 'enabled': status == 'on', 'status': status, 'limit': null, 'used': null, 'remaining': null, 'unlimited': false};

void main() {
  tearDown(() => HijriDisplay.mode = 'off');

  group('the customer screen', () {
    testWidgets('the customer screen shows the chip', (tester) async {
      await start(tester, paydayServer(pattern: payPatternJson()));
      await openCustomer(tester);

      expect(find.byKey(_chip), findsOneWidget);
      expect(find.text('Usually pays around day 27 of the month'), findsOneWidget);
      expect(find.text('Seen in 83% of recent months'), findsOneWidget);
    });

    testWidgets('says it at the end of the month as the server worded it, and builds no wording of its own', (tester) async {
      await start(tester, paydayServer(pattern: payPatternJson(endOfMonth: true, within: 71, label: 'Pays when the month ends', basis: 'Most months')));
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
      await start(tester, server);
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
      await start(tester, server);
      await openForm(tester);

      // Today is 14 October: the new contract's first instalment starts on 14 November, and their day is the 27th.
      final first = DateTime(2026, 11, 14);
      final suggested = DateTime(2026, 11, 27);
      expect(plusMonth(DateTime(2026, 10, 14)), first);
      expect(find.byKey(_suggestion), findsOneWidget);
      expect(textOf(tester), useSentence('Ahmad Salem', 27, suggested));
      // Offered, not applied: the field still holds the month-later date it started with.
      expect(find.text(formatMoment(first, 'en')), findsOneWidget);
      expect(find.text(formatMoment(suggested, 'en')), findsNothing);

      await tapText(tester, 'Use it');

      expect(find.text(formatMoment(suggested, 'en')), findsOneWidget);
      expect(find.text(formatMoment(first, 'en')), findsNothing);
      // The date now is their payday, so the offer says so and the button goes.
      expect(textOf(tester), onPaydaySentence('Ahmad Salem', 27));
      expect(find.text('Use it'), findsNothing);
      expect(server.calls('POST /contracts'), 0);
    });

    testWidgets('offers next month’s payday once the day has passed', (tester) async {
      // 30 October: the first instalment would start on 30 November, after the 27th.
      await start(tester, paydayServer(pattern: payPatternJson()), on: DateTime(2026, 10, 30, 9));
      await openForm(tester);

      expect(find.text(formatMoment(DateTime(2026, 11, 30), 'en')), findsOneWidget);
      expect(textOf(tester), useSentence('Ahmad Salem', 27, DateTime(2026, 12, 27)));

      await tapText(tester, 'Use it');

      expect(find.text(formatMoment(DateTime(2026, 12, 27), 'en')), findsOneWidget);
      expect(textOf(tester), onPaydaySentence('Ahmad Salem', 27));
    });

    testWidgets('says so, and has no button, when the first due date already falls on their payday', (tester) async {
      // 27 October: the first instalment would start on 27 November.
      await start(tester, paydayServer(pattern: payPatternJson()), on: DateTime(2026, 10, 27, 9));
      await openForm(tester);

      expect(find.text(formatMoment(DateTime(2026, 11, 27), 'en')), findsOneWidget);
      expect(textOf(tester), onPaydaySentence('Ahmad Salem', 27));
      expect(find.byKey(_useIt), findsNothing);
      expect(find.text('Use it'), findsNothing);
    });

    testWidgets('from the 31st of January the first due date is the 28th of February, and the offer is the 27th of March', (tester) async {
      await start(tester, paydayServer(pattern: payPatternJson()), on: DateTime(2027, 1, 31, 9));
      await openForm(tester);

      expect(find.text(formatMoment(DateTime(2027, 2, 28), 'en')), findsOneWidget);
      expect(textOf(tester), useSentence('Ahmad Salem', 27, DateTime(2027, 3, 27)));

      await tapText(tester, 'Use it');

      expect(find.text(formatMoment(DateTime(2027, 3, 27), 'en')), findsOneWidget);
    });

    testWidgets('sends the contract on the day that was offered, and the schedule preview follows it', (tester) async {
      final server = paydayServer(pattern: payPatternJson(day: 3));
      await start(tester, server, size: const Size(412, 1800));
      await openForm(tester);
      await typeInto(tester, 'Sale price (SAR)', '1200');
      final first = DateTime(2026, 11, 14);
      final suggested = DateTime(2026, 12, 3);
      // The preview's first row is on the field's date, and nothing has moved it.
      expect(find.text(formatMoment(first, 'en')), findsNWidgets(2));
      expect(find.text(formatMoment(suggested, 'en')), findsNothing);

      await tapText(tester, 'Use it');

      expect(find.text(formatMoment(first, 'en')), findsNothing);
      expect(find.text(formatMoment(suggested, 'en')), findsNWidgets(2));
      await tapButton(tester, 'Open contract');

      expect(sent(server, 'POST /contracts')['first_due_date'], '2026-12-03');
    });

    testWidgets('sends the contract on the day it was set, when nobody pressed the button', (tester) async {
      final server = paydayServer(pattern: payPatternJson(day: 3));
      await start(tester, server, size: const Size(412, 1800));
      await openForm(tester);
      await typeInto(tester, 'Sale price (SAR)', '1200');

      await tapButton(tester, 'Open contract');

      expect(sent(server, 'POST /contracts')['first_due_date'], '2026-11-14');
    });

    testWidgets('end of the month: offers the last day of the month, in words that fit', (tester) async {
      await start(tester, paydayServer(pattern: payPatternJson(endOfMonth: true)));
      await openForm(tester);

      final suggested = lastOfMonth(DateTime(2026, 11, 14));
      expect(suggested, DateTime(2026, 11, 30));
      expect(textOf(tester), 'Ahmad Salem usually pays at the end of the month. Use ${said(suggested)}?');

      await tapText(tester, 'Use it');

      expect(find.text(formatMoment(suggested, 'en')), findsOneWidget);
      expect(textOf(tester), 'Ahmad Salem usually pays at the end of the month. This date is already their payday.');
    });

    testWidgets('end of the month: nothing to offer when the first due date is already the last day', (tester) async {
      await start(tester, paydayServer(pattern: payPatternJson(endOfMonth: true)), on: DateTime(2026, 10, 30, 9));
      await openForm(tester);

      expect(find.text(formatMoment(DateTime(2026, 11, 30), 'en')), findsOneWidget);
      expect(textOf(tester), 'Ahmad Salem usually pays at the end of the month. This date is already their payday.');
      expect(find.text('Use it'), findsNothing);
    });

    testWidgets('picking another date changes only the sentence, never the date they picked', (tester) async {
      final server = paydayServer(pattern: payPatternJson());
      await start(tester, server);
      await openForm(tester);
      final first = DateTime(2026, 11, 14);

      await tapText(tester, formatMoment(first, 'en'));
      await tester.tap(find.text('3').last);
      await tester.pump();
      await tester.tap(find.text('OK'));
      await settle(tester);

      final picked = DateTime(2026, 11, 3);
      expect(find.text(formatMoment(picked, 'en')), findsOneWidget);
      expect(textOf(tester), useSentence('Ahmad Salem', 27, DateTime(2026, 11, 27)));
      // The sentence moved on; the date they picked is still the one in the field.
      expect(find.text(formatMoment(picked, 'en')), findsOneWidget);
      expect(find.text(formatMoment(DateTime(2026, 11, 27), 'en')), findsNothing);
    });

    testWidgets('the whole name is used, and a name that looks like a placeholder is left as it is', (tester) async {
      final server = paydayServer(pattern: payPatternJson(), name: 'Ali :day :date Abdulrahman Mohammed Al-Ghamdi');
      await start(tester, server, size: const Size(360, 1500));
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'New contract');
      await tapText(tester, 'Choose a customer');
      await tapText(tester, 'Ali :day :date Abdulrahman Mohammed Al-Ghamdi');

      expect(textOf(tester), useSentence('Ali :day :date Abdulrahman Mohammed Al-Ghamdi', 27, DateTime(2026, 11, 27)));
    });

    testWidgets('a cash sale and the shop’s own dates have no first-due date to suggest for', (tester) async {
      final account = accountWithPayday();
      account['entitlements'] = {...Map<String, dynamic>.from(account['entitlements'] as Map), 'flexible_schedules': toggle('on')};
      await start(tester, paydayServer(pattern: payPatternJson(), account: account), size: const Size(412, 1800));
      await openForm(tester);
      expect(find.byKey(_suggestion), findsOneWidget);

      await tapText(tester, 'My own dates');
      expect(find.byKey(_suggestion), findsNothing);

      await tapText(tester, 'Cash sale');
      expect(find.byKey(_suggestion), findsNothing);
    });

    testWidgets('a screen reader hears the button and can press it', (tester) async {
      final handle = tester.ensureSemantics();
      await start(tester, paydayServer(pattern: payPatternJson(day: 3)));
      await openForm(tester);
      final suggested = DateTime(2026, 12, 3);

      final button = find.semantics.byLabel('Use it');
      expect(button, findsOneWidget);
      final data = button.evaluate().single.getSemanticsData();
      expect(data.label, 'Use it');
      expect(data.hint, formatMoment(suggested, 'en'));
      expect(data.hasAction(SemanticsAction.tap), isTrue);
      expect(data.flagsCollection.isButton, isTrue);
      expect(tester.getSize(find.byKey(_useIt)).height, greaterThanOrEqualTo(48));

      tester.semantics.tap(button);
      await settle(tester);

      expect(find.text(formatMoment(suggested, 'en')), findsOneWidget);
      expect(find.text('Use it'), findsNothing);
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
          await start(tester, paydayServer(account: account, pattern: pattern));
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
      await start(tester, server);
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
          await start(tester, paydayServer(pattern: broken));
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
      await start(tester, server);
      await openForm(tester);
      await typeInto(tester, 'Sale price (SAR)', '1200');
      await typeInto(tester, 'Down payment (optional)', '100');

      expect(server.calls('GET /customers/c1'), 0);
      expect(server.calls('GET /customers'), greaterThan(0));
    });

    testWidgets('with the feature on the form asks for the customer’s page once, however much is typed', (tester) async {
      final server = paydayServer(pattern: payPatternJson());
      await start(tester, server);
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
      account['entitlements'] = {...Map<String, dynamic>.from(account['entitlements'] as Map), 'reschedule': toggle('on')};

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
            'GET /contracts/k1': always(json(200, {'data': contractDueOn(due ?? DateTime(2026, 11, 23))})),
            'POST /contracts/k1/amendments/preview': always(json(200, {'data': {'old_schedule': <Object?>[], 'new_schedule': <Object?>[]}})),
          },
        );

    Future<void> openPlan(WidgetTester tester) async {
      await tapText(tester, 'Contracts', last: true);
      await tapText(tester, 'C-0007');
      await tapButton(tester, 'Change the payment plan');
    }

    Future<void> openMove(WidgetTester tester) async {
      await openPlan(tester);
      await tapText(tester, 'Move one date');
    }

    testWidgets('the change-plan screen offers the same', (tester) async {
      final due = DateTime(2026, 11, 23);
      final server = planServer(pattern: payPatternJson(), due: due);
      await start(tester, server);
      await openPlan(tester);

      // Not on the other two options.
      expect(find.byKey(_suggestion), findsNothing);

      await tapText(tester, 'Move one date');

      final suggested = DateTime(2026, 11, 27);
      expect(find.text(formatDay(iso(due), 'en')), findsWidgets);
      expect(textOf(tester), useSentence('Ahmad Salem', 27, suggested));
      expect(server.calls('POST /contracts/k1/amendments/preview'), 0);

      await tapText(tester, 'Use it');

      expect(find.text(formatDay(iso(suggested), 'en')), findsOneWidget);
      expect(find.text(formatDay(iso(due), 'en')), findsNothing);
      expect(textOf(tester), onPaydaySentence('Ahmad Salem', 27));
      await tapButton(tester, 'Preview');
      expect(sent(server, 'POST /contracts/k1/amendments/preview'), {'type': 'move', 'installment_id': 'i2', 'due_date': '2026-11-27'});
    });

    testWidgets('says so, and has no button, when the date already is their payday', (tester) async {
      await start(tester, planServer(pattern: payPatternJson(), due: DateTime(2026, 11, 27)));
      await openMove(tester);

      expect(textOf(tester), onPaydaySentence('Ahmad Salem', 27));
      expect(find.text('Use it'), findsNothing);
    });

    group('an instalment already late is offered the next payday from today, never one in the past', () {
      for (final (today, payday) in [
        (DateTime(2026, 10, 14, 9), DateTime(2026, 10, 27)),
        // On their payday itself: today can still be chosen.
        (DateTime(2026, 10, 27, 9), DateTime(2026, 10, 27)),
        (DateTime(2026, 10, 28, 9), DateTime(2026, 11, 27)),
      ]) {
        testWidgets('on ${iso(today)}', (tester) async {
          final server = planServer(pattern: payPatternJson(), due: DateTime(2020, 1, 7));
          await start(tester, server, on: today);
          await openMove(tester);

          expect(textOf(tester), useSentence('Ahmad Salem', 27, payday));
          expect(find.text('Use it'), findsOneWidget);

          await tapText(tester, 'Use it');
          await tapButton(tester, 'Preview');

          expect(sent(server, 'POST /contracts/k1/amendments/preview')['due_date'], iso(payday));
        });
      }
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
          await start(tester, build());
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
        await start(tester, server);
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

    // The two date rows keep their words on one line at the normal text size, in the brand font, on the usual phone widths: the
    // date beside the label takes the room it needs and no more, so the label gets all the rest (a row of two flexible children
    // gave each half, and wrapped the label on every phone). A date as wide as "Nov 23, 2026" leaves a 360 dp phone too little for
    // this label whatever the layout; the instalment here is due on 7 December, as in the sample contract.
    group('the date rows keep their label on one line at normal text size', () {
      for (final width in [360.0, 390.0, 412.0]) {
        testWidgets('${width.toInt()} dp wide', (tester) async {
          await start(tester, planServer(pattern: payPatternJson(), due: DateTime(2026, 12, 7)), size: Size(width, 1500), realFonts: true);
          await openPlan(tester);

          expect(linesOf(tester, 'First new instalment'), 1);

          await tapText(tester, 'Move one date');

          expect(linesOf(tester, 'New date (to move one)'), 1);
          expect(tester.takeException(), isNull);
        });
      }
    });
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

        final account = accountWithPayday();
        account['entitlements'] = {...Map<String, dynamic>.from(account['entitlements'] as Map), 'reschedule': toggle('on')};
        final server = paydayServer(account: account, pattern: payPatternJson(label: label, basis: basis), name: name, routes: {
          'GET /contracts/k1': always(json(200, {
            'data': {
              ...contractJson(),
              'customer': {'id': 'c1', 'name': name},
              'installments': [
                installmentJson(1, state: 'paid', paid: '275.00', status: 'paid'),
                {...installmentJson(2), 'due_date': '2026-12-07'},
              ],
            },
          })),
        });
        await start(tester, server, language: language, size: const Size(360, 640), textScale: 2);
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
        // The whole name, the day, and the date in the language of the app: no placeholder is left over.
        final sentence = textOf(tester);
        expect(sentence, contains(name));
        expect(sentence, contains('27'));
        expect(sentence, contains(said(DateTime(2026, 11, 27), language)));
        expect(sentence, isNot(matches(RegExp(r':(name|day|date)'))));
        if (language == 'ar') expect(sentence, contains('27 نوفمبر 2026'.replaceAll(' ', _nbsp)));
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
        final moveSentence = textOf(tester);
        expect(moveSentence, contains(name));
        expect(moveSentence, isNot(matches(RegExp(r':(name|day|date)'))));
        expect(tester.takeException(), isNull, reason: 'overflow on the change-plan screen');
      });
    }

    // With Hijri dates on the date reads "27 Nov 2026 (17 Jumada al-Ula 1448 AH)": only the Gregorian part is kept in one piece,
    // so the Hijri words can still break at 200% text instead of one unbreakable run being cut mid-word.
    for (final mode in ['beside', 'first']) {
      testWidgets('with Hijri dates $mode the sentence breaks at the Hijri words and nothing overflows at 200% text', (tester) async {
        final account = accountWithPayday();
        account['tenant'] = {...Map<String, dynamic>.from(account['tenant'] as Map), 'hijri_dates': mode};
        await start(tester, paydayServer(account: account, pattern: payPatternJson()), size: const Size(360, 640), textScale: 2);
        GoRouter.of(tester.element(find.byType(Scaffold).first)).go('/contracts/new?customer=c1');
        await settle(tester);
        await tester.ensureVisible(find.byKey(_suggestion));
        await tester.pump();

        final hijri = HijriDate.fromGregorian(DateTime(2026, 11, 27))!.format('en');
        final gregorian = 'Nov 27, 2026'.replaceAll(' ', _nbsp);
        final sentence = textOf(tester);
        expect(sentence, contains(mode == 'beside' ? '$gregorian ($hijri)?' : '$hijri ($gregorian)?'));
        // The Hijri date's own spaces are ordinary ones, and so is the space before the bracket.
        expect(hijri, contains(' '));
        expect(sentence, isNot(contains('$_nbsp(')));
        expect(sentence, isNot(contains('($_nbsp')));
        expect(tester.takeException(), isNull, reason: 'overflow with Hijri dates $mode');
        expect(tester.getRect(find.byKey(_suggestion)).right, lessThanOrEqualTo(360));

        await tapText(tester, 'Use it');
        expect(textOf(tester), contains('already their payday'));
        expect(tester.takeException(), isNull);
      });
    }
  });
}
