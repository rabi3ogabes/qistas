import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../core/ui/reason_dialog.dart';
import '../../data/models.dart';
import '../payments/payments_state.dart';
import 'ledger_line_sheet.dart';

/// An open contract (Win Plan PP4): the balance as the one figure, two big buttons ("they took", "they paid") and the
/// account, newest first, with the balance after every line. Lines are never edited; a mistake is voided.
class OpenAccountBody extends ConsumerWidget {
  const OpenAccountBody({super.key, required this.contract, required this.account, required this.onCancel});

  final Contract contract;
  final Account? account;
  final VoidCallback onCancel;

  String get _currency => account?.currency ?? '';

  Future<void> _add(BuildContext context, WidgetRef ref, LineDirection direction) async {
    final recorded = await showLedgerLineSheet(context, contract, direction);
    if (recorded == null || !context.mounted) return;

    refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
    unawaited(HapticFeedback.mediumImpact());
    final limit = contract.creditLimit;
    final message = recorded.overCreditLimit && limit != null && recorded.balance != null
        ? context.t('The balance is now :balance, past the credit limit of :limit.', {'balance': recorded.balance!.format(_currency), 'limit': limit.format(_currency)})
        : direction == LineDirection.took
            ? context.t(':amount added to the balance.', {'amount': recorded.line.amount.format(_currency)})
            : context.t('Payment of :amount recorded.', {'amount': recorded.line.amount.format(_currency)});
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
  }

  Future<void> _void(BuildContext context, WidgetRef ref, LedgerLine line) async {
    final reason = await askReason(
      context,
      title: line.isCharge ? context.t('Void this line?') : context.t('Void this payment?'),
      message: context.t('A reversal is added to the account; nothing is deleted.'),
      confirmLabel: context.t('Void'),
    );
    if (reason == null || !context.mounted) return;

    try {
      await ref.read(apiProvider).voidPayment(line.id, reason: reason);
      refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final onHero = c.onPrimary;
    final balance = contract.owed ?? Money.zero;
    final inCredit = balance.isNegative;
    final limit = contract.creditLimit;
    final overLimit = limit != null && balance > limit;
    final writes = account?.canWrite == true && contract.isRunning;
    var order = 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Reveal(
          order: order++,
          child: HeroPanel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [QBadge(context.t('Open account'), tone: QTone.gold), if (!contract.isRunning) ...[const SizedBox(width: 8), QBadge(context.t('Cancelled'))]]),
                const SizedBox(height: 14),
                if (contract.customerName != null && contract.customerId != null)
                  InkWell(
                    onTap: () => context.push('/customers/${contract.customerId}'),
                    borderRadius: BorderRadius.circular(8),
                    child: Row(
                      children: [
                        Flexible(child: Text(contract.customerName!, style: text.headlineSmall?.copyWith(color: onHero, fontWeight: FontWeight.w700), maxLines: 2, overflow: TextOverflow.ellipsis)),
                        Icon(context.isRtl ? Icons.chevron_left : Icons.chevron_right, color: onHero.withValues(alpha: 0.7)),
                      ],
                    ),
                  ),
                const SizedBox(height: 18),
                Text(inCredit ? context.t('In credit') : context.t('Balance'), style: text.labelLarge?.copyWith(color: onHero.withValues(alpha: 0.72), letterSpacing: 0.4)),
                const SizedBox(height: 4),
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: CountUpMoney(inCredit ? -balance : balance, _currency, color: onHero, style: text.displaySmall?.copyWith(fontSize: 42, fontWeight: FontWeight.w700, height: 1.1)),
                ),
                if (inCredit || limit != null) ...[
                  const SizedBox(height: 14),
                  SoftChip(
                    inCredit
                        ? context.t('They paid ahead; the next things they take come off this.')
                        : overLimit
                            ? context.t('Past the credit limit of :limit', {'limit': limit.format(_currency)})
                            : context.t('Credit limit: :limit', {'limit': limit!.format(_currency)}),
                    icon: overLimit ? Icons.warning_amber_rounded : Icons.speed_rounded,
                    background: overLimit ? c.tintBlush : onHero.withValues(alpha: 0.12),
                    foreground: overLimit ? c.danger : onHero,
                  ),
                ],
              ],
            ),
          ),
        ),
        if (writes)
          Reveal(
            order: order++,
            child: Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Row(
                children: [
                  Expanded(child: QButton(label: context.t('They took'), icon: Icons.add_rounded, onPressed: () => _add(context, ref, LineDirection.took))),
                  const SizedBox(width: 10),
                  Expanded(child: QButton(label: context.t('They paid'), icon: Icons.remove_rounded, kind: QButtonKind.gold, onPressed: () => _add(context, ref, LineDirection.paid))),
                ],
              ),
            ),
          ),
        Reveal(
          order: order++,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              QSectionTitle(context.t('Their account')),
              if (contract.transactions.isEmpty)
                QCard(child: Text(context.t('What they take and what they pay appear here, with the balance after each line.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)))
              else
                QCard(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Column(
                    children: [
                      for (final (index, line) in contract.transactions.indexed) ...[
                        if (index > 0) const Divider(height: 1),
                        _TabLine(
                          line: line,
                          currency: _currency,
                          language: language,
                          onVoid: account?.canDelete == true && contract.isRunning && line.canVoid && line.balanceAfter != null ? () => _void(context, ref, line) : null,
                        ),
                      ],
                    ],
                  ),
                ),
            ],
          ),
        ),
        if (account?.canDelete == true && contract.isRunning) ...[
          const SizedBox(height: 28),
          QButton(label: context.t('Cancel contract'), kind: QButtonKind.quiet, icon: Icons.block_outlined, onPressed: onCancel),
        ],
      ],
    );
  }
}

