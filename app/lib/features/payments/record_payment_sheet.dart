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
import '../../data/models.dart';
import '../../data/qistas_api.dart';
import '../billing/upgrade_sheet.dart';

/// How a customer paid, in words.
String methodLabel(BuildContext context, String method) => switch (method) {
      'cash' => context.t('Cash'),
      'bank_transfer' => context.t('Bank transfer'),
      'card' => context.t('Card'),
      'cheque' => context.t('Cheque'),
      'other' => context.t('Other'),
      _ => method,
    };

IconData methodIcon(String method) => switch (method) {
      'cash' => Icons.payments_outlined,
      'bank_transfer' => Icons.account_balance_outlined,
      'card' => Icons.credit_card_outlined,
      'cheque' => Icons.receipt_long_outlined,
      _ => Icons.paid_outlined,
    };

const List<String> paymentMethods = ['cash', 'bank_transfer', 'card', 'cheque', 'other'];

/// Opens the sheet that takes a payment on [contract]. Returns the payment that was recorded, or null.
Future<LedgerLine?> showRecordPaymentSheet(BuildContext context, Contract contract, {required String currency}) => showModalBottomSheet<LedgerLine>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (context) => RecordPaymentSheet(contract: contract, currency: currency),
    );

class RecordPaymentSheet extends ConsumerStatefulWidget {
  const RecordPaymentSheet({super.key, required this.contract, required this.currency});

  final Contract contract;
  final String currency;

  @override
  ConsumerState<RecordPaymentSheet> createState() => _RecordPaymentSheetState();
}

class _RecordPaymentSheetState extends ConsumerState<RecordPaymentSheet> {
  late final TextEditingController _amount;
  final _note = TextEditingController();
  String _method = 'cash';
  DateTime _day = DateTime.now();
  bool _saving = false;
  String? _amountError;
  String? _problem;

  // One key per attempt. A double tap, or a retry after the connection dropped, sends the same key and the server
  // records one payment. As soon as what is being paid changes, it is a different payment and gets a new key.
  String _key = newIdempotencyKey();
  String _keyFor = '';

  @override
  void initState() {
    super.initState();
    final suggestion = widget.contract.next?.remaining ?? widget.contract.owed;
    _amount = TextEditingController(text: suggestion != null && suggestion.isPositive ? suggestion.toDecimalString() : '');
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  String _fingerprint(Money amount) => '${amount.toDecimalString()}|$_method|${isoDay(_day)}|${_note.text.trim()}';

  Future<void> _pickDay() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _day,
      firstDate: DateTime(now.year - 2),
      lastDate: now,
      locale: Locale(ref.read(localeProvider)),
    );
    if (picked != null) setState(() => _day = picked);
  }

  Future<void> _submit() async {
    if (_saving) return;

    final amount = Money.parseTyped(_amount.text);
    final owed = widget.contract.owed;
    String? error;
    if (amount == null || !amount.isPositive) {
      error = context.t('Enter the amount the customer paid.');
    } else if (owed != null && amount > owed) {
      error = context.t('That is more than the :owed still owed on this contract.', {'owed': owed.format(widget.currency)});
    }
    if (error != null) {
      setState(() {
        _amountError = error;
        _problem = null;
      });

      return;
    }

    final fingerprint = _fingerprint(amount!);
    if (fingerprint != _keyFor) {
      _key = newIdempotencyKey();
      _keyFor = fingerprint;
    }

    setState(() {
      _saving = true;
      _amountError = null;
      _problem = null;
    });

    try {
      final payment = await ref.read(apiProvider).recordPayment(
            widget.contract.id,
            amount: amount,
            method: _method,
            idempotencyKey: _key,
            note: _note.text,
            paidOn: _day,
          );
      if (mounted) Navigator.of(context).pop(payment);
    } on UpgradeRequired catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      await showUpgradeSheet(context, e);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _amountError = e.fieldError('amount');
        _problem = e.isValidation && e.fields.isNotEmpty ? e.fieldError('paid_at') ?? e.fieldError('method') ?? e.fieldError('note') : errorMessage(context, e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final owed = widget.contract.owed;
    final today = isoDay(DateTime.now()) == isoDay(_day);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: ContentColumn(
          padding: EdgeInsets.zero,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(context.t('Record a payment'), style: text.headlineSmall),
              const SizedBox(height: 4),
              Row(
                children: [
                  Directionality(textDirection: TextDirection.ltr, child: Text(widget.contract.reference, style: text.bodyMedium?.copyWith(color: c.inkMuted))),
                  if (widget.contract.customerName != null) Flexible(child: Text('  ·  ${widget.contract.customerName}', style: text.bodyMedium?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis)),
                ],
              ),
              if (owed != null) ...[
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [Text(context.t('Still owed'), style: text.bodyMedium?.copyWith(color: c.inkMuted)), MoneyText(owed, widget.currency, style: text.titleMedium)],
                ),
              ],
              const SizedBox(height: 20),
              if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 16)],
              QField(
                controller: _amount,
                label: context.t('Amount (:currency)', {'currency': widget.currency}),
                errorText: _amountError,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                textInputAction: TextInputAction.next,
                latin: true,
                autofocus: true,
                enabled: !_saving,
                suffix: owed != null && owed.isPositive
                    ? TextButton(onPressed: _saving ? null : () => setState(() => _amount.text = owed.toDecimalString()), child: Text(context.t('Pay in full')))
                    : null,
              ),
              const SizedBox(height: 16),
              Text(context.t('Paid by'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final method in paymentMethods)
                    ChoiceChip(
                      label: Text(methodLabel(context, method)),
                      selected: _method == method,
                      onSelected: _saving ? null : (_) => setState(() => _method = method),
                    ),
                ],
              ),
              const SizedBox(height: 16),
              Text(context.t('Date received'), style: text.labelLarge),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: _saving ? null : _pickDay,
                icon: const Icon(Icons.calendar_today_outlined, size: 18),
                label: Text(today ? context.t('Today, :date', {'date': formatMoment(_day, language)}) : formatMoment(_day, language)),
              ),
              const SizedBox(height: 16),
              QField(controller: _note, label: context.t('Note (optional)'), maxLines: 2, enabled: !_saving, maxLength: 1000),
              const SizedBox(height: 24),
              QButton(label: context.t('Record payment'), icon: Icons.check, loading: _saving, onPressed: _submit),
              const SizedBox(height: 4),
              QButton(label: context.t('Cancel'), kind: QButtonKind.text, onPressed: _saving ? null : () => Navigator.of(context).pop()),
            ],
          ),
        ),
      ),
    );
  }
}
