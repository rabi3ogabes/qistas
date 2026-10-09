import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/chrome.dart';
import '../../app/language_button.dart';
import '../../app/providers.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../appearance/welcome_banner.dart';
import '../billing/upgrade_sheet.dart';
import '../reminders/reminders.dart';

final dashboardProvider = FutureProvider.autoDispose<Dashboard>((ref) => ref.watch(apiProvider).dashboard());

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final board = ref.watch(dashboardProvider);
    final account = ref.watch(accountProvider);

    Future<void> refresh() async {
      await ref.read(authProvider.notifier).refresh();
      ref.invalidate(dashboardProvider);
      await ref.read(dashboardProvider.future).then<void>((_) {}, onError: (Object _) {});
    }

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: refresh,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            child: ContentColumn(
              padding: EdgeInsets.zero,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _Greeting(account: account, board: board.valueOrNull),
                  const SizedBox(height: 18),
                  const WelcomeBannerCard(),
                  board.when(
                    loading: () => const _Loading(),
                    error: (error, _) => SizedBox(
                      height: 380,
                      child: QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(dashboardProvider)),
                    ),
                    data: (data) => _Body(data: data, account: account),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Loading extends StatelessWidget {
  const _Loading();

  @override
  Widget build(BuildContext context) => const Column(
        children: [
          QSkeleton(height: 250),
          SizedBox(height: 18),
          QSkeleton(height: 120),
          SizedBox(height: 18),
          QSkeleton(height: 96),
        ],
      );
}

/// "Good morning, Layla": the time of day, the name, and one honest line about what the day holds.
class _Greeting extends ConsumerWidget {
  const _Greeting({required this.account, required this.board});

  final Account? account;
  final Dashboard? board;

  String _hello(BuildContext context) {
    final hour = DateTime.now().hour;

    return hour < 12 ? context.t('Good morning') : (hour < 18 ? context.t('Good afternoon') : context.t('Good evening'));
  }

  String _line(BuildContext context) {
    final data = board;
    if (data == null) return context.t('Here is how things stand.');
    final count = data.needsYou.length;
    if (count == 1) return context.t('1 instalment needs you today');
    if (count > 1) return context.t(':count instalments need you today', {'count': count});
    if (data.upcoming.length == 1) return context.t('Nothing is late. 1 instalment falls due this week.');
    if (data.upcoming.isNotEmpty) return context.t('Nothing is late. :count instalments fall due this week.', {'count': data.upcoming.length});

    return context.t('You are all caught up.');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final first = (account?.name ?? '').split(' ').firstWhere((w) => w.isNotEmpty, orElse: () => '');

    return Reveal(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(formatLongDay(DateTime.now(), language), style: text.labelMedium?.copyWith(color: c.inkMuted, letterSpacing: 0.3)),
                const SizedBox(height: 6),
                Text(first.isEmpty ? _hello(context) : '${_hello(context)}${context.isRtl ? '، ' : ', '}$first', style: text.headlineMedium, maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 4),
                Text(_line(context), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
              ],
            ),
          ),
          const SizedBox(width: 8),
          const LanguageButton(compact: true),
          const SearchButton(),
          const AccountButton(),
        ],
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.data, required this.account});

  final Dashboard data;
  final Account? account;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final started = (account?.entitlement('customers').used ?? data.activeCustomers) > 0;
    final language = ref.watch(localeProvider);
    var order = 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Reveal(order: order++, child: _Hero(data: data)),
        if (!started) Reveal(order: order++, child: const Padding(padding: EdgeInsets.only(top: 18), child: _GettingStarted())),
        if (data.needsYou.isNotEmpty) Reveal(order: order++, child: _NeedsYou(data: data, language: language)),
        Reveal(order: order++, child: _Month(data: data)),
        if (data.upcoming.isNotEmpty) Reveal(order: order++, child: _ComingUp(data: data)),
        if (account != null && account!.isFree) Reveal(order: order++, child: _PlanCard(account: account!)),
      ],
    );
  }
}

/// The one figure: what is still to collect, with the last two weeks of takings beneath it.
class _Hero extends StatelessWidget {
  const _Hero({required this.data});

  final Dashboard data;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final onHero = c.onPrimary;
    final currency = data.currency;

