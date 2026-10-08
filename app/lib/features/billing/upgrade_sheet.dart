import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../data/models.dart';

/// The words for a feature key from the API.
String featureLabel(BuildContext context, String key) => switch (key) {
      'customers' => context.t('Customers'),
      'active_contracts' => context.t('Active contracts'),
      'pdf_statements' => context.t('PDF statements'),
      'export_csv' => context.t('CSV export'),
      'advanced_reports' => context.t('Advanced reports'),
      'custom_branding' => context.t('Custom branding'),
      'api_tokens' => context.t('API access tokens'),
      _ => key,
    };

/// "3 of 5", "Unlimited" or "Not included", for a usage meter.
String usageFigures(BuildContext context, Entitlement e) {
  if (!e.enabled) return context.t('Not included');
  if (e.unlimited || e.limit == null) return context.t('Unlimited');

  return context.t(':used of :limit', {'used': e.used ?? 0, 'limit': e.limit});
}

/// Opens the website's billing page: the only place Pro is bought from the web build of the app.
Future<void> openBilling([String? url]) async {
  await launchUrl(Uri.parse(url ?? '${AppConfig.webUrl}/app/billing'), mode: LaunchMode.externalApplication);
}

/// The answer to every HTTP 402: what was hit, how much is used, and where to go. Never a generic error.
Future<void> showUpgradeSheet(BuildContext context, UpgradeRequired reason) async {
  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (context) => UpgradeSheet(reason: reason),
  );

  if (context.mounted) unawaited(ProviderScope.containerOf(context).read(authProvider.notifier).refresh());
}

/// If [error] is a 402, shows the upgrade sheet and returns true; the caller then has nothing more to say.
Future<bool> showUpgradeIfNeeded(BuildContext context, Object error) async {
  if (error is! UpgradeRequired) return false;

  await showUpgradeSheet(context, error);

  return true;
}

class UpgradeSheet extends ConsumerWidget {
  const UpgradeSheet({super.key, required this.reason});

  final UpgradeRequired reason;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    final entitlement = account?.entitlement(reason.feature);
    final used = reason.used ?? entitlement?.used;
    final limit = reason.limit ?? entitlement?.limit;

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 4, 24, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(width: 52, height: 52, decoration: BoxDecoration(color: c.tintSand, shape: BoxShape.circle), child: Icon(Icons.workspace_premium_outlined, color: c.accentText, size: 28)),
            const SizedBox(height: 16),
            Text(
              reason.isLocked ? context.t('This is part of Pro') : context.t('You have reached your plan limit'),
              style: text.headlineSmall,
            ),
            const SizedBox(height: 8),
            Text(reason.message, style: text.bodyLarge?.copyWith(color: c.inkMuted)),
            if (!reason.isLocked && used != null) ...[
              const SizedBox(height: 20),
              QMeter(
                label: featureLabel(context, reason.feature),
                used: used,
                limit: limit,
                figures: limit == null ? '$used' : context.t(':used of :limit', {'used': used, 'limit': limit}),
              ),
            ],
            const SizedBox(height: 24),
            if (kIsWeb) ...[
              QButton(label: context.t('See plans and upgrade'), kind: QButtonKind.gold, icon: Icons.open_in_new, onPressed: () => openBilling(reason.upgradeUrl)),
            ] else ...[
              // Store rules: Pro is not sold inside the phone app. Say where it can be activated, without a purchase link.
              QNotice(context.t('Pro can be activated from your account on our website. It will appear in this app as soon as it is on.'), tone: QTone.info, icon: Icons.info_outline),
              const SizedBox(height: 12),
              QButton(
                label: context.t('Compare plans'),
                kind: QButtonKind.quiet,
                onPressed: () {
                  Navigator.of(context).pop();
                  context.push('/plans');
                },
              ),
            ],
            const SizedBox(height: 8),
            QButton(label: context.t('Not now'), kind: QButtonKind.text, onPressed: () => Navigator.of(context).pop()),
          ],
        ),
      ),
    );
  }
}
