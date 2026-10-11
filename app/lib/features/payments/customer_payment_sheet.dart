import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../../data/qistas_api.dart' show newIdempotencyKey;
import 'payments_state.dart';
import 'record_payment_sheet.dart' show methodLabel, paymentMethods;

/// One payment across a customer's contracts (Win Plan PP6): whatever falls due first is paid first, whichever contract
/// it belongs to, and each contract gets its own line. Answers true once recorded.
Future<bool> showCustomerPaymentSheet(BuildContext context, Customer customer, {required String currency}) async =>
    await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => _CustomerPaymentSheet(customer: customer, currency: currency),
    ) ??
    false;

/// Where the amount starts: each running contract's next instalment that is already due, or, when none is yet, the
/// soonest one (as a contract's own payment sheet starts at its next instalment). Never the whole balance, which one
/// tap could record by mistake. Open accounts take their own payments and are left out.
Money? _startingAmount(Customer customer) {
  final now = DateTime.now();
  final today = '${now.year}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
  final next = [
    for (final contract in customer.contracts)
      if (contract.isRunning && !contract.isOpen && contract.next != null && contract.next!.remaining.isPositive) contract.next!,
  ]..sort((a, b) => a.dueDate.compareTo(b.dueDate));
  if (next.isEmpty) return null;
  final due = next.where((n) => n.dueDate.compareTo(today) <= 0).fold(Money.zero, (sum, n) => sum + n.remaining);

  return due.isPositive ? due : next.first.remaining;
}

/// What this payment can pay off: the running contracts with a schedule. Null when the server did not say.
Money? _reachableOwed(Customer customer) {
  final reachable = [for (final contract in customer.contracts) if (contract.isRunning && !contract.isOpen) contract.owed];
  if (reachable.isEmpty || reachable.contains(null)) return customer.owed;

  return reachable.fold<Money>(Money.zero, (sum, owed) => sum + owed!);
}

class _CustomerPaymentSheet extends ConsumerStatefulWidget {
  const _CustomerPaymentSheet({required this.customer, required this.currency});

  final Customer customer;
  final String currency;

  @override
  ConsumerState<_CustomerPaymentSheet> createState() => _CustomerPaymentSheetState();
}

class _CustomerPaymentSheetState extends ConsumerState<_CustomerPaymentSheet> {
  late final _amount = TextEditingController(text: _startingAmount(widget.customer)?.toDecimalString() ?? '');
  final _note = TextEditingController();
  String _method = 'cash';
  bool _saving = false;
  String? _amountError;
  String? _problem;
  String _key = newIdempotencyKey();
  String _keyFor = '';

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_saving) return;
    final amount = Money.parseTyped(_amount.text);
    final owed = _reachableOwed(widget.customer);
    String? error;
    if (amount == null || !amount.isPositive) {
      error = context.t('Enter the amount the customer paid.');
    } else if (owed != null && amount > owed) {
      error = context.t('The payment is more than the :owed this customer still owes.', {'owed': owed.format(widget.currency)});
    }
    if (error != null) {
      setState(() => _amountError = error);
      return;
    }

    final fingerprint = '${amount!.toDecimalString()}|$_method|${_note.text.trim()}';
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
      await ref.read(apiProvider).payAcrossContracts(widget.customer.id, amount: amount, method: _method, idempotencyKey: _key, note: _note.text);
      refreshAfterMoney(ref, customerId: widget.customer.id);
      unawaited(HapticFeedback.mediumImpact());
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Payment of :amount recorded.', {'amount': amount.format(widget.currency)}))));
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _saving = false;
          _amountError = e.fieldError('amount');
          _problem = _amountError == null ? errorMessage(context, e) : null;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final owed = _reachableOwed(widget.customer);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: ContentColumn(
          padding: EdgeInsets.zero,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t('Take a payment across contracts'), style: text.headlineSmall),
              const SizedBox(height: 4),
              Text(widget.customer.name, style: text.bodyMedium?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 10),
              Text(context.t('It pays whatever falls due first, whichever contract it belongs to. Each contract gets its own line in the ledger.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              if (owed != null) ...[
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [Text(context.t('Still owed'), style: text.bodyMedium?.copyWith(color: c.inkMuted)), MoneyText(owed, widget.currency, style: text.titleMedium)],
                ),
              ],
              const SizedBox(height: 18),
              if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 14)],
              QField(
                controller: _amount,
                label: context.t('Amount (:currency)', {'currency': widget.currency}),
                errorText: _amountError,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                latin: true,
                autofocus: true,
                enabled: !_saving,
              ),
              const SizedBox(height: 16),
              Text(context.t('Paid by'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final method in paymentMethods)
                    ChoiceChip(label: Text(methodLabel(context, method)), selected: _method == method, onSelected: _saving ? null : (_) => setState(() => _method = method)),
                ],
              ),
              const SizedBox(height: 16),
              QField(controller: _note, label: context.t('Note (optional)'), maxLines: 2, enabled: !_saving, maxLength: 1000),
              const SizedBox(height: 22),
              QButton(label: context.t('Record payment'), icon: Icons.check, loading: _saving, onPressed: _submit),
            ],
          ),
        ),
      ),
    );
  }
}
