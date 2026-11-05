import 'dart:async';
import 'dart:convert';
import 'dart:io' show HttpException, Platform, SocketException, TlsException;
import 'dart:ui' show ErrorCallback, PlatformDispatcher;

import 'package:dio/dio.dart' show DioException;
import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_exception.dart';
import '../config.dart';
import '../device_hint.dart';
import 'error_queue.dart';
import 'trace_scrubber.dart';

/// Sends one report to the server. A refusal or a lost connection is an [ApiException]; anything else it throws is
/// kept just the same: the report waits for the next try.
typedef ReportSender = Future<void> Function(Map<String, dynamic> report);

/// What the phone says about itself in a report: [os] is `android` or `ios` (null on any other platform, which does not
/// report), [osVersion] is the system's own words (the report cuts them), [device] is `phone` or `tablet`.
typedef DeviceFacts = ({String? os, String osVersion, String device, String version});

/// The Settings switch "Send crash reports": kept on this phone, on until it is turned off.
abstract final class CrashReports {
  static const String key = 'crash_reports';

  static bool isOn(SharedPreferences prefs) {
    final value = prefs.get(key);

    return value is bool ? value : true;
  }
}

/// This phone, as a report tells it: the system (`android` or `ios` from [defaultTargetPlatform], nothing on the web or
/// a desktop), its version, a phone or a tablet by the shortest side of the screen, and the app's version.
///
/// The screen is asked of the [dispatcher] (the engine's own unless a test brings one) because the reporter runs outside
/// any widget, with no `BuildContext` to ask. A phone without a screen to ask is a phone.
DeviceFacts currentDeviceFacts({PlatformDispatcher? dispatcher}) {
  final os = kIsWeb
      ? null
      : switch (defaultTargetPlatform) {
          TargetPlatform.android => 'android',
          TargetPlatform.iOS => 'ios',
          _ => null,
        };
  if (os == null) return (os: null, osVersion: '', device: 'phone', version: AppConfig.appVersion);

  var osVersion = '';
  try {
    osVersion = Platform.operatingSystemVersion;
  } on Object {
    // The system's own words are a courtesy; the server does not keep them.
  }

  var device = 'phone';
  try {
    final view = (dispatcher ?? PlatformDispatcher.instance).views.first;
    if (view.devicePixelRatio > 0) {
      // The same rule as the sign-in history: a tablet has 600 logical pixels on its short side.
      device = deviceHint(shortestSide: view.physicalSize.shortestSide / view.devicePixelRatio, os: os)['type'] == 'tablet' ? 'tablet' : 'phone';
    }
  } on Object {
    // No screen to ask: a phone.
  }

  return (os: os, osVersion: osVersion, device: device, version: AppConfig.appVersion);
}

/// Tells the server about the errors nothing in the app caught, when the person allows it.
///
/// What it sends is only the kind of error ([Object.runtimeType]), the first line of its message, a trace, the app's
/// version, the system's version and a phone or a tablet: all of it through [TraceScrubber], and cut to what the server
/// takes. It never sends a name, a number, an amount, what was typed, or an account; it sends nothing at all when
/// Settings > Send crash reports is off.
///
/// What it does not report: a failed network call (offline is normal, and the server sees a 5xx itself), a refusal
/// (validation, plan limits) and the same error again within a minute (a screen that fails on every frame is one report).
///
/// A report that cannot be sent waits in an [ErrorQueue] (20 at most, 6 hours at most) and goes with the next one, or
/// when the app comes back. The server's answers decide the rest: 403, 404 and 405 (a browser's report, or a server that
/// has no such place yet) stop it for the whole session and empty the queue; 413 and 422 drop that report, which can
/// never succeed; 429 waits as long as asked; anything else keeps the report for later.
///
/// It is built to be harmless. Every public method returns normally, whatever goes wrong inside it; what goes wrong
/// inside it runs in its own zone, where an error is swallowed instead of becoming a report of its own; and a report made
/// while the reporter is busy is ignored. It does not change what the app does with an error: it only listens.
class ErrorReporter with WidgetsBindingObserver {
  ErrorReporter({
    required Future<SharedPreferences> Function() preferences,
    required ReportSender send,
    DeviceFacts Function()? facts,
    String Function(String text, {int max})? scrub,
    DateTime Function()? clock,
  })  : _preferences = preferences,
        _send = send,
        _facts = facts ?? currentDeviceFacts,
        _scrub = scrub ?? TraceScrubber.clean,
        _clock = clock ?? DateTime.now;

