import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../common/add_flows.dart';
import '../contracts/contracts_screen.dart';
import '../documents/document_options_sheet.dart';
import '../reminders/reminders.dart';
import 'customers_screen.dart';
import 'tags.dart';



final customerProvider = FutureProvider.autoDispose.family<Customer, String>((ref, id) => ref.watch(apiProvider).customer(id));

class CustomerDetailScreen extends ConsumerWidget {
  const CustomerDetailScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final customer = ref.watch(customerProvider(id));
    final account = ref.watch(accountProvider);

    return Scaffold(
      appBar: AppBar(
        title: Text(customer.valueOrNull?.name ?? context.t('Customer')),
        actions: [
          if (customer.hasValue)
            IconButton(tooltip: context.t('Statement'), icon: const Icon(Icons.description_outlined), onPressed: () => showDocumentSheet(context, DocumentRequest.customer(id, customer.requireValue.name))),
          if (account?.canWrite == true && customer.hasValue)
            IconButton(
              tooltip: customer.requireValue.pinned ? context.t('Unpin') : context.t('Pin to the top'),
              isSelected: customer.requireValue.pinned,
              icon: const Icon(Icons.push_pin_outlined),
              selectedIcon: const Icon(Icons.push_pin_rounded),
              onPressed: () => _pin(context, ref, customer.requireValue),
            ),
          if (account?.canWrite == true && customer.hasValue)
            IconButton(tooltip: context.t('Edit'), icon: const Icon(Icons.edit_outlined), onPressed: () async {
              await context.push('/customers/$id/edit');
              ref.invalidate(customerProvider(id));
            }),
        ],
      ),
      body: customer.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 5)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(customerProvider(id))),
        data: (data) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(customerProvider(id));
            await ref.read(customerProvider(id).future);
          },
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 40),
            child: ContentColumn(padding: EdgeInsets.zero, child: _Body(customer: data, account: account)),
          ),
        ),
      ),
    );
  }

  Future<void> _pin(BuildContext context, WidgetRef ref, Customer customer) async {
    final pin = !customer.pinned;
    try {
      await ref.read(apiProvider).pinCustomer(customer.id, pinned: pin);
      ref.invalidate(customerProvider(customer.id));
      unawaited(ref.read(customersListProvider.notifier).refresh());
      if (!context.mounted) return;
      final message = pin
          ? context.t(':name is pinned to the top of your lists.', {'name': customer.name})
          : context.t(':name is no longer pinned.', {'name': customer.name});
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.customer, required this.account});

  final Customer customer;
  final Account? account;

  Future<void> _openContract(BuildContext context, WidgetRef ref) async {
    await addContract(context, ref, customerId: customer.id);
    ref.invalidate(customerProvider(customer.id));
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.t('Delete customer')),
        content: Text(context.t('This removes :name from your lists and frees a place on your plan. Their contracts and payments stay in your records.', {'name': customer.name})),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.t('Cancel'))),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('Delete customer'), style: TextStyle(color: Theme.of(context).colorScheme.error))),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;

    try {
      await ref.read(apiProvider).deleteCustomer(customer.id);
      unawaited(ref.read(customersListProvider.notifier).refresh());
      await ref.read(authProvider.notifier).refresh();
      if (context.mounted) {
        context.pop();
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Customer deleted.'))));
      }
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.fieldError('customer') ?? errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final currency = account?.currency ?? '';
    final country = account?.country ?? '';
    final onHero = c.onPrimary;
    final hasPhone = customer.phone.trim().isNotEmpty;
    final email = customer.email;
    final owed = customer.owed;
    final running = customer.runningContracts ?? 0;
    final tags = showsTags(account) ? customer.tags : const <CustomerTag>[];
    // Finished contracts the shop put away wait at the bottom, folded (Win Plan PP12).
    final current = [for (final contract in customer.contracts) if (!contract.archived) contract];
    final archived = [for (final contract in customer.contracts) if (contract.archived) contract];
    var order = 0;

    final hasDetails = customer.phoneSecondary != null || email != null || customer.address != null || customer.job != null || customer.nationalId != null || customer.notes != null || hasPhone;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Reveal(
          order: order++,
          child: HeroPanel(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    InitialsAvatar(customer.name, size: 58),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(customer.name, style: text.headlineSmall?.copyWith(color: onHero, fontWeight: FontWeight.w700), maxLines: 2, overflow: TextOverflow.ellipsis),
                          if (hasPhone) Directionality(textDirection: TextDirection.ltr, child: Text(customer.phone, style: text.bodyMedium?.copyWith(color: onHero.withValues(alpha: 0.75)))),
                        ],
                      ),
                    ),
                  ],
                ),
                if (owed != null) ...[
                  const SizedBox(height: 22),
                  Text(context.t('Owes'), style: text.labelLarge?.copyWith(color: onHero.withValues(alpha: 0.72), letterSpacing: 0.4)),
                  const SizedBox(height: 4),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: AlignmentDirectional.centerStart,
                    child: CountUpMoney(owed, currency, color: onHero, style: text.displaySmall?.copyWith(fontSize: 40, fontWeight: FontWeight.w700, height: 1.1)),
                  ),
                  if (running > 0) ...[
                    const SizedBox(height: 12),
                    SoftChip(context.t(':count running', {'count': running}), icon: Icons.description_outlined, background: onHero.withValues(alpha: 0.12), foreground: onHero),
                  ],
                ],
              ],
            ),
          ),
        ),
        if (tags.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 14),
            child: Wrap(spacing: 6, runSpacing: 6, children: [for (final tag in tags) TagChip(tag)]),
          ),
        if (hasPhone || email != null)
          Reveal(
            order: order++,
            child: Padding(
              padding: const EdgeInsets.only(top: 16),
              child: Row(
                children: [
                  if (hasPhone) Expanded(child: _ContactAction(icon: Icons.call_outlined, label: context.t('Call'), onTap: () => callNumber(context, customer.phone))),
                  if (hasPhone) Expanded(child: _ContactAction(icon: Icons.chat_outlined, label: context.t('WhatsApp'), onTap: () => sendWhatsApp(context, phone: customer.phone, country: country, message: ''))),
                  if (email != null) Expanded(child: _ContactAction(icon: Icons.mail_outline, label: context.t('Email'), onTap: () => launchUrl(Uri.parse('mailto:$email'), mode: LaunchMode.externalApplication))),
                ],
              ),
            ),
          ),
        Reveal(
          order: order++,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              QSectionTitle(
                context.t('Contracts'),
                trailing: account?.canWrite == false ? null : TextButton.icon(onPressed: () => _openContract(context, ref), icon: const Icon(Icons.add), label: Text(context.t('Open a contract'))),
              ),
              if (customer.contracts.isEmpty)
                QCard(child: Text(context.t('Open a contract to set up this customer’s instalment plan.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)))
              else
                for (final contract in current)
                  Padding(padding: const EdgeInsets.only(bottom: 10), child: ContractRow(contract: contract, currency: currency, language: language)),
              if (archived.isNotEmpty)
                Theme(
                  data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                  child: ExpansionTile(
                    tilePadding: const EdgeInsets.symmetric(horizontal: 4),
                    childrenPadding: EdgeInsets.zero,
                    leading: Icon(Icons.archive_outlined, color: c.inkMuted),
                    title: Text(context.t('Archived contracts: :count', {'count': archived.length}), style: text.titleSmall?.copyWith(color: c.inkMuted)),
                    children: [
                      for (final contract in archived)
                        Padding(padding: const EdgeInsets.only(bottom: 10), child: ContractRow(contract: contract, currency: currency, language: language)),
                    ],
                  ),
                ),
            ],
          ),
        ),
        if (hasDetails)
          Reveal(
            order: order++,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                QSectionTitle(context.t('Details')),
                QCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (hasPhone) QFact(context.t('Phone'), customer.phone, latin: true),
                      if (customer.phoneSecondary != null) QFact(context.t('Second phone'), customer.phoneSecondary!, latin: true),
                      if (email != null) QFact(context.t('Email'), email, latin: true),
                      if (customer.address != null) QFact(context.t('Address'), customer.address!),
                      if (customer.job != null) QFact(context.t('Job or employer'), customer.job!),
                      if (customer.nationalId != null) QFact(context.t('National ID'), customer.nationalId!, latin: true),
                      if (customer.notes != null) QFact(context.t('Notes'), customer.notes!),
                    ],
                  ),
                ),
              ],
            ),
          ),
        if (account?.canDelete == true) ...[
          const SizedBox(height: 28),
          QButton(label: context.t('Delete customer'), kind: QButtonKind.quiet, icon: Icons.delete_outline, onPressed: () => _delete(context, ref)),
        ],
      ],
    );
  }
}

/// A round button with its word beneath it: the quickest way to reach someone.
class _ContactAction extends StatelessWidget {
  const _ContactAction({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return Semantics(
      button: true,
      label: label,
      onTap: onTap,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(QistasMetrics.radiusLg),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(shape: BoxShape.circle, color: c.surface, border: Border.all(color: c.line)),
                child: Icon(icon, color: c.accentText),
              ),
              const SizedBox(height: 6),
              Text(label, style: Theme.of(context).textTheme.labelMedium?.copyWith(color: c.ink), maxLines: 1, overflow: TextOverflow.ellipsis),
            ],
          ),
        ),
      ),
    );
  }
}
