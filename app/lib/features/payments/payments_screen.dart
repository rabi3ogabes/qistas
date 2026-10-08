import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/paged.dart';
import '../../data/models.dart';
import '../contracts/contract_detail_screen.dart';
import 'payments_state.dart';

/// The ledger across every contract: money in, newest first, with a way to open the contract behind each line.
class PaymentsScreen extends ConsumerWidget {
  const PaymentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final account = ref.watch(accountProvider);
    final language = ref.watch(localeProvider);
    final currency = account?.currency ?? '';

    return SectionScaffold(
      title: context.t('Payments'),
      body: PagedListView<LedgerLine>(
        provider: paymentsListProvider,
        separated: false,
        empty: QEmpty(
          icon: Icons.account_balance_wallet_outlined,
          title: context.t('No payments yet'),
          message: context.t('Payments you record on a contract appear here, newest first.'),
          action: QButton(label: context.t('Go to contracts'), kind: QButtonKind.quiet, expand: false, onPressed: () => context.go('/contracts')),
        ),
        itemBuilder: (context, line) => Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: QCard(
            padding: EdgeInsets.zero,
            onTap: line.contractId == null ? null : () => context.push('/contracts/${line.contractId}'),
            child: PaymentLine(
              line: line,
              currency: currency,
              language: language,
              showContract: true,
              onVoid: account?.canDelete == true && line.canVoid ? () => voidPaymentFlow(context, ref, line, currency: currency) : null,
            ),
          ),
        ),
      ),
    );
  }
}
