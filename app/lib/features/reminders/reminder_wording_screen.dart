import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/languages.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/notifications.dart';
import '../notifications/alerts.dart';

/// The business's own reminder wording, in each of the five languages (Win Plan PP9). Owners and managers only.
class ReminderWordingScreen extends ConsumerStatefulWidget {
  const ReminderWordingScreen({super.key});

  @override
  ConsumerState<ReminderWordingScreen> createState() => _ReminderWordingScreenState();
}

class _ReminderWordingScreenState extends ConsumerState<ReminderWordingScreen> {
  late String _language = ref.read(localeProvider);

  @override
  Widget build(BuildContext context) {
    final wording = ref.watch(reminderWordingProvider);
    final text = Theme.of(context).textTheme;

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Reminder wording'))),
      body: wording.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 3)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(reminderWordingProvider)),
        data: (all) => ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          children: [
            Text(context.t('Write the reminders the way you speak to your customers. These words are filled in for you: :placeholders', {'placeholders': ':name :amount :date :reference :business'}),
                style: text.bodySmall?.copyWith(color: context.qc.inkMuted)),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final MapEntry(key: code, value: name) in languageNames.entries)
                  ChoiceChip(label: Text(name), selected: _language == code, onSelected: (_) => setState(() => _language = code)),
              ],
            ),
            const SizedBox(height: 16),
            for (final (key, label) in [('reminder_due', context.t('Before it is late')), ('reminder_late', context.t('Once it is late'))])
              if (all.where((w) => w.key == key && w.language == _language).firstOrNull case final current?)
                Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: _WordingCard(key: ValueKey('$key-$_language-${current.body.hashCode}'), label: label, wording: current),
                ),
          ],
        ),
      ),
    );
  }
}

class _WordingCard extends ConsumerStatefulWidget {
  const _WordingCard({super.key, required this.label, required this.wording});

  final String label;
  final ReminderWording wording;

  @override
  ConsumerState<_WordingCard> createState() => _WordingCardState();
}

class _WordingCardState extends ConsumerState<_WordingCard> {
  late final _body = TextEditingController(text: widget.wording.body);
  bool _saving = false;

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
  }

  Future<void> _save(String body) async {
    setState(() => _saving = true);
    try {
      await ref.read(apiProvider).saveReminderWording(widget.wording.key, language: widget.wording.language, body: body);
      ref.invalidate(reminderWordingProvider);
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(body.trim().isEmpty ? context.t('The default wording is back.') : context.t('Wording saved.'))));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rtl = widget.wording.language == 'ar' || widget.wording.language == 'ur';

    return QCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(child: Text(widget.label, style: Theme.of(context).textTheme.titleSmall)),
              if (widget.wording.custom) QBadge(context.t('Your own words')),
            ],
          ),
          const SizedBox(height: 10),
          TextField(
            controller: _body,
            minLines: 3,
            maxLines: 6,
            maxLength: 1000,
            enabled: !_saving,
            textDirection: rtl ? TextDirection.rtl : TextDirection.ltr,
          ),
          Wrap(
            spacing: 8,
            alignment: WrapAlignment.end,
            children: [
              if (widget.wording.custom) QButton(label: context.t('Use the default'), kind: QButtonKind.text, expand: false, onPressed: _saving ? null : () => _save('')),
              QButton(label: context.t('Save'), expand: false, loading: _saving, onPressed: () => _save(_body.text)),
            ],
          ),
        ],
      ),
    );
  }
}
