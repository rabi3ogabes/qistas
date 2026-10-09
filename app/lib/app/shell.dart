import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/design/tokens.dart';
import '../core/design/widgets.dart';
import '../core/l10n/translations.dart';
import '../data/models.dart';
import '../features/dashboard/dashboard_screen.dart';
import 'chrome.dart';
import 'language_button.dart';
import 'providers.dart';

/// How long the app may sit in the background before coming back to it refreshes what it shows.
const Duration staleAfter = Duration(minutes: 2);

final staleAfterProvider = Provider<Duration>((ref) => staleAfter);

/// The signed-in frame: four sections and a gold plus along the bottom of a phone, or down the side of a tablet or a
/// browser. Settings are one tap away from the initials at the top of every screen.
class AppShell extends ConsumerStatefulWidget {
  const AppShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  ConsumerState<AppShell> createState() => _AppShellState();
}

class _AppShellState extends ConsumerState<AppShell> with WidgetsBindingObserver {
  DateTime? _leftAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Coming back after a while shows today's figures, not yesterday's.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused) {
      _leftAt = DateTime.now();
    } else if (state == AppLifecycleState.resumed) {
      final left = _leftAt;
      _leftAt = null;
      if (left != null && DateTime.now().difference(left) > ref.read(staleAfterProvider)) {
        unawaited(ref.read(authProvider.notifier).refresh());
        ref.invalidate(dashboardProvider);
      }
    }
  }

  void _select(int index) {
    HapticFeedback.selectionClick();
    widget.shell.goBranch(index, initialLocation: index == widget.shell.currentIndex);
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authProvider).valueOrNull;
    final account = auth?.account;
    final shell = widget.shell;

    final destinations = [
      NavDestination(Icons.home_outlined, Icons.home_rounded, context.t('Dashboard')),
      NavDestination(Icons.people_outline, Icons.people_rounded, context.t('Customers')),
      NavDestination(Icons.description_outlined, Icons.description_rounded, context.t('Contracts')),
      NavDestination(Icons.account_balance_wallet_outlined, Icons.account_balance_wallet_rounded, context.t('Payments')),
    ];
    // The fifth branch is settings: no tab, so none is selected there.
    final selected = shell.currentIndex < destinations.length ? shell.currentIndex : -1;

    final notices = _Notices(auth: auth, account: account);
    final hasNotices = (account != null && (account.isDemo || account.isTest || !account.emailVerified)) || (auth?.offline ?? false);
    final wide = MediaQuery.sizeOf(context).width >= 840;

    final content = Column(
      children: [
        if (hasNotices) SafeArea(bottom: false, child: notices),
        // The notices already stand clear of the status bar; the screen below must not leave a second gap for it.
        Expanded(child: hasNotices ? MediaQuery.removePadding(context: context, removeTop: true, child: shell) : shell),
      ],
    );

    if (wide) {
      return Scaffold(
        body: Row(
          children: [
            NavigationRail(
              selectedIndex: selected < 0 ? null : selected,
              onDestinationSelected: _select,
              labelType: NavigationRailLabelType.all,
              leading: Padding(
                padding: const EdgeInsets.symmetric(vertical: 16),
                child: FloatingActionButton(
                  heroTag: 'quick-actions',
                  tooltip: context.t('Quick actions'),
                  backgroundColor: context.qc.accent,
                  foregroundColor: context.qc.onAccent,
                  elevation: 2,
                  onPressed: () => showQuickActions(context, ref),
                  child: const Icon(Icons.add),
                ),
              ),
              destinations: [for (final d in destinations) NavigationRailDestination(icon: Icon(d.icon), selectedIcon: Icon(d.selectedIcon), label: Text(d.label))],
            ),
            Expanded(child: content),
          ],
        ),
      );
    }

    return Scaffold(
      body: content,
      bottomNavigationBar: QistasNavBar(destinations: destinations, selected: selected, onSelect: _select, onAdd: () => showQuickActions(context, ref)),
    );
  }
}

/// What the whole app should know right now: it is a demo or a test, there is no connection, or the e-mail is unconfirmed.
class _Notices extends ConsumerWidget {
  const _Notices({required this.auth, required this.account});

  final AuthState? auth;
  final Account? account;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final small = Theme.of(context).textTheme.bodySmall;
    final account = this.account;

    Widget strip({required Color color, required Widget child}) => Container(
          width: double.infinity,
          color: color,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: child,
        );

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (account != null && account.isDemo)
          strip(
            color: c.tintSand,
            // Only the way to a real account: the demo says what it is on the way in, so the strip does not repeat it.
            child: Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton(
                onPressed: () async {
                  final router = GoRouter.of(context);
                  await ref.read(authProvider.notifier).signOut();
                  router.go('/register');
                },
                child: Text(context.t('Create my free account')),
              ),
            ),
          ),
        if (account != null && account.isTest)
          strip(
            color: c.tintSand,
            child: Text.rich(
              TextSpan(children: [
                TextSpan(text: context.t('Test workspace'), style: const TextStyle(fontWeight: FontWeight.w700)),
                TextSpan(text: '  ${context.t('Sample data. Nothing here is real, and no customer sees it.')}'),
              ]),
              style: small?.copyWith(color: c.ink),
            ),
          ),
        if (auth?.offline ?? false)
          strip(
            color: c.tintSand,
            child: Row(
              children: [
                Icon(Icons.cloud_off_outlined, size: 18, color: c.warning),
                const SizedBox(width: 10),
                Expanded(child: Text(context.t('You are offline. Showing what was last saved on this phone.'), style: small?.copyWith(color: c.warning))),
                TextButton(onPressed: () => ref.invalidate(authProvider), child: Text(context.t('Try again'))),
              ],
            ),
          ),
        if (account != null && !account.emailVerified)
          strip(color: c.tintSky, child: Text(context.t('Verify your e-mail to unlock billing and exports. We sent you a link.'), style: small?.copyWith(color: c.info))),
      ],
    );
  }
}

/// A screen of one of the sections: a title in the serif voice, search and the account at the top, and a button
/// where there is something to add.
class SectionScaffold extends StatelessWidget {
  const SectionScaffold({super.key, required this.title, required this.body, this.actions = const [], this.floating, this.showAccount = true});

  final String title;
  final Widget body;
  final List<Widget> actions;
  final Widget? floating;
  final bool showAccount;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(title),
          actions: [...actions, const Padding(padding: EdgeInsetsDirectional.only(end: 4), child: LanguageButton(compact: true)), const SearchButton(), if (showAccount) const AccountButton(), const SizedBox(width: 8)],
        ),
        body: ContentColumn(padding: EdgeInsets.zero, child: body),
        floatingActionButton: floating,
      );
}
