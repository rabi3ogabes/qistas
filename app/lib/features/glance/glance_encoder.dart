import 'package:intl/intl.dart';

import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import 'glance_data.dart';
import 'glance_privacy.dart';

/// The keys the Android widget reads, all strings, and nothing else. This is the contract with the Kotlin side:
///
/// - `enabled` "1" / "0", `rtl` "1" / "0"
/// - `due_line`, `late_line`: "Due today: 3", "Late: 2" in the app's language
/// - `amount_line`: the two amounts in one line, empty when amounts are hidden
/// - `next_1`, `next_2`, `next_3`: "Ahmad Salem · SAR 275.00 · Oct 7"; empty when hidden or there is none
/// - `updated`: the time of this refresh ("09:05"), `updated_epoch_ms`: the same moment in epoch milliseconds
///
/// Everything is already in the app's language, so the native code needs no translations. When `enabled` is "0" the
/// only text is `due_line`: "Open Qistas to refresh".
const List<String> glanceKeys = ['enabled', 'rtl', 'due_line', 'late_line', 'amount_line', 'next_1', 'next_2', 'next_3', 'updated', 'updated_epoch_ms'];

/// How many instalments the widget lists.
const int glanceRows = 3;

/// What to hand the widget for [data]. **The only place the privacy rules are applied** ([GlancePrivacy.amountsShown]
/// and [GlancePrivacy.namesShown]): with the app lock on, only the counts leave this function.
Map<String, String> encodeGlance(
  GlanceData data, {
  required GlancePrivacy privacy,
  required bool appLockOn,
  required String language,
  required bool rtl,
  required Translations words,
  required DateTime now,
}) {
  final stamp = {'updated': westernDigits(DateFormat.Hm(language).format(now.toLocal())), 'updated_epoch_ms': '${now.millisecondsSinceEpoch}'};

  if (!data.enabled) {
    return {
      'enabled': '0',
      'rtl': rtl ? '1' : '0',
      'due_line': words.t('Open Qistas to refresh'),
      'late_line': '',
      'amount_line': '',
      for (var i = 1; i <= glanceRows; i++) 'next_$i': '',
      ...stamp,
    };
  }

  final amounts = privacy.amountsShown(appLockOn: appLockOn);
  final names = privacy.namesShown(appLockOn: appLockOn);
  final currency = data.currency.isEmpty ? null : data.currency;

  String row(GlanceItem item) {
    // Names need amounts, so no amounts means nothing to say about any instalment.
    if (!amounts) return '';

    final who = names && (item.name?.isNotEmpty ?? false) ? item.name! : item.reference;

    return [who, item.amount.format(currency), _day(item.dueOn, language)].join(' · ');
  }

  String amount(Money money) => money.format(currency);

  return {
    'enabled': '1',
    'rtl': rtl ? '1' : '0',
    'due_line': words.t('Due today: :count', {'count': data.dueCount}),
    'late_line': words.t('Late: :count', {'count': data.lateCount}),
    'amount_line': amounts ? words.t('Due :due · Late :late', {'due': amount(data.dueAmount), 'late': amount(data.lateAmount)}) : '',
    for (var i = 0; i < glanceRows; i++) 'next_${i + 1}': i < data.next.length ? row(data.next[i]) : '',
    ...stamp,
  };
}

/// "Oct 7" in the reader's language, Western digits. A date nobody can read is shown as it came.
String _day(String iso, String language) {
  final date = DateTime.tryParse(iso);

  return date == null ? iso : westernDigits(DateFormat.MMMd(language).format(date));
}
