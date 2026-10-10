import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/investors.dart';
import '../billing/upgrade_sheet.dart';
import 'investors_screen.dart';

/// Adds a partner, or changes an investor: name, commission, registration, notes, and archiving a partner who funds no
/// new contracts. The business's own capital is never archived and pays no commission, so it has neither field.
class InvestorFormScreen extends ConsumerStatefulWidget {
  const InvestorFormScreen({super.key, this.id});

  /// The investor being changed; null to add one.
  final String? id;

  @override
  ConsumerState<InvestorFormScreen> createState() => _InvestorFormScreenState();
}

class _InvestorFormScreenState extends ConsumerState<InvestorFormScreen> {
  final _name = TextEditingController();
  final _opening = TextEditingController();
  final _commission = TextEditingController();
  final _registration = TextEditingController();
  final _notes = TextEditingController();
  Investor? _investor;
  bool _archived = false;
  bool _loading = false;
  bool _saving = false;
  Map<String, List<String>> _fields = const {};
  String? _problem;

  bool get _adding => widget.id == null;

  @override
  void initState() {
    super.initState();
    if (!_adding) _load();
  }

  @override
  void dispose() {
    for (final controller in [_name, _opening, _commission, _registration, _notes]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final investor = (await ref.read(investorDetailProvider(widget.id!).future)).investor;
      if (!mounted) return;
      setState(() {
        _investor = investor;
        _name.text = investor.name;
        _commission.text = investor.paysCommission ? investor.commissionShort : '';
        _registration.text = investor.commercialRegistration ?? '';
        _notes.text = investor.notes ?? '';
        _archived = investor.archived;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _problem = errorMessage(context, e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      setState(() => _fields = {'name': [context.t('Enter a name.')]});
      return;
    }
    final opening = _opening.text.trim().isEmpty ? null : Money.parseTyped(_opening.text);
    if (_opening.text.trim().isNotEmpty && (opening == null || opening.isNegative)) {
      setState(() => _fields = {'opening_capital': [context.t('Enter an amount with at most two decimals.')]});
      return;
    }

    setState(() {
      _saving = true;
      _fields = const {};
      _problem = null;
    });
    final api = ref.read(apiProvider);
    try {
      if (_adding) {
        final investor = await api.createInvestor(
          name: _name.text,
          openingCapital: opening?.toDecimalString(),
          commissionPercent: _commission.text,
          commercialRegistration: _registration.text,
          notes: _notes.text,
        );
        ref.invalidate(investorsProvider);
        if (mounted) context.pushReplacement('/investors/${investor.id}');
      } else {
        final main = _investor?.isMain ?? false;
        await api.updateInvestor(
          widget.id!,
          name: _name.text,
          commissionPercent: main ? null : (_commission.text.trim().isEmpty ? '0' : _commission.text),
          commercialRegistration: _registration.text,
          notes: _notes.text,
          archived: main ? null : _archived,
        );
        ref.invalidate(investorsProvider);
        ref.invalidate(investorDetailProvider(widget.id!));
        if (mounted) context.pop();
      }
    } on UpgradeRequired catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      await showUpgradeSheet(context, e);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _fields = e.fields;
        _problem = e.fields.isEmpty ? errorMessage(context, e) : context.t('Check the highlighted fields and try again.');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final main = _investor?.isMain ?? false;
    final currency = ref.watch(accountProvider)?.currency ?? '';

    return Scaffold(
      appBar: AppBar(title: Text(_adding ? context.t('Add an investor') : context.t('Edit details'))),
      body: SafeArea(
        child: _loading
            ? const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4))
            : SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
                child: ContentColumn(
                  padding: EdgeInsets.zero,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 16)],
                      if (_adding) ...[
                        Text(
                          context.t('A partner who funds some of your contracts. Their money in, money out and profit are kept apart from the business’s own.'),
                          style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: Theme.of(context).hintColor),
                        ),
                        const SizedBox(height: 20),
                      ],
                      QField(controller: _name, label: context.t('Name'), errorText: _fields['name']?.firstOrNull, enabled: !_saving, maxLength: 120, textInputAction: TextInputAction.next),
                      if (_adding) ...[
                        const SizedBox(height: 16),
                        QField(
                          controller: _opening,
                          label: context.t('Money they start with (optional)'),
                          helper: context.t('In :currency. Recorded as their first deposit.', {'currency': currency}),
                          errorText: _fields['opening_capital']?.firstOrNull,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          latin: true,
                          enabled: !_saving,
                        ),
                      ],
                      if (!main) ...[
                        const SizedBox(height: 16),
                        QField(
                          controller: _commission,
                          label: context.t('Commission (optional)'),
                          helper: context.t('The share of their profit that goes to the business, as a percent.'),
                          errorText: _fields['commission_percent']?.firstOrNull,
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          latin: true,
                          enabled: !_saving,
                          suffix: const Padding(padding: EdgeInsets.all(14), child: Text('%')),
                        ),
                      ],
                      const SizedBox(height: 16),
                      QField(
                        controller: _registration,
                        label: context.t('Commercial registration (optional)'),
                        errorText: _fields['commercial_registration']?.firstOrNull,
                        latin: true,
                        enabled: !_saving,
                        maxLength: 60,
                      ),
                      const SizedBox(height: 16),
                      QField(controller: _notes, label: context.t('Notes (optional)'), maxLines: 3, maxLength: 2000, enabled: !_saving),
                      if (!_adding && !main) ...[
                        const SizedBox(height: 8),
                        SwitchListTile(
                          contentPadding: EdgeInsets.zero,
                          value: _archived,
                          onChanged: _saving ? null : (value) => setState(() => _archived = value),
                          title: Text(context.t('Archived')),
                          subtitle: Text(context.t('Funds no new contracts. Everything they funded stays theirs.')),
                        ),
                      ],
                      const SizedBox(height: 24),
                      QButton(label: _adding ? context.t('Add investor') : context.t('Save'), icon: Icons.check, loading: _saving, onPressed: _save),
                    ],
                  ),
                ),
              ),
      ),
    );
  }
}
