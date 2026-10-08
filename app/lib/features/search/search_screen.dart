import 'dart:async';
import 'dart:convert';

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
import '../contracts/contracts_screen.dart';

/// Something a person opened from search, remembered so the next search starts from where they last were.
class RecentItem {
  const RecentItem({required this.kind, required this.id, required this.title, required this.subtitle});

  factory RecentItem.fromJson(Map<String, dynamic> json) => RecentItem(
        kind: (json['kind'] ?? 'customer').toString(),
        id: (json['id'] ?? '').toString(),
        title: (json['title'] ?? '').toString(),
        subtitle: (json['subtitle'] ?? '').toString(),
      );

  /// customer or contract.
  final String kind;
  final String id;
  final String title;
  final String subtitle;

  Map<String, dynamic> toJson() => {'kind': kind, 'id': id, 'title': title, 'subtitle': subtitle};

  String get path => kind == 'contract' ? '/contracts/$id' : '/customers/$id';
}

/// The last few things opened from search, newest first, kept on this phone.
class RecentSearches extends Notifier<List<RecentItem>> {
  static const String key = 'recent_searches';
  static const int keep = 6;

  @override
  List<RecentItem> build() {
    final raw = ref.read(sharedPreferencesProvider).getString(key);
    if (raw == null) return const [];

    try {
      return [for (final entry in jsonDecode(raw) as List<dynamic>) RecentItem.fromJson(entry as Map<String, dynamic>)];
    } on Object {
      return const [];
    }
  }

  Future<void> remember(RecentItem item) async {
    state = [item, ...state.where((e) => !(e.kind == item.kind && e.id == item.id))].take(keep).toList();
    await _save();
  }

  Future<void> clear() async {
    state = const [];
    await _save();
  }

  Future<void> _save() => ref.read(sharedPreferencesProvider).setString(key, jsonEncode([for (final e in state) e.toJson()]));
}

final recentSearchesProvider = NotifierProvider<RecentSearches, List<RecentItem>>(RecentSearches.new);