/// One line of the account: what it was, the money (red when it adds to what is owed, green when it takes from it)
/// and the balance once it was written.
class _TabLine extends StatelessWidget {
  const _TabLine({required this.line, required this.currency, required this.language, this.onVoid});

  final LedgerLine line;
  final String currency;
  final String language;
  final VoidCallback? onVoid;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final addsToBalance = line.isCharge == line.amount.isPositive;
    final magnitude = line.amount.isNegative ? -line.amount : line.amount;
    final before = line.balanceAfter == null;
    final title = switch (line.type) {
      'charge' => context.t('They took'),
      'charge_reversal' => context.t('Taken back off the balance'),
      'reversal' => context.t('Payment voided'),
      'down_payment' => context.t('Down payment'),
      _ => context.t('They paid'),
    };

    return InkWell(
      onTap: onVoid,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 32,
              height: 32,
              margin: const EdgeInsets.only(top: 2),
              alignment: Alignment.center,
              decoration: BoxDecoration(color: before ? c.surfaceAlt : (addsToBalance ? c.tintBlush : c.tintMint), shape: BoxShape.circle),
              child: Icon(addsToBalance ? Icons.add_rounded : Icons.remove_rounded, size: 18, color: before ? c.inkMuted : (addsToBalance ? c.danger : c.positive)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: 6,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(title, style: text.bodyLarge?.copyWith(color: line.voided || before ? c.inkMuted : null)),
                      if (line.tag != null) QBadge(tagLabel(context, line.tag!)),
                      if (line.voided) QBadge(context.t('Voided'), tone: QTone.bad),
                      if (before) QBadge(context.t('Before it became open')),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(formatMoment(line.paidAt, language), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                  if (line.note != null) Text(line.note!, style: text.bodySmall?.copyWith(color: c.inkMuted)),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                // Amounts read left to right in every language, sign first.
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(
                    '${addsToBalance ? '+' : '−'}${magnitude.format(currency)}',
                    style: text.titleSmall?.copyWith(
                      color: line.voided || before ? c.inkMuted : (addsToBalance ? c.danger : c.positive),
                      decoration: line.voided ? TextDecoration.lineThrough : null,
                    ),
                  ),
                ),
                if (line.balanceAfter != null)
                  Text(context.t('Balance :amount', {'amount': line.balanceAfter!.format(currency)}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
