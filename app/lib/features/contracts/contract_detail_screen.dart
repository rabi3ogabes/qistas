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
import '../../core/ui/errors.dart';
import '../../core/ui/reason_dialog.dart';
import '../../data/models.dart';
import '../customers/customer_detail_screen.dart';
import '../payments/payment_success.dart';
import '../payments/payments_state.dart';
import '../payments/record_payment_sheet.dart';
import '../reminders/reminders.dart';
import 'contracts_screen.dart';

class ContractDetailScreen extends ConsumerStatefulWidget {
  const ContractDetailScreen({super.key, required this.id, this.recordPayment = false});

  final String id;

  /// Open the payment sheet as soon as the contract has loaded: the "Record payment" shortcuts end here.
  final bool recordPayment;

  @override
  ConsumerState<ContractDetailScreen> createState() => _ContractDetailScreenState();
}

class _ContractDetailScreenState extends ConsumerState<ContractDetailScreen> {
  bool _opened = false;

  Future<void> _record(Contract contract) async {
    final account = ref.read(accountProvider);
    final payment = await showRecordPaymentSheet(context, contract, currency: account?.currency ?? '');
    if (payment == null) return;

    refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
    unawaited(HapticFeedback.mediumImpact());
    if (mounted) await showPaymentSuccess(context, contract: contract, payment: payment);
  }

