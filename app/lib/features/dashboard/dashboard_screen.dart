import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';

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

    return SectionScaffold(
      title: context.t('Dashboard'),
      body: RefreshIndicator(
        onRefresh: refresh,
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
            if (account != null) _Workspace(account: account),
            const SizedBox(height: 16),
            board.when(
              loading: () => const Column(children: [QSkeleton(height: 110), SizedBox(height: 12), QSkeleton(height: 80), SizedBox(height: 12), QSkeleton(height: 160)]),
              error: (error, _) => SizedBox(
                height: 380,
                child: QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(dashboardProvider)),
              ),
              data: (data) => _Figures(data: data, account: account),
            ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Workspace extends StatelessWidget {
  const _Workspace({required this.account});

  final Account account;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final customers = account.entitlement('customers');
    final contracts = account.entitlement('active_contracts');

    return QCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(account.businessName, style: text.titleLarge, maxLines: 2, overflow: TextOverflow.ellipsis)),
              const SizedBox(width: 8),
              QBadge(account.planName, tone: account.isFree ? QTone.neutral : QTone.gold),
            ],
          ),
          const SizedBox(height: 4),
          Text(account.name, style: text.bodyMedium?.copyWith(color: c.inkMuted)),
          if (account.isFree) ...[
            const SizedBox(height: 16),
            QMeter(label: context.t('Customers'), used: customers.used ?? 0, limit: customers.limit, figures: usageFigures(context, customers), enabled: customers.enabled),
            const SizedBox(height: 12),
            QMeter(label: context.t('Active contracts'), used: contracts.used ?? 0, limit: contracts.limit, figures: usageFigures(context, contracts), enabled: contracts.enabled),
            const SizedBox(height: 16),
            QButton(label: context.t('Upgrade your plan'), kind: QButtonKind.gold, icon: Icons.workspace_premium_outlined, onPressed: () => context.push('/plans')),
          ],
        ],
      ),
    );
  }
}

class _Figures extends ConsumerWidget {
  const _Figures({required this.data, required this.account});

  final Dashboard data;
  final Account? account;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = data.currency;
    final customersUsed = account?.entitlement('customers').used ?? data.activeCustomers;
    final started = customersUsed > 0;

    Widget figure(String label, Widget value, {Color? tone}) => Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: text.bodySmall?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 4),
              value,
            ],
          ),
        );

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (!started) _GettingStarted(account: account, data: data),
        QCard(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(context.t('Still owed'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 6),
              FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: MoneyText(data.outstanding, currency, style: text.headlineMedium?.copyWith(fontWeight: FontWeight.w700))),
              const Divider(height: 28),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  figure(context.t('Overdue'), FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: MoneyText(data.overdue, currency, style: text.titleMedium, color: data.overdue.isPositive ? c.danger : null))),
                  const SizedBox(width: 16),
                  figure(context.t('Collected this month'), FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: MoneyText(data.collectedThisMonth, currency, style: text.titleMedium))),
                ],
              ),
              const SizedBox(height: 16),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  figure(context.t('Active customers'), Text('${data.activeCustomers}', style: text.titleMedium)),
                  const SizedBox(width: 16),
                  figure(context.t('Collection rate'), Text(data.collectionRate == null ? '—' : '${data.collectionRate}%', style: text.titleMedium)),
                ],
              ),
            ],
          ),
        ),
        QSectionTitle(context.t('Due today')),
        if (data.dueToday.isEmpty)
          QCard(child: Text(context.t('Nothing is due today.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)))
        else
          for (final due in data.dueToday)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: QCard(
                onTap: () => context.push('/contracts/${due.contractId}'),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(due.customerName, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                          Directionality(textDirection: TextDirection.ltr, child: Text(due.reference, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                        ],
                      ),
                    ),
                    EndAmount(child: MoneyText(due.amount, currency, style: text.titleSmall)),
                  ],
                ),
              ),
            ),
      ],
    );
  }
}

/// Shown until the business has its first customer: the next three things to do, in order.
class _GettingStarted extends StatelessWidget {
  const _GettingStarted({required this.account, required this.data});

  final Account? account;
  final Dashboard data;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final steps = [
      (context.t('Add your first customer'), Icons.person_add_alt_1_outlined, '/customers/new'),
      (context.t('Open a contract'), Icons.note_add_outlined, '/contracts/new'),
      (context.t('Record a payment'), Icons.payments_outlined, '/contracts'),
    ];

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: QCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(context.t('Get started in three steps'), style: text.titleMedium),
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
      ),
    );
  }
}
