import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/billing/store_gateway.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/billing.dart';
import '../../data/models.dart';
import 'purchases.dart';
import 'upgrade_sheet.dart';

final plansProvider = FutureProvider.autoDispose<List<PlanOffer>>((ref) => ref.watch(apiProvider).plans());

/// The plans, exactly as the admin set them up: what each includes comes from the server, never from the app. On an
/// Android phone, the owner buys a paid plan here through Google Play (Win Plan PP13), at the store's own price. The
/// app never points to another way to pay: that stays on the website.
class PlansScreen extends ConsumerWidget {
  const PlansScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final plans = ref.watch(plansProvider);
    final account = ref.watch(accountProvider);
    final billing = ref.watch(billingProvider).valueOrNull;
    final storeOffers = ref.watch(storeOffersProvider).valueOrNull ?? const <StoreOffer>[];

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Plans'))),
      body: plans.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 5)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(plansProvider)),
        data: (offers) => ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ContentColumn(
              padding: EdgeInsets.zero,
              child: Column(
                children: [
                  const _PurchaseNotice(),
                  for (final offer in offers) ...[
                    _PlanCard(
                      offer: offer,
                      current: account?.planKey == offer.key,
                      billing: billing,
                      storeOffers: [for (final s in storeOffers) if (s.productId == billing?.productFor(offer.key)) s],
                    ),
                    const SizedBox(height: 14),
                  ],
                  if (!kIsWeb && billing != null && !billing.canBuy && account?.isFree == true)
                    QNotice(context.t('Only the owner of the workspace can change its plan.'), tone: QTone.info, icon: Icons.info_outline),
                  if (kIsWeb && account?.isFree == true)
                    Padding(
                      padding: const EdgeInsets.only(top: 8),
                      child: QButton(label: context.t('See plans and upgrade'), kind: QButtonKind.gold, icon: Icons.open_in_new, onPressed: openBilling),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// What happened to the last purchase: waiting on Google, done, refused, or nothing to restore.
class _PurchaseNotice extends ConsumerWidget {
  const _PurchaseNotice();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final purchase = ref.watch(purchaseControllerProvider);
    final (String? message, QTone tone, IconData icon) = switch (purchase.phase) {
      PurchasePhase.pending => (context.t('Google Play is completing the payment. Pro turns on as soon as it is done.'), QTone.info, Icons.hourglass_top_rounded),
      PurchasePhase.done => (context.t('Pro is on. Thank you.'), QTone.ok, Icons.workspace_premium_outlined),
      PurchasePhase.nothingToRestore => (context.t('Nothing to restore for this Google account.'), QTone.info, Icons.info_outline),
      PurchasePhase.failed => (
          purchase.error is ApiException ? errorMessage(context, purchase.error!) : context.t('The purchase did not go through.'),
          QTone.bad,
          Icons.error_outline,
        ),
      _ => (null, QTone.neutral, Icons.info_outline),
    };
    if (message == null) return const SizedBox.shrink();

    return Padding(padding: const EdgeInsets.only(bottom: 14), child: QNotice(message, tone: tone, icon: icon));
  }
}

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.offer, required this.current, required this.billing, required this.storeOffers});

  final PlanOffer offer;
  final bool current;
  final BillingState? billing;

  /// The store's offers for this plan, when the owner can buy it in the app.
  final List<StoreOffer> storeOffers;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final monthly = offer.monthlyPrice;
    final onStore = current && (billing?.paidThroughStore ?? false);
    final outside = current && (billing?.paidOutsideStore ?? false);

    return QCard(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(offer.name, style: text.headlineSmall)),
              if (current) QBadge(context.t('Your plan'), tone: QTone.gold),
            ],
          ),
          if (offer.description != null) ...[const SizedBox(height: 4), Text(offer.description!, style: text.bodyMedium?.copyWith(color: c.inkMuted))],
          const SizedBox(height: 12),
          if (offer.isFree)
            Text(context.t('Free'), style: text.titleLarge)
          // A plan paid outside the store shows no price: the app never quotes one it does not sell.
          else if (outside)
            const SizedBox.shrink()
          // The store's own price when the plan is sold in the app: one price, in the person's currency.
          else if (storeOffers.isNotEmpty)
            Text(_priceLine(context, storeOffers.first), style: text.titleLarge)
          else if (monthly != null)
            Row(
              crossAxisAlignment: CrossAxisAlignment.baseline,
              textBaseline: TextBaseline.alphabetic,
              children: [
                MoneyText(monthly, offer.currency, style: text.titleLarge),
                const SizedBox(width: 6),
                Text(context.t('per month'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              ],
            ),
          const SizedBox(height: 14),
          for (final feature in offer.features)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(feature.enabled ? Icons.check_circle_outline : Icons.remove_circle_outline, size: 20, color: feature.enabled ? c.positive : c.inkMuted),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      feature.enabled ? feature.summary : '${featureLabel(context, feature.key)}: ${feature.summary}',
                      style: text.bodyMedium?.copyWith(color: feature.enabled ? c.ink : c.inkMuted),
                    ),
                  ),
                ],
              ),
            ),
          if (onStore) _StoreSubscription(billing: billing!)
          else if (outside) _PaidPlan(billing: billing!)
          else if (!current && storeOffers.isNotEmpty && billing?.accountRef != null) _Subscribe(offers: storeOffers, accountRef: billing!.accountRef!),
        ],
      ),
    );
  }
}

