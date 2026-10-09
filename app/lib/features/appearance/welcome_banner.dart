import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/config.dart';
import '../../core/design/tokens.dart';
import '../../core/l10n/translations.dart';
import '../../data/appearance.dart';

/// The welcome banner the admin wrote for the Android app, on the dashboard: in one of four tones drawn from the
/// look's own colours, with its button when it has one, and closable when the admin allows it (remembered on the
/// phone by the banner's key, so a new banner shows again). Shown only within its days.
class WelcomeBannerCard extends ConsumerWidget {
  const WelcomeBannerCard({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final banner = ref.watch(lookProvider)?.wearAt(DateTime.now()).banner;
    final dismissed = ref.watch(dismissedBannersProvider);
    final show = banner != null && !dismissed.contains(banner.key) && banner.isShownOn(DateTime.now());

    // Closing folds the card away rather than making the dashboard jump.
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 260),
      switchInCurve: Curves.easeOutCubic,
      switchOutCurve: Curves.easeInCubic,
      transitionBuilder: (child, animation) => SizeTransition(sizeFactor: animation, axisAlignment: -1, child: FadeTransition(opacity: animation, child: child)),
      child: show ? Padding(key: ValueKey(banner.key), padding: const EdgeInsets.only(bottom: 18), child: _Card(banner: banner)) : const SizedBox(key: ValueKey('none'), width: double.infinity),
    );
  }
}

class _Card extends ConsumerWidget {
  const _Card({required this.banner});

  final WelcomeBanner banner;

  /// A page of the website, or a secure address of its own; anything else is no link at all.
  Uri? _target() {
    final url = banner.ctaUrl;
    if (url == null) return null;
    if (url.startsWith('/') && !url.startsWith('//')) return Uri.parse('${AppConfig.webUrl}$url');
    final uri = Uri.tryParse(url);

    return uri != null && uri.scheme == 'https' ? uri : null;
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final radius = BorderRadius.circular(QistasMetrics.radiusLg);
    final target = _target();

    final (BoxDecoration decoration, Color ink, Color muted, Color buttonBg, Color buttonFg) = switch (banner.tone) {
      'navy' => (
          BoxDecoration(borderRadius: radius, gradient: LinearGradient(begin: AlignmentDirectional.topStart, end: AlignmentDirectional.bottomEnd, colors: [c.heroFrom, c.heroTo]), boxShadow: [BoxShadow(color: c.heroFrom.withValues(alpha: 0.16), blurRadius: 12, offset: const Offset(0, 4))]),
          c.onPrimary,
          c.onPrimary.withValues(alpha: 0.82),
          c.accent,
          c.onAccent,
        ),
      'sand' => (BoxDecoration(borderRadius: radius, color: c.surfaceAlt), c.ink, c.inkMuted, c.action, c.onAction),
      'sky' => (BoxDecoration(borderRadius: radius, color: c.tintSky, border: Border.all(color: c.info.withValues(alpha: 0.3))), c.ink, c.inkMuted, c.info, c.onInfo),
      _ => (BoxDecoration(borderRadius: radius, color: c.tintSand, border: Border.all(color: c.accent.withValues(alpha: 0.55))), c.ink, c.inkMuted, c.action, c.onAction),
    };

    return Semantics(
      container: true,
      label: context.t('Announcement'),
      child: DecoratedBox(
        key: const ValueKey('welcome-banner'),
        decoration: decoration,
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 14, 6, 14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (banner.imageUrl != null) ...[
                    ClipRRect(
                      borderRadius: BorderRadius.circular(QistasMetrics.radiusSm),
                      child: Image.network(banner.imageUrl!, width: 56, height: 42, fit: BoxFit.cover, errorBuilder: (_, _, _) => const SizedBox(width: 56, height: 42)),
                    ),
                    const SizedBox(width: 12),
                  ],
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(banner.title, style: text.titleMedium?.copyWith(color: ink, fontWeight: FontWeight.w700)),
                          if (banner.message.isNotEmpty) ...[
                            const SizedBox(height: 4),
                            Text(banner.message, style: text.bodyMedium?.copyWith(color: muted)),
                          ],
                        ],
                      ),
                    ),
                  ),
                  if (banner.dismissible)
                    IconButton(
                      tooltip: context.t('Close'),
                      icon: Icon(Icons.close_rounded, color: ink.withValues(alpha: 0.75), size: 20),
                      onPressed: () {
                        unawaited(HapticFeedback.selectionClick());
                        unawaited(ref.read(dismissedBannersProvider.notifier).dismiss(banner.key));
                      },
                    )
                  else
                    const SizedBox(width: 10),
                ],
              ),
              if (target != null && banner.ctaLabel.isNotEmpty) ...[
                const SizedBox(height: 12),
                FilledButton(
                  style: FilledButton.styleFrom(
                    backgroundColor: buttonBg,
                    foregroundColor: buttonFg,
                    minimumSize: const Size(0, 44),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(QistasMetrics.radiusButton)),
                  ),
                  onPressed: () => ref.read(openExternalProvider)(target),
                  child: Text(banner.ctaLabel),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
