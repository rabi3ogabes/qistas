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
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../../data/qistas_api.dart';
import '../../domain/schedule_generator.dart';
import '../billing/upgrade_sheet.dart';
import '../customers/customer_detail_screen.dart';
import '../payments/payments_state.dart';

/// Opens a contract: who it is for, what was sold and how it is paid. The schedule is worked out on the phone as
/// the numbers are typed (the same arithmetic the server uses), so the customer can be shown it before anything is saved.
class ContractFormScreen extends ConsumerStatefulWidget {
  const ContractFormScreen({super.key, this.customerId});

  final String? customerId;

  @override
  ConsumerState<ContractFormScreen> createState() => _ContractFormScreenState();
}

/// One line of the shop's own dates: a date picked from the calendar and the amount due on it.
class _DateRow {
  _DateRow(this.date, String amount) : amount = TextEditingController(text: amount);

  DateTime? date;
  final TextEditingController amount;

  bool get isBlank => date == null && amount.text.trim().isEmpty;
}

class _ContractFormScreenState extends ConsumerState<ContractFormScreen> {
  static final _generator = ScheduleGenerator();
  static const _maxGraceDays = 90;

  final _principal = TextEditingController();
  final _down = TextEditingController();
  final _markup = TextEditingController();
  final _notes = TextEditingController();

  Customer? _customer;
  bool _loadingCustomer = false;
  String _type = 'scheduled';
  String _markupType = 'none';
  String _frequency = 'monthly';
  int _count = 6;
  int _grace = 0;
  final List<_DateRow> _rows = [];
  late DateTime _start = _today();
  late DateTime _firstDue = _plusMonth(_start);
  bool _firstDueTouched = false;

  bool _saving = false;
  bool _submitted = false;
  Map<String, List<String>> _fields = const {};
  String? _problem;

  static DateTime _today() {
    final now = DateTime.now();

    return DateTime(now.year, now.month, now.day);
  }

  static DateTime _plusMonth(DateTime day) {
    final lastOfNext = DateTime(day.year, day.month + 2, 0).day;

    return DateTime(day.year, day.month + 1, day.day > lastOfNext ? lastOfNext : day.day);
  }

  /// Daily to yearly plans, the shop's own dates, up to 600 and grace days: only when the server says so. A server
  /// that does not mention the feature predates it and would refuse those plans.
  bool get _flexible => ref.read(accountProvider)?.entitlements['flexible_schedules']?.enabled ?? false;

  int get _maxCount => _flexible ? ScheduleGenerator.maxCount : ScheduleGenerator.basicMaxCount;

  bool get _isCustom => _frequency == ScheduleGenerator.custom;

  @override
  void initState() {
    super.initState();
    if (widget.customerId != null) _loadCustomer(widget.customerId!);
  }

