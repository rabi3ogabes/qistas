import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/design/widgets.dart';
import '../../core/device_hint.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import 'auth_shell.dart';

/// Five things to fill in: name, e-mail, password, the password again and the terms. The business, its country and its
/// currency are asked later, in the app, once there is something to set up.
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();

  bool _terms = false;
  bool _busy = false;
  bool _showPassword = false;
  String? _banner;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    for (final c in [_name, _email, _password, _confirmation]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _open(String path) => launchUrl(Uri.parse('${AppConfig.webUrl}$path'), mode: LaunchMode.externalApplication);

  Future<void> _submit() async {
    if (_busy) return;
    final errors = <String, String>{
      if (_name.text.trim().isEmpty) 'name': context.t('Enter your name.'),
      if (_email.text.trim().isEmpty) 'email': context.t('Enter your e-mail address.'),
      if (_password.text.isEmpty) 'password': context.t('Choose a password.'),
      if (_password.text.isNotEmpty && _password.text != _confirmation.text) 'password_confirmation': context.t('The two passwords are not the same.'),
      if (!_terms) 'terms': context.t('Please accept the terms to continue.'),
    };
    setState(() {
      _banner = null;
      _fieldErrors = errors;
    });
    if (errors.isNotEmpty) return;

    // Read before the first await: the screen's size is asked of the context, which must not be used after one.
    final device = deviceHint(shortestSide: MediaQuery.sizeOf(context).shortestSide);

    setState(() => _busy = true);
    try {
      await ref.read(authProvider.notifier).register(name: _name.text, email: _email.text, password: _password.text, device: device);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        if (e.isValidation) {
          _fieldErrors = {for (final entry in e.fields.entries) entry.key: entry.value.first};
          // A complaint about something this form does not show would otherwise go unseen.
          if (_fieldErrors.keys.every((k) => !const {'name', 'email', 'password', 'password_confirmation', 'terms'}.contains(k))) {
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
              QField(controller: _email, label: context.t('Email'), latin: true, keyboardType: TextInputType.emailAddress, textInputAction: TextInputAction.next, autofillHints: const [AutofillHints.email], errorText: _fieldErrors['email'], enabled: !_busy),
              const SizedBox(height: 16),
              QField(
                controller: _password,
                label: context.t('Password'),
                latin: true,
                obscure: !_showPassword,
                textInputAction: TextInputAction.next,
                autofillHints: const [AutofillHints.newPassword],
                helper: context.t('At least 10 characters, with upper and lower case letters, a number and a symbol.'),
                errorText: _fieldErrors['password'],
                enabled: !_busy,
                suffix: IconButton(
                  tooltip: _showPassword ? context.t('Hide password') : context.t('Show password'),
                  icon: Icon(_showPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                  onPressed: () => setState(() => _showPassword = !_showPassword),
                ),
              ),
              const SizedBox(height: 16),
              QField(
                controller: _confirmation,
                label: context.t('Confirm password'),
                latin: true,
                obscure: !_showPassword,
                textInputAction: TextInputAction.done,
                autofillHints: const [AutofillHints.newPassword],
                errorText: _fieldErrors['password_confirmation'],
                enabled: !_busy,
                onSubmitted: (_) => _submit(),
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
