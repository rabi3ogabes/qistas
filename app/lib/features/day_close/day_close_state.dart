import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

import '../../app/providers.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/paged.dart';
import '../../data/day_close.dart';
import '../../data/models.dart';

// The end of the day on the phone (daily-habit wave A, H4): what decides whether it is offered, what it reads, how the
// counted cash is typed, and the plain words for the difference. The server works every figure out; nothing here does
// more than a hint.

/// Whether the end-of-day close is switched on for this business: only when the server lists it and says it is on, so a
/// server that does not know it, or a platform or plan that has it off, shows nothing and asks for nothing.
bool dayCloseOn(Account? account) => account?.entitlements['day_close']?.isOn ?? false;

/// Whether this person is offered the close on Today: it is on, and they are not a viewer.
bool offersDayClose(Account? account) => dayCloseOn(account) && (account?.canWrite ?? false);

/// Today's cash to count and today's close, for the end-of-day screen. It lives only while that screen is open, so every
/// visit reads the figures again: money recorded since the morning is in them.
final dayCloseTodayProvider = FutureProvider.autoDispose<DayCloseToday>((ref) => ref.watch(apiProvider).dayCloseToday());

/// The same answer for the tile on Today, which only needs the closing time and whether the day is closed. Read only
/// while the feature is on and the person may close; with the feature off nothing asks.
final dayCloseTileProvider = FutureProvider.autoDispose<DayCloseToday>((ref) => ref.watch(apiProvider).dayCloseToday());

/// Tells everything that reads the day to look again, through the container rather than a widget's `ref`: a close or a
/// reopening can be answered after the screen that asked has gone, and a widget's `ref` is unusable by then.
void refreshDayClose(ProviderContainer container) {
  container
    ..invalidate(dayCloseTodayProvider)
    ..invalidate(dayCloseTileProvider)
    ..invalidate(dayCloseHistoryProvider);
}

/// Every close, newest day first.
class DayCloseHistory extends PagedNotifier<DayCloseRecord> {
  @override
  Future<Paged<DayCloseRecord>> fetch(int page) => ref.read(apiProvider).dayCloseHistory(page: page);
}

final dayCloseHistoryProvider = NotifierProvider<DayCloseHistory, PagedState<DayCloseRecord>>(DayCloseHistory.new);

/// Hands a sentence to the phone's share sheet (WhatsApp, a message, any app). A provider, so tests see what was shared.
final textSharerProvider = Provider<Future<void> Function(String text, String subject)>(
  (ref) => (text, subject) => SharePlus.instance.share(ShareParams(text: text, subject: subject)),
);

/// Whether [now] is at or past the closing time ("HH:MM"). A time the app cannot read never counts as past.
bool pastClosingTime(DateTime now, String closingTime) {
  final parts = closingTime.split(':');
  final hour = parts.length == 2 ? int.tryParse(parts[0]) : null;
  final minute = parts.length == 2 ? int.tryParse(parts[1]) : null;
  if (hour == null || minute == null) return false;

  return now.hour * 60 + now.minute >= hour * 60 + minute;
}

/// The cash counted, as it is typed on the number pad: whole currency units, then up to two decimals. It becomes whole
/// cents only through [Money], so no double ever touches the amount.
@immutable
class CountedEntry {
  const CountedEntry([this.whole = '', this.fraction = '', this.point = false]);

  /// "No cash today": nothing in the till.
  static const CountedEntry noCash = CountedEntry('0');

  /// The most digits before the point: far more than a till holds, and what the amount still fits on a phone with.
  static const int maxWholeDigits = 12;

  /// The digits before the decimal point, without a leading zero (apart from a lone "0").
  final String whole;

  /// The digits after it, at most two.
  final String fraction;

  /// The decimal point has been pressed.
  final bool point;

  /// Nothing has been entered yet.
  bool get isEmpty => whole.isEmpty;

  /// The amount in whole cents, or null while nothing is entered. "0" is an amount: it is how a till with nothing in it
  /// is told.
  Money? get amount => isEmpty ? null : Money.parse('$whole.${fraction.padRight(2, '0')}');

  /// One key of the pad: a digit, the decimal point (".") or the delete key ("<").
  CountedEntry press(String key) {
    if (key == '<') return back;
    if (key == '.') return point ? this : CountedEntry(isEmpty ? '0' : whole, '', true);
    if (key.length != 1 || !'0123456789'.contains(key)) return this;

    if (point) return fraction.length >= 2 ? this : CountedEntry(whole, '$fraction$key', true);
    if (whole == '0') return key == '0' ? this : CountedEntry(key);

    return whole.length >= maxWholeDigits ? this : CountedEntry('$whole$key');
  }

  /// Takes the last key away: the decimals first, then the point, then the whole digits.
  CountedEntry get back {
    if (fraction.isNotEmpty) return CountedEntry(whole, fraction.substring(0, fraction.length - 1), true);
    if (point) return CountedEntry(whole);

    return whole.isEmpty ? this : CountedEntry(whole.substring(0, whole.length - 1));
  }

  /// What is typed, in Western digits with the thousands grouped: "3,100.5". Empty while nothing is entered.
  String get display {
    if (isEmpty) return '';
    final grouped = whole.replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');

    return point ? '$grouped.$fraction' : grouped;
  }

  @override
  bool operator ==(Object other) => other is CountedEntry && other.whole == whole && other.fraction == fraction && other.point == point;

  @override
  int get hashCode => Object.hash(whole, fraction, point);
}

/// How the counted cash stands against what the day expects.
enum CashStanding { balanced, short, over }

/// Counted less expected, in whole cents: negative is short, positive is over.
CashStanding standingOf(Money difference) => difference.isZero ? CashStanding.balanced : (difference.isNegative ? CashStanding.short : CashStanding.over);

/// The difference in plain words, the server's own: "Balanced", "Short by SAR 12.50", "Over by SAR 12.50".
String differenceWords(BuildContext context, Money difference, String currency) => switch (standingOf(difference)) {
      CashStanding.balanced => context.t('Balanced'),
      CashStanding.short => context.t('Short by :amount', {'amount': (-difference).format(currency)}),
      CashStanding.over => context.t('Over by :amount', {'amount': difference.format(currency)}),
    };
