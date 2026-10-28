import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/day_picker.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../../data/plan_changes.dart';
import '../customers/customer_detail_screen.dart';
import '../customers/payday_suggestion.dart';
import '../payments/payments_state.dart';
import 'contracts_screen.dart';

/// Change the plan (Win Plan PP6): a new count or amount for what is left, a skipped month, or a moved date. The old
/// and the new schedules side by side before anything is written; a reason before it is confirmed. Paid instalments
/// never move and the total never changes.
class ChangePlanScreen extends ConsumerStatefulWidget {
  const ChangePlanScreen({super.key, required this.id});

  final String id;

  @override
  ConsumerState<ChangePlanScreen> createState() => _ChangePlanScreenState();
}

class _ChangePlanScreenState extends ConsumerState<ChangePlanScreen> {
  final _count = TextEditingController();
  final _amount = TextEditingController();
  final _reason = TextEditingController();
  String _type = 'reschedule';
  String? _firstDate;
  String? _installmentId;
  String? _newDate;
  PlanPreview? _preview;
  bool _busy = false;
  String? _problem;
  String? _reasonError;

  @override
  void dispose() {
    for (final controller in [_count, _amount, _reason]) {
      controller.dispose();
    }
    super.dispose();
  }

  /// The instalments nobody has paid towards, in date order: the only ones a change touches.
  List<Installment> _untouched(Contract contract) =>
      contract.installments.where((i) => i.status == 'pending' && i.paidAmount.isZero).toList()..sort((a, b) => a.dueDate.compareTo(b.dueDate));

  PlanChange? _change(Contract contract) {
    final untouched = _untouched(contract);
    if (untouched.isEmpty) return null;
    final chosen = _installmentId ?? untouched.first.id;

    return switch (_type) {
      'skip' => PlanChange.skip(chosen),
      'move' => PlanChange.move(chosen, _newDate ?? untouched.firstWhere((i) => i.id == chosen, orElse: () => untouched.first).dueDate),
      _ => PlanChange.reschedule(
          count: int.tryParse(_count.text.trim()),
          amount: _count.text.trim().isEmpty ? Money.parseTyped(_amount.text) : null,
          firstDueDate: _firstDate ?? untouched.first.dueDate,
        ),
    };
  }

  void _edited() {
    if (_preview != null || _problem != null) {
      setState(() {
        _preview = null;
        _problem = null;
      });
    }
  }

  Future<void> _pickDate(String current, ValueChanged<String> chosen) async {
    final now = DateTime.now();
    final picked = await pickDay(context, initial: DateTime.tryParse(current) ?? now, first: DateTime(now.year, now.month, now.day), last: DateTime(now.year + 50), language: ref.read(localeProvider));
    if (picked != null) {
      chosen(isoDay(picked));
      _edited();
    }
  }

