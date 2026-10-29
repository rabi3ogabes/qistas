import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' hide TextDirection;

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/investors.dart';
import '../documents/document_options_sheet.dart';
import 'investor_entry_sheet.dart';
import 'investors_screen.dart';


/// One investor: the money in their wallet, what is out in contracts, profit earned and still to come, profit month by
/// month, the contracts they fund and every line of their money. Accountants and above record money in and out; owners
/// and managers reverse a mistaken deposit or withdrawal.
class InvestorDetailScreen extends ConsumerWidget {
  const InvestorDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(investorDetailProvider(id));
    final account = ref.watch(accountProvider);
    final canManage = account != null && account.canWrite && account.role != 'collector';

    return Scaffold(
      appBar: AppBar(
        title: Text(detail.valueOrNull?.investor.name ?? context.t('Investor')),
        actions: [
          if (detail.hasValue)
            IconButton(tooltip: context.t('Report'), icon: const Icon(Icons.description_outlined), onPressed: () => showDocumentSheet(context, DocumentRequest.investor(id, detail.requireValue.investor.name))),
          if (canManage && detail.hasValue)
            IconButton(tooltip: context.t('Edit details'), icon: const Icon(Icons.edit_outlined), onPressed: () => context.push('/investors/$id/edit')),
        ],
      ),
      body: SafeArea(
        child: detail.when(
          loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
          error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(investorDetailProvider(id))),
          data: (detail) => RefreshIndicator(
            onRefresh: () => ref.refresh(investorDetailProvider(id).future),
            child: ContentColumn(
              padding: EdgeInsets.zero,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
                children: [
                  Reveal(child: _Hero(investor: detail.investor)),
                  if (canManage && !detail.investor.archived) ...[
                    const SizedBox(height: 16),
                    QButton(
                      label: context.t('Record money in or out'),
                      icon: Icons.swap_vert_rounded,
                      kind: QButtonKind.gold,
                      onPressed: () async {
                        final recorded = await showInvestorEntrySheet(context, detail.investor);
                        if (recorded) {
                          ref.invalidate(investorDetailProvider(id));
                          ref.invalidate(investorsProvider);
                        }
                      },
                    ),
                  ],
                  QSectionTitle(context.t('Where the money is')),
                  QCard(child: MoneySplit(summary: detail.investor.summary, currency: account?.currency ?? detail.investor.currency)),
                  QSectionTitle(context.t('Profit, month by month')),
                  QCard(child: _ProfitBars(months: detail.profitByMonth, currency: account?.currency ?? detail.investor.currency)),
                  QSectionTitle(context.t('Contracts they fund')),
                  if (detail.contracts.isEmpty)
                    QCard(
                      child: Text(
                        detail.investor.isMain ? context.t('Every contract nobody else funds is funded here.') : context.t('Choose them under “Funded by” when you open a contract.'),
                        style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.qc.inkMuted),
                      ),
                    )
                  else
                    QCard(
                      padding: EdgeInsets.zero,
                      child: Column(children: [for (final contract in detail.contracts) _ContractRow(contract: contract, currency: account?.currency ?? detail.investor.currency)]),
                    ),
                  QSectionTitle(context.t('Money in and out')),
                  if (detail.entries.isEmpty)
                    QCard(child: Text(context.t('Nothing yet. Money put in, taken out and earned from contracts appears here.'), style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.qc.inkMuted)))
                  else
                    QCard(
                      padding: EdgeInsets.zero,
                      child: Column(
                        children: [
                          for (final entry in detail.entries)
                            _EntryRow(
                              entry: entry,
                              currency: account?.currency ?? detail.investor.currency,
                              onReverse: account?.canDelete == true && entry.reversible ? () => _confirmReverse(context, ref, entry) : null,
                            ),
                        ],
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _confirmReverse(BuildContext context, WidgetRef ref, InvestorEntry entry) async {
    final currency = ref.read(accountProvider)?.currency ?? '';
    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      isScrollControlled: true,
      builder: (context) => SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(entryLabel(context, entry), style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 4),
            MoneyText(entry.amount, currency, style: Theme.of(context).textTheme.headlineSmall),
            if (entry.note != null) ...[const SizedBox(height: 8), Text(entry.note!)],
            const SizedBox(height: 12),
            Text(context.t('Adds the same amount the other way. Nothing is deleted.'), style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.qc.inkMuted)),
            const SizedBox(height: 20),
            QButton(label: context.t('Reverse this entry'), kind: QButtonKind.danger, icon: Icons.undo_rounded, onPressed: () => Navigator.of(context).pop(true)),
            const SizedBox(height: 8),
            QButton(label: context.t('Keep it'), kind: QButtonKind.text, onPressed: () => Navigator.of(context).pop(false)),
          ],
        ),
      ),
    );
    if (confirmed != true || !context.mounted) return;

    try {
      await ref.read(apiProvider).reverseInvestorEntry(entry.id);
      ref.invalidate(investorDetailProvider(id));
      ref.invalidate(investorsProvider);
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('The entry was reversed.'))));
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }
}

