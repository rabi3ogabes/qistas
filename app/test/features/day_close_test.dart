import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart' hide Route;
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/router.dart';
import 'package:qistas/core/design/widgets.dart' show QButton, QErrorView;
import 'package:qistas/core/l10n/formats.dart';
import 'package:qistas/core/money.dart';
import 'package:qistas/core/push/push_service.dart';
import 'package:qistas/features/day_close/day_close_screen.dart' show DaySeal, DayClosedSheet;
import 'package:qistas/features/day_close/day_close_state.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The end of the day on the owner's phone (daily-habit wave A, H4): count the till on a number pad, read the difference
/// in plain words, close, share the sentence, and for an owner or a manager reopen. Every figure is the server's; the
/// difference under the pad is only a hint, worked out in whole cents.

const tall = Size(412, 1800);

/// The server, with a day that closes: `GET /day-close/today` follows what `POST /day-close` and the reopening did.
class Day {
  Day({this.role = 'owner', this.closed = false, this.canReopen = true, this.expected = '3100.00', this.reopened = false});

  final String role;
  bool closed;
  bool reopened;
  final bool canReopen;
  final String expected;

  Map<String, dynamic> get today => dayCloseTodayJson(
        expected: expected,
        closed: closed,
        close: closed ? dayCloseJson(expected: expected) : (reopened ? dayCloseJson(expected: expected, status: 'reopened', reopenReason: 'Wrong count') : null),
        canClose: role != 'viewer' && !closed,
        canReopen: closed && canReopen,
      );
}

FakeServer dayServer(Day day, {Map<String, Route> routes = const {}, Map<String, dynamic>? account}) => workspaceServer(
      account: account ?? accountWithDayClose(role: day.role),
      routes: {
        'GET /day-close/today': (_) => json(200, {'data': day.today}),
        'POST /day-close': (_) {
          day.closed = true;

          return json(201, {'data': dayCloseJson(expected: day.expected)});
        },
        'POST /day-close/dc1/reopen': (_) {
          day.closed = false;
          day.reopened = true;

          return json(200, {'data': dayCloseJson(status: 'reopened', reopenReason: 'Wrong count')});
        },
        'GET /day-close': always(json(200, pageJson([
          dayCloseJson(),
          dayCloseJson(id: 'dc0', day: '2026-10-20', counted: '2980.00', expected: '3000.00', difference: '-20.00', closedBy: null, summary: 'Al-Fares Electronics, 20 Oct: collected SAR 3,000, 9 payments, short by SAR 20.'),
          dayCloseJson(id: 'dc9', day: '2026-10-19', status: 'reopened', reopenReason: 'Wrong count'),
        ], last: 1, total: 3))),
        'POST /push-tokens': always(json(201, {'data': {'id': 'p1', 'platform': 'android'}})),
        ...routes,
      },
    );

Future<void> openDayClose(WidgetTester tester) async {
  await tester.ensureVisible(find.byKey(const ValueKey('day-close-tile')));
  await tester.pump();
  await tester.tap(find.byKey(const ValueKey('day-close-tile')));
  await settle(tester);
}

/// Presses the keys of the number pad: digits, "." and "<" (delete).
Future<void> enter(WidgetTester tester, String keys) async {
  for (final key in keys.split('')) {
    final pad = find.byKey(ValueKey(switch (key) { '.' => 'pad-point', '<' => 'pad-delete', _ => 'pad-$key' }));
    await tester.ensureVisible(pad);
    await tester.pump();
    await tester.tap(pad);
    await tester.pump();
  }
}

Finder inHint(String text) => find.descendant(of: find.byKey(const ValueKey('difference-hint')), matching: find.text(text));

Finder inAmount(String text) => find.descendant(of: find.byKey(const ValueKey('counted-amount')), matching: find.text(text));

final closeButton = find.byKey(const ValueKey('close-day-button'));

Map<String, dynamic> body(FakeServer server, String route) => jsonDecode(server.requestsTo(route).last.data as String) as Map<String, dynamic>;

class FakePush implements PushService {
  final tapped = StreamController<String>.broadcast();

  @override
  Future<String?> start() async => 'fcm-token';

  @override
  Stream<String> get tokenRefresh => const Stream.empty();

  @override
  Stream<String> get taps => tapped.stream;

  @override
  Stream<void> get arrivals => const Stream.empty();
}