  @override
  void dispose() {
    for (final controller in [_principal, _down, _markup, _notes, for (final row in _rows) row.amount]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadCustomer(String id) async {
    setState(() => _loadingCustomer = true);
    try {
      final customer = await ref.read(apiProvider).customer(id);
      if (mounted) setState(() => _customer = customer);
    } on ApiException {
      // The picker is still there: choose the customer by hand.
    } finally {
      if (mounted) setState(() => _loadingCustomer = false);
    }
  }

  // ------------------------------------------------------------------ the schedule

  /// What a typed amount means to the schedule and to the server: a two-decimal string, or null if it is not an amount.
  String? _amount(TextEditingController controller) => Money.parseTyped(controller.text)?.toDecimalString();

  /// The shop's own lines as the generator reads them; a line left blank is not a payment.
  List<ScheduleEntry> _entries() => [
        for (final row in _rows)
          if (!row.isBlank) ScheduleEntry(row.date == null ? '' : isoDay(row.date!), _amount(row.amount) ?? row.amount.text.trim()),
      ];

  ScheduleRequest? _request({String? frequency}) {
    final principal = _amount(_principal);
    if (principal == null) return null;
    final rhythm = frequency ?? _frequency;

    return ScheduleRequest(
      principal: principal,
      downPayment: _down.text.trim().isEmpty ? '0' : _amount(_down) ?? _down.text.trim(),
      markupType: _markupType,
      markupValue: _markupType == 'none' || _markup.text.trim().isEmpty ? '0' : _amount(_markup) ?? _markup.text.trim(),
      count: _count,
      frequency: rhythm,
      firstDueDate: isoDay(_firstDue),
      customSchedule: rhythm == ScheduleGenerator.custom ? _entries() : null,
    );
  }

  /// The schedule for what is typed so far, or why there is none yet.
  (ScheduleResult?, Map<String, String>) _preview(BuildContext context) {
    final request = _request();
    if (request == null) {
      return (null, _principal.text.trim().isEmpty ? const {} : {'principal': context.t('Enter a price greater than zero.')});
    }

    try {
      return (_generator.generate(request), const {});
    } on InvalidScheduleException catch (e) {
      return (null, {e.field: _scheduleMessage(context, e)});
    }
  }

  /// What the shop's own amounts still lack (negative when they go over), or null when that is not the problem.
  Money? _remaining() {
    final request = _request();
    if (request == null || !_isCustom) return null;
    try {
      _generator.generate(request);
    } on InvalidScheduleException catch (e) {
      return e.remaining;
    }

    return null;
  }

  String _scheduleMessage(BuildContext context, InvalidScheduleException e) {
    final currency = ref.read(accountProvider)?.currency;

    return switch (e.field) {
      'principal' => context.t('Enter a price greater than zero.'),
      'down_payment' => context.t('The down payment must be less than the price.'),
      'markup_value' => context.t('Enter a markup of zero or more.'),
      'count' => context.t('Choose between 1 and :max instalments.', {'max': _maxCount}),
      'custom_schedule' => switch (e.reason) {
          'sum' when e.remaining!.isPositive => context.t('Still to place: :amount', {'amount': e.remaining!.format(currency)}),
          'sum' => context.t('That is :amount more than the total.', {'amount': (-e.remaining!).format(currency)}),
          'date' => context.t('Row :row: choose its date.', {'row': e.row}),
          'order' => context.t('Row :row: choose a later date than the row before.', {'row': e.row}),
          'amount' => context.t('Row :row: enter an amount greater than zero.', {'row': e.row}),
          _ => context.t('Add at least one payment date.'),
        },
      _ => context.t('Check this date.'),
    };
  }

  // -------------------------------------------------------------------------- inputs

  Future<DateTime?> _pick(DateTime initial, {required DateTime first, required DateTime last}) => showDatePicker(
        context: context,
        initialDate: initial.isBefore(first) ? first : (initial.isAfter(last) ? last : initial),
        firstDate: first,
        lastDate: last,
        locale: Locale(ref.read(localeProvider)),
      );

  Future<void> _pickStart() async {
    final now = _today();
    final picked = await _pick(_start, first: DateTime(now.year - 5), last: DateTime(now.year + 2));
    if (picked == null) return;
    setState(() {
      _start = picked;
      if (!_firstDueTouched) _firstDue = _plusMonth(picked);
    });
  }

  Future<void> _pickFirstDue() async {
    final picked = await _pick(_firstDue, first: DateTime(_start.year - 1), last: DateTime(_start.year + 12));
    if (picked != null) {
      setState(() {
        _firstDue = picked;
        _firstDueTouched = true;
      });
    }
  }

  Future<void> _pickCustomer() async {
    final picked = await showModalBottomSheet<Customer>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (context) => const _CustomerPicker(),
    );
    if (picked != null) setState(() => _customer = picked);
  }

  /// Choosing "my own dates" starts from the plan already on screen, so the shop adjusts it instead of typing it all.
  void _chooseFrequency(String frequency) {
    if (frequency == _frequency) return;
    setState(() {
      if (frequency == ScheduleGenerator.custom && _rows.every((row) => row.isBlank)) {
        final request = _request();
        ScheduleResult? plan;
        try {
          plan = request == null ? null : _generator.generate(request);
        } on InvalidScheduleException {
          plan = null;
        }
        _replaceRows([
          if (plan != null)
            for (final row in plan.installments) _DateRow(DateTime.parse(row.dueDate), row.amount.toDecimalString())
          else
            _DateRow(_firstDue, ''),
        ]);
      }
      _frequency = frequency;
    });
  }

  void _replaceRows(List<_DateRow> rows) {
    for (final row in _rows) {
      row.amount.dispose();
    }
    _rows
      ..clear()
      ..addAll(rows);
  }

  /// A new line a month after the last, already holding what is still to place.
  void _addRow() {
    final remaining = _remaining();
    final last = _rows.map((row) => row.date).whereType<DateTime>().fold<DateTime?>(null, (a, b) => a == null || b.isAfter(a) ? b : a);
    setState(() => _rows.add(_DateRow(last == null ? _firstDue : _plusMonth(last), remaining != null && remaining.isPositive ? remaining.toDecimalString() : '')));
  }

  void _removeRow(_DateRow row) {
    setState(() => _rows.remove(row));
    // The field may still be on screen for this frame.
    WidgetsBinding.instance.addPostFrameCallback((_) => row.amount.dispose());
  }

  /// Dates are kept in order as they are picked, so the only order mistake left is the same date twice.
  Future<void> _pickRowDate(_DateRow row) async {
    final picked = await _pick(row.date ?? _firstDue, first: DateTime(_start.year - 1), last: DateTime(_start.year + 50));
    if (picked == null) return;
    setState(() {
      row.date = picked;
      _rows.sort((a, b) => a.date == null ? 1 : (b.date == null ? -1 : a.date!.compareTo(b.date!)));
    });
  }

  Future<void> _typeCount() async {
    final count = await showDialog<int>(context: context, builder: (context) => _CountDialog(initial: _count, max: _maxCount));
    if (count != null) setState(() => _count = count);
  }

  // --------------------------------------------------------------------------- save

  String? _error(String field) => _fields[field]?.firstOrNull;

  /// What the server said about the shop's own dates or the plan as a whole, if anything.
  String? _scheduleError() =>
      _error('custom_schedule') ?? _error('schedule') ?? _fields.entries.where((e) => e.key.startsWith('custom_schedule.')).map((e) => e.value.firstOrNull).nonNulls.firstOrNull;

  Future<void> _save() async {
    if (_saving) return;
    setState(() {
      _submitted = true;
      _problem = null;
      _fields = const {};
    });

    final customer = _customer;
    final scheduled = _type == 'scheduled';
    final (preview, previewErrors) = _preview(context);
    final principal = _amount(_principal);

    final problems = <String, List<String>>{
      if (customer == null) 'customer_id': [context.t('Choose who this contract is for.')],
      if (principal == null || !(Money.tryParse(principal)?.isPositive ?? false)) 'principal': [context.t('Enter a price greater than zero.')],
      if (scheduled) for (final entry in previewErrors.entries) entry.key: [entry.value],
    };
    if (problems.isNotEmpty || customer == null || principal == null || (scheduled && preview == null)) {
      setState(() => _fields = problems);

      return;
    }

    setState(() => _saving = true);

    final flexible = _flexible;
    final form = ContractForm(
      customerId: customer.id,
      type: _type,
      principal: principal,
      downPayment: scheduled && _down.text.trim().isNotEmpty ? _amount(_down) ?? '' : '',
      markupType: _markupType,
      markupValue: scheduled && _markupType != 'none' && _markup.text.trim().isNotEmpty ? _amount(_markup) ?? '' : '',
      installmentCount: _count,
      frequency: _frequency,
      startDate: isoDay(_start),
      firstDueDate: isoDay(_firstDue),
      notes: _notes.text,
      graceDays: scheduled && flexible ? _grace : 0,
      customSchedule: scheduled && _isCustom && preview != null
          ? [for (final row in preview.installments) ScheduleEntry(row.dueDate, row.amount.toDecimalString())]
          : const [],
    );

    try {
      final contract = await ref.read(apiProvider).createContract(form);
      refreshAfterMoney(ref, customerId: customer.id);
      ref.invalidate(customerProvider(customer.id));
      await ref.read(authProvider.notifier).refresh();

      if (mounted) context.pushReplacement('/contracts/${contract.id}');
    } on UpgradeRequired catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      await showUpgradeSheet(context, e);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _fields = e.fields;
        _problem = e.isValidation && e.fields.isNotEmpty ? context.t('Check the highlighted fields and try again.') : errorMessage(context, e);
      });
    }
  }

  // -------------------------------------------------------------------------- build

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    final language = ref.watch(localeProvider);
    final currency = account?.currency ?? '';
    final flexible = account?.entitlements['flexible_schedules']?.enabled ?? false;
    final scheduled = _type == 'scheduled';
    final custom = scheduled && _isCustom;
    final (preview, previewErrors) = _preview(context);

    String? shown(String field) => _error(field) ?? (_submitted || _principal.text.isNotEmpty ? previewErrors[field] : null);

    return Scaffold(
      appBar: AppBar(title: Text(context.t('New contract'))),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          child: ContentColumn(
              padding: EdgeInsets.zero,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 16)],
                  Text(context.t('Customer'), style: text.labelLarge),
                  const SizedBox(height: 6),
                  _loadingCustomer
                      ? const QSkeleton(height: 56)
                      : OutlinedButton(
                          onPressed: _saving ? null : _pickCustomer,
                          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(56), alignment: AlignmentDirectional.centerStart),
                          child: Row(
                            children: [
                              Icon(Icons.person_outline, color: c.inkMuted),
                              const SizedBox(width: 12),
                              Expanded(child: Text(_customer?.name ?? context.t('Choose a customer'), maxLines: 2, overflow: TextOverflow.ellipsis)),
                              Icon(Icons.unfold_more, color: c.inkMuted),
                            ],
                          ),
                        ),
                  if (_error('customer_id') != null)
                    Padding(padding: const EdgeInsets.only(top: 6), child: Text(_error('customer_id')!, style: text.bodySmall?.copyWith(color: c.danger))),
                  const SizedBox(height: 20),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      ChoiceChip(label: Text(context.t('Instalments')), selected: scheduled, onSelected: _saving ? null : (_) => setState(() => _type = 'scheduled')),
                      ChoiceChip(label: Text(context.t('Cash sale')), selected: !scheduled, onSelected: _saving ? null : (_) => setState(() => _type = 'cash')),
                    ],
                  ),
                  const SizedBox(height: 20),
                  QField(
                    controller: _principal,
                    label: context.t('Sale price (:currency)', {'currency': currency}),
                    errorText: _error('principal') ?? (_submitted ? previewErrors['principal'] : null),
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    textInputAction: TextInputAction.next,
                    latin: true,
                    enabled: !_saving,
                    onChanged: (_) => setState(() {}),
                  ),
                  if (scheduled) ...[
                    const SizedBox(height: 16),
                    QField(
                      controller: _down,
                      label: context.t('Down payment (optional)'),
                      errorText: shown('down_payment'),
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      textInputAction: TextInputAction.next,
                      latin: true,
                      enabled: !_saving,
                      onChanged: (_) => setState(() {}),
                    ),
                    const SizedBox(height: 20),
                    Text(context.t('Markup'), style: text.labelLarge),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        ChoiceChip(label: Text(context.t('None')), selected: _markupType == 'none', onSelected: _saving ? null : (_) => setState(() => _markupType = 'none')),
                        ChoiceChip(label: Text(context.t('Fixed amount')), selected: _markupType == 'fixed', onSelected: _saving ? null : (_) => setState(() => _markupType = 'fixed')),
                        ChoiceChip(label: Text(context.t('Percent')), selected: _markupType == 'percent', onSelected: _saving ? null : (_) => setState(() => _markupType = 'percent')),
                      ],
                    ),
                    if (_markupType != 'none') ...[
                      const SizedBox(height: 12),
                      QField(
                        controller: _markup,
                        label: _markupType == 'percent' ? context.t('Markup (% of the financed amount)') : context.t('Markup amount (:currency)', {'currency': currency}),
                        errorText: shown('markup_value'),
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        latin: true,
                        enabled: !_saving,
                        onChanged: (_) => setState(() {}),
                      ),
                    ],
                    const SizedBox(height: 20),
                    if (flexible) ...[
                      Text(context.t('How often'), style: text.labelLarge),
                      const SizedBox(height: 8),
                      _RhythmGrid(
                        selected: _frequency,
                        enabled: !_saving,
                        onSelected: _chooseFrequency,
                        options: [
                          ('daily', context.t('Day')),
                          ('weekly', context.t('Week')),
                          ('biweekly', context.t('Two weeks')),
                          ('monthly', context.t('Month')),
                          ('bimonthly', context.t('Two months')),
                          ('quarterly', context.t('Three months')),
                          ('semiannual', context.t('Six months')),
                          ('yearly', context.t('Year')),
                          (ScheduleGenerator.custom, context.t('My own dates')),
                        ],
                      ),
                      const SizedBox(height: 20),
                    ],
                    if (!custom) ...[
                      Text(context.t('Number of instalments'), style: text.labelLarge),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          IconButton.outlined(
                            tooltip: context.t('Fewer instalments'),
                            onPressed: _saving || _count <= 1 ? null : () => setState(() => _count--),
                            icon: const Icon(Icons.remove),
                          ),
                          Expanded(
                            child: Center(
                              child: Tooltip(
                                message: context.t('Type the number of instalments'),
                                child: InkWell(
                                  borderRadius: BorderRadius.circular(QistasMetrics.radiusSm),
                                  onTap: _saving ? null : _typeCount,
                                  child: Padding(
                                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                                    child: Row(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Text('$_count', style: text.titleLarge),
                                        const SizedBox(width: 6),
                                        Icon(Icons.edit_outlined, size: 16, color: c.inkMuted),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                          IconButton.outlined(
                            tooltip: context.t('More instalments'),
                            onPressed: _saving || _count >= _maxCount ? null : () => setState(() => _count++),
                            icon: const Icon(Icons.add),
                          ),
                        ],
                      ),
                      if (shown('count') != null)
                        Padding(padding: const EdgeInsets.only(top: 6), child: Text(shown('count')!, style: text.bodySmall?.copyWith(color: c.danger))),
                    ],
                    if (!flexible) ...[
                      const SizedBox(height: 16),
                      Text(context.t('Every'), style: text.labelLarge),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          ChoiceChip(label: Text(context.t('Week')), selected: _frequency == 'weekly', onSelected: _saving ? null : (_) => setState(() => _frequency = 'weekly')),
                          ChoiceChip(label: Text(context.t('Two weeks')), selected: _frequency == 'biweekly', onSelected: _saving ? null : (_) => setState(() => _frequency = 'biweekly')),
                          ChoiceChip(label: Text(context.t('Month')), selected: _frequency == 'monthly', onSelected: _saving ? null : (_) => setState(() => _frequency = 'monthly')),
                        ],
                      ),
                    ],
                    if (custom)
                      _OwnDates(
                        rows: _rows,
                        language: language,
                        enabled: !_saving,
                        status: _ownDatesStatus(context, previewErrors),
                        onPickDate: _pickRowDate,
                        onAmountChanged: () => setState(() {}),
                        onRemove: _removeRow,
                        onAdd: _addRow,
                      ),
                  ],
                  const SizedBox(height: 20),
                  Text(context.t('Contract date'), style: text.labelLarge),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(onPressed: _saving ? null : _pickStart, icon: const Icon(Icons.calendar_today_outlined, size: 18), label: Text(formatMoment(_start, language))),
                  if (scheduled && !custom) ...[
                    const SizedBox(height: 16),
                    Text(context.t('First instalment due'), style: text.labelLarge),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(onPressed: _saving ? null : _pickFirstDue, icon: const Icon(Icons.event_outlined, size: 18), label: Text(formatMoment(_firstDue, language))),
                    if (_error('first_due_date') != null)
                      Padding(padding: const EdgeInsets.only(top: 6), child: Text(_error('first_due_date')!, style: text.bodySmall?.copyWith(color: c.danger))),
                  ],
                  if (scheduled && flexible) ...[
                    const SizedBox(height: 20),
                    _GraceDays(
                      days: _grace,
                      enabled: !_saving,
                      onLess: _grace > 0 ? () => setState(() => _grace--) : null,
                      onMore: _grace < _maxGraceDays ? () => setState(() => _grace++) : null,
                    ),
                  ],
                  const SizedBox(height: 20),
                  QField(controller: _notes, label: context.t('Notes (optional)'), maxLines: 3, enabled: !_saving, maxLength: 2000),
                  if (scheduled && preview != null) ...[
                    QSectionTitle(context.t('Schedule preview')),
                    _Preview(result: preview, currency: currency, language: language),
                  ],
                  const SizedBox(height: 24),
                  QButton(label: context.t('Open contract'), icon: Icons.check, loading: _saving, onPressed: _save),
                ],
              ),
            ),
        ),
      ),
    );
  }

  /// How the shop's own dates stand: what the server said, else what is wrong with them, else that they add up.
  (String, QTone) _ownDatesStatus(BuildContext context, Map<String, String> previewErrors) {
    final server = _scheduleError();
    if (server != null) return (server, QTone.bad);
    final problem = previewErrors['custom_schedule'];
    if (problem != null) return (problem, _remaining()?.isPositive ?? false ? QTone.warn : QTone.bad);
    if (_amount(_principal) == null) return (context.t('Enter the price to check the dates against the total.'), QTone.info);

    return (context.t('The dates add up to the total.'), QTone.ok);
  }
}

