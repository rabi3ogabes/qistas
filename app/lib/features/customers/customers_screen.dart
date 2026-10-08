import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/paged.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';

class CustomersList extends PagedNotifier<Customer> {
  String _query = '';

  String get query => _query;

  @override
  Future<Paged<Customer>> fetch(int page) => ref.read(apiProvider).customers(query: _query, page: page);

  Future<void> search(String query) {
    _query = query;

    return refresh();
  }
}

final customersListProvider = NotifierProvider<CustomersList, PagedState<Customer>>(CustomersList.new);

/// What a person sees when they try to add a customer on a full plan: the upgrade sheet, never an empty form.
Future<void> addCustomer(BuildContext context, WidgetRef ref) async {
  final account = ref.read(accountProvider);
  final customers = account?.entitlement('customers');

  if (customers != null && !customers.allowsMore) {
    await showUpgradeSheet(
      context,
      UpgradeRequired(
        code: customers.enabled ? 'limit_reached' : 'feature_locked',
        message: context.t('Your plan includes up to :limit customers. Upgrade to add more, or delete a customer you no longer need to free up a place.', {'limit': customers.limit ?? 0}),
        feature: 'customers',
        limit: customers.limit,
        used: customers.used,
      ),
    );

    return;
  }

  await context.push('/customers/new');
}

class CustomersScreen extends ConsumerStatefulWidget {
  const CustomersScreen({super.key});

  @override
  ConsumerState<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends ConsumerState<CustomersScreen> {
  final _search = TextEditingController();
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _changed(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () => ref.read(customersListProvider.notifier).search(value));
  }

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(accountProvider);
    final customers = account?.entitlement('customers');
    // Rebuild when a search has come back, so the empty message names what was asked.
    ref.watch(customersListProvider.select((state) => state.items));
    final searching = ref.read(customersListProvider.notifier).query.isNotEmpty;

    return SectionScaffold(
      title: context.t('Customers'),
      floating: account?.canWrite == false
          ? null
          : FloatingActionButton.extended(onPressed: () => addCustomer(context, ref), icon: const Icon(Icons.add), label: Text(context.t('Add customer'))),
      body: PagedListView<Customer>(
        provider: customersListProvider,
        header: Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Column(
            children: [
              TextField(
                controller: _search,
                onChanged: _changed,
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(
                  hintText: context.t('Search by name, phone or email'),
                  prefixIcon: const Icon(Icons.search),
                  suffixIcon: _search.text.isEmpty ? null : IconButton(tooltip: context.t('Clear'), icon: const Icon(Icons.close), onPressed: () {
                    _search.clear();
                    _changed('');
                    setState(() {});
                  }),
                ),
              ),
              if (customers != null && customers.enabled && customers.limit != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: QMeter(label: context.t('Customers'), used: customers.used ?? 0, limit: customers.limit, figures: usageFigures(context, customers)),
                ),
            ],
          ),
        ),
        empty: searching
            ? QEmpty(icon: Icons.search_off, title: context.t('No customers match “:term”', {'term': _search.text}), message: context.t('Check the spelling, or try part of their phone number.'))
            : QEmpty(
                icon: Icons.people_outline,
                title: context.t('No customers yet'),
                message: context.t('Add the people you sell to on instalments. Contracts and payments belong to a customer.'),
                action: account?.canWrite == false ? null : QButton(label: context.t('Add your first customer'), expand: false, onPressed: () => addCustomer(context, ref)),
              ),
        itemBuilder: (context, customer) => _CustomerRow(customer: customer, currency: account?.currency ?? ''),
      ),
    );
  }
}

class _CustomerRow extends StatelessWidget {
  const _CustomerRow({required this.customer, required this.currency});

  final Customer customer;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final owed = customer.owed;

    return QCard(
      onTap: () => context.push('/customers/${customer.id}'),
      child: Row(
        children: [
          CircleAvatar(backgroundColor: c.surfaceAlt, foregroundColor: c.ink, child: Text(customer.name.isEmpty ? '?' : String.fromCharCode(customer.name.runes.first).toUpperCase())),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(customer.name, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis),
                Directionality(textDirection: TextDirection.ltr, child: Text(customer.phone, style: text.bodySmall?.copyWith(color: c.inkMuted))),
              ],
            ),
          ),
          if (owed != null)
            // Gives way, in size, before the name does: large text must never push the amount off the card.
            EndAmount(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  MoneyText(owed, currency, style: text.titleSmall, color: owed.isPositive ? null : c.inkMuted),
                  if ((customer.runningContracts ?? 0) > 0) Text(context.t(':count running', {'count': customer.runningContracts}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
