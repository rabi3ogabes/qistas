import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import 'contracts_screen.dart';

/// "Who paid?": the contracts that can take a payment, searchable; choosing one opens it ready to record.
Future<void> showContractPicker(BuildContext context) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => const _ContractPicker(),
    );

class _ContractPicker extends ConsumerStatefulWidget {
  const _ContractPicker();

  @override
  ConsumerState<_ContractPicker> createState() => _ContractPickerState();
}

class _ContractPickerState extends ConsumerState<_ContractPicker> {
  final _search = TextEditingController();
  Timer? _debounce;
  List<Contract> _items = const [];
  bool _loading = true;
  ApiException? _error;
  int _request = 0;

  @override
  void initState() {
    super.initState();
    _load('');
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    super.dispose();
  }

  void _typed(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 250), () => _load(value));
  }

  Future<void> _load(String query) async {
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final page = await ref.read(apiProvider).contracts(status: 'active', query: query);
      if (mounted && request == _request) {
        setState(() {
          _items = page.items;
          _loading = false;
        });
      }
    } on ApiException catch (e) {
      if (mounted && request == _request) {
        setState(() {
          _error = e;
          _loading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(accountProvider);
    final currency = account?.currency ?? '';
    final text = Theme.of(context).textTheme;
    final c = context.qc;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SizedBox(
        height: MediaQuery.sizeOf(context).height * 0.78,
        child: ContentColumn(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(context.t('Who paid?'), style: text.titleLarge),
              const SizedBox(height: 12),
              TextField(
                controller: _search,
                onChanged: _typed,
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(hintText: context.t('Search by reference or customer'), prefixIcon: const Icon(Icons.search)),
              ),
              const SizedBox(height: 8),
              Expanded(
                child: _loading
                    ? const QSkeletonList(rows: 4)
                    : _error != null
                        ? QErrorView(message: errorMessage(context, _error!), retryLabel: context.t('Try again'), onRetry: () => _load(_search.text))
                        : _items.isEmpty
                            ? QEmpty(icon: Icons.search_off, title: context.t('No contracts here'), message: context.t('Check the spelling, or try the contract number.'))
                            : ListView.separated(
                                itemCount: _items.length,
                                separatorBuilder: (_, _) => Divider(height: 1, color: c.line),
                                itemBuilder: (context, index) {
                                  final contract = _items[index];

                                  return ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    leading: InitialsAvatar(contract.customerName ?? '?', size: 40),
                                    title: Text(contract.customerName ?? contract.reference, maxLines: 1, overflow: TextOverflow.ellipsis),
                                    subtitle: Directionality(textDirection: TextDirection.ltr, child: Text(contract.reference, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                                    trailing: contract.owed == null ? null : EndAmount(child: MoneyText(contract.owed!, currency, style: text.titleSmall)),
                                    onTap: () {
                                      Navigator.of(context).pop();
                                      context.push('/contracts/${contract.id}?pay=1');
                                    },
                                  );
                                },
                              ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Re-exported for the callers that only need to know a contract's state in a word.
Widget contractStateBadge(String state) => ContractStateBadge(state);