  Future<void> _previewChange(Contract contract) async {
    final change = _change(contract);
    if (change == null) return;
    setState(() {
      _busy = true;
      _problem = null;
    });
    try {
      final preview = await ref.read(apiProvider).previewPlanChange(contract.id, change);
      if (mounted) {
        setState(() {
          _preview = preview;
          _busy = false;
        });
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _problem = e.fields.values.expand((m) => m).firstOrNull ?? errorMessage(context, e);
        });
      }
    }
  }

  Future<void> _confirm(Contract contract) async {
    final change = _change(contract);
    if (change == null) return;
    if (_reason.text.trim().isEmpty) {
      setState(() => _reasonError = context.t('Say why the plan is changing.'));
      return;
    }
    setState(() {
      _busy = true;
      _problem = null;
      _reasonError = null;
    });
    try {
      await ref.read(apiProvider).changePlan(contract.id, change, reason: _reason.text);
      refreshAfterMoney(ref, contractId: contract.id, customerId: contract.customerId);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('The plan is changed. The old instalments are kept in the history.'))));
      context.pop();
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _problem = e.fields.values.expand((m) => m).firstOrNull ?? errorMessage(context, e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final contract = ref.watch(contractProvider(widget.id));

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Change the payment plan'))),
      body: contract.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(contractProvider(widget.id))),
        data: (data) => _form(context, data),
      ),
    );
  }

  Widget _form(BuildContext context, Contract contract) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final account = ref.watch(accountProvider);
    final currency = account?.currency ?? '';
    final untouched = _untouched(contract);
    final preview = _preview;

    if (untouched.isEmpty) {
      return QEmpty(icon: Icons.event_busy_outlined, title: context.t('Every instalment has been paid towards; there is nothing left to change.'), message: '');
    }
    final chosen = untouched.firstWhere((i) => i.id == _installmentId, orElse: () => untouched.first);
    // Payday learning: when the customer usually pays, from their own page. Nothing is asked for while the feature is off, or
    // where there is no date field to offer it under.
    final customerId = contract.customerId;
    final payer = _type == 'move' && customerId != null && offersPaydaySuggestion(account) ? ref.watch(customerProvider(customerId)).valueOrNull : null;
    final payPattern = payer?.payPattern;
    final moveTo = _newDate ?? chosen.dueDate;
    final now = DateTime.now();

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        child: ContentColumn(
          padding: EdgeInsets.zero,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t('Only the instalments nobody has paid towards change. Paid ones stay exactly as they are, and the total to repay never moves.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 16),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final (value, label) in [('reschedule', context.t('A new count or amount for what is left')), ('skip', context.t('Skip a month')), ('move', context.t('Move one date'))])
                    ChoiceChip(
                      label: Text(label),
                      selected: _type == value,
                      onSelected: _busy
                          ? null
                          : (_) {
                              setState(() => _type = value);
                              _edited();
                            },
                    ),
                ],
              ),
              const SizedBox(height: 16),
              if (_type == 'reschedule') ...[
                QField(controller: _count, label: context.t('Number of instalments'), keyboardType: TextInputType.number, latin: true, enabled: !_busy, onChanged: (_) => _edited()),
                const SizedBox(height: 12),
                QField(controller: _amount, label: context.t('Or an amount for each'), keyboardType: const TextInputType.numberWithOptions(decimal: true), latin: true, enabled: !_busy, onChanged: (_) => _edited()),
                const SizedBox(height: 12),
                _DateRow(
                  label: context.t('First new instalment'),
                  value: formatDay(_firstDate ?? untouched.first.dueDate, language),
                  onTap: _busy ? null : () => _pickDate(_firstDate ?? untouched.first.dueDate, (d) => setState(() => _firstDate = d)),
                ),
              ] else ...[
                Text(context.t('Which instalment'), style: text.labelLarge),
                const SizedBox(height: 8),
                DropdownButtonFormField<String>(
                  initialValue: chosen.id,
                  isExpanded: true,
                  items: [for (final i in untouched) DropdownMenuItem(value: i.id, child: Text('${formatDay(i.dueDate, language)}, ${i.amount.format(currency)}', maxLines: 1, overflow: TextOverflow.ellipsis))],
                  onChanged: _busy
                      ? null
                      : (id) {
                          setState(() => _installmentId = id);
                          _edited();
                        },
                ),
                if (_type == 'move') ...[
                  const SizedBox(height: 12),
                  _DateRow(
                    label: context.t('New date (to move one)'),
                    value: formatDay(_newDate ?? chosen.dueDate, language),
                    onTap: _busy ? null : () => _pickDate(_newDate ?? chosen.dueDate, (d) => setState(() => _newDate = d)),
                  ),
                  // Offered, never applied: only the button inside changes the date.
                  if (payer != null && payPattern != null) ...[
                    const SizedBox(height: 10),
                    PaydaySuggestion(
                      name: payer.name,
                      pattern: payPattern,
                      from: DateTime.tryParse(moveTo),
                      notBefore: DateTime(now.year, now.month, now.day),
                      onUse: _busy
                          ? null
                          : (day) {
                              setState(() => _newDate = isoDay(day));
                              _edited();
                            },
                    ),
                  ],
                ],
              ],
              if (_problem != null) ...[const SizedBox(height: 14), QNotice(_problem!, icon: Icons.error_outline)],
              const SizedBox(height: 18),
              if (preview == null)
                QButton(label: context.t('Preview'), kind: QButtonKind.quiet, icon: Icons.compare_arrows_rounded, loading: _busy, onPressed: () => _previewChange(contract))
              else ...[
                Text(context.t('Before and after'), style: text.titleMedium),
                const SizedBox(height: 10),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(child: _Rows(title: context.t('Now'), rows: preview.before, currency: currency, language: language)),
                    const SizedBox(width: 10),
                    Expanded(child: _Rows(title: context.t('After the change'), rows: preview.after, currency: currency, language: language, fresh: true)),
                  ],
                ),
                const SizedBox(height: 16),
                QField(controller: _reason, label: context.t('Why is the plan changing?'), errorText: _reasonError, maxLines: 2, maxLength: 500, enabled: !_busy),
                const SizedBox(height: 12),
                QButton(label: context.t('Change the payment plan'), icon: Icons.check, loading: _busy, onPressed: () => _confirm(contract)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _DateRow extends StatelessWidget {
  const _DateRow({required this.label, required this.value, required this.onTap});

  final String label;
  final String value;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => QCard(
        onTap: onTap,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Icon(Icons.event_outlined, color: context.qc.inkMuted),
            const SizedBox(width: 12),
            Expanded(child: Text(label, style: Theme.of(context).textTheme.bodyMedium)),
            Flexible(child: Text(value, style: Theme.of(context).textTheme.titleSmall, textAlign: TextAlign.end)),
          ],
        ),
      );
}

class _Rows extends StatelessWidget {
  const _Rows({required this.title, required this.rows, required this.currency, required this.language, this.fresh = false});

  final String title;
  final List<ScheduleRow> rows;
  final String currency;
  final String language;
  final bool fresh;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(title, style: text.labelLarge?.copyWith(color: c.inkMuted)),
        const SizedBox(height: 6),
        for (final row in rows)
          Container(
            margin: const EdgeInsets.only(bottom: 4),
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
            decoration: BoxDecoration(color: (fresh ? c.accent : c.line).withValues(alpha: fresh ? 0.12 : 0.3), borderRadius: BorderRadius.circular(10)),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(formatDay(row.dueDate, language), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                Text(row.amount.format(currency), style: text.titleSmall),
              ],
            ),
          ),
      ],
    );
  }
}
