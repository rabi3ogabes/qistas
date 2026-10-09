import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';

/// What the owner can set for this workspace. The server describes each tool, so a feature that arrives later needs
/// no new screen here.
final toolsProvider = FutureProvider.autoDispose<({List<Tool> tools, bool canEdit})>((ref) => ref.watch(apiProvider).tools());

class ToolsScreen extends ConsumerWidget {
  const ToolsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final tools = ref.watch(toolsProvider);

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Instalment tools'))),
      body: ContentColumn(
        child: tools.when(
          loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
          error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(toolsProvider)),
          data: (loaded) => loaded.tools.isEmpty
              ? QEmpty(icon: Icons.tune_rounded, title: context.t('Nothing to set up yet'), message: context.t('Tools you can switch on will appear here.'))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                  children: [
                    if (!loaded.canEdit) ...[
                      QNotice(context.t('Only the owner and managers can change these.'), tone: QTone.info, icon: Icons.info_outline),
                      const SizedBox(height: 12),
                    ],
                    for (final tool in loaded.tools)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: _ToolCard(key: ValueKey(tool.key), tool: tool, editable: loaded.canEdit),
                      ),
                  ],
                ),
        ),
      ),
    );
  }
}

class _ToolCard extends ConsumerStatefulWidget {
  const _ToolCard({super.key, required this.tool, required this.editable});

  final Tool tool;
  final bool editable;

  @override
  ConsumerState<_ToolCard> createState() => _ToolCardState();
}

class _ToolCardState extends ConsumerState<_ToolCard> {
  late Object? _value = widget.tool.value;
  late final TextEditingController _number = TextEditingController(text: '${widget.tool.value ?? ''}');
  bool _saving = false;
  bool _saved = false;
  String? _error;

  @override
  void dispose() {
    _number.dispose();
    super.dispose();
  }

  /// Sends [value]; shows it at once, and puts the old one back if the server refuses.
  Future<void> _save(Object? value) async {
    final before = _value;
    setState(() {
      _value = value;
      _saving = true;
      _saved = false;
      _error = null;
    });

    try {
      final saved = await ref.read(apiProvider).saveTool(widget.tool.key, value);
      if (!mounted) return;
      setState(() {
        _value = saved.value;
        _number.text = '${saved.value ?? ''}';
        _saving = false;
        _saved = true;
      });
      unawaited(HapticFeedback.selectionClick());
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _value = before;
        _number.text = '${before ?? ''}';
        _saving = false;
        _error = e.fieldError('value') ?? e.message;
      });
      // The platform switched that feature off since this list was read: read the list again, and the tool is gone.
      if (e.code == 'feature_unavailable') ref.invalidate(toolsProvider);
      if (e is UpgradeRequired) await showUpgradeSheet(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final tool = widget.tool;
    final enabled = widget.editable && !_saving;

    final heading = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(tool.label, style: text.titleSmall),
        if (tool.help.isNotEmpty) ...[const SizedBox(height: 2), Text(tool.help, style: text.bodySmall?.copyWith(color: c.inkMuted))],
      ],
    );

    final Widget control = switch (tool.type) {
      'switch' => Row(
          children: [
            Expanded(child: heading),
            const SizedBox(width: 12),
            Switch(value: _value == true, onChanged: enabled ? _save : null),
          ],
        ),
      'int' => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            heading,
            const SizedBox(height: 12),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: TextField(
                    controller: _number,
                    enabled: enabled,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    textDirection: TextDirection.ltr,
                    decoration: InputDecoration(errorText: _error),
                    onChanged: (_) => setState(() => _saved = false),
                    onSubmitted: (_) => _submitNumber(),
                  ),
                ),
                const SizedBox(width: 12),
                QButton(label: context.t('Save'), expand: false, kind: QButtonKind.quiet, loading: _saving, onPressed: enabled && _number.text != '${_value ?? ''}' ? _submitNumber : null),
              ],
            ),
          ],
        ),
      _ => Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            heading,
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final option in tool.options)
                  ChoiceChip(label: Text(option.label), selected: '$_value' == option.value, onSelected: enabled ? (_) => _save(option.value) : null),
              ],
            ),
          ],
        ),
    };

    return QCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          control,
          if (tool.type != 'int' && _error != null) ...[const SizedBox(height: 8), Text(_error!, style: text.bodySmall?.copyWith(color: c.danger))],
          if (_saved) ...[const SizedBox(height: 8), Row(children: [Icon(Icons.check_rounded, size: 16, color: c.positive), const SizedBox(width: 6), Text(context.t('Saved'), style: text.bodySmall?.copyWith(color: c.positive))])],
        ],
      ),
    );
  }

  void _submitNumber() {
    final parsed = int.tryParse(_number.text.trim());
    if (parsed != null) _save(parsed);
  }
}