class _Hero extends ConsumerWidget {
  const _Hero({required this.investor});

  final Investor investor;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final onHero = c.onPrimary;
    final currency = ref.watch(accountProvider)?.currency ?? investor.currency;
    final summary = investor.summary;

    Widget fact(String label, Money value) => Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: text.labelSmall?.copyWith(color: onHero.withValues(alpha: 0.72)), maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 2),
              FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: MoneyText(value, currency, style: text.titleSmall, color: onHero)),
            ],
          ),
        );

    return HeroPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(investorRole(context, investor), style: text.labelLarge?.copyWith(color: onHero.withValues(alpha: 0.8)))),
              if (investor.archived) QBadge(context.t('Archived')),
            ],
          ),
          const SizedBox(height: 14),
          Text(context.t('In the wallet'), style: text.labelLarge?.copyWith(color: onHero.withValues(alpha: 0.72), letterSpacing: 0.4)),
          const SizedBox(height: 4),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: CountUpMoney(summary.wallet, currency, color: onHero, style: text.displaySmall?.copyWith(fontSize: 40, fontWeight: FontWeight.w700, height: 1.1)),
          ),
          if (investor.isMain && summary.wallet.isNegative) ...[
            const SizedBox(height: 6),
            Text(context.t('Record the money the business put in to see what is left.'), style: text.bodySmall?.copyWith(color: onHero.withValues(alpha: 0.8))),
          ],
          const SizedBox(height: 18),
          // Bottom-aligned, so the amounts sit on one line however the labels wrap.
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              fact(context.t('Out in contracts'), summary.outInContracts),
              const SizedBox(width: 12),
              fact(context.t('Profit earned'), summary.profitEarned),
              const SizedBox(width: 12),
              fact(context.t('Profit still to come'), summary.profitExpected),
            ],
          ),
        ],
      ),
    );
  }
}

/// Six quiet bars, this month in full gold: the shape of the profit, with the amounts written above.
class _ProfitBars extends ConsumerWidget {
  const _ProfitBars({required this.months, required this.currency});

  final List<({String month, Money amount})> months;
  final String currency;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final peak = months.fold<double>(0, (peak, m) => m.amount.cents.abs().toDouble() > peak ? m.amount.cents.abs().toDouble() : peak);

    return SizedBox(
      height: 168,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          for (final (index, month) in months.indexed)
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    FittedBox(fit: BoxFit.scaleDown, child: Text(Money.fromCents(month.amount.cents - month.amount.cents % BigInt.from(100)).format(currency).replaceFirst(RegExp(r'[.,]00(?!\d)'), ''), style: text.labelSmall?.copyWith(color: c.inkMuted))),
                    const SizedBox(height: 6),
                    TweenAnimationBuilder<double>(
                      tween: Tween(begin: 0, end: peak == 0 ? 0 : month.amount.cents.abs().toDouble() / peak),
                      duration: Duration(milliseconds: 500 + index * 60),
                      curve: Curves.easeOutCubic,
                      builder: (context, value, _) => Container(
                        height: 4 + value * 100,
                        constraints: const BoxConstraints(maxWidth: 30),
                        decoration: BoxDecoration(
                          color: month.amount.isNegative ? c.danger.withValues(alpha: 0.55) : (index == months.length - 1 ? c.accent : c.accent.withValues(alpha: 0.55)),
                          borderRadius: const BorderRadius.vertical(top: Radius.circular(6), bottom: Radius.circular(2)),
                        ),
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(_monthName(month.month, language), style: text.labelSmall?.copyWith(color: c.inkMuted)),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  static String _monthName(String month, String language) {
    final date = DateTime.tryParse('$month-01');

    return date == null ? month : westernDigits(DateFormat.MMM(language).format(date));
  }
}

class _ContractRow extends StatelessWidget {
  const _ContractRow({required this.contract, required this.currency});

  final InvestorContract contract;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return ListTile(
      onTap: () => context.go('/contracts/${contract.id}'),
      title: Text(contract.customerName ?? contract.reference, maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Wrap(
        spacing: 8,
        runSpacing: 4,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(contract.reference, style: text.bodySmall?.copyWith(color: c.inkMuted)),
          QBadge(
            switch (contract.status) {
              'settled' => context.t('Settled'),
              'cancelled' => context.t('Cancelled'),
              _ => context.t('Active'),
            },
            tone: switch (contract.status) {
              'settled' => QTone.ok,
              'cancelled' => QTone.neutral,
              _ => QTone.info,
            },
          ),
        ],
      ),
      trailing: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          MoneyText(contract.collected, currency, style: text.titleSmall),
          Text(context.t('of :amount', {'amount': contract.total.format(currency)}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
        ],
      ),
    );
  }
}

class _EntryRow extends ConsumerWidget {
  const _EntryRow({required this.entry, required this.currency, this.onReverse});

  final InvestorEntry entry;
  final String currency;
  final VoidCallback? onReverse;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final muted = entry.reversed;

    return InkWell(
      onTap: onReverse,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(
                    spacing: 6,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      Text(entryLabel(context, entry), style: text.bodyLarge?.copyWith(color: muted ? c.inkMuted : null)),
                      if (entry.isReversal) QBadge(context.t('Reversal'), tone: QTone.warn),
                      if (entry.reversed) QBadge(context.t('Reversed')),
                    ],
                  ),
                  const SizedBox(height: 2),
                  Text(
                    [formatDay(entry.occurredOn, language), ?entry.contractReference].join('   '),
                    style: text.bodySmall?.copyWith(color: c.inkMuted),
                  ),
                  if (entry.note != null) Text(entry.note!, style: text.bodySmall?.copyWith(color: c.inkMuted)),
                ],
              ),
            ),
            const SizedBox(width: 12),
            MoneyText(
              entry.amount,
              currency,
              style: text.titleSmall,
              color: muted ? c.inkMuted : (entry.amount.isPositive ? c.positive : null),
              strike: muted,
            ),
          ],
        ),
      ),
    );
  }
}

