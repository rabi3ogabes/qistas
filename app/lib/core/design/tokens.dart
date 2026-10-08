import 'package:flutter/material.dart';

/// The Qistas colours: the same tokens as the website (resources/css/tokens.css, the base theme of the theme
/// engine), in light and dark. Screens read them from the theme, never as literals, so a theme change reaches
/// every screen at once.
@immutable
class QistasColors extends ThemeExtension<QistasColors> {
  const QistasColors({
    required this.primary,
    required this.onPrimary,
    required this.action,
    required this.onAction,
    required this.accent,
    required this.onAccent,
    required this.accentText,
    required this.info,
    required this.onInfo,
    required this.bg,
    required this.surface,
    required this.surfaceAlt,
    required this.ink,
    required this.inkMuted,
    required this.line,
    required this.positive,
    required this.warning,
    required this.danger,
    required this.tintSky,
    required this.tintBlush,
    required this.tintSand,
    required this.tintMint,
    required this.heroFrom,
    required this.heroTo,
  });

  final Color primary;
  final Color onPrimary;
  final Color action;
  final Color onAction;
  final Color accent;
  final Color onAccent;

  /// Gold that is dark enough to read as text.
  final Color accentText;
  final Color info;
  final Color onInfo;
  final Color bg;
  final Color surface;
  final Color surfaceAlt;
  final Color ink;
  final Color inkMuted;
  final Color line;
  final Color positive;
  final Color warning;
  final Color danger;
  final Color tintSky;
  final Color tintBlush;
  final Color tintSand;
  final Color tintMint;
  final Color heroFrom;
  final Color heroTo;

  static const QistasColors light = QistasColors(
    primary: Color(0xFF0B1F44),
    onPrimary: Color(0xFFF7F3EA),
    action: Color(0xFF0B1F44),
    onAction: Color(0xFFF7F3EA),
    accent: Color(0xFFC9A25B),
    onAccent: Color(0xFF0B1F44),
    accentText: Color(0xFF7F6126),
    info: Color(0xFF1C6BA4),
    onInfo: Color(0xFFFFFFFF),
    bg: Color(0xFFF7F3EA),
    surface: Color(0xFFFFFFFF),
    surfaceAlt: Color(0xFFEFE9DB),
    ink: Color(0xFF0B1F44),
    inkMuted: Color(0xFF4F5B76),
    line: Color(0xFFE3DCCB),
    positive: Color(0xFF176E50),
    warning: Color(0xFF9A5700),
    danger: Color(0xFFB3261E),
    tintSky: Color(0xFFDDEDFA),
    tintBlush: Color(0xFFF6E4EA),
    tintSand: Color(0xFFF8EBCB),
    tintMint: Color(0xFFDCF2E8),
    heroFrom: Color(0xFF0B1F44),
    heroTo: Color(0xFF1B3A78),
  );

  static const QistasColors dark = QistasColors(
    primary: Color(0xFF163A7A),
    onPrimary: Color(0xFFF7F3EA),
    action: Color(0xFFC9A25B),
    onAction: Color(0xFF0B1F44),
    accent: Color(0xFFD4AE68),
    onAccent: Color(0xFF0B1F44),
    accentText: Color(0xFFDDBB7A),
    info: Color(0xFF6AAEE0),
    onInfo: Color(0xFF071634),
    bg: Color(0xFF071634),
    surface: Color(0xFF0E2250),
    surfaceAlt: Color(0xFF14295F),
    ink: Color(0xFFF2EEE3),
    inkMuted: Color(0xFFA9B4CC),
    line: Color(0xFF22386B),
    positive: Color(0xFF41C795),
    warning: Color(0xFFEBB04A),
    danger: Color(0xFFF28B82),
    tintSky: Color(0xFF14355F),
    tintBlush: Color(0xFF3A2540),
    tintSand: Color(0xFF3A3320),
    tintMint: Color(0xFF123B31),
    heroFrom: Color(0xFF1A4290),
    heroTo: Color(0xFF0E2A5C),
  );

  /// Every pair of colours that is ever drawn text-on-background, for the contrast test.
  List<(String, Color, Color)> get textPairs => [
        ('ink on bg', ink, bg),
        ('ink on surface', ink, surface),
        ('ink on surfaceAlt', ink, surfaceAlt),
        ('inkMuted on bg', inkMuted, bg),
        ('inkMuted on surface', inkMuted, surface),
        ('inkMuted on surfaceAlt', inkMuted, surfaceAlt),
        ('onPrimary on primary', onPrimary, primary),
        ('onAction on action', onAction, action),
        ('onAccent on accent', onAccent, accent),
        ('accentText on bg', accentText, bg),
        ('accentText on surface', accentText, surface),
        ('onInfo on info', onInfo, info),
        ('info on surface', info, surface),
        ('positive on surface', positive, surface),
        ('warning on surface', warning, surface),
        ('danger on surface', danger, surface),
        ('info on tintSky', info, tintSky),
        ('positive on tintMint', positive, tintMint),
        ('warning on tintSand', warning, tintSand),
        ('danger on tintBlush', danger, tintBlush),
        ('ink on tintSand', ink, tintSand),
        ('onPrimary on heroFrom', onPrimary, heroFrom),
        ('onPrimary on heroTo', onPrimary, heroTo),
      ];

  @override
  QistasColors copyWith() => this;

  /// Colours switch between light and dark; they are not blended.
  @override
  QistasColors lerp(ThemeExtension<QistasColors>? other, double t) => other is QistasColors && t >= 0.5 ? other : this;
}

/// Shape and spacing that never change per theme.
class QistasMetrics {
  const QistasMetrics._();

  static const double radiusSm = 10;
  static const double radiusMd = 12;
  static const double radiusLg = 20;
  static const double radiusXl = 28;

  /// Buttons and fields: a little rounder than a card's corner suggests, a little taller than a thumb needs.
  static const double radiusButton = 14;
  static const double buttonHeight = 52;

  /// The smallest thing a finger should have to hit.
  static const double touchTarget = 48;

  /// Screens are read in a column this wide at most; a tablet or a browser gets margins instead of stretched rows.
  static const double contentWidth = 720;

  static const double gutter = 16;
}

extension QistasColorsOf on BuildContext {
  /// The Qistas colours of the current theme.
  QistasColors get qc => Theme.of(this).extension<QistasColors>()!;
}

/// WCAG relative contrast between two colours, 1 (none) to 21 (black on white).
double contrastRatio(Color a, Color b) {
  final lighter = a.computeLuminance() > b.computeLuminance() ? a : b;
  final darker = identical(lighter, a) ? b : a;

  return (lighter.computeLuminance() + 0.05) / (darker.computeLuminance() + 0.05);
}
