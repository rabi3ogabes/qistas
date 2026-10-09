import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/config.dart';
import '../core/design/qistas_theme.dart';
import '../core/l10n/translations.dart';
import '../data/appearance.dart';
import 'providers.dart';
import 'router.dart';

/// The whole app: the router, the language, the theme, and the words every screen asks for.
class QistasApp extends ConsumerWidget {
  const QistasApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    final language = ref.watch(localeProvider);
    final mode = ref.watch(themeModeProvider);
    final brandFonts = ref.watch(brandFontsProvider);
    // While a new language loads, the previous words stay on screen: no flash of English.
    final translations = ref.watch(translationsProvider).valueOrNull ?? const Translations.english();
    // The look chosen in the admin (today's event included), or the Qistas colours until there is one.
    final look = ref.watch(lookProvider)?.wearAt(DateTime.now());

    return MaterialApp.router(
      title: 'Qistas',
      debugShowCheckedModeBanner: false,
      routerConfig: router,
      locale: Locale(language),
      supportedLocales: [for (final code in AppConfig.locales) Locale(code)],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: QistasTheme.of(Brightness.light, language, brandFonts: brandFonts, colors: look?.light),
      darkTheme: QistasTheme.of(Brightness.dark, language, brandFonts: brandFonts, colors: look?.dark),
      themeMode: mode,
      builder: (context, child) => TranslationsScope(
        translations: translations,
        // People who enlarge their text get it, up to the point where a phone screen still holds a form.
        child: MediaQuery.withClampedTextScaling(maxScaleFactor: 2, child: child ?? const SizedBox.shrink()),
      ),
    );
  }
}
