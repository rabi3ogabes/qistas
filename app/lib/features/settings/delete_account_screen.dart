import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';

/// "Delete my account" (Google Play and Apple require it inside the app). The owner deletes the business: it turns
/// read-only and is erased after 30 days unless restored; they type its name so it never happens by a slip. Anyone
/// else deletes only their own login, at once. Both confirm with the password (and the code with two-step sign-in).
class DeleteAccountScreen extends ConsumerStatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  ConsumerState<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends ConsumerState<DeleteAccountScreen> {
  final _password = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  bool _busy = false;
  ApiException? _error;

  @override
  void dispose() {
    _password.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  Future<void> _delete() async {
    final account = ref.read(accountProvider);
    if (account == null) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final scheduled = context.t('Your business will be deleted on :date. You can restore it until then.');

    try {
      final result = await ref.read(apiProvider).deleteAccount(
            password: _password.text,
            code: account.twoFactor ? _code.text.trim() : null,
            confirmName: account.isOwner ? _name.text.trim() : null,
          );
      if (result.scope == 'login') {
        // The login no longer exists: back to the sign-in screen.
        await ref.read(authProvider.notifier).signOut();
        return;
      }
      await ref.read(authProvider.notifier).refresh();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(scheduled.replaceAll(':date', result.restoreUntil ?? ''))));
      context.pop();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    if (account == null) return const SizedBox.shrink();
    final owner = account.isOwner;
    final nameMatches = _name.text.trim() == account.businessName.trim();
    final ready = _password.text.isNotEmpty && (!owner || nameMatches) && (!account.twoFactor || _code.text.trim().isNotEmpty);
    final general = _error != null && _error!.fields.isEmpty ? errorMessage(context, _error!) : null;

    return SectionScaffold(
      title: context.t('Delete my account'),
      showAccount: false,
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
        children: [
          QCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  owner ? context.t('Delete :name and your account', {'name': account.businessName}) : context.t('Delete your login'),
                  style: text.titleMedium,
                ),
                const SizedBox(height: 10),
                if (owner) ...[
                  _Point(context.t('The business turns read-only at once for everyone in it.')),
                  _Point(context.t('After 30 days its customers, contracts, payments and files are erased for good.')),
                  _Point(context.t('Until then you can restore it with one tap, exactly as it was.')),
                ] else
                  Text(context.t('You leave :name at once. The business and its books stay as they are.', {'name': account.businessName}), style: text.bodyMedium),
              ],
            ),
          ),
          const SizedBox(height: 20),
          QField(
            key: const ValueKey('delete-password'),
            controller: _password,
            label: context.t('Your password'),
            obscure: true,
            autofillHints: const [AutofillHints.password],
            errorText: _error?.fieldError('password'),
            onChanged: (_) => setState(() {}),
          ),
          if (account.twoFactor) ...[
            const SizedBox(height: 14),
            QField(
              key: const ValueKey('delete-code'),
              controller: _code,
              label: context.t('Authentication code'),
              keyboardType: TextInputType.number,
              latin: true,
              maxLength: 6,
              onChanged: (_) => setState(() {}),
            ),
          ],
          if (owner) ...[
            const SizedBox(height: 14),
            QField(
              key: const ValueKey('delete-confirm-name'),
              controller: _name,
              label: context.t('Type the business name to confirm: :name', {'name': account.businessName}),
              errorText: _error?.fieldError('confirm_name'),
              onChanged: (_) => setState(() {}),
            ),
          ],
          if (general != null) ...[
            const SizedBox(height: 12),
            Text(general, key: const ValueKey('delete-error'), style: text.bodyMedium?.copyWith(color: c.danger)),
          ],
          const SizedBox(height: 24),
          QButton(
            key: const ValueKey('delete-confirm'),
            label: owner ? context.t('Delete the business') : context.t('Delete my login'),
            kind: QButtonKind.danger,
            loading: _busy,
            onPressed: ready ? _delete : null,
          ),
        ],
      ),
    );
  }
}

class _Point extends StatelessWidget {
  const _Point(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(padding: const EdgeInsets.only(top: 7), child: Icon(Icons.circle, size: 6, color: context.qc.inkMuted)),
            const SizedBox(width: 10),
            Expanded(child: Text(text, style: Theme.of(context).textTheme.bodyMedium)),
          ],
        ),
      );
}

/// On the dashboard while the business is being deleted: what that means, and the owner's way back.
class DeletionBanner extends ConsumerStatefulWidget {
  const DeletionBanner({super.key});

  @override
  ConsumerState<DeletionBanner> createState() => _DeletionBannerState();
}

class _DeletionBannerState extends ConsumerState<DeletionBanner> {
  bool _busy = false;

  Future<void> _restore() async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).restoreAccount();
      await ref.read(authProvider.notifier).refresh();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(accountProvider);
    final date = account?.deletionScheduledFor;
    if (account == null || date == null) return const SizedBox.shrink();
    final c = context.qc;

    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Material(
        key: const ValueKey('deletion-banner'),
        color: c.tintBlush,
        borderRadius: BorderRadius.circular(16),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 12, 12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.delete_outline_rounded, color: c.danger),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      context.t('This business will be deleted on :date. Until then nothing can be changed.', {'date': date}),
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: c.ink),
                    ),
                  ),
                ],
              ),
              if (account.isOwner)
                Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: TextButton(key: const ValueKey('deletion-restore'), onPressed: _busy ? null : _restore, child: Text(context.t('Restore the business'))),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
