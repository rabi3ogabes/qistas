import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// A report and the moment it was made.
class QueuedReport {
  const QueuedReport(this.report, this.at);

  final Map<String, dynamic> report;
  final DateTime at;
}

/// The crash reports the phone could not send yet, kept in its preferences so that they survive a restart.
///
/// At most [maxReports] are kept (the 21st pushes out the oldest) and none older than [maxAge]: an old crash helps
/// nobody, and a phone that was offline for a day should not wake up and send yesterday's. The age is checked whenever
/// the queue is read or written, so a stale report never takes the place of a new one.
///
/// Every operation on the same preferences waits for the one before it, whichever [ErrorQueue] it comes from, so two
/// reports arriving together (the app often crashes in bursts) never overwrite each other.
class ErrorQueue {
  ErrorQueue(this._prefs, {DateTime Function()? clock}) : _now = clock ?? DateTime.now;

  /// The preferences key; the Settings switch empties the queue by removing it.
  static const String key = 'crash_report_queue';

  static const int maxReports = 20;

  static const Duration maxAge = Duration(hours: 6);

  /// A clock set back would leave a report "from the future" that never grows old; one this far ahead is dropped.
  static const Duration _slack = Duration(minutes: 1);

  /// The end of the line of operations in progress, per preferences.
  static final Expando<Future<void>> _tails = Expando<Future<void>>('crash report queue');

  final SharedPreferences _prefs;
  final DateTime Function() _now;

  Future<T> _exclusive<T>(Future<T> Function() operation) {
    final run = (_tails[_prefs] ?? Future<void>.value()).then((_) => operation());
    _tails[_prefs] = run.then<void>((_) {}, onError: (Object _) {});

    return run;
  }

  /// Keeps [report], and forgets the oldest if that makes more than [maxReports].
  Future<void> add(Map<String, dynamic> report) => _exclusive(() async {
        final reports = [..._fresh(_read()), QueuedReport(report, _now())];

        await _write(_newest(reports));
      });

  /// Every report that waits, oldest first, taken out of the queue. A report that could not be sent after all goes back with [restore].
  Future<List<QueuedReport>> drain() => _exclusive(() async {
        final reports = _fresh(_read());
        await _remove();

        return reports;
      });

  /// Puts back what [drain] returned and could not be sent, in front of whatever arrived meanwhile.
  Future<void> restore(List<QueuedReport> reports) => _exclusive(() async {
        final merged = _fresh([...reports, ..._read()]);
        // Oldest first; reports made in the same instant keep the order they came in.
        final ordered = [for (final (index, entry) in merged.indexed) (index, entry)]
          ..sort((a, b) {
            final byTime = a.$2.at.compareTo(b.$2.at);

            return byTime != 0 ? byTime : a.$1.compareTo(b.$1);
          });

        await _write(_newest([for (final (_, entry) in ordered) entry]));
      });

  Future<void> clear() => _exclusive(_remove);

  /// Writes to the phone only when there is something there to remove: coming back to the app asks for the queue every time.
  Future<void> _remove() async {
    if (_prefs.containsKey(key)) await _prefs.remove(key);
  }

  List<QueuedReport> _newest(List<QueuedReport> reports) => reports.length > maxReports ? reports.sublist(reports.length - maxReports) : reports;

  List<QueuedReport> _fresh(List<QueuedReport> reports) {
    final now = _now();

    return [
      for (final entry in reports)
        if (_inRange(now.difference(entry.at))) entry,
    ];
  }

  bool _inRange(Duration age) => age <= maxAge && age >= -_slack;

  Future<void> _write(List<QueuedReport> reports) {
    if (reports.isEmpty) return _remove();

    return _prefs.setString(
      key,
      jsonEncode([
        for (final entry in reports) {'at': entry.at.millisecondsSinceEpoch, 'report': entry.report},
      ]),
    );
  }

  /// What the preferences hold, or nothing when it is missing or damaged: a queue that cannot be read is an empty one.
  List<QueuedReport> _read() {
    final raw = _prefs.getString(key);
    if (raw == null || raw.isEmpty) return [];

    try {
      final decoded = jsonDecode(raw);
      if (decoded is! List<dynamic>) return [];

      return [
        for (final item in decoded)
          if (item is Map<String, dynamic> && item['at'] is int && item['report'] is Map<String, dynamic>)
            QueuedReport(Map<String, dynamic>.from(item['report'] as Map<String, dynamic>), DateTime.fromMillisecondsSinceEpoch(item['at'] as int)),
      ];
    } on Object {
      return [];
    }
  }
}
