import 'package:flutter/material.dart';

import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../data/qistas_api.dart';

/// One thing being sold, as typed (Win Plan PP7).
class SoldItemRow {
  SoldItemRow({String name = '', int quantity = 1, String serial = '', String price = '', String cost = '', this.productId})
      : name = TextEditingController(text: name),
        quantity = TextEditingController(text: '$quantity'),
        serial = TextEditingController(text: serial),
        price = TextEditingController(text: price),
        cost = TextEditingController(text: cost);

  final TextEditingController name;
  final TextEditingController quantity;
  final TextEditingController serial;
  final TextEditingController price;
  final TextEditingController cost;
  String? productId;

  bool get isBlank => [name, serial, price, cost].every((c) => c.text.trim().isEmpty);

  int get count => int.tryParse(quantity.text.trim()) ?? 1;

  /// What the server needs, or null for a row left blank.
  ContractItemForm? toForm() {
    if (isBlank) return null;
    String amount(TextEditingController c) => c.text.trim().isEmpty ? '' : Money.parseTyped(c.text)?.toDecimalString() ?? c.text.trim();

    return ContractItemForm(name: name.text, quantity: count < 1 ? 1 : count, serial: serial.text.replaceAll(RegExp(r'\s'), ''), price: amount(price), cost: amount(cost), productId: productId);
  }

  void dispose() {
    for (final controller in [name, quantity, serial, price, cost]) {
      controller.dispose();
    }
  }
}

/// What was sold: numbered rows of what, how many, the serial or IMEI (with a scan button), price and cost.
class SoldItemsEditor extends StatelessWidget {
  const SoldItemsEditor({
    super.key,
    required this.rows,
    required this.enabled,
    required this.onChanged,
    required this.onAdd,
    required this.onPick,
    required this.onRemove,
    required this.onScan,
    this.error,
  });

  final List<SoldItemRow> rows;
  final bool enabled;
  final VoidCallback onChanged;
  final VoidCallback onAdd;
  final VoidCallback onPick;
  final ValueChanged<SoldItemRow> onRemove;
  final ValueChanged<SoldItemRow> onScan;
  final String? error;

  static const int maxItems = 10;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final (index, row) in rows.indexed)
          Padding(
            key: ObjectKey(row),
            padding: const EdgeInsets.only(bottom: 12),
            child: DecoratedBox(
              decoration: BoxDecoration(border: Border.all(color: c.line), borderRadius: BorderRadius.circular(QistasMetrics.radiusMd)),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 10, 4, 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Text(context.t('Item :number', {'number': index + 1}), style: text.labelLarge?.copyWith(color: c.inkMuted)),
                        const Spacer(),
                        IconButton(tooltip: context.t('Remove this item'), icon: Icon(Icons.close, color: c.inkMuted), onPressed: enabled ? () => onRemove(row) : null),
                      ],
                    ),
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 8),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(
                                flex: 3,
                                child: KeyedSubtree(key: ValueKey('item-name-$index'), child: QField(controller: row.name, label: context.t('What it is'), enabled: enabled, maxLength: 120, onChanged: (_) => onChanged())),
                              ),
                              const SizedBox(width: 8),
                              Expanded(child: QField(controller: row.quantity, label: context.t('Quantity'), keyboardType: TextInputType.number, latin: true, enabled: enabled, onChanged: (_) => onChanged())),
                            ],
                          ),
                          const SizedBox(height: 8),
                          QField(
                            controller: row.serial,
                            label: context.t('Serial or IMEI'),
                            latin: true,
                            enabled: enabled,
                            maxLength: 60,
                            suffix: IconButton(tooltip: context.t('Scan the serial or IMEI'), icon: const Icon(Icons.qr_code_scanner_rounded), onPressed: enabled ? () => onScan(row) : null),
                          ),
                          const SizedBox(height: 8),
                          Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Expanded(child: QField(controller: row.price, label: context.t('Price'), keyboardType: const TextInputType.numberWithOptions(decimal: true), latin: true, enabled: enabled, onChanged: (_) => onChanged())),
                              const SizedBox(width: 8),
                              Expanded(child: QField(controller: row.cost, label: context.t('Cost'), keyboardType: const TextInputType.numberWithOptions(decimal: true), latin: true, enabled: enabled)),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        if (error != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(error!, style: text.bodySmall?.copyWith(color: c.danger))),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            QButton(label: context.t('Add an item'), icon: Icons.add, kind: QButtonKind.quiet, expand: false, onPressed: enabled && rows.length < maxItems ? onAdd : null),
            QButton(label: context.t('Pick a product'), icon: Icons.inventory_2_outlined, kind: QButtonKind.quiet, expand: false, onPressed: enabled && rows.length < maxItems ? onPick : null),
          ],
        ),
      ],
    );
  }
}
