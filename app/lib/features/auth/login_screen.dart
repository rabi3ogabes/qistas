import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import 'auth_shell.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();

  bool _busy = false;
  bool _needsCode = false;
  bool _useRecovery = false;
  bool _showPassword = false;
  String? _banner;
  Map<String, String> _fieldErrors = const {};

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    setState(() {
      _banner = null;
      _fieldErrors = const {};
    });

    final errors = <String, String>{
      if (_email.text.trim().isEmpty) 'email': context.t('Enter your e-mail address.'),
      if (_password.text.isEmpty) 'password': context.t('Enter your password.'),
    };
    if (errors.isNotEmpty) {
      setState(() => _fieldErrors = errors);

      return;
    }

    setState(() => _busy = true);
    try {
      final code = _code.text.trim();
      await ref.read(authProvider.notifier).signIn(
            email: _email.text,
            password: _password.text,
            code: _useRecovery ? null : code,
            recoveryCode: _useRecovery ? code : null,
          );
      // The router moves a signed-in person on by itself.
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        switch (e.code) {
          case 'two_factor_required':
            _needsCode = true;
            _banner = null;
          case 'invalid_two_factor_code':
            _fieldErrors = {'code': e.message};
          default:
            if (e.isValidation) {
              _fieldErrors = {for (final entry in e.fields.entries) entry.key: entry.value.first};
            } else {
              _banner = errorMessage(context, e);
            }
        }
      });

      return;
    }

    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final sessionEnded = ref.watch(authProvider).valueOrNull?.sessionEnded ?? false;

    return AuthShell(
      title: context.t('Welcome back'),
      subtitle: context.t('Sign in to manage your customers and instalments.'),
      footer: Wrap(
        alignment: WrapAlignment.center,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(context.t('New to :app?', {'app': 'Qistas'}), style: Theme.of(context).textTheme.bodyMedium),
          TextButton(onPressed: () => context.go('/register'), child: Text(context.t('Create a free account'))),
        ],
      ),
      children: [
        if (sessionEnded && _banner == null) ...[QNotice(context.t('Your session ended. Please sign in again.'), tone: QTone.info, icon: Icons.info_outline), const SizedBox(height: 16)],
        if (_banner != null) ...[QNotice(_banner!, icon: Icons.error_outline), const SizedBox(height: 16)],
        AutofillGroup(
          child: Column(
            children: [
              QField(
                controller: _email,
                label: context.t('Email'),
                latin: true,
                keyboardType: TextInputType.emailAddress,
                textInputAction: TextInputAction.next,
                autofillHints: const [AutofillHints.username, AutofillHints.email],
                errorText: _fieldErrors['email'],
                enabled: !_busy && !_needsCode,
              ),
              const SizedBox(height: 16),
              QField(
                controller: _password,
                label: context.t('Password'),
                latin: true,
                obscure: !_showPassword,
                textInputAction: _needsCode ? TextInputAction.next : TextInputAction.done,
                autofillHints: const [AutofillHints.password],
                errorText: _fieldErrors['password'],
                enabled: !_busy && !_needsCode,
                onSubmitted: (_) => _needsCode ? null : _submit(),
                suffix: IconButton(
                  tooltip: _showPassword ? context.t('Hide password') : context.t('Show password'),
                  icon: Icon(_showPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                  onPressed: () => setState(() => _showPassword = !_showPassword),
                ),
              ),
              if (_needsCode) ...[
                const SizedBox(height: 16),
                QNotice(context.t('Enter the code from your authenticator app.'), tone: QTone.info, icon: Icons.shield_outlined),
                const SizedBox(height: 12),
                QField(
                  controller: _code,
                  label: _useRecovery ? context.t('Recovery code') : context.t('Authentication code'),
                  latin: true,
                  autofocus: true,
                  keyboardType: _useRecovery ? TextInputType.text : TextInputType.number,
                  textInputAction: TextInputAction.done,
                  errorText: _fieldErrors['code'],
                  enabled: !_busy,
                  onSubmitted: (_) => _submit(),
                ),
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton(
                    onPressed: _busy ? null : () => setState(() {
                      _useRecovery = !_useRecovery;
                      _code.clear();
                      _fieldErrors = const {};
                    }),
                    child: Text(_useRecovery ? context.t('Use an authenticator code instead') : context.t('Use a recovery code instead')),
                  ),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 24),
        QButton(label: context.t('Sign in'), loading: _busy, onPressed: _submit),
      ],
    );
  }
}
