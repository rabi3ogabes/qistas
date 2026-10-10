import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/notifications.dart';
import 'alerts.dart';

/// The inbox (Win Plan PP9): every alert this person was sent, newest first. Opening it marks them all read.
class InboxScreen extends ConsumerStatefulWidget {
  const InboxScreen({super.key});

  @override
  ConsumerState<InboxScreen> createState() => _InboxScreenState();
}

class _InboxScreenState extends ConsumerState<InboxScreen> {
  bool _marked = false;

  Future<void> _markRead(InboxPage page) async {
    if (_marked || page.unread == 0) return;
    _marked = true;
    try {
      await ref.read(apiProvider).markRead();
    } on ApiException {
      // Still shown; they will be marked next time.
    }
  }

  @override
  Widget build(BuildContext context) {
    final inbox = ref.watch(inboxProvider);
    final language = ref.watch(localeProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('Alerts')),
        actions: [IconButton(tooltip: context.t('Alert settings'), icon: const Icon(Icons.tune_rounded), onPressed: () => context.push('/settings/alerts'))],
      ),
      body: inbox.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(inboxProvider)),
        data: (page) {
          unawaited(_markRead(page));
          if (page.items.isEmpty) {
            return QEmpty(
              icon: Icons.notifications_none_rounded,
              title: context.t('No alerts yet'),
              message: context.t('Your morning summary and instalment alerts will be kept here.'),
            );
          }

          return RefreshIndicator(
            onRefresh: () => ref.refresh(inboxProvider.future),
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
              itemCount: page.items.length,
              separatorBuilder: (_, _) => const SizedBox(height: 10),
              itemBuilder: (context, index) => _Entry(item: page.items[index], language: language),
            ),
          );
        },
      ),
    );
  }
}

class _Entry extends StatelessWidget {
  const _Entry({required this.item, required this.language});

  final AppNotification item;
  final String language;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final late = item.type == 'instalment_late';
    final route = item.route;

    return QCard(
      onTap: route == null || route == '/' ? null : () => context.push(route),
      padding: const EdgeInsets.all(14),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(color: (late ? c.danger : c.accent).withValues(alpha: 0.12), shape: BoxShape.circle),
            child: Icon(item.type == 'daily_digest' ? Icons.wb_sunny_outlined : (late ? Icons.schedule_rounded : Icons.event_available_rounded), size: 18, color: late ? c.danger : c.accentText),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(child: Text(item.title, style: text.titleSmall?.copyWith(fontWeight: item.read ? FontWeight.w500 : FontWeight.w700))),
                    if (!item.read) Container(width: 8, height: 8, decoration: BoxDecoration(color: c.accent, shape: BoxShape.circle)),
                  ],
                ),
                const SizedBox(height: 4),
                Text(item.body, style: text.bodyMedium),
                const SizedBox(height: 6),
                Text(formatMoment(item.createdAt.toLocal(), language), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