/// The ways of paying, as a grid of tiles: three to a row, so all nine fit in one glance.
class _RhythmGrid extends StatelessWidget {
  const _RhythmGrid({required this.options, required this.selected, required this.onSelected, required this.enabled});

  final List<(String, String)> options;
  final String selected;
  final ValueChanged<String> onSelected;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    const gap = 8.0;

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = (constraints.maxWidth - gap * 2) / 3;

        return Wrap(
          spacing: gap,
          runSpacing: gap,
          children: [
            for (final (value, label) in options)
              Semantics(
                button: true,
                selected: value == selected,
                inMutuallyExclusiveGroup: true,
                child: SizedBox(
                  width: width,
                  child: Material(
                    color: value == selected ? c.accent.withValues(alpha: 0.16) : c.surface,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(QistasMetrics.radiusButton),
                      side: BorderSide(color: value == selected ? c.accent : c.line, width: value == selected ? 1.5 : 1),
                    ),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(QistasMetrics.radiusButton),
                      onTap: enabled ? () => onSelected(value) : null,
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(minHeight: QistasMetrics.touchTarget + 4),
                        child: Center(
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
                            child: Text(
                              label,
                              textAlign: TextAlign.center,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: text.labelLarge?.copyWith(
                                color: value == selected ? c.ink : c.inkMuted,
                                fontWeight: value == selected ? FontWeight.w700 : FontWeight.w500,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}

/// The shop's own dates: numbered lines of date and amount, a way to add one, and whether they make the total.
class _OwnDates extends StatelessWidget {
  const _OwnDates({
    required this.rows,
    required this.language,
    required this.enabled,
    required this.status,
    required this.onPickDate,
    required this.onAmountChanged,
    required this.onRemove,
    required this.onAdd,
  });

  final List<_DateRow> rows;
  final String language;
  final bool enabled;
  final (String, QTone) status;
  final ValueChanged<_DateRow> onPickDate;
  final VoidCallback onAmountChanged;
  final ValueChanged<_DateRow> onRemove;
  final VoidCallback onAdd;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final (message, tone) = status;
    final toneColor = switch (tone) {
      QTone.ok => c.positive,
      QTone.warn => c.warning,
      QTone.bad => c.danger,
      _ => c.inkMuted,
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(context.t('Payment dates'), style: text.labelLarge),
        const SizedBox(height: 2),
        Text(context.t('One line for each payment. Together they must make the total to collect.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
        const SizedBox(height: 12),
        // One heading for each column instead of a label on every line, so the date and the amount sit level.
        Row(
          children: [
            const SizedBox(width: 36),
            Expanded(flex: 5, child: Text(context.t('Due date'), style: text.labelMedium?.copyWith(color: c.inkMuted))),
            const SizedBox(width: 8),
            Expanded(flex: 4, child: Text(context.t('Amount'), style: text.labelMedium?.copyWith(color: c.inkMuted))),
            const SizedBox(width: QistasMetrics.touchTarget),
          ],
        ),
        const SizedBox(height: 6),
        for (final (index, row) in rows.indexed)
          Padding(
            key: ObjectKey(row),
            padding: const EdgeInsets.only(bottom: 10),
            child: Row(
              children: [
                Container(
                  width: 28,
                  height: 28,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(color: c.surfaceAlt, shape: BoxShape.circle),
                  child: Text('${index + 1}', style: text.labelMedium?.copyWith(color: c.inkMuted)),
                ),
                const SizedBox(width: 8),
                Expanded(
                  flex: 5,
                  child: OutlinedButton(
                    onPressed: enabled ? () => onPickDate(row) : null,
                    style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), padding: const EdgeInsets.symmetric(horizontal: 12)),
                    child: Text(
                      row.date == null ? context.t('Choose a date') : formatMoment(row.date!, language),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  flex: 4,
                  child: KeyedSubtree(
                    key: ValueKey('custom-amount-$index'),
                    child: Semantics(
                      label: '${context.t('Amount')} ${index + 1}',
                      child: TextField(
                        controller: row.amount,
                        enabled: enabled,
                        keyboardType: const TextInputType.numberWithOptions(decimal: true),
                        autocorrect: false,
                        enableSuggestions: false,
                        textDirection: TextDirection.ltr,
                        onChanged: (_) => onAmountChanged(),
                        decoration: const InputDecoration(hintText: '0.00'),
                      ),
                    ),
                  ),
                ),
                IconButton(
                  tooltip: context.t('Remove this date'),
                  onPressed: enabled && rows.length > 1 ? () => onRemove(row) : null,
                  icon: Icon(Icons.close, color: c.inkMuted),
                ),
              ],
            ),
          ),
        TextButton.icon(onPressed: enabled ? onAdd : null, icon: const Icon(Icons.add), label: Text(context.t('Add a date'))),
        const SizedBox(height: 4),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(tone == QTone.ok ? Icons.check_circle_outline : Icons.info_outline, size: 18, color: toneColor),
            const SizedBox(width: 8),
            Expanded(child: Text(message, style: text.bodyMedium?.copyWith(color: tone == QTone.info ? c.inkMuted : toneColor))),
          ],
        ),
      ],
    );
  }
}

/// Days after a due date before an instalment counts as late, chosen with a small stepper.
class _GraceDays extends StatelessWidget {
  const _GraceDays({required this.days, required this.enabled, required this.onLess, required this.onMore});

  final int days;
  final bool enabled;
  final VoidCallback? onLess;
  final VoidCallback? onMore;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(context.t('Grace days'), style: text.labelLarge),
              const SizedBox(height: 2),
              Text(context.t('Days after a due date before the instalment counts as late.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
            ],
          ),
        ),
        const SizedBox(width: 12),
        IconButton.outlined(tooltip: context.t('Fewer grace days'), onPressed: enabled ? onLess : null, icon: const Icon(Icons.remove)),
        SizedBox(width: 44, child: Text('$days', textAlign: TextAlign.center, style: text.titleMedium)),
        IconButton.outlined(tooltip: context.t('More grace days'), onPressed: enabled ? onMore : null, icon: const Icon(Icons.add)),
      ],
    );
  }
}

/// Type the number of instalments instead of tapping up to it: a long plan can run to 600.
class _CountDialog extends StatefulWidget {
  const _CountDialog({required this.initial, required this.max});