void main() {
  group('the cash to count', () {
    testWidgets('it shows the expected cash and the other payments', (tester) async {
      final server = dayServer(Day());
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      expect(find.text('Expected cash'), findsOneWidget);
      expect(find.text('SAR 3,100.00'), findsOneWidget);
      expect(find.text('Count the notes and coins in the till and enter the total.'), findsOneWidget);

      // Other payments: the card line with its note, and a void, which is its own negative line.
      expect(find.text('Other payments'), findsOneWidget);
      expect(find.text('Noura Al-Saif'), findsOneWidget);
      expect(find.text('Card · Ref 7731'), findsOneWidget);
      expect(find.text('SAR 50.00'), findsOneWidget);
      expect(find.text('Badr Hamdan'), findsOneWidget);
      expect(find.text('Payment voided'), findsNWidgets(2)); // in the other payments, and as something worth a look
      expect(find.text('-SAR 20.00'), findsOneWidget);

      // Worth a look: what the server flagged, with the amount where it has one.
      expect(find.text('Worth a look'), findsOneWidget);
      expect(find.text('Youssef Mansour'), findsOneWidget);
      expect(find.text('SAR 75.00'), findsOneWidget);
      expect(find.text('Sara Idris'), findsOneWidget);
      expect(find.text('Contract opened'), findsOneWidget);

      expect(server.calls('GET /day-close/today'), 2); // the tile on Today, and the screen, which always reads the day afresh
    });

    testWidgets('a day with nothing in it says so', (tester) async {
      final server = dayServer(Day(), routes: {
        'GET /day-close/today': always(json(200, {'data': {...dayCloseTodayJson(expected: '0.00', nonCash: [], watch: []), 'by_method': <String, dynamic>{}, 'count': 0, 'per_member': <String, dynamic>{}}})),
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      expect(find.text('Nothing collected yet today.'), findsOneWidget);
      expect(find.text('No other payments recorded for this day.'), findsOneWidget);
      expect(find.text('Nothing out of the ordinary on this day.'), findsOneWidget);
    });

    testWidgets('it shows the difference with the right wording as digits are typed', (tester) async {
      final server = dayServer(Day());
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      // Nothing typed yet: no difference to speak of, and nothing to close with.
      expect(find.byKey(const ValueKey('difference-hint')), findsNothing);
      expect(tester.widget<FilledButton>(find.descendant(of: closeButton, matching: find.byType(FilledButton))).onPressed, isNull);

      await enter(tester, '3100');
      expect(inAmount('3,100'), findsOneWidget);
      expect(inHint('Balanced'), findsOneWidget);

      await enter(tester, '<<');
      expect(inAmount('31'), findsOneWidget);
      expect(inHint('Short by SAR 3,069.00'), findsOneWidget);

      await enter(tester, '<<');
      await enter(tester, '3125.5');
      expect(inAmount('3,125.5'), findsOneWidget);
      expect(inHint('Over by SAR 25.50'), findsOneWidget);

      // Only two decimals, and a lone zero is not the start of a number.
      await enter(tester, '99');
      expect(inAmount('3,125.59'), findsOneWidget);
      expect(inHint('Over by SAR 25.59'), findsOneWidget);

      // The hint is a hint: nothing was sent to the server to work it out.
      expect(server.calls('POST /day-close'), 0);
    });

    testWidgets('the difference is exact to the cent', (tester) async {
      final server = dayServer(Day(expected: '0.30'));
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      await enter(tester, '0.1');
      expect(inHint('Short by SAR 0.20'), findsOneWidget); // 0.1 - 0.3 is not 0.19999999999999998 here

      await enter(tester, '<<<<');
      await enter(tester, '0.3');
      expect(inHint('Balanced'), findsOneWidget);
    });

    testWidgets('"No cash today" counts zero, and the difference says what that means', (tester) async {
      await pumpApp(tester, dayServer(Day()), size: tall);
      await openDayClose(tester);

      await tapButton(tester, 'No cash today');

      expect(inAmount('0'), findsOneWidget);
      expect(inHint('Short by SAR 3,100.00'), findsOneWidget);
    });

    testWidgets('the number pad keys say what they are to a screen reader and are big enough to hit', (tester) async {
      final handle = tester.ensureSemantics();
      await pumpApp(tester, dayServer(Day()), size: tall);
      await openDayClose(tester);

      for (final label in ['1', '5', '0', 'Decimal point', 'Delete the last digit']) {
        expect(find.semantics.byLabel(label), findsOneWidget, reason: 'no key called "$label" for a screen reader');
      }
      for (final key in ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', 'point', 'delete']) {
        final size = tester.getSize(find.byKey(ValueKey('pad-$key')));
        expect(size.width, greaterThanOrEqualTo(48), reason: 'key $key is too narrow');
        expect(size.height, greaterThanOrEqualTo(48), reason: 'key $key is too short');
      }
      handle.dispose();
    });
  });

  group('closing the day', () {
    testWidgets('Close the day calls the server once even on a double tap', (tester) async {
      final gate = Completer<void>();
      final day = Day();
      final server = dayServer(day, routes: {
        'POST /day-close': (_) async {
          await gate.future;
          day.closed = true;

          return json(201, {'data': dayCloseJson()});
        },
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);
      await enter(tester, '3100');

      // Two taps in the same frame, then a third once the screen has seen the first.
      await tester.tap(closeButton);
      await tester.tap(closeButton);
      await tester.pump();
      await tester.tap(closeButton);
      await tester.pump();
      expect(find.descendant(of: closeButton, matching: find.byType(CircularProgressIndicator)), findsOneWidget);

      gate.complete();
      await settle(tester);

      expect(server.calls('POST /day-close'), 1);
    });

    testWidgets('it sends the counted cash as exact text, with the day shown, the note and a key to retry with', (tester) async {
      final server = dayServer(Day());
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      await enter(tester, '3100.5');
      await tester.enterText(find.byType(TextField), 'All counted twice');
      await tester.pump();
      await tester.tap(closeButton);
      await settle(tester);

      expect(body(server, 'POST /day-close'), {'counted_cash': '3100.50', 'note': 'All counted twice', 'day': '2026-10-21'});
      expect(server.requestsTo('POST /day-close').single.headers['Idempotency-Key'], matches(RegExp(r'^app-[0-9a-f]{24}$')));
    });

    testWidgets('a retry after a lost answer carries the same key', (tester) async {
      final server = dayServer(Day());
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);
      await enter(tester, '3100');

      server.offline = true;
      await tester.tap(closeButton);
      await settle(tester);
      expect(find.text('No connection. Check your internet and try again.'), findsOneWidget);

      server.offline = false;
      await tester.tap(closeButton);
      await settle(tester);

      final keys = [for (final request in server.requestsTo('POST /day-close')) request.headers['Idempotency-Key']];
      expect(keys, hasLength(2));
      expect(keys.first, keys.last);
    });

    testWidgets('a different amount after a refusal is a new attempt with a new key', (tester) async {
      final server = dayServer(Day(), routes: {
        'POST /day-close': always(apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {'fields': {'counted_cash': ['The counted cash cannot be negative.']}})),
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      await enter(tester, '3100');
      await tester.tap(closeButton);
      await settle(tester);
      await enter(tester, '5');
      await tester.tap(closeButton);
      await settle(tester);

      final keys = [for (final request in server.requestsTo('POST /day-close')) request.headers['Idempotency-Key']];
      expect(keys, hasLength(2));
      expect(keys.first, isNot(keys.last));
    });

    testWidgets('after closing there is a seal, a medium tap of the phone, and the server’s own sentence to share', (tester) async {
      final haptics = <Object?>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'HapticFeedback.vibrate') haptics.add(call.arguments);

        return null;
      });
      addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));

      final shared = <(String, String)>[];
      final day = Day();
      await pumpApp(tester, dayServer(day), size: tall, overrides: [
        textSharerProvider.overrideWithValue((text, subject) async => shared.add((text, subject))),
      ]);
      await openDayClose(tester);
      await enter(tester, '3100');
      await tester.tap(closeButton);
      await settle(tester);

      const sentence = 'Al-Fares Electronics, 21 Oct: collected SAR 3,150 (cash 3,100, other 50), 14 payments, difference 0.';
      final moment = find.byType(DayClosedSheet);
      expect(find.byKey(const ValueKey('closed-seal')), findsOneWidget);
      expect(find.descendant(of: moment, matching: find.text('The day is closed.')), findsOneWidget);
      expect(find.descendant(of: moment, matching: find.text('Balanced')), findsOneWidget);
      expect(find.descendant(of: moment, matching: find.text(sentence)), findsOneWidget);
      expect(haptics, contains('HapticFeedbackType.mediumImpact'));

      // The share sheet gets the sentence exactly as the server wrote it.
      await tester.tap(find.descendant(of: moment, matching: find.widgetWithText(QButton, 'Share')));
      await settle(tester);
      expect(shared, [(sentence, 'Day close')]);

      await tester.tap(find.descendant(of: moment, matching: find.widgetWithText(QButton, 'Done')));
      await settle(tester);
      expect(find.byKey(const ValueKey('closed-seal')), findsNothing);
      expect(find.byKey(const ValueKey('day-seal')), findsOneWidget); // the screen behind now shows a closed day
      expect(find.byKey(const ValueKey('pad-1')), findsNothing);
    });

    testWidgets('a 422 on the counted cash is written next to the amount, and the button is ready again', (tester) async {
      final server = dayServer(Day(), routes: {
        'POST /day-close': always(apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {'fields': {'counted_cash': ['The amount can have at most two decimals.']}})),
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      await enter(tester, '3100');
      await tester.tap(closeButton);
      await settle(tester);

      final error = find.byKey(const ValueKey('counted-error'));
      expect(error, findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('counted-amount')).first, matching: find.text('3,100')), findsOneWidget);
      expect(tester.widget<Text>(error).data, 'The amount can have at most two decimals.');
      // It sits under the amount, not in the bar at the foot of the screen.
      expect(tester.getTopLeft(error).dy, greaterThan(tester.getBottomLeft(find.byKey(const ValueKey('counted-amount'))).dy - 1));
      expect(tester.getTopLeft(error).dy, lessThan(tester.getTopLeft(closeButton).dy));
      expect(find.byKey(const ValueKey('close-problem')), findsNothing);
      expect(find.descendant(of: closeButton, matching: find.byType(CircularProgressIndicator)), findsNothing);

      // Typing again clears the complaint.
      await enter(tester, '<');
      expect(error, findsNothing);
    });

    testWidgets('a day that was closed from another phone says so above the button, and the screen looks again', (tester) async {
      final day = Day();
      final server = dayServer(day, routes: {
        'POST /day-close': (_) {
          day.closed = true;

          return apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {'fields': {'day': ['This day is already closed.']}});
        },
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);
      await enter(tester, '3100');
      await tester.tap(closeButton);
      await settle(tester);

      // The other phone's close is what the screen shows now, with the refusal at its head.
      expect(find.text('This day is already closed.'), findsOneWidget);
      expect(find.byKey(const ValueKey('day-seal')), findsOneWidget);
      expect(find.byKey(const ValueKey('pad-1')), findsNothing);
    });

    testWidgets('no connection while closing shows the standard message and queues nothing', (tester) async {
      final server = dayServer(Day());
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);
      await enter(tester, '3100');

      server.offline = true;
      await tester.tap(closeButton);
      await settle(tester);

      expect(find.text('No connection. Check your internet and try again.'), findsOneWidget);
      expect(server.calls('POST /day-close'), 1);

      // Nothing waits in the background to be sent later.
      server.offline = false;
      await settle(tester, frames: 60);
      expect(server.calls('POST /day-close'), 1);
    });

    testWidgets('no connection on opening the screen shows the standard message and a way to try again', (tester) async {
      final server = dayServer(Day());
      await pumpApp(tester, server, size: tall);
      server.offline = true;
      await openDayClose(tester);

      expect(find.text('No connection. Check your internet and try again.'), findsOneWidget);
      server.offline = false;
      await tapButton(tester, 'Try again');
      expect(find.text('Expected cash'), findsOneWidget);
    });

    testWidgets('leaving while the close is on its way throws nothing, tells Today the day is closed, and neither buzzes nor opens a sheet', (tester) async {
      final haptics = <Object?>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'HapticFeedback.vibrate') haptics.add(call.arguments);

        return null;
      });
      addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));

      final gate = Completer<void>();
      final day = Day();
      final server = dayServer(day, routes: {
        'POST /day-close': (_) async {
          await gate.future;
          day.closed = true;

          return json(201, {'data': dayCloseJson()});
        },
      });
      await pumpApp(tester, server, size: tall, clock: FakeClock(DateTime(2026, 10, 21, 21)));
      final tile = find.byKey(const ValueKey('day-close-tile'));
      expect(find.descendant(of: tile, matching: find.text('It is past 20:00. Count the till and close the day.')), findsOneWidget);

      await openDayClose(tester);
      await enter(tester, '3100');
      await tester.tap(closeButton);
      await tester.pump();

      // Back to Today while the close is still on its way; then the server answers.
      await tester.pageBack();
      await settle(tester);
      expect(closeButton, findsNothing);
      gate.complete();
      await settle(tester);

      expect(tester.takeException(), isNull);
      expect(server.calls('POST /day-close'), 1);
      // Today looks again and now knows: the tile is not nagging about a day that is closed.
      expect(find.descendant(of: tile, matching: find.text('The day is closed.')), findsOneWidget);
      expect(find.text('It is past 20:00. Count the till and close the day.'), findsNothing);
      // Nobody is there to feel the tap or to see the seal.
      expect(haptics, isNot(contains('HapticFeedbackType.mediumImpact')));
      expect(find.byType(DayClosedSheet), findsNothing);
    });

    testWidgets('the same figures closed again after a reopening are a new close with a new key', (tester) async {
      final server = dayServer(Day());
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      Future<void> closeWith(String note) async {
        await enter(tester, '3100');
        await tester.enterText(find.byType(TextField), note);
        await tester.pump();
        await tester.tap(closeButton);
        await settle(tester);
      }

      await closeWith('Counted twice');
      await tester.tap(find.descendant(of: find.byType(DayClosedSheet), matching: find.widgetWithText(QButton, 'Done')));
      await settle(tester);
      await tapButton(tester, 'Reopen this day');
      await tester.enterText(find.byType(TextField).last, 'Wrong count of the till');
      await tapButton(tester, 'Reopen the day');
      expect(find.byKey(const ValueKey('pad-1')), findsOneWidget);

      await closeWith('Counted twice');

      final posts = server.requestsTo('POST /day-close');
      expect(posts, hasLength(2));
      expect(body(server, 'POST /day-close'), {'counted_cash': '3100.00', 'note': 'Counted twice', 'day': '2026-10-21'});
      expect(posts.first.headers['Idempotency-Key'], isNot(posts.last.headers['Idempotency-Key']));
    });

    testWidgets('when looking at the day again fails after closing, the page stays and says so with a way to try again', (tester) async {
      final day = Day();
      late final FakeServer server;
      server = dayServer(day, routes: {
        'POST /day-close': (_) {
          day.closed = true;
          server.offline = true; // the signal goes the moment the close is answered

          return json(201, {'data': dayCloseJson()});
        },
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);
      await enter(tester, '3100');
      await tester.tap(closeButton);
      await settle(tester);
      expect(find.byType(DayClosedSheet), findsOneWidget);

      await tester.tap(find.descendant(of: find.byType(DayClosedSheet), matching: find.widgetWithText(QButton, 'Done')));
      await settle(tester);

      // What was on the screen stays, with the trouble said in place: not an error page in its stead.
      final problem = find.byKey(const ValueKey('refresh-problem'));
      expect(problem, findsOneWidget);
      expect(find.descendant(of: problem, matching: find.text('No connection. Check your internet and try again.')), findsOneWidget);
      expect(find.byType(QErrorView), findsNothing);
      expect(find.text('Expected cash'), findsOneWidget);

      server.offline = false;
      await tester.tap(find.descendant(of: problem, matching: find.widgetWithText(QButton, 'Try again')));
      await settle(tester);

      expect(problem, findsNothing);
      expect(find.byKey(const ValueKey('day-seal')), findsOneWidget);
      expect(find.byKey(const ValueKey('pad-1')), findsNothing);
    });

    testWidgets('a reopened day says so and why, and can be closed again', (tester) async {
      final day = Day(reopened: true);
      await pumpApp(tester, dayServer(day), size: tall);
      await openDayClose(tester);

      expect(find.textContaining('This day was reopened. Close it again when the figures are right.'), findsOneWidget);
      expect(find.textContaining('Why: Wrong count'), findsOneWidget);
      expect(find.byKey(const ValueKey('pad-1')), findsOneWidget);
    });
  });

  group('a closed day', () {
    testWidgets('a closed day shows the seal and no number pad', (tester) async {
      await pumpApp(tester, dayServer(Day(closed: true)), size: tall);
      await openDayClose(tester);

      expect(find.byKey(const ValueKey('day-seal')), findsOneWidget);
      expect(find.text('The day is closed.'), findsOneWidget);
      for (final key in ['pad-1', 'pad-0', 'pad-point', 'pad-delete']) {
        expect(find.byKey(ValueKey(key)), findsNothing);
      }
      expect(closeButton, findsNothing);
      expect(find.text('No cash today'), findsNothing);

      // The sentence is the server's, as it came, with the figures the day was signed with.
      expect(find.text('Al-Fares Electronics, 21 Oct: collected SAR 3,150 (cash 3,100, other 50), 14 payments, difference 0.'), findsOneWidget);
      expect(find.text('Expected cash'), findsOneWidget);
      expect(find.text('Counted cash'), findsOneWidget);
      expect(find.text('Balanced'), findsOneWidget);
      expect(find.text('Layla Haddad'), findsOneWidget);
    });

    testWidgets('a short close is written in the server’s plain words', (tester) async {
      final day = Day(closed: true);
      final server = dayServer(day, routes: {
        'GET /day-close/today': always(json(200, {'data': dayCloseTodayJson(closed: true, close: dayCloseJson(counted: '3080.00', difference: '-20.00', note: 'Two notes were torn'), canClose: false)})),
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      expect(find.text('Short by SAR 20.00'), findsOneWidget);
      expect(find.text('Two notes were torn'), findsOneWidget);
    });

    testWidgets('reopen asks for a reason and is offered only to owners and managers', (tester) async {
      final day = Day(closed: true);
      final server = dayServer(day, routes: {
        'POST /day-close/dc1/reopen': (options) {
          final reason = (jsonDecode(options.data as String) as Map<String, dynamic>)['reason'] as String;
          if (reason.trim().length < 5) {
            return apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {'fields': {'reason': ['Give a reason of at least 5 characters.']}});
          }
          day.closed = false;
          day.reopened = true;

          return json(200, {'data': dayCloseJson(status: 'reopened', reopenReason: reason)});
        },
      });
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);

      await tapButton(tester, 'Reopen this day');
      expect(find.text('The close stays on record as it was signed. The day opens again so it can be corrected, then closed once more.'), findsOneWidget);

      // A reason that is too short is the server's to refuse: its words appear under the field, and nothing reopens.
      await tester.enterText(find.byType(TextField).last, 'oops');
      await tapButton(tester, 'Reopen the day');
      expect(find.text('Give a reason of at least 5 characters.'), findsOneWidget);
      expect(day.closed, isTrue);

      await tester.enterText(find.byType(TextField).last, 'Wrong count of the till');
      await tapButton(tester, 'Reopen the day');

      expect(body(server, 'POST /day-close/dc1/reopen'), {'reason': 'Wrong count of the till'});
      expect(find.text('The day is open again. Close it again when the figures are right.'), findsOneWidget);
      expect(find.byKey(const ValueKey('pad-1')), findsOneWidget);
      expect(find.byKey(const ValueKey('day-seal')), findsNothing);
    });

    testWidgets('a reopening whose sheet was closed on its way still lets the screen and Today know', (tester) async {
      final gate = Completer<void>();
      final day = Day(closed: true);
      final server = dayServer(day, routes: {
        'POST /day-close/dc1/reopen': (_) async {
          await gate.future;
          day.closed = false;
          day.reopened = true;

          return json(200, {'data': dayCloseJson(status: 'reopened', reopenReason: 'Wrong count of the till')});
        },
      });
      await pumpApp(tester, server, size: tall, clock: FakeClock(DateTime(2026, 10, 21, 9)));
      final tile = find.byKey(const ValueKey('day-close-tile'));
      expect(find.descendant(of: tile, matching: find.text('The day is closed.')), findsOneWidget);
      await openDayClose(tester);

      await tapButton(tester, 'Reopen this day');
      await tester.enterText(find.byType(TextField).last, 'Wrong count of the till');
      await tapButton(tester, 'Reopen the day');
      expect(find.text('Reopen the day'), findsNothing); // the button is a spinner while it is on its way

      // The sheet is dismissed (a tap beside it) before the server has answered.
      await tester.tapAt(const Offset(10, 10));
      await settle(tester);
      expect(find.byType(TextField), findsNothing);
      gate.complete();
      await settle(tester);

      expect(tester.takeException(), isNull);
      // The day is open on the server, and what reads it looks again: the screen shows the pad, Today the closing time.
      expect(find.byKey(const ValueKey('pad-1')), findsOneWidget);
      await tester.pageBack();
      await settle(tester);
      expect(find.descendant(of: tile, matching: find.text('Closing time is 20:00.')), findsOneWidget);
    });

    testWidgets('in Arabic the day and the time read in the page’s own direction, the names too, and only the amounts run left to right', (tester) async {
      await pumpApp(tester, dayServer(Day(closed: true)), size: tall, language: 'ar');
      await openDayClose(tester);

      TextDirection around(Finder finder) => Directionality.of(tester.element(finder.first));

      // "21 أكتوبر, 20:42": Arabic month names inside a left-to-right island would come out scrambled.
      final closedAt = find.text(formatMomentLong(DateTime.parse('2026-10-21T17:42:00+00:00'), 'ar'));
      expect(closedAt, findsOneWidget);
      expect(around(closedAt), TextDirection.rtl);
      expect(around(find.text(formatDay('2026-10-21', 'ar'))), TextDirection.rtl);
      expect(around(find.text('Layla Haddad')), TextDirection.rtl);
      // Amounts and nothing else are the left-to-right islands.
      expect(around(find.text('SAR 3,100.00')), TextDirection.ltr);
    });

    for (final (role, canReopen, offered) in [('owner', true, true), ('manager', true, true), ('accountant', false, false), ('collector', false, false), ('viewer', false, false)]) {
      testWidgets('$role ${offered ? 'is offered' : 'is not offered'} Reopen, as the server says', (tester) async {
        await pumpApp(tester, dayServer(Day(role: role, closed: true, canReopen: canReopen)), size: tall);
        if (role == 'viewer') {
          // A viewer has no tile; a tapped reminder or a link still reaches the screen, read-only.
          ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).read(routerProvider).go('/day-close');
          await settle(tester);
        } else {
          await openDayClose(tester);
        }

        expect(find.byKey(const ValueKey('day-seal')), findsOneWidget);
        expect(find.text('Reopen this day'), offered ? findsOneWidget : findsNothing);
      });
    }
  });

  group('who is offered it', () {
    testWidgets('the tile is hidden when the feature is off and for a viewer', (tester) async {
      // Off: the server lists it as switched off by the platform; or lacks it in the plan; or does not list it at all.
      for (final account in [
        accountWithDayClose(status: 'platform_off'),
        accountWithDayClose(status: 'plan_locked'),
        accountWithDayClose(listed: false),
        accountWithDayClose(role: 'viewer'),
      ]) {
        final server = dayServer(Day(), account: account);
        await pumpApp(tester, server, size: tall);

        expect(find.byKey(const ValueKey('day-close-tile')), findsNothing, reason: 'a tile for ${(account['entitlements'] as Map)['day_close']} ${(account['tenant'] as Map)['role']}');
        expect(find.text('Close the day'), findsNothing);
        expect(server.calls('GET /day-close/today'), 0, reason: 'nothing is asked while it is off');
        await tester.pumpWidget(const SizedBox());
      }

      // On, for someone who records payments: the tile is there, and it asks for the closing time.
      for (final role in ['owner', 'manager', 'accountant', 'collector']) {
        final server = dayServer(Day(role: role));
        await pumpApp(tester, server, size: tall);

        expect(find.byKey(const ValueKey('day-close-tile')), findsOneWidget, reason: 'no tile for a $role');
        expect(find.text('Close the day'), findsOneWidget);
        expect(server.calls('GET /day-close/today'), 1);
        await tester.pumpWidget(const SizedBox());
      }
    });

    testWidgets('with the feature off the screen asks for nothing and offers nothing', (tester) async {
      final server = dayServer(Day(), account: accountWithDayClose(status: 'platform_off'));
      await pumpApp(tester, server);
      ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).read(routerProvider).go('/day-close');
      await settle(tester);

      expect(find.text('Closing the day is not switched on for this business.'), findsOneWidget);
      expect(find.byKey(const ValueKey('pad-1')), findsNothing);

      ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).read(routerProvider).go('/day-close/history');
      await settle(tester);
      expect(find.text('Closing the day is not switched on for this business.'), findsOneWidget);

      expect(server.calls('GET /day-close/today'), 0);
      expect(server.calls('GET /day-close'), 0);
    });

    testWidgets('a viewer sees the figures read-only, without Close or Reopen', (tester) async {
      final server = dayServer(Day(role: 'viewer'));
      await pumpApp(tester, server, size: tall);
      ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).read(routerProvider).go('/day-close');
      await settle(tester);

      expect(find.text('SAR 3,100.00'), findsOneWidget);
      expect(find.text('Noura Al-Saif'), findsOneWidget);
      expect(find.text('You can see the day’s figures. Only people who record payments can close it.'), findsOneWidget);
      expect(closeButton, findsNothing);
      expect(find.byKey(const ValueKey('pad-1')), findsNothing);
      expect(find.text('No cash today'), findsNothing);
      expect(find.text('Sign off'), findsNothing);
      expect(find.text('Reopen this day'), findsNothing);
    });

    testWidgets('after the closing time the tile is warmer in its words, and once closed it shows the seal', (tester) async {
      final clock = FakeClock(DateTime(2026, 10, 21, 9));
      final day = Day();
      await pumpApp(tester, dayServer(day), size: tall, clock: clock);
      expect(find.text('Closing time is 20:00.'), findsOneWidget);
      expect(find.text('It is past 20:00. Count the till and close the day.'), findsNothing);

      clock.now = DateTime(2026, 10, 21, 20, 30);
      await tester.pumpWidget(const SizedBox());
      await pumpApp(tester, dayServer(day), size: tall, clock: clock);
      expect(find.text('It is past 20:00. Count the till and close the day.'), findsOneWidget);
      expect(find.text('Closing time is 20:00.'), findsNothing);

      day.closed = true;
      await tester.pumpWidget(const SizedBox());
      await pumpApp(tester, dayServer(day), size: tall, clock: clock);
      expect(find.text('The day is closed.'), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('day-close-tile')), matching: find.byType(DaySeal)), findsOneWidget);
      expect(find.text('It is past 20:00. Count the till and close the day.'), findsNothing);
    });

    testWidgets('the tap on a push opens /day-close', (tester) async {
      final push = FakePush();
      await pumpApp(tester, dayServer(Day()), push: push, size: tall);
      expect(find.text('Count the notes and coins in the till and enter the total.'), findsNothing);

      push.tapped.add('/day-close');
      await settle(tester);

      expect(find.text('Count the notes and coins in the till and enter the total.'), findsOneWidget);
      expect(find.text('SAR 3,100.00'), findsOneWidget);
    });
  });

  group('the history', () {
    testWidgets('lists every close, newest first, in the server’s words', (tester) async {
      final server = dayServer(Day(closed: true));
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);
      await tapTooltip(tester, 'History');

      for (final day in ['2026-10-21', '2026-10-20', '2026-10-19']) {
        expect(find.text(formatDay(day, 'en')), findsOneWidget);
      }
      expect(find.text('Closed'), findsNWidgets(2));
      expect(find.text('Reopened'), findsOneWidget);
      expect(find.text('Balanced'), findsNWidgets(2));
      expect(find.text('Short by SAR 20.00'), findsOneWidget);
      expect(find.text('Closed by: Someone no longer on the team'), findsOneWidget);
      expect(find.text('Al-Fares Electronics, 20 Oct: collected SAR 3,000, 9 payments, short by SAR 20.'), findsOneWidget);
    });

    testWidgets('says so when no day has been closed', (tester) async {
      final server = dayServer(Day(), routes: {'GET /day-close': always(json(200, pageJson(<Map<String, dynamic>>[], last: 1, total: 0)))});
      await pumpApp(tester, server, size: tall);
      await openDayClose(tester);
      await tapTooltip(tester, 'History');

      expect(find.text('Closed days will be listed here.'), findsOneWidget);
    });
  });

  group('large text and other languages', () {
    for (final language in ['en', 'ar']) {
      testWidgets('the screen lays out at 200% text in ${language == 'en' ? 'English' : 'Arabic'} without overflow', (tester) async {
        final day = Day();
        final server = dayServer(day, routes: {
          'POST /day-close': always(apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {'fields': {'day': ['A day that has not come yet cannot be closed.']}})),
        });
        await pumpApp(tester, server, size: const Size(360, 640), textScale: 2, language: language);
        final router = ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).read(routerProvider);
        expect(tester.takeException(), isNull, reason: 'overflow on the tile');

        router.go('/day-close');
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'overflow on the open day');

        // Type an amount (the difference appears), then be refused (the message appears above the button).
        await enter(tester, '3125.5');
        expect(tester.takeException(), isNull, reason: 'overflow with an amount and its difference');
        await tester.tap(closeButton);
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'overflow with the refusal above the button');

        await tester.ensureVisible(find.byType(TextField));
        await tester.pump();
        expect(tester.takeException(), isNull, reason: 'overflow at the foot of the open day');

        // The closed day, with its facts and the reopening.
        day.closed = true;
        router.go('/');
        await settle(tester);
        router.go('/day-close');
        await settle(tester);
        expect(find.byKey(const ValueKey('day-seal')), findsOneWidget);
        expect(tester.takeException(), isNull, reason: 'overflow on the closed day');

        await tester.ensureVisible(find.byType(OutlinedButton).last);
        await tester.pump();
        expect(tester.takeException(), isNull, reason: 'overflow at the foot of the closed day');
        await tester.tap(find.byType(OutlinedButton).last);
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'overflow in the reopening sheet');
        await tester.enterText(find.byType(TextField).last, 'oops');
        await tester.pump();
        expect(tester.takeException(), isNull);
        Navigator.of(tester.element(find.byType(TextField).last)).pop();
        await settle(tester);

        router.go('/day-close/history');
        await settle(tester);
        expect(tester.takeException(), isNull, reason: 'overflow in the history');
      });
    }

    testWidgets('in Arabic the words, the pad and the amounts read the right way round', (tester) async {
      await pumpApp(tester, dayServer(Day()), size: tall, language: 'ar');
      await openDayClose(tester);

      expect(find.text('النقد المتوقع'), findsOneWidget);
      await enter(tester, '3000');
      expect(inHint('نقص SAR 100.00'), findsOneWidget);

      // The pad is a phone's pad in every language: 1 at the left of the top row, 3 at the right.
      expect(tester.getCenter(find.byKey(const ValueKey('pad-1'))).dx, lessThan(tester.getCenter(find.byKey(const ValueKey('pad-3'))).dx));
      // The amount is read left to right.
      expect(inAmount('3,000'), findsOneWidget);
    });

    testWidgets('light and dark both draw the closed day', (tester) async {
      await pumpApp(tester, dayServer(Day(closed: true)), size: tall, preferences: {'theme_mode': 'dark'});
      await openDayClose(tester);

      expect(find.byKey(const ValueKey('day-seal')), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('the notices about closing the day', () {
    Map<String, dynamic> preferences() {
      const kinds = {
        'daily_digest': ('alerts', 'Morning summary'),
        'instalment_due': ('alerts', 'On the due date'),
        'instalment_late': ('alerts', 'When an instalment is late'),
        'day_close_reminder': ('alerts', 'Evening reminder to close the day'),
        'day_close_difference': ('alerts', 'When a day is closed with a cash difference'),
        'billing_renewed': ('billing', 'When your plan renews'),
        'support_reply': ('support', 'Support replies'),
      };

      return {
        'data': {
          'daily_digest': {'enabled': true, 'time': '09:00'},
          'instalment_due': {'enabled': false},
          'instalment_late': {'enabled': true, 'repeat': 'weekly'},
          for (final key in kinds.keys.where((k) => !k.startsWith('instalment') && k != 'daily_digest')) key: {'enabled': true, 'channels': {'push': true, 'email': false, 'sms': false}},
          'quiet_hours': {'enabled': false, 'from': '22:00', 'to': '08:00'},
        },
        'meta': {
          'kinds': [for (final MapEntry(:key, :value) in kinds.entries) {'key': key, 'group': value.$1, 'label': value.$2, 'description': '${value.$2}, explained.'}],
          'channels': {
            'push': {'available': true, 'label': 'In the app'},
            'email': {'available': false, 'label': 'By e-mail'},
            'sms': {'available': false, 'label': 'By text message'},
          },
          'allowed': {for (final key in kinds.keys) key: {'push': true, 'email': false, 'sms': false}},
        },
      };
    }

    Future<void> openNotices(WidgetTester tester, String role, {String status = 'on'}) async {
      await pumpApp(tester, workspaceServer(account: accountWithDayClose(role: role, status: status), routes: {
        'GET /notifications/preferences': always(json(200, preferences())),
      }), size: const Size(412, 2400));
      await openSettings(tester);
      await tester.ensureVisible(find.byKey(const ValueKey('settings-alerts')));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('settings-alerts')));
      await settle(tester);
    }

    final reminder = find.byKey(const ValueKey('notice-day_close_reminder'));
    final difference = find.byKey(const ValueKey('notice-day_close_difference'));

    testWidgets('an owner is offered both, under their own heading', (tester) async {
      await openNotices(tester, 'owner');

      expect(reminder, findsOneWidget);
      expect(difference, findsOneWidget);
      expect(find.text('Evening reminder to close the day'), findsOneWidget);
      expect(find.text('Close the day'), findsOneWidget); // the heading of that group
      expect(find.text('Support replies'), findsOneWidget);
    });

    testWidgets('a manager, an accountant and a collector are offered the reminder but not the notice of a difference', (tester) async {
      for (final role in ['manager', 'accountant', 'collector']) {
        await openNotices(tester, role);

        expect(reminder, findsOneWidget, reason: '$role should be offered the reminder');
        expect(difference, findsNothing, reason: '$role should not be offered the notice of a difference');
        await tester.pumpWidget(const SizedBox());
      }
    });

    testWidgets('a viewer is offered neither', (tester) async {
      await openNotices(tester, 'viewer');

      expect(reminder, findsNothing);
      expect(difference, findsNothing);
      expect(find.text('Close the day'), findsNothing);
      expect(find.text('Support replies'), findsOneWidget);
    });

    testWidgets('with the feature off nobody is offered either, owners included', (tester) async {
      for (final status in ['platform_off', 'plan_locked']) {
        await openNotices(tester, 'owner', status: status);

        expect(reminder, findsNothing, reason: 'the reminder while $status');
        expect(difference, findsNothing, reason: 'the notice while $status');
        expect(find.text('Close the day'), findsNothing);
        expect(find.text('Support replies'), findsOneWidget);
        await tester.pumpWidget(const SizedBox());
      }
    });
  });

  group('the counted amount', () {
    CountedEntry typed(String keys) => keys.split('').fold<CountedEntry>(const CountedEntry(), (entry, key) => entry.press(key));

    test('is built from digits and one decimal point, as whole cents', () {
      expect(typed('').amount, isNull);
      expect(typed('3100').amount, Money.fromCents(BigInt.from(310000)));
      expect(typed('3100.5').amount, Money.fromCents(BigInt.from(310050)));
      expect(typed('3100.57').amount!.toDecimalString(), '3100.57');
      expect(typed('.5').amount!.toDecimalString(), '0.50');
      expect(typed('0').amount, Money.zero);
      expect(CountedEntry.noCash.amount, Money.zero);
    });

    test('takes no third decimal, no second point and no leading zeros', () {
      expect(typed('1.234').display, '1.23');
      expect(typed('1.2.3').display, '1.23');
      expect(typed('007').display, '7');
      expect(typed('00').display, '0');
      expect(typed('0.0').amount, Money.zero);
    });

    test('groups the thousands and shows a point that has been pressed', () {
      expect(typed('1234567').display, '1,234,567');
      expect(typed('1234.').display, '1,234.');
      expect(typed('1234.5').display, '1,234.5');
      expect(typed('').display, '');
    });

    test('delete takes the decimals first, then the point, then the whole digits', () {
      expect(typed('12.34<').display, '12.3');
      expect(typed('12.34<<').display, '12.');
      expect(typed('12.34<<<').display, '12');
      expect(typed('12.34<<<<').display, '1');
      expect(typed('12.34<<<<<').isEmpty, isTrue);
      expect(typed('<<<').isEmpty, isTrue);
    });

    test('keeps to twelve digits before the point, and stays exact to the cent', () {
      final full = typed('1234567890123456');
      expect(full.display, '123,456,789,012');
      // More cents than a double can hold exactly: the amount is still the digits that were typed.
      expect(typed('999999999999.99').amount!.toDecimalString(), '999999999999.99');
      expect(typed('999999999999.99').amount!.cents, BigInt.parse('99999999999999'));
    });

    test('knows how a count stands against what the day expects', () {
      expect(standingOf(Money.zero), CashStanding.balanced);
      expect(standingOf(Money.parse('-0.01')), CashStanding.short);
      expect(standingOf(Money.parse('0.01')), CashStanding.over);
      // 0.1 + 0.2 is not 0.3 as a double; as cents it is.
      expect(standingOf(Money.parse('0.30') - (Money.parse('0.10') + Money.parse('0.20'))), CashStanding.balanced);
    });

    test('knows when the closing time has come', () {
      expect(pastClosingTime(DateTime(2026, 10, 21, 19, 59), '20:00'), isFalse);
      expect(pastClosingTime(DateTime(2026, 10, 21, 20), '20:00'), isTrue);
      expect(pastClosingTime(DateTime(2026, 10, 21, 23, 30), '20:30'), isTrue);
      expect(pastClosingTime(DateTime(2026, 10, 21, 23, 30), 'late'), isFalse);
      expect(pastClosingTime(DateTime(2026, 10, 21, 23, 30), ''), isFalse);
    });
  });
}
