import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/config.dart';
import '../core/design/tokens.dart';
import '../core/l10n/languages.dart';
import '../core/l10n/translations.dart';
import 'providers.dart';

/// The typeface each language is set in on its card and its monogram: the serif of the brand for the Latin scripts,
/// IBM Plex Sans Arabic for Arabic, Nastaliq for Urdu (null where the app draws with the platform font).
String? languageTypeface(String code, {required bool brandFonts}) {
  if (!brandFonts) return null;

  return switch (code) {
    'ar' => 'IBMPlexSansArabic',
    'ur' => 'NotoNastaliqUrdu',
    _ => 'CormorantGaramond',
  };
}

TextDirection _directionOf(String code) => AppConfig.rtlLocales.contains(code) ? TextDirection.rtl : TextDirection.ltr;

/// The language control. In the app ([compact]) a round monogram of the language beside the account circle; before
/// signing in, a pill with a globe and the language spelled out. Either opens the five language cards.
class LanguageButton extends ConsumerWidget {
  const LanguageButton({super.key, this.compact = false});

  /// The round monogram, for the top bar of a section where every pixel is shared.
  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final current = ref.watch(localeProvider);
    final name = languageNames[current] ?? current;
    final brandFonts = ref.watch(brandFontsProvider);

    void open() {
      HapticFeedback.selectionClick();
      showLanguageSheet(context);
    }

    final face = compact
        ? Container(
            width: 40,
            height: 40,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: c.surface,
              border: Border.all(color: c.accent.withValues(alpha: 0.8), width: 1.5),
            ),
            child: Text(
              languageMonograms[current] ?? current.toUpperCase(),
              textDirection: _directionOf(current),
              maxLines: 1,
              // The monogram is a mark, not body text: it keeps its size when the text is enlarged.
              textScaler: TextScaler.noScaling,
              style: TextStyle(
                fontFamily: languageTypeface(current, brandFonts: brandFonts),
                fontSize: current == 'ar' || current == 'ur' ? 17 : 17.5,
                fontWeight: FontWeight.w700,
                height: 1.1,
                color: c.ink,
              ),
            ),
          )
        : ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 44, minWidth: 44),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.language_rounded, size: 18, color: c.accentText),
                  const SizedBox(width: 6),
                  Flexible(child: Text(name, style: Theme.of(context).textTheme.labelLarge?.copyWith(color: c.ink), maxLines: 1, overflow: TextOverflow.ellipsis)),
                  const SizedBox(width: 2),
                  Icon(Icons.expand_more_rounded, size: 18, color: c.inkMuted),
                ],
              ),
            ),
          );

    return Tooltip(
      message: context.t('Language'),
      excludeFromSemantics: true,
      child: Semantics(
        button: true,
        label: context.t('Language'),
        value: name,
        // The inner ink's tap is hidden with the rest of its semantics, so the button carries its own for screen readers.
        onTap: open,
        excludeSemantics: true,
        child: compact
            ? InkResponse(onTap: open, radius: 26, child: Padding(padding: const EdgeInsets.all(4), child: face))
            : Material(
                color: c.surface,
                shape: StadiumBorder(side: BorderSide(color: c.accent.withValues(alpha: 0.6))),
                clipBehavior: Clip.antiAlias,
                child: InkWell(onTap: open, child: face),
              ),
      ),
    );
  }
}

Future<void> showLanguageSheet(BuildContext context) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      // Over the whole screen, the bottom bar included: the choice is about the app, not about one section.
      useRootNavigator: true,
      builder: (context) => const LanguageSheet(),
    );

/// The five languages as cards: each written in itself, in its own typeface, with a greeting in it and its name in the
/// language the app speaks now. Choosing one marks it, changes the whole app at once, and closes the cards.
class LanguageSheet extends ConsumerStatefulWidget {
  const LanguageSheet({super.key});

  @override
  ConsumerState<LanguageSheet> createState() => _LanguageSheetState();
}

class _LanguageSheetState extends ConsumerState<LanguageSheet> {
  bool _closing = false;

