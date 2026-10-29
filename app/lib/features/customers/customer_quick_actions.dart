import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import '../reminders/reminders.dart';
import 'customer_account.dart';
import 'customer_detail_screen.dart';
import 'customers_screen.dart';

/// What a long press or a swipe on a customer's row can do.
enum CustomerQuickAction { call, whatsapp, pay, open, pin }

/// Takes a payment from the customer list: on the contract that needs it most (late, then running), after the same
/// sheet the customer's screen uses. Says so when nothing is running.
Future<void> payFromList(BuildContext context, WidgetRef ref, Customer customer) async {
  final account = ref.read(accountProvider);
  final Customer full;
  try {
    full = await ref.read(customerProvider(customer.id).future);
  } on ApiException catch (e) {
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    return;
  }
  if (!context.mounted) return;

  final contract = chosenContract(full, null);
  if (contract == null || !contract.takesPayments) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t(':name has no running contract to pay on.', {'name': customer.name}))));
    return;
  }
  await recordOnAccount(context, ref, customer: full, contractId: contract.id, currency: account?.currency ?? '');
  unawaited(ref.read(customersListProvider.notifier).refresh());
}

/// Carries out one quick action on a customer.
Future<void> runQuickAction(BuildContext context, WidgetRef ref, Customer customer, CustomerQuickAction action, {required VoidCallback onPin}) async {
  final country = ref.read(accountProvider)?.country ?? '';
  switch (action) {
    case CustomerQuickAction.call:
      await callNumber(context, customer.phone);
    case CustomerQuickAction.whatsapp:
      await sendWhatsApp(context, phone: customer.phone, country: country, message: '');
    case CustomerQuickAction.pay:
      await payFromList(context, ref, customer);
    case CustomerQuickAction.open:
      await context.push('/customers/${customer.id}');
    case CustomerQuickAction.pin:
      onPin();
  }
}

/// The long-press menu: everything a swipe does, and more, for anyone who does not swipe.
Future<CustomerQuickAction?> showQuickActionsSheet(BuildContext context, Customer customer, {required bool canWrite}) => showModalBottomSheet<CustomerQuickAction>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      builder: (context) {
        final hasPhone = customer.phone.trim().isNotEmpty;
        final running = (customer.runningContracts ?? 0) > 0;
        Widget item(CustomerQuickAction action, IconData icon, String label) =>
            ListTile(key: ValueKey('quick-${action.name}'), leading: Icon(icon), title: Text(label), onTap: () => Navigator.of(context).pop(action));

        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: Text(customer.name, style: Theme.of(context).textTheme.titleMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
              if (hasPhone) item(CustomerQuickAction.call, Icons.call_outlined, context.t('Call')),
              if (hasPhone) item(CustomerQuickAction.whatsapp, Icons.chat_outlined, context.t('WhatsApp')),
              if (canWrite && running) item(CustomerQuickAction.pay, Icons.account_balance_wallet_outlined, context.t('Record a payment')),
              item(CustomerQuickAction.open, Icons.person_outline, context.t('Open their page')),
              if (canWrite) item(CustomerQuickAction.pin, customer.pinned ? Icons.push_pin_rounded : Icons.push_pin_outlined, customer.pinned ? context.t('Unpin') : context.t('Pin to the top')),
              const SizedBox(height: 8),
            ],
          ),
        );
      },
    );

/// A row that swipes: towards the end to call, towards the start to take a payment. The row springs back after the
/// action, so nothing in the list ever disappears; a light tap of haptics confirms it was understood.
class SwipeActions extends StatelessWidget {
  const SwipeActions({super.key, required this.id, required this.child, this.onCall, this.onPay});

  final String id;
  final Widget child;
  final VoidCallback? onCall;
  final VoidCallback? onPay;

  @override
  Widget build(BuildContext context) {
    if (onCall == null && onPay == null) return child;

    final c = context.qc;
    final direction = onCall != null && onPay != null
        ? DismissDirection.horizontal
        : onCall != null
            ? DismissDirection.startToEnd
            : DismissDirection.endToStart;

    return Dismissible(
      key: ValueKey('swipe-$id'),
      direction: direction,
      dismissThresholds: const {DismissDirection.startToEnd: 0.32, DismissDirection.endToStart: 0.32},
      movementDuration: const Duration(milliseconds: 260),
      confirmDismiss: (swiped) async {
        unawaited(HapticFeedback.mediumImpact());
        if (swiped == DismissDirection.startToEnd) {
          onCall?.call();
        } else {
          onPay?.call();
        }

        return false;
      },
      background: _SwipeBackground(icon: Icons.call_rounded, label: context.t('Call'), color: c.positive, start: true),
      secondaryBackground: _SwipeBackground(icon: Icons.account_balance_wallet_rounded, label: context.t('Record a payment'), color: c.accentText, start: false),
      child: child,
    );
  }
}

class _SwipeBackground extends StatelessWidget {
  const _SwipeBackground({required this.icon, required this.label, required this.color, required this.start});

  final IconData icon;
  final String label;
  final Color color;
  final bool start;

  @override
  Widget build(BuildContext context) {
    final onColor = Colors.white;

    return Container(
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(QistasMetrics.radiusLg)),
      padding: const EdgeInsets.symmetric(horizontal: 22),
      alignment: start ? AlignmentDirectional.centerStart : AlignmentDirectional.centerEnd,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: onColor),
          const SizedBox(width: 10),
          Text(label, style: Theme.of(context).textTheme.labelLarge?.copyWith(color: onColor, fontWeight: FontWeight.w700)),
        ],
      ),
    );
  }
}

/// The book at a glance over the list: customers, this month's instalments, still owed, and late (in red when any).
class BookTotals extends StatelessWidget {
  const BookTotals({super.key, required this.customers, required this.board, required this.currency});

  final int customers;
  final Dashboard board;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final late = board.overdue.isPositive;
    final figures = <(String, String, String, bool)>[
      ('customers', context.t('Customers'), '$customers', false),
      ('due-this-month', context.t('Instalments this month'), board.expectedThisMonth.format(currency), false),
      ('outstanding', context.t('Still owed'), board.outstanding.format(currency), false),
      ('overdue', context.t('Late'), board.overdue.format(currency), late),
    ];

    return Container(
      key: const ValueKey('book-totals'),
      decoration: BoxDecoration(
        color: c.line,
        borderRadius: BorderRadius.circular(QistasMetrics.radiusLg),
        border: Border.all(color: c.line),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          for (final pair in [figures.sublist(0, 2), figures.sublist(2)])
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (final (index, (key, label, value, danger)) in pair.indexed) ...[
                    if (index > 0) const SizedBox(width: 1),
                    Expanded(child: _Figure(key: ValueKey('book-$key'), label: label, value: value, danger: danger)),
                  ],
                ],
              ),
            ),
        ].expand((row) => [row, const SizedBox(height: 1)]).toList()..removeLast(),
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({super.key, required this.label, required this.value, required this.danger});

  final String label;
  final String value;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
      color: danger ? Color.alphaBlend(c.danger.withValues(alpha: 0.08), c.surface) : c.surface,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: text.labelSmall?.copyWith(color: c.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis),
          const SizedBox(height: 4),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Text(value, style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700, color: danger ? c.danger : c.ink, fontFeatures: const [FontFeature.tabularFigures()])),
            ),
          ),
        ],
      ),
    );
  }
}
