import 'package:flutter/foundation.dart';

/// Payday learning (daily-habit wave A, H6): when a customer usually pays, and the date that suggests.
///
/// The server works the pattern out and sends it on the customer's own page (`GET /customers/{id}`, key `pay_pattern`);
/// this file reads it and does the one piece of arithmetic the phone owns: which date to offer. Nothing here ever changes
/// a date; a screen offers it and a person taps.

/// A customer's usual payday. [label] and [basis] are the server's own words in the language of the request: show them as
/// sent, never rebuild them from the figures.
@immutable
class PayPattern {
  const PayPattern({
    required this.day,
    required this.spread,
    required this.months,
    required this.within,
    required this.confidence,
    required this.endOfMonth,
    required this.label,
    required this.basis,
  });

  /// The usual day of the month, 1 to 28. 28 also stands for the 29th to the 31st: see [endOfMonth].
  final int day;

  /// How far apart the days run, in days.
  final int spread;

  /// How many months it was worked out from.
  final int months;

  /// The percent of those months paid within three days of [day].
  final int within;

  /// `high` or `medium`.
  final String confidence;

  /// They pay at the end of the month, whatever its length (the server sends [day] 28 with it).
  final bool endOfMonth;

  /// "Usually pays around day 27 of the month".
  final String label;

  /// "Seen in 83% of recent months".
  final String basis;

  /// The pattern in an answer, or null when there is none to read: no key, a null, or anything that is not what the server
  /// documents. A pattern is a hint, so a malformed one is ignored rather than allowed to break the screen it sits on.
  static PayPattern? fromJson(Object? json) {
    if (json is! Map) return null;
    final day = _whole(json['day']);
    final spread = _whole(json['spread']);
    final months = _whole(json['months']);
    final within = _whole(json['within']);
    final endOfMonth = json['end_of_month'];
    final confidence = json['confidence'];
    final label = json['label'];
    final basis = json['basis'];

    if (day == null || day < 1 || day > 28 || spread == null || months == null || within == null) return null;
    if (endOfMonth is! bool || confidence is! String || label is! String || basis is! String) return null;
    if (label.trim().isEmpty) return null;

    return PayPattern(day: day, spread: spread, months: months, within: within, confidence: confidence, endOfMonth: endOfMonth, label: label, basis: basis);
  }

  /// A whole number, also when a loose encoder wrote it as 27.0; null for anything else.
  static int? _whole(Object? value) => value is int ? value : (value is double && value.isFinite && value == value.truncateToDouble() ? value.toInt() : null);

  @override
  bool operator ==(Object other) =>
      other is PayPattern &&
      other.day == day &&
      other.spread == spread &&
      other.months == months &&
      other.within == within &&
      other.confidence == confidence &&
      other.endOfMonth == endOfMonth &&
      other.label == label &&
      other.basis == basis;

  @override
  int get hashCode => Object.hash(day, spread, months, within, confidence, endOfMonth, label, basis);
}

// ---------------------------------------------------------------------------------------------------- the rule
//
// The rule is written out, with its hand-computed dates, in shared/payday-vectors.json, and the website's
// resources/js/pay-day.js follows the same file. Whole years, months and days only: a date is never made from typed text as
// a moment, because a date read as UTC and shown in a local zone is a day out in half the world.

final RegExp _shape = RegExp(r'^([0-9]{4})-([0-9]{2})-([0-9]{2})$');

bool _isLeap(int year) => (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;

int _daysIn(int year, int month) => month == 2 ? (_isLeap(year) ? 29 : 28) : (month == 4 || month == 6 || month == 9 || month == 11 ? 30 : 31);

typedef _Day = ({int year, int month, int day});

/// A real calendar day in the years 1 to 9999, or null.
_Day? _real(int year, int month, int day) =>
    year < 1 || year > 9999 || month < 1 || month > 12 || day < 1 || day > _daysIn(year, month) ? null : (year: year, month: month, day: day);

/// The day a 'YYYY-MM-DD' text names, or null: exactly four, two and two ASCII digits, nothing before or after, and a day that
/// exists.
_Day? _read(String? text) {
  final match = text == null ? null : _shape.firstMatch(text);

  return match == null ? null : _real(int.parse(match[1]!), int.parse(match[2]!), int.parse(match[3]!));
}

String _write(_Day day) => '${day.year.toString().padLeft(4, '0')}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';

/// The first day on or after [from] that is the customer's payday, or null when there is none to give.
_Day? _next(_Day from, int day, bool endOfMonth) {
  if (day < 1 || day > 28) return null;
  if (endOfMonth) return (year: from.year, month: from.month, day: _daysIn(from.year, from.month));
  if (day >= from.day) return (year: from.year, month: from.month, day: day);
  if (from.month < 12) return (year: from.year, month: from.month + 1, day: day);

  return from.year >= 9999 ? null : (year: from.year + 1, month: 1, day: day);
}

/// The rule in the shape of the shared vectors: what the first-due field holds now ([typed], null or empty when it has
/// nothing), the workspace's [today], the customer's usual [day] (1 to 28) and whether they pay at the [endOfMonth]. Gives a
/// 'YYYY-MM-DD' date, or null for no suggestion: a day outside 1 to 28, or neither [typed] nor [today] a real day, or a result
/// past the year 9999. An invalid [typed] falls back to [today].
String? paydaySuggestion({String? typed, required String today, required int day, required bool endOfMonth}) {
  final from = _read(typed) ?? _read(today);
  final next = from == null ? null : _next(from, day, endOfMonth);

  return next == null ? null : _write(next);
}

/// The first date on or after [from] that falls on the pattern's day (the month's last day for an end-of-month pattern),
/// or null when there is none to give (past the year 9999). Only [from]'s calendar day counts, never its time or zone. The
/// answer is a local date at midnight, as the app's other dates are.
DateTime? nextPayday(DateTime from, PayPattern pattern) {
  final start = _real(from.year, from.month, from.day);
  final next = start == null ? null : _next(start, pattern.day, pattern.endOfMonth);

  return next == null ? null : DateTime(next.year, next.month, next.day);
}

/// [nextPayday], or [from]'s own day when there is none.
DateTime nextOccurrence(DateTime from, PayPattern pattern) => nextPayday(from, pattern) ?? DateTime(from.year, from.month, from.day);
