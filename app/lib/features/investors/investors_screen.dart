import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/investors.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';

/// The investors and what this person may do with them, fetched each time a screen that needs them opens.
final investorsProvider = FutureProvider.autoDispose<InvestorsPage>((ref) => ref.watch(apiProvider).investors());

/// One investor's page.
final investorDetailProvider = FutureProvider.autoDispose.family<InvestorDetail, String>((ref, id) => ref.watch(apiProvider).investor(id));

/// Investors are drawn once the server lists the feature (an older server has no investors at all), while the platform
/// has it on, and never for a collector.
bool showsInvestors(Account? account) =>
    account != null && account.entitlements.containsKey('investors') && account.shows('investors') && account.role != 'collector';

/// "Own capital" or "Partner, 15% commission": who an investor is, in a few words.
String investorRole(BuildContext context, Investor investor) => investor.isMain
    ? context.t('The business’s own money')
    : investor.paysCommission
        ? context.t('Partner, :percent% commission', {'percent': investor.commissionShort})
        : context.t('Partner');

/// What a line of an investor's money means to them, in words; a commission reads by its direction.
String entryLabel(BuildContext context, InvestorEntry entry) => switch (entry.type) {
      'deposit' => context.t('Money put in'),
      'withdrawal' => context.t('Money taken out'),
      'funding_out' => context.t('Funded a contract'),
      'funding_back' => context.t('Back from a cancelled contract'),
      'principal_back' => context.t('Principal back from a payment'),
      'profit_share' => context.t('Profit from a payment'),
      'commission' => (entry.isReversal ? !entry.amount.isNegative : entry.amount.isNegative) ? context.t('Commission to the business') : context.t('Commission from a partner'),
      _ => entry.type,
    };

/// Win Plan PP3: who funds the business, each with the money in their wallet and what they have earned as customers pay.
class InvestorsScreen extends ConsumerWidget {
  const InvestorsScreen({super.key});

  Future<void> _add(BuildContext context, InvestorsPage page) async {
    if (page.isFull) {
      await showUpgradeSheet(
        context,
        UpgradeRequired(code: 'limit_reached', message: context.t('Your plan keeps the business’s own capital. Upgrade to add partners.'), feature: 'investors', limit: page.limit, used: page.used),
      );
      return;
    }
    await context.push('/investors/new');
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final page = ref.watch(investorsProvider);

    return SectionScaffold(
      title: context.t('Investors'),
      showAccount: false,
      actions: [
        if (page.valueOrNull?.canManage == true)
          IconButton(tooltip: context.t('Add an investor'), icon: const Icon(Icons.person_add_alt_1_outlined), onPressed: () => _add(context, page.value!)),
      ],
      body: page.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 3)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(investorsProvider)),
        data: (page) => RefreshIndicator(
          onRefresh: () => ref.refresh(investorsProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
            children: [
              Text(context.t('Who funds your contracts, and what each one has earned as customers pay.'), style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.qc.inkMuted)),
              const SizedBox(height: 16),
              for (final (index, investor) in page.investors.indexed)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Reveal(order: index, child: _InvestorCard(investor: investor)),
                ),
              if (page.investors.length == 1 && page.canManage) _PartnersInvite(onAdd: () => _add(context, page)),
            ],
          ),
        ),
      ),
    );
  }
}

class _InvestorCard extends ConsumerWidget {
  const _InvestorCard({required this.investor});

  final Investor investor;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = ref.watch(accountProvider)?.currency ?? investor.currency;
    final summary = investor.summary;

    Widget fact(String label, String value) => Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: text.bodySmall?.copyWith(color: c.inkMuted), maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 2),
              Text(value, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
            ],
          ),
        );

    return Opacity(
      opacity: investor.archived ? 0.7 : 1,
      child: QCard(
        onTap: () => context.push('/investors/${investor.id}'),
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                InitialsAvatar(investor.name, size: 40),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(investor.name, style: text.titleMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
                      Text(investorRole(context, investor), style: text.bodySmall?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis),
                    ],
                  ),
                ),
                if (investor.archived) QBadge(context.t('Archived')),
              ],
            ),
            const SizedBox(height: 16),
            Text(context.t('In the wallet'), style: text.labelMedium?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 2),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: AlignmentDirectional.centerStart,
              child: MoneyText(summary.wallet, currency, style: text.headlineSmall?.copyWith(fontWeight: FontWeight.w700), color: summary.wallet.isNegative ? c.danger : null),
            ),
            const Divider(height: 28),
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                fact(context.t('Out in contracts'), summary.outInContracts.format(currency)),
                const SizedBox(width: 12),
                fact(context.t('Profit earned'), summary.profitEarned.format(currency)),
                const SizedBox(width: 12),
                fact(context.t('Profit still to come'), summary.profitExpected.format(currency)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// While the business has only its own capital: what partners are for, and the way to add one.
class _PartnersInvite extends StatelessWidget {
  const _PartnersInvite({required this.onAdd});

  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(QistasMetrics.radiusLg),
        border: Border.all(color: c.line),
        color: c.surfaceAlt.withValues(alpha: 0.5),
      ),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.handshake_outlined, color: c.accentText),
            const SizedBox(height: 10),
            Text(context.t('Working with partners?'), style: text.titleMedium),
            const SizedBox(height: 4),
            Text(context.t('Add each partner with the money they put in. Mark which contracts they fund, and their profit grows as customers pay.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 14),
            QButton(label: context.t('Add an investor'), kind: QButtonKind.quiet, icon: Icons.add, expand: false, onPressed: onAdd),
          ],
        ),
      ),
    );
  }
}
