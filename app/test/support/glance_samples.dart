import 'dart:convert';
import 'dart:io';

import 'package:qistas/core/l10n/translations.dart';
import 'package:qistas/features/glance/glance_data.dart';
import 'package:qistas/features/glance/glance_encoder.dart';
import 'package:qistas/features/glance/glance_privacy.dart';

/// What the glance tests share (daily-habit wave A, H3): the server's answer, the moment the widget is drawn, the app's
/// words in each language and the strings the widget should be handed for an answer.

/// `GET /glance` as the server writes it: three instalments coming, with customers' names only when asked for.
Map<String, dynamic> glanceJson({bool names = false, int due = 3, String dueAmount = '825.00', int late = 2, String lateAmount = '400.00', int rows = 3}) => {
      'enabled': true,
      'currency': 'SAR',
      'due_count': due,
      'due_amount': dueAmount,
      'late_count': late,
      'late_amount': lateAmount,
      'next': [
        for (final row in [
          ('C-0007', '275.00', '2026-10-07', 'Ahmad Salem'),
          ('C-0011', '150.00', '2026-10-07', 'Badr Khan'),
          ('C-0003', '400.00', '2026-10-09', 'Dalia Amin'),
        ].take(rows))
          {'reference': row.$1, 'amount': row.$2, 'due_on': row.$3, if (names) 'name': row.$4},
      ],
      'generated_at': '2026-10-07T06:05:00Z',
    };

final DateTime moment = DateTime(2026, 10, 7, 9, 5);

Translations wordsFor(String language) {
  if (language == 'en') return const Translations.english();
  final table = jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;

  return Translations(language, {for (final entry in table.entries) entry.key: entry.value.toString()});
}

Map<String, String> encode(
  Map<String, dynamic> json, {
  GlancePrivacy privacy = const GlancePrivacy(),
  bool lock = false,
  String language = 'en',
  DateTime? now,
}) {
  final words = wordsFor(language);

  return encodeGlance(GlanceData.fromJson(json), privacy: privacy, appLockOn: lock, language: language, rtl: words.isRtl, words: words, now: now ?? moment);
}