  Future<void> _cancel(Contract contract) async {
    final reason = await askReason(
      context,
      title: context.t('Cancel this contract?'),
      message: context.t('Payments already taken stay in the ledger. The unpaid instalments are no longer collected, and a place on your plan is freed.'),
      confirmLabel: context.t('Cancel contract'),
    );
    if (reason == null || !mounted) return;

    try {
      await ref.read(apiProvider).cancelContract(contract.id, reason: reason);
      refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
      await ref.read(authProvider.notifier).refresh();
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Contract cancelled.'))));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final id = widget.id;
    final contract = ref.watch(contractProvider(id));
    final account = ref.watch(accountProvider);

    // "Record payment" from anywhere lands here and opens the sheet once the contract is on screen.
    final loaded = contract.valueOrNull;
    if (widget.recordPayment && !_opened && loaded != null && (account?.canWrite ?? false) && loaded.takesPayments) {
      _opened = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) unawaited(_record(loaded));
      });
    }

    return Scaffold(
      appBar: AppBar(title: Directionality(textDirection: TextDirection.ltr, child: Text(loaded?.reference ?? context.t('Contract')))),
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
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 40),
            child: ContentColumn(padding: EdgeInsets.zero, child: _Body(contract: data, account: account, onRecord: () => _record(data), onCancel: () => _cancel(data))),
          ),
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.contract, required this.account, required this.onRecord, required this.onCancel});

  final Contract contract;
  final Account? account;
  final VoidCallback onRecord;
  final VoidCallback onCancel;

  String get _currency => account?.currency ?? '';

  /// The instalment to chase, as a reminder wants it: who, what is owed, since or until when.
  DueItem? _dueItem(Customer? customer) {
    final next = contract.next;
    if (next == null || !contract.isRunning) return null;
    final due = DateTime.tryParse(next.dueDate);
    final today = DateTime.now();
    final late = due != null && DateTime(due.year, due.month, due.day).isBefore(DateTime(today.year, today.month, today.day));
    final days = due == null ? 0 : DateTime(today.year, today.month, today.day).difference(DateTime(due.year, due.month, due.day)).inDays;

    return DueItem(
      installmentId: '',
      contractId: contract.id,
      reference: contract.reference,
      customerId: contract.customerId ?? '',
      customerName: contract.customerName ?? customer?.name ?? '',
      phone: customer?.phone ?? '',
      amount: next.remaining,
      dueDate: next.dueDate,
      daysLate: late ? days : null,
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final customer = contract.customerId == null ? null : ref.watch(customerProvider(contract.customerId!)).valueOrNull;
    final item = _dueItem(customer);
    final owed = contract.owed;
    final paid = contract.paid;
    final fraction = contract.paidFraction ?? 0.0;
    final onHero = c.onPrimary;
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
                Row(
                  children: [
                    ContractStateBadge(contract.state),
                    if (contract.type == 'cash') ...[const SizedBox(width: 8), QBadge(context.t('Cash sale'))],
                  ],
                ),
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
                if (owed != null) ...[
                  Text(contract.status == 'cancelled' ? context.t('Owed when cancelled') : context.t('Still owed'), style: text.labelLarge?.copyWith(color: onHero.withValues(alpha: 0.72), letterSpacing: 0.4)),
                  const SizedBox(height: 4),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerStart,
                    child: CountUpMoney(owed, _currency, color: onHero, style: text.displaySmall?.copyWith(fontSize: 42, fontWeight: FontWeight.w700, height: 1.1)),
                  ),
                  const SizedBox(height: 16),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: LinearProgressIndicator(value: fraction, minHeight: 8, color: c.accent, backgroundColor: onHero.withValues(alpha: 0.16)),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Flexible(child: Text(context.t('Paid :amount', {'amount': (paid ?? owed).format(_currency)}), style: text.labelMedium?.copyWith(color: onHero.withValues(alpha: 0.8)), maxLines: 1, overflow: TextOverflow.ellipsis)),
                      Text('${(fraction * 100).round()}%', style: text.labelMedium?.copyWith(color: onHero.withValues(alpha: 0.8))),
                    ],
                  ),
                ],
                if (contract.next != null && contract.isRunning) ...[
                  const SizedBox(height: 14),
                  SoftChip(
                    context.t('Next: :amount on :date', {'amount': contract.next!.remaining.format(_currency), 'date': formatDay(contract.next!.dueDate, language)}),
                    icon: contract.isLate ? Icons.schedule : Icons.event_outlined,
                    background: contract.isLate ? c.tintBlush : onHero.withValues(alpha: 0.12),
                    foreground: contract.isLate ? c.danger : onHero,
                  ),
                ],
              ],
            ),
          ),
        ),
        if (account?.canWrite == true && contract.takesPayments)
          Reveal(
            order: order++,
            child: Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Row(
                children: [
                  Expanded(child: QButton(label: context.t('Record a payment'), kind: QButtonKind.gold, icon: Icons.add_card_outlined, onPressed: onRecord)),
                  if (item != null) ...[
                    const SizedBox(width: 10),
                    _RoundAction(icon: Icons.chat_outlined, tooltip: context.t('Remind'), onPressed: () => showReminderSheet(context, item)),
                    if (customer != null && customer.phone.isNotEmpty) ...[
                      const SizedBox(width: 8),
                      _RoundAction(icon: Icons.call_outlined, tooltip: context.t('Call'), onPressed: () => callNumber(context, customer.phone)),
                    ],
                  ],
                ],
              ),
            ),
          ),
        if (contract.installments.isNotEmpty)
          Reveal(
            order: order++,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                QSectionTitle(context.t('Schedule')),
                QCard(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
                  child: Column(
                    children: [
                      for (final (index, installment) in contract.installments.indexed)
                        _TimelineRow(
                          installment: installment,
                          currency: _currency,
                          language: language,
                          isFirst: index == 0,
                          isLast: index == contract.installments.length - 1,
                          isNext: contract.next?.number == installment.number && contract.isRunning,
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        Reveal(
          order: order++,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
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
            ],
          ),
        ),
        Reveal(
          order: order++,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
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

class _RoundAction extends StatelessWidget {
  const _RoundAction({required this.icon, required this.tooltip, required this.onPressed});

  final IconData icon;
  final String tooltip;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => IconButton.outlined(
        tooltip: tooltip,
        onPressed: onPressed,
        icon: Icon(icon),
        style: IconButton.styleFrom(minimumSize: const Size(QistasMetrics.buttonHeight, QistasMetrics.buttonHeight), side: BorderSide(color: context.qc.ink.withValues(alpha: 0.18))),
      );
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

/// One instalment on the schedule's timeline: a node that says where it stands, joined to its neighbours by a line.
class _TimelineRow extends StatelessWidget {
  const _TimelineRow({required this.installment, required this.currency, required this.language, required this.isFirst, required this.isLast, required this.isNext});

  final Installment installment;
  final String currency;
  final String language;
  final bool isFirst;
  final bool isLast;
  final bool isNext;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final state = installment.state;
    final paid = state == 'paid';
    final late = state == 'overdue';
    final partly = state == 'partial' || (late && installment.paidAmount.isPositive);

    final Color ring = paid ? c.positive : (late ? c.danger : (isNext ? c.accent : c.line));
    final Widget node = Container(
      width: 22,
      height: 22,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: paid ? c.tintMint : (late ? c.tintBlush : (isNext ? c.tintSand : c.surface)),
        border: Border.all(color: ring, width: paid || late || isNext ? 2 : 1.5),
      ),
      child: paid
          ? Icon(Icons.check_rounded, size: 14, color: c.positive)
          : late
              ? Icon(Icons.priority_high_rounded, size: 14, color: c.danger)
              : isNext
                  ? Center(child: Container(width: 8, height: 8, decoration: BoxDecoration(color: c.accent, shape: BoxShape.circle)))
                  : null,
    );

    Widget rail(bool visible) => Expanded(child: Container(width: 2, color: visible ? c.line : Colors.transparent));

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 34,
            child: Column(children: [rail(!isFirst), node, rail(!isLast)]),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 10),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(formatDay(installment.dueDate, language), style: text.titleSmall?.copyWith(color: paid ? c.inkMuted : null)),
                        const SizedBox(height: 4),
                        Wrap(
                          spacing: 8,
                          runSpacing: 4,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            Text('#${installment.number}', style: text.bodySmall?.copyWith(color: c.inkMuted)),
                            InstallmentStateBadge(state),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      EndAmount(child: MoneyText(installment.amount, currency, style: text.titleSmall, strike: paid, color: paid ? c.inkMuted : null)),
                      if (partly) Text(context.t(':amount left', {'amount': installment.remaining.format(currency)}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                    ],
                  ),
                ],
              ),
            ),
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
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(shape: BoxShape.circle, color: line.isReversal ? c.tintBlush : c.surfaceAlt),
            child: Icon(line.isReversal ? Icons.undo_rounded : methodIcon(line.method), size: 20, color: line.voided ? c.inkMuted : (line.isReversal ? c.danger : c.accentText)),
          ),
          const SizedBox(width: 12),
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
                if (onVoid != null)
                  TextButton(
                    onPressed: onVoid,
                    style: TextButton.styleFrom(padding: EdgeInsets.zero, minimumSize: const Size(48, 36), alignment: AlignmentDirectional.centerStart, textStyle: text.labelMedium),
                    child: Text(context.t('Void payment')),
                  ),
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