  /// The most bytes a body may have; the server refuses more than 16 KB (413), and this leaves room for the framing.
  static const int _maxBody = 15000;

  /// How long the same error is reported once.
  static const Duration _window = Duration(minutes: 1);

  /// How long a 429 without a time to wait waits, and the longest it ever waits.
  static const int _defaultPause = 60;
  static const int _longestPause = 300;

  /// A marker on the zone the reporter does its own work in.
  static final Object _inside = Object();

  /// Where the reporter does its own work. An error nobody caught there is swallowed (it would otherwise reach the app's
  /// handlers and become a report of its own), and the marker tells [report] that it is being called from inside.
  final Zone _zone = Zone.current.fork(
    specification: ZoneSpecification(handleUncaughtError: (self, parent, zone, error, stack) {}),
    zoneValues: {_inside: true},
  );

  final Future<SharedPreferences> Function() _preferences;
  final ReportSender _send;
  final DeviceFacts Function() _facts;
  final String Function(String text, {int max}) _scrub;
  final DateTime Function() _clock;

  Future<SharedPreferences>? _opened;
  bool _stopped = false;
  bool _flushing = false;
  bool _again = false;
  bool _watching = false;
  DateTime? _pausedUntil;
  Object? _lastError;
  DateTime? _lastErrorAt;
  final Map<String, DateTime> _recent = {};

  /// The server said it does not want reports (a 403, a 404 or a 405): nothing is sent or kept until the app is started again.
  bool get stopped => _stopped;

  // ---------------------------------------------------------------- what the app hands over

  /// Reports a framework error (`FlutterError.onError`). Errors the framework marks as handled (`silent`) are not reported.
  void onFlutterError(FlutterErrorDetails details) {
    if (details.silent) return;
    _hand(details.exception, details.stack ?? StackTrace.empty);
  }

  /// Reports an error the platform hook caught. Always false: whether the app goes on, and what it prints, is not for the reporter to change.
  bool onPlatformError(Object error, StackTrace stack) {
    _hand(error, stack);

    return false;
  }

  /// Reports an error nothing in the app's zone caught, and prints it as the zone would have, so that the console still shows it.
  void onZoneError(Object error, StackTrace stack) {
    _hand(error, stack);
    try {
      debugPrint('Unhandled Exception: $error\n$stack');
    } on Object {
      // A text that cannot be printed cannot be reported either; the app goes on.
    }
  }

  void _hand(Object error, StackTrace stack) {
    try {
      unawaited(report(error, stack));
    } on Object {
      // Never an error out of an error handler.
    }
  }

  // ---------------------------------------------------------------- reporting

  /// Reports [error]: builds the report, keeps it, and tries to send what is kept. Completes when that is done, and never throws.
  Future<void> report(Object error, StackTrace stack) {
    if (Zone.current[_inside] == true) return Future<void>.value();

    return _guarded(() => _report(error, stack));
  }

  /// Tries to send what is kept. At most one run at a time: a call that comes while one is under way asks it to look again when it is done.
  Future<void> flush() => Zone.current[_inside] == true ? _flush() : _guarded(_flush);

  /// Runs [job] in the reporter's own zone: whatever it throws, now or later, ends there, and is not a report. (One zone
  /// for every job, not one each: Dart does not hand an error from a future of one error zone to a listener in another.)
  Future<void> _guarded(Future<void> Function() job) {
    final done = Completer<void>();
    _zone.run(() {
      unawaited(() async {
        try {
          await job();
        } on Object {
          // swallowed
        } finally {
          if (!done.isCompleted) done.complete();
        }
      }());
    });

    return done.future;
  }

  Future<void> _report(Object error, StackTrace stack) async {
    if (_stopped || _isExpected(error) || _isSame(error)) return;

    final prefs = await _open();
    if (!CrashReports.isOn(prefs)) return;

    final report = _build(error, stack);
    if (report == null || _isRepeat(report)) return;

    await ErrorQueue(prefs, clock: _clock).add(report);
    await _flush();
  }

  /// Not an error of the app: a lost connection, a time-out, a refusal the server wrote. (Offline is normal.)
  static bool _isExpected(Object error) =>
      error is ApiException ||
      error is SocketException ||
      error is HttpException ||
      error is TlsException ||
      error is TimeoutException ||
      error is DioException ||
      error.runtimeType.toString() == 'NetworkImageLoadException';

