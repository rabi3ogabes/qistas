import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../data/models.dart';
import '../documents/document_options_sheet.dart';
import '../reminders/reminders.dart';


/// The moment after money is taken: what was received, what is left, and a receipt one tap from the customer.
Future<void> showPaymentSuccess(BuildContext context, {required Contract contract, required LedgerLine payment}) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => PaymentSuccessSheet(contract: contract, payment: payment),
    );

class PaymentSuccessSheet extends ConsumerStatefulWidget {
  const PaymentSuccessSheet({super.key, required this.contract, required this.payment});

  final Contract contract;
  final LedgerLine payment;

  @override
  ConsumerState<PaymentSuccessSheet> createState() => _PaymentSuccessSheetState();
}

class _PaymentSuccessSheetState extends ConsumerState<PaymentSuccessSheet> {
  bool _sending = false;

  Money? get _remaining => widget.payment.contractOwed;

  /// 0 to 1: how much of the contract is paid now.
  double get _paidFraction {
    final total = widget.contract.total;
    final remaining = _remaining;
    if (remaining == null || !total.isPositive) return 0;

    return ((total.cents.toDouble() - remaining.cents.toDouble()) / total.cents.toDouble()).clamp(0, 1).toDouble();
  }

  Future<void> _sendReceipt() async {
    final customerId = widget.contract.customerId;
    if (customerId == null || _sending) return;
    setState(() => _sending = true);

    final account = ref.read(accountProvider);
    final language = ref.read(localeProvider);
    try {
      final customer = await ref.read(apiProvider).customer(customerId);
      if (!mounted) return;

      final message = receiptMessage(
        context,
        name: customer.name,
        amount: widget.payment.amount,
        remaining: _remaining,
        reference: widget.contract.reference,
        currency: account?.currency ?? '',
        business: account?.businessName ?? '',
        paidAt: widget.payment.paidAt,
        language: language,
      );

      if (customer.phone.trim().isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('This customer has no phone number yet. Add one to send a reminder.'))));
      } else {
        await sendWhatsApp(context, phone: customer.phone, country: account?.country ?? '', message: message);
      }
    } on ApiException {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Something went wrong. Please try again.'))));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = ref.watch(accountProvider)?.currency ?? '';
    final settled = _remaining != null && _remaining!.isZero;
    final quiet = MediaQuery.disableAnimationsOf(context);

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TweenAnimationBuilder<double>(
              tween: Tween(begin: quiet ? 1 : 0, end: 1),
              duration: quiet ? Duration.zero : const Duration(milliseconds: 700),
              curve: Curves.elasticOut,
              builder: (context, scale, child) => Transform.scale(scale: scale.clamp(0, 1.2), child: child),
              child: Container(
                width: 76,
                height: 76,
                decoration: BoxDecoration(shape: BoxShape.circle, color: c.tintMint, border: Border.all(color: c.positive.withValues(alpha: 0.3), width: 2)),
                child: Icon(Icons.check_rounded, size: 40, color: c.positive),
              ),
            ),
            const SizedBox(height: 18),
            Text(context.t('Payment recorded'), style: text.labelLarge?.copyWith(color: c.inkMuted, letterSpacing: 0.4)),
            const SizedBox(height: 4),
            CountUpMoney(widget.payment.amount, currency, style: text.displaySmall?.copyWith(fontSize: 40, fontWeight: FontWeight.w700), color: c.ink),
            const SizedBox(height: 10),
            Text(
              settled ? context.t('This contract is now fully paid.') : context.t('Still owed on :reference: :amount', {'reference': widget.contract.reference, 'amount': (_remaining ?? Money.zero).format(currency)}),
              style: text.bodyLarge?.copyWith(color: settled ? c.positive : c.inkMuted, fontWeight: settled ? FontWeight.w600 : null),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 18),
            TweenAnimationBuilder<double>(
              tween: Tween(begin: quiet ? _paidFraction : 0, end: _paidFraction),
              duration: quiet ? Duration.zero : const Duration(milliseconds: 900),
              curve: Curves.easeOutCubic,
              builder: (context, value, _) => ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: LinearProgressIndicator(value: value, minHeight: 10, color: c.accent, backgroundColor: c.surfaceAlt),
              ),
            ),
            const SizedBox(height: 8),
            Text(context.t(':percent% of this contract is paid', {'percent': (_paidFraction * 100).round()}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 24),
            // The receipt as a PDF through the share sheet (WhatsApp, a printer, email), then the short message.
            QButton(label: context.t('Send receipt'), icon: Icons.receipt_long_outlined, kind: QButtonKind.gold, onPressed: () => showDocumentSheet(context, DocumentRequest.receipt(widget.payment.id, widget.contract.reference))),
            const SizedBox(height: 8),
            QButton(label: context.t('Send receipt on WhatsApp'), icon: Icons.chat_outlined, kind: QButtonKind.quiet, loading: _sending, onPressed: widget.contract.customerId == null ? null : _sendReceipt),
            const SizedBox(height: 8),
            QButton(label: context.t('Done'), kind: QButtonKind.text, onPressed: () => Navigator.of(context).pop()),
          ],
        ),
      ),
    );
  }
}
