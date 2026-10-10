import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/language_button.dart';
import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/config.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/languages.dart';
import '../../core/l10n/translations.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';
import '../security/app_lock.dart';
import 'tools_screen.dart';

/// Settings, in the order a person looks for things: who they are, their plan, how the app looks and speaks, their
/// workspace, keeping the account safe, and help. Each row says what it holds; signing out asks first.
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
    final account = ref.watch(accountProvider);
    final language = ref.watch(localeProvider);
    final mode = ref.watch(themeModeProvider);
    // The owner's tools are listed only when the platform has some switched on for this workspace.
    final hasTools = ref.watch(toolsProvider).valueOrNull?.tools.isNotEmpty ?? false;
    final twoStep = account?.twoFactor == true;
    final appLock = ref.watch(appLockProvider);

    return SectionScaffold(
      title: context.t('Settings'),
      showAccount: false,
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 40),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (account != null) ...[
              _ProfileHeader(account: account),
              const SizedBox(height: 20),
              _Group(id: 'plan', title: context.t('Your plan'), child: _PlanCard(account: account)),
            ],
            _Group(
              id: 'preferences',
              title: context.t('Preferences'),
              child: Column(
                children: [
                  _Row(
                    key: const ValueKey('settings-language'),
                    icon: Icons.translate_rounded,
                    title: context.t('Language'),
                    value: languageNames[language] ?? language,
                    onTap: () => showLanguageSheet(context),
                  ),
                  const _Divider(),
                  _AppearanceRow(mode: mode, onChanged: (value) => ref.read(themeModeProvider.notifier).choose(value)),
                ],
              ),
            ),
            if (hasTools)
              _Group(
                id: 'workspace',
                title: context.t('Workspace'),
                child: _Row(icon: Icons.tune_rounded, title: context.t('Instalment tools'), onTap: () => context.push('/tools')),
              ),
            _Group(
              id: 'security',
              title: context.t('Security'),
              child: Column(
                children: [
                  _Row(
                    key: const ValueKey('settings-two-step'),
                    icon: twoStep ? Icons.verified_user_outlined : Icons.shield_outlined,
                    iconColor: twoStep ? c.positive : null,
                    title: context.t('Two-step sign-in'),
                    subtitle: context.t('Turn it on or off from your account on the website.'),
                    value: twoStep ? context.t('On') : context.t('Off'),
                    external: true,
                    onTap: () => _open('/security'),
                  ),
                  if (!kIsWeb) ...[
                    const _Divider(),
                    _Row(
                      key: const ValueKey('settings-app-lock'),
                      icon: appLock.active ? Icons.fingerprint_rounded : Icons.lock_open_rounded,
                      iconColor: appLock.active ? c.positive : null,
                      title: context.t('App lock'),
                      subtitle: context.t('Fingerprint, face or phone PIN'),
                      value: appLock.active ? context.t('On') : context.t('Off'),
                      onTap: () => context.push('/app-lock'),
                    ),
                  ],
                  const _Divider(),
                  _Row(icon: Icons.logout_rounded, title: context.t('Sign out'), chevron: false, onTap: () => _confirmSignOut(context, ref, everywhere: false)),
                  const _Divider(),
                  _Row(
                    icon: Icons.devices_other_outlined,
                    iconColor: c.danger,
                    title: context.t('Sign out everywhere'),
                    titleColor: c.danger,
                    chevron: false,
                    onTap: () => _confirmSignOut(context, ref, everywhere: true),
                  ),
                ],
              ),
            ),
            _Group(
              id: 'help',
              title: context.t('Help'),
              child: Column(
                children: [
                  _Row(icon: Icons.public_rounded, title: context.t('Open the website'), external: true, onTap: () => _open('/')),
                  const _Divider(),
                  _Row(icon: Icons.lock_outline_rounded, title: context.t('Privacy'), external: true, onTap: () => _open('/privacy')),
                  const _Divider(),
                  _Row(icon: Icons.description_outlined, title: context.t('Terms'), external: true, onTap: () => _open('/terms')),
                  const _Divider(),
                  _Row(icon: Icons.info_outline_rounded, title: context.t('Version'), value: AppConfig.appVersion, latinValue: true, chevron: false),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Who is signed in, and where: the initials in the brand's circle, the name, the e-mail, the business and the role.
class _ProfileHeader extends StatelessWidget {
  const _ProfileHeader({required this.account});

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

    return HeroPanel(
      padding: const EdgeInsets.all(20),
      child: Row(
        children: [
          Container(
            width: 64,
            height: 64,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [c.heroTo, c.heroFrom]),
              border: Border.all(color: c.accent, width: 2),
            ),
            child: Text(
              InitialsAvatar.initials(account.name),
              textScaler: TextScaler.noScaling,
              style: text.titleLarge?.copyWith(color: c.onPrimary, fontWeight: FontWeight.w700, height: 1),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(account.name, style: text.titleLarge?.copyWith(color: c.onPrimary), maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 2),
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(account.email, style: text.bodySmall?.copyWith(color: c.onPrimary.withValues(alpha: 0.78)), maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
                const SizedBox(height: 10),
                Text(account.businessName, style: text.titleSmall?.copyWith(color: c.onPrimary), maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    SoftChip(_role(context), background: c.onPrimary.withValues(alpha: 0.12), foreground: c.onPrimary),
                    SoftChip(account.currency, background: c.onPrimary.withValues(alpha: 0.12), foreground: c.onPrimary),
                    if (account.isTest) QBadge(context.t('Test workspace'), tone: QTone.info),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The workspace's plan: on Free, how much of each limit is used and the way to Pro; on Pro, that every feature is on.
class _PlanCard extends StatelessWidget {
  const _PlanCard({required this.account});

  final Account account;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    if (!account.isFree) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(shape: BoxShape.circle, color: c.tintSand),
              child: Icon(Icons.workspace_premium_rounded, color: c.accentText),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(context.t('Pro is on'), style: text.titleMedium),
                  const SizedBox(height: 2),
                  Text(context.t('Every feature is open to your workspace.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                ],
              ),
            ),
            const SizedBox(width: 8),
            QBadge(account.planName, tone: QTone.gold),
          ],
        ),
      );
    }

    final customers = account.entitlement('customers');
    final contracts = account.entitlement('active_contracts');

    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text(context.t('What you use'), style: text.titleSmall)),
              QBadge(account.planName, tone: QTone.neutral),
            ],
          ),
          const SizedBox(height: 14),
          QMeter(label: context.t('Customers'), used: customers.used ?? 0, limit: customers.limit, figures: usageFigures(context, customers), enabled: customers.enabled),
          const SizedBox(height: 12),
          QMeter(label: context.t('Active contracts'), used: contracts.used ?? 0, limit: contracts.limit, figures: usageFigures(context, contracts), enabled: contracts.enabled),
          const SizedBox(height: 16),
          QButton(label: context.t('Upgrade to Pro'), kind: QButtonKind.gold, icon: Icons.workspace_premium_outlined, onPressed: () => context.push('/plans')),
        ],
      ),
    );
  }
}

