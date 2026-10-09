import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/language_button.dart';
import '../../app/providers.dart';
import '../../core/design/qistas_symbol.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';

/// Three screens on the first launch: what Qistas is for, said plainly, then a way in.
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  final PageController _pages = PageController();
  int _index = 0;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  Future<void> _finish(String destination) async {
    await ref.read(sharedPreferencesProvider).setBool(onboardedKey, true);
    if (mounted) context.go(destination);
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    final slides = [
      (Icons.balance_outlined, context.t('Every instalment, to the cent.'), context.t('Qistas keeps your customers, contracts and payments in order, in Arabic and English.')),
      (Icons.payments_outlined, context.t('Payments land on the oldest instalment first.'), context.t('Record what a customer paid and Qistas applies it from the oldest unpaid instalment forward. A mistake is undone with a reversal, never by editing history.')),
      (Icons.workspace_premium_outlined, context.t('Free to start. Pro when you grow.'), context.t('Your first customers are free. When you need more, Pro removes every limit.')),
    ];
    final last = _index == slides.length - 1;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
              child: Row(
                children: [
                  const QistasSymbol(height: 40),
                  const Spacer(),
                  const LanguageButton(compact: true),
                  const SizedBox(width: 4),
                  TextButton(onPressed: () => _finish('/login'), child: Text(context.t('Skip'))),
                ],
              ),
            ),
            Expanded(
              child: PageView.builder(
                controller: _pages,
                itemCount: slides.length,
                onPageChanged: (i) => setState(() => _index = i),
                itemBuilder: (context, i) {
                  final (icon, title, body) = slides[i];

                  return ContentColumn(
                    padding: const EdgeInsets.symmetric(horizontal: 28),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(width: 72, height: 72, decoration: BoxDecoration(color: c.tintSand, borderRadius: BorderRadius.circular(22)), child: Icon(icon, size: 36, color: c.accentText)),
                        const SizedBox(height: 28),
                        Text(title, style: text.headlineLarge),
                        const SizedBox(height: 14),
                        Text(body, style: text.bodyLarge?.copyWith(color: c.inkMuted)),
                      ],
                    ),
                  );
                },
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                for (var i = 0; i < slides.length; i++)
                  AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    margin: const EdgeInsets.all(4),
                    width: i == _index ? 22 : 8,
                    height: 8,
                    decoration: BoxDecoration(color: i == _index ? c.accent : c.line, borderRadius: BorderRadius.circular(999)),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 20, 24, 24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: last
                    ? Column(
                        children: [
                          QButton(label: context.t('Start free'), onPressed: () => _finish('/register')),
                          const SizedBox(height: 8),
                          QButton(label: context.t('I already have an account'), kind: QButtonKind.text, onPressed: () => _finish('/login')),
                        ],
                      )
                    : QButton(label: context.t('Next'), onPressed: () => _pages.nextPage(duration: const Duration(milliseconds: 280), curve: Curves.easeOutCubic)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
