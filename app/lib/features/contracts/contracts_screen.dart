import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/paged.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';

class ContractsList extends PagedNotifier<Contract> {
  String _status = 'active';
  String _query = '';

  String get status => _status;

  String get query => _query;

  @override
  Future<Paged<Contract>> fetch(int page) => ref.read(apiProvider).contracts(status: _status, query: _query, page: page);

  Future<void> filter({String? status, String? query}) {
    _status = status ?? _status;
    _query = query ?? _query;

    return refresh();
  }
}

final contractsListProvider = NotifierProvider<ContractsList, PagedState<Contract>>(ContractsList.new);

/// One contract with its schedule and its payments.
final contractProvider = FutureProvider.autoDispose.family<Contract, String>((ref, id) => ref.watch(apiProvider).contract(id));

/// Opening a contract on a full plan answers with the upgrade sheet, never with an empty form.
Future<void> addContract(BuildContext context, WidgetRef ref, {String? customerId}) async {
  final contracts = ref.read(accountProvider)?.entitlement('active_contracts');

  if (contracts != null && !contracts.allowsMore) {
    await showUpgradeSheet(
      context,
      UpgradeRequired(
        code: contracts.enabled ? 'limit_reached' : 'feature_locked',
        message: context.t('Your plan includes up to :limit active contracts. Upgrade to open more, or wait until a contract is settled to free up a place.', {'limit': contracts.limit ?? 0}),
        feature: 'active_contracts',
        limit: contracts.limit,
        used: contracts.used,
      ),
    );

    return;
  }

  await context.push(customerId == null ? '/contracts/new' : '/contracts/new?customer=$customerId');
}

class ContractsScreen extends ConsumerStatefulWidget {
  const ContractsScreen({super.key});

  @override
  ConsumerState<ContractsScreen> createState() => _ContractsScreenState();
}

class _ContractsScreenState extends ConsumerState<ContractsScreen> {
  final _search = TextEditingController();
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _typed(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () => ref.read(contractsListProvider.notifier).filter(query: value));
  }

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(accountProvider);
    final language = ref.watch(localeProvider);
    final list = ref.read(contractsListProvider.notifier);
    // Rebuild when a search or filter has come back, so the empty message names what was asked.
    ref.watch(contractsListProvider.select((state) => state.items));
    final contracts = account?.entitlement('active_contracts');
    final filters = {
      'active': context.t('Active'),
      'late': context.t('Late'),
      'settled': context.t('Settled'),
      'cancelled': context.t('Cancelled'),
      'all': context.t('All'),
    };

    return SectionScaffold(
      title: context.t('Contracts'),
      floating: account?.canWrite == false
          ? null
          : FloatingActionButton.extended(onPressed: () => addContract(context, ref), icon: const Icon(Icons.add), label: Text(context.t('New contract'))),
      body: PagedListView<Contract>(
        provider: contractsListProvider,
        header: Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TextField(
                controller: _search,
                onChanged: (value) {
                  _typed(value);
                  setState(() {});
                },
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(
                  hintText: context.t('Search by reference or customer'),
                  prefixIcon: const Icon(Icons.search),
                  suffixIcon: _search.text.isEmpty
                      ? null
                      : IconButton(
                          tooltip: context.t('Clear'),
                          icon: const Icon(Icons.close),
                          onPressed: () {
                            _search.clear();
                            _typed('');
                            setState(() {});
                          },
                        ),
                ),
              ),
              const SizedBox(height: 12),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    for (final entry in filters.entries)
                      Padding(
                        padding: const EdgeInsetsDirectional.only(end: 8),
                        child: ChoiceChip(
                          label: Text(entry.value),
                          selected: list.status == entry.key,
                          onSelected: (_) {
                            list.filter(status: entry.key);
                            setState(() {});
                          },
                        ),
                      ),
                  ],
                ),
              ),
              if (contracts != null && contracts.enabled && contracts.limit != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: QMeter(label: context.t('Active contracts'), used: contracts.used ?? 0, limit: contracts.limit, figures: usageFigures(context, contracts)),
                ),
            ],
          ),
        ),
        empty: list.query.isNotEmpty || list.status != 'active'
            ? QEmpty(
                icon: Icons.search_off,
                title: context.t('No contracts here'),
                message: list.query.isNotEmpty ? context.t('Check the spelling, or try the contract number.') : context.t('Nothing matches this filter yet.'),
              )
            : QEmpty(
                icon: Icons.description_outlined,
                title: context.t('No contracts yet'),
                message: context.t('A contract turns a sale into a schedule of instalments you can collect against.'),
                action: account?.canWrite == false ? null : QButton(label: context.t('Open your first contract'), expand: false, onPressed: () => addContract(context, ref)),
              ),
        itemBuilder: (context, contract) => ContractRow(contract: contract, currency: account?.currency ?? '', language: language),
      ),
    );
  }
}

/// A contract's state in a word: active, late, settled or cancelled.
class ContractStateBadge extends StatelessWidget {
  const ContractStateBadge(this.state, {super.key});

  final String state;

  @override
  Widget build(BuildContext context) => switch (state) {
        'late' => QBadge(context.t('Late'), tone: QTone.bad),
        'settled' => QBadge(context.t('Settled'), tone: QTone.ok),
        'cancelled' => QBadge(context.t('Cancelled')),
        _ => QBadge(context.t('Active'), tone: QTone.info),
      };
}

class ContractRow extends StatelessWidget {
  const ContractRow({super.key, required this.contract, required this.currency, required this.language});

  final Contract contract;
  final String currency;
  final String language;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final next = contract.next;
    final owed = contract.owed;

    return QCard(
      onTap: () => context.push('/contracts/${contract.id}'),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // The reference and its state wrap onto a second line before they crowd the amount.
              Expanded(
                child: Wrap(
                  spacing: 8,
                  runSpacing: 4,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Directionality(textDirection: TextDirection.ltr, child: Text(contract.reference, style: text.titleSmall)),
                    ContractStateBadge(contract.state),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              if (owed != null) EndAmount(child: MoneyText(owed, currency, style: text.titleSmall)),
            ],
          ),
          if (contract.customerName != null) ...[
            const SizedBox(height: 4),
            Text(contract.customerName!, style: text.bodyMedium?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis),
          ],
          if (next != null && contract.isRunning) ...[
            const SizedBox(height: 8),
            Row(
              children: [
                Icon(Icons.event_outlined, size: 16, color: contract.isLate ? c.danger : c.inkMuted),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    context.t('Next: :amount on :date', {'amount': next.remaining.format(currency), 'date': formatDay(next.dueDate, language)}),
                    style: text.bodySmall?.copyWith(color: contract.isLate ? c.danger : c.inkMuted),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
