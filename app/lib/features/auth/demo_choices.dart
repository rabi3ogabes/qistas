import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';

/// "Just looking around?": two ways into a throw-away demo, shown only when the server offers it. Nothing to sign up
/// for and nothing to type: an admin with every feature, or a user with the Free plan's limits.
class DemoChoices extends ConsumerStatefulWidget {
  const DemoChoices({super.key});

  @override
  ConsumerState<DemoChoices> createState() => _DemoChoicesState();
}

class _DemoChoicesState extends ConsumerState<DemoChoices> {
  String? _starting;
  String? _problem;

  Future<void> _start(DemoPersona persona) async {
    if (_starting != null) return;
    setState(() {
      _starting = persona.key;
      _problem = null;
    });

    try {
      await ref.read(authProvider.notifier).signInDemo(persona.key);
      // The router moves a signed-in person on by itself.
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _starting = null;
        _problem = errorMessage(context, e);
      });

      return;
    }

    if (mounted) setState(() => _starting = null);
  }

  @override
  Widget build(BuildContext context) {
    final offer = ref.watch(demoOfferProvider).valueOrNull ?? DemoOffer.none;
    if (!offer.enabled || offer.personas.isEmpty) return const SizedBox.shrink();

    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: const EdgeInsets.only(top: 28),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Divider(color: c.line, height: 1),
          const SizedBox(height: 20),
          Text(context.t('Just looking around?'), style: text.titleLarge),
          const SizedBox(height: 4),
          Text(context.t('Try the demo with sample data. Nothing to sign up for, nothing to type.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
          const SizedBox(height: 14),
          if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 12)],
          for (final persona in offer.personas)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: _Choice(persona: persona, busy: _starting == persona.key, disabled: _starting != null, onPressed: () => _start(persona)),
            ),
        ],
      ),
    );
  }
}

class _Choice extends StatelessWidget {
  const _Choice({required this.persona, required this.busy, required this.disabled, required this.onPressed});

  final DemoPersona persona;
  final bool busy;
  final bool disabled;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final highlighted = persona.key == 'admin';

    return Semantics(
      button: true,
      enabled: !disabled,
      label: '${persona.label}. ${persona.description}',
      onTap: disabled ? null : onPressed,
      excludeSemantics: true,
      child: Material(
        color: highlighted ? c.tintSand : c.surface,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(QistasMetrics.radiusMd), side: BorderSide(color: highlighted ? c.accent : c.line)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: disabled ? null : onPressed,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 68),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(persona.label, style: text.titleSmall),
                        const SizedBox(height: 2),
                        Text(persona.description, style: text.bodySmall?.copyWith(color: c.inkMuted)),
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  busy
                      ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                      : Icon(context.isRtl ? Icons.chevron_left : Icons.chevron_right, color: c.inkMuted),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
