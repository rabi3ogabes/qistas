import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/contract_account.dart';
import '../../data/models.dart';
import '../contracts/contracts_screen.dart';
import '../contracts/ledger_line_sheet.dart';
import '../documents/document_options_sheet.dart';
import '../payments/payment_success.dart';
import '../payments/payments_state.dart';
import '../payments/record_payment_sheet.dart';

/// One contract's account for the customer's screen: its lines with a running balance, what is unpaid, the totals.
final contractAccountProvider = FutureProvider.autoDispose.family<ContractAccount, String>((ref, id) => ref.watch(apiProvider).contractAccount(id));

/// The contract the owner picked on a customer's screen; null until they pick one.
final chosenContractProvider = StateProvider.autoDispose.family<String?, String>((ref, customerId) => null);

/// The contract a customer's screen shows: the one picked if it is still theirs, otherwise the one that needs
/// attention (late, then running, then the newest). An archived contract stays folded away: null when only those are left.
Contract? chosenContract(Customer customer, String? picked) {
  final asked = customer.contracts.where((k) => k.id == picked).firstOrNull;
  if (asked != null) return asked;
  final current = customer.contracts.where((k) => !k.archived).toList();
  if (current.isEmpty) return null;

  return current.where((k) => k.isLate).firstOrNull ?? current.where((k) => k.isRunning).firstOrNull ?? current.first;
}

/// The day's work for one customer: pick a contract, take a payment, read every line with the balance after it,
/// and see what is unpaid where it fell due.
class CustomerAccountSection extends ConsumerWidget {
  const CustomerAccountSection({super.key, required this.customer, required this.chosen, required this.account});

  final Customer customer;
  final Contract chosen;
  final Account? account;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final language = ref.watch(localeProvider);
    final currency = account?.currency ?? '';
    final canWrite = account?.canWrite ?? false;
    final ledger = ref.watch(contractAccountProvider(chosen.id));
    final current = [for (final k in customer.contracts) if (!k.archived) k];
    final tabs = current.isEmpty ? [chosen] : current;
    final finished = chosen.status == 'settled' || chosen.status == 'cancelled';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SizedBox(
          height: 74,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            itemCount: tabs.length,
            separatorBuilder: (_, _) => const SizedBox(width: 8),
            itemBuilder: (context, i) => _ContractChip(
              contract: tabs[i],
              currency: currency,
              selected: tabs[i].id == chosen.id,
              onTap: () => ref.read(chosenContractProvider(customer.id).notifier).state = tabs[i].id,
            ),
          ),
        ),
        const SizedBox(height: 12),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (canWrite && chosen.takesPayments)
              QButton(
                key: const ValueKey('account-record-payment'),
                label: context.t('Record a payment'),
                icon: Icons.account_balance_wallet_outlined,
                expand: false,
                onPressed: () => recordOnAccount(context, ref, customer: customer, contractId: chosen.id, currency: currency),
              ),
            QButton(
              label: context.t('Contract statement'),
              kind: QButtonKind.quiet,
              icon: Icons.description_outlined,
              expand: false,
              onPressed: () => showDocumentSheet(context, DocumentRequest.contract(chosen.id, chosen.reference)),
            ),
            QButton(
              label: context.t('Full contract page'),
              kind: QButtonKind.quiet,
              icon: Icons.open_in_new,
              expand: false,
              onPressed: () async {
                await context.push('/contracts/${chosen.id}');
                ref.invalidate(contractAccountProvider(chosen.id));
              },
            ),
            if (canWrite && finished && !chosen.archived)
              QButton(
                label: context.t('Archive'),
                kind: QButtonKind.quiet,
                icon: Icons.archive_outlined,
                expand: false,
                onPressed: () => _archive(context, ref),
              ),
          ],
        ),
        const SizedBox(height: 12),
        ledger.when(
          loading: () => const QCard(child: QSkeletonList(rows: 4)),
          error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(contractAccountProvider(chosen.id))),
          data: (data) => _Ledger(
            data: data,
            language: language,
            canPay: canWrite && data.takesPayments,
            onPay: (installment) => recordOnAccount(context, ref, customer: customer, contractId: chosen.id, currency: currency, forInstallment: installment),
          ),
        ),
      ],
    );
  }

  Future<void> _archive(BuildContext context, WidgetRef ref) async {
    try {
      await ref.read(apiProvider).archiveContract(chosen.id);
      refreshAfterMoney(ref, contractId: chosen.id, customerId: customer.id);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Contract :reference archived. It is under Archived in your contracts.', {'reference': chosen.reference}))));
      }
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }
}

