import 'package:flutter/foundation.dart';

import '../core/money.dart';
import '../data/late_fees.dart';
import '../data/models.dart';

/// One instalment a payment would reach: how much of the payment goes to it, and whether that pays it in full.
@immutable
class CoveredInstallment {
  const CoveredInstallment(this.number, this.amount, this.total, {required this.settles});

  final int number;
  final Money amount;

  /// What the instalment comes to, for "60.00 of 100.00".
  final Money total;
  final bool settles;
}

/// What a payment would cover (Win Plan PP16).
@immutable
class PaymentCoverage {
  const PaymentCoverage(this.covers, this.leftOnLast, this.owedAfter, {this.toFees});

  final List<CoveredInstallment> covers;

  /// What stays owed on the last instalment it reaches.
  final Money? leftOnLast;
  final Money owedAfter;

  /// What goes to late fees (Win Plan K4); null when none does.
  final Money? toFees;
}

/// What [amount] would pay, by the server's own rule (App\Domain\Ledger\PaymentAllocator): what is due by [today] first
/// (the oldest first, ties by number), then the open late [fees] (oldest first), then what is not due yet; each filled
/// before the next is touched. Null when it is not a payment the server would take (nothing, or more than is owed).
/// [first], when given, is filled before anything else (Win Plan PP6), as the server does with apply_to.
PaymentCoverage? paymentCoverage(List<Installment> installments, Money amount, {String? first, List<LateFee> fees = const [], String today = '9999-12-31'}) {
  final open = fees.where((fee) => fee.isOpen && fee.remaining.isPositive).toList();
  final owed = open.fold(installments.fold(Money.zero, (Money sum, i) => i.remaining.isPositive ? sum + i.remaining : sum), (Money sum, fee) => sum + fee.remaining);
  if (!amount.isPositive || amount > owed) return null;

  // 0: the chosen instalment, 1: due by today, 2: late fees, 3: not due yet; then by day, instalment and period.
  final queue = <({int group, String day, int number, int period, Installment? installment, LateFee? fee})>[
    for (final i in installments)
      (group: i.id == first ? 0 : (i.dueDate.compareTo(today) <= 0 ? 1 : 3), day: i.dueDate, number: i.number, period: 0, installment: i, fee: null),
    for (final fee in open) (group: 2, day: fee.chargedOn, number: fee.installmentNumber, period: fee.period, installment: null, fee: fee),
  ]..sort((a, b) => a.group != b.group
      ? a.group.compareTo(b.group)
      : a.day != b.day
          ? a.day.compareTo(b.day)
          : a.number != b.number
              ? a.number.compareTo(b.number)
              : a.period.compareTo(b.period));

  final covers = <CoveredInstallment>[];
  Money? leftOnLast;
  Money? toFees;
  var left = amount;
  for (final line in queue) {
    final remaining = line.installment?.remaining ?? line.fee!.remaining;
    if (!remaining.isPositive) continue;
    final take = left < remaining ? left : remaining;
    final installment = line.installment;
    if (installment == null) {
      toFees = (toFees ?? Money.zero) + take;
    } else {
      leftOnLast = remaining - take;
      covers.add(CoveredInstallment(installment.number, take, installment.amount, settles: leftOnLast.isZero));
    }
    left = left - take;
    if (left.isZero) break;
  }

  return PaymentCoverage(covers, leftOnLast, owed - amount, toFees: toFees);
}
