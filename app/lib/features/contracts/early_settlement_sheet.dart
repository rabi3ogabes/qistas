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
import '../../data/plan_changes.dart';
import '../../data/qistas_api.dart' show newIdempotencyKey;
import '../payments/payments_state.dart';
import '../payments/record_payment_sheet.dart' show methodLabel, paymentMethods;

/// Settle early (Win Plan PP6): the payoff in large figures, a discount on the profit not yet earned (a percent on a
/// slider, or an amount), and one tap to settle at exactly the figure shown. Answers true once settled.
Future<bool> showEarlySettlementSheet(BuildContext context, Contract contract, {required String currency}) async =>
    await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => _EarlySettlementSheet(contract: contract, currency: currency),
    ) ??
    false;

class _EarlySettlementSheet extends ConsumerStatefulWidget {
  const _EarlySettlementSheet({required this.contract, required this.currency});

  final Contract contract;
  final String currency;

  @override
  ConsumerState<_EarlySettlementSheet> createState() => _EarlySettlementSheetState();
}

class _EarlySettlementSheetState extends ConsumerState<_EarlySettlementSheet> {
  final _amount = TextEditingController();
  String _type = 'none';
  double _percent = 50;
  String _method = 'cash';
  EarlySettlementQuote? _quote;
  bool _loading = true;
  bool _saving = false;
  String? _problem;
  Timer? _debounce;
  String _key = newIdempotencyKey();
  String _keyFor = '';

  @override
  void initState() {
    super.initState();
    _fetch();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _amount.dispose();
    super.dispose();
  }

  String get _value => switch (_type) {
        'percent' => _percent.round().toString(),
        'fixed' => (Money.parseTyped(_amount.text) ?? Money.zero).toDecimalString(),
        _ => '0',
      };

  Future<void> _fetch() async {
    setState(() => _loading = true);
    try {
      final quote = await ref.read(apiProvider).earlySettlementQuote(widget.contract.id, rebateType: _type, rebateValue: _value);
      if (mounted) {
        setState(() {
          _quote = quote;
          _loading = false;
        });
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _loading = false;
          _problem = errorMessage(context, e);
        });
      }
    }
  }

  void _changed({bool now = false}) {
    _debounce?.cancel();
    _problem = null;
    if (now) {
      unawaited(_fetch());
    } else {
      _debounce = Timer(const Duration(milliseconds: 350), _fetch);
    }
  }

  Future<void> _settle() async {
    final quote = _quote;
    if (quote == null || _saving) return;
    // The same attempt keeps its key; a different figure is a different settlement.
    final fingerprint = '${quote.payoff.toDecimalString()}|$_type|$_value|$_method';
    if (fingerprint != _keyFor) {
      _key = newIdempotencyKey();
      _keyFor = fingerprint;
    }

    setState(() {
      _saving = true;
      _problem = null;
    });
    try {
      await ref.read(apiProvider).settleEarly(widget.contract.id, rebateType: _type, rebateValue: _value, payoff: quote.payoff, method: _method, idempotencyKey: _key);
      refreshAfterMoney(ref, contractId: widget.contract.id, customerId: widget.contract.customerId);
      unawaited(HapticFeedback.mediumImpact());
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _problem = errorMessage(context, e);
      });
      // Money came in meanwhile: show the new figure; nothing was settled.
      if (e.code == 'quote_changed') unawaited(_fetch());
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final quote = _quote;
    String format(Money amount) => amount.format(widget.currency);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: ContentColumn(
          padding: EdgeInsets.zero,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t('Settle early'), style: text.headlineSmall),
              const SizedBox(height: 4),
              Directionality(textDirection: TextDirection.ltr, child: Text(widget.contract.reference, style: text.bodyMedium?.copyWith(color: c.inkMuted), textAlign: TextAlign.start)),
              const SizedBox(height: 20),
              Text(context.t('To settle today'), style: text.labelLarge?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 4),
              AnimatedOpacity(
                duration: const Duration(milliseconds: 160),
                opacity: _loading ? 0.45 : 1,
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: Text(quote == null ? '—' : format(quote.payoff), style: text.displaySmall?.copyWith(fontWeight: FontWeight.w700, height: 1.1)),
                ),
              ),
              if (quote != null && quote.rebate.isPositive) ...[
                const SizedBox(height: 6),
                Text(context.t('The customer saves :amount.', {'amount': format(quote.rebate)}), style: text.titleSmall?.copyWith(color: c.positive)),
              ],
              if (quote != null) ...[
                const SizedBox(height: 14),
                QCard(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                  child: Column(
                    children: [
                      _Fact(context.t('Still owed'), format(quote.remaining)),
                      _Fact(context.t('Profit already earned'), format(quote.earnedProfit)),
                      _Fact(context.t('Profit not yet earned'), format(quote.unearnedProfit)),
                    ],
                  ),
                ),
              ],
              if (quote != null && quote.capped) ...[
                const SizedBox(height: 10),
                QNotice(context.t('A discount cannot be more than the profit not yet earned, so it was kept to :amount.', {'amount': format(quote.rebate)}), tone: QTone.warn, icon: Icons.info_outline),
              ],
              const SizedBox(height: 18),
              Text(context.t('Discount'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                children: [
                  for (final (value, label) in [('none', context.t('No discount')), ('percent', context.t('Percent')), ('fixed', context.t('Amount'))])
                    ChoiceChip(
                      label: Text(label),
                      selected: _type == value,
                      onSelected: _saving
                          ? null
                          : (_) {
                              setState(() => _type = value);
                              _changed(now: true);
                            },
                    ),
                ],
              ),
              if (_type == 'percent') ...[
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: Slider(
                        value: _percent,
                        max: 100,
                        divisions: 20,
                        label: '${_percent.round()}%',
                        onChanged: _saving ? null : (v) => setState(() => _percent = v),
                        onChangeEnd: (_) => _changed(now: true),
                      ),
                    ),
                    SizedBox(width: 52, child: Text('${_percent.round()}%', style: text.titleSmall, textAlign: TextAlign.end)),
                  ],
                ),
                Text(context.t('A percent of the profit not yet earned'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              ],
              if (_type == 'fixed') ...[
                const SizedBox(height: 10),
                QField(
                  controller: _amount,
                  label: context.t('Amount (:currency)', {'currency': widget.currency}),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  latin: true,
                  enabled: !_saving,
                  onChanged: (_) => _changed(),
                ),
              ],
              const SizedBox(height: 18),
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
              if (_problem != null) ...[const SizedBox(height: 16), QNotice(_problem!, icon: Icons.error_outline)],
              const SizedBox(height: 22),
              QButton(
                label: quote == null ? context.t('Settle early') : context.t('Settle for :amount', {'amount': format(quote.payoff)}),
                icon: Icons.verified_outlined,
                loading: _saving,
                onPressed: quote == null || _loading ? null : _settle,
              ),
              const SizedBox(height: 6),
              Text(
                context.t('One payment of the payoff is recorded, what the discount covers is waived, and the contract is settled. Voiding that payment brings everything back.'),
                style: text.bodySmall?.copyWith(color: c.inkMuted),
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Fact extends StatelessWidget {
  const _Fact(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Expanded(child: Text(label, style: text.bodyMedium?.copyWith(color: context.qc.inkMuted))),
          Directionality(textDirection: TextDirection.ltr, child: Text(value, style: text.bodyLarge)),
        ],
      ),
    );
  }
}