/// Takes a payment on one of the customer's contracts from their screen: "they paid" on an open account, otherwise
/// the payment sheet, with the tapped instalment's amount filled in.
Future<void> recordOnAccount(BuildContext context, WidgetRef ref, {required Customer customer, required String contractId, required String currency, String? forInstallment}) async {
  final Contract contract;
  try {
    contract = await ref.read(contractProvider(contractId).future);
  } on ApiException catch (e) {
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    return;
  }
  if (!context.mounted) return;

  if (contract.isOpen) {
    final recorded = await showLedgerLineSheet(context, contract, LineDirection.paid);
    if (recorded != null) refreshAfterMoney(ref, contractId: contract.id, customerId: customer.id);
    return;
  }
  final payment = await showRecordPaymentSheet(context, contract, currency: currency, forInstallment: forInstallment);
  if (payment == null) return;

  refreshAfterMoney(ref, contractId: contract.id, customerId: customer.id);
  unawaited(HapticFeedback.mediumImpact());
  if (context.mounted) await showPaymentSuccess(context, contract: contract, payment: payment);
}

class _ContractChip extends StatelessWidget {
  const _ContractChip({required this.contract, required this.currency, required this.selected, required this.onTap});

  final Contract contract;
  final String currency;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final (label, tone) = switch (contract.isLate ? 'late' : contract.status) {
      'late' => (context.t('Late'), QTone.bad),
      'settled' => (context.t('Settled'), QTone.ok),
      'cancelled' => (context.t('Cancelled'), QTone.bad),
      _ => (context.t('Active'), QTone.info),
    };
    final owed = contract.status == 'cancelled' ? Money.zero : (contract.owed ?? Money.zero);

    return Semantics(
      button: true,
      selected: selected,
      child: Material(
        color: selected ? Color.alphaBlend(c.accent.withValues(alpha: 0.10), c.surface) : c.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(QistasMetrics.radiusLg),
          side: BorderSide(color: selected ? c.accent : c.line, width: selected ? 1.5 : 1),
        ),
        child: InkWell(
          key: ValueKey('contract-chip-${contract.id}'),
          onTap: onTap,
          borderRadius: BorderRadius.circular(QistasMetrics.radiusLg),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(contract.reference, style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700, color: c.ink)),
                    const SizedBox(width: 8),
                    QBadge(label, tone: tone),
                  ],
                ),
                const SizedBox(height: 4),
                Directionality(textDirection: TextDirection.ltr, child: Text(owed.format(currency), style: text.bodySmall?.copyWith(color: c.inkMuted))),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// Every line of the contract, oldest first: what it added, what was paid and the balance after it. An instalment
/// past its date and still owed sits where it fell due, in red, one tap from being paid.
class _Ledger extends StatelessWidget {
  const _Ledger({required this.data, required this.language, required this.canPay, required this.onPay});

  final ContractAccount data;
  final String language;
  final bool canPay;
  final void Function(String installmentId) onPay;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final small = Theme.of(context).textTheme.labelSmall?.copyWith(color: c.inkMuted, fontWeight: FontWeight.w600, letterSpacing: 0.3);
    var number = 0;

    return QCard(
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
            child: Row(
              children: [
                SizedBox(width: 26, child: Text('#', style: small)),
                Expanded(child: Text(context.t('Date'), style: small)),
                _Amount(context.t('Added'), style: small),
                _Amount(context.t('Paid'), style: small),
                _Amount(context.t('Balance'), style: small),
              ],
            ),
          ),
          Divider(height: 1, color: c.line),
          if (data.lines.isEmpty)
            Padding(padding: const EdgeInsets.all(16), child: Text(context.t('Nothing recorded on this contract yet.'), style: TextStyle(color: c.inkMuted)))
          else
            for (final line in data.lines)
              line.isUnpaid
                  ? _UnpaidRow(line: line.unpaid!, language: language, onPay: canPay ? () => onPay(line.unpaid!.id) : null)
                  : _LineRow(line: line, number: ++number, language: language),
        ],
      ),
    );
  }
}

class _LineRow extends StatelessWidget {
  const _LineRow({required this.line, required this.number, required this.language});

  final AccountLine line;
  final int number;
  final String language;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final figures = text.bodyMedium?.copyWith(fontFeatures: const [FontFeature.tabularFigures()]);

    return Container(
      key: ValueKey('account-line-$number'),
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
      decoration: BoxDecoration(border: Border(bottom: BorderSide(color: c.line.withValues(alpha: 0.6)))),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 26, child: Text('$number', style: text.bodySmall?.copyWith(color: c.inkMuted))),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(formatDay(line.date, language), style: text.bodyMedium),
                Text(
                  [_describe(context, line), if (line.tag != null) _tag(context, line.tag!)].join(' · '),
                  style: text.bodySmall?.copyWith(color: c.inkMuted),
                ),
              ],
            ),
          ),
          _Amount(line.added?.format(null) ?? '', style: figures?.copyWith(color: c.accentText)),
          _Amount(line.paid?.format(null) ?? '', style: figures?.copyWith(color: c.positive)),
          _Amount(line.balance?.format(null) ?? '', style: figures?.copyWith(fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }

  static String _describe(BuildContext context, AccountLine line) => switch (line.kind) {
        'sale' => context.t('Sale on the contract'),
        'down_payment' => context.t('Down payment'),
        'payment' => line.method == null ? context.t('Payment') : '${context.t('Payment')} (${methodLabel(context, line.method!)})',
        'reversal' => context.t('Payment voided'),
        'charge' => context.t('They took'),
        'charge_reversal' => context.t('Taken back off the balance'),
        'cancelled' => context.t('Contract cancelled'),
        'early_rebate' => line.paid != null ? context.t('Early settlement discount') : context.t('Early settlement discount voided'),
        'late_fee' => context.t('Late fee'),
        'late_fee_waived' => context.t('Late fee waived'),
        _ => line.kind,
      };

  static String _tag(BuildContext context, String tag) => switch (tag) {
        'advance' => context.t('Advance'),
        'refund' => context.t('Refund'),
        'early_discount' => context.t('Early-payment discount'),
        'unpaid' => context.t('Unpaid'),
        _ => tag,
      };
}

