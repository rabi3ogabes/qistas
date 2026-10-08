import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import 'upgrade_sheet.dart';

final plansProvider = FutureProvider.autoDispose<List<PlanOffer>>((ref) => ref.watch(apiProvider).plans());

/// The plans, exactly as the admin set them up: what each includes comes from the server, never from the app.
class PlansScreen extends ConsumerWidget {
  const PlansScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final plans = ref.watch(plansProvider);
    final account = ref.watch(accountProvider);

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
                  for (final offer in offers) ...[
                    _PlanCard(offer: offer, current: account?.planKey == offer.key),
                    const SizedBox(height: 14),
                  ],
                  if (!kIsWeb) QNotice(context.t('Pro can be activated from your account on our website. It will appear in this app as soon as it is on.'), tone: QTone.info, icon: Icons.info_outline),
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

class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.offer, required this.current});

  final PlanOffer offer;
  final bool current;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final monthly = offer.monthlyPrice;

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
        ],
      ),
    );
  }
}
