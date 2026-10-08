import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../money.dart';
import 'tokens.dart';

/// One vocabulary of small parts for every screen: the same card, button, field, badge and meter everywhere.

/// Keeps a screen's content in a readable column, centred, on a tablet or in a browser.
class ContentColumn extends StatelessWidget {
  const ContentColumn({super.key, required this.child, this.padding = const EdgeInsets.symmetric(horizontal: QistasMetrics.gutter)});

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) => Align(
        alignment: AlignmentDirectional.topCenter,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: QistasMetrics.contentWidth),
          child: Padding(padding: padding, child: child),
        ),
      );
}

/// An amount at the end of a row: as wide as it needs, but never more than about half the screen, and it shrinks
/// its text rather than push the rest of the row off the card when text is enlarged.
class EndAmount extends StatelessWidget {
  const EndAmount({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => ConstrainedBox(
        constraints: BoxConstraints(maxWidth: math.min(MediaQuery.sizeOf(context).width * 0.45, 240)),
        child: FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerEnd, child: child),
      );
}

class QCard extends StatelessWidget {
  const QCard({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.onTap, this.semanticLabel});

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final String? semanticLabel;

  @override
  Widget build(BuildContext context) {
    final card = Card(
      clipBehavior: Clip.antiAlias,
      child: onTap == null
          ? Padding(padding: padding, child: child)
          : InkWell(onTap: onTap, child: Padding(padding: padding, child: child)),
    );

    return semanticLabel == null ? card : Semantics(label: semanticLabel, container: true, child: card);
  }
}

enum QButtonKind { primary, gold, quiet, danger, text }

class QButton extends StatelessWidget {
  const QButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.kind = QButtonKind.primary,
    this.icon,
    this.loading = false,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final QButtonKind kind;
  final IconData? icon;

  /// Shows a spinner and ignores taps: a second tap can never send the same thing twice.
  final bool loading;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final action = loading ? null : onPressed;

    final Widget content = loading
        ? SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(strokeWidth: 2.5, color: _foreground(c), semanticsLabel: label),
          )
        : Row(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (icon != null) ...[Icon(icon, size: 20), const SizedBox(width: 8)],
              Flexible(child: Text(label, textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis)),
            ],
          );

    final Widget button = switch (kind) {
      QButtonKind.quiet => OutlinedButton(onPressed: action, child: content),
      QButtonKind.text => TextButton(onPressed: action, child: content),
      QButtonKind.gold => FilledButton(
          onPressed: action,
          style: FilledButton.styleFrom(backgroundColor: c.accent, foregroundColor: c.onAccent, disabledBackgroundColor: c.accent.withValues(alpha: 0.5), disabledForegroundColor: c.onAccent),
          child: content,
        ),
      QButtonKind.danger => FilledButton(
          onPressed: action,
          style: FilledButton.styleFrom(backgroundColor: c.danger, foregroundColor: Theme.of(context).colorScheme.onError, disabledBackgroundColor: c.danger.withValues(alpha: 0.5)),
          child: content,
        ),
      QButtonKind.primary => FilledButton(onPressed: action, child: content),
    };

    return Semantics(button: true, enabled: action != null, label: label, excludeSemantics: loading, child: expand ? SizedBox(width: double.infinity, child: button) : button);
  }

  Color _foreground(QistasColors c) => switch (kind) {
        QButtonKind.quiet || QButtonKind.text => c.ink,
        QButtonKind.gold => c.onAccent,
        QButtonKind.danger => Colors.white,
        QButtonKind.primary => c.onAction,
      };
}

class QField extends StatelessWidget {
  const QField({
    super.key,
    required this.controller,
    required this.label,
    this.hint,
    this.helper,
    this.errorText,
    this.keyboardType,
    this.textInputAction,
    this.obscure = false,
    this.autofillHints,
    this.maxLines = 1,
    this.onChanged,
    this.onSubmitted,
    this.enabled = true,
    this.latin = false,
    this.suffix,
    this.focusNode,
    this.autofocus = false,
    this.maxLength,
  });

