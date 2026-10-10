import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../../data/qistas_api.dart';
import '../payments/record_payment_sheet.dart';

/// "They took" adds to an open contract's balance; "they paid" takes from it.
enum LineDirection { took, paid }

/// A tag, in words.
String tagLabel(BuildContext context, String tag) => switch (tag) {
      'advance' => context.t('Advance'),
      'refund' => context.t('Refund'),
      'early_discount' => context.t('Early-payment discount'),
      'unpaid' => context.t('Unpaid'),
      _ => tag,
    };

const List<String> lineTags = ['advance', 'refund', 'early_discount', 'unpaid'];

/// What was recorded, and whether the balance is now past the credit limit (it never refuses).
typedef RecordedLine = ({LedgerLine line, bool overCreditLimit, Money? balance});

/// The sheet behind the two big buttons of an open contract. Answers what was recorded, or null.
Future<RecordedLine?> showLedgerLineSheet(BuildContext context, Contract contract, LineDirection direction) => showModalBottomSheet<RecordedLine>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => LedgerLineSheet(contract: contract, direction: direction),
    );

class LedgerLineSheet extends ConsumerStatefulWidget {
  const LedgerLineSheet({super.key, required this.contract, required this.direction});

  final Contract contract;
  final LineDirection direction;

  @override
  ConsumerState<LedgerLineSheet> createState() => _LedgerLineSheetState();
}

class _LedgerLineSheetState extends ConsumerState<LedgerLineSheet> {
  final _amount = TextEditingController();
  final _note = TextEditingController();
  String _method = 'cash';
  String? _tag;
  bool _saving = false;
  String? _error;

  /// One key per line: tapping again after a dropped connection records it once; changing what it says makes it new.
  String _key = newIdempotencyKey();
  String? _keyFor;

  bool get _took => widget.direction == LineDirection.took;

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final amount = Money.parseTyped(_amount.text);
    if (amount == null || !amount.isPositive) {
      setState(() => _error = context.t('Enter an amount greater than zero, with at most two decimals.'));
      return;
    }

    final fingerprint = '${amount.toDecimalString()}|$_method|$_tag|${_note.text.trim()}';
    if (fingerprint != _keyFor) {
      _key = newIdempotencyKey();
      _keyFor = fingerprint;
    }

    setState(() {
      _saving = true;
      _error = null;
    });
    final api = ref.read(apiProvider);
    try {
      final RecordedLine recorded;
      if (_took) {
        final result = await api.recordCharge(widget.contract.id, amount: amount, idempotencyKey: _key, tag: _tag, note: _note.text);
        recorded = (line: result.line, overCreditLimit: result.overCreditLimit, balance: result.balance);
      } else {
        final line = await api.recordPayment(widget.contract.id, amount: amount, method: _method, idempotencyKey: _key, tag: _tag, note: _note.text);
        recorded = (line: line, overCreditLimit: false, balance: line.contractOwed);
      }
      if (mounted) Navigator.of(context).pop(recorded);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = e.fields['amount']?.firstOrNull ?? errorMessage(context, e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final tone = _took ? c.danger : c.positive;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: _took ? c.tintBlush : c.tintMint, shape: BoxShape.circle),
                  child: Icon(_took ? Icons.add_rounded : Icons.remove_rounded, color: tone),
                ),
                const SizedBox(width: 12),
                Expanded(child: Text(_took ? context.t('They took') : context.t('They paid'), style: text.titleLarge)),
              ],
            ),
            const SizedBox(height: 4),
            Text(_took ? context.t('Adds to what they owe.') : context.t('Comes off what they owe.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 18),
            KeyedSubtree(
              key: const ValueKey('line-amount'),
              child: QField(
                controller: _amount,
                label: context.t('Amount'),
                errorText: _error,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                latin: true,
                autofocus: true,
                enabled: !_saving,
              ),
            ),
            if (!_took) ...[
              const SizedBox(height: 16),
              Text(context.t('How was it paid?'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final method in paymentMethods)
                    ChoiceChip(
                      avatar: Icon(methodIcon(method), size: 18),
                      label: Text(methodLabel(context, method)),
                      selected: _method == method,
                      onSelected: _saving ? null : (_) => setState(() => _method = method),
                    ),
                ],
              ),
            ],
            const SizedBox(height: 16),
            Text(context.t('Tag (optional)'), style: text.labelLarge),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final tag in lineTags)
                  ChoiceChip(
                    label: Text(tagLabel(context, tag)),
                    selected: _tag == tag,
                    onSelected: _saving ? null : (selected) => setState(() => _tag = selected ? tag : null),
                  ),
              ],
            ),
            const SizedBox(height: 16),
            QField(controller: _note, label: _took ? context.t('What they took (optional)') : context.t('Note (optional)'), enabled: !_saving, maxLength: 1000),
            const SizedBox(height: 12),
            QButton(
              label: _took ? context.t('Add to their balance') : context.t('Take off their balance'),
              icon: _took ? Icons.add_rounded : Icons.check_rounded,
              kind: _took ? QButtonKind.primary : QButtonKind.gold,
              loading: _saving,
              onPressed: _save,
            ),
          ],
        ),
      ),
    );
  }
}
