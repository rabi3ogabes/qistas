import 'package:flutter/material.dart';

import '../../app/language_button.dart';
import '../../core/design/qistas_symbol.dart';
import '../../core/design/tokens.dart';

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
                      // The wordmark gives way before the language button does, however large the text is set.
                      Expanded(child: FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: Text('qistas', style: text.headlineMedium))),
                      ConstrainedBox(constraints: const BoxConstraints(maxWidth: 200), child: const LanguageButton()),
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
