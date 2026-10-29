import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/shell.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../glance/glance_privacy.dart';
import '../glance/glance_service.dart';
import '../security/app_lock.dart';

/// The home-screen widget: what it may show. Anyone who picks up the phone can read a home screen, so amounts can be
/// hidden, customers' names are off until the owner allows them, and with the app lock on the widget shows only counts.
/// Both choices stay on this phone and apply at once.
class WidgetSettingsScreen extends ConsumerWidget {
  const WidgetSettingsScreen({super.key});

  Future<void> _change(WidgetRef ref, Future<void> Function(GlancePrivacyController choices) save) async {
    await save(ref.read(glancePrivacyProvider.notifier));
    // The widget is drawn again under the new rules at once, and read again for what they need (the names).
    unawaited(ref.read(glanceServiceProvider).restyle());
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final privacy = ref.watch(glancePrivacyProvider);
    final lockOn = ref.watch(appLockProvider).active;

    // The switches show what the widget will do, not only what was chosen: behind the app lock both read off.
    final amountsOn = privacy.amountsShown(appLockOn: lockOn);
    final namesOn = privacy.namesShown(appLockOn: lockOn);

    return SectionScaffold(
      title: context.t('Home-screen widget'),
      showAccount: false,
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
        children: [
          Text(context.t('The widget shows what is due and late today. It refreshes each morning and whenever you open Qistas.'), style: text.bodyLarge?.copyWith(color: c.inkMuted)),
          const SizedBox(height: 16),
          QCard(
            padding: EdgeInsets.zero,
            child: Column(
              children: [
                SwitchListTile(
                  key: const ValueKey('widget-amounts'),
                  title: Text(context.t('Show amounts')),
                  subtitle: Text(lockOn ? context.t('Amounts stay hidden while the app lock is on') : context.t('Without them, the widget shows only the counts.')),
                  value: amountsOn,
                  onChanged: lockOn ? null : (on) => _change(ref, (choices) => choices.setShowAmounts(on)),
                ),
                Divider(height: 1, color: c.line),
                SwitchListTile(
                  key: const ValueKey('widget-names'),
                  title: Text(context.t('Show customer names')),
                  subtitle: Text(
                    lockOn
                        ? context.t('Names stay hidden while the app lock is on')
                        : !amountsOn
                            ? context.t('Names stay hidden while amounts are hidden')
                            : context.t('Anyone who sees your home screen could read them.'),
                  ),
                  value: namesOn,
                  onChanged: amountsOn ? (on) => _change(ref, (choices) => choices.setShowNames(on)) : null,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
