import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/notifications.dart';
import 'alerts.dart';

/// What this person is told, and when (Win Plan PP9). Every choice is saved the moment it is made.
class AlertSettingsScreen extends ConsumerStatefulWidget {
  const AlertSettingsScreen({super.key});

  @override
  ConsumerState<AlertSettingsScreen> createState() => _AlertSettingsScreenState();
}

class _AlertSettingsScreenState extends ConsumerState<AlertSettingsScreen> {
  AlertPreferences? _shown;

  Future<void> _save(AlertPreferences next) async {
    final before = _shown;
    setState(() => _shown = next);
    try {
      final saved = await ref.read(apiProvider).saveAlertPreferences(next);
      if (mounted) setState(() => _shown = saved);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _shown = before);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  Future<String?> _pickTime(String current) async {
    final parts = current.split(':');
    final picked = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: int.tryParse(parts.first) ?? 9, minute: int.tryParse(parts.last) ?? 0),
      builder: (context, child) => MediaQuery(data: MediaQuery.of(context).copyWith(alwaysUse24HourFormat: true), child: child!),
    );
    if (picked == null) return null;

    return '${picked.hour.toString().padLeft(2, '0')}:${picked.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final loaded = ref.watch(alertPreferencesProvider);
    final digestOn = ref.watch(accountProvider)?.entitlements['daily_digest']?.isOn ?? false;
    final alertsOn = remindsEveryone(ref.watch(accountProvider));

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Alert settings'))),
      body: loaded.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(alertPreferencesProvider)),
        data: (fromServer) {
          final p = _shown ??= fromServer;
          final text = Theme.of(context).textTheme;
          final c = context.qc;

          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            children: [
              Text(context.t('Times are on your business’s clock. Alerts wait in the inbox when the phone has no pushes.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 12),
              if (digestOn) ...[
                QCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      SwitchListTile(
                        value: p.digest,
                        onChanged: (on) => _save(p.copyWith(digest: on)),
                        title: Text(context.t('Morning summary')),
                        subtitle: Text(context.t('Who pays today, who is late, and how much.')),
                      ),
                      ListTile(
                        enabled: p.digest,
                        leading: const Icon(Icons.schedule_rounded),
                        title: Text(context.t('Time')),
                        trailing: Directionality(textDirection: TextDirection.ltr, child: Text(p.digestTime, style: text.titleSmall)),
                        onTap: () async {
                          final time = await _pickTime(p.digestTime);
                          if (time != null) await _save(p.copyWith(digestTime: time));
                        },
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
              ],
              if (alertsOn) ...[
                QCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      SwitchListTile(
                        value: p.due,
                        onChanged: (on) => _save(p.copyWith(due: on)),
                        title: Text(context.t('On the due date')),
                        subtitle: Text(context.t('An alert for each instalment that falls due today.')),
                      ),
                      const Divider(height: 1),
                      SwitchListTile(
                        value: p.late,
                        onChanged: (on) => _save(p.copyWith(late: on)),
                        title: Text(context.t('When an instalment is late')),
                        subtitle: Text(context.t('Once the grace days are over, until it is paid.')),
                      ),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                        child: Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            for (final (value, label) in [('daily', context.t('Every day')), ('weekly', context.t('Every week')), ('monthly', context.t('Every month'))])
                              ChoiceChip(label: Text(label), selected: p.lateRepeat == value, onSelected: p.late ? (_) => _save(p.copyWith(lateRepeat: value)) : null),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 14),
              ],
              QCard(
                padding: EdgeInsets.zero,
                child: Column(
                  children: [
                    SwitchListTile(
                      value: p.quiet,
                      onChanged: (on) => _save(p.copyWith(quiet: on)),
                      title: Text(context.t('Quiet hours')),
                      subtitle: Text(context.t('Nothing arrives in these hours; it waits until they end.')),
                    ),
                    if (p.quiet)
                      Row(
                        children: [
                          Expanded(child: _TimeTile(label: context.t('From'), value: p.quietFrom, onTap: () async {
                            final time = await _pickTime(p.quietFrom);
                            if (time != null) await _save(p.copyWith(quietFrom: time));
                          })),
                          Expanded(child: _TimeTile(label: context.t('Until'), value: p.quietTo, onTap: () async {
                            final time = await _pickTime(p.quietTo);
                            if (time != null) await _save(p.copyWith(quietTo: time));
                          })),
                        ],
                      ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

class _TimeTile extends StatelessWidget {
  const _TimeTile({required this.label, required this.value, required this.onTap});

  final String label;
  final String value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => ListTile(
        title: Text(label),
        trailing: Directionality(textDirection: TextDirection.ltr, child: Text(value, style: Theme.of(context).textTheme.titleSmall)),
        onTap: onTap,
      );
}
