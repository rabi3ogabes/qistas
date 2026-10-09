import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/config.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/languages.dart';
import '../../core/l10n/translations.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';
import 'tools_screen.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  Future<void> _confirmSignOut(BuildContext context, WidgetRef ref, {required bool everywhere}) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(everywhere ? context.t('Sign out everywhere?') : context.t('Sign out?')),
        content: Text(
          everywhere
              ? context.t('This signs you out on every phone and browser, including this one. You will need your password to come back.')
              : context.t('You will need your password to sign back in on this phone.'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.t('Cancel'))),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(everywhere ? context.t('Sign out everywhere') : context.t('Sign out'))),
        ],
      ),
    );
    if (confirmed != true) return;

    final auth = ref.read(authProvider.notifier);
    await (everywhere ? auth.signOutEverywhere() : auth.signOut());
  }

  Future<void> _open(String path) => launchUrl(Uri.parse('${AppConfig.webUrl}$path'), mode: LaunchMode.externalApplication);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    final language = ref.watch(localeProvider);
    final mode = ref.watch(themeModeProvider);
    // The owner's tools are listed only when the platform has some switched on for this workspace.
    final hasTools = ref.watch(toolsProvider).valueOrNull?.tools.isNotEmpty ?? false;

    return SectionScaffold(
      title: context.t('Settings'),
      showAccount: false,
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
          if (account != null) _AccountCard(account: account),
          if (hasTools) ...[
            QSectionTitle(context.t('Workspace')),
            QCard(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: ListTile(leading: const Icon(Icons.tune_rounded), title: Text(context.t('Instalment tools')), trailing: const Icon(Icons.chevron_right), onTap: () => context.push('/tools')),
            ),
          ],
          QSectionTitle(context.t('Language')),
          QCard(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: RadioGroup<String>(
              groupValue: language,
              onChanged: (value) {
                if (value != null) ref.read(localeProvider.notifier).choose(value);
              },
              child: Column(
                children: [
                  for (final code in AppConfig.locales)
                    RadioListTile<String>(
                      value: code,
                      title: Text(languageNames[code] ?? code),
                    ),
                ],
              ),
            ),
          ),
          QSectionTitle(context.t('Appearance')),
          QCard(
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                ChoiceChip(label: Text(context.t('Match my phone')), selected: mode == ThemeMode.system, onSelected: (_) => ref.read(themeModeProvider.notifier).choose(ThemeMode.system)),
                ChoiceChip(label: Text(context.t('Light')), selected: mode == ThemeMode.light, onSelected: (_) => ref.read(themeModeProvider.notifier).choose(ThemeMode.light)),
                ChoiceChip(label: Text(context.t('Dark')), selected: mode == ThemeMode.dark, onSelected: (_) => ref.read(themeModeProvider.notifier).choose(ThemeMode.dark)),
              ],
            ),
          ),
          QSectionTitle(context.t('Security')),
          QCard(
            child: Row(
              children: [
                Icon(account?.twoFactor == true ? Icons.verified_user_outlined : Icons.shield_outlined, color: account?.twoFactor == true ? c.positive : c.inkMuted),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(account?.twoFactor == true ? context.t('Two-step sign-in is on') : context.t('Two-step sign-in is off'), style: text.titleSmall),
                      Text(context.t('Turn it on or off from your account on the website.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          QButton(label: context.t('Sign out'), kind: QButtonKind.quiet, icon: Icons.logout, onPressed: () => _confirmSignOut(context, ref, everywhere: false)),
          const SizedBox(height: 8),
          QButton(label: context.t('Sign out everywhere'), kind: QButtonKind.text, onPressed: () => _confirmSignOut(context, ref, everywhere: true)),
          QSectionTitle(context.t('About')),
          QCard(
            padding: const EdgeInsets.symmetric(vertical: 4),
            child: Column(
              children: [
                ListTile(leading: const Icon(Icons.public), title: Text(context.t('Open the website')), onTap: () => _open('/')),
                const Divider(height: 1),
                ListTile(leading: const Icon(Icons.lock_outline), title: Text(context.t('Privacy')), onTap: () => _open('/privacy')),
                const Divider(height: 1),
                ListTile(leading: const Icon(Icons.description_outlined), title: Text(context.t('Terms')), onTap: () => _open('/terms')),
                const Divider(height: 1),
                ListTile(leading: const Icon(Icons.info_outline), title: Text(context.t('Version')), trailing: Directionality(textDirection: TextDirection.ltr, child: Text(AppConfig.appVersion, style: text.bodyMedium?.copyWith(color: c.inkMuted)))),
              ],
            ),
          ),
          ],
        ),
      ),
    );
  }
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({required this.account});

  final Account account;

  String _role(BuildContext context) => switch (account.role) {
        'owner' => context.t('Owner'),
        'manager' => context.t('Manager'),
        'accountant' => context.t('Accountant'),
        'collector' => context.t('Collector'),
        _ => context.t('Viewer'),
      };

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
              CircleAvatar(radius: 24, backgroundColor: c.surfaceAlt, foregroundColor: c.ink, child: Text(account.name.isEmpty ? '?' : String.fromCharCode(account.name.runes.first).toUpperCase())),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(account.name, style: text.titleMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
                    Directionality(textDirection: TextDirection.ltr, child: Text(account.email, style: text.bodySmall?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis)),
                  ],
                ),
              ),
            ],
          ),
          const Divider(height: 28),
          Row(
            children: [
              Expanded(child: Text(account.businessName, style: text.titleSmall, maxLines: 2, overflow: TextOverflow.ellipsis)),
              const SizedBox(width: 8),
              QBadge(account.planName, tone: account.isFree ? QTone.neutral : QTone.gold),
            ],
          ),
          const SizedBox(height: 4),
          Text('${_role(context)}  ·  ${account.currency}', style: text.bodySmall?.copyWith(color: c.inkMuted)),
          if (account.isFree) ...[
            const SizedBox(height: 16),
            QMeter(label: context.t('Customers'), used: customers.used ?? 0, limit: customers.limit, figures: usageFigures(context, customers), enabled: customers.enabled),
            const SizedBox(height: 12),
            QMeter(label: context.t('Active contracts'), used: contracts.used ?? 0, limit: contracts.limit, figures: usageFigures(context, contracts), enabled: contracts.enabled),
            const SizedBox(height: 16),
            QButton(label: context.t('See Pro'), kind: QButtonKind.gold, icon: Icons.workspace_premium_outlined, onPressed: () => context.push('/plans')),
          ],
        ],
      ),
    );
  }
}
