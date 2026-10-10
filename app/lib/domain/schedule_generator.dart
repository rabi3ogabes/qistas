import 'package:flutter/foundation.dart';

import '../core/money.dart';

/// Why a schedule cannot be built; [field] says which input is at fault so a form can mark it.
class InvalidScheduleException implements Exception {
  const InvalidScheduleException(this.field, this.message, {this.reason, this.row, this.remaining});

  /// One of principal, down_payment, markup_value, count, frequency, first_due_date, custom_schedule.
  final String field;
  final String message;

  /// For the shop's own dates, what is wrong: count, date, order, amount or sum.
  final String? reason;

  /// The shop's own row at fault, counting from 1.
  final int? row;

  /// When the shop's own amounts miss the total: what is still to place (negative when they go over).
  final Money? remaining;

  @override
  String toString() => 'InvalidScheduleException($field): $message';
}

/// What a person asks of the schedule. Amounts are the text they typed (or the API sent), exactly as the server
/// receives it, so both ends read the same thing.
@immutable
class ScheduleRequest {
  const ScheduleRequest({
    required this.principal,
    required this.downPayment,
    required this.markupType,
    required this.markupValue,
    required this.count,
    required this.frequency,
    required this.firstDueDate,
    this.customSchedule,
  });

  /// From the snake_case map used by the API and by shared/schedule-vectors.json.
  factory ScheduleRequest.fromJson(Map<String, dynamic> json) => ScheduleRequest(
        principal: (json['principal'] ?? '').toString(),
        downPayment: (json['down_payment'] ?? '0').toString(),
        markupType: (json['markup_type'] ?? 'none').toString(),
        markupValue: (json['markup_value'] ?? '0').toString(),
        count: (json['count'] as num?)?.toInt() ?? 0,
        frequency: (json['frequency'] ?? '').toString(),
        firstDueDate: (json['first_due_date'] ?? '').toString(),
        customSchedule: json['custom_schedule'] is List
            ? [
                for (final row in (json['custom_schedule'] as List<dynamic>).cast<Map<String, dynamic>>())
                  ScheduleEntry((row['due_date'] ?? '').toString(), (row['amount'] ?? '').toString()),
              ]
            : null,
      );

  final String principal;
  final String downPayment;

  /// none, fixed or percent.
  final String markupType;
  final String markupValue;
  final int count;

  /// One of [ScheduleGenerator.frequencies], or [ScheduleGenerator.custom] for the shop's own dates.
  final String frequency;

  /// YYYY-MM-DD.
  final String firstDueDate;

  /// The shop's own dates and amounts, when [frequency] is custom; count and first due date then come from these.
  final List<ScheduleEntry>? customSchedule;
}

/// One line of the shop's own dates, as typed.
@immutable
class ScheduleEntry {
  const ScheduleEntry(this.dueDate, this.amount);

  /// YYYY-MM-DD.
  final String dueDate;
  final String amount;

  Map<String, String> toJson() => {'due_date': dueDate, 'amount': amount};
}

@immutable
class ScheduleRow {
  const ScheduleRow(this.number, this.dueDate, this.amount);

  final int number;

  /// YYYY-MM-DD.
  final String dueDate;
  final Money amount;
}

@immutable
class ScheduleResult {
  const ScheduleResult(this.financed, this.markup, this.total, this.installments);

  final Money financed;
  final Money markup;
  final Money total;
  final List<ScheduleRow> installments;
}

/// Builds an instalment schedule: a port of the server's generator that must give the very same numbers (both are
/// checked against shared/schedule-vectors.json).
///
///   financed = principal - down payment
///   markup   = fixed amount | percent of the financed amount (rounded half up to 2 decimals) | none
///   total    = financed + markup, split in whole cents; the LAST instalment takes the remainder
///   months   = counted from the FIRST due date, keeping its day, clamped to the end of shorter months
///   custom   = the shop's own rows: dates strictly increasing, amounts adding up to the total exactly
class ScheduleGenerator {
  static const int maxCount = 600;

  /// Without flexible schedules: weekly, every two weeks or monthly, up to 120.
  static const List<String> basicFrequencies = ['weekly', 'biweekly', 'monthly'];
  static const int basicMaxCount = 120;

  /// Each rhythm's step, in days or in months.
  static const Map<String, ({int days, int months})> frequencies = {
    'daily': (days: 1, months: 0),
    'weekly': (days: 7, months: 0),
    'biweekly': (days: 14, months: 0),
    'monthly': (days: 0, months: 1),
    'bimonthly': (days: 0, months: 2),
    'quarterly': (days: 0, months: 3),
    'semiannual': (days: 0, months: 6),
    'yearly': (days: 0, months: 12),
  };

  static const String custom = 'custom';

  static final RegExp _fourDecimals = RegExp(r'^-?\d+(?:\.\d{1,4})?$');
  static final RegExp _date = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$');

