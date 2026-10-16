import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:intl/intl.dart' show DateFormat;

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/languages.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../contracts/paperwork_section.dart' show photoPickerProvider;

/// A person's face: their profile photo when they have one, else their initials, in a gold ring.
class ProfileAvatar extends StatelessWidget {
  const ProfileAvatar({super.key, required this.account, this.size = 64});

  final Account account;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final bytes = account.photoBytes;

    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [c.heroTo, c.heroFrom]),
        border: Border.all(color: c.accent, width: 2),
      ),
      child: bytes != null
          ? Image.memory(bytes, key: const ValueKey('profile-photo-image'), width: size, height: size, fit: BoxFit.cover, gaplessPlayback: true)
          : Text(
              InitialsAvatar.initials(account.name),
              textScaler: TextScaler.noScaling,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(color: c.onPrimary, fontWeight: FontWeight.w700, height: 1, fontSize: size * 0.34),
            ),
    );
  }
}

/// "Your profile": your photo, name, e-mail, phone and language, and your password, the same as on the web. What the
/// server says is wrong shows under its field; a saved change shows at once, everywhere in the app.
class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _current = TextEditingController();
  final _password = TextEditingController();
  final _confirmation = TextEditingController();
  String _locale = 'en';

  bool _savingDetails = false;
  bool _savingPassword = false;
  bool _savingPhoto = false;
  bool _showPasswords = false;
  Map<String, List<String>> _detailErrors = const {};
  Map<String, List<String>> _passwordErrors = const {};
  String? _problem;

  @override
  void initState() {
    super.initState();
    final account = ref.read(accountProvider);
    if (account != null) _fill(account);
  }

  void _fill(Account account) {
    _name.text = account.name;
    _email.text = account.email;
    _phone.text = account.phone ?? '';
    _locale = account.locale;
  }

  @override
  void dispose() {
    for (final controller in [_name, _email, _phone, _current, _password, _confirmation]) {
      controller.dispose();
    }
    super.dispose();
  }

  void _say(String message) => ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));

  Future<void> _saveDetails() async {
    if (_savingDetails) return;
    final local = <String, List<String>>{
      if (_name.text.trim().isEmpty) 'name': [context.t('Enter your name.')],
      if (_email.text.trim().isEmpty) 'email': [context.t('Enter your e-mail.')],
    };
    if (local.isNotEmpty) {
      setState(() => _detailErrors = local);

      return;
    }

    final before = ref.read(accountProvider);
    setState(() {
      _savingDetails = true;
      _detailErrors = const {};
      _problem = null;
    });
    try {
      final account = await ref.read(apiProvider).updateProfile(name: _name.text, email: _email.text, phone: _phone.text, locale: _locale);
      await ref.read(authProvider.notifier).accountChanged(account);
      if (account.locale != ref.read(localeProvider)) await ref.read(localeProvider.notifier).choose(account.locale);
      if (!mounted) return;
      setState(() {
        _savingDetails = false;
        _fill(account);
      });
      _say(before != null && before.email != account.email
          ? context.t('Your details are saved. We sent a link to your new e-mail to confirm it.')
          : context.t('Your details are saved.'));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _savingDetails = false;
        _detailErrors = e.fields;
        _problem = e.isValidation && e.fields.isNotEmpty ? null : errorMessage(context, e);
      });
    }
  }

  Future<void> _savePassword() async {
    if (_savingPassword) return;
    final local = <String, List<String>>{
      if (_current.text.isEmpty) 'current_password': [context.t('Enter your current password.')],
      if (_password.text.isEmpty) 'password': [context.t('Choose a new password.')],
      if (_password.text.isNotEmpty && _password.text != _confirmation.text) 'password_confirmation': [context.t('The two new passwords are not the same.')],
    };
    if (local.isNotEmpty) {
      setState(() => _passwordErrors = local);

      return;
    }

    setState(() {
      _savingPassword = true;
      _passwordErrors = const {};
      _problem = null;
    });
    try {
      await ref.read(apiProvider).changePassword(current: _current.text, password: _password.text, confirmation: _confirmation.text);
      if (!mounted) return;
      setState(() {
        _savingPassword = false;
        for (final controller in [_current, _password, _confirmation]) {
          controller.clear();
        }
      });
      _say(context.t('Your password is changed.'));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _savingPassword = false;
        _passwordErrors = e.fields;
        _problem = e.isValidation && e.fields.isNotEmpty ? null : errorMessage(context, e);
      });
    }
  }

  Future<void> _changePhoto() async {
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(leading: const Icon(Icons.photo_camera_outlined), title: Text(context.t('Take a photo')), onTap: () => Navigator.of(context).pop(ImageSource.camera)),
            ListTile(leading: const Icon(Icons.photo_library_outlined), title: Text(context.t('Choose from your photos')), onTap: () => Navigator.of(context).pop(ImageSource.gallery)),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (source == null || !mounted) return;

    final picked = await ref.read(photoPickerProvider)(source);
    if (picked == null || !mounted) return;
    await _photo(() => ref.read(apiProvider).uploadProfilePhoto(picked.bytes, picked.name), context.t('Your photo is saved.'));
  }

  Future<void> _removePhoto() => _photo(() => ref.read(apiProvider).removeProfilePhoto(), context.t('Your photo is removed.'));

  Future<void> _photo(Future<Account> Function() change, String done) async {
    if (_savingPhoto) return;
    setState(() => _savingPhoto = true);
    try {
      final account = await change();
      await ref.read(authProvider.notifier).accountChanged(account);
      if (!mounted) return;
      setState(() => _savingPhoto = false);
      _say(done);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _savingPhoto = false);
      _say(e.fieldError('photo') ?? errorMessage(context, e));
    }
  }

  Future<void> _chooseLanguage() async {
    final chosen = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final entry in languageNames.entries)
              ListTile(
                title: Text(entry.value),
                trailing: entry.key == _locale ? Icon(Icons.check_rounded, color: context.qc.accentText) : null,
                onTap: () => Navigator.of(context).pop(entry.key),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (chosen != null && mounted) setState(() => _locale = chosen);
  }

  String? _first(Map<String, List<String>> errors, String field) {
    final messages = errors[field];

    return messages == null || messages.isEmpty ? null : messages.first;
  }

  String _role(BuildContext context, String role) => switch (role) {
        'owner' => context.t('Owner'),
        'manager' => context.t('Manager'),
        'accountant' => context.t('Accountant'),
        'collector' => context.t('Collector'),
        _ => context.t('Viewer'),
      };

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(accountProvider);
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    if (account == null) return Scaffold(appBar: AppBar(title: Text(context.t('Your profile'))));

    final since = account.memberSince == null ? null : DateTime.tryParse(account.memberSince!);

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Your profile'))),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
          child: ContentColumn(
            padding: EdgeInsets.zero,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                // Who you are, with your photo.
                HeroPanel(
                  padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
                  child: Column(
                    children: [
                      Stack(
                        alignment: Alignment.center,
                        children: [
                          ProfileAvatar(account: account, size: 96),
                          if (_savingPhoto) SizedBox(width: 96, height: 96, child: CircularProgressIndicator(strokeWidth: 2.5, color: c.accent)),
                        ],
                      ),
                      const SizedBox(height: 14),
                      Text(account.name, textAlign: TextAlign.center, style: text.headlineSmall?.copyWith(color: c.onPrimary), maxLines: 2, overflow: TextOverflow.ellipsis),
                      const SizedBox(height: 4),
                      Directionality(
                        textDirection: TextDirection.ltr,
                        child: Text(account.email, style: text.bodyMedium?.copyWith(color: c.onPrimary.withValues(alpha: 0.8)), maxLines: 1, overflow: TextOverflow.ellipsis),
                      ),
                      const SizedBox(height: 12),
                      Wrap(
                        alignment: WrapAlignment.center,
                        spacing: 6,
                        runSpacing: 6,
                        children: [
                          SoftChip(
                            account.emailVerified ? context.t('E-mail confirmed') : context.t('E-mail not confirmed yet'),
                            icon: account.emailVerified ? Icons.verified_rounded : Icons.mark_email_unread_outlined,
                            background: c.onPrimary.withValues(alpha: 0.12),
                            foreground: c.onPrimary,
                          ),
                          SoftChip(_role(context, account.role), background: c.onPrimary.withValues(alpha: 0.12), foreground: c.onPrimary),
                        ],
                      ),
                      if (since != null) ...[
                        const SizedBox(height: 10),
                        Text(
                          context.t('Member since :date', {'date': westernDigits(DateFormat.yMMMM(ref.watch(localeProvider)).format(since))}),
                          style: text.bodySmall?.copyWith(color: c.onPrimary.withValues(alpha: 0.75)),
                        ),
                      ],
                      const SizedBox(height: 16),
                      Wrap(
                        alignment: WrapAlignment.center,
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          OutlinedButton.icon(
                            key: const ValueKey('profile-photo-change'),
                            onPressed: _savingPhoto ? null : _changePhoto,
                            icon: const Icon(Icons.photo_camera_outlined, size: 18),
                            label: Text(account.photo == null ? context.t('Add a photo') : context.t('Change photo')),
                            style: OutlinedButton.styleFrom(foregroundColor: c.onPrimary, side: BorderSide(color: c.onPrimary.withValues(alpha: 0.4))),
                          ),
                          if (account.photo != null)
                            TextButton(
                              key: const ValueKey('profile-photo-remove'),
                              onPressed: _savingPhoto ? null : _removePhoto,
                              style: TextButton.styleFrom(foregroundColor: c.onPrimary),
                              child: Text(context.t('Remove photo')),
                            ),
                        ],
                      ),
                    ],
                  ),
                ),
                if (_problem != null) ...[const SizedBox(height: 16), QNotice(_problem!, icon: Icons.error_outline)],

                QSectionTitle(context.t('Your details')),
                QCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      QField(
                        key: const ValueKey('profile-name'),
                        controller: _name,
                        label: context.t('Your name'),
                        errorText: _first(_detailErrors, 'name'),
                        textInputAction: TextInputAction.next,
                        autofillHints: const [AutofillHints.name],
                        enabled: !_savingDetails,
                      ),
                      const SizedBox(height: 16),
                      QField(
                        key: const ValueKey('profile-email'),
                        controller: _email,
                        label: context.t('Email'),
                        helper: context.t('A new e-mail is confirmed with a link we send to it.'),
                        errorText: _first(_detailErrors, 'email'),
                        keyboardType: TextInputType.emailAddress,
                        textInputAction: TextInputAction.next,
                        autofillHints: const [AutofillHints.email],
                        latin: true,
                        enabled: !_savingDetails,
                      ),
                      const SizedBox(height: 16),
                      QField(
                        key: const ValueKey('profile-phone'),
                        controller: _phone,
                        label: context.t('Phone (optional)'),
                        helper: context.t('Your own number, for notices by text message once they are offered.'),
                        errorText: _first(_detailErrors, 'phone'),
                        keyboardType: TextInputType.phone,
                        autofillHints: const [AutofillHints.telephoneNumber],
                        latin: true,
                        enabled: !_savingDetails,
                      ),
                      const SizedBox(height: 8),
                      ListTile(
                        key: const ValueKey('profile-language'),
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(Icons.translate_rounded, color: c.accentText),
                        title: Text(context.t('Language')),
                        subtitle: Text(context.t('The website, the app and your e-mails use it.')),
                        trailing: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(languageNames[_locale] ?? _locale, style: text.titleSmall),
                            const Icon(Icons.chevron_right_rounded),
                          ],
                        ),
                        onTap: _savingDetails ? null : _chooseLanguage,
                      ),
                      const SizedBox(height: 8),
                      QButton(label: context.t('Save changes'), loading: _savingDetails, onPressed: _saveDetails),
                    ],
                  ),
                ),

                QSectionTitle(context.t('Password')),
                QCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      QField(
                        key: const ValueKey('profile-current-password'),
                        controller: _current,
                        label: context.t('Current password'),
                        errorText: _first(_passwordErrors, 'current_password'),
                        obscure: !_showPasswords,
                        autofillHints: const [AutofillHints.password],
                        textInputAction: TextInputAction.next,
                        latin: true,
                        enabled: !_savingPassword,
                        suffix: IconButton(
                          tooltip: _showPasswords ? context.t('Hide the passwords') : context.t('Show the passwords'),
                          icon: Icon(_showPasswords ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                          onPressed: () => setState(() => _showPasswords = !_showPasswords),
                        ),
                      ),
                      const SizedBox(height: 16),
                      QField(
                        key: const ValueKey('profile-new-password'),
                        controller: _password,
                        label: context.t('New password'),
                        helper: context.t('At least 10 characters, with capital and small letters, a number and a symbol.'),
                        errorText: _first(_passwordErrors, 'password'),
                        obscure: !_showPasswords,
                        autofillHints: const [AutofillHints.newPassword],
                        textInputAction: TextInputAction.next,
                        latin: true,
                        enabled: !_savingPassword,
                      ),
                      const SizedBox(height: 16),
                      QField(
                        key: const ValueKey('profile-confirm-password'),
                        controller: _confirmation,
                        label: context.t('Confirm the new password'),
                        errorText: _first(_passwordErrors, 'password_confirmation'),
                        obscure: !_showPasswords,
                        autofillHints: const [AutofillHints.newPassword],
                        latin: true,
                        enabled: !_savingPassword,
                        onSubmitted: (_) => _savePassword(),
                      ),
                      const SizedBox(height: 16),
                      QButton(label: context.t('Change password'), loading: _savingPassword, onPressed: _savePassword),
                    ],
                  ),
                ),

                QSectionTitle(context.t('More for your account')),
                QCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      _Link(icon: Icons.devices_outlined, title: context.t('Devices'), onTap: () => context.push('/devices')),
                      const Divider(height: 1),
                      _Link(icon: Icons.notifications_none_rounded, title: context.t('Alert settings'), onTap: () => context.push('/settings/alerts')),
                      const Divider(height: 1),
                      _Link(icon: Icons.person_remove_outlined, title: context.t('Delete my account'), onTap: () => context.push('/delete-account'), danger: true),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Link extends StatelessWidget {
  const _Link({required this.icon, required this.title, required this.onTap, this.danger = false});

  final IconData icon;
  final String title;
  final VoidCallback onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final color = danger ? c.danger : null;

    return ListTile(
      leading: Icon(icon, color: color ?? c.accentText),
      title: Text(title, style: TextStyle(color: color)),
      // The chevron turns round by itself in a right-to-left language.
      trailing: const Icon(Icons.chevron_right_rounded),
      onTap: onTap,
    );
  }
}
