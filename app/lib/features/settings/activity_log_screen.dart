import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/backups.dart';

/// Win Plan PP10: what the business's people did, newest first, filtered by person and kind. Owners and managers.
class ActivityLogScreen extends ConsumerStatefulWidget {
  const ActivityLogScreen({super.key});

  @override
  ConsumerState<ActivityLogScreen> createState() => _ActivityLogScreenState();
}

class _ActivityLogScreenState extends ConsumerState<ActivityLogScreen> {
  String? _user;
  String? _kind;
  final List<ActivityEntry> _entries = [];
  List<({String id, String name})> _people = const [];
  int _page = 1;
  bool _hasMore = false;
  bool _loading = true;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final page = await ref.read(apiProvider).activity(user: _user, kind: _kind, page: more ? _page + 1 : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _entries.clear();
        _entries.addAll(page.entries);
        _people = page.people;
        _page = page.currentPage;
        _hasMore = page.hasMore;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e;
          _loading = false;
        });
      }
    }
  }

  Future<void> _choosePerson() async {
    final everyone = context.t('Everyone');
    final chosen = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.only(bottom: 24),
        children: [
          ListTile(title: Text(everyone), trailing: _user == null ? const Icon(Icons.check) : null, onTap: () => Navigator.of(context).pop('')),
          for (final person in _people)
            ListTile(title: Text(person.name), trailing: _user == person.id ? const Icon(Icons.check) : null, onTap: () => Navigator.of(context).pop(person.id)),
        ],
      ),
    );
    if (chosen == null) return;
    _user = chosen.isEmpty ? null : chosen;
    await _load();
  }

  IconData _iconOf(String? kind) => switch (kind) {
        'customers' => Icons.people_outline,
        'contracts' => Icons.description_outlined,
        'payments' => Icons.payments_outlined,
        'investors' => Icons.savings_outlined,
        'products' => Icons.inventory_2_outlined,
        'team' => Icons.groups_2_outlined,
        _ => Icons.tune_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final kinds = [
      (null, context.t('Everything')),
      ('customers', context.t('Customers')),
      ('contracts', context.t('Contracts')),
      ('payments', context.t('Payments')),
      ('investors', context.t('Investors')),
      ('products', context.t('Products')),
      ('team', context.t('Team')),
      ('settings', context.t('Settings')),
    ];
    final personName = _people.where((p) => p.id == _user).map((p) => p.name).firstOrNull ?? context.t('Everyone');

    return SectionScaffold(
      title: context.t('Activity log'),
      showAccount: false,
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
          children: [
            Text(context.t('Who added, changed, recorded or removed what, newest first.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 12),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: ActionChip(avatar: const Icon(Icons.person_outline, size: 18), label: Text(personName), onPressed: _loading ? null : _choosePerson),
            ),
            const SizedBox(height: 8),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final (kind, label) in kinds)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 8),
                      child: ChoiceChip(
                        label: Text(label),
                        selected: _kind == kind,
                        onSelected: _loading
                            ? null
                            : (_) {
                                _kind = kind;
                                _load();
                              },
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            if (_error != null)
              QErrorView(message: errorMessage(context, _error!), retryLabel: context.t('Try again'), onRetry: _load)
            else if (_loading && _entries.isEmpty)
              const QSkeletonList(rows: 5)
            else if (_entries.isEmpty)
              QEmpty(
                icon: Icons.history_rounded,
                title: _user != null || _kind != null ? context.t('Nothing matches these filters') : context.t('Nothing yet'),
                message: _user != null || _kind != null ? context.t('Try another person, kind or day.') : context.t('What your team does appears here as it happens.'),
              )
            else
              QCard(
                padding: EdgeInsets.zero,
                child: Column(
                  children: [
                    for (final (index, entry) in _entries.indexed) ...[
                      if (index > 0) const Divider(height: 1),
                      ListTile(
                        leading: CircleAvatar(radius: 18, backgroundColor: c.surfaceAlt, child: Icon(_iconOf(entry.kind), size: 18, color: c.inkMuted)),
                        title: Text(entry.summary),
                        subtitle: Text('${entry.person ?? 'Qistas'} · ${formatMomentLong(entry.at, language)}', style: text.bodySmall?.copyWith(color: c.inkMuted)),
                      ),
                    ],
                  ],
                ),
              ),
            if (_hasMore) ...[
              const SizedBox(height: 12),
              QButton(label: context.t('Show more'), kind: QButtonKind.quiet, loading: _loading, onPressed: _loading ? null : () => _load(more: true)),
            ],
          ],
        ),
      ),
    );
  }
}