/// System, light or dark: three choices side by side, the chosen one filled.
class _AppearanceRow extends StatelessWidget {
  const _AppearanceRow({required this.mode, required this.onChanged});

  final ThemeMode mode;
  final ValueChanged<ThemeMode> onChanged;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: const EdgeInsetsDirectional.fromSTEB(16, 14, 16, 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const _IconTile(icon: Icons.contrast_rounded),
              const SizedBox(width: 14),
              Expanded(child: Text(context.t('Appearance'), style: text.titleSmall)),
            ],
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: SegmentedButton<ThemeMode>(
              showSelectedIcon: false,
              segments: [
                ButtonSegment(value: ThemeMode.system, icon: const Icon(Icons.brightness_auto_outlined, size: 18), label: Text(context.t('Auto')), tooltip: context.t('Match my phone')),
                ButtonSegment(value: ThemeMode.light, icon: const Icon(Icons.light_mode_outlined, size: 18), label: Text(context.t('Light'))),
                ButtonSegment(value: ThemeMode.dark, icon: const Icon(Icons.dark_mode_outlined, size: 18), label: Text(context.t('Dark'))),
              ],
              selected: {mode},
              onSelectionChanged: (choice) {
                HapticFeedback.selectionClick();
                onChanged(choice.first);
              },
              style: ButtonStyle(
                backgroundColor: WidgetStateProperty.resolveWith((states) => states.contains(WidgetState.selected) ? c.tintSand : c.surface),
                foregroundColor: WidgetStateProperty.resolveWith((states) => states.contains(WidgetState.selected) ? c.ink : c.inkMuted),
                side: WidgetStatePropertyAll(BorderSide(color: c.line)),
                padding: const WidgetStatePropertyAll(EdgeInsets.symmetric(horizontal: 8, vertical: 12)),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// A titled group of rows on one card. [id] names it whatever the language (tests find it by it).
class _Group extends StatelessWidget {
  const _Group({required this.id, required this.title, required this.child});

  final String id;
  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) => Column(
        key: ValueKey('settings-group-$id'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          QSectionTitle(title),
          QCard(padding: EdgeInsets.zero, child: child),
        ],
      );
}

class _IconTile extends StatelessWidget {
  const _IconTile({required this.icon, this.color});

  final IconData icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return Container(
      width: 36,
      height: 36,
      decoration: BoxDecoration(color: c.surfaceAlt, borderRadius: BorderRadius.circular(QistasMetrics.radiusSm)),
      child: Icon(icon, size: 20, color: color ?? c.accentText),
    );
  }
}

/// One setting: an icon, what it is (and a line of explanation), what it holds, and where a tap goes.
class _Row extends StatelessWidget {
  const _Row({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.value,
    this.onTap,
    this.iconColor,
    this.titleColor,
    this.chevron = true,
    this.external = false,
    this.latinValue = false,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final String? value;
  final VoidCallback? onTap;
  final Color? iconColor;
  final Color? titleColor;
  final bool chevron;

  /// Opens outside the app (the website): shown with an arrow out instead of a chevron.
  final bool external;

  /// A value that reads left to right in every language (a version number).
  final bool latinValue;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final valueText = value == null ? null : Text(value!, style: text.bodyMedium?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis);

    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 56),
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 12, 10),
          child: Row(
            children: [
              _IconTile(icon: icon, color: iconColor),
              const SizedBox(width: 14),
              Expanded(
                flex: 3,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(title, style: text.titleSmall?.copyWith(color: titleColor)),
                    if (subtitle != null) ...[
                      const SizedBox(height: 2),
                      Text(subtitle!, style: text.bodySmall?.copyWith(color: c.inkMuted)),
                    ],
                  ],
                ),
              ),
              if (valueText != null) ...[
                const SizedBox(width: 10),
                // Sits at the end of the row, beside its chevron, whatever its length.
                Flexible(
                  flex: 2,
                  child: Align(alignment: AlignmentDirectional.centerEnd, child: latinValue ? Directionality(textDirection: TextDirection.ltr, child: valueText) : valueText),
                ),
              ],
              if (onTap != null && (chevron || external)) ...[
                const SizedBox(width: 4),
                Icon(external ? Icons.north_east_rounded : Icons.chevron_right_rounded, size: external ? 18 : 22, color: c.inkMuted),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _Divider extends StatelessWidget {
  const _Divider();

  // Starts after the icon, so the rows read as one list.
  @override
  Widget build(BuildContext context) => Divider(height: 1, indent: 66, color: context.qc.line);
}
