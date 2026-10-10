import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';

/// The business's tags, by name (Win Plan PP12).
final customerTagsProvider = FutureProvider.autoDispose<List<CustomerTag>>((ref) => ref.watch(apiProvider).tags());

/// Tags show once the server lists the feature and it is on (an older server has none).
bool showsTags(Account? account) => account?.entitlements['customer_tags']?.isOn ?? false;

/// The colour a tag is drawn in; the same six the website uses.
Color tagColour(BuildContext context, String colour) {
  final c = context.qc;

  return switch (colour) {
    'gold' => c.accent,
    'green' => c.positive,
    'blue' => c.info,
    'red' => c.danger,
    'purple' => const Color(0xFF7A5AF5),
    _ => c.inkMuted,
  };
}

String tagColourName(BuildContext context, String colour) => switch (colour) {
      'gold' => context.t('Gold'),
      'green' => context.t('Green'),
      'blue' => context.t('Blue'),
      'red' => context.t('Red'),
      'purple' => context.t('Purple'),
      _ => context.t('Grey'),
    };

class TagDot extends StatelessWidget {
  const TagDot(this.colour, {super.key, this.size = 8});

  final String colour;
  final double size;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: tagColour(context, colour), shape: BoxShape.circle),
      );
}

/// A small, quiet label on a customer: a coloured dot and the tag's name.
class TagChip extends StatelessWidget {
  const TagChip(this.tag, {super.key});

  final CustomerTag tag;

  @override
  Widget build(BuildContext context) {
    final colour = tagColour(context, tag.colour);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: colour.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: colour.withValues(alpha: 0.28)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            TagDot(tag.colour, size: 6),
            const SizedBox(width: 5),
            Flexible(
              child: Text(tag.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.labelSmall?.copyWith(color: context.qc.ink)),
            ),
          ],
        ),
      ),
    );
  }
}

/// Adds a tag, or renames and recolours [tag]. Gives back the tag as saved, or null when nothing was saved.
Future<CustomerTag?> showTagSheet(BuildContext context, {CustomerTag? tag}) => showModalBottomSheet<CustomerTag>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => _TagSheet(tag: tag),
    );

class _TagSheet extends ConsumerStatefulWidget {
  const _TagSheet({this.tag});

  final CustomerTag? tag;

  @override
  ConsumerState<_TagSheet> createState() => _TagSheetState();
}

class _TagSheetState extends ConsumerState<_TagSheet> {
  late final _name = TextEditingController(text: widget.tag?.name ?? '');
  late String _colour = widget.tag?.colour ?? 'gold';
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _error = context.t('Enter a name.'));
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final api = ref.read(apiProvider);
      final tag = widget.tag;
      final saved = tag == null ? await api.createTag(name: name, colour: _colour) : await api.updateTag(tag.id, name: name, colour: _colour);
      ref.invalidate(customerTagsProvider);
      if (mounted) Navigator.of(context).pop(saved);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _saving = false;
          _error = e.fields['name']?.firstOrNull ?? errorMessage(context, e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(widget.tag == null ? context.t('Add a tag') : context.t('Edit'), style: text.titleLarge),
            const SizedBox(height: 16),
            QField(
              controller: _name,
              label: context.t('Name'),
              helper: context.t('For example: Shop 2, Government staff.'),
              errorText: _error,
              autofocus: widget.tag == null,
              enabled: !_saving,
              maxLength: 40,
              textInputAction: TextInputAction.done,
            ),
            const SizedBox(height: 12),
            Text(context.t('Colour'), style: text.labelLarge),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final colour in CustomerTag.colours)
                  ChoiceChip(
                    avatar: TagDot(colour, size: 10),
                    label: Text(tagColourName(context, colour)),
                    selected: _colour == colour,
                    onSelected: _saving ? null : (_) => setState(() => _colour = colour),
                  ),
              ],
            ),
            const SizedBox(height: 20),
            QButton(label: widget.tag == null ? context.t('Add tag') : context.t('Save changes'), icon: Icons.check, loading: _saving, onPressed: _save),
          ],
        ),
      ),
    );
  }
}

/// Win Plan PP12: the business's tags, to add, rename, recolour or let go of.
class TagsScreen extends ConsumerWidget {
  const TagsScreen({super.key});

  Future<void> _add(BuildContext context) async {
    final saved = await showTagSheet(context);
    if (saved != null && context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Tag added.'))));
  }

  Future<void> _edit(BuildContext context, CustomerTag tag) async {
    final saved = await showTagSheet(context, tag: tag);
    if (saved != null && context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Tag saved.'))));
  }

  Future<void> _remove(BuildContext context, WidgetRef ref, CustomerTag tag) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.t('Remove this tag')),
        content: Text(tag.name),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.t('Keep it'))),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('Remove'))),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;

    try {
      await ref.read(apiProvider).deleteTag(tag.id);
      ref.invalidate(customerTagsProvider);
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Tag removed. Its customers are unchanged.'))));
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tags = ref.watch(customerTagsProvider);
    final account = ref.watch(accountProvider);
    final canManage = account?.canWrite ?? false;
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return SectionScaffold(
      title: context.t('Customer tags'),
      showAccount: false,
      actions: [if (canManage) IconButton(tooltip: context.t('Add a tag'), icon: const Icon(Icons.add_rounded), onPressed: () => _add(context))],
      body: tags.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(customerTagsProvider)),
        data: (list) => list.isEmpty
            ? QEmpty(
                icon: Icons.sell_outlined,
                title: context.t('No tags yet'),
                message: context.t('Group customers by shop, by employer or however you work, then filter every list by it.'),
                action: canManage ? QButton(label: context.t('Add a tag'), expand: false, icon: Icons.add, onPressed: () => _add(context)) : null,
              )
            : RefreshIndicator(
                onRefresh: () => ref.refresh(customerTagsProvider.future),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
                  children: [
                    QCard(
                      padding: EdgeInsets.zero,
                      child: Column(
                        children: [
                          for (final (index, tag) in list.indexed) ...[
                            if (index > 0) const Divider(height: 1),
                            ListTile(
                              leading: TagDot(tag.colour, size: 12),
                              minLeadingWidth: 12,
                              title: Text(tag.name),
                              subtitle: Text(context.t('Customers: :count', {'count': tag.customers ?? 0}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                              onTap: canManage ? () => _edit(context, tag) : null,
                              trailing: canManage
                                  ? PopupMenuButton<String>(
                                      tooltip: context.t('More'),
                                      onSelected: (action) => action == 'edit' ? _edit(context, tag) : _remove(context, ref, tag),
                                      itemBuilder: (context) => [
                                        PopupMenuItem(value: 'edit', child: Text(context.t('Edit'))),
                                        PopupMenuItem(value: 'remove', child: Text(context.t('Remove this tag'))),
                                      ],
                                    )
                                  : null,
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}
