import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/features/glance/glance_privacy.dart';

import '../support/fake_api.dart';
import '../support/glance_phone.dart';
import '../support/glance_samples.dart';

/// The refresher in the state it is really in: a background isolate that `main()` never ran in (daily-habit wave A, H3).
///
/// Nothing here initialises date formatting, and nothing may: `intl` starts with no locale data at all, `main()` loads
/// it, and the isolate Firebase starts for a silent push has no `main()`. The other refresher tests load every locale in
/// `setUpAll` to build their expected values, which would hide a refresher that depends on `main()`; each test file runs
/// in an isolate of its own, so this one stays bare. Each language is its own test so that each one's formats run (the
/// first test of the file is the one that meets the bare isolate; the rest guard the formats of their language).

/// A day with real money in it: thousands separators, two decimals, a row with a date, so every formatter runs.
Map<String, dynamic> richDay() {
  final day = glanceJson(names: true, dueAmount: '1200.50', lateAmount: '31250.75');
  ((day['next'] as List<dynamic>).first as Map<String, dynamic>)['amount'] = '1234567.89';

  return day;
}

void main() {
  for (final (language, updated) in [('en', '09:05'), ('ar', '09:05'), ('es', '9:05'), ('fr', '09:05'), ('ur', '09:05')]) {
    test('it writes in $language on an isolate where nothing initialised date formatting', () async {
      final p = await phone(prefs: {'language': language, GlancePrivacyController.namesKey: true}, routes: {'GET /glance': (_) => json(200, {'data': richDay()})});

      expect(await p.refresher.run(), isTrue, reason: 'a refresher that needs main() to have run writes nothing, every morning');

      expect(p.store.writes, hasLength(1));
      expect(p.store.last['updated'], updated, reason: 'the time, in Western digits, as the language writes it');
      expect(p.store.last['amount_line'], contains('1,200.50'));
      expect(p.store.last['amount_line'], contains('31,250.75'));
      expect(p.store.last['next_1'], allOf(contains('Ahmad Salem'), contains('1,234,567.89')), reason: 'the row is formatted, and its date after it');
      expect(p.store.last['next_1'], isNot(endsWith(' · ')), reason: 'the day is not empty');
      expect(p.store.last['rtl'], language == 'ar' || language == 'ur' ? '1' : '0');
      expect(p.store.redraws, 1);
    });
  }

  test('it writes the disabled state on an isolate where nothing initialised date formatting', () async {
    // The disabled state stamps the time too, so it needs the same data.
    final p = await phone(prefs: {'language': 'ar'}, routes: {'GET /glance': (_) => json(200, {'data': {'enabled': false}})});

    expect(await p.refresher.run(), isTrue);

    expect(p.store.last['enabled'], '0');
    expect(p.store.last['updated'], '09:05');
  });
}