  /// The same error object, handed over by two of the hooks.
  bool _isSame(Object error) {
    final now = _clock();
    final last = _lastErrorAt;
    final same = identical(error, _lastError) && last != null && now.difference(last) < _window;
    if (!same) {
      _lastError = error;
      _lastErrorAt = now;
    }

    return same;
  }

  /// The same class, message and first frames within the last minute.
  bool _isRepeat(Map<String, dynamic> report) {
    final now = _clock();
    _recent.removeWhere((_, at) => now.difference(at) >= _window);

    final frames = (report['trace'] as String).split('\n').take(3).join('\n');
    final fingerprint = '${report['class']}|${report['message']}|$frames';
    if (_recent.containsKey(fingerprint)) return true;

    if (_recent.length >= 64) _recent.remove(_recent.keys.first);
    _recent[fingerprint] = now;

    return false;
  }

  /// The report, or null when this platform does not report or when the text could not be cleaned: it fails closed.
  Map<String, dynamic>? _build(Object error, StackTrace stack) {
    try {
      final facts = _facts();
      final os = facts.os;
      if (os == null) return null;

      final className = _scrub(error.runtimeType.toString(), max: TraceScrubber.classMax);
      var trace = _scrub(stack.toString(), max: TraceScrubber.traceMax);
      final report = <String, dynamic>{
        'class': className.isEmpty ? 'Error' : className,
        // Only the first line: what follows is often what was typed (a FormatException prints its source) or a dump of widgets.
        'message': _scrub(_firstLine(error.toString()), max: TraceScrubber.messageMax),
        'trace': trace,
        'version': TraceScrubber.cut(facts.version, 16),
        'os': os,
        'os_version': _scrub(facts.osVersion, max: 24),
        'device': facts.device,
      };

      // Letters of some scripts take three or four bytes: the body, not the count of characters, is what the server limits.
      while (trace.isNotEmpty && utf8.encode(jsonEncode(report)).length > _maxBody) {
        trace = TraceScrubber.cut(trace, trace.runes.length ~/ 2);
        report['trace'] = trace;
      }

      return report;
    } on Object {
      return null;
    }
  }

  static String _firstLine(String text) {
    for (final line in text.split('\n')) {
      final trimmed = line.trim();
      if (trimmed.isNotEmpty) return trimmed;
    }

    return '';
  }

  // ---------------------------------------------------------------- sending

  Future<SharedPreferences> _open() async {
    try {
      return await (_opened ??= _preferences());
    } on Object {
      _opened = null;
      rethrow;
    }
  }

  Future<void> _flush() async {
    if (_stopped) return;
    if (_flushing) {
      _again = true;

      return;
    }

    _flushing = true;
    try {
      bool finished;
      do {
        _again = false;
        finished = await _sendKept();
      } while (finished && _again);
    } finally {
      _flushing = false;
    }
  }

  /// One pass over what is kept. False when it stopped before the end (a failure, a pause, the server saying no, the switch turned off).
  Future<bool> _sendKept() async {
    final prefs = await _open();
    if (!CrashReports.isOn(prefs)) return false;

    final pausedUntil = _pausedUntil;
    if (pausedUntil != null && _clock().isBefore(pausedUntil)) return false;

    final queue = ErrorQueue(prefs, clock: _clock);
    final batch = await queue.drain();

    for (var i = 0; i < batch.length; i++) {
      // The person may have turned reports off while one was on its way: nothing more is sent, nothing is brought back.
      if (!CrashReports.isOn(prefs)) {
        await queue.clear();

        return false;
      }

      try {
        await _send(batch[i].report);
      } on ApiException catch (e) {
        switch (e.status) {
          case 403 || 404 || 405:
            _stopped = true;
            await queue.clear();

            return false;
          case 413 || 422:
            continue; // this one can never succeed; the others can
          case 429:
            _pausedUntil = _clock().add(Duration(seconds: (e.retryAfter ?? _defaultPause).clamp(1, _longestPause)));
        }
        await _keep(prefs, queue, batch.sublist(i));

        return false;
      } on Object {
        await _keep(prefs, queue, batch.sublist(i));

        return false;
      }
    }

    return true;
  }