    return HeroPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(context.t('Still to collect'), style: text.labelLarge?.copyWith(color: onHero.withValues(alpha: 0.72), letterSpacing: 0.4)),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: CountUpMoney(data.outstanding, currency, color: onHero, style: text.displaySmall?.copyWith(fontSize: 46, fontWeight: FontWeight.w700, height: 1.1)),
          ),
          const SizedBox(height: 14),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              if (data.overdue.isPositive)
                SoftChip(context.t('Overdue :amount', {'amount': data.overdue.format(currency)}), icon: Icons.schedule, background: c.tintBlush, foreground: c.danger),
              SoftChip(context.t('Collected :amount this month', {'amount': data.collectedThisMonth.format(currency)}), icon: Icons.trending_up, background: onHero.withValues(alpha: 0.12), foreground: onHero),
            ],
          ),
          const SizedBox(height: 18),
          Sparkline(values: [for (final day in data.daily) day.amount.cents.toDouble()], color: c.accent),
          const SizedBox(height: 4),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(child: Text(context.t('Last 14 days'), style: text.labelSmall?.copyWith(color: onHero.withValues(alpha: 0.6)), maxLines: 1, overflow: TextOverflow.ellipsis)),
              const SizedBox(width: 12),
              Flexible(child: Text(context.t('Active customers: :count', {'count': data.activeCustomers}), style: text.labelSmall?.copyWith(color: onHero.withValues(alpha: 0.6)), maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.end)),
            ],
          ),
        ],
      ),
    );
  }
}

/// Who to chase today. The first few get their actions right there; the rest are a tap away.
class _NeedsYou extends StatelessWidget {
  const _NeedsYou({required this.data, required this.language});

  final Dashboard data;
  final String language;

  static const int _detailed = 3;
  static const int _shown = 6;

  @override
  Widget build(BuildContext context) {
    final items = data.needsYou;
    final shown = items.take(_shown).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Needs you'), trailing: SoftChip('${items.length}', background: context.qc.tintSand, foreground: context.qc.accentText)),
        for (final (index, item) in shown.indexed)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: _NeedRow(item: item, currency: data.currency, detailed: index < _detailed),
          ),
        if (items.length > shown.length)
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: TextButton(onPressed: () => context.go('/contracts'), child: Text(context.t('See all :count', {'count': items.length}))),
          ),
      ],
    );
  }
}

class _NeedRow extends StatelessWidget {
  const _NeedRow({required this.item, required this.currency, required this.detailed});

  final DueItem item;
  final String currency;
  final bool detailed;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final late = item.isOverdue;
    final status = late
        ? (item.daysLate == 1 ? context.t('1 day late') : context.t(':count days late', {'count': item.daysLate}))
        : context.t('Due today');

    return QCard(
      onTap: () => context.push('/contracts/${item.contractId}'),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              InitialsAvatar(item.customerName),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(item.customerName, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                    const SizedBox(height: 2),
                    // How late, and which contract: side by side when there is room, one under the other when there is not.
                    Wrap(
                      spacing: 10,
                      runSpacing: 2,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Container(width: 8, height: 8, decoration: BoxDecoration(color: late ? c.danger : c.accent, shape: BoxShape.circle)),
                            const SizedBox(width: 6),
                            Flexible(child: Text(status, style: text.bodySmall?.copyWith(color: late ? c.danger : c.inkMuted, fontWeight: FontWeight.w600))),
                          ],
                        ),
                        Directionality(textDirection: TextDirection.ltr, child: Text(item.reference, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              EndAmount(child: MoneyText(item.amount, currency, style: text.titleSmall)),
            ],
          ),
          if (detailed) ...[
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  flex: 2,
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(minimumSize: const Size(0, 44), padding: const EdgeInsets.symmetric(horizontal: 12)),
                    onPressed: () => showReminderSheet(context, item),
                    icon: const Icon(Icons.chat_outlined, size: 18),
                    label: FittedBox(fit: BoxFit.scaleDown, child: Text(context.t('Remind'), maxLines: 1)),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 3,
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(minimumSize: const Size(0, 44), padding: const EdgeInsets.symmetric(horizontal: 12)),
                    onPressed: () => context.push('/contracts/${item.contractId}?pay=1'),
                    icon: const Icon(Icons.add_card_outlined, size: 18),
                    label: FittedBox(fit: BoxFit.scaleDown, child: Text(context.t('Record payment'), maxLines: 1)),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// How the month is going: the share of its instalments already paid, and how it stands against last month.
class _Month extends StatelessWidget {
  const _Month({required this.data});

  final Dashboard data;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final fraction = data.collectionFraction;
    final currency = data.currency;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('This month')),
        QCard(
          padding: const EdgeInsets.all(18),
          child: Row(
            children: [
              ProgressRing(
                value: fraction ?? 0,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(fraction == null ? '—' : '${(fraction * 100).round()}%', style: text.headlineSmall?.copyWith(fontWeight: FontWeight.w700, height: 1.1)),
                    Text(context.t('paid'), style: text.labelSmall?.copyWith(color: c.inkMuted)),
                  ],
                ),
              ),
              const SizedBox(width: 18),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      fraction == null ? context.t('Nothing falls due this month.') : context.t(':rate% of this month’s instalments are paid', {'rate': (fraction * 100).round()}),
                      style: text.bodyMedium,
                    ),
                    const SizedBox(height: 10),
                    _Fact(label: context.t('Collected'), value: data.collectedThisMonth.format(currency)),
                    _Fact(label: context.t('Expected'), value: data.expectedThisMonth.format(currency)),
                    const SizedBox(height: 8),
                    if (data.beatLastMonth)
                      SoftChip(context.t('Ahead of last month'), icon: Icons.emoji_events_outlined, background: c.tintMint, foreground: c.positive)
                    else if (data.collectedLastMonth.isPositive)
                      SoftChip(context.t(':amount to match last month', {'amount': data.toMatchLastMonth.format(currency)}), icon: Icons.flag_outlined, background: c.tintSand, foreground: c.accentText, maxLines: 2),
                  ],
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Flexible(child: Text(label, style: text.bodySmall?.copyWith(color: context.qc.inkMuted))),
          const SizedBox(width: 8),
          Flexible(child: Directionality(textDirection: TextDirection.ltr, child: Text(value, style: text.labelLarge, maxLines: 1, overflow: TextOverflow.ellipsis))),
        ],
      ),
    );
  }
}

