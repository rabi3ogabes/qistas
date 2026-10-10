import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/appearance.dart';
import '../../data/notifications.dart';

/// Remind everyone (Win Plan PP9): the day's customers one after another. "Next" opens WhatsApp with the message
/// written; coming back ticks them off and offers the next. Who was reminded is kept until the screen is left.
class RemindAllScreen extends ConsumerStatefulWidget {
  const RemindAllScreen({super.key, this.scope = 'due'});

  /// due (who pays today) or late.
  final String scope;

  @override
  ConsumerState<RemindAllScreen> createState() => _RemindAllScreenState();
}

class _RemindAllScreenState extends ConsumerState<RemindAllScreen> {
  late String _scope = widget.scope == 'late' ? 'late' : 'due';
  List<DueReminder>? _rows;
  ApiException? _error;
  final Set<String> _done = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _rows = null;
      _error = null;
    });
    try {
      final rows = await ref.read(apiProvider).reminders(scope: _scope, language: ref.read(localeProvider));
      if (mounted) setState(() => _rows = rows);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _send(DueReminder row) async {
    final number = row.whatsapp;
    if (number == null) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('No phone number'))));
      return;
    }

    final opened = await ref.read(openExternalProvider)(Uri.parse('https://wa.me/$number?text=${Uri.encodeComponent(row.message)}'));
    if (!mounted) return;
    if (opened) {
      unawaited(HapticFeedback.selectionClick());
      setState(() => _done.add(row.installmentId));
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Could not open that on this phone.'))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Remind everyone'))),
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
              child: Wrap(
                spacing: 8,
                children: [
                  for (final (value, label) in [('due', context.t('Due today')), ('late', context.t('Late'))])
                    ChoiceChip(
                      label: Text(label),
                      selected: _scope == value,
                      onSelected: (_) {
                        if (_scope == value) return;
                        _scope = value;
                        _done.clear();
                        _load();
                      },
                    ),
                ],
              ),
            ),
            Expanded(
              child: _error != null
                  ? QErrorView(message: errorMessage(context, _error!), retryLabel: context.t('Try again'), onRetry: _load)
                  : rows == null
                      ? const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4))
                      : rows.isEmpty
                          ? QEmpty(
                              icon: Icons.event_available_rounded,
                              title: _scope == 'late' ? context.t('Nobody is late') : context.t('Nothing is due today.'),
                              message: context.t('When there is someone to remind, they will be here with the message ready.'),
                            )
                          : _List(rows: rows, done: _done, onSend: _send),
            ),
          ],
        ),
      ),
    );
  }
}

class _List extends ConsumerWidget {
  const _List({required this.rows, required this.done, required this.onSend});

  final List<DueReminder> rows;
  final Set<String> done;
  final void Function(DueReminder row) onSend;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = ref.watch(accountProvider)?.currency ?? '';
    final language = ref.watch(localeProvider);
    final next = rows.where((row) => !done.contains(row.installmentId)).firstOrNull;
    final reminded = rows.where((row) => done.contains(row.installmentId)).length;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        QCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t(':done of :total reminded', {'done': reminded, 'total': rows.length}), style: text.titleMedium),
              const SizedBox(height: 10),
              ClipRRect(
                borderRadius: BorderRadius.circular(99),
                child: LinearProgressIndicator(value: rows.isEmpty ? 0 : reminded / rows.length, minHeight: 8, color: c.positive, backgroundColor: c.surfaceAlt),
              ),
              const SizedBox(height: 14),
              if (next != null)
                QButton(label: context.t('Next: :name', {'name': next.customerName}), icon: Icons.chat_outlined, onPressed: () => onSend(next))
              else
                Row(
                  children: [
                    Icon(Icons.check_circle_rounded, color: c.positive),
                    const SizedBox(width: 8),
                    Expanded(child: Text(context.t('Everyone here is reminded.'), style: text.titleSmall)),
                  ],
                ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        for (final row in rows) ...[
          QCard(
            padding: const EdgeInsets.all(14),
            onTap: () => context.push('/contracts/${row.contractId}'),
            child: Row(
              children: [
                done.contains(row.installmentId)
                    ? CircleAvatar(radius: 20, backgroundColor: c.positive.withValues(alpha: 0.14), child: Icon(Icons.check_rounded, color: c.positive))
                    : InitialsAvatar(row.customerName),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(row.customerName, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                      const SizedBox(height: 2),
                      Text(
                        row.daysLate != null ? context.t('Days late: :count', {'count': row.daysLate}) : '${row.reference}, ${formatDay(row.dueDate, language)}',
                        style: text.bodySmall?.copyWith(color: row.daysLate != null ? c.danger : c.inkMuted),
                      ),
                    ],
                  ),
                ),
                MoneyText(row.amount, currency, style: text.titleSmall),
                IconButton(tooltip: context.t('WhatsApp'), icon: const Icon(Icons.chat_outlined), onPressed: () => onSend(row)),
              ],
            ),
          ),
          const SizedBox(height: 10),
        ],
      ],
    );
  }
}