  final int initial;
  final int max;

  @override
  State<_CountDialog> createState() => _CountDialogState();
}

class _CountDialogState extends State<_CountDialog> {
  late final _controller = TextEditingController(text: '${widget.initial}');
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _done() {
    final count = int.tryParse(westernDigits(_controller.text.trim()));
    if (count == null || count < 1 || count > widget.max) {
      setState(() => _error = context.t('Choose between 1 and :max instalments.', {'max': widget.max}));

      return;
    }
    Navigator.of(context).pop(count);
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(context.t('Number of instalments')),
        content: KeyedSubtree(
          key: const ValueKey('count-input'),
          child: TextField(
            controller: _controller,
            autofocus: true,
            keyboardType: TextInputType.number,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _done(),
            decoration: InputDecoration(errorText: _error, helperText: context.t('From 1 to :max.', {'max': widget.max})),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.t('Cancel'))),
          FilledButton(onPressed: _done, child: Text(context.t('Done'))),
        ],
      );
}

/// The schedule as it would be saved: the totals, then the instalments (the middle ones folded away when many).
class _Preview extends StatelessWidget {
  const _Preview({required this.result, required this.currency, required this.language});

  final ScheduleResult result;
  final String currency;
  final String language;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final rows = result.installments;
    final folded = rows.length > 8;
    final shown = folded ? [...rows.take(4), ...rows.skip(rows.length - 2)] : rows;

