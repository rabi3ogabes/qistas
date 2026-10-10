import 'package:flutter/foundation.dart';

import '../core/money.dart';
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
  const PaymentCoverage(this.covers, this.leftOnLast, this.owedAfter);

  final List<CoveredInstallment> covers;

  /// What stays owed on the last instalment it reaches.
  final Money? leftOnLast;
  final Money owedAfter;
}

/// What [amount] would pay, by the server's own rule (App\Domain\Ledger\PaymentAllocator): the oldest due first, ties by
/// number, each filled before the next is touched. Null when it is not a payment the server would take (nothing, or
/// more than is owed).
PaymentCoverage? paymentCoverage(List<Installment> installments, Money amount) {
  final owed = installments.fold(Money.zero, (Money sum, i) => i.remaining.isPositive ? sum + i.remaining : sum);
  if (!amount.isPositive || amount > owed) return null;

  final ordered = [...installments]..sort((a, b) => a.dueDate == b.dueDate ? a.number.compareTo(b.number) : a.dueDate.compareTo(b.dueDate));
  final covers = <CoveredInstallment>[];
  Money? leftOnLast;
  var left = amount;
  for (final installment in ordered) {
    if (!installment.remaining.isPositive) continue;
    final take = left < installment.remaining ? left : installment.remaining;
    leftOnLast = installment.remaining - take;
    covers.add(CoveredInstallment(installment.number, take, installment.amount, settles: leftOnLast.isZero));
    left = left - take;
    if (left.isZero) break;
  }

  return PaymentCoverage(covers, leftOnLast, owed - amount);
}