/// The week ahead, so nothing arrives as a surprise.
class _ComingUp extends StatelessWidget {
  const _ComingUp({required this.data});

  final Dashboard data;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final items = data.upcoming.take(4).toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Coming up this week')),
        QCard(
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              for (final (index, item) in items.indexed) ...[
                if (index > 0) Divider(height: 1, color: c.line),
                InkWell(
                  onTap: () => context.push('/contracts/${item.contractId}'),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(item.customerName, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                              Text(
                                item.daysUntil == 1 ? context.t('Tomorrow') : context.t('In :count days', {'count': item.daysUntil}),
                                style: text.bodySmall?.copyWith(color: c.inkMuted),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        EndAmount(child: MoneyText(item.amount, data.currency, style: text.titleSmall)),
                      ],
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.account});

  final Account account;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final customers = account.entitlement('customers');
    final contracts = account.entitlement('active_contracts');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Your plan'), trailing: QBadge(account.planName)),
        QCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              QMeter(label: context.t('Customers'), used: customers.used ?? 0, limit: customers.limit, figures: usageFigures(context, customers), enabled: customers.enabled),
              const SizedBox(height: 14),
              QMeter(label: context.t('Active contracts'), used: contracts.used ?? 0, limit: contracts.limit, figures: usageFigures(context, contracts), enabled: contracts.enabled),
              const SizedBox(height: 16),
              Text(context.t('Your first customers are free. When you need more, Pro removes every limit.'), style: text.bodySmall?.copyWith(color: context.qc.inkMuted)),
              const SizedBox(height: 14),
              QButton(label: context.t('Upgrade your plan'), kind: QButtonKind.gold, icon: Icons.workspace_premium_outlined, onPressed: () => context.push('/plans')),
            ],
          ),
        ),
      ],
    );
  }
}

/// Shown until the business has its first customer: the next three things to do, in order.
class _GettingStarted extends StatelessWidget {
  const _GettingStarted();

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final steps = [
      (context.t('Add your first customer'), Icons.person_add_alt_1_outlined, '/customers/new'),
      (context.t('Open a contract'), Icons.note_add_outlined, '/contracts/new'),
      (context.t('Record a payment'), Icons.payments_outlined, '/contracts'),
    ];

    return QCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(context.t('Get started in three steps'), style: text.titleLarge),
          const SizedBox(height: 12),
          for (final (label, icon, path) in steps)
            ListTile(
              contentPadding: EdgeInsets.zero,
              leading: Container(width: 40, height: 40, decoration: BoxDecoration(color: c.surfaceAlt, shape: BoxShape.circle), child: Icon(icon, color: c.accentText, size: 20)),
              title: Text(label),
              trailing: Icon(context.isRtl ? Icons.chevron_left : Icons.chevron_right),
              onTap: () => context.push(path),
            ),
        ],
      ),
    );
  }
}
