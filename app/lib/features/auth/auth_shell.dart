import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/config.dart';
import '../../core/design/qistas_symbol.dart';
import '../../core/design/tokens.dart';
import '../../core/l10n/translations.dart';

/// Each language's own name, so a person can find theirs whatever language the screen is in.
const Map<String, String> languageNames = {
  'en': 'English',
  'ar': 'العربية',
  'fr': 'Français',
  'es': 'Español',
  'ur': 'اردو',
};

/// The frame of the sign-in and sign-up screens: the mark, a heading, the form, a way to change language.
class AuthShell extends StatelessWidget {
  const AuthShell({super.key, required this.title, this.subtitle, required this.children, this.footer});

  final String title;
  final String? subtitle;
  final List<Widget> children;
  final Widget? footer;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 12, 24, 32),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const QistasSymbol(height: 44),
                      const SizedBox(width: 12),
                      // The wordmark gives way before the language menu does, however large the text is set.
                      Expanded(child: FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: Text('qistas', style: text.headlineMedium))),
                      const Flexible(child: LanguageMenu()),
                    ],
                  ),
                  const SizedBox(height: 32),
                  Text(title, style: text.headlineLarge),
                  if (subtitle != null) ...[const SizedBox(height: 8), Text(subtitle!, style: text.bodyLarge?.copyWith(color: c.inkMuted))],
                  const SizedBox(height: 24),
                  ...children,
                  if (footer != null) ...[const SizedBox(height: 24), footer!],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Lets a person pick the app's language: each is named in itself.
class LanguageMenu extends ConsumerWidget {
  const LanguageMenu({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final current = ref.watch(localeProvider);

    return PopupMenuButton<String>(
      tooltip: context.t('Language'),
      initialValue: current,
      onSelected: (language) => ref.read(localeProvider.notifier).choose(language),
      itemBuilder: (context) => [
        for (final code in AppConfig.locales)
          PopupMenuItem<String>(value: code, child: Text(languageNames[code] ?? code, textDirection: AppConfig.rtlLocales.contains(code) ? TextDirection.rtl : TextDirection.ltr)),
      ],
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 12),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.language, size: 20),
            const SizedBox(width: 6),
            Flexible(child: Text(languageNames[current] ?? current, style: Theme.of(context).textTheme.labelLarge, maxLines: 1, overflow: TextOverflow.ellipsis)),
            const Icon(Icons.arrow_drop_down, size: 20),
          ],
        ),
      ),
    );
  }
}
