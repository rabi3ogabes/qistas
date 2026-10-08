import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../core/ui/reason_dialog.dart';
import '../../data/models.dart';
import '../payments/payments_state.dart';
import '../payments/record_payment_sheet.dart';
import 'contracts_screen.dart';

class ContractDetailScreen extends ConsumerWidget {
  const ContractDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final contract = ref.watch(contractProvider(id));
    final account = ref.watch(accountProvider);

    return Scaffold(
      appBar: AppBar(title: Directionality(textDirection: TextDirection.ltr, child: Text(contract.valueOrNull?.reference ?? context.t('Contract')))),
      body: contract.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 6)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(contractProvider(id))),
        data: (data) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(contractProvider(id));
            await ref.read(contractProvider(id).future).then<void>((_) {}, onError: (Object _) {});
          },
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            child: ContentColumn(padding: EdgeInsets.zero, child: _Body(contract: data, account: account)),
          ),
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.contract, required this.account});

  final Contract contract;
  final Account? account;

  String get _currency => account?.currency ?? '';

  Future<void> _record(BuildContext context, WidgetRef ref) async {
    final payment = await showRecordPaymentSheet(context, contract, currency: _currency);
    if (payment == null) return;

    refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
    if (context.mounted) {
      final settled = payment.contractOwed != null && payment.contractOwed!.isZero;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(settled ? context.t('Payment recorded. This contract is now settled.') : context.t('Payment of :amount recorded.', {'amount': payment.amount.format(_currency)}))),
      );
    }
  }

  Future<void> _cancel(BuildContext context, WidgetRef ref) async {
    final reason = await askReason(
      context,
      title: context.t('Cancel this contract?'),
      message: context.t('Payments already taken stay in the ledger. The unpaid instalments are no longer collected, and a place on your plan is freed.'),
      confirmLabel: context.t('Cancel contract'),
    );
    if (reason == null || !context.mounted) return;

    try {
      await ref.read(apiProvider).cancelContract(contract.id, reason: reason);
      refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
      await ref.read(authProvider.notifier).refresh();
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Contract cancelled.'))));
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final owed = contract.owed;
    final paid = contract.paid;
    final fraction = paid == null || contract.total.cents == BigInt.zero ? 0.0 : (paid.cents.toDouble() / contract.total.cents.toDouble()).clamp(0.0, 1.0);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        QCard(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  ContractStateBadge(contract.state),
                  const Spacer(),
                  if (contract.type == 'cash') QBadge(context.t('Cash sale')),
                ],
              ),
              const SizedBox(height: 12),
              if (contract.customerName != null && contract.customerId != null)
                InkWell(
                  onTap: () => context.push('/customers/${contract.customerId}'),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Icon(Icons.person_outline, size: 18, color: c.inkMuted),
                        const SizedBox(width: 8),
                        Expanded(child: Text(contract.customerName!, style: text.titleMedium)),
                        Icon(context.isRtl ? Icons.chevron_left : Icons.chevron_right, color: c.inkMuted),
                      ],
                    ),
                  ),
                ),
              if (owed != null) ...[
                const SizedBox(height: 12),
                Text(contract.status == 'cancelled' ? context.t('Owed when cancelled') : context.t('Still owed'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
                const SizedBox(height: 4),
                FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: MoneyText(owed, _currency, style: text.headlineMedium?.copyWith(fontWeight: FontWeight.w700))),
                const SizedBox(height: 14),
                ClipRRect(
                  borderRadius: BorderRadius.circular(999),
                  child: LinearProgressIndicator(value: fraction, minHeight: 8, color: c.accent, backgroundColor: c.surfaceAlt),
                ),
                const SizedBox(height: 8),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(context.t('Paid'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                    if (paid != null) MoneyText(paid, _currency, style: text.labelLarge),
                  ],
                ),
              ],
              if (contract.next != null && contract.isRunning) ...[
                const Divider(height: 28),
                Row(
                  children: [
                    Icon(Icons.event_outlined, size: 18, color: contract.isLate ? c.danger : c.inkMuted),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        context.t('Next: :amount on :date', {'amount': contract.next!.remaining.format(_currency), 'date': formatDay(contract.next!.dueDate, language)}),
                        style: text.bodyMedium?.copyWith(color: contract.isLate ? c.danger : null),
                      ),
                    ),
                  ],
                ),
              ],
              if (account?.canWrite == true && contract.takesPayments) ...[
                const SizedBox(height: 16),
                QButton(label: context.t('Record a payment'), icon: Icons.add_card_outlined, onPressed: () => _record(context, ref)),
              ],
            ],
          ),
        ),
        QSectionTitle(context.t('Terms')),
        QCard(
          child: Column(
            children: [
              _line(context, context.t('Sale price'), MoneyText(contract.principal, _currency, style: text.bodyLarge)),
              if (contract.downPayment.isPositive) _line(context, context.t('Down payment'), MoneyText(contract.downPayment, _currency, style: text.bodyLarge)),
              if (contract.type == 'scheduled') ...[
                _line(context, context.t('Financed'), MoneyText(contract.financed, _currency, style: text.bodyLarge)),
                if (contract.markupAmount.isPositive) _line(context, context.t('Markup'), MoneyText(contract.markupAmount, _currency, style: text.bodyLarge)),
                _line(context, context.t('Total to collect'), MoneyText(contract.total, _currency, style: text.titleSmall)),
                _line(context, context.t('Instalments'), Text('${contract.installmentCount} · ${_frequency(context, contract.frequency)}', style: text.bodyLarge)),
                _line(context, context.t('First due'), Text(formatDay(contract.firstDueDate, language), style: text.bodyLarge)),
              ],
              _line(context, context.t('Started'), Text(formatDay(contract.startDate, language), style: text.bodyLarge)),
              if (contract.notes != null) _line(context, context.t('Notes'), Flexible(child: Text(contract.notes!, style: text.bodyLarge, textAlign: TextAlign.end))),
            ],
          ),
        ),
        if (contract.installments.isNotEmpty) ...[
          QSectionTitle(context.t('Schedule')),
          QCard(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Column(
              children: [
                for (final (index, installment) in contract.installments.indexed) ...[
                  if (index > 0) const Divider(height: 1),
                  _InstallmentRow(installment: installment, currency: _currency, language: language),
                ],
              ],
            ),
          ),
        ],
        QSectionTitle(context.t('Payments')),
        if (contract.transactions.isEmpty)
          QCard(child: Text(context.t('No payments yet.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)))
        else
          QCard(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Column(
              children: [
                for (final (index, line) in contract.transactions.indexed) ...[
                  if (index > 0) const Divider(height: 1),
                  PaymentLine(
                    line: line,
                    currency: _currency,
                    language: language,
                    onVoid: account?.canDelete == true && line.canVoid ? () => voidPaymentFlow(context, ref, line, currency: _currency) : null,
                  ),
                ],
              ],
            ),
          ),
        if (account?.canDelete == true && contract.isRunning) ...[
          const SizedBox(height: 28),
          QButton(label: context.t('Cancel contract'), kind: QButtonKind.quiet, icon: Icons.block_outlined, onPressed: () => _cancel(context, ref)),
        ],
      ],
    );
  }

  Widget _line(BuildContext context, String label, Widget value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 7),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Flexible(child: Text(label, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.qc.inkMuted))),
            const SizedBox(width: 16),
            value,
          ],
        ),
      );

  String _frequency(BuildContext context, String frequency) => switch (frequency) {
        'weekly' => context.t('Weekly'),
        'biweekly' => context.t('Every two weeks'),
        _ => context.t('Monthly'),
      };
}

