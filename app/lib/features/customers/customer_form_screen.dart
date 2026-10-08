import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../../data/qistas_api.dart';
import '../billing/upgrade_sheet.dart';
import 'customer_detail_screen.dart';
import 'customers_screen.dart';

/// Adds a customer, or changes one when [id] is given.
class CustomerFormScreen extends ConsumerStatefulWidget {
  const CustomerFormScreen({super.key, this.id});

  final String? id;

  @override
  ConsumerState<CustomerFormScreen> createState() => _CustomerFormScreenState();
}

class _CustomerFormScreenState extends ConsumerState<CustomerFormScreen> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _phoneSecondary = TextEditingController();
  final _email = TextEditingController();
  final _nationalId = TextEditingController();
  final _address = TextEditingController();
  final _notes = TextEditingController();

  bool get _editing => widget.id != null;

  Customer? _loaded;
  ApiException? _loadError;
  bool _loading = false;
  bool _saving = false;
  bool _removeNationalId = false;
  Map<String, List<String>> _fields = const {};
  String? _problem;

  @override
  void initState() {
    super.initState();
    if (_editing) _load();
  }

  @override
  void dispose() {
    for (final controller in [_name, _phone, _phoneSecondary, _email, _nationalId, _address, _notes]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _loadError = null;
    });

    try {
      final customer = await ref.read(apiProvider).customer(widget.id!);
      if (!mounted) return;
      setState(() {
        _loaded = customer;
        _loading = false;
        _name.text = customer.name;
        _phone.text = customer.phone;
        _phoneSecondary.text = customer.phoneSecondary ?? '';
        _email.text = customer.email ?? '';
        _address.text = customer.address ?? '';
        _notes.text = customer.notes ?? '';
      });
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _loadError = e;
          _loading = false;
        });
      }
    }
  }

  String? _error(String field) => _fields[field]?.firstOrNull;

  Future<void> _save() async {
    if (_saving) return;

    final name = _name.text.trim();
    final phone = _phone.text.trim();
    final local = <String, List<String>>{
      if (name.isEmpty) 'name': [context.t('Enter the customer’s name.')],
      if (phone.isEmpty) 'phone': [context.t('Enter a phone number we can reach them on.')],
    };
    if (local.isNotEmpty) {
      setState(() {
        _fields = local;
        _problem = null;
      });

      return;
    }

    setState(() {
      _saving = true;
      _fields = const {};
      _problem = null;
    });

    final form = CustomerForm(
      name: name,
      phone: phone,
      phoneSecondary: _phoneSecondary.text,
      email: _email.text,
      nationalId: _nationalId.text,
      address: _address.text,
      notes: _notes.text,
      removeNationalId: _removeNationalId,
    );

    try {
      final api = ref.read(apiProvider);
      final saved = _editing ? await api.updateCustomer(widget.id!, form) : await api.createCustomer(form);
      unawaited(ref.read(customersListProvider.notifier).refresh());
      if (_editing) ref.invalidate(customerProvider(widget.id!));
      await ref.read(authProvider.notifier).refresh();

      if (!mounted) return;
      if (_editing) {
        context.pop();
      } else {
        context.pushReplacement('/customers/${saved.id}');
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
        _problem = e.isValidation && e.fields.isNotEmpty ? null : errorMessage(context, e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final title = _editing ? context.t('Edit customer') : context.t('Add customer');

    if (_loading) {
      return Scaffold(appBar: AppBar(title: Text(title)), body: const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 5)));
    }
    if (_loadError != null) {
      return Scaffold(
        appBar: AppBar(title: Text(title)),
        body: QErrorView(message: errorMessage(context, _loadError!), retryLabel: context.t('Try again'), onRetry: _load),
      );
    }

    final hasId = _loaded?.nationalId != null;

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          child: ContentColumn(
              padding: EdgeInsets.zero,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 16)],
                  QField(
                    controller: _name,
                    label: context.t('Full name'),
                    errorText: _error('name'),
                    textInputAction: TextInputAction.next,
                    autofillHints: const [AutofillHints.name],
                    autofocus: !_editing,
                    enabled: !_saving,
                  ),
                  const SizedBox(height: 16),
                  QField(
                    controller: _phone,
                    label: context.t('Phone'),
                    errorText: _error('phone'),
                    keyboardType: TextInputType.phone,
                    textInputAction: TextInputAction.next,
                    autofillHints: const [AutofillHints.telephoneNumber],
                    latin: true,
                    enabled: !_saving,
                  ),
                  const SizedBox(height: 16),
                  QField(
                    controller: _phoneSecondary,
                    label: context.t('Second phone (optional)'),
                    errorText: _error('phone_secondary'),
                    keyboardType: TextInputType.phone,
                    textInputAction: TextInputAction.next,
                    latin: true,
                    enabled: !_saving,
                  ),
                  const SizedBox(height: 16),
                  QField(
                    controller: _email,
                    label: context.t('Email (optional)'),
                    errorText: _error('email'),
                    keyboardType: TextInputType.emailAddress,
                    textInputAction: TextInputAction.next,
                    autofillHints: const [AutofillHints.email],
                    latin: true,
                    enabled: !_saving,
                  ),
                  const SizedBox(height: 16),
                  QField(
                    controller: _nationalId,
                    label: context.t('National ID (optional)'),
                    helper: hasId && !_removeNationalId
                        ? context.t('On file: :masked. Leave this empty to keep it.', {'masked': _loaded!.nationalId})
                        : context.t('Stored encrypted. Only the last three digits are ever shown.'),
                    errorText: _error('national_id'),
                    textInputAction: TextInputAction.next,
                    latin: true,
                    enabled: !_saving && !_removeNationalId,
                  ),
                  if (hasId)
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      controlAffinity: ListTileControlAffinity.leading,
                      value: _removeNationalId,
                      onChanged: _saving ? null : (value) => setState(() => _removeNationalId = value ?? false),
                      title: Text(context.t('Remove the ID on file'), style: text.bodyMedium),
                    ),
                  const SizedBox(height: 16),
                  QField(
                    controller: _address,
                    label: context.t('Address (optional)'),
                    errorText: _error('address'),
                    textInputAction: TextInputAction.next,
                    maxLines: 2,
                    enabled: !_saving,
                  ),
                  const SizedBox(height: 16),
                  QField(
                    controller: _notes,
                    label: context.t('Notes (optional)'),
                    helper: context.t('Only your team sees these.'),
                    errorText: _error('notes'),
                    maxLines: 3,
                    enabled: !_saving,
                  ),
                  const SizedBox(height: 24),
                  QButton(label: _editing ? context.t('Save changes') : context.t('Add customer'), loading: _saving, onPressed: _save),
                  if (!_editing) ...[
                    const SizedBox(height: 8),
                    Text(context.t('You can open a contract for them next.'), style: text.bodySmall?.copyWith(color: c.inkMuted), textAlign: TextAlign.center),
                  ],
                ],
              ),
            ),
        ),
      ),
    );
  }
}
