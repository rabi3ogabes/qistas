import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/config.dart';

/// The Google Play listing in `docs/store/listing` (Sprint 2 Task 8, Win Plan 6.7): within Play's limits in every
/// language, the same lines in each, and nothing Play forbids or Qistas cannot stand behind.
class Listing {
  Listing(this.sections);

  /// Reads a listing: each `## Heading` and the text under it.
  factory Listing.read(String language) {
    final sections = <String, String>{};
    String? heading;
    final body = StringBuffer();
    void close() {
      if (heading != null) sections[heading] = body.toString().trim();
      body.clear();
    }

    for (final line in File('../docs/store/listing/$language.md').readAsLinesSync()) {
      if (line.startsWith('## ')) {
        close();
        heading = line.substring(3).trim();
      } else if (heading != null) {
        body.writeln(line);
      }
    }
    close();

    return Listing(sections);
  }

  final Map<String, String> sections;

  String get title => sections['Title'] ?? '';
  String get short => sections['Short description'] ?? '';
  String get full => sections['Full description'] ?? '';

  /// The lines waiting for their feature: feature key => the line, as it joins the full description.
  Map<String, String> get waiting => {
        for (final match in RegExp(r'^- `([a-z_]+)`: (.+)$', multiLine: true).allMatches(sections['When switched on'] ?? ''))
          match.group(1)!: match.group(2)!,
      };

  /// The full description once every waiting line has joined it.
  String get fullWithEverything => [full, ...waiting.values].join('\n');

  int bullets(String text) => RegExp(r'^• ', multiLine: true).allMatches(text).length;
}

/// Play counts characters; code points are the stricter count for Arabic and Urdu, which use no surrogate pairs.
int characters(String text) => text.runes.length;

/// Whether [text] has [word] as a word of its own (so "prêts" is not "prêt", nor "precios" "ios").
bool hasWord(String text, String word) =>
    RegExp('(?<![\\p{L}\\p{N}])${RegExp.escape(word)}(?![\\p{L}\\p{N}])', unicode: true, caseSensitive: false).hasMatch(text);

void main() {
  final english = Listing.read('en');

  for (final language in AppConfig.locales) {
    group('the $language listing', () {
      final listing = Listing.read(language);

      test('fits Google Play: a title of 30 characters, a short description of 80, a full description of 4,000', () {
        expect(characters(listing.title), inInclusiveRange(8, 30), reason: listing.title);
        expect(characters(listing.short), inInclusiveRange(30, 80), reason: listing.short);
        expect(characters(listing.full), inInclusiveRange(400, 4000));
        // Every waiting line must still fit once its feature is switched on.
        expect(characters(listing.fullWithEverything), lessThanOrEqualTo(4000));
      });

      test('says what the English listing says, line for line', () {
        expect(listing.bullets(listing.full), english.bullets(english.full));
        expect(listing.waiting.keys, english.waiting.keys);
        expect(listing.waiting.values.every((line) => line.startsWith('• ')), isTrue);
      });

      test('keeps to one line per field, and to what Play allows and Qistas does', () {
        expect(listing.title, isNot(contains('\n')));
        expect(listing.short, isNot(contains('\n')));
        final everything = '${listing.title}\n${listing.short}\n${listing.fullWithEverything}';
        // No iPhone until the iPhone app is live; no "loan", which Play reviews under its personal-loan policy.
        for (final word in ['iPhone', 'iOS', 'App Store', 'loan', 'loans', 'قرض', 'قروض', 'prêt', 'prêts', 'préstamo', 'préstamos']) {
          expect(hasWord(everything, word), isFalse, reason: word);
        }
        // Play forbids rankings and promotions in the title.
        expect(listing.title, isNot(contains('#1')));
        for (final word in ['best', 'top', 'free', 'مجاني', 'مجانًا', 'gratuit', 'gratis', 'مفت']) {
          expect(hasWord(listing.title, word), isFalse, reason: word);
        }
      });
    });
  }
}
