import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../domain/schedule_generator.dart';
import '../common/add_flows.dart';
import '../contracts/contract_draft.dart';

/// The calculator under the + button (Win Plan PP15). With a customer in front of them, the shop types the price, the
/// down payment, the profit and the number of instalments, and sees each instalment, the total and the last date at
/// once. No customer is needed and nothing is saved; the numbers come from the same engine as the contract form, and
/// "Create a contract from this" carries them into a new contract.
class CalculatorScreen extends ConsumerStatefulWidget {
  const CalculatorScreen({super.key});

  @override
  ConsumerState<CalculatorScreen> createState() => _CalculatorScreenState();
}

class _CalculatorScreenState extends ConsumerState<CalculatorScreen> {
  static final _generator = ScheduleGenerator();
  static const _frequencies = ['monthly', 'biweekly', 'weekly'];

  final _price = TextEditingController();
  final _down = TextEditingController();
  final _markup = TextEditingController();
  final _count = TextEditingController(text: '6');
  String _frequency = 'monthly';
  late DateTime _firstDue = _plusMonth(DateTime.now());
  bool _showAll = false;

  static DateTime _plusMonth(DateTime day) {
    final lastOfNext = DateTime(day.year, day.month + 2, 0).day;

    return DateTime(day.year, day.month + 1, day.day > lastOfNext ? lastOfNext : day.day);
  }

  @override
  void dispose() {
    for (final controller in [_price, _down, _markup, _count]) {
      controller.dispose();
    }
    super.dispose();
  }

  String? _amount(TextEditingController controller) => Money.parseTyped(controller.text)?.toDecimalString();

  int get _instalments => int.tryParse(_count.text.trim()) ?? 0;

  ScheduleRequest? get _request {
    final price = _amount(_price);
    if (price == null) return null;
    final markup = _amount(_markup);

    return ScheduleRequest(
      principal: price,
      downPayment: _down.text.trim().isEmpty ? '0' : _amount(_down) ?? _down.text.trim(),
      markupType: markup != null && Money.parse(markup).isPositive ? 'percent' : 'none',
      markupValue: markup ?? '0',
      count: _instalments,
      frequency: _frequency,
      firstDueDate: isoDay(_firstDue),
    );
  }

  /// The schedule for what is typed, or why there is none yet.
  (ScheduleResult?, String?) _work(BuildContext context) {
    final request = _request;
    if (request == null) return (null, _price.text.trim().isEmpty ? null : context.t('Enter a price greater than zero.'));
    try {
      return (_generator.generate(request), null);
    } on InvalidScheduleException catch (e) {
      return (
        null,
        switch (e.field) {
          'principal' => context.t('Enter a price greater than zero.'),
          'down_payment' => context.t('The down payment must be less than the price.'),
          'markup_value' => context.t('Enter a markup of zero or more.'),
          'count' => context.t('Choose between 1 and :max instalments.', {'max': ScheduleGenerator.basicMaxCount}),
          _ => context.t('Check this date.'),
        }
      );
    }
  }

