import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../core/ui/paged.dart';
import '../../core/ui/reason_dialog.dart';
import '../../data/models.dart';
import '../contracts/contracts_screen.dart';
import '../customers/customer_detail_screen.dart';
import '../dashboard/dashboard_screen.dart';

/// The ledger: every payment and reversal, newest first.
class PaymentsList extends PagedNotifier<LedgerLine> {
  @override
  Future<Paged<LedgerLine>> fetch(int page) => ref.read(apiProvider).payments(page: page);
}

final paymentsListProvider = NotifierProvider<PaymentsList, PagedState<LedgerLine>>(PaymentsList.new);

/// Everything that depends on the ledger is stale after money moves: the lists, the dashboard, the contract.
void refreshAfterMoney(WidgetRef ref, {String? contractId, String? customerId}) {
  ref.read(paymentsListProvider.notifier).refresh();
  ref.read(contractsListProvider.notifier).refresh();
  ref.invalidate(dashboardProvider);
  if (contractId != null) ref.invalidate(contractProvider(contractId));
  if (customerId != null) ref.invalidate(customerProvider(customerId));
}

/// Reverses a payment after asking. The ledger keeps both lines: nothing is ever deleted.
Future<bool> voidPaymentFlow(BuildContext context, WidgetRef ref, LedgerLine payment, {required String currency}) async {
  final reason = await askReason(
    context,
    title: context.t('Void this payment?'),
    message: context.t('This adds a reversal of :amount to the ledger. The original payment stays visible, marked as voided.', {'amount': payment.amount.format(currency)}),
    confirmLabel: context.t('Void payment'),
  );
  if (reason == null || !context.mounted) return false;

  try {
    await ref.read(apiProvider).voidPayment(payment.id, reason: reason);
    refreshAfterMoney(ref, contractId: payment.contractId);
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Payment voided.'))));

    return true;
  } on ApiException catch (e) {
    if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));

    return false;
  }
}
