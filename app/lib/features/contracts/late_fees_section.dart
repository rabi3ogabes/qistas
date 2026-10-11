import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../core/ui/reason_dialog.dart';
import '../../data/late_fees.dart';
import '../../data/models.dart';
import '../payments/payments_state.dart';

/// A contract's late fees (Win Plan K4): the policy it was made with, then each fee on its own line. An owner or a
/// manager waives an open one, always saying why.
class LateFeesSection extends ConsumerWidget {
  const LateFeesSection({super.key, required this.contract, required this.currency, required this.language, required this.canWaive});

  final Contract contract;
  final String currency;
  final String language;
  final bool canWaive;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final policy = contract.lateFeePolicy;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Late fees')),
        QCard(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (policy != null)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
                  child: Text(policySentence(context, policy, currency), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
                ),
              if (contract.lateFees.isEmpty)
                Padding(padding: const EdgeInsets.fromLTRB(16, 4, 16, 14), child: Text(context.t('No late fees so far.'), style: text.bodyMedium))
              else
                for (final (index, fee) in contract.lateFees.indexed) ...[
                  if (index > 0 || policy != null) const Divider(height: 1),
                  _FeeLine(
                    fee: fee,
                    currency: currency,
                    language: language,
                    onWaive: canWaive && fee.isOpen && contract.status != 'cancelled' ? () => waiveLateFeeFlow(context, ref, contract, fee, currency: currency) : null,
                  ),
                ],
            ],
          ),
        ),
      ],
    );
  }
}

/// "10.00, once, from the day after the grace days. At most 50.00 per instalment. The fees go to charity."
String policySentence(BuildContext context, LateFeePolicy policy, String currency) {
  final when = switch (policy.frequency) {
    'daily' => context.t('every day it is late'),
    'weekly' => context.t('every week it is late'),
    'monthly' => context.t('every month it is late'),
    _ => context.t('once'),
  };
  final fee = policy.isPercent ? context.t(':percent% of what is still owed on the instalment', {'percent': policy.amount.toDecimalString().replaceFirst(RegExp(r'\.?0+$'), '')}) : policy.amount.format(currency);

  return [
    context.t(':fee, :when, from the day after the grace days.', {'fee': fee, 'when': when}),
    if (policy.capPerInstallment != null) context.t('At most :amount per instalment.', {'amount': policy.capPerInstallment!.format(currency)}),
    if (policy.capPerContract != null) context.t('At most :amount for the contract.', {'amount': policy.capPerContract!.format(currency)}),
    if (policy.charity) context.t('The fees go to charity.'),
  ].join(' ');
}

class _FeeLine extends StatelessWidget {
  const _FeeLine({required this.fee, required this.currency, required this.language, this.onWaive});

  final LateFee fee;
  final String currency;
  final String language;
  final VoidCallback? onWaive;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final (label, tone) = switch (fee.status) {
      'paid' => (context.t('Paid'), QTone.ok),
      'waived' => (context.t('Waived'), QTone.neutral),
      _ => (context.t('Owed'), QTone.bad),
    };

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(context.t('Instalment :number', {'number': fee.installmentNumber}), style: text.titleSmall),
                    QBadge(label, tone: tone),
                    if (fee.charity) QBadge(context.t('For charity'), tone: QTone.info),
                  ],
                ),
                Text(formatDay(fee.chargedOn, language), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                if (fee.paidAmount.isPositive && fee.status != 'paid')
                  Text(context.t('Paid :amount', {'amount': fee.paidAmount.format(currency)}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                if (fee.status == 'waived')
                  Text(
                    [
                      if (fee.waivedBy != null && fee.waivedAt != null) context.t('Waived by :name on :date', {'name': fee.waivedBy!, 'date': formatDay(fee.waivedAt!, language)}),
                      ?fee.waivedReason,
                    ].join(': '),
                    style: text.bodySmall?.copyWith(color: c.inkMuted),
                  ),
                if (onWaive != null)
                  TextButton(
                    onPressed: onWaive,
                    style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(48, 36), alignment: AlignmentDirectional.centerStart, textStyle: text.labelMedium),
                    child: Text(context.t('Waive this fee')),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          EndAmount(child: MoneyText(fee.amount, currency, style: text.titleSmall, strike: fee.status == 'waived', color: fee.status == 'waived' ? c.inkMuted : null)),
        ],
      ),
    );
  }
}

/// Waives [fee] after asking why: the reason is required and kept with it. Nothing is deleted.
Future<bool> waiveLateFeeFlow(BuildContext context, WidgetRef ref, Contract contract, LateFee fee, {required String currency}) async {
  final reason = await askReason(
    context,
    title: context.t('Waive this fee?'),
    message: context.t('The customer no longer owes what is left of this fee (:amount). What was paid on it stays paid.', {'amount': fee.remaining.format(currency)}),
    confirmLabel: context.t('Waive fee'),
    required: true,
  );
  if (reason == null || reason.isEmpty || !context.mounted) return false;

  try {
    await ref.read(apiProvider).waiveLateFee(fee.id, reason: reason);
    refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Late fee waived.'))));

    return true;
  } on ApiException catch (e) {
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));

    return false;
  }
}
