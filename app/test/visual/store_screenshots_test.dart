import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/harness.dart';
import '../support/samples.dart';
import '../support/store_frame.dart';
import '../support/visual.dart';

/// The Google Play pictures (Sprint 2 Task 8, Win Plan 6.7): seven real screens in each of the five languages, each under
/// a large caption, with names and money as a shop in that listing's market would see them. They show only what is
/// switched on for everyone. Every run checks that each one lays out without overflow; with QISTAS_SCREENSHOTS set they
/// are also written out:
///
///     QISTAS_SCREENSHOTS=../docs/store/screenshots flutter test test/visual/store_screenshots_test.dart

/// One listing's language: its words, its market's names and money, and the caption of each picture.
class StoreLanguage {
  const StoreLanguage(this.code, {required this.currency, required this.perRiyal, required this.names, required this.captions});

  final String code;
  final String currency;

  /// How many of [currency] make a riyal, as a fraction, so the samples' riyal amounts read as local prices.
  final (int, int) perRiyal;

  /// The samples' names (and phone number), as shops in this market would write them.
  final Map<String, String> names;

  /// The caption of each picture, in the order of [_frames].
  final List<String> captions;

  /// The app's own word for [english], from its translations.
  String t(String english, [Map<String, String> args = const {}]) {
    var text = code == 'en' ? english : (_table[english] as String? ?? english);
    args.forEach((key, value) => text = text.replaceAll(':$key', value));

    return text;
  }

  Map<String, dynamic> get _table => _tables[code] ??= jsonDecode(File('assets/i18n/$code.json').readAsStringSync()) as Map<String, dynamic>;
  static final Map<String, Map<String, dynamic>> _tables = {};

  /// An answer from the server as this market's shop would get it: local names, its currency, its amounts.
  String localise(String body) {
    final Object? decoded;
    try {
      decoded = jsonDecode(body);
    } on FormatException {
      return body;
    }

    return jsonEncode(_localise(decoded, null));
  }

  static const _notMoney = {'markup_value', 'commission_percent', 'collection_rate'};
  static final _amount = RegExp(r'^-?\d+\.\d{2}$');

  Object? _localise(Object? value, String? key) => switch (value) {
        final Map<String, dynamic> map => {for (final entry in map.entries) entry.key: _localise(entry.value, entry.key)},
        final List<dynamic> list => [for (final item in list) _localise(item, key)],
        final String text when names.containsKey(text) => names[text],
        'SAR' => currency,
        final String text when !_notMoney.contains(key) && _amount.hasMatch(text) => _money(text),
        _ => value,
      };

  /// [riyals] in this market's money, as a shop types it: whole units.
  String whole(String riyals) => _money(riyals).split('.').first;

  String _money(String riyals) {
    final (times, per) = perRiyal;
    final cents = (int.parse(riyals.replaceAll('.', '')) * times / per).round();
    final sign = cents < 0 ? '-' : '';

    return '$sign${cents.abs() ~/ 100}.${(cents.abs() % 100).toString().padLeft(2, '0')}';
  }
}

const _english = StoreLanguage(
  'en',
  currency: 'AED',
  perRiyal: (1, 1),
  names: {
    'Ahmad Salem': 'Omar Farooq', 'Youssef Mansour': 'Hassan Raza', 'نورة السبيعي': 'Fatima Noor', 'Khalid Al-Harbi': 'Bilal Ahmed',
    'Layla Haddad': 'Sara Malik', 'Al-Fares Electronics': 'Al Noor Electronics', 'Riyadh': 'Dubai', '+966501234567': '+971501234567',
  },
  captions: [
    'Every installment, on time',
    'Remind a customer in one tap',
    'A plan in seconds, to the cent',
    'Payments land on the oldest installment first',
    'Statements with your logo, ready to share',
    'Safe, and yours alone',
    'Your language, day or night',
  ],
);