  final TextEditingController controller;
  final String label;
  final String? hint;
  final String? helper;
  final String? errorText;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final bool obscure;
  final Iterable<String>? autofillHints;
  final int maxLines;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final bool enabled;

  /// E-mail addresses, phone numbers and amounts are read left to right in every language.
  final bool latin;
  final Widget? suffix;
  final FocusNode? focusNode;
  final bool autofocus;
  final int? maxLength;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsetsDirectional.only(bottom: 6),
            child: Text(label, style: Theme.of(context).textTheme.labelLarge),
          ),
          TextField(
            controller: controller,
            focusNode: focusNode,
            enabled: enabled,
            autofocus: autofocus,
            obscureText: obscure,
            maxLines: obscure ? 1 : maxLines,
            maxLength: maxLength,
            keyboardType: keyboardType,
            textInputAction: textInputAction,
            autofillHints: autofillHints,
            autocorrect: !latin && !obscure,
            enableSuggestions: !latin && !obscure,
            textDirection: latin ? TextDirection.ltr : null,
            textAlign: latin ? TextAlign.start : TextAlign.start,
            onChanged: onChanged,
            onSubmitted: onSubmitted,
            decoration: InputDecoration(hintText: hint, helperText: helper, helperMaxLines: 3, errorText: errorText, suffixIcon: suffix, counterText: ''),
          ),
        ],
      );
}

enum QTone { neutral, info, ok, warn, bad, gold }

class QBadge extends StatelessWidget {
  const QBadge(this.label, {super.key, this.tone = QTone.neutral});

  final String label;
  final QTone tone;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final (Color background, Color foreground) = switch (tone) {
      QTone.info => (c.tintSky, c.info),
      QTone.ok => (c.tintMint, c.positive),
      QTone.warn => (c.tintSand, c.warning),
      QTone.bad => (c.tintBlush, c.danger),
      QTone.gold => (c.tintSand, c.accentText),
      QTone.neutral => (c.surfaceAlt, c.ink),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(999)),
      child: Text(label, style: Theme.of(context).textTheme.labelSmall?.copyWith(color: foreground)),
    );
  }
}

/// Usage of one limit: "3 of 5 customers" with a bar that warms as it fills. [limit] null means unlimited.
class QMeter extends StatelessWidget {
  const QMeter({super.key, required this.label, required this.used, required this.limit, required this.figures, this.enabled = true});

  final String label;
  final int used;
  final int? limit;

  /// The words for the numbers, already translated: "3 of 5", "Unlimited", "Not included".
  final String figures;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final fraction = !enabled || limit == null || limit == 0 ? 0.0 : (used / limit!).clamp(0.0, 1.0);
    final color = !enabled || fraction >= 1 ? c.danger : (fraction >= 0.8 ? c.warning : c.accent);

    return Semantics(
      label: '$label: $figures',
      child: ExcludeSemantics(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Flexible(child: Text(label, style: Theme.of(context).textTheme.bodySmall?.copyWith(color: c.inkMuted))),
                const SizedBox(width: 12),
                Text(figures, style: Theme.of(context).textTheme.labelMedium),
              ],
            ),
            if (enabled && limit != null) ...[
              const SizedBox(height: 6),
              ClipRRect(
                borderRadius: BorderRadius.circular(999),
                child: LinearProgressIndicator(value: fraction, minHeight: 6, color: color, backgroundColor: c.surfaceAlt),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// An amount of money, read left to right in every language and with figures that line up.
class MoneyText extends StatelessWidget {
  const MoneyText(this.amount, this.currency, {super.key, this.style, this.color, this.strike = false});

  final Money amount;
  final String? currency;
  final TextStyle? style;
  final Color? color;
  final bool strike;

  @override
  Widget build(BuildContext context) {
    final base = style ?? Theme.of(context).textTheme.bodyMedium;

    return Directionality(
      textDirection: TextDirection.ltr,
      child: Text(
        amount.format(currency),
        style: base?.copyWith(
          color: color,
          fontFeatures: const [FontFeature.tabularFigures()],
          decoration: strike ? TextDecoration.lineThrough : null,
        ),
      ),
    );
  }
}

/// A grey bar that stands in for text while it loads; still when the person asked for less motion.
class QSkeleton extends StatefulWidget {
  const QSkeleton({super.key, this.width, this.height = 16});

  final double? width;
  final double height;

  @override
  State<QSkeleton> createState() => _QSkeletonState();
}

class _QSkeletonState extends State<QSkeleton> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.disableAnimationsOf(context)) {
      _controller.stop();
    } else if (!_controller.isAnimating) {
      _controller.repeat();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return ExcludeSemantics(
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) => Container(
          width: widget.width,
          height: widget.height,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(6),
            gradient: LinearGradient(
              begin: Alignment(-1 + 2 * _controller.value, 0),
              end: Alignment(1 + 2 * _controller.value, 0),
              colors: [c.surfaceAlt, Color.lerp(c.surfaceAlt, c.surface, 0.6)!, c.surfaceAlt],
            ),
          ),
        ),
      ),
    );
  }
}