  Future<void> _keep(SharedPreferences prefs, ErrorQueue queue, List<QueuedReport> unsent) async {
    if (CrashReports.isOn(prefs)) await queue.restore(unsent);
  }

  // ---------------------------------------------------------------- the app coming back

  /// Starts sending what was kept whenever the app comes back to the screen. Watching twice is watching once.
  void watch() {
    if (_watching) return;
    WidgetsBinding.instance.addObserver(this);
    _watching = true;
  }

  void unwatch() {
    if (!_watching) return;
    WidgetsBinding.instance.removeObserver(this);
    _watching = false;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(flush());
  }
}

/// What [installErrorReporting] put in place, and the way to run the app inside it.
class ErrorReporting {
  ErrorReporting._(this.reporter, this._flushAfter, this._previousFlutter, this._flutterHook, this._previousPlatform, this._platformHook);

  /// Nothing installed (a browser): [run] only runs the body.
  ErrorReporting._off()
      : reporter = null,
        _flushAfter = Duration.zero,
        _previousFlutter = null,
        _flutterHook = null,
        _previousPlatform = null,
        _platformHook = null;

  /// The reporter, or null where none is installed.
  final ErrorReporter? reporter;

  final Duration _flushAfter;
  final FlutterExceptionHandler? _previousFlutter;
  final FlutterExceptionHandler? _flutterHook;
  final ErrorCallback? _previousPlatform;
  final ErrorCallback? _platformHook;
  Timer? _timer;

  /// Runs [body], the app's start-up (it ends with `runApp`), in a zone that reports what nothing else catches. Once the
  /// body is done the reporter starts watching the app come back, and sends what an earlier run could not.
  void run(Future<void> Function() body) {
    final reporter = this.reporter;
    if (reporter == null) {
      unawaited(body());

      return;
    }

    unawaited(
      runZonedGuarded<Future<void>>(() async {
            await body();
            _up(reporter);
          }, reporter.onZoneError) ??
          Future<void>.value(),
    );
  }

  void _up(ErrorReporter reporter) {
    try {
      reporter.watch();
    } on Object {
      // No binding to watch: the reports still go out with the next error.
    }
    _timer = Timer(_flushAfter, () => unawaited(reporter.flush()));
  }

  /// Puts the handlers back as they were found (unless something else has replaced ours since) and stops watching.
  void uninstall() {
    _timer?.cancel();
    _timer = null;

    final reporter = this.reporter;
    if (reporter == null) return;

    try {
      reporter.unwatch();
    } on Object {
      // Nothing was being watched.
    }
    if (identical(FlutterError.onError, _flutterHook)) FlutterError.onError = _previousFlutter;
    if (identical(PlatformDispatcher.instance.onError, _platformHook)) PlatformDispatcher.instance.onError = _previousPlatform;
  }
}

/// Installs crash reporting: `FlutterError.onError` and `PlatformDispatcher.instance.onError` report, then do what they
/// did before (the console output and the app's behaviour are unchanged), and the returned [ErrorReporting.run] is the
/// zone the app runs in. Nothing is installed in a browser: its reports always carry an `Origin` header and the server
/// refuses every one.
///
/// Call it first thing in `main`, before the bindings exist: the preferences are opened when the first report needs them.
/// A test calls it with its own [preferences], [send] and [facts], and [ErrorReporting.uninstall]s afterwards.
ErrorReporting installErrorReporting({
  required Future<SharedPreferences> Function() preferences,
  required ReportSender send,
  DeviceFacts Function()? facts,
  DateTime Function()? clock,
  bool isWeb = kIsWeb,
  Duration flushAfter = const Duration(seconds: 3),
}) {
  if (isWeb) return ErrorReporting._off();

  final reporter = ErrorReporter(preferences: preferences, send: send, facts: facts, clock: clock);
  final previousFlutter = FlutterError.onError;
  final previousPlatform = PlatformDispatcher.instance.onError;

  void flutterHook(FlutterErrorDetails details) {
    reporter.onFlutterError(details);
    (previousFlutter ?? FlutterError.presentError)(details);
  }

  bool platformHook(Object error, StackTrace stack) {
    reporter.onPlatformError(error, stack);

    return previousPlatform?.call(error, stack) ?? false;
  }

  FlutterError.onError = flutterHook;
  PlatformDispatcher.instance.onError = platformHook;

  return ErrorReporting._(reporter, flushAfter, previousFlutter, flutterHook, previousPlatform, platformHook);
}