const _arabic = StoreLanguage(
  'ar',
  currency: 'SAR',
  perRiyal: (1, 1),
  names: {
    'Ahmad Salem': 'أحمد سالم', 'Youssef Mansour': 'يوسف منصور', 'Khalid Al-Harbi': 'خالد الحربي',
    'Layla Haddad': 'ليلى حداد', 'Al-Fares Electronics': 'معرض الفارس للإلكترونيات', 'Riyadh': 'الرياض',
  },
  captions: [
    'كل قسط في موعده',
    'ذكّر عميلك بلمسة واحدة',
    'خطة التقسيط في ثوانٍ، بدقة',
    'كل دفعة إلى أقدم قسط أولًا',
    'كشف حساب بشعارك، جاهز للمشاركة',
    'بياناتك محفوظة ولك وحدك',
    'بلغتك، ليلًا ونهارًا',
  ],
);

const _french = StoreLanguage(
  'fr',
  currency: 'MAD',
  perRiyal: (5, 2),
  names: {
    'Ahmad Salem': 'Karim Benali', 'Youssef Mansour': 'Youssef Amrani', 'نورة السبيعي': 'Nadia Cherif', 'Khalid Al-Harbi': 'Mehdi Ouali',
    'Layla Haddad': 'Leïla Haddad', 'Al-Fares Electronics': 'Électro Atlas', 'Riyadh': 'Casablanca', '+966501234567': '+212612345678',
  },
  captions: [
    'Chaque échéance, à temps',
    'Rappelez un client d’un geste',
    'Un plan en quelques secondes, au centime',
    'Chaque paiement va d’abord à l’échéance la plus ancienne',
    'Des relevés à votre logo, à partager',
    'En sécurité, et à vous seul',
    'Votre langue, de jour comme de nuit',
  ],
);

const _spanish = StoreLanguage(
  'es',
  currency: 'USD',
  perRiyal: (4, 15),
  names: {
    'Ahmad Salem': 'Carlos Méndez', 'Youssef Mansour': 'Javier Ruiz', 'نورة السبيعي': 'Ana Torres', 'Khalid Al-Harbi': 'Marta Gil',
    'Layla Haddad': 'Lucía Romero', 'Al-Fares Electronics': 'Electro Sol', 'Riyadh': 'Monterrey', '+966501234567': '+525512345678',
  },
  captions: [
    'Cada cuota, a tiempo',
    'Recuerda a un cliente con un toque',
    'Un plan en segundos, al céntimo',
    'Cada pago va primero a la cuota más antigua',
    'Estados de cuenta con tu logo, listos para enviar',
    'Seguro, y solo tuyo',
    'Tu idioma, de día o de noche',
  ],
);

const _urdu = StoreLanguage(
  'ur',
  currency: 'PKR',
  perRiyal: (75, 1),
  names: {
    'Ahmad Salem': 'احمد سلیم', 'Youssef Mansour': 'یوسف منصور', 'نورة السبيعي': 'نورین خان', 'Khalid Al-Harbi': 'بلال احمد',
    'Layla Haddad': 'سارہ ملک', 'Al-Fares Electronics': 'النور الیکٹرانکس', 'Riyadh': 'لاہور', '+966501234567': '+923001234567',
  },
  captions: [
    'ہر قسط، وقت پر',
    'ایک ٹچ میں گاہک کو یاد دہانی',
    'سیکنڈوں میں قسطوں کا پلان',
    'ہر ادائیگی پہلے سب سے پرانی قسط پر',
    'آپ کے لوگو کے ساتھ گوشوارے',
    'محفوظ، اور صرف آپ کا',
    'آپ کی زبان، دن ہو یا رات',
  ],
);

const _languages = [_english, _arabic, _french, _spanish, _urdu];

