import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/investors.dart';

/// Money an investor put in or took out. Answers true once it is recorded.
Future<bool> showInvestorEntrySheet(BuildContext context, Investor investor) async =>
    await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => InvestorEntrySheet(investor: investor),
    ) ??
    false;

class InvestorEntrySheet extends ConsumerStatefulWidget {
  const InvestorEntrySheet({super.key, required this.investor});

  final Investor investor;

  @override
  ConsumerState<InvestorEntrySheet> createState() => _InvestorEntrySheetState();
}

class _InvestorEntrySheetState extends ConsumerState<InvestorEntrySheet> {
  final _amount = TextEditingController();
  final _note = TextEditingController();
  String _type = 'deposit';
  DateTime? _on;
  bool _saving = false;
  Map<String, List<String>> _fields = const {};
  String? _problem;

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _on ?? now,
      firstDate: DateTime(now.year - 10),
      lastDate: now,
      locale: Locale(ref.read(localeProvider)),
    );
    if (picked != null) setState(() => _on = picked);
  }

  Future<void> _save() async {
    final amount = Money.parseTyped(_amount.text);
    if (amount == null || !amount.isPositive) {
      setState(() => _fields = {'amount': [context.t('Enter an amount greater than zero, with at most two decimals.')]});
      return;
    }

    setState(() {
      _saving = true;
      _fields = const {};
      _problem = null;
    });
    try {
      await ref.read(apiProvider).recordInvestorEntry(
            widget.investor.id,
            type: _type,
            amount: amount.toDecimalString(),
            occurredOn: _on == null ? null : isoDay(_on!),
            note: _note.text,
          );
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _fields = e.fields;
        _problem = e.fields.isEmpty ? errorMessage(context, e) : null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(context.t('Record money in or out'), style: text.titleLarge),
            const SizedBox(height: 4),
            Text(widget.investor.name, style: text.bodyMedium?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 16),
            if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 12)],
            SegmentedButton<String>(
              segments: [
                ButtonSegment(value: 'deposit', label: Text(context.t('Put in')), icon: const Icon(Icons.south_west_rounded)),
                ButtonSegment(value: 'withdrawal', label: Text(context.t('Taken out')), icon: const Icon(Icons.north_east_rounded)),
              ],
              selected: {_type},
              onSelectionChanged: _saving ? null : (value) => setState(() => _type = value.first),
            ),
            const SizedBox(height: 16),
            QField(
              controller: _amount,
              label: context.t('Amount'),
              errorText: _fields['amount']?.firstOrNull,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              latin: true,
              autofocus: true,
              enabled: !_saving,
            ),
            const SizedBox(height: 16),
            Text(context.t('Date'), style: text.labelLarge),
            const SizedBox(height: 6),
            OutlinedButton.icon(
              onPressed: _saving ? null : _pickDate,
              icon: const Icon(Icons.event_outlined, size: 18),
              label: Text(_on == null ? context.t('Today') : formatMoment(_on!, language)),
            ),
            if (_fields['occurred_on'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(_fields['occurred_on']!.first, style: text.bodySmall?.copyWith(color: c.danger))),
            const SizedBox(height: 16),
            QField(controller: _note, label: context.t('Note (optional)'), enabled: !_saving, maxLength: 500),
            const SizedBox(height: 12),
            QButton(label: context.t('Record'), icon: Icons.check, loading: _saving, onPressed: _save),
          ],
        ),
      ),
    );
  }
}
