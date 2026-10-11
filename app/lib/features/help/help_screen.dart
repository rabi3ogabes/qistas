import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/appearance.dart';
import '../../data/help.dart';
import 'help_articles.dart';
import 'whats_new.dart';

final supportInfoProvider = FutureProvider.autoDispose<SupportInfo>((ref) => ref.watch(apiProvider).supportInfo());

final supportTicketsProvider = FutureProvider.autoDispose<List<SupportTicket>>((ref) => ref.watch(apiProvider).supportTickets());

/// This phone, as a ticket and a support message name it.
String supportPlatform() => kIsWeb ? 'web' : (defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android');

/// Help inside the app (Win Plan PP17): short answers to search, a way to reach a person (WhatsApp with the support
/// code, or a message here), the reply promise, and the person's questions with the team's replies.
class HelpScreen extends ConsumerStatefulWidget {
  const HelpScreen({super.key});

  @override
  ConsumerState<HelpScreen> createState() => _HelpScreenState();
}

class _HelpScreenState extends ConsumerState<HelpScreen> {
  final _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _whatsapp(SupportInfo info) async {
    // The code names the workspace and the version names the app; nothing private goes in the message.
    final message = context.t('Hello Qistas team. Support code: :code, app :version (:platform).', {'code': info.code, 'version': AppConfig.appVersion, 'platform': supportPlatform()});
    await ref.read(openExternalProvider)(Uri.https('wa.me', '/${info.whatsapp}', {'text': '$message\n\n'}));
  }

  Future<void> _write() async {
    final sent = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => const _WriteSheet(),
    );
    if (sent == true && mounted) {
      ref.invalidate(supportTicketsProvider);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Sent. We reply here and on your phone.'))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final info = ref.watch(supportInfoProvider).valueOrNull;
    final tickets = ref.watch(supportTicketsProvider);
    final query = _search.text.trim();
    final articles = [for (final article in helpArticles(context)) if (query.isEmpty || article.matches(query)) article];

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Help'))),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          ContentColumn(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(
                  key: const ValueKey('help-search'),
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(hintText: context.t('Search help'), prefixIcon: const Icon(Icons.search)),
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 12),
                QCard(
                  padding: const EdgeInsets.symmetric(horizontal: 4),
                  child: articles.isEmpty
                      ? Padding(padding: const EdgeInsets.all(16), child: Text(context.t('No answer matches. Write to us and a person will reply.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)))
                      : Column(
                          children: [
                            for (final article in articles)
                              Theme(
                                data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                                child: ExpansionTile(
                                  key: ValueKey(article.title),
                                  title: Text(article.title, style: text.titleSmall),
                                  childrenPadding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 16),
                                  expandedCrossAxisAlignment: CrossAxisAlignment.start,
                                  children: [Text(article.body, style: text.bodyMedium?.copyWith(color: c.inkMuted, height: 1.5))],
                                ),
                              ),
                          ],
                        ),
                ),
                QSectionTitle(context.t('Talk to a person')),
                if (info != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: QNotice(
                      context.t('We reply within :hours hours, and within :urgent hours if data looks missing.', {'hours': info.replyHours, 'urgent': info.missingDataHours}),
                      tone: QTone.info,
                      icon: Icons.schedule_outlined,
                    ),
                  ),
                if (info?.whatsapp != null) ...[
                  QButton(label: context.t('Message us on WhatsApp'), kind: QButtonKind.gold, icon: Icons.chat_outlined, onPressed: () => _whatsapp(info!)),
                  const SizedBox(height: 10),
                ],
                QButton(label: context.t('Write to us'), kind: info?.whatsapp != null ? QButtonKind.quiet : QButtonKind.gold, icon: Icons.edit_outlined, onPressed: _write),
                if (info != null) ...[
                  const SizedBox(height: 8),
                  Text(context.t('Your support code: :code', {'code': info.code}), style: text.bodySmall?.copyWith(color: c.inkMuted), textAlign: TextAlign.center),
                ],
                ...tickets.when(
                  loading: () => const <Widget>[],
                  error: (_, _) => const <Widget>[],
                  data: (list) => list.isEmpty
                      ? const <Widget>[]
                      : [
                          QSectionTitle(context.t('Your questions')),
                          for (final ticket in list) ...[_TicketCard(ticket: ticket), const SizedBox(height: 12)],
                        ],
                ),
                const SizedBox(height: 12),
                QButton(label: context.t('What’s new'), kind: QButtonKind.text, icon: Icons.auto_awesome_outlined, onPressed: () => showWhatsNew(context, ref, since: '0.0.0')),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _TicketCard extends ConsumerWidget {
  const _TicketCard({required this.ticket});

  final SupportTicket ticket;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final (String status, QTone tone) = switch (ticket.status) {
      'answered' => (context.t('Answered'), QTone.ok),
      'closed' => (context.t('Closed'), QTone.neutral),
      _ => (context.t('Waiting for a reply'), QTone.warn),
    };

    return QCard(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              QBadge(status, tone: tone),
              const Spacer(),
              Text(formatMoment(ticket.createdAt, language), style: text.bodySmall?.copyWith(color: c.inkMuted)),
            ],
          ),
          const SizedBox(height: 10),
          Text(ticket.message, style: text.bodyMedium),
          for (final reply in ticket.replies) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: c.tintSand,
                borderRadius: BorderRadius.circular(12),
                border: BorderDirectional(start: BorderSide(color: c.accent, width: 3)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(context.t('The Qistas team'), style: text.labelMedium?.copyWith(color: c.accentText)),
                  const SizedBox(height: 4),
                  Text(reply.body, style: text.bodyMedium),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Writing to the team: what it is about and the message. The app version and the platform go with it; nothing else.
class _WriteSheet extends ConsumerStatefulWidget {
  const _WriteSheet();

  @override
  ConsumerState<_WriteSheet> createState() => _WriteSheetState();
}

class _WriteSheetState extends ConsumerState<_WriteSheet> {
  final _message = TextEditingController();
  String _category = 'question';
  bool _sending = false;
  String? _problem;

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_message.text.trim().isEmpty) {
      setState(() => _problem = context.t('Write your message first.'));
      return;
    }
    setState(() {
      _sending = true;
      _problem = null;
    });
    try {
      await ref.read(apiProvider).sendSupportTicket(category: _category, message: _message.text.trim(), appVersion: AppConfig.appVersion, platform: supportPlatform());
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _problem = errorMessage(context, e));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final categories = {
      'question': context.t('A question'),
      'problem': context.t('Something is wrong'),
      'missing_data': context.t('Data looks missing'),
      'billing': context.t('My plan or a payment'),
      'idea': context.t('An idea'),
    };

    return SingleChildScrollView(
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(context.t('Write to us'), style: text.titleLarge),
          const SizedBox(height: 4),
          Text(context.t('Write in your language. Never send a password: we will never ask for one.'), style: text.bodyMedium?.copyWith(color: context.qc.inkMuted)),
          const SizedBox(height: 16),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final entry in categories.entries)
                ChoiceChip(label: Text(entry.value), selected: _category == entry.key, onSelected: _sending ? null : (_) => setState(() => _category = entry.key)),
            ],
          ),
          const SizedBox(height: 16),
          QField(controller: _message, label: context.t('Your message'), maxLines: 6, maxLength: 5000, textInputAction: TextInputAction.newline, keyboardType: TextInputType.multiline),
          if (_problem != null) ...[const SizedBox(height: 12), QNotice(_problem!)],
          const SizedBox(height: 16),
          QButton(label: context.t('Send'), kind: QButtonKind.gold, icon: Icons.send_outlined, loading: _sending, onPressed: _sending ? null : _send),
        ],
      ),
    );
  }
}
