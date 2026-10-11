import 'package:flutter/foundation.dart';

import '../core/money.dart';

/// What settling a contract early costs today (Win Plan PP6), worked out by the server: what is left, the profit not
/// yet earned, the discount asked for (never more than that profit) and the payoff.
@immutable
class EarlySettlementQuote {
  const EarlySettlementQuote({
    required this.remaining,
    required this.earnedProfit,
    required this.unearnedProfit,
    required this.rebate,
    required this.payoff,
    required this.capped,
  });

  factory EarlySettlementQuote.fromJson(Map<String, dynamic> json) {
    Money money(String key) => Money.parse((json[key] ?? '0').toString());

    return EarlySettlementQuote(
      remaining: money('remaining'),
      earnedProfit: money('earned_profit'),
      unearnedProfit: money('unearned_profit'),
      rebate: money('rebate'),
      payoff: money('payoff'),
      capped: json['capped'] == true,
    );
  }

  final Money remaining;
  final Money earnedProfit;
  final Money unearnedProfit;
  final Money rebate;
  final Money payoff;

  /// The discount asked for was more than the profit not yet earned, and was kept to it.
  final bool capped;
}

/// One instalment of a schedule before or after a plan change.
@immutable
class ScheduleRow {
  const ScheduleRow({required this.dueDate, required this.amount, this.number});

  factory ScheduleRow.fromJson(Map<String, dynamic> json) => ScheduleRow(
        dueDate: (json['due_date'] ?? '').toString(),
        amount: Money.parse((json['amount'] ?? '0').toString()),
        number: (json['number'] as num?)?.toInt(),
      );

  final String dueDate;
  final Money amount;
  final int? number;
}

/// What a change to the plan would do: the instalments it replaces and the ones it writes.
@immutable
class PlanPreview {
  const PlanPreview({required this.before, required this.after});

  factory PlanPreview.fromJson(Map<String, dynamic> json) => PlanPreview(
        before: [for (final row in (json['old_schedule'] as List<dynamic>? ?? const [])) ScheduleRow.fromJson(row as Map<String, dynamic>)],
        after: [for (final row in (json['new_schedule'] as List<dynamic>? ?? const [])) ScheduleRow.fromJson(row as Map<String, dynamic>)],
      );

  final List<ScheduleRow> before;
  final List<ScheduleRow> after;
}

/// A change to the plan, as kept on the contract: what, why, who and when.
@immutable
class ContractAmendment {
  const ContractAmendment({required this.id, required this.type, required this.reason, required this.createdAt, required this.before, required this.after, this.approvedBy});

  factory ContractAmendment.fromJson(Map<String, dynamic> json) {
    final preview = PlanPreview.fromJson(json);
    final by = json['approved_by'];

    return ContractAmendment(
      id: json['id'].toString(),
      type: (json['type'] ?? '').toString(),
      reason: (json['reason'] ?? '').toString(),
      createdAt: DateTime.tryParse((json['created_at'] ?? '').toString()) ?? DateTime.now(),
      before: preview.before,
      after: preview.after,
      approvedBy: by is Map ? by['name']?.toString() : null,
    );
  }

  final String id;

  /// reschedule | skip | move
  final String type;
  final String reason;
  final DateTime createdAt;
  final List<ScheduleRow> before;
  final List<ScheduleRow> after;
  final String? approvedBy;
}

/// What a person asks the plan to become (Win Plan PP6).
@immutable
class PlanChange {
  const PlanChange.reschedule({this.count, this.amount, required String this.firstDueDate})
      : type = 'reschedule',
        installmentId = null,
        dueDate = null;

  const PlanChange.skip(String this.installmentId)
      : type = 'skip',
        count = null,
        amount = null,
        firstDueDate = null,
        dueDate = null;

  const PlanChange.move(String this.installmentId, String this.dueDate)
      : type = 'move',
        count = null,
        amount = null,
        firstDueDate = null;

  final String type;
  final int? count;
  final Money? amount;
  final String? firstDueDate;
  final String? installmentId;
  final String? dueDate;

  Map<String, dynamic> toJson() => {
        'type': type,
        'count': ?count,
        'amount': ?amount?.toDecimalString(),
        'first_due_date': ?firstDueDate,
        'installment_id': ?installmentId,
        'due_date': ?dueDate,
      };
}