/// Where an investor's money is: a ring of what customers still hold against what waits in the wallet, the money at
/// work in its middle, and profit earned beside it. A wallet below zero counts as nothing in the ring.
class MoneySplit extends StatelessWidget {
  const MoneySplit({super.key, required this.summary, required this.currency});

  final InvestorSummary summary;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final out = summary.outInContracts.isPositive ? summary.outInContracts : Money.zero;
    final wallet = summary.wallet.isPositive ? summary.wallet : Money.zero;
    final atWork = out + wallet;

    if (!atWork.isPositive) {
      return Text(context.t('Nothing at work yet. Record the money put in, or fund a contract, to see where it is.'), style: text.bodyMedium?.copyWith(color: c.inkMuted));
    }

    final share = out.cents.toDouble() / atWork.cents.toDouble();
    final outPercent = (share * 100).round();
    Widget row(Color colour, String label, Money amount, String? percent, {bool round = false}) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 12,
                height: 12,
                margin: const EdgeInsets.only(top: 3),
                decoration: BoxDecoration(color: colour, borderRadius: BorderRadius.circular(round ? 6 : 3)),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: text.bodySmall?.copyWith(color: c.inkMuted)),
                    Text.rich(
                      TextSpan(children: [
                        TextSpan(text: amount.format(currency), style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                        if (percent != null) TextSpan(text: '  $percent', style: text.bodySmall?.copyWith(color: c.inkMuted)),
                      ]),
                      textDirection: TextDirection.ltr,
                    ),
                  ],
                ),
              ),
            ],
          ),
        );

    return Semantics(
      container: true,
      label: context.t(':out out in contracts and :wallet in the wallet, :total at work.', {'out': out.format(currency), 'wallet': wallet.format(currency), 'total': atWork.format(currency)}),
      child: ExcludeSemantics(
        child: Row(
          children: [
            SizedBox(
              width: 132,
              height: 132,
              child: TweenAnimationBuilder<double>(
                tween: Tween(begin: 0, end: share),
                duration: MediaQuery.of(context).disableAnimations ? Duration.zero : const Duration(milliseconds: 700),
                curve: Curves.easeOutCubic,
                builder: (context, value, _) => CustomPaint(
                  painter: _RingPainter(share: value, out: c.primary, wallet: c.accent, track: c.surfaceAlt),
                  child: Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(context.t('At work'), style: text.labelSmall?.copyWith(color: c.inkMuted)),
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 18),
                          child: FittedBox(
                            fit: BoxFit.scaleDown,
                            child: Text(
                              '${currency.isEmpty ? '' : '$currency '}${NumberFormat.decimalPattern('en').format((atWork.cents ~/ BigInt.from(100)).toInt())}',
                              style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700),
                              textDirection: TextDirection.ltr,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 18),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  row(c.primary, context.t('Out in contracts'), out, '$outPercent%'),
                  row(c.accent, context.t('In the wallet'), wallet, '${100 - outPercent}%'),
                  row(c.positive, context.t('Profit earned'), summary.profitEarned, null, round: true),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  const _RingPainter({required this.share, required this.out, required this.wallet, required this.track});

  /// 0 to 1: the part of the money at work that customers still hold.
  final double share;
  final Color out;
  final Color wallet;
  final Color track;

  @override
  void paint(Canvas canvas, Size size) {
    const stroke = 14.0;
    final rect = Rect.fromCircle(center: size.center(Offset.zero), radius: size.shortestSide / 2 - stroke / 2);
    Paint pen(Color colour) => Paint()
      ..color = colour
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke;
    const start = -3.141592653589793 / 2;
    const full = 2 * 3.141592653589793;

    canvas.drawArc(rect, 0, full, false, pen(track));
    canvas.drawArc(rect, start + full * share, full * (1 - share), false, pen(wallet));
    if (share > 0) canvas.drawArc(rect, start, full * share, false, pen(out)..strokeCap = StrokeCap.round);
  }

  @override
  bool shouldRepaint(_RingPainter old) => old.share != share || old.out != out || old.wallet != wallet || old.track != track;
}
