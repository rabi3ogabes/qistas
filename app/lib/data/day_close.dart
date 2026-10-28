import 'package:flutter/foundation.dart';

import '../core/money.dart';

// The end-of-day close (daily-habit wave A, H4), as the API writes it. Every figure is the server's: the app reads the
// amounts into [Money] (whole cents) and shows them, and never works a day's totals out itself.

Money _money(Object? value) => Money.parse((value ?? '0.00').toString());

Map<String, dynamic> _map(Object? value) => value is Map<String, dynamic> ? value : const {};

List<Map<String, dynamic>> _list(Object? value) => value is List<dynamic> ? value.whereType<Map<String, dynamic>>().toList() : const [];

String? _personName(Object? value) => value is Map<String, dynamic> ? value['name']?.toString() : null;

/// One person's cash side of a day.
@immutable
class DayCloseMember {
  const DayCloseMember({required this.name, required this.cash, required this.count});

  factory DayCloseMember.fromJson(Map<String, dynamic> json) =>
      DayCloseMember(name: (json['name'] ?? '').toString(), cash: _money(json['cash']), count: (json['count'] as num?)?.toInt() ?? 0);

  final String name;
  final Money cash;
  final int count;
}

/// A day's figures as they were when it was closed.
@immutable
class DayCloseTotals {
  const DayCloseTotals({required this.byMethod, required this.count, required this.perMember});

  factory DayCloseTotals.fromJson(Map<String, dynamic> json) => DayCloseTotals(
        byMethod: {for (final entry in _map(json['by_method']).entries) entry.key: _money(entry.value)},
        count: (json['count'] as num?)?.toInt() ?? 0,
        perMember: {for (final entry in _map(json['per_member']).entries) entry.key: DayCloseMember.fromJson(_map(entry.value))},
      );

  /// The net of each way of paying that had a line in the day, cash first.
  final Map<String, Money> byMethod;

  /// Payments taken in the day that were not voided in it.
  final int count;

  /// The cash side of the day by the person who recorded each line, keyed by user id.
  final Map<String, DayCloseMember> perMember;
}

/// One close of one day, as it was signed. A close is a record: reopening moves its status, never its figures.
@immutable
class DayCloseRecord {
  const DayCloseRecord({
    required this.id,
    required this.day,
    required this.version,
    required this.status,
    required this.expectedCash,
    required this.countedCash,
    required this.difference,
    required this.totals,
    required this.summaryText,
    this.note,
    this.closedBy,
    this.closedAt,
    this.reopenedBy,
    this.reopenedAt,
    this.reopenReason,
  });

  factory DayCloseRecord.fromJson(Map<String, dynamic> json) => DayCloseRecord(
        id: json['id'].toString(),
        day: (json['day'] ?? '').toString(),
        version: (json['version'] as num?)?.toInt() ?? 1,
        status: (json['status'] ?? 'closed').toString(),
        expectedCash: _money(json['expected_cash']),
        countedCash: _money(json['counted_cash']),
        difference: _money(json['difference']),
        totals: DayCloseTotals.fromJson(_map(json['totals'])),
        note: json['note']?.toString(),
        closedBy: _personName(json['closed_by']),
        closedAt: json['closed_at'] == null ? null : DateTime.tryParse(json['closed_at'].toString())?.toLocal(),
        reopenedBy: _personName(json['reopened_by']),
        reopenedAt: json['reopened_at'] == null ? null : DateTime.tryParse(json['reopened_at'].toString())?.toLocal(),
        reopenReason: json['reopen_reason']?.toString(),
        summaryText: (json['summary_text'] ?? '').toString(),
      );

  final String id;

  /// The workspace's own calendar day, YYYY-MM-DD.
  final String day;
  final int version;

  /// closed, or reopened once someone opened the day again to correct it.
  final String status;
  final Money expectedCash;
  final Money countedCash;

  /// Counted less expected: negative is short, positive is over.
  final Money difference;
  final DayCloseTotals totals;
  final String? note;

  /// Null once that person has left the team.
  final String? closedBy;
  final DateTime? closedAt;
  final String? reopenedBy;
  final DateTime? reopenedAt;
  final String? reopenReason;

