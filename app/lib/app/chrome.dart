import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/design/luxe.dart';
import '../core/design/tokens.dart';
import '../core/l10n/translations.dart';
import '../features/common/add_flows.dart';
import '../features/contracts/contract_picker.dart';
import 'providers.dart';

/// The parts of the signed-in frame that every screen shares: search, the account, and the quick-action bar.

class SearchButton extends StatelessWidget {
  const SearchButton({super.key});

  @override
  Widget build(BuildContext context) => IconButton(
        tooltip: context.t('Search'),
        onPressed: () => context.push('/search'),
        icon: const Icon(Icons.search),
      );
}

/// The person's initials: a way into settings from anywhere, in the place a phone user looks for it.
class AccountButton extends ConsumerWidget {
  const AccountButton({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final account = ref.watch(accountProvider);
    final c = context.qc;

    return Tooltip(
      message: context.t('Settings'),
      child: Semantics(
        button: true,
        label: context.t('Settings'),
        child: InkResponse(
          onTap: () => context.go('/more'),
          radius: 26,
          child: Padding(
            padding: const EdgeInsets.all(4),
            child: Container(
              width: 40,
              height: 40,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [c.heroTo, c.heroFrom]),
                border: Border.all(color: c.accent.withValues(alpha: 0.8), width: 1.5),
              ),
              child: Text(InitialsAvatar.initials(account?.name ?? ''), style: Theme.of(context).textTheme.labelLarge?.copyWith(color: c.onPrimary, height: 1)),
            ),
          ),
        ),
      ),
    );
  }
}

/// One of the four sections in the bar.
class NavDestination {
  const NavDestination(this.icon, this.selectedIcon, this.label);

  final IconData icon;
  final IconData selectedIcon;
  final String label;
}

/// The bottom bar: four sections and, in the middle, the gold button that adds anything.
class QistasNavBar extends StatelessWidget {
  const QistasNavBar({super.key, required this.destinations, required this.selected, required this.onSelect, required this.onAdd});

  final List<NavDestination> destinations;

  /// 0 to 3, or -1 when the screen is none of them (settings).
  final int selected;
  final ValueChanged<int> onSelect;
  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final half = destinations.length ~/ 2;

    Widget item(int index) => Expanded(
          child: _NavItem(destination: destinations[index], selected: index == selected, onTap: () => onSelect(index)),
        );

    return DecoratedBox(
      decoration: BoxDecoration(
        color: c.surface,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(26)),
        boxShadow: [BoxShadow(color: c.ink.withValues(alpha: 0.10), blurRadius: 26, offset: const Offset(0, -8))],
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 68,
          child: Row(
            children: [
              for (var i = 0; i < half; i++) item(i),
              Expanded(child: Center(child: _AddButton(onPressed: onAdd))),
              for (var i = half; i < destinations.length; i++) item(i),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({required this.destination, required this.selected, required this.onTap});

  final NavDestination destination;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Semantics(
      button: true,
      selected: selected,
      label: destination.label,
      excludeSemantics: true,
      child: InkResponse(
        onTap: onTap,
        radius: 36,
        highlightShape: BoxShape.rectangle,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 220),
              curve: Curves.easeOutCubic,
              width: selected ? 52 : 36,
              height: 30,
              decoration: BoxDecoration(color: selected ? c.accent.withValues(alpha: 0.30) : Colors.transparent, borderRadius: BorderRadius.circular(15)),
              child: Icon(selected ? destination.selectedIcon : destination.icon, size: 22, color: selected ? c.ink : c.inkMuted),
            ),
            const SizedBox(height: 3),
            Text(
              destination.label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: text.labelSmall?.copyWith(color: selected ? c.ink : c.inkMuted, fontWeight: selected ? FontWeight.w700 : FontWeight.w500, fontSize: 11.5),
            ),
          ],
        ),
      ),
    );
  }
}

class _AddButton extends StatelessWidget {
  const _AddButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return Transform.translate(
      offset: const Offset(0, -14),
      child: Tooltip(
        message: context.t('Quick actions'),
        child: Semantics(
          button: true,
          label: context.t('Quick actions'),
          excludeSemantics: true,
          child: GestureDetector(
            onTap: onPressed,
            child: Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color.lerp(c.accent, Colors.white, 0.28)!, c.accent]),
                boxShadow: [BoxShadow(color: c.accent.withValues(alpha: 0.5), blurRadius: 20, offset: const Offset(0, 8))],
                border: Border.all(color: c.surface, width: 4),
              ),
              child: Icon(Icons.add, size: 30, color: c.onAccent),
            ),
          ),
        ),
      ),
    );
  }
}

/// The gold plus: everything a person does most, one tap from anywhere.
Future<void> showQuickActions(BuildContext context, WidgetRef ref) async {
  unawaited(HapticFeedback.lightImpact());
  final account = ref.read(accountProvider);
  final canWrite = account?.canWrite ?? false;

  await showModalBottomSheet<void>(
    context: context,
    useSafeArea: true,
    builder: (sheet) {
      final text = Theme.of(sheet).textTheme;

      Widget tile(IconData icon, String label, String note, VoidCallback onTap, {bool highlight = false}) => Expanded(
            child: _ActionTile(icon: icon, label: label, note: note, highlight: highlight, onTap: () {
              Navigator.of(sheet).pop();
              onTap();
            }),
          );

      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 4, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(sheet.t('What would you like to do?'), style: text.titleLarge),
              const SizedBox(height: 16),
              if (canWrite) ...[
                Row(
                  children: [
                    tile(Icons.add_card_outlined, sheet.t('Record a payment'), sheet.t('Someone paid you'), () => showContractPicker(context), highlight: true),
                    const SizedBox(width: 12),
                    tile(Icons.person_add_alt_1_outlined, sheet.t('New customer'), sheet.t('Add someone you sell to'), () => addCustomer(context, ref)),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    tile(Icons.note_add_outlined, sheet.t('New contract'), sheet.t('Set up an instalment plan'), () => addContract(context, ref)),
                    const SizedBox(width: 12),
                    tile(Icons.search, sheet.t('Search'), sheet.t('Find a customer or contract'), () => context.push('/search')),
                  ],
                ),
              ] else
                Row(children: [tile(Icons.search, sheet.t('Search'), sheet.t('Find a customer or contract'), () => context.push('/search'))]),
            ],
          ),
        ),
      );
    },
  );
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({required this.icon, required this.label, required this.note, required this.onTap, required this.highlight});

  final IconData icon;
  final String label;
  final String note;
  final VoidCallback onTap;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Material(
      color: highlight ? c.tintSand : c.surfaceAlt,
      borderRadius: BorderRadius.circular(QistasMetrics.radiusLg),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(color: highlight ? c.accent : c.surface, shape: BoxShape.circle),
                child: Icon(icon, color: highlight ? c.onAccent : c.accentText, size: 22),
              ),
              const SizedBox(height: 12),
              Text(label, style: text.titleSmall, maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 2),
              Text(note, style: text.bodySmall?.copyWith(color: c.inkMuted), maxLines: 2, overflow: TextOverflow.ellipsis),
            ],
          ),
        ),
      ),
    );
  }
}
