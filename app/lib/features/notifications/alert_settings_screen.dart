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

/// What this person is told, when, and how (Win Plan PP9, Sprint 3 Task 1b): the alerts and their timing, then every kind
/// of notice with the channels it reaches them on, among what the admin allows and what is set up. Billing notices are
/// for owners. Every choice is saved the moment it is made.
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
    final account = ref.watch(accountProvider);
    final digestOn = account?.entitlements['daily_digest']?.isOn ?? false;
    final alertsOn = remindsEveryone(account);
    final owner = account?.isOwner ?? false;

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
              if (p.kinds.isNotEmpty) ...[
                QSectionTitle(context.t('How notices reach you')),
                for (final (group, title) in [('alerts', context.t('Instalments')), ('billing', context.t('Your plan and payments')), ('support', context.t('Support'))])
                  if (_shownIn(p, group, owner: owner, digestOn: digestOn, alertsOn: alertsOn).isNotEmpty) ...[
                    Padding(padding: const EdgeInsets.fromLTRB(4, 4, 4, 8), child: Text(title, style: text.titleSmall?.copyWith(color: c.inkMuted))),
                    QCard(
                      padding: EdgeInsets.zero,
                      child: Column(
                        children: [
                          for (final (index, kind) in _shownIn(p, group, owner: owner, digestOn: digestOn, alertsOn: alertsOn).indexed) ...[
                            if (index > 0) const Divider(height: 1),
                            _NoticeRow(kind: kind, preferences: p, onChanged: _save),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(height: 14),
                  ],
                if (!p.channels.any((channel) => channel.key == 'sms' && channel.available))
                  Text(context.t('By text message: not available yet.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              ],
            ],
          );
        },
      ),
    );
  }

  /// The kinds of [group] this person can be told about: alerts only while their switch is on for the platform, billing
  /// only for owners.
  List<NoticeKind> _shownIn(AlertPreferences p, String group, {required bool owner, required bool digestOn, required bool alertsOn}) => [
        for (final kind in p.kinds)
          if (kind.group == group &&
              (group != 'billing' || owner) &&
              (kind.key != 'daily_digest' || digestOn) &&
              (!{'instalment_due', 'instalment_late'}.contains(kind.key) || alertsOn))
            kind,
      ];
}

/// One kind of notice: its switch (the alerts have theirs above) and the channels it reaches the person on.
class _NoticeRow extends StatelessWidget {
  const _NoticeRow({required this.kind, required this.preferences, required this.onChanged});

  final NoticeKind kind;
  final AlertPreferences preferences;
  final ValueChanged<AlertPreferences> onChanged;

  @override
  Widget build(BuildContext context) {
    final on = preferences.isOn(kind.key);
    final choice = preferences.choices[kind.key];
    final offered = preferences.offered(kind.key);
    final alert = AlertPreferences.alertKinds.contains(kind.key);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (alert)
          ListTile(title: Text(kind.label), subtitle: on ? null : Text(context.t('Off')))
        else
          SwitchListTile(
            key: ValueKey('notice-${kind.key}'),
            value: on,
            onChanged: (value) => onChanged(preferences.withNotice(kind.key, enabled: value)),
            title: Text(kind.label),
            subtitle: Text(kind.description),
          ),
        if (offered.isNotEmpty)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final channel in offered)
                  FilterChip(
                    key: ValueKey('notice-${kind.key}-${channel.key}'),
                    label: Text(channel.label),
                    selected: choice?.channels[channel.key] ?? false,
                    onSelected: on ? (value) => onChanged(preferences.withNotice(kind.key, channel: channel.key, on: value)) : null,
                  ),
              ],
            ),
          ),
      ],
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
