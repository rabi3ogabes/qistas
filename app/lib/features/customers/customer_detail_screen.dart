import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../contracts/contracts_screen.dart';
import 'customers_screen.dart';

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
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
            child: ContentColumn(padding: EdgeInsets.zero, child: _Body(customer: data, account: account)),
          ),
        ),
      ),
    );
  }
}

class _Body extends ConsumerWidget {
  const _Body({required this.customer, required this.account});

  final Customer customer;
  final Account? account;

  Future<void> _launch(String uri) => launchUrl(Uri.parse(uri), mode: LaunchMode.externalApplication);

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
    final digits = customer.phone.replaceAll(RegExp(r'[^\d]'), '');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        QCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  OutlinedButton.icon(onPressed: () => _launch('tel:${customer.phone.replaceAll(RegExp(r'[^\d+]'), '')}'), icon: const Icon(Icons.call_outlined), label: Text(context.t('Call'))),
                  if (digits.length >= 7) OutlinedButton.icon(onPressed: () => _launch('https://wa.me/$digits'), icon: const Icon(Icons.chat_outlined), label: Text(context.t('WhatsApp'))),
                  if (customer.email != null) OutlinedButton.icon(onPressed: () => _launch('mailto:${customer.email}'), icon: const Icon(Icons.mail_outline), label: Text(context.t('Email'))),
                ],
              ),
              const SizedBox(height: 8),
              QFact(context.t('Phone'), customer.phone, latin: true),
              if (customer.phoneSecondary != null) QFact(context.t('Second phone'), customer.phoneSecondary!, latin: true),
              if (customer.email != null) QFact(context.t('Email'), customer.email!, latin: true),
              if (customer.address != null) QFact(context.t('Address'), customer.address!),
              if (customer.nationalId != null) QFact(context.t('National ID'), customer.nationalId!, latin: true),
              if (customer.notes != null) QFact(context.t('Notes'), customer.notes!),
              if (customer.owed != null) ...[
                const Divider(height: 24),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [Text(context.t('Owes'), style: text.bodyMedium?.copyWith(color: c.inkMuted)), MoneyText(customer.owed!, currency, style: text.titleMedium)],
                ),
              ],
            ],
          ),
        ),
        QSectionTitle(
          context.t('Contracts'),
          trailing: account?.canWrite == false ? null : TextButton.icon(onPressed: () => _openContract(context, ref), icon: const Icon(Icons.add), label: Text(context.t('Open a contract'))),
        ),
        if (customer.contracts.isEmpty)
          QCard(child: Text(context.t('Open a contract to set up this customer’s instalment plan.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)))
        else
          for (final contract in customer.contracts)
            Padding(padding: const EdgeInsets.only(bottom: 10), child: ContractRow(contract: contract, currency: currency, language: language)),
        if (account?.canDelete == true) ...[
          const SizedBox(height: 24),
          QButton(label: context.t('Delete customer'), kind: QButtonKind.quiet, icon: Icons.delete_outline, onPressed: () => _delete(context, ref)),
        ],
      ],
    );
  }
}