  ScheduleResult generate(ScheduleRequest r) {
    final principal = _cents(_scaled(r.principal, 'principal'), 'principal');
    final down = _cents(_scaled(r.downPayment, 'down_payment'), 'down_payment');
    final markupValue = _scaled(r.markupValue, 'markup_value'); // units of 1/10000

    if (principal <= BigInt.zero) {
      throw const InvalidScheduleException('principal', 'The principal must be greater than zero.');
    }
    if (down < BigInt.zero || down >= principal) {
      throw const InvalidScheduleException('down_payment', 'The down payment must be zero or more and less than the principal.');
    }
    if (markupValue < BigInt.zero) {
      throw const InvalidScheduleException('markup_value', 'The markup cannot be negative.');
    }
    if (r.frequency != custom && !frequencies.containsKey(r.frequency)) {
      throw InvalidScheduleException('frequency', 'The frequency must be one of: ${[...frequencies.keys, custom].join(', ')}.');
    }

    final financed = principal - down;
    final BigInt markup;
    switch (r.markupType) {
      case 'none':
        markup = BigInt.zero;
      case 'fixed':
        markup = _cents(markupValue, 'markup_value');
      case 'percent':
        // percent of the financed amount: cents * percent(1/10000) is millionths of a currency unit; divide by
        // 100 (truncating, as the server does at 6 decimals), then round half up to whole cents.
        final truncated = (financed * markupValue) ~/ BigInt.from(100);
        markup = (truncated + BigInt.from(5000)) ~/ BigInt.from(10000);
      default:
        throw const InvalidScheduleException('markup_type', 'The markup type must be none, fixed or percent.');
    }
    final total = financed + markup;

    if (r.frequency == custom) {
      return ScheduleResult(Money.fromCents(financed), Money.fromCents(markup), Money.fromCents(total), _custom(r.customSchedule ?? const [], total));
    }

    if (r.count < 1 || r.count > maxCount) {
      throw const InvalidScheduleException('count', 'The number of instalments must be between 1 and $maxCount.');
    }
    final first = _day(r.firstDueDate);

    final count = BigInt.from(r.count);
    if (total < count) {
      throw const InvalidScheduleException('principal', 'The total is too small to give every instalment at least one cent.');
    }
    final base = total ~/ count;
    final last = total - base * BigInt.from(r.count - 1);

    final rows = <ScheduleRow>[
      for (var n = 0; n < r.count; n++)
        ScheduleRow(n + 1, _format(_due(first, r.frequency, n)), Money.fromCents(n == r.count - 1 ? last : base)),
    ];

    return ScheduleResult(Money.fromCents(financed), Money.fromCents(markup), Money.fromCents(total), rows);
  }

  /// The shop's own rows, checked like the server checks them; a mistake names its row (counting from 1).
  List<ScheduleRow> _custom(List<ScheduleEntry> entries, BigInt total) {
    if (entries.isEmpty || entries.length > maxCount) {
      throw const InvalidScheduleException('custom_schedule', 'Give between 1 and $maxCount instalments.', reason: 'count');
    }

    final rows = <ScheduleRow>[];
    var sum = BigInt.zero;
    DateTime? previous;
    for (var i = 0; i < entries.length; i++) {
      final number = i + 1;
      final DateTime date;
      try {
        date = _day(entries[i].dueDate);
      } on InvalidScheduleException {
        throw InvalidScheduleException('custom_schedule', 'Row $number: the date must be a real date in YYYY-MM-DD format.', reason: 'date', row: number);
      }
      if (previous != null && !date.isAfter(previous)) {
        throw InvalidScheduleException('custom_schedule', 'Row $number: the date must be after the row before it.', reason: 'order', row: number);
      }
      final BigInt cents;
      try {
        cents = _cents(_scaled(entries[i].amount, 'custom_schedule'), 'custom_schedule');
      } on InvalidScheduleException {
        throw InvalidScheduleException('custom_schedule', 'Row $number: the amount is not valid.', reason: 'amount', row: number);
      }
      if (cents <= BigInt.zero) {
        throw InvalidScheduleException('custom_schedule', 'Row $number: the amount must be more than zero.', reason: 'amount', row: number);
      }

      rows.add(ScheduleRow(number, _format(date), Money.fromCents(cents)));
      sum += cents;
      previous = date;
    }

    if (sum != total) {
      throw InvalidScheduleException(
        'custom_schedule',
        'The instalments add up to ${Money.fromCents(sum).toDecimalString()}, but the total is ${Money.fromCents(total).toDecimalString()}.',
        reason: 'sum',
        remaining: Money.fromCents(total - sum),
      );
    }

    return rows;
  }

  /// The text as a whole number of ten-thousandths, or an error naming the field.
  BigInt _scaled(String text, String field) {
    if (!_fourDecimals.hasMatch(text)) {
      throw InvalidScheduleException(field, 'That is not a valid amount.');
    }
    final negative = text.startsWith('-');
    final parts = text.replaceFirst('-', '').split('.');
    final value = BigInt.parse(parts[0]) * BigInt.from(10000) + BigInt.parse((parts.length > 1 ? parts[1] : '').padRight(4, '0'));

    return negative ? -value : value;
  }

  /// Ten-thousandths to cents; more than two decimals is an error.
  BigInt _cents(BigInt scaled, String field) {
    if (scaled % BigInt.from(100) != BigInt.zero) {
      throw InvalidScheduleException(field, 'An amount can have at most 2 decimals.');
    }

    return scaled ~/ BigInt.from(100);
  }

  DateTime _day(String text) {
    final match = _date.firstMatch(text);
    if (match != null) {
      final year = int.parse(match.group(1)!);
      final month = int.parse(match.group(2)!);
      final day = int.parse(match.group(3)!);
      final date = DateTime.utc(year, month, day);
      // DateTime rolls 31 February over into March; a real date comes back unchanged.
      if (date.year == year && date.month == month && date.day == day) return date;
    }

    throw const InvalidScheduleException('first_due_date', 'The first due date must be a real date in YYYY-MM-DD format.');
  }

  /// Always counted from the first due date (never step by step), so a clamped February never drags later months.
  DateTime _due(DateTime first, String frequency, int n) {
    final step = frequencies[frequency]!;
    if (step.days > 0) return first.add(Duration(days: step.days * n));

    final months = first.month - 1 + step.months * n;
    final year = first.year + months ~/ 12;
    final month = months % 12 + 1;
    final lastDay = DateTime.utc(year, month + 1, 0).day;

    return DateTime.utc(year, month, first.day < lastDay ? first.day : lastDay);
  }

  String _format(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
}