String _priceLine(BuildContext context, StoreOffer offer) =>
    offer.yearly ? context.t(':price a year', {'price': offer.price}) : context.t(':price a month', {'price': offer.price});

/// Monthly or yearly at the store's price, with the free trial Google offers this person, then one button.
class _Subscribe extends ConsumerStatefulWidget {
  const _Subscribe({required this.offers, required this.accountRef});

  final List<StoreOffer> offers;
  final String accountRef;

  @override
  ConsumerState<_Subscribe> createState() => _SubscribeState();
}

class _SubscribeState extends ConsumerState<_Subscribe> {
  late StoreOffer _chosen = widget.offers.first;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final purchase = ref.watch(purchaseControllerProvider);
    final busy = purchase.phase == PurchasePhase.working;
    final controller = ref.read(purchaseControllerProvider.notifier);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),
        Divider(color: c.line, height: 1),
        const SizedBox(height: 16),
        for (final offer in widget.offers) ...[
          _OfferTile(offer: offer, selected: offer.key == _chosen.key, onTap: busy ? null : () => setState(() => _chosen = offer)),
          const SizedBox(height: 10),
        ],
        const SizedBox(height: 4),
        QButton(
          label: _chosen.trialDays != null ? context.t('Start the free trial') : context.t('Subscribe'),
          kind: QButtonKind.gold,
          icon: Icons.workspace_premium_outlined,
          loading: busy,
          onPressed: busy ? null : () => controller.buy(_chosen, widget.accountRef),
        ),
        const SizedBox(height: 10),
        Text(context.t('Billed through Google Play. Cancel any time in the Play Store.'), style: text.bodySmall?.copyWith(color: c.inkMuted), textAlign: TextAlign.center),
        QButton(label: context.t('Restore purchases'), kind: QButtonKind.text, onPressed: busy ? null : controller.restore),
      ],
    );
  }
}

class _OfferTile extends StatelessWidget {
  const _OfferTile({required this.offer, required this.selected, required this.onTap});

  final StoreOffer offer;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final price = _priceLine(context, offer);

    return Semantics(
      selected: selected,
      button: true,
      child: Material(
        color: selected ? Color.alphaBlend(c.accent.withValues(alpha: .10), c.surface) : c.surface,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: BorderSide(color: selected ? c.accent : c.line, width: selected ? 1.5 : 1),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            child: Row(
              children: [
                Icon(selected ? Icons.radio_button_checked : Icons.radio_button_off, size: 20, color: selected ? c.accentText : c.inkMuted),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(offer.yearly ? context.t('Yearly') : context.t('Monthly'), style: text.titleSmall),
                      const SizedBox(height: 2),
                      Text(
                        offer.trialDays != null ? context.t(':days days free, then :price', {'days': offer.trialDays, 'price': price}) : price,
                        style: text.bodyMedium?.copyWith(color: c.inkMuted),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// A plan paid or given outside the store: when it renews or until when, and nothing else. No button, no price and no
/// word of how it was paid (Google Play's payments policy); a plan with no end shows nothing here.
class _PaidPlan extends ConsumerWidget {
  const _PaidPlan({required this.billing});

  final BillingState billing;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    if (billing.until == null) return const SizedBox.shrink();
    final date = formatDay(billing.until!, ref.watch(localeProvider));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),
        Divider(color: c.line, height: 1),
        const SizedBox(height: 16),
        Text(
          billing.autoRenew ?? false ? context.t('Renews :date', {'date': date}) : context.t('Until :date', {'date': date}),
          style: text.bodyMedium,
        ),
      ],
    );
  }
}

/// A plan bought in Google Play: until when, and Google's own page to change or cancel it.
class _StoreSubscription extends ConsumerWidget {
  const _StoreSubscription({required this.billing});

  final BillingState billing;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final date = billing.until == null ? null : formatDay(billing.until!, language);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 16),
        Divider(color: c.line, height: 1),
        const SizedBox(height: 16),
        if (date != null)
          Text(
            billing.autoRenew ?? false ? context.t('Through Google Play, renews :date', {'date': date}) : context.t('Through Google Play, until :date', {'date': date}),
            style: text.bodyMedium,
          ),
        const SizedBox(height: 12),
        QButton(
          label: context.t('Manage in the Play Store'),
          kind: QButtonKind.quiet,
          icon: Icons.open_in_new,
          onPressed: () => launchUrl(Uri.parse('https://play.google.com/store/account/subscriptions'), mode: LaunchMode.externalApplication),
        ),
      ],
    );
  }
}
