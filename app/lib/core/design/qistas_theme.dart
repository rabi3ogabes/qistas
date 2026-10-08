import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import 'tokens.dart';

/// Builds the app's [ThemeData] from the Qistas tokens, for a language and a brightness.
///
/// Type follows the website: Cormorant Garamond for headings and Geist for text in Latin scripts, IBM Plex Sans
/// Arabic for Arabic, Noto Nastaliq Urdu for Urdu. Pass `webFonts: false` (tests) to use the platform font and
/// never touch the network.
class QistasTheme {
  const QistasTheme._();

  static ThemeData of(Brightness brightness, String language, {bool webFonts = true}) {
    final dark = brightness == Brightness.dark;
    final c = dark ? QistasColors.dark : QistasColors.light;

    final scheme = ColorScheme(
      brightness: brightness,
      primary: c.action,
      onPrimary: c.onAction,
      secondary: c.accent,
      onSecondary: c.onAccent,
      tertiary: c.info,
      onTertiary: c.onInfo,
      error: c.danger,
      onError: dark ? c.bg : Colors.white,
      surface: c.surface,
      onSurface: c.ink,
      onSurfaceVariant: c.inkMuted,
      surfaceContainerLowest: c.surface,
      surfaceContainerLow: c.surface,
      surfaceContainer: c.surfaceAlt,
      surfaceContainerHigh: c.surfaceAlt,
      surfaceContainerHighest: c.surfaceAlt,
      outline: c.line,
      outlineVariant: c.line,
      shadow: Colors.black,
      scrim: c.primary,
    );

    final text = _textTheme(ThemeData(brightness: brightness).textTheme, language, c, webFonts);
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(QistasMetrics.radiusMd));
    final border = OutlineInputBorder(
      borderRadius: BorderRadius.circular(QistasMetrics.radiusMd),
      borderSide: BorderSide(color: c.ink.withValues(alpha: 0.22)),
    );

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: c.bg,
      canvasColor: c.bg,
      textTheme: text,
      primaryTextTheme: text,
      extensions: [c],
      materialTapTargetSize: MaterialTapTargetSize.padded,
      visualDensity: VisualDensity.standard,
      splashFactory: InkSparkle.splashFactory,
      dividerTheme: DividerThemeData(color: c.line, space: 1, thickness: 1),
      appBarTheme: AppBarTheme(
        backgroundColor: c.bg,
        foregroundColor: c.ink,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: text.titleLarge,
      ),
      cardTheme: CardThemeData(
        color: c.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(QistasMetrics.radiusLg),
          side: BorderSide(color: c.line),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: c.surface,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        border: border,
        enabledBorder: border,
        focusedBorder: border.copyWith(borderSide: BorderSide(color: c.info, width: 2)),
        errorBorder: border.copyWith(borderSide: BorderSide(color: c.danger)),
        focusedErrorBorder: border.copyWith(borderSide: BorderSide(color: c.danger, width: 2)),
        labelStyle: text.bodyMedium?.copyWith(color: c.inkMuted),
        helperStyle: text.bodySmall?.copyWith(color: c.inkMuted),
        errorStyle: text.bodySmall?.copyWith(color: c.danger, fontWeight: FontWeight.w500),
        errorMaxLines: 3,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(64, QistasMetrics.touchTarget + 4),
          padding: const EdgeInsets.symmetric(horizontal: 20),
          shape: shape,
          textStyle: text.labelLarge,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(64, QistasMetrics.touchTarget + 4),
          padding: const EdgeInsets.symmetric(horizontal: 20),
          foregroundColor: c.ink,
          side: BorderSide(color: c.ink.withValues(alpha: 0.22)),
          shape: shape,
          textStyle: text.labelLarge,
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(64, QistasMetrics.touchTarget),
          foregroundColor: dark ? c.accentText : c.info,
          textStyle: text.labelLarge,
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: c.surface,
        surfaceTintColor: Colors.transparent,
        indicatorColor: c.accent.withValues(alpha: dark ? 0.28 : 0.30),
        height: 68,
        labelTextStyle: WidgetStatePropertyAll(text.labelSmall),
        iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(color: states.contains(WidgetState.selected) ? c.ink : c.inkMuted)),
      ),
      navigationRailTheme: NavigationRailThemeData(
        backgroundColor: c.surfaceAlt,
        indicatorColor: c.surface,
        selectedIconTheme: IconThemeData(color: c.accentText),
        unselectedIconTheme: IconThemeData(color: c.inkMuted),
        selectedLabelTextStyle: text.labelMedium?.copyWith(color: c.ink, fontWeight: FontWeight.w700),
        unselectedLabelTextStyle: text.labelMedium?.copyWith(color: c.inkMuted),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: c.surface,
        selectedColor: c.accent.withValues(alpha: 0.30),
        side: BorderSide(color: c.line),
        labelStyle: text.labelLarge,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
        showCheckmark: false,
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: c.surface,
        surfaceTintColor: Colors.transparent,
        showDragHandle: true,
        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(QistasMetrics.radiusXl))),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: c.surface,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(QistasMetrics.radiusLg)),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: c.primary,
        contentTextStyle: text.bodyMedium?.copyWith(color: c.onPrimary),
        shape: shape,
      ),
      listTileTheme: ListTileThemeData(iconColor: c.inkMuted, textColor: c.ink),
      progressIndicatorTheme: ProgressIndicatorThemeData(color: c.accent, linearTrackColor: c.surfaceAlt),
      checkboxTheme: CheckboxThemeData(
        side: BorderSide(color: c.ink.withValues(alpha: 0.5), width: 1.5),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(5)),
      ),
    );
  }

  static TextTheme _textTheme(TextTheme base, String language, QistasColors c, bool webFonts) {
    final themed = base.apply(bodyColor: c.ink, displayColor: c.ink);
    if (!webFonts) return _scale(themed, language);

    final TextTheme body;
    final TextStyle Function(TextStyle) heading;

    switch (language) {
      case 'ar':
        body = GoogleFonts.ibmPlexSansArabicTextTheme(themed);
        heading = (s) => GoogleFonts.ibmPlexSansArabic(textStyle: s, fontWeight: FontWeight.w700);
      case 'ur':
        body = GoogleFonts.notoNastaliqUrduTextTheme(themed);
        heading = (s) => GoogleFonts.notoNastaliqUrdu(textStyle: s, fontWeight: FontWeight.w700);
      default:
        body = GoogleFonts.getTextTheme('Geist', themed);
        heading = (s) => GoogleFonts.cormorantGaramond(textStyle: s, fontWeight: FontWeight.w600);
    }

    return _scale(
      body.copyWith(
        displayLarge: heading(body.displayLarge!),
        displayMedium: heading(body.displayMedium!),
        displaySmall: heading(body.displaySmall!),
        headlineLarge: heading(body.headlineLarge!),
        headlineMedium: heading(body.headlineMedium!),
        headlineSmall: heading(body.headlineSmall!),
        titleLarge: heading(body.titleLarge!),
      ),
      language,
    );
  }

  /// Sizes for reading on a phone; Urdu's Nastaliq needs more line height than Latin.
  static TextTheme _scale(TextTheme t, String language) {
    final tall = language == 'ur' ? 1.9 : (language == 'ar' ? 1.5 : 1.35);

    TextStyle? size(TextStyle? s, double fontSize) => s?.copyWith(fontSize: fontSize, height: tall);

    return t.copyWith(
      headlineLarge: size(t.headlineLarge, 34),
      headlineMedium: size(t.headlineMedium, 28),
      headlineSmall: size(t.headlineSmall, 24),
      titleLarge: size(t.titleLarge, 22),
      titleMedium: size(t.titleMedium, 17)?.copyWith(fontWeight: FontWeight.w600),
      titleSmall: size(t.titleSmall, 15)?.copyWith(fontWeight: FontWeight.w600),
      bodyLarge: size(t.bodyLarge, 17),
      bodyMedium: size(t.bodyMedium, 15),
      bodySmall: size(t.bodySmall, 13),
      labelLarge: size(t.labelLarge, 15)?.copyWith(fontWeight: FontWeight.w600),
      labelMedium: size(t.labelMedium, 13)?.copyWith(fontWeight: FontWeight.w600),
      labelSmall: size(t.labelSmall, 12)?.copyWith(fontWeight: FontWeight.w600),
    );
  }
}
