import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/design/tokens.dart';
import '../core/design/widgets.dart';
import '../core/l10n/translations.dart';
import 'providers.dart';

/// The signed-in frame: five sections along the bottom of a phone, or down the side of a tablet or a browser.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authProvider).valueOrNull;
    final account = auth?.account;

    final destinations = [
      (Icons.home_outlined, Icons.home, context.t('Dashboard')),
      (Icons.people_outline, Icons.people, context.t('Customers')),
      (Icons.description_outlined, Icons.description, context.t('Contracts')),
      (Icons.account_balance_wallet_outlined, Icons.account_balance_wallet, context.t('Payments')),
      (Icons.menu, Icons.menu, context.t('More')),
    ];

    final wide = MediaQuery.sizeOf(context).width >= 840;
    void select(int index) => shell.goBranch(index, initialLocation: index == shell.currentIndex);

    final notices = Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (account != null && account.isDemo)
          Container(
            width: double.infinity,
            color: context.qc.tintSand,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Wrap(
              spacing: 12,
              runSpacing: 4,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text.rich(
                  TextSpan(children: [
                    TextSpan(text: context.t('Demo workspace'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    TextSpan(text: '  ${context.t('Sample data, cleared a few hours after you started. Nothing here is real.')}'),
                  ]),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.qc.ink),
                ),
                TextButton(
                  onPressed: () async {
                    final router = GoRouter.of(context);
                    await ref.read(authProvider.notifier).signOut();
                    router.go('/register');
                  },
                  child: Text(context.t('Create my free account')),
                ),
              ],
            ),
          ),
        if (account != null && account.isTest)
          Container(
            width: double.infinity,
            color: context.qc.tintSand,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Text.rich(
              TextSpan(children: [
                TextSpan(text: context.t('Test workspace'), style: const TextStyle(fontWeight: FontWeight.w700)),
                TextSpan(text: '  ${context.t('Sample data. Nothing here is real, and no customer sees it.')}'),
              ]),
              style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.qc.ink),
            ),
          ),
        if (auth?.offline ?? false)
          Container(
            width: double.infinity,
            color: context.qc.tintSand,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Row(
              children: [
                Icon(Icons.cloud_off_outlined, size: 18, color: context.qc.warning),
                const SizedBox(width: 10),
                Expanded(child: Text(context.t('You are offline. Showing what was last saved on this phone.'), style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.qc.warning))),
                TextButton(onPressed: () => ref.invalidate(authProvider), child: Text(context.t('Try again'))),
              ],
            ),
          ),
        if (account != null && !account.emailVerified)
          Container(
            width: double.infinity,
            color: context.qc.tintSky,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: Text(context.t('Verify your e-mail to unlock billing and exports. We sent you a link.'), style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.qc.info)),
          ),
      ],
    );

    if (wide) {
      return Scaffold(
        body: Row(
          children: [
            NavigationRail(
              selectedIndex: shell.currentIndex,
              onDestinationSelected: select,
              labelType: NavigationRailLabelType.all,
              leading: const Padding(padding: EdgeInsets.symmetric(vertical: 16), child: Icon(Icons.balance_outlined)),
              destinations: [for (final (icon, selected, label) in destinations) NavigationRailDestination(icon: Icon(icon), selectedIcon: Icon(selected), label: Text(label))],
            ),
            Expanded(child: Column(children: [notices, Expanded(child: shell)])),
          ],
        ),
      );
    }

    return Scaffold(
      body: Column(children: [SafeArea(bottom: false, child: notices), Expanded(child: shell)]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        onDestinationSelected: select,
        destinations: [for (final (icon, selected, label) in destinations) NavigationDestination(icon: Icon(icon), selectedIcon: Icon(selected), label: label)],
      ),
    );
  }
}

/// A screen of one of the five sections: a title row, and a button where there is something to add.
class SectionScaffold extends StatelessWidget {
  const SectionScaffold({super.key, required this.title, required this.body, this.actions = const [], this.floating});

  final String title;
  final Widget body;
  final List<Widget> actions;
  final Widget? floating;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(title), actions: actions),
        body: ContentColumn(padding: EdgeInsets.zero, child: body),
        floatingActionButton: floating,
      );
}