/// An instalment's state in a word.
class InstallmentStateBadge extends StatelessWidget {
  const InstallmentStateBadge(this.state, {super.key});

  final String state;

  @override
  Widget build(BuildContext context) => switch (state) {
        'paid' => QBadge(context.t('Paid'), tone: QTone.ok),
        'overdue' => QBadge(context.t('Overdue'), tone: QTone.bad),
        'partial' => QBadge(context.t('Part paid'), tone: QTone.warn),
        _ => QBadge(context.t('Upcoming')),
      };
}

class _InstallmentRow extends StatelessWidget {
  const _InstallmentRow({required this.installment, required this.currency, required this.language});

  final Installment installment;
  final String currency;
  final String language;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final partly = installment.state == 'partial' || (installment.state == 'overdue' && installment.paidAmount.isPositive);

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          SizedBox(width: 28, child: Text('${installment.number}', style: text.labelLarge?.copyWith(color: c.inkMuted))),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(formatDay(installment.dueDate, language), style: text.bodyLarge),
                const SizedBox(height: 4),
                InstallmentStateBadge(installment.state),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              MoneyText(installment.amount, currency, style: text.titleSmall, strike: installment.state == 'paid', color: installment.state == 'paid' ? c.inkMuted : null),
              if (partly) Text(context.t(':amount left', {'amount': installment.remaining.format(currency)}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
            ],
          ),
        ],
      ),
    );
  }
}

/// One line of the ledger: a payment, a down payment or a reversal.
class PaymentLine extends StatelessWidget {
  const PaymentLine({super.key, required this.line, required this.currency, required this.language, this.onVoid, this.showContract = false});

  final LedgerLine line;
  final String currency;
  final String language;
  final VoidCallback? onVoid;

  /// On the ledger list the line also names the customer and contract.
  final bool showContract;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final title = line.isReversal ? context.t('Reversal') : (line.type == 'down_payment' ? context.t('Down payment') : methodLabel(context, line.method));

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
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(title, style: text.titleSmall),
                    if (line.voided) QBadge(context.t('Voided'), tone: QTone.warn),
                    if (line.isReversal) QBadge(context.t('Reversal'), tone: QTone.neutral),
                  ],
                ),
                if (showContract && line.customerName != null) Text(line.customerName!, style: text.bodyMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
                if (showContract && line.contractReference != null) Directionality(textDirection: TextDirection.ltr, child: Text(line.contractReference!, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                Text(
                  [formatMoment(line.paidAt, language), if (line.takenBy != null) line.takenBy!].join('  ·  '),
                  style: text.bodySmall?.copyWith(color: c.inkMuted),
                ),
                if (line.note != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(line.note!, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                if (onVoid != null) TextButton(onPressed: onVoid, style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(48, 40), alignment: AlignmentDirectional.centerStart), child: Text(context.t('Void payment'))),
              ],
            ),
          ),
          const SizedBox(width: 12),
          EndAmount(child: MoneyText(line.amount, currency, style: text.titleSmall, strike: line.voided, color: line.amount.isNegative ? c.danger : (line.voided ? c.inkMuted : null))),
        ],
      ),
    );
  }
}
