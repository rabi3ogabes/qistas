import '../../data/models.dart';

/// Win Plan PP6: who may do what to a contract's plan, and when. Settling early and changing the plan give up money or
/// change the terms, so they are the owner's or a manager's, on a running contract with a schedule, once the platform
/// has the switch on. Choosing what a payment covers is for anyone who takes payments.
bool canSettleEarly(Account? account, Contract contract) =>
    (account?.canDelete ?? false) && contract.isRunning && contract.type == 'scheduled' && (account?.entitlements['early_settlement']?.isOn ?? false);

bool canChangePlan(Account? account, Contract contract) =>
    (account?.canDelete ?? false) && contract.isRunning && contract.type == 'scheduled' && (account?.entitlements['reschedule']?.isOn ?? false);

bool canTargetPayments(Account? account) => account?.entitlements['payment_targeting']?.isOn ?? false;