  Future<void> _choose(String code) async {
    if (_closing) return;
    _closing = true;
    unawaited(HapticFeedback.selectionClick());
    await ref.read(localeProvider.notifier).choose(code);
    // A moment for the gold ring to settle on the chosen card before the cards close.
    await Future<void>.delayed(const Duration(milliseconds: 220));
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final current = ref.watch(localeProvider);
    final brandFonts = ref.watch(brandFontsProvider);
    final english = {'en': context.t('English'), 'ar': context.t('Arabic'), 'fr': context.t('French'), 'es': context.t('Spanish'), 'ur': context.t('Urdu')};

    return SafeArea(
      top: false,
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(context.t('Choose your language'), style: text.headlineSmall),
            const SizedBox(height: 4),
            Text(context.t('The whole app changes at once.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
            const SizedBox(height: 18),
            for (final code in AppConfig.locales) ...[
              _LanguageCard(
                key: ValueKey('language-$code'),
                code: code,
                // Shown in the app's language, except on the card of that same language, where it would repeat.
                nameHere: code == current ? null : english[code],
                selected: code == current,
                typeface: languageTypeface(code, brandFonts: brandFonts),
                onTap: () => _choose(code),
              ),
              const SizedBox(height: 10),
            ],
          ],
        ),
      ),
    );
  }
}

class _LanguageCard extends StatelessWidget {
  const _LanguageCard({super.key, required this.code, required this.nameHere, required this.selected, required this.typeface, required this.onTap});

  final String code;
  final String? nameHere;
  final bool selected;
  final String? typeface;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final name = languageNames[code] ?? code;
    final own = _directionOf(code);
    final arabicScript = code == 'ar' || code == 'ur';
    final radius = BorderRadius.circular(QistasMetrics.radiusLg);

    return Semantics(
      button: true,
      selected: selected,
      label: name,
      onTap: onTap,
      excludeSemantics: true,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOutCubic,
        decoration: BoxDecoration(
          color: selected ? c.tintSand : c.surface,
          borderRadius: radius,
          border: Border.all(color: selected ? c.accent : c.line, width: selected ? 1.6 : 1),
          boxShadow: selected ? [BoxShadow(color: c.accent.withValues(alpha: 0.22), blurRadius: 18, offset: const Offset(0, 6))] : null,
        ),
        child: Material(
          type: MaterialType.transparency,
          child: InkWell(
            onTap: onTap,
            borderRadius: radius,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 76),
              child: Padding(
                padding: const EdgeInsetsDirectional.fromSTEB(14, 12, 16, 12),
                child: Row(
                  children: [
                    Container(
                      width: 48,
                      height: 48,
                      alignment: Alignment.center,
                      decoration: BoxDecoration(shape: BoxShape.circle, color: selected ? c.surface : c.surfaceAlt),
                      child: Text(
                        languageMonograms[code] ?? code,
                        textDirection: own,
                        textScaler: TextScaler.noScaling,
                        style: TextStyle(fontFamily: typeface, fontSize: 19, fontWeight: FontWeight.w700, height: 1.1, color: c.ink),
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      flex: 3,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // Every name starts at the same edge, whichever way its own script runs.
                          Align(
                            alignment: AlignmentDirectional.centerStart,
                            child: Text(
                              name,
                              textDirection: own,
                              style: text.titleLarge?.copyWith(fontFamily: typeface, fontWeight: FontWeight.w600, height: arabicScript ? 1.5 : 1.15),
                            ),
                          ),
                          Align(
                            alignment: AlignmentDirectional.centerStart,
                            child: Text(
                              languageGreetings[code] ?? '',
                              textDirection: own,
                              style: text.bodyMedium?.copyWith(fontFamily: arabicScript ? typeface : null, color: c.inkMuted, height: arabicScript ? 1.6 : 1.3),
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (nameHere != null) ...[
                      const SizedBox(width: 10),
                      // A fixed share of the row, so every card's circle sits on the same line; gives way to the
                      // language's own name when the text is enlarged.
                      Expanded(flex: 2, child: Text(nameHere!, maxLines: 1, overflow: TextOverflow.ellipsis, textAlign: TextAlign.end, style: text.labelMedium?.copyWith(color: c.inkMuted))),
                    ],
                    const SizedBox(width: 10),
                    AnimatedSwitcher(
                      duration: const Duration(milliseconds: 200),
                      transitionBuilder: (child, animation) => ScaleTransition(scale: animation, child: child),
                      child: selected
                          ? Container(
                              key: const ValueKey('on'),
                              width: 28,
                              height: 28,
                              decoration: BoxDecoration(shape: BoxShape.circle, color: c.accent),
                              child: Icon(Icons.check_rounded, size: 18, color: c.onAccent),
                            )
                          : SizedBox(key: const ValueKey('off'), width: 28, height: 28, child: DecoratedBox(decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: c.line, width: 1.5)))),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
