import 'dart:convert';

import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';

import '../config.dart';

/// The app's words in one language. The English text is the key, exactly as on the website, so a sentence reads
/// the same everywhere and an untranslated one simply shows in English. Placeholders are written `:name`.
///
///     context.t('Welcome back')
///     context.t('Next instalment: :amount, due :date.', {'amount': '$275', 'date': '7 Dec'})
class Translations {
  const Translations(this.language, [this._table = const {}]);

  /// English: every sentence is its own translation.
  const Translations.english() : this('en');

  final String language;
  final Map<String, String> _table;

  bool get isRtl => AppConfig.rtlLocales.contains(language);

  Locale get locale => Locale(language);

  String t(String english, [Map<String, Object?> params = const {}]) {
    var text = _table[english] ?? english;
    params.forEach((name, value) => text = text.replaceAll(':$name', '$value'));

    return text;
  }

  /// Loads the translation file of a language shipped with the app (`assets/i18n/LANGUAGE.json`).
  static Future<Translations> load(String language, {AssetBundle? bundle}) async {
    if (language == 'en' || !AppConfig.locales.contains(language)) return const Translations.english();

    final raw = await (bundle ?? rootBundle).loadString('assets/i18n/$language.json');
    final decoded = jsonDecode(raw) as Map<String, dynamic>;

    return Translations(language, {for (final entry in decoded.entries) entry.key: entry.value.toString()});
  }
}

/// Hands the current [Translations] to every widget below it.
class TranslationsScope extends InheritedWidget {
  const TranslationsScope({super.key, required this.translations, required super.child});

  final Translations translations;

  static Translations of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<TranslationsScope>()?.translations ?? const Translations.english();

  @override
  bool updateShouldNotify(TranslationsScope oldWidget) => oldWidget.translations != translations;
}

extension TranslateContext on BuildContext {
  /// The sentence in the app's language.
  String t(String english, [Map<String, Object?> params = const {}]) => TranslationsScope.of(this).t(english, params);

  /// Left to right or right to left, for the current language.
  bool get isRtl => TranslationsScope.of(this).isRtl;
}
