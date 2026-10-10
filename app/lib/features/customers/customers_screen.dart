import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../core/ui/paged.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';
import '../common/add_flows.dart';
import 'tags.dart';

/// Where the order the customers were last sorted in is kept, so the list opens the way the shop works.
const customerSortKey = 'customer_sort';

class CustomersList extends PagedNotifier<Customer> {
  String _query = '';
  String? _sort;
  String? _tag;

  String get query => _query;

  /// name, balance, next_due or activity (Win Plan PP12). Pinned customers come first whatever the order.
  String get sort => _sort ??= ref.read(sharedPreferencesProvider).getString(customerSortKey) ?? 'name';

  /// The tag the list is narrowed to, if any.
  String? get tag => _tag;

  @override
  Future<Paged<Customer>> fetch(int page) => ref.read(apiProvider).customers(query: _query, page: page, sort: sort, tag: _tag);

  Future<void> search(String query) {
    _query = query;

    return refresh();
  }

  Future<void> sortBy(String sort) {
    _sort = sort;
    ref.read(sharedPreferencesProvider).setString(customerSortKey, sort);

    return refresh();
  }

  Future<void> showTag(String? tag) {
    _tag = tag;

    return refresh();
  }
}

final customersListProvider = NotifierProvider<CustomersList, PagedState<Customer>>(CustomersList.new);

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

  /// Pin or unpin, from a long press on the row.
  Future<void> _actions(Customer customer) async {
    final pin = !customer.pinned;
    final chosen = await showModalBottomSheet<bool>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
              child: Text(customer.name, style: Theme.of(context).textTheme.titleMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
            ),
            ListTile(
              leading: Icon(pin ? Icons.push_pin_outlined : Icons.push_pin_rounded),
              title: Text(pin ? context.t('Pin to the top') : context.t('Unpin')),
              onTap: () => Navigator.of(context).pop(true),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (chosen != true || !mounted) return;

    try {
      await ref.read(apiProvider).pinCustomer(customer.id, pinned: pin);
      unawaited(ref.read(customersListProvider.notifier).refresh());
      if (!mounted) return;
      final message = pin
          ? context.t(':name is pinned to the top of your lists.', {'name': customer.name})
          : context.t(':name is no longer pinned.', {'name': customer.name});
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  Future<void> _sort() async {
    final list = ref.read(customersListProvider.notifier);
    final sorts = {
      'name': context.t('Name'),
      'balance': context.t('What they owe'),
      'next_due': context.t('Next due date'),
      'activity': context.t('Last activity'),
    };
    final chosen = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(padding: const EdgeInsets.fromLTRB(20, 0, 20, 8), child: Text(context.t('Sort by'), style: Theme.of(context).textTheme.titleMedium)),
            RadioGroup<String>(
              groupValue: list.sort,
              onChanged: (value) => Navigator.of(context).pop(value),
              child: Column(
                children: [for (final entry in sorts.entries) RadioListTile<String>(value: entry.key, title: Text(entry.value))],
              ),
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (chosen == null || chosen == list.sort) return;
    await list.sortBy(chosen);
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(accountProvider);
    final customers = account?.entitlement('customers');
    // Rebuild when a search has come back, so the empty message names what was asked.
    ref.watch(customersListProvider.select((state) => state.items));
    final list = ref.read(customersListProvider.notifier);
    final searching = list.query.isNotEmpty;
    final tagsOn = showsTags(account);
    final tags = tagsOn ? ref.watch(customerTagsProvider).valueOrNull ?? const <CustomerTag>[] : const <CustomerTag>[];
    final canWrite = account?.canWrite ?? false;

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
              Row(
                children: [
                  Expanded(
                    child: TextField(
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
                  ),
                  const SizedBox(width: 8),
                  _SortButton(active: list.sort != 'name', onPressed: _sort),
                ],
              ),
              if (tags.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        Padding(
                          padding: const EdgeInsetsDirectional.only(end: 8),
                          child: ChoiceChip(
                            label: Text(context.t('All')),
                            selected: list.tag == null,
                            onSelected: (_) {
                              list.showTag(null);
                              setState(() {});
                            },
                          ),
                        ),
                        for (final tag in tags)
                          Padding(
                            padding: const EdgeInsetsDirectional.only(end: 8),
                            child: ChoiceChip(
                              avatar: TagDot(tag.colour),
                              label: Text(tag.name),
                              selected: list.tag == tag.id,
                              onSelected: (_) {
                                list.showTag(list.tag == tag.id ? null : tag.id);
                                setState(() {});
                              },
                            ),
                          ),
                        if (canWrite) IconButton(tooltip: context.t('Manage tags'), icon: const Icon(Icons.tune_rounded), onPressed: () => context.push('/settings/tags')),
                      ],
                    ),
                  ),
                )
              else if (tagsOn && canWrite && ref.watch(customerTagsProvider).hasValue)
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton.icon(
                    onPressed: () => context.push('/settings/tags'),
                    icon: const Icon(Icons.sell_outlined, size: 18),
                    label: Text(context.t('Group customers with tags')),
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
            : list.tag != null
            ? QEmpty(icon: Icons.sell_outlined, title: context.t('No customers with this tag yet'), message: context.t('Add a tag on a customer’s form to group them here.'))
            : QEmpty(
                icon: Icons.people_outline,
                title: context.t('No customers yet'),
                message: context.t('Add the people you sell to on instalments. Contracts and payments belong to a customer.'),
                action: account?.canWrite == false ? null : QButton(label: context.t('Add your first customer'), expand: false, onPressed: () => addCustomer(context, ref)),
              ),
        itemBuilder: (context, customer) => _CustomerRow(
          customer: customer,
          currency: account?.currency ?? '',
          showTags: tagsOn,
          onLongPress: canWrite ? () => _actions(customer) : null,
        ),
      ),
    );
  }
}

/// Opens the choice of order; carries a gold dot while the list is in anything but name order.
class _SortButton extends StatelessWidget {
  const _SortButton({required this.active, required this.onPressed});

  final bool active;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return IconButton.outlined(
      tooltip: context.t('Sort'),
      onPressed: onPressed,
      style: IconButton.styleFrom(side: BorderSide(color: c.line), fixedSize: const Size(52, 52)),
      icon: Badge(isLabelVisible: active, smallSize: 7, backgroundColor: c.accent, child: const Icon(Icons.swap_vert_rounded)),
    );
  }
}

class _CustomerRow extends StatelessWidget {
  const _CustomerRow({required this.customer, required this.currency, this.showTags = false, this.onLongPress});

  final Customer customer;
  final String currency;
  final bool showTags;
  final VoidCallback? onLongPress;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final owed = customer.owed;

    final tags = showTags ? customer.tags : const <CustomerTag>[];

    return QCard(
      onTap: () => context.push('/customers/${customer.id}'),
      onLongPress: onLongPress,
      child: Row(
        children: [
          InitialsAvatar(customer.name, size: 46),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    if (customer.pinned) ...[
                      Icon(Icons.push_pin_rounded, size: 14, color: c.accentText, semanticLabel: context.t('Pinned')),
                      const SizedBox(width: 4),
                    ],
                    Flexible(child: Text(customer.name, style: text.titleSmall, maxLines: 1, overflow: TextOverflow.ellipsis)),
                  ],
                ),
                Directionality(textDirection: TextDirection.ltr, child: Text(customer.phone, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                if (tags.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Wrap(spacing: 4, runSpacing: 4, children: [for (final tag in tags.take(3)) TagChip(tag), if (tags.length > 3) Text('+${tags.length - 3}', style: text.labelSmall?.copyWith(color: c.inkMuted))]),
                  ),
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