  Future<void> _pickFirstDue() async {
    final today = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _firstDue,
      firstDate: DateTime(today.year, today.month, today.day),
      lastDate: DateTime(today.year + 5, today.month, today.day),
      locale: Locale(ref.read(localeProvider)),
    );
    if (picked != null) setState(() => _firstDue = picked);
  }

  /// The calculator sits above the bottom bar and the contract form inside it, so the form is opened from the screen the
  /// calculator was started on: closing the calculator first keeps one bar on screen.
  Future<void> _toContract(ScheduleRequest request) async {
    if (!await mayAddContract(context, ref) || !mounted) return;
    final draft = ContractDraft(
      principal: request.principal,
      downPayment: Money.parse(request.downPayment).toDecimalString(),
      markupPercent: request.markupValue,
      count: request.count,
      frequency: request.frequency,
      firstDue: _firstDue,
    );
    final router = GoRouter.of(context);
    router.pop();
    unawaited(router.push<void>('/contracts/new', extra: draft));
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    final currency = account?.currency ?? 'SAR';
    final language = ref.watch(localeProvider);
    final (result, problem) = _work(context);
    final request = _request;
    final labels = {'monthly': context.t('Every month'), 'biweekly': context.t('Every two weeks'), 'weekly': context.t('Every week')};

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Calculator'))),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          ContentColumn(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _Result(result: result, currency: currency, language: language),
                if (problem != null) ...[const SizedBox(height: 12), QNotice(problem)],
                const SizedBox(height: 20),
                QField(
                  key: const ValueKey('calc-price'),
                  controller: _price,
                  label: context.t('Price'),
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  textInputAction: TextInputAction.next,
                  latin: true,
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 14),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: QField(
                        key: const ValueKey('calc-down'),
                        controller: _down,
                        label: context.t('Down payment'),
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        textInputAction: TextInputAction.next,
                        latin: true,
                        onChanged: (_) => setState(() {}),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: QField(
                        key: const ValueKey('calc-markup'),
                        controller: _markup,
                        label: context.t('Profit, %'),
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        textInputAction: TextInputAction.next,
                        latin: true,
                        onChanged: (_) => setState(() {}),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                QField(
                  key: const ValueKey('calc-count'),
                  controller: _count,
                  label: context.t('Instalments'),
                  keyboardType: TextInputType.number,
                  textInputAction: TextInputAction.done,
                  latin: true,
                  onChanged: (_) => setState(() {}),
                ),
                const SizedBox(height: 16),
                Text(context.t('How often'), style: text.labelLarge),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final frequency in _frequencies)
                      ChoiceChip(label: Text(labels[frequency]!), selected: _frequency == frequency, onSelected: (_) => setState(() => _frequency = frequency)),
                  ],
                ),
                const SizedBox(height: 12),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(Icons.event_outlined, color: c.inkMuted),
                  title: Text(context.t('First instalment')),
                  subtitle: Text(formatDay(isoDay(_firstDue), language)),
                  trailing: const Icon(Icons.edit_calendar_outlined),
                  onTap: _pickFirstDue,
                ),
                if (result != null) ...[
                  const SizedBox(height: 8),
                  for (final row in _showAll ? result.installments : result.installments.take(3))
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(
                        children: [
                          SizedBox(width: 32, child: Text('${row.number}', style: text.bodySmall?.copyWith(color: c.inkMuted))),
                          Expanded(child: Text(formatDay(row.dueDate, language), style: text.bodyMedium)),
                          MoneyText(row.amount, currency, style: text.bodyMedium),
                        ],
                      ),
                    ),
                  if (result.installments.length > 3)
                    Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: TextButton(
                        onPressed: () => setState(() => _showAll = !_showAll),
                        child: Text(_showAll ? context.t('Show fewer') : context.t('See all :count instalments', {'count': result.installments.length})),
                      ),
                    ),
                  const SizedBox(height: 12),
                  if (account?.canWrite ?? false)
                    QButton(label: context.t('Create a contract from this'), kind: QButtonKind.gold, icon: Icons.note_add_outlined, onPressed: () => unawaited(_toContract(request!))),
                  const SizedBox(height: 10),
                  Text(context.t('Nothing is saved: this is only a calculation.'), style: text.bodySmall?.copyWith(color: c.inkMuted), textAlign: TextAlign.center),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The one figure the screen is about: each instalment, with the total, the profit and the last date beside it.
class _Result extends StatelessWidget {
  const _Result({required this.result, required this.currency, required this.language});

  final ScheduleResult? result;
  final String currency;
  final String language;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final onHero = c.onPrimary;
    final result = this.result;
    TextStyle? small(double alpha) => text.labelMedium?.copyWith(color: onHero.withValues(alpha: alpha));

    return HeroPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(context.t('Each instalment'), style: text.labelLarge?.copyWith(color: onHero.withValues(alpha: 0.72), letterSpacing: 0.4)),
          const SizedBox(height: 6),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: result == null
                ? Text('—', style: text.displaySmall?.copyWith(color: onHero, fontWeight: FontWeight.w700))
                : MoneyText(result.installments.first.amount, currency, style: text.displaySmall?.copyWith(color: onHero, fontSize: 44, fontWeight: FontWeight.w700, height: 1.1)),
          ),
          if (result != null) ...[
            const SizedBox(height: 16),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: _Figure(label: context.t('Total to repay'), value: result.total.format(currency), style: small)),
                Expanded(child: _Figure(label: context.t('Profit'), value: result.markup.format(currency), style: small)),
                Expanded(child: _Figure(label: context.t('Last instalment'), value: formatDay(result.installments.last.dueDate, language), style: small)),
              ],
            ),
          ] else ...[
            const SizedBox(height: 10),
            Text(context.t('Type a price to see the plan.'), style: small(0.72)),
          ],
        ],
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, required this.style});

  final String label;
  final String value;
  final TextStyle? Function(double alpha) style;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: style(0.64), maxLines: 1, overflow: TextOverflow.ellipsis),
          const SizedBox(height: 2),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Text(value, style: style(1)?.copyWith(fontWeight: FontWeight.w600, fontSize: 15)),
          ),
        ],
      );
}
