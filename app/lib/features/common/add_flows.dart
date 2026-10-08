import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/translations.dart';
import '../billing/upgrade_sheet.dart';

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