class _UnpaidRow extends StatelessWidget {
  const _UnpaidRow({required this.line, required this.language, required this.onPay});

  final UnpaidInstallment line;
  final String language;
  final VoidCallback? onPay;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final what = context.t('Instalment :number, due :date: :amount', {'number': line.number, 'date': formatDay(line.dueDate, language), 'amount': line.remaining.format(null)});

    return Semantics(
      button: onPay != null,
      label: onPay == null ? what : '$what. ${context.t('Unpaid. Record a payment')}',
      excludeSemantics: true,
      child: Material(
        color: Color.alphaBlend(c.danger.withValues(alpha: 0.08), c.surface),
        child: InkWell(
          key: ValueKey('unpaid-${line.id}'),
          onTap: onPay,
          child: Container(
            padding: const EdgeInsetsDirectional.fromSTEB(12, 10, 14, 10),
            decoration: BoxDecoration(border: BorderDirectional(start: BorderSide(color: c.danger, width: 3), bottom: BorderSide(color: c.line.withValues(alpha: 0.6)))),
            child: Row(
              children: [
                Icon(Icons.error_outline, size: 18, color: c.danger),
                const SizedBox(width: 8),
                Expanded(child: Text(what, style: text.bodyMedium?.copyWith(color: c.danger, fontWeight: FontWeight.w500))),
                if (onPay != null) ...[
                  const SizedBox(width: 8),
                  Flexible(
                    child: Text(context.t('Unpaid. Record a payment'), textAlign: TextAlign.end, style: text.labelMedium?.copyWith(color: c.danger, fontWeight: FontWeight.w700)),
                  ),
                ] else
                  QBadge(context.t('Unpaid'), tone: QTone.bad),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Amount extends StatelessWidget {
  const _Amount(this.text, {this.style});

  final String text;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 76,
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Text(text, textAlign: Directionality.of(context) == TextDirection.rtl ? TextAlign.left : TextAlign.right, style: style, maxLines: 1, overflow: TextOverflow.visible, softWrap: false),
        ),
      );
}

/// Where the chosen contract stands, pinned under the screen: instalment, instalments paid and left, paid, left, late.
class AccountTotalsBar extends StatelessWidget {
  const AccountTotalsBar({super.key, required this.totals, required this.currency});

  final AccountTotals totals;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final late = totals.late.isPositive;
    final figures = <(String, String, String, bool)>[
      if (totals.instalment != null) ('instalment', context.t('Instalment'), totals.instalment!.format(null), false),
      if (totals.paidCount != null) ('paid-count', context.t('Instalments paid'), '${totals.paidCount}', false),
      if (totals.leftCount != null) ('left-count', context.t('Instalments left'), '${totals.leftCount}', false),
      ('paid', context.t('Paid'), totals.paid.format(null), false),
      ('left', context.t('Still owed'), totals.left.format(null), false),
      ('late', context.t('Late'), totals.late.format(null), late),
    ];

    return Material(
      color: c.surface,
      elevation: 6,
      shadowColor: c.ink.withValues(alpha: 0.2),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: GridView.count(
            key: const ValueKey('account-totals'),
            crossAxisCount: 3,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            childAspectRatio: 2.6,
            mainAxisSpacing: 6,
            crossAxisSpacing: 6,
            children: [for (final (key, label, value, danger) in figures) _Figure(key: ValueKey('total-$key'), label: label, value: value, danger: danger, currency: currency)],
          ),
        ),
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({super.key, required this.label, required this.value, required this.danger, required this.currency});

  final String label;
  final String value;
  final bool danger;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: danger ? Color.alphaBlend(c.danger.withValues(alpha: 0.10), c.surface) : c.surfaceAlt.withValues(alpha: 0.55),
        borderRadius: BorderRadius.circular(QistasMetrics.radiusMd),
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: text.labelSmall?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Text(value, style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700, color: danger ? c.danger : c.ink, fontFeatures: const [FontFeature.tabularFigures()])),
            ),
          ),
        ],
      ),
    );
  }
}