/// A list's placeholder rows while the first page loads.
class QSkeletonList extends StatelessWidget {
  const QSkeletonList({super.key, this.rows = 6});

  final int rows;

  @override
  Widget build(BuildContext context) => Column(
        children: [
          for (var i = 0; i < rows; i++)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 14),
              child: Row(children: [Expanded(child: QSkeleton(height: 18)), SizedBox(width: 24), QSkeleton(width: 72, height: 18)]),
            ),
        ],
      );
}

class QEmpty extends StatelessWidget {
  const QEmpty({super.key, required this.icon, required this.title, required this.message, this.action});

  final IconData icon;
  final String title;
  final String message;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(width: 64, height: 64, decoration: BoxDecoration(color: c.surfaceAlt, shape: BoxShape.circle), child: Icon(icon, color: c.accentText, size: 28)),
            const SizedBox(height: 16),
            Text(title, style: text.titleMedium, textAlign: TextAlign.center),
            if (message.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(message, style: text.bodyMedium?.copyWith(color: c.inkMuted), textAlign: TextAlign.center),
            ],
            if (action != null) ...[const SizedBox(height: 20), action!],
          ],
        ),
      ),
    );
  }
}

/// What a failed load looks like: what happened, and a button to try again.
class QErrorView extends StatelessWidget {
  const QErrorView({super.key, required this.message, required this.retryLabel, required this.onRetry});

  final String message;
  final String retryLabel;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => QEmpty(
        icon: Icons.cloud_off_outlined,
        title: message,
        message: '',
        action: QButton(label: retryLabel, onPressed: onRetry, kind: QButtonKind.quiet, expand: false),
      );
}

class QSectionTitle extends StatelessWidget {
  const QSectionTitle(this.title, {super.key, this.trailing});

  final String title;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(top: 24, bottom: 10),
        child: Row(
          children: [
            Expanded(child: Text(title, style: Theme.of(context).textTheme.titleMedium, semanticsLabel: title)),
            ?trailing,
          ],
        ),
      );
}

/// A label and its value, for the facts of a record.
class QFact extends StatelessWidget {
  const QFact(this.label, this.value, {super.key, this.latin = false});

  final String label;
  final String value;
  final bool latin;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: text.bodySmall?.copyWith(color: c.inkMuted)),
          const SizedBox(height: 2),
          latin ? Directionality(textDirection: TextDirection.ltr, child: Text(value, style: text.bodyLarge)) : Text(value, style: text.bodyLarge),
        ],
      ),
    );
  }
}

/// A message that sits above a form: what went wrong in one sentence.
class QNotice extends StatelessWidget {
  const QNotice(this.message, {super.key, this.tone = QTone.bad, this.icon});

  final String message;
  final QTone tone;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final (Color background, Color foreground) = switch (tone) {
      QTone.ok => (c.tintMint, c.positive),
      QTone.warn || QTone.gold => (c.tintSand, c.warning),
      QTone.info => (c.tintSky, c.info),
      _ => (c.tintBlush, c.danger),
    };

    return Semantics(
      liveRegion: true,
      container: true,
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd)),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (icon != null) ...[Icon(icon, size: 20, color: foreground), const SizedBox(width: 10)],
            Expanded(child: Text(message, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: foreground))),
          ],
        ),
      ),
    );
  }
}
