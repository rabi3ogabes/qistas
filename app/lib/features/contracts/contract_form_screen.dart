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

class _ContractFormScreenState extends ConsumerState<ContractFormScreen> {
  static final _generator = ScheduleGenerator();

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

  @override
  void initState() {
    super.initState();
    if (widget.customerId != null) _loadCustomer(widget.customerId!);
  }

  @override
  void dispose() {
    for (final controller in [_principal, _down, _markup, _notes]) {
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

  ScheduleRequest? _request() {
    final principal = _amount(_principal);
    if (principal == null) return null;

    return ScheduleRequest(
      principal: principal,
      downPayment: _down.text.trim().isEmpty ? '0' : _amount(_down) ?? _down.text.trim(),
      markupType: _markupType,
      markupValue: _markupType == 'none' || _markup.text.trim().isEmpty ? '0' : _amount(_markup) ?? _markup.text.trim(),
      count: _count,
      frequency: _frequency,
      firstDueDate: isoDay(_firstDue),
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
      return (null, {e.field: _scheduleMessage(context, e.field)});
    }
  }

  String _scheduleMessage(BuildContext context, String field) => switch (field) {
        'principal' => context.t('Enter a price greater than zero.'),
        'down_payment' => context.t('The down payment must be less than the price.'),
        'markup_value' => context.t('Enter a markup of zero or more.'),
        'count' => context.t('Choose between 1 and :max instalments.', {'max': ScheduleGenerator.maxCount}),
        _ => context.t('Check this date.'),
      };

  // -------------------------------------------------------------------------- inputs

  Future<DateTime?> _pick(DateTime initial, {required DateTime first, required DateTime last}) => showDatePicker(
        context: context,
        initialDate: initial.isBefore(first) ? first : initial,
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

  // --------------------------------------------------------------------------- save

  String? _error(String field) => _fields[field]?.firstOrNull;

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
    final scheduled = _type == 'scheduled';
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
                    Text(context.t('Number of instalments'), style: text.labelLarge),
                    const SizedBox(height: 4),
                    Row(
                      children: [
                        IconButton.outlined(
                          tooltip: context.t('Fewer instalments'),
                          onPressed: _saving || _count <= 1 ? null : () => setState(() => _count--),
                          icon: const Icon(Icons.remove),
                        ),
                        Expanded(child: Center(child: Text('$_count', style: text.titleLarge))),
                        IconButton.outlined(
                          tooltip: context.t('More instalments'),
                          onPressed: _saving || _count >= ScheduleGenerator.maxCount ? null : () => setState(() => _count++),
                          icon: const Icon(Icons.add),
                        ),
                      ],
                    ),
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
                  const SizedBox(height: 20),
                  Text(context.t('Contract date'), style: text.labelLarge),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(onPressed: _saving ? null : _pickStart, icon: const Icon(Icons.calendar_today_outlined, size: 18), label: Text(formatMoment(_start, language))),
                  if (scheduled) ...[
                    const SizedBox(height: 16),
                    Text(context.t('First instalment due'), style: text.labelLarge),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(onPressed: _saving ? null : _pickFirstDue, icon: const Icon(Icons.event_outlined, size: 18), label: Text(formatMoment(_firstDue, language))),
                    if (_error('first_due_date') != null)
                      Padding(padding: const EdgeInsets.only(top: 6), child: Text(_error('first_due_date')!, style: text.bodySmall?.copyWith(color: c.danger))),
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
