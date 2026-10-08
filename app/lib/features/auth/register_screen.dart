import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import 'auth_shell.dart';
import 'countries.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _name = TextEditingController();
  final _business = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();

  String _country = 'SA';
  bool _terms = false;
  bool _busy = false;
  bool _showPassword = false;
  String? _banner;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    for (final c in [_name, _business, _email, _password]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _open(String path) => launchUrl(Uri.parse('${AppConfig.webUrl}$path'), mode: LaunchMode.externalApplication);

  Future<void> _submit() async {
    if (_busy) return;
    final errors = <String, String>{
      if (_name.text.trim().isEmpty) 'name': context.t('Enter your name.'),
      if (_business.text.trim().isEmpty) 'business_name': context.t('Enter your business name.'),
      if (_email.text.trim().isEmpty) 'email': context.t('Enter your e-mail address.'),
      if (_password.text.isEmpty) 'password': context.t('Choose a password.'),
      if (!_terms) 'terms': context.t('Please accept the terms to continue.'),
    };
    setState(() {
      _banner = null;
      _fieldErrors = errors;
    });
    if (errors.isNotEmpty) return;

    setState(() => _busy = true);
    try {
      await ref.read(authProvider.notifier).register(
            name: _name.text,
            email: _email.text,
            password: _password.text,
            businessName: _business.text,
            country: _country,
          );
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        if (e.isValidation) {
          _fieldErrors = {for (final entry in e.fields.entries) entry.key: entry.value.first};
          if (_fieldErrors.keys.every((k) => !const {'name', 'email', 'password', 'business_name', 'country', 'terms'}.contains(k))) {
            _banner = e.message;
          }
        } else {
          _banner = errorMessage(context, e);
        }
      });

      return;
    }

    if (mounted) setState(() => _busy = false);
  }

  Future<void> _pickCountry() async {
    final chosen = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.8,
        builder: (context, scroll) => ListView(
          controller: scroll,
          children: [
            for (final code in countryCodes)
              ListTile(
                title: Text(countryName(context, code)),
                trailing: code == _country ? const Icon(Icons.check) : null,
                onTap: () => Navigator.of(context).pop(code),
              ),
          ],
        ),
      ),
    );
    if (chosen != null) setState(() => _country = chosen);
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return AuthShell(
      title: context.t('Create your free account'),
      subtitle: context.t('Free for your first customers. No card needed.'),
      footer: Wrap(
        alignment: WrapAlignment.center,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(context.t('Already have an account?'), style: text.bodyMedium),
          TextButton(onPressed: () => context.go('/login'), child: Text(context.t('Sign in'))),
        ],
      ),
      children: [
        if (_banner != null) ...[QNotice(_banner!, icon: Icons.error_outline), const SizedBox(height: 16)],
        AutofillGroup(
          child: Column(
            children: [
              QField(controller: _name, label: context.t('Your name'), errorText: _fieldErrors['name'], textInputAction: TextInputAction.next, autofillHints: const [AutofillHints.name], enabled: !_busy),
              const SizedBox(height: 16),
              QField(controller: _business, label: context.t('Business name'), errorText: _fieldErrors['business_name'], textInputAction: TextInputAction.next, autofillHints: const [AutofillHints.organizationName], enabled: !_busy),
              const SizedBox(height: 16),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(padding: const EdgeInsets.only(bottom: 6), child: Text(context.t('Country'), style: text.labelLarge)),
                  OutlinedButton(
                    onPressed: _busy ? null : _pickCountry,
                    style: OutlinedButton.styleFrom(alignment: AlignmentDirectional.centerStart),
                    child: Row(children: [Expanded(child: Text(countryName(context, _country))), const Icon(Icons.arrow_drop_down)]),
                  ),
                  if (_fieldErrors['country'] != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(_fieldErrors['country']!, style: text.bodySmall?.copyWith(color: Theme.of(context).colorScheme.error))),
                ],
              ),
              const SizedBox(height: 16),
              QField(controller: _email, label: context.t('Email'), latin: true, keyboardType: TextInputType.emailAddress, textInputAction: TextInputAction.next, autofillHints: const [AutofillHints.email], errorText: _fieldErrors['email'], enabled: !_busy),
              const SizedBox(height: 16),
              QField(
                controller: _password,
                label: context.t('Password'),
                latin: true,
                obscure: !_showPassword,
                textInputAction: TextInputAction.done,
                autofillHints: const [AutofillHints.newPassword],
                helper: context.t('At least 10 characters, with upper and lower case letters, a number and a symbol.'),
                errorText: _fieldErrors['password'],
                enabled: !_busy,
                onSubmitted: (_) => _submit(),
                suffix: IconButton(
                  tooltip: _showPassword ? context.t('Hide password') : context.t('Show password'),
                  icon: Icon(_showPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                  onPressed: () => setState(() => _showPassword = !_showPassword),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Checkbox(value: _terms, onChanged: _busy ? null : (value) => setState(() => _terms = value ?? false)),
            Expanded(child: Padding(padding: const EdgeInsets.only(top: 12), child: Text(context.t('I accept the terms and the privacy policy.')))),
          ],
        ),
        Wrap(
          children: [
            TextButton(onPressed: () => _open('/terms'), child: Text(context.t('Read the terms'))),
            TextButton(onPressed: () => _open('/privacy'), child: Text(context.t('Read the privacy policy'))),
          ],
        ),
        if (_fieldErrors['terms'] != null) Text(_fieldErrors['terms']!, style: text.bodySmall?.copyWith(color: Theme.of(context).colorScheme.error)),
        const SizedBox(height: 20),
        QButton(label: context.t('Start free'), loading: _busy, onPressed: _submit),
      ],
    );
  }
}
