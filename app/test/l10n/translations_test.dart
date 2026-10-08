import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/config.dart';
import 'package:qistas/core/l10n/translations.dart';

/// Every sentence the app shows is written in the code as t('English sentence'). This reads them all and holds the
/// translation files to them: nothing missing, nothing stale, and no placeholder lost on the way.

final RegExp _call = RegExp(r"\bt\(\s*'((?:[^'\\]|\\.)*)'");
final RegExp _placeholder = RegExp(r':[a-z_]+');

String _decode(String raw) => raw.replaceAllMapped(RegExp(r"\\(['\\$n])"), (m) => m[1] == 'n' ? '\n' : m[1]!);

Set<String> sentencesInCode() {
  final found = <String>{};
  for (final file in Directory('lib').listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart'))) {
    if (file.path.endsWith('translations.dart')) continue;
    final code = file.readAsLinesSync().where((line) => !line.trimLeft().startsWith('//')).join('\n');
    for (final match in _call.allMatches(code)) {
      found.add(_decode(match[1]!));
    }
  }

  return found;
}

Map<String, String> table(String language) {
  final decoded = jsonDecode(File('assets/i18n/$language.json').readAsStringSync()) as Map<String, dynamic>;

  return {for (final entry in decoded.entries) entry.key: entry.value.toString()};
}

List<String> placeholders(String text) => (_placeholder.allMatches(text).map((m) => m[0]!).toSet().toList()..sort());

void main() {
  final sentences = sentencesInCode();
  final languages = AppConfig.locales.where((code) => code != 'en');

  test('the code uses sentences, and none is built with string interpolation', () {
    expect(sentences.length, greaterThan(200));
    expect(sentences.where((s) => s.contains(r'$')), isEmpty);
  });

  for (final language in languages) {
    group(language, () {
      final translated = table(language);

      test('translates every sentence the code uses', () {
        expect(sentences.where((s) => (translated[s] ?? '').trim().isEmpty).toList(), isEmpty);
      });

      test('keeps every :placeholder of the English sentence', () {
        final broken = [for (final s in sentences) if (translated[s] != null && placeholders(translated[s]!).join() != placeholders(s).join()) s];

        expect(broken, isEmpty);
      });

      test('holds nothing the code no longer uses', () {
        expect(translated.keys.where((key) => !sentences.contains(key)).toList(), isEmpty);
      });

      test('is not just the English sentence again', () {
        // One or two words can be the same in another language (a name, a loan word); a sentence cannot.
        final copied = [for (final s in sentences) if (translated[s] == s && s.trim().split(RegExp(r'\s+')).length >= 3) s];

        expect(copied, isEmpty);
      });
    });
  }

  group('Translations', () {
    test('replaces :placeholders and falls back to the English sentence', () {
      const t = Translations('fr', {'Hello :name': 'Bonjour :name'});

      expect(t.t('Hello :name', {'name': 'Layla'}), 'Bonjour Layla');
      expect(t.t('Untranslated :n', {'n': 3}), 'Untranslated 3');
    });

    test('knows which languages read right to left', () {
      expect([for (final code in AppConfig.locales) Translations(code).isRtl], [false, true, false, false, true]);
    });
  });
}
