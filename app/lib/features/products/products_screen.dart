import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';

/// The products to pick from when opening a contract.
final productsProvider = FutureProvider.autoDispose<List<Product>>((ref) => ref.watch(apiProvider).products());

/// Contract details are drawn once the server lists the feature and it is on (an older server has no products).
bool showsContractDetails(Account? account) => account?.entitlements['contract_items']?.isOn ?? false;

/// Win Plan PP7: what the shop sells, with a price and a cost, to pick from when opening a contract. No stock is counted.
class ProductsScreen extends ConsumerWidget {
  const ProductsScreen({super.key});

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final added = await showModalBottomSheet<bool>(context: context, isScrollControlled: true, useSafeArea: true, showDragHandle: true, builder: (_) => const _ProductSheet());
    if (added == true) ref.invalidate(productsProvider);
  }

  Future<void> _archive(BuildContext context, WidgetRef ref, Product product) async {
    try {
      await ref.read(apiProvider).archiveProduct(product.id);
      ref.invalidate(productsProvider);
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final products = ref.watch(productsProvider);
    final account = ref.watch(accountProvider);
    final currency = account?.currency ?? '';
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final canManage = account?.canWrite ?? false;

    return SectionScaffold(
      title: context.t('Products'),
      showAccount: false,
      actions: [if (canManage) IconButton(tooltip: context.t('Add a product'), icon: const Icon(Icons.add_rounded), onPressed: () => _add(context, ref))],
      body: products.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(productsProvider)),
        data: (list) => list.isEmpty
            ? QEmpty(
                icon: Icons.inventory_2_outlined,
                title: context.t('No products yet'),
                message: context.t('Add what you sell most, with its price, and pick it when you open a contract.'),
                action: canManage ? QButton(label: context.t('Add a product'), expand: false, icon: Icons.add, onPressed: () => _add(context, ref)) : null,
              )
            : RefreshIndicator(
                onRefresh: () => ref.refresh(productsProvider.future),
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
                  children: [
                    QCard(
                      padding: EdgeInsets.zero,
                      child: Column(
                        children: [
                          for (final (index, product) in list.indexed) ...[
                            if (index > 0) const Divider(height: 1),
                            ListTile(
                              title: Text(product.name),
                              subtitle: product.cost == null ? null : Text(context.t('Cost :amount', {'amount': product.cost!.format(currency)}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  if (product.defaultPrice != null) MoneyText(product.defaultPrice!, currency, style: text.titleSmall),
                                  if (canManage)
                                    PopupMenuButton<String>(
                                      tooltip: context.t('More'),
                                      onSelected: (_) => _archive(context, ref, product),
                                      itemBuilder: (context) => [PopupMenuItem(value: 'archive', child: Text(context.t('Archive')))],
                                    ),
                                ],
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}

class _ProductSheet extends ConsumerStatefulWidget {
  const _ProductSheet();

  @override
  ConsumerState<_ProductSheet> createState() => _ProductSheetState();
}

class _ProductSheetState extends ConsumerState<_ProductSheet> {
  final _name = TextEditingController();
  final _price = TextEditingController();
  final _cost = TextEditingController();
  bool _saving = false;
  Map<String, List<String>> _fields = const {};

  @override
  void dispose() {
    for (final controller in [_name, _price, _cost]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_name.text.trim().isEmpty) {
      setState(() => _fields = {'name': [context.t('Enter a name.')]});
      return;
    }
    String? amount(TextEditingController controller) => controller.text.trim().isEmpty ? null : Money.parseTyped(controller.text)?.toDecimalString();

    setState(() => _saving = true);
    try {
      await ref.read(apiProvider).createProduct(name: _name.text, defaultPrice: amount(_price), cost: amount(_cost));
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _saving = false;
          _fields = e.fields.isEmpty ? {'name': [errorMessage(context, e)]} : e.fields;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t('Add a product'), style: Theme.of(context).textTheme.titleLarge),
              const SizedBox(height: 16),
              QField(controller: _name, label: context.t('Name'), errorText: _fields['name']?.firstOrNull, autofocus: true, enabled: !_saving, maxLength: 120),
              const SizedBox(height: 12),
              QField(controller: _price, label: context.t('Price (optional)'), errorText: _fields['default_price']?.firstOrNull, keyboardType: const TextInputType.numberWithOptions(decimal: true), latin: true, enabled: !_saving),
              const SizedBox(height: 12),
              QField(controller: _cost, label: context.t('What it costs you (optional)'), errorText: _fields['cost']?.firstOrNull, keyboardType: const TextInputType.numberWithOptions(decimal: true), latin: true, enabled: !_saving),
              const SizedBox(height: 20),
              QButton(label: context.t('Add product'), icon: Icons.check, loading: _saving, onPressed: _save),
            ],
          ),
        ),
      );
}
