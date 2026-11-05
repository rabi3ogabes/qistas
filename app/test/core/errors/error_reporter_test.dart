import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:ui';

import 'package:dio/dio.dart' show DioException, RequestOptions;
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/api/api_client.dart';
import 'package:qistas/core/api/api_exception.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/core/errors/error_queue.dart';
import 'package:qistas/core/errors/error_reporter.dart';
import 'package:qistas/core/errors/trace_scrubber.dart';
import 'package:qistas/core/storage/token_store.dart';
import 'package:qistas/data/qistas_api.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../support/fake_api.dart';

/// The phone's crash reporter: what it sends, when it sends nothing, how it keeps what it could not send, and how
/// it stays out of the way of the app it watches.
void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();

  const phone = (os: 'android', osVersion: 'Linux 5.15.78', device: 'phone', version: '1.29.0');
  final trace = StackTrace.fromString('#0      main (package:qistas/main.dart:12:3)\n#1      _rootRun (dart:async/zone.dart:1399:13)');

  late SharedPreferences prefs;
  late List<Map<String, dynamic>> sent;
  late Future<void> Function(Map<String, dynamic> report) behaviour;
  late DateTime now;
  late ErrorReporter reporter;

  Future<void> settle() => pumpEventQueue(times: 80);

  ApiException refusal(int status, {int? retryAfter}) => ApiException(status: status, code: 'http_$status', message: '', retryAfter: retryAfter);

  Future<void> offline(Map<String, dynamic> report) async => throw const ApiException.network();

  /// A reporter with a fresh phone. The ceiling on how often a report is built (1 a second, 10 a minute) is lifted, so that
  /// the tests that make many different reports in one instant need not move the clock; the tests about it ask for [limited].
  Future<void> start({Map<String, Object> preferences = const {}, DeviceFacts Function()? facts, String Function(String text, {int max})? scrub, bool limited = false}) async {
    SharedPreferences.setMockInitialValues(preferences);
    prefs = await SharedPreferences.getInstance();
    sent = [];
    behaviour = (_) async {};
    now = DateTime(2026, 11, 5, 9);
    reporter = ErrorReporter(
      preferences: () async => prefs,
      send: (report) {
        sent.add(report);

        return behaviour(report);
      },
      facts: facts ?? () => phone,
      scrub: scrub,
      clock: () => now,
      buildGap: limited ? const Duration(seconds: 1) : Duration.zero,
      buildsPerMinute: limited ? 10 : 1 << 20,
    );
  }

  /// What waits on the phone, read without taking it out.
  List<String> queued() {
    final raw = prefs.getString(ErrorQueue.key);
    if (raw == null) return [];

    return [for (final entry in jsonDecode(raw) as List<dynamic>) ((entry as Map<String, dynamic>)['report'] as Map<String, dynamic>)['message'].toString()];
  }

  List<String> messages() => [for (final report in sent) report['message'].toString()];

  setUp(() => start());

  group('a report', () {
    test('holds the class, the message, the trace, the app version and the kind of phone, and nothing else', () async {
      await reporter.report(StateError('Broken'), trace);

      expect(sent, hasLength(1));
      final report = sent.single;
      expect(report.keys.toSet(), {'class', 'message', 'trace', 'version', 'os', 'os_version', 'device'});
      expect(report['class'], 'StateError');
      expect(report['message'], 'Bad state: Broken');
      expect(report['trace'], contains('package:qistas/main.dart:12:3'));
      expect(report['version'], '1.29.0');
      expect(report['os'], 'android');
      expect(report['os_version'], 'Linux 5.15.78');
      expect(report['device'], 'phone');
    });

    test('cuts the words of the system and of the app to what the server reads', () async {
      await start(facts: () => (os: 'ios', osVersion: 'Version 17.2.1 (Build 21C66) on a very long name', device: 'tablet', version: '1.29.0+33-beta.something'));

      await reporter.report(StateError('x'), trace);

      expect((sent.single['os_version'] as String).length, lessThanOrEqualTo(24));
      expect((sent.single['version'] as String).length, lessThanOrEqualTo(16));
      expect(sent.single['os'], 'ios');
      expect(sent.single['device'], 'tablet');
    });

    test('has no name, number, amount, phone number, e-mail, what was typed, or account id', () async {
      const typed = 'Layla Haddad 1250.00 SAR +974 5555 1234';
      final where = StackTrace.fromString('#0      save (file:///C:/Users/layla/app/lib/save.dart:12:3)\n#1      x (package:qistas/x.dart:1:1)');

      for (final error in <Object>[
        const FormatException('Not an amount with at most two decimals', typed),
        ArgumentError.value('Layla Haddad', 'customer'),
        StateError('Account 3f2b8c1e-5d4a-4b7e-9c1d-2a3b4c5d6e7f has no layla@example.com'),
        Exception('call +974 5555 1234 or 0501234567 about 1234567890'),
        NoSuchMethodError.withInvocation('Layla Haddad', Invocation.method(#foo, ['1250.00'])),
      ]) {
        await reporter.report(error, where);
      }

      expect(sent, hasLength(5));
      final everything = jsonEncode(sent);
      for (final secret in ['Layla', 'Haddad', '1250', '5555', '0501234567', '1234567890', 'layla', '3f2b8c1e', r'C:\', 'C:/', 'Users']) {
        expect(everything.contains(secret), isFalse, reason: 'the report holds "$secret"');
      }
    });

    test('of a message that runs over several lines, keeps the first line only: the rest is often what was typed', () async {
      await reporter.report(const FormatException('Not an amount with at most two decimals', 'Layla 12'), trace);

      expect(sent.single['message'], 'FormatException: Not an amount with at most two decimals');
    });

    test('is cut to what the server takes, after it is cleaned', () async {
      await reporter.report(Exception('x' * 3000), StackTrace.fromString('frame\n' * 9000));

      final report = sent.single;
      expect((report['message'] as String).runes.length, lessThanOrEqualTo(500));
      expect((report['trace'] as String).runes.length, lessThanOrEqualTo(8000));
      expect((report['class'] as String).runes.length, lessThanOrEqualTo(160));
      expect(utf8.encode(jsonEncode(report)).length, lessThan(16384));
    });

    test('is cut on a character boundary', () async {
      await reporter.report(Exception('😀' * 600), StackTrace.fromString('😀' * 9000));

      final report = sent.single;
      for (final field in ['message', 'trace']) {
        final text = report[field] as String;
        // no half of a pair is left: every character is a whole one, and the cut is of the emoji, not of the words before them
        expect(text.runes.every((rune) => rune < 0xD800 || rune > 0xDFFF), isTrue, reason: field);
        expect(text.runes.last, 0x1F600, reason: field);
      }
      expect((report['message'] as String).runes.length, 500);
      expect((report['trace'] as String).runes.length, lessThanOrEqualTo(8000));
    });

    test('still fits a 16 KB body when the script takes three bytes a letter', () async {
      await reporter.report(Exception('日' * 900), StackTrace.fromString('日' * 9000));

      final report = sent.single;
      expect(utf8.encode(jsonEncode(report)).length, lessThanOrEqualTo(16384));
      expect((report['trace'] as String).isNotEmpty, isTrue);
    });

    test('does not report the same error again for a minute, but does after', () async {
      await reporter.report(Exception('same'), trace);
      await reporter.report(Exception('same'), trace);
      now = now.add(const Duration(seconds: 59));
      await reporter.report(Exception('same'), trace);

      expect(sent, hasLength(1));

      now = now.add(const Duration(seconds: 2));
      await reporter.report(Exception('same'), trace);

      expect(sent, hasLength(2));
    });

    test('does not report one error object twice, whichever hook caught it (even with another trace)', () async {
      final error = StateError('twice');
      await reporter.report(error, trace);
      await reporter.report(error, StackTrace.fromString('#0      other (package:qistas/other.dart:1:1)'));

      expect(sent, hasLength(1));

      now = now.add(const Duration(minutes: 2));
      await reporter.report(error, trace);

      expect(sent, hasLength(2), reason: 'a minute later it is a new sighting');
    });

    test('is not made of a failed network call or a refusal: offline is normal and the server sees the rest', () async {
      for (final error in <Object>[
        const ApiException.network(),
        const ApiException(status: 500, code: 'server_error', message: ''),
        const ApiException(status: 422, code: 'validation_failed', message: ''),
        const SocketException('offline'),
        const HttpException('closed'),
        TimeoutException('slow'),
        DioException.connectionError(requestOptions: RequestOptions(path: '/x'), reason: 'offline'),
      ]) {
        await reporter.report(error, trace);
      }

      expect(sent, isEmpty);
      expect(queued(), isEmpty);
    });
  });

  group('when nothing is sent', () {
    test('the switch is on until it is turned off', () async {
      await reporter.report(StateError('on'), trace);

      expect(sent, hasLength(1));
    });

    test('the switch off sends nothing and queues nothing', () async {
      await start(preferences: {CrashReports.key: false});

      await reporter.report(StateError('off'), trace);
      await reporter.flush();

      expect(sent, isEmpty);
      expect(prefs.getString(ErrorQueue.key), isNull);
    });

    test('the switch turned off while a report waits sends nothing more and does not bring the queue back', () async {
      // The answer is made inside the sender, as a real one is (a future from outside would not hand its error over).
      late Completer<void> answer;
      behaviour = (_) {
        answer = Completer<void>();

        return answer.future;
      };

      final first = reporter.report(StateError('one'), trace);
      await settle();
      expect(sent, hasLength(1));

      // What the Settings switch does: write the choice, empty the queue.
      await prefs.setBool(CrashReports.key, false);
      await ErrorQueue(prefs, clock: () => now).clear();
      answer.completeError(const ApiException.network());
      await first;

      expect(queued(), isEmpty);
      await reporter.flush();
      expect(sent, hasLength(1));
    });

    test('a platform that is not Android or iOS sends nothing and queues nothing', () async {
      await start(facts: () => (os: null, osVersion: '', device: 'phone', version: '1.29.0'));

      await reporter.report(StateError('desktop'), trace);

      expect(sent, isEmpty);
      expect(prefs.getString(ErrorQueue.key), isNull);
    });

    test('a scrubber that throws means nothing is sent and nothing is queued', () async {
      await start(scrub: (text, {int max = 8000}) => throw StateError('the scrubber broke'));

      await reporter.report(StateError('unsafe'), trace);

      expect(sent, isEmpty);
      expect(prefs.getString(ErrorQueue.key), isNull);
    });

    test('an error whose own text cannot be read means nothing is sent', () async {
      await reporter.report(_Unreadable(), trace);

      expect(sent, isEmpty);
    });
  });

  group('when sending fails', () {
    test('the report is kept, and the next flush that succeeds sends the kept ones in order', () async {
      behaviour = offline;
      await reporter.report(StateError('first'), trace);
      now = now.add(const Duration(minutes: 1));
      await reporter.report(StateError('second'), trace);

      expect(queued(), ['Bad state: first', 'Bad state: second']);

      sent.clear();
      now = now.add(const Duration(seconds: 61));
      behaviour = (_) async {};
      await reporter.flush();

      expect(messages(), ['Bad state: first', 'Bad state: second']);
      expect(queued(), isEmpty);
    });

    test('after a failure it waits a minute: what comes in meanwhile is kept, not tried at once', () async {
      behaviour = offline;
      await reporter.report(StateError('a'), trace);
      await reporter.report(StateError('b'), trace);
      await reporter.report(StateError('c'), trace);
      await reporter.flush();

      expect(messages(), ['Bad state: a'], reason: 'one attempt, then it waits');
      expect(queued(), ['Bad state: a', 'Bad state: b', 'Bad state: c']);

      now = now.add(const Duration(seconds: 59));
      await reporter.flush();
      expect(messages(), ['Bad state: a'], reason: 'not yet');

      now = now.add(const Duration(seconds: 2));
      behaviour = (_) async {};
      await reporter.flush();

      expect(messages(), ['Bad state: a', 'Bad state: a', 'Bad state: b', 'Bad state: c']);
      expect(queued(), isEmpty);
    });

    test('a 5xx answer waits a minute too', () async {
      behaviour = (_) async => throw refusal(503);
      await reporter.report(StateError('a'), trace);
      await reporter.report(StateError('b'), trace);

      expect(messages(), ['Bad state: a']);

      now = now.add(const Duration(seconds: 61));
      await reporter.flush();

      expect(messages(), ['Bad state: a', 'Bad state: a']);
    });

    test('a 408, the server giving up on a slow body, is a lost connection: kept, and a minute of waiting', () async {
      behaviour = (_) async => throw refusal(408);
      await reporter.report(StateError('slow'), trace);

      expect(queued(), ['Bad state: slow']);
      expect(reporter.stopped, isFalse);
      await reporter.flush();
      expect(sent, hasLength(1));
    });

    test('the 21st report waiting drops the oldest', () async {
      behaviour = offline;
      for (var i = 1; i <= 21; i++) {
        await reporter.report(StateError('e$i'), trace);
      }

      final waiting = queued();

      expect(waiting, hasLength(20));
      expect(waiting.first, 'Bad state: e2');
      expect(waiting.last, 'Bad state: e21');
    });

    test('a report more than 6 hours old is dropped, not sent', () async {
      behaviour = offline;
      await reporter.report(StateError('old'), trace);
      now = now.add(const Duration(hours: 6, minutes: 1));
      behaviour = (_) async {};
      sent.clear();

      await reporter.flush();

      expect(sent, isEmpty);
      expect(queued(), isEmpty);
    });

    test('an exception thrown by the sender is swallowed, produces no second report, and keeps the report', () async {
      behaviour = (_) async => throw StateError('the sender broke');
      final uncaught = <Object>[];
      final framework = <FlutterErrorDetails>[];
      final before = FlutterError.onError;
      FlutterError.onError = framework.add;
      addTearDown(() => FlutterError.onError = before);

      await runZonedGuarded(() async {
        await reporter.report(StateError('first'), trace);
        await settle();
      }, (error, stack) => uncaught.add(error));

      expect(sent, hasLength(1), reason: 'only the original; the sender\'s own failure is not a report');
      expect(uncaught, isEmpty);
      expect(framework, isEmpty);
      expect(queued(), ['Bad state: first']);
    });

    test('a sender that leaves a failure behind for later does not let it out, or make a report of it', () async {
      behaviour = (_) {
        unawaited(Future<void>.error(StateError('left unawaited')));
        unawaited(Future<void>.delayed(const Duration(milliseconds: 5), () => throw StateError('thrown later')));
        Timer(const Duration(milliseconds: 10), () => throw StateError('thrown by a timer'));

        return Future<void>.value();
      };
      final uncaught = <Object>[];
      final framework = <FlutterErrorDetails>[];
      final before = FlutterError.onError;
      FlutterError.onError = framework.add;
      addTearDown(() => FlutterError.onError = before);

      await runZonedGuarded(() async {
        await reporter.report(StateError('first'), trace);
        await Future<void>.delayed(const Duration(milliseconds: 60));
      }, (error, stack) => uncaught.add(error));

      expect(uncaught, isEmpty);
      expect(framework, isEmpty);
      expect(messages(), ['Bad state: first'], reason: 'none of the late failures is a report');
    });

    test('a sender that throws before it returns a future is swallowed too', () async {
      reporter = ErrorReporter(preferences: () async => prefs, send: (_) => throw StateError('sync'), facts: () => phone, clock: () => now);

      await reporter.report(StateError('first'), trace);

      expect(queued(), ['Bad state: first']);
    });

    test('never more than one flush runs at a time, and what came in meanwhile is sent after', () async {
      late Completer<void> answer;
      behaviour = (_) {
        answer = Completer<void>();

        return answer.future;
      };

      final first = reporter.report(StateError('a'), trace);
      await settle();
      final second = reporter.report(StateError('b'), trace);
      await settle();

      expect(messages(), ['Bad state: a'], reason: 'one in flight, the second waits');

      behaviour = (_) async {};
      answer.complete();
      await Future.wait([first, second]);
      await settle();

      expect(messages(), ['Bad state: a', 'Bad state: b']);
      expect(queued(), isEmpty);
    });
  });

  group('what it costs when the same error comes again and again', () {
    /// How many reports were built: the trace is scrubbed once for each (the one text cut to 8000 characters).
    late int built;

    Future<void> startCounting() async {
      built = 0;
      await start(
        limited: true,
        scrub: (text, {int max = TraceScrubber.traceMax}) {
          if (max == TraceScrubber.traceMax) built++;

          return TraceScrubber.clean(text, max: max);
        },
      );
    }

    test('500 errors that are equal but not the same object are cleaned once, and reported once', () async {
      await startCounting();

      for (var i = 0; i < 500; i++) {
        await reporter.report(StateError('same'), trace);
      }

      expect(built, 1);
      expect(sent, hasLength(1));
    });

    test('the same error is cleaned and reported again once the minute is over', () async {
      await startCounting();
      await reporter.report(StateError('same'), trace);
      now = now.add(const Duration(seconds: 59));
      await reporter.report(StateError('same'), trace);

      expect(built, 1);

      now = now.add(const Duration(seconds: 2));
      await reporter.report(StateError('same'), trace);

      expect(built, 2);
      expect(sent, hasLength(2));
    });

    test('what tells two errors apart is read before anything is cleaned: another message, another frame, another class', () async {
      await startCounting();
      await reporter.report(StateError('one'), trace);
      now = now.add(const Duration(seconds: 1));
      await reporter.report(StateError('two'), trace);
      now = now.add(const Duration(seconds: 1));
      await reporter.report(StateError('one'), StackTrace.fromString('#0      other (package:qistas/other.dart:1:1)'));
      now = now.add(const Duration(seconds: 1));
      await reporter.report(ArgumentError('one'), trace);

      expect(built, 4);
    });

    test('20 different errors in one second are cleaned once: at most one a second', () async {
      await startCounting();

      for (var i = 0; i < 20; i++) {
        await reporter.report(StateError('different $i'), trace);
      }

      expect(built, 1);
      expect(sent, hasLength(1));
    });

    test('and one more each second that passes, never more than 10 in a minute', () async {
      await startCounting();

      for (var i = 0; i < 15; i++) {
        await reporter.report(StateError('different $i'), trace);
        now = now.add(const Duration(seconds: 1));
      }

      expect(built, 10, reason: 'the first ten seconds; the next five were over the ceiling');

      now = now.add(const Duration(seconds: 46));
      await reporter.report(StateError('after the minute'), trace);

      expect(built, 11, reason: 'a minute after the first ones, there is room again');
    });

    test('an error that was over the ceiling is not forgotten as if it had been reported: it is taken when there is room', () async {
      await startCounting();
      await reporter.report(StateError('a'), trace);
      await reporter.report(StateError('b'), trace);

      expect(messages(), ['Bad state: a']);

      now = now.add(const Duration(seconds: 1));
      await reporter.report(StateError('b'), trace);

      expect(messages(), ['Bad state: a', 'Bad state: b']);
    });

    test('an error that cannot be turned into a report is not tried again every time it comes', () async {
      await startCounting();

      for (var i = 0; i < 100; i++) {
        await reporter.report(_Unreadable(), trace);
      }

      expect(built, lessThanOrEqualTo(1));
      expect(sent, isEmpty);
    });
  });

  group('what the server answers', () {
    for (final status in [403, 404, 405]) {
      test('$status stops sending for the session and drops the queue', () async {
        behaviour = offline;
        await reporter.report(StateError('waiting'), trace);

        now = now.add(const Duration(seconds: 61));
        behaviour = (_) async => throw refusal(status);
        await reporter.report(StateError('next'), trace);
        final calls = sent.length;

        behaviour = (_) async {};
        await reporter.report(StateError('after'), trace);
        await reporter.flush();
        now = now.add(const Duration(hours: 5));
        await reporter.report(StateError('much later'), trace);

        expect(reporter.stopped, isTrue);
        expect(sent, hasLength(calls), reason: 'nothing is sent once the server has said it does not want them');
        expect(prefs.getString(ErrorQueue.key), isNull, reason: 'and nothing is kept for later');
      });
    }

    test('a report that comes in while the server is saying no is dropped with the rest', () async {
      late Completer<void> answer;
      behaviour = (_) {
        answer = Completer<void>();

        return answer.future;
      };

      final first = reporter.report(StateError('one'), trace);
      await settle();
      final during = reporter.report(StateError('during'), trace);
      await settle();
      expect(messages(), ['Bad state: one'], reason: 'in flight, the other waits behind it');

      answer.completeError(refusal(403));
      await Future.wait([first, during]);
      await settle();

      expect(reporter.stopped, isTrue);
      expect(queued(), isEmpty, reason: 'what came in while it was answering is dropped too');
      expect(messages(), ['Bad state: one']);
      expect(prefs.getString(ErrorQueue.key), isNull);
    });

    for (final status in [400, 401, 406, 409, 413, 415, 422]) {
      test('$status drops that report, which will not be better for trying again, and keeps the rest', () async {
        behaviour = offline;
        await reporter.report(StateError('refused'), trace);
        await reporter.report(StateError('fine'), trace);
        sent.clear();

        now = now.add(const Duration(seconds: 61));
        behaviour = (report) async {
          if (report['message'] == 'Bad state: refused') throw refusal(status);
        };
        await reporter.flush();

        expect(messages(), ['Bad state: refused', 'Bad state: fine']);
        expect(reporter.stopped, isFalse);
        expect(queued(), isEmpty);

        sent.clear();
        await reporter.flush();
        expect(sent, isEmpty, reason: 'the refused one is not tried again');
      });
    }

    test('429 keeps the queue and waits as long as the server said', () async {
      behaviour = (_) async => throw refusal(429, retryAfter: 30);
      await reporter.report(StateError('busy'), trace);
      final calls = sent.length;

      await reporter.report(StateError('more'), trace);
      await reporter.flush();
      expect(sent, hasLength(calls), reason: 'it waits');

      now = now.add(const Duration(seconds: 31));
      behaviour = (_) async {};
      await reporter.flush();

      expect(messages().sublist(calls), ['Bad state: busy', 'Bad state: more']);
      expect(reporter.stopped, isFalse);
    });

    test('429 without a time to wait still waits, but never for long', () async {
      behaviour = (_) async => throw refusal(429);
      await reporter.report(StateError('busy'), trace);
      final calls = sent.length;

      behaviour = (_) async {};
      now = now.add(const Duration(seconds: 10));
      await reporter.flush();
      expect(sent, hasLength(calls));

      now = now.add(const Duration(minutes: 6));
      await reporter.flush();
      expect(sent, hasLength(calls + 1));
    });

    test('a 5xx answer or a lost connection keeps the report for the next try', () async {
      behaviour = (_) async => throw refusal(503);
      await reporter.report(StateError('down'), trace);

      expect(queued(), ['Bad state: down']);
      expect(reporter.stopped, isFalse);
    });
  });

  group('the call to the server', () {
    ApiClient clientFor(FakeAdapter adapter, {TokenStore? tokens}) => ApiClient(
          baseUrl: 'https://qistas.test/api/v1',
          tokens: tokens ?? MemoryTokenStore('qst_secret-token'),
          language: () => 'en',
          onUnauthorized: () {},
          adapter: adapter,
        );

    const report = {'class': 'StateError', 'message': 'Bad state: x', 'trace': '#0 main', 'version': '1.29.0', 'os': 'android', 'os_version': 'Linux', 'device': 'phone'};

    test('posts the report as JSON to /client-errors, with the token when there is one, and says nothing of where it comes from', () async {
      final adapter = FakeAdapter((_) => const FakeResponse(204, ''));

      await QistasApi(clientFor(adapter)).reportError(report);

      expect(adapter.last.method, 'POST');
      expect(adapter.last.uri.toString(), 'https://qistas.test/api/v1/client-errors');
      expect(adapter.last.headers['Authorization'], 'Bearer qst_secret-token');
      expect(adapter.last.headers['Content-Type'], 'application/json');
      expect(adapter.lastBody, report);
      final names = adapter.last.headers.keys.map((name) => name.toLowerCase());
      expect(names, isNot(contains('origin')));
      expect(names, isNot(contains('sec-fetch-site')));
    });

    test('sends no token when nobody is signed in', () async {
      final adapter = FakeAdapter((_) => const FakeResponse(204, ''));

      await QistasApi(clientFor(adapter, tokens: MemoryTokenStore())).reportError(report);

      expect(adapter.last.headers.containsKey('Authorization'), isFalse);
    });

    test('a refusal with no words reaches the reporter as its status', () async {
      final adapter = FakeAdapter((_) => const FakeResponse(403, ''));
      reporter = ErrorReporter(preferences: () async => prefs, send: QistasApi(clientFor(adapter)).reportError, facts: () => phone, clock: () => now);

      await reporter.report(StateError('from a browser'), trace);

      expect(adapter.requests, hasLength(1));
      expect(reporter.stopped, isTrue);
    });

    test('an old server that has no such route (404) stops it the same way', () async {
      final adapter = FakeAdapter((_) => apiError(404, 'not_found', 'Not found'));
      reporter = ErrorReporter(preferences: () async => prefs, send: QistasApi(clientFor(adapter)).reportError, facts: () => phone, clock: () => now);

      await reporter.report(StateError('first'), trace);
      await reporter.report(StateError('second'), trace);

      expect(adapter.requests, hasLength(1));
      expect(reporter.stopped, isTrue);
    });
  });

  group('the real facts of this phone', () {
    tearDown(() => debugDefaultTargetPlatformOverride = null);

    test('Android and iOS are the two that report, and say which', () {
      debugDefaultTargetPlatformOverride = TargetPlatform.android;
      expect(currentDeviceFacts().os, 'android');

      debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
      final facts = currentDeviceFacts();

      expect(facts.os, 'ios');
      expect(facts.version, AppConfig.appVersion);
    });

    test('anything else (a desktop build for development) does not report', () {
      for (final platform in [TargetPlatform.windows, TargetPlatform.macOS, TargetPlatform.linux, TargetPlatform.fuchsia]) {
        debugDefaultTargetPlatformOverride = platform;

        expect(currentDeviceFacts().os, isNull, reason: platform.name);
      }
    });

    test('a tablet is told by its shortest side, as in the sign-in history: 600 logical pixels', () {
      final view = binding.platformDispatcher.views.first;
      addTearDown(view.reset);

      view.devicePixelRatio = 2;
      view.physicalSize = const Size(1600, 2560);
      expect(currentDeviceFacts(dispatcher: binding.platformDispatcher).device, 'tablet');

      view.physicalSize = const Size(1199, 2560);
      expect(currentDeviceFacts(dispatcher: binding.platformDispatcher).device, 'phone');

      view.devicePixelRatio = 3;
      view.physicalSize = const Size(1080, 2400);
      expect(currentDeviceFacts(dispatcher: binding.platformDispatcher).device, 'phone');
    });

    test('with no screen to ask it is a phone', () {
      expect(currentDeviceFacts(dispatcher: _NoScreens()).device, 'phone');
    });
  });

  group('the hooks', () {
    ErrorReporting? reporting;
    late FlutterExceptionHandler? flutterBefore;
    late ErrorCallback? platformBefore;

    ErrorReporting install({bool isWeb = false}) => reporting = installErrorReporting(
          preferences: () async => prefs,
          send: (report) {
            sent.add(report);

            return behaviour(report);
          },
          facts: () => phone,
          clock: () => now,
          isWeb: isWeb,
          reportInDebug: true,
          flushAfter: Duration.zero,
        );

    setUp(() {
      flutterBefore = FlutterError.onError;
      platformBefore = PlatformDispatcher.instance.onError;
    });

    tearDown(() {
      reporting?.uninstall();
      reporting = null;
      FlutterError.onError = flutterBefore;
      PlatformDispatcher.instance.onError = platformBefore;
    });

    test('a framework error is reported, and the app\'s own handler still sees it', () async {
      final seen = <FlutterErrorDetails>[];
      FlutterError.onError = seen.add;
      install();

      FlutterError.onError!(FlutterErrorDetails(exception: StateError('framework'), stack: trace));
      await settle();

      expect(seen, hasLength(1));
      expect(messages(), ['Bad state: framework']);
    });

    test('a platform error is reported, and the answer is the previous handler\'s', () async {
      var asked = 0;
      PlatformDispatcher.instance.onError = (error, stack) {
        asked++;

        return true;
      };
      install();

      final handled = PlatformDispatcher.instance.onError!(StateError('platform'), trace);
      await settle();

      expect(handled, isTrue);
      expect(asked, 1);
      expect(messages(), ['Bad state: platform']);
    });

    test('a platform error with no handler before ours is left unhandled, as it was', () async {
      PlatformDispatcher.instance.onError = null;
      install();

      final handled = PlatformDispatcher.instance.onError!(StateError('platform'), trace);
      await settle();

      expect(handled, isFalse);
      expect(messages(), ['Bad state: platform']);
    });

    test('an error nothing else catches, in the app\'s zone, is reported and printed', () async {
      final printed = <String?>[];
      final before = debugPrint;
      debugPrint = (message, {wrapWidth}) => printed.add(message);
      addTearDown(() => debugPrint = before);

      install().run(() async => throw StateError('zone'));
      await settle();

      expect(messages(), ['Bad state: zone']);
      expect(printed.join('\n'), contains('zone'));
    });

    test('an error the framework and the zone both catch is reported once', () async {
      final error = StateError('both');
      install().run(() async => throw error);
      await settle();
      PlatformDispatcher.instance.onError!(error, trace);
      await settle();

      expect(messages(), ['Bad state: both']);
    });

    test('the sender breaking is not reported by the hooks it sits behind', () async {
      behaviour = (_) async => throw StateError('the sender broke');
      install();

      FlutterError.onError!(FlutterErrorDetails(exception: StateError('framework'), stack: trace));
      await settle();
      await settle();

      expect(messages(), ['Bad state: framework']);
    });

    test('runs the app\'s start-up and, once it is up, sends what an earlier run could not, now and whenever the app comes back', () async {
      behaviour = offline;
      await ErrorQueue(prefs, clock: () => now).add({'class': 'StateError', 'message': 'kept from before', 'trace': '', 'version': '1.29.0', 'os': 'android', 'os_version': 'x', 'device': 'phone'});
      behaviour = (_) async {};
      var ran = false;

      install().run(() async => ran = true);
      await settle();

      expect(ran, isTrue);
      expect(messages(), ['kept from before']);

      await ErrorQueue(prefs, clock: () => now).add({'class': 'StateError', 'message': 'kept meanwhile', 'trace': '', 'version': '1.29.0', 'os': 'android', 'os_version': 'x', 'device': 'phone'});
      binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle();

      expect(messages(), ['kept from before', 'kept meanwhile']);
    });

    test('stops watching the app when it is taken down', () async {
      final installed = install();
      installed.run(() async {});
      await settle();
      installed.uninstall();
      reporting = null;
      await ErrorQueue(prefs, clock: () => now).add({'class': 'StateError', 'message': 'kept', 'trace': '', 'version': '1.29.0', 'os': 'android', 'os_version': 'x', 'device': 'phone'});

      binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await settle();

      expect(sent, isEmpty);
    });

    test('puts the handlers back as they were', () async {
      final seen = <FlutterErrorDetails>[];
      FlutterError.onError = seen.add;
      final platform = PlatformDispatcher.instance.onError;
      final handler = FlutterError.onError;

      final installed = install();
      expect(FlutterError.onError, isNot(same(handler)));
      installed.uninstall();

      expect(FlutterError.onError, same(handler));
      expect(PlatformDispatcher.instance.onError, platform);
    });

    test('a sender that calls the installed hooks itself does not start a loop of reports', () async {
      install();
      behaviour = (_) {
        FlutterError.onError!(FlutterErrorDetails(exception: StateError('inner framework'), stack: trace));
        PlatformDispatcher.instance.onError!(StateError('inner platform'), trace);

        return Future<void>.value();
      };

      FlutterError.onError!(FlutterErrorDetails(exception: StateError('outer'), stack: trace));
      await settle();
      await settle();

      expect(messages(), ['Bad state: outer']);
    });

    test('in a debug build nothing is installed unless asked: what a developer breaks does not go to the live server', () async {
      final handler = FlutterError.onError;
      final platform = PlatformDispatcher.instance.onError;
      var ran = false;

      // flutter test is a debug build, as `flutter run` is
      final installed = reporting = installErrorReporting(
        preferences: () async => prefs,
        send: (report) {
          sent.add(report);

          return behaviour(report);
        },
        facts: () => phone,
        clock: () => now,
        flushAfter: Duration.zero,
      );
      installed.run(() async => ran = true);
      FlutterError.onError!(FlutterErrorDetails(exception: StateError('developer'), stack: trace));
      await settle();

      expect(installed.reporter, isNull);
      expect(FlutterError.onError, same(handler));
      expect(PlatformDispatcher.instance.onError, platform);
      expect(sent, isEmpty);
      expect(ran, isTrue, reason: 'the app still starts');
    });

    test('a debug build reports when it is told to (to a server of its own)', () async {
      final installed = install();

      FlutterError.onError!(FlutterErrorDetails(exception: StateError('on purpose'), stack: trace));
      await settle();

      expect(installed.reporter, isNotNull);
      expect(messages(), ['Bad state: on purpose']);
    });

    test('a release build reports without being asked', () async {
      final handler = FlutterError.onError;

      final installed = reporting = installErrorReporting(
        preferences: () async => prefs,
        send: (report) {
          sent.add(report);

          return behaviour(report);
        },
        facts: () => phone,
        clock: () => now,
        isDebug: false,
        flushAfter: Duration.zero,
      );
      FlutterError.onError!(FlutterErrorDetails(exception: StateError('released'), stack: trace));
      await settle();

      expect(installed.reporter, isNotNull);
      expect(FlutterError.onError, isNot(same(handler)));
      expect(messages(), ['Bad state: released']);
    });

    test('a release build in a browser still installs nothing', () async {
      final handler = FlutterError.onError;

      final installed = reporting = installErrorReporting(preferences: () async => prefs, send: (_) async {}, isWeb: true, isDebug: false);

      expect(installed.reporter, isNull);
      expect(FlutterError.onError, same(handler));
    });

    test('in a browser nothing is installed: its reports would all be refused', () async {
      final handler = FlutterError.onError;
      final platform = PlatformDispatcher.instance.onError;
      var ran = false;

      final installed = install(isWeb: true);
      installed.run(() async => ran = true);
      await settle();

      expect(installed.reporter, isNull);
      expect(FlutterError.onError, same(handler));
      expect(PlatformDispatcher.instance.onError, platform);
      expect(ran, isTrue, reason: 'the app still starts');
    });
  });
}

class _Unreadable {
  @override
  String toString() => throw StateError('no text');
}

class _NoScreens implements PlatformDispatcher {
  @override
  Iterable<FlutterView> get views => const [];

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
