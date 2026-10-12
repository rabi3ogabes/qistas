import 'dart:math' as math;

import 'package:flutter/material.dart';

/// Columns drawn on the canvas (Win Plan K8, decision D7: no chart library): one per value, rising into place unless
/// the phone asks for less motion, the labels thinned out when there are many. A screen reader hears [summary]; the
/// list beside the chart carries the same figures.
class ColumnChart extends StatelessWidget {
  const ColumnChart({super.key, required this.values, required this.labels, required this.color, required this.summary, this.height = 170});

  final List<double> values;
  final List<String> labels;
  final Color color;
  final String summary;
  final double height;

  @override
  Widget build(BuildContext context) {
    final quiet = MediaQuery.disableAnimationsOf(context);
    final style = Theme.of(context).textTheme.labelSmall?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant);

    return Semantics(
      label: summary,
      container: true,
      child: ExcludeSemantics(
        child: SizedBox(
          height: height,
          width: double.infinity,
          child: TweenAnimationBuilder<double>(
            tween: Tween(begin: quiet ? 1 : 0, end: 1),
            duration: quiet ? Duration.zero : const Duration(milliseconds: 700),
            curve: Curves.easeOutCubic,
            builder: (context, progress, _) => CustomPaint(
              painter: _ColumnPainter(values: values, labels: labels, color: color, track: color.withValues(alpha: 0.12), progress: progress, labelStyle: style ?? const TextStyle(fontSize: 11)),
            ),
          ),
        ),
      ),
    );
  }
}

class _ColumnPainter extends CustomPainter {
  const _ColumnPainter({required this.values, required this.labels, required this.color, required this.track, required this.progress, required this.labelStyle});

  final List<double> values;
  final List<String> labels;
  final Color color;
  final Color track;
  final double progress;
  final TextStyle labelStyle;

  @override
  void paint(Canvas canvas, Size size) {
    if (values.isEmpty) return;
    const labelSpace = 22.0;
    final plot = size.height - labelSpace;
    final top = values.reduce(math.max);
    final slot = size.width / values.length;
    final bar = math.min(28.0, slot * 0.62);
    final every = values.length > 8 ? 2 : 1;

    // The baseline, then each column, rounded at its top.
    canvas.drawLine(Offset(0, plot + 0.5), Offset(size.width, plot + 0.5), Paint()..color = track..strokeWidth = 1);
    for (var i = 0; i < values.length; i++) {
      final x = i * slot + (slot - bar) / 2;
      final h = top <= 0 ? 0.0 : math.max(values[i] > 0 ? 2.0 : 0.0, values[i] / top * (plot - 4) * progress);
      if (h > 0) {
        canvas.drawRRect(
          RRect.fromRectAndCorners(Rect.fromLTWH(x, plot - h, bar, h), topLeft: const Radius.circular(4), topRight: const Radius.circular(4)),
          Paint()..color = color,
        );
      }
      if (i % every == 0 && i < labels.length) {
        final painter = TextPainter(text: TextSpan(text: labels[i], style: labelStyle), textDirection: TextDirection.ltr, maxLines: 1)..layout(maxWidth: slot * every);
        painter.paint(canvas, Offset(x + bar / 2 - painter.width / 2, plot + 6));
      }
    }
  }

  @override
  bool shouldRepaint(_ColumnPainter old) => old.progress != progress || old.color != color || old.values != values || old.labels != labels;
}
