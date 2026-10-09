import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/config.dart';
import '../core/design/tokens.dart';
import '../core/l10n/languages.dart';
import '../core/l10n/translations.dart';
import 'providers.dart';

/// The language control: a small pill of its own, never a row in a menu. A globe and the language in its own script
/// (or, where space is short, its two-letter code); one tap opens the list of the five languages.
class LanguageButton extends ConsumerWidget {
  const LanguageButton({super.key, this.compact = false});

  /// Just the code (EN, AR…) beside the globe, for the top bar of a section where every pixel is shared.
  final bool compact;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final current = ref.watch(localeProvider);
    final name = languageNames[current] ?? current;

    return Tooltip(
      message: context.t('Language'),
      excludeFromSemantics: true,
      child: Semantics(
        button: true,
        label: context.t('Language'),
        value: name,
        excludeSemantics: true,
        child: Material(
          color: c.surface,
          shape: StadiumBorder(side: BorderSide(color: c.accent.withValues(alpha: 0.6))),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: () {
              HapticFeedback.selectionClick();
              showLanguageSheet(context);
            },
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 44, minWidth: 44),
              child: Padding(
                padding: EdgeInsets.symmetric(horizontal: compact ? 12 : 14),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.language_rounded, size: 18, color: c.accentText),
                    const SizedBox(width: 6),
                    Flexible(
                      child: Text(
                        compact ? current.toUpperCase() : name,
                        style: Theme.of(context).textTheme.labelLarge?.copyWith(color: c.ink),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
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

Future<void> showLanguageSheet(BuildContext context) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      // Over the whole screen, the bottom bar included: the sheet is about the app, not about one section.
      useRootNavigator: true,
      builder: (context) => const LanguageSheet(),
    );

/// The five languages, each in its own script, the current one marked in gold. Choosing one changes the whole app
/// at once and closes the sheet.
class LanguageSheet extends ConsumerWidget {
  const LanguageSheet({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final text = Theme.of(context).textTheme;
    final current = ref.watch(localeProvider);

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
              child: Text(context.t('Choose your language'), style: text.headlineSmall),
            ),
            for (final code in AppConfig.locales)
              _LanguageRow(
                code: code,
                selected: code == current,
                onTap: () {
                  HapticFeedback.selectionClick();
                  ref.read(localeProvider.notifier).choose(code);
                  Navigator.of(context).pop();
                },
              ),
          ],
        ),
      ),
    );
  }
}

class _LanguageRow extends StatelessWidget {
  const _LanguageRow({required this.code, required this.selected, required this.onTap});

  final String code;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final name = languageNames[code] ?? code;
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(QistasMetrics.radiusMd));

    return Padding(
      padding: const EdgeInsets.only(bottom: 4),
      child: Semantics(
        button: true,
        selected: selected,
        label: name,
        excludeSemantics: true,
        child: Material(
          color: selected ? c.tintSand : Colors.transparent,
          shape: shape,
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 56),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: Row(
                  children: [
                    // Every name starts at the same edge, whichever way its own script runs.
                    Expanded(
                      child: Align(
                        alignment: AlignmentDirectional.centerStart,
                        child: Text(
                          name,
                          textDirection: AppConfig.rtlLocales.contains(code) ? TextDirection.rtl : TextDirection.ltr,
                          style: text.titleMedium?.copyWith(fontWeight: selected ? FontWeight.w700 : FontWeight.w500),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Text(code.toUpperCase(), style: text.labelMedium?.copyWith(color: c.inkMuted, letterSpacing: 0.6)),
                    const SizedBox(width: 12),
                    SizedBox(width: 24, child: selected ? Icon(Icons.check_rounded, size: 22, color: c.accentText) : null),
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