class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key});

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _field = TextEditingController();
  final _focus = FocusNode();
  Timer? _debounce;
  int _request = 0;

  String _query = '';
  bool _loading = false;
  ApiException? _error;
  List<Customer> _customers = const [];
  List<Contract> _contracts = const [];

  @override
  void dispose() {
    _debounce?.cancel();
    _field.dispose();
    _focus.dispose();
    super.dispose();
  }

  void _typed(String value) {
    _debounce?.cancel();
    final query = value.trim();
    setState(() => _query = query);

    if (query.isEmpty) {
      _request++;
      setState(() {
        _loading = false;
        _error = null;
        _customers = const [];
        _contracts = const [];
      });

      return;
    }

    _debounce = Timer(const Duration(milliseconds: 280), () => _run(query));
  }

  Future<void> _run(String query) async {
    final request = ++_request;
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final api = ref.read(apiProvider);
      final results = await Future.wait<Object>([api.customers(query: query), api.contracts(status: 'all', query: query)]);
      if (!mounted || request != _request) return;

      setState(() {
        _customers = (results[0] as Paged<Customer>).items;
        _contracts = (results[1] as Paged<Contract>).items;
        _loading = false;
      });
    } on ApiException catch (e) {
      if (!mounted || request != _request) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  /// Search sits above the bottom bar and the details inside it, so the result is opened from the screen search was
  /// started on: closing search first keeps one bar on screen, and Back returns to where the person was.
  Future<void> _open(RecentItem item) async {
    await ref.read(recentSearchesProvider.notifier).remember(item);
    if (!mounted) return;

    final router = GoRouter.of(context);
    router.pop();
    unawaited(router.push<void>(item.path));
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    final currency = account?.currency ?? '';
    final recents = ref.watch(recentSearchesProvider);

    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: TextField(
          controller: _field,
          focusNode: _focus,
          autofocus: true,
          onChanged: _typed,
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(
            hintText: context.t('Search customers and contracts'),
            border: InputBorder.none,
            enabledBorder: InputBorder.none,
            focusedBorder: InputBorder.none,
            filled: false,
            suffixIcon: _field.text.isEmpty
                ? null
                : IconButton(
                    tooltip: context.t('Clear'),
                    icon: const Icon(Icons.close),
                    onPressed: () {
                      _field.clear();
                      _typed('');
                      _focus.requestFocus();
                    },
                  ),
          ),
        ),
      ),
      body: ContentColumn(
        child: _query.isEmpty
            ? _Recents(items: recents, onOpen: _open, onClear: () => ref.read(recentSearchesProvider.notifier).clear())
            : _loading && _customers.isEmpty && _contracts.isEmpty
                ? const Padding(padding: EdgeInsets.only(top: 16), child: QSkeletonList(rows: 5))
                : _error != null
                    ? QErrorView(message: errorMessage(context, _error!), retryLabel: context.t('Try again'), onRetry: () => _run(_query))
                    : _customers.isEmpty && _contracts.isEmpty
                        ? QEmpty(icon: Icons.search_off, title: context.t('Nothing found for “:term”', {'term': _query}), message: context.t('Check the spelling, or try part of a phone number or a contract number.'))
                        : ListView(
                            padding: const EdgeInsets.only(bottom: 32),
                            children: [
                              if (_customers.isNotEmpty) ...[
                                _Heading(context.t('Customers')),
                                for (final customer in _customers)
                                  ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    leading: InitialsAvatar(customer.name, size: 42),
                                    title: Text(customer.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                                    subtitle: Directionality(textDirection: TextDirection.ltr, child: Text(customer.phone, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                                    trailing: customer.owed == null || !customer.owed!.isPositive ? null : EndAmount(child: MoneyText(customer.owed!, currency, style: text.titleSmall)),
                                    onTap: () => _open(RecentItem(kind: 'customer', id: customer.id, title: customer.name, subtitle: customer.phone)),
                                  ),
                              ],
                              if (_contracts.isNotEmpty) ...[
                                _Heading(context.t('Contracts')),
                                for (final contract in _contracts)
                                  ListTile(
                                    contentPadding: EdgeInsets.zero,
                                    leading: Container(
                                      width: 42,
                                      height: 42,
                                      decoration: BoxDecoration(color: c.surfaceAlt, shape: BoxShape.circle),
                                      child: Icon(Icons.description_outlined, color: c.accentText, size: 20),
                                    ),
                                    title: Directionality(textDirection: TextDirection.ltr, child: Text(contract.reference, style: text.titleSmall)),
                                    subtitle: Text(contract.customerName ?? '', maxLines: 1, overflow: TextOverflow.ellipsis),
                                    trailing: ContractStateBadge(contract.state),
                                    onTap: () => _open(RecentItem(kind: 'contract', id: contract.id, title: contract.reference, subtitle: contract.customerName ?? '')),
                                  ),
                              ],
                            ],
                          ),
      ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.title);

  final String title;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 18, bottom: 4),
        child: Text(title, style: Theme.of(context).textTheme.labelLarge?.copyWith(color: context.qc.inkMuted, letterSpacing: 0.4)),
      );
}

class _Recents extends StatelessWidget {
  const _Recents({required this.items, required this.onOpen, required this.onClear});

  final List<RecentItem> items;
  final ValueChanged<RecentItem> onOpen;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    if (items.isEmpty) {
      return QEmpty(
        icon: Icons.search,
        title: context.t('Find anyone in a moment'),
        message: context.t('Type a name, a phone number or a contract number.'),
      );
    }

    return ListView(
      padding: const EdgeInsets.only(bottom: 32),
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Row(
            children: [
              Expanded(child: _Heading(context.t('Recent'))),
              TextButton(onPressed: onClear, child: Text(context.t('Clear'))),
            ],
          ),
        ),
        for (final item in items)
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: item.kind == 'contract'
                ? Container(width: 42, height: 42, decoration: BoxDecoration(color: c.surfaceAlt, shape: BoxShape.circle), child: Icon(Icons.history, color: c.accentText, size: 20))
                : InitialsAvatar(item.title, size: 42),
            title: Text(item.title, maxLines: 1, overflow: TextOverflow.ellipsis),
            subtitle: item.subtitle.isEmpty ? null : Text(item.subtitle, style: text.bodySmall?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis),
            onTap: () => onOpen(item),
          ),
      ],
    );
  }
}
