import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import 'app_lock.dart';

/// The app lock: on or off for this phone, how soon it locks again, and (for the owner and managers) whether the
/// whole business must use it.
class AppLockSettingsScreen extends ConsumerStatefulWidget {
  const AppLockSettingsScreen({super.key});

  @override
  ConsumerState<AppLockSettingsScreen> createState() => _AppLockSettingsScreenState();
}

class _AppLockSettingsScreenState extends ConsumerState<AppLockSettingsScreen> {
  bool _savingPolicy = false;

  Future<void> _toggle(bool on) async {
    final lock = ref.read(appLockProvider.notifier);
    final reason = on ? context.t('Confirm it is you to turn on the app lock') : context.t('Confirm it is you to turn off the app lock');
    final failed = context.t('Not changed: the phone could not confirm it is you.');
    final done = on ? await lock.turnOn(reason) : await lock.turnOff(reason);
    if (!done && mounted) _say(failed);
  }

  Future<void> _require(bool required) async {
    setState(() => _savingPolicy = true);
    try {
      await ref.read(apiProvider).setAppLockRequired(required);
      await ref.read(authProvider.notifier).refresh();
      if (mounted) _say(required ? context.t('Everyone in your business must now unlock Qistas.') : context.t('The app lock is now each person’s choice.'));
    } on ApiException catch (e) {
      if (mounted) _say(errorMessage(context, e));
    } finally {
      if (mounted) setState(() => _savingPolicy = false);
    }
  }

  void _say(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  // One sentence per choice: Arabic counts 5 and 15 minutes in different forms, so no ":count minutes".
  String _afterLabel(BuildContext context, Duration after) => switch (after.inMinutes) {
        0 => context.t('Every time I leave the app'),
        1 => context.t('After 1 minute'),
        5 => context.t('After 5 minutes'),
        _ => context.t('After 15 minutes'),
      };

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final lock = ref.watch(appLockProvider);
    final account = ref.watch(accountProvider);
    final available = ref.watch(deviceAuthAvailableProvider).valueOrNull ?? false;

    return SectionScaffold(
      title: context.t('App lock'),
      showAccount: false,
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
        children: [
          Text(context.t('Qistas opens only after your fingerprint, your face or your phone’s PIN, so your customers’ balances stay private.'), style: text.bodyLarge?.copyWith(color: c.inkMuted)),
          const SizedBox(height: 16),
          if (!available)
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: QCard(
                key: const ValueKey('app-lock-unavailable'),
                child: Text(context.t('This phone has no screen lock. Set a fingerprint, face or PIN in the phone’s settings to use the app lock.'), style: text.bodyMedium),
              ),
            ),
          QCard(
            padding: EdgeInsets.zero,
            child: Column(
              children: [
                SwitchListTile(
                  key: const ValueKey('app-lock-switch'),
                  title: Text(context.t('Lock Qistas')),
                  subtitle: lock.required ? Text(context.t('Your business requires the app lock.'), style: text.bodySmall?.copyWith(color: c.inkMuted)) : null,
                  value: lock.active,
                  onChanged: !available || lock.required ? null : _toggle,
                ),
                if (lock.active) ...[
                  Divider(height: 1, color: c.line),
                  Padding(
                    padding: const EdgeInsetsDirectional.fromSTEB(16, 14, 16, 4),
                    child: Align(alignment: AlignmentDirectional.centerStart, child: Text(context.t('Ask again'), style: text.titleSmall)),
                  ),
                  RadioGroup<int>(
                    groupValue: lock.lockAfter.inSeconds,
                    onChanged: (seconds) => ref.read(appLockProvider.notifier).setLockAfter(Duration(seconds: seconds ?? 0)),
                    child: Column(
                      children: [
                        for (final after in AppLockController.choices)
                          RadioListTile<int>(
                            key: ValueKey('app-lock-after-${after.inSeconds}'),
                            value: after.inSeconds,
                            title: Text(_afterLabel(context, after)),
                          ),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
          if (account?.canManageSettings ?? false) ...[
            const SizedBox(height: 24),
            Text(context.t('Your business'), style: text.titleMedium),
            const SizedBox(height: 8),
            QCard(
              padding: EdgeInsets.zero,
              child: SwitchListTile(
                key: const ValueKey('app-lock-require'),
                title: Text(context.t('Require it for everyone')),
                subtitle: Text(context.t('Every member must unlock Qistas on their phone before it shows your books.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                value: account!.requireAppLock,
                onChanged: _savingPolicy ? null : _require,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
