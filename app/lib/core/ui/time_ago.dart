import 'package:flutter/widgets.dart';

import '../l10n/formats.dart';
import '../l10n/translations.dart';

/// How long before [now] something happened, in the units that fit: "Just now", "5 minutes ago", "3 hours ago", "2 days ago".
/// From a week on, the date. A moment slightly ahead of [now] (a phone whose clock runs behind the server's) is "Just now".
/// [now] is passed in, so a test can hold the clock still.
String timeAgo(BuildContext context, DateTime moment, DateTime now, String language) {
  final elapsed = now.difference(moment);
  if (elapsed < const Duration(minutes: 1)) return context.t('Just now');

  if (elapsed < const Duration(hours: 1)) {
    final minutes = elapsed.inMinutes;

    return minutes == 1 ? context.t('1 minute ago') : context.t(':count minutes ago', {'count': minutes});
  }
  if (elapsed < const Duration(days: 1)) {
    final hours = elapsed.inHours;

    return hours == 1 ? context.t('1 hour ago') : context.t(':count hours ago', {'count': hours});
  }
  if (elapsed < const Duration(days: 7)) {
    final days = elapsed.inDays;

    return days == 1 ? context.t('1 day ago') : context.t(':count days ago', {'count': days});
  }

  return formatMoment(moment, language);
}
