import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/paged.dart';
import '../../data/day_close.dart';
import 'day_close_state.dart';

/// Every day that was closed, newest first (daily-habit wave A, H4): the day, whether the close stands or was reopened,
/// how the cash stood, and who closed it. A day that was reopened and closed again lists both closes, the latest first.
/// Anyone in the business may read it. The sentence on each is the server's, shown as it came.
class DayCloseHistoryScreen extends ConsumerWidget {
  const DayCloseHistoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Off means off: nothing is asked of the server.
    if (!dayCloseOn(ref.watch(accountProvider))) {
      return Scaffold(
        appBar: AppBar(title: Text(context.t('History'))),
        body: QEmpty(icon: Icons.event_busy_outlined, title: context.t('Closing the day is not switched on for this business.'), message: ''),
      );
    }

    return Scaffold(
      appBar: AppBar(title: Text(context.t('History'))),
      body: PagedListView<DayCloseRecord>(
        provider: dayCloseHistoryProvider,
        itemBuilder: (context, record) => _HistoryCard(record: record),
        empty: QEmpty(icon: Icons.history_rounded, title: context.t('Closed days will be listed here.'), message: ''),
      ),
    );
  }
}

class _HistoryCard extends ConsumerWidget {
  const _HistoryCard({required this.record});

  final DayCloseRecord record;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final currency = ref.watch(accountProvider)?.currency ?? '';
    final standing = standingOf(record.difference);
    final tone = switch (standing) {
      CashStanding.balanced => c.positive,
      CashStanding.short => c.danger,
      CashStanding.over => c.warning,
    };

    return QCard(
      key: ValueKey('history-${record.id}'),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          OverflowBar(
            alignment: MainAxisAlignment.spaceBetween,
            overflowAlignment: OverflowBarAlignment.start,
            overflowSpacing: 6,
            spacing: 12,
            children: [
              Text(formatDay(record.day, language), style: text.titleMedium),
              QBadge(record.isReopened ? context.t('Reopened') : context.t('Closed'), tone: record.isReopened ? QTone.warn : QTone.ok),
            ],
          ),
          const SizedBox(height: 6),
          Text(differenceWords(context, record.difference, currency), style: text.titleSmall?.copyWith(color: tone)),
          const SizedBox(height: 2),
          Text(
            '${context.t('Closed by')}: ${record.closedBy ?? context.t('Someone no longer on the team')}',
            style: text.bodySmall?.copyWith(color: c.inkMuted),
          ),
          const SizedBox(height: 10),
          Text(record.summaryText, style: text.bodySmall),
        ],
      ),
    );
  }
}