/// A busier day than the ordinary samples: a shop with a few dozen customers, mid-month.
Map<String, dynamic> _storeDashboard() => {
      ...dashboardJson(),
      'outstanding': '48250.00', 'overdue': '1980.00', 'collected_this_month': '12400.00', 'expected_this_month': '18400.00',
      'collected_last_month': '15100.00', 'active_customers': 37, 'collection_rate': '67.4',
      'due_today': [dueJson(), dueJson(contract: 'k4', reference: 'C-0012', name: 'Khalid Al-Harbi', amount: '450.00')],
      'overdue_list': [dueJson(contract: 'k2', reference: 'C-0003', name: 'Youssef Mansour', amount: '180.00', due: '2026-10-01', late: 6)],
      'upcoming': [dueJson(contract: 'k3', reference: 'C-0002', name: 'نورة السبيعي', amount: '600.00', due: '2026-10-09', until: 2)],
      'daily_collected': [
        for (var day = 0; day < 14; day++)
          {'date': '2026-09-${(24 + day).clamp(1, 30).toString().padLeft(2, '0')}', 'amount': const ['650.00', '0.00', '1200.00', '275.00', '0.00', '900.00', '450.00'][day % 7]},
      ],
    };

/// One picture: its file name, what the app keeps in its preferences, and how to reach the screen.
typedef _Frame = ({String name, Map<String, Object> preferences, bool appLock, Future<void> Function(WidgetTester, StoreLanguage) reach});

final List<_Frame> _frames = [
  (name: '1-today', preferences: const {}, appLock: false, reach: (tester, l) async {}),
  (name: '2-remind', preferences: const {}, appLock: false, reach: (tester, l) => tapText(tester, l.t('Remind'))),
  (
    name: '3-plan',
    preferences: const {},
    appLock: false,
    reach: (tester, l) async {
      await tapTooltip(tester, l.t('Quick actions'));
      await tapText(tester, l.t('Calculator'));
      await typeInto(tester, l.t('Price'), l.whole('3600.00'));
      await typeInto(tester, l.t('Down payment'), l.whole('600.00'));
      await typeInto(tester, l.t('Profit, %'), '10');
      // The keyboard down and the page back at the top, where the instalment is.
      FocusManager.instance.primaryFocus?.unfocus();
      tester.state<ScrollableState>(find.byType(Scrollable).first).position.jumpTo(0);
      await settle(tester);
    },
  ),
  (
    name: '4-contract',
    preferences: const {},
    appLock: false,
    reach: (tester, l) async {
      await tapText(tester, l.t('Contracts'), last: true);
      await tapText(tester, 'C-0007');
    },
  ),
  (
    name: '5-statement',
    preferences: const {},
    appLock: false,
    reach: (tester, l) async {
      await tapText(tester, l.t('Contracts'), last: true);
      await tapText(tester, 'C-0007');
      await tapTooltip(tester, l.t('Statement'));
    },
  ),
  (
    name: '6-app-lock',
    preferences: const {'app_lock.enabled': true},
    appLock: true,
    reach: (tester, l) async {
      await openSettings(tester);
      await tapText(tester, l.t('App lock'));
    },
  ),
  (name: '7-language', preferences: const {'theme_mode': 'dark'}, appLock: false, reach: (tester, l) => tapTooltip(tester, l.t('Language'))),
];

void main() {
  test('every language has a caption for every picture, short enough for two lines', () {
    for (final language in _languages) {
      expect(language.captions, hasLength(_frames.length), reason: language.code);
      expect(language.captions.every((caption) => caption.runes.length <= 56), isTrue, reason: language.code);
    }
  });

  for (final language in _languages) {
    for (final (index, frame) in _frames.indexed) {
      testWidgets('the ${language.code} store picture ${frame.name} lays out without overflow', (tester) async {
        // Pictures show real shadows; the flag is put back before the framework checks that nothing was left changed.
        debugDisableShadows = false;
        try {
          final server = workspaceServer(account: accountJson(plan: 'pro'), routes: {'GET /dashboard': always(json(200, {'data': _storeDashboard()}))})
            ..rewrite = language.localise;
          await pumpApp(
            tester,
            server,
            realFonts: true,
            size: StoreFrame.size,
            language: language.code,
            preferences: frame.preferences,
            deviceAuth: frame.appLock ? FakeDeviceAuth() : null,
            frame: (app) => StoreFrame(caption: language.captions[index], language: language.code, app: app),
          );
          await frame.reach(tester, language);
          await snapshot(tester, '${language.code}/${frame.name}');
        } finally {
          debugDisableShadows = true;
        }
      });
    }
  }
}
