import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/qistas_theme.dart';
import 'package:qistas/core/design/tokens.dart';

void main() {
  group('colour contrast (WCAG AA: 4.5 for text)', () {
    for (final entry in {'light': QistasColors.light, 'dark': QistasColors.dark}.entries) {
      for (final (name, foreground, background) in entry.value.textPairs) {
        test('${entry.key}: $name', () {
          expect(contrastRatio(foreground, background), greaterThanOrEqualTo(4.5));
        });
      }
    }
  });

  test('contrast is 21 for black on white and 1 for a colour on itself', () {
    expect(contrastRatio(Colors.black, Colors.white), closeTo(21, 0.001));
    expect(contrastRatio(Colors.red, Colors.red), 1);
  });

  group('the theme', () {
    test('uses the Qistas tokens in light and dark', () {
      final light = QistasTheme.of(Brightness.light, 'en', webFonts: false);
      final dark = QistasTheme.of(Brightness.dark, 'en', webFonts: false);

      expect(light.scaffoldBackgroundColor, QistasColors.light.bg);
      expect(dark.scaffoldBackgroundColor, QistasColors.dark.bg);
      expect(light.extension<QistasColors>(), QistasColors.light);
      expect(dark.colorScheme.primary, QistasColors.dark.action);
      expect(light.colorScheme.error, QistasColors.light.danger);
    });

    test('keeps every control at least 48 dp tall', () {
      final theme = QistasTheme.of(Brightness.light, 'en', webFonts: false);

      expect(theme.filledButtonTheme.style!.minimumSize!.resolve({})!.height, greaterThanOrEqualTo(48));
      expect(theme.outlinedButtonTheme.style!.minimumSize!.resolve({})!.height, greaterThanOrEqualTo(48));
      expect(theme.textButtonTheme.style!.minimumSize!.resolve({})!.height, greaterThanOrEqualTo(48));
      expect(theme.materialTapTargetSize, MaterialTapTargetSize.padded);
    });

    test('gives Urdu more line height than Latin', () {
      final latin = QistasTheme.of(Brightness.light, 'en', webFonts: false).textTheme.bodyMedium!.height!;
      final urdu = QistasTheme.of(Brightness.light, 'ur', webFonts: false).textTheme.bodyMedium!.height!;

      expect(urdu, greaterThan(latin));
    });
  });

  group('right to left', () {
    for (final entry in {'ar': TextDirection.rtl, 'ur': TextDirection.rtl, 'en': TextDirection.ltr, 'fr': TextDirection.ltr, 'es': TextDirection.ltr}.entries) {
      testWidgets('${entry.key} reads ${entry.value.name}', (tester) async {
        TextDirection? seen;
        await tester.pumpWidget(MaterialApp(
          locale: Locale(entry.key),
          supportedLocales: [Locale(entry.key)],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: Builder(builder: (context) {
            seen = Directionality.of(context);

            return const SizedBox();
          }),
        ));

        expect(seen, entry.value);
      });
    }
  });
}