    Widget line(String label, Widget value) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [Flexible(child: Text(label, style: text.bodyMedium?.copyWith(color: c.inkMuted))), const SizedBox(width: 12), value]),
        );

    return QCard(
      child: Column(
        children: [
          line(context.t('Financed'), MoneyText(result.financed, currency, style: text.bodyLarge)),
          if (result.markup.isPositive) line(context.t('Markup'), MoneyText(result.markup, currency, style: text.bodyLarge)),
          line(context.t('Total to collect'), MoneyText(result.total, currency, style: text.titleSmall)),
          line(context.t('Last payment'), Text(formatDay(rows.last.dueDate, language), style: text.bodyLarge)),
          const Divider(height: 24),
          for (final (index, row) in shown.indexed) ...[
            if (folded && index == 4)
              Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text(context.t('… :count more instalments', {'count': rows.length - 6}), style: text.bodySmall?.copyWith(color: c.inkMuted))),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 5),
              child: Row(
                children: [
                  SizedBox(width: 28, child: Text('${row.number}', style: text.labelLarge?.copyWith(color: c.inkMuted))),
                  Expanded(child: Text(formatDay(row.dueDate, language), style: text.bodyMedium)),
                  MoneyText(row.amount, currency, style: text.bodyMedium),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Pick who a contract is for: a searchable list of customers.
class _CustomerPicker extends ConsumerStatefulWidget {
  const _CustomerPicker();

  @override
  ConsumerState<_CustomerPicker> createState() => _CustomerPickerState();
}

class _CustomerPickerState extends ConsumerState<_CustomerPicker> {
  final _search = TextEditingController();
  List<Customer> _items = const [];
  bool _loading = true;
  ApiException? _error;
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load('');
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load(String query) async {
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final page = await ref.read(apiProvider).customers(query: query);
      if (mounted && request == _request) {
        setState(() {
          _items = page.items;
          _loading = false;
        });
      }
    } on ApiException catch (e) {
      if (mounted && request == _request) {
        setState(() {
          _error = e;
          _loading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.75,
        child: ContentColumn(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(context.t('Choose a customer'), style: text.titleLarge),
              const SizedBox(height: 12),
              TextField(
                controller: _search,
                autofocus: true,
                textInputAction: TextInputAction.search,
                onChanged: _load,
                decoration: InputDecoration(hintText: context.t('Search by name, phone or email'), prefixIcon: const Icon(Icons.search)),
              ),
              const SizedBox(height: 8),
              Expanded(
                child: _loading
                    ? const QSkeletonList(rows: 4)
                    : _error != null
                        ? QErrorView(message: errorMessage(context, _error!), retryLabel: context.t('Try again'), onRetry: () => _load(_search.text))
                        : _items.isEmpty
                            ? QEmpty(
                                icon: Icons.people_outline,
                                title: _search.text.isEmpty ? context.t('No customers yet') : context.t('No customers match “:term”', {'term': _search.text}),
                                message: context.t('Add the customer first, then open their contract.'),
                                action: QButton(
                                  label: context.t('Add customer'),
                                  expand: false,
                                  onPressed: () {
                                    Navigator.of(context).pop();
                                    context.pushReplacement('/customers/new');
                                  },
                                ),
                              )
                            : ListView.separated(
                                itemCount: _items.length,
                                separatorBuilder: (_, _) => const Divider(height: 1),
                                itemBuilder: (context, index) {
                                  final customer = _items[index];

                                  return ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    title: Text(customer.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                                    subtitle: Directionality(textDirection: TextDirection.ltr, child: Text(customer.phone, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                                    onTap: () => Navigator.of(context).pop(customer),
                                  );
                                },
                              ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