  /// The sentence to share, in the language of the request. Shown as the server wrote it; never built on the device.
  final String summaryText;

  bool get isReopened => status == 'reopened';
}

/// A card, transfer, cheque or other line of the day; a void is its own negative line.
@immutable
class DayCloseLine {
  const DayCloseLine({required this.id, required this.customer, required this.amount, required this.method, this.note});

  factory DayCloseLine.fromJson(Map<String, dynamic> json) => DayCloseLine(
        id: json['id'].toString(),
        customer: (json['customer'] ?? '').toString(),
        amount: _money(json['amount']),
        method: (json['method'] ?? 'other').toString(),
        note: _nonEmpty(json['note']),
      );

  final String id;
  final String customer;
  final Money amount;
  final String method;
  final String? note;

  bool get isVoid => amount.isNegative;
}

/// Something worth a look today: a voided payment, a waived fee, a back-dated payment, a contract opened or cancelled.
@immutable
class DayCloseWatch {
  const DayCloseWatch({required this.kind, required this.id, required this.label, this.amount});

  factory DayCloseWatch.fromJson(Map<String, dynamic> json) => DayCloseWatch(
        kind: (json['kind'] ?? '').toString(),
        id: json['id'].toString(),
        label: (json['label'] ?? '').toString(),
        amount: json['amount'] == null ? null : _money(json['amount']),
      );

  /// void, waived_fee, back_dated, opened or cancelled.
  final String kind;
  final String id;

  /// What to say about it: the customer's name, or "N contracts opened" when more than ten were opened that day.
  final String label;

  /// What it concerned, always positive; null for an open account, which has no price.
  final Money? amount;
}

/// What the end-of-day screen shows: the cash to count on the workspace's own day, and the day's close if there is one.
@immutable
class DayCloseToday {
  const DayCloseToday({
    required this.day,
    required this.closingTime,
    required this.closed,
    required this.close,
    required this.expectedCash,
    required this.byMethod,
    required this.count,
    required this.nonCash,
    required this.watch,
    required this.canClose,
    required this.canReopen,
  });

  factory DayCloseToday.fromJson(Map<String, dynamic> json) => DayCloseToday(
        day: (json['day'] ?? '').toString(),
        closingTime: (json['closing_time'] ?? '20:00').toString(),
        closed: json['closed'] == true,
        close: json['close'] is Map<String, dynamic> ? DayCloseRecord.fromJson(json['close'] as Map<String, dynamic>) : null,
        expectedCash: _money(json['expected_cash']),
        byMethod: {for (final entry in _map(json['by_method']).entries) entry.key: _money(entry.value)},
        count: (json['count'] as num?)?.toInt() ?? 0,
        nonCash: _list(json['non_cash']).map(DayCloseLine.fromJson).toList(),
        watch: _list(json['watch']).map(DayCloseWatch.fromJson).toList(),
        canClose: json['can_close'] == true,
        canReopen: json['can_reopen'] == true,
      );

  /// Today on the workspace's own clock, YYYY-MM-DD.
  final String day;

  /// When the team is asked to close the day, "HH:MM", by the workspace's own clock.
  final String closingTime;

  /// True while a close of today stands.
  final bool closed;

  /// Today's latest close (a reopened one included), or null.
  final DayCloseRecord? close;

  /// Cash recorded today, less cash given back by voids; negative on a day that only gave money back.
  final Money expectedCash;

  /// The net of each way of paying that had a line today (empty when none).
  final Map<String, Money> byMethod;

  /// Payments taken today that were not voided today.
  final int count;
  final List<DayCloseLine> nonCash;
  final List<DayCloseWatch> watch;

  /// The person may close the day (not a viewer) and it is not closed.
  final bool canClose;

  /// It is closed and the person is an owner or a manager.
  final bool canReopen;

  /// Nothing was recorded in cash today.
  bool get nothingCollected => byMethod.isEmpty;
}

String? _nonEmpty(Object? value) {
  final text = value?.toString().trim() ?? '';

  return text.isEmpty ? null : text;
}
