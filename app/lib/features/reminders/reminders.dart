import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/providers.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../data/models.dart';

/// The international dialling code of each country a business can start in. A number written the local way
/// (a leading 0) is completed with its business's own code, so WhatsApp can find it.
const Map<String, String> dialCodes = {
  'SA': '966', 'AE': '971', 'QA': '974', 'KW': '965', 'BH': '973', 'OM': '968', 'EG': '20', 'JO': '962', 'LB': '961', 'IQ': '964',
  'MA': '212', 'DZ': '213', 'TN': '216', 'LY': '218', 'SD': '249', 'YE': '967', 'SY': '963', 'MR': '222', 'TR': '90', 'PK': '92',
  'IN': '91', 'BD': '880', 'MY': '60', 'ID': '62', 'FR': '33', 'ES': '34', 'DE': '49', 'IT': '39', 'GB': '44', 'US': '1',
  'CA': '1', 'AU': '61', 'NG': '234', 'KE': '254', 'ZA': '27',
};

/// Digits only, with the country code in front: what `wa.me` wants. Null when the number is too short to be one.
String? whatsappNumber(String phone, String country) {
  final trimmed = phone.trim();
  var digits = trimmed.replaceAll(RegExp(r'[^\d]'), '');
  if (digits.length < 7) return null;

  if (trimmed.startsWith('+')) return digits;
  if (digits.startsWith('00')) return digits.substring(2);

  final code = dialCodes[country];
  if (code != null && digits.startsWith('0')) digits = '$code${digits.substring(1)}';

  return digits;
}

/// Words for a customer, in the app's language: a friendly reminder, or a firmer one once an instalment is late.
String reminderMessage(BuildContext context, DueItem item, {required String currency, required String business, required String language}) {
  final params = {
    'name': item.customerName,
    'amount': item.amount.format(currency),
    'date': formatDay(item.dueDate, language),
    'reference': item.reference,
    'business': business,
  };

  return item.isOverdue
      ? context.t('Hello :name, :amount for contract :reference has been overdue since :date. Could you settle it soon? Thank you. :business', params)
      : context.t('Hello :name, a friendly reminder that :amount is due on :date for contract :reference. Thank you. :business', params);
}

/// Words for a customer after a payment: what was received and what is left.
String receiptMessage(
  BuildContext context, {
  required String name,
  required Money amount,
  required Money? remaining,
  required String reference,
  required String currency,
  required String business,
  required DateTime paidAt,
  required String language,
}) {
  final params = {
    'name': name,
    'amount': amount.format(currency),
    'date': formatMoment(paidAt, language),
    'reference': reference,
    'remaining': (remaining ?? Money.zero).format(currency),
    'business': business,
  };

  return remaining != null && remaining.isZero
      ? context.t('Hello :name, we received :amount on :date for contract :reference. It is now fully paid. Thank you. :business', params)
      : context.t('Hello :name, we received :amount on :date for contract :reference. Still to pay: :remaining. Thank you. :business', params);
}

Future<bool> _open(Uri uri) async {
  try {
    return await launchUrl(uri, mode: LaunchMode.externalApplication);
  } on Object {
    return false;
  }
}

/// Opens WhatsApp on a ready-written message, or says it could not.
Future<void> sendWhatsApp(BuildContext context, {required String phone, required String country, required String message}) async {
  final number = whatsappNumber(phone, country);
  if (number == null) return _problem(context);

  final opened = await _open(Uri.parse('https://wa.me/$number?text=${Uri.encodeComponent(message)}'));
  if (!opened && context.mounted) _problem(context);
}

Future<void> sendSms(BuildContext context, {required String phone, required String message}) async {
  final opened = await _open(Uri.parse('sms:${phone.replaceAll(RegExp(r'[^\d+]'), '')}?body=${Uri.encodeComponent(message)}'));
  if (!opened && context.mounted) _problem(context);
}

Future<void> callNumber(BuildContext context, String phone) async {
  final opened = await _open(Uri.parse('tel:${phone.replaceAll(RegExp(r'[^\d+]'), '')}'));
  if (!opened && context.mounted) _problem(context);
}

void _problem(BuildContext context) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Could not open that on this phone.'))));
}

/// "Remind Ahmad": the message that will be sent, and the ways to send it.
Future<void> showReminderSheet(BuildContext context, DueItem item) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => ReminderSheet(item: item),
    );

class ReminderSheet extends ConsumerWidget {
  const ReminderSheet({super.key, required this.item});

  final DueItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    final language = ref.watch(localeProvider);
    final currency = account?.currency ?? '';
    final country = account?.country ?? '';
    final message = reminderMessage(context, item, currency: currency, business: account?.businessName ?? '', language: language);

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 4, 24, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                InitialsAvatar(item.customerName, size: 48),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(context.t('Remind :name', {'name': item.customerName}), style: text.titleLarge, maxLines: 2, overflow: TextOverflow.ellipsis),
                      Directionality(textDirection: TextDirection.ltr, child: Text(item.phone.isEmpty ? '—' : item.phone, style: text.bodySmall?.copyWith(color: c.inkMuted))),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: c.surfaceAlt, borderRadius: BorderRadius.circular(QistasMetrics.radiusLg)),
              child: Text(message, style: text.bodyMedium),
            ),
            const SizedBox(height: 18),
            if (!item.hasPhone) ...[QNotice(context.t('This customer has no phone number yet. Add one to send a reminder.'), tone: QTone.warn, icon: Icons.phone_disabled_outlined), const SizedBox(height: 12)],
            QButton(
              label: context.t('Send on WhatsApp'),
              icon: Icons.chat_outlined,
              onPressed: item.hasPhone ? () => sendWhatsApp(context, phone: item.phone, country: country, message: message) : null,
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Expanded(child: QButton(label: context.t('SMS'), kind: QButtonKind.quiet, icon: Icons.sms_outlined, onPressed: item.hasPhone ? () => sendSms(context, phone: item.phone, message: message) : null)),
                const SizedBox(width: 10),
                Expanded(child: QButton(label: context.t('Call'), kind: QButtonKind.quiet, icon: Icons.call_outlined, onPressed: item.hasPhone ? () => callNumber(context, item.phone) : null)),
              ],
            ),
            const SizedBox(height: 10),
            QButton(
              label: context.t('Copy message'),
              kind: QButtonKind.text,
              icon: Icons.copy_outlined,
              onPressed: () async {
                await Clipboard.setData(ClipboardData(text: message));
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Message copied.'))));
                }
              },
            ),
          ],
        ),
      ),
    );
  }
}
