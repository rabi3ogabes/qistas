import 'dart:math' as math;
import 'dart:ui' show PathMetric;

import 'package:flutter/material.dart';

import '../money.dart';
import 'tokens.dart';

// The finishing parts of the design: the things that make a screen feel considered rather than assembled. All of
// them keep still for a person who asked their phone for less motion.

/// Fades a child in and lifts it a little the first time it appears, [order] places into a gentle stagger.
class Reveal extends StatefulWidget {
  const Reveal({super.key, required this.child, this.order = 0});

  final Widget child;
  final int order;

  @override
  State<Reveal> createState() => _RevealState();
}

class _RevealState extends State<Reveal> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this, duration: const Duration(milliseconds: 480));
  late final Animation<double> _curve = CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic);
  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;

    if (MediaQuery.disableAnimationsOf(context)) {
      _controller.value = 1;
    } else {
      Future<void>.delayed(Duration(milliseconds: 55 * widget.order.clamp(0, 10)), () {
        if (mounted) _controller.forward();
      });
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: _curve,
        child: widget.child,
        builder: (context, child) => Opacity(
          opacity: _curve.value,
          child: Transform.translate(offset: Offset(0, 14 * (1 - _curve.value)), child: child),
        ),
      );
}

/// An amount that counts up to its value when it first appears, and glides to a new one when it changes.
class CountUpMoney extends StatelessWidget {
  const CountUpMoney(this.amount, this.currency, {super.key, this.style, this.color});

  final Money amount;
  final String currency;
  final TextStyle? style;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final target = amount.cents.toDouble();
    final base = (style ?? Theme.of(context).textTheme.headlineMedium)?.copyWith(color: color, fontFeatures: const [FontFeature.tabularFigures()]);
    final quiet = MediaQuery.disableAnimationsOf(context);

    return Directionality(
      textDirection: TextDirection.ltr,
      child: TweenAnimationBuilder<double>(
        tween: Tween(begin: quiet ? target : 0, end: target),
        duration: quiet ? Duration.zero : const Duration(milliseconds: 900),
        curve: Curves.easeOutCubic,
        builder: (context, value, _) => Text(Money.fromCents(BigInt.from(value.round())).format(currency), style: base),
      ),
    );
  }
}

/// The dark, gold-edged panel that carries the one figure a screen is about.
class HeroPanel extends StatelessWidget {
  const HeroPanel({super.key, required this.child, this.padding = const EdgeInsets.all(22)});

  final Widget child;
  final EdgeInsetsGeometry padding;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(QistasMetrics.radiusXl),
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [c.heroFrom, c.heroTo]),
        boxShadow: [BoxShadow(color: c.heroFrom.withValues(alpha: 0.28), blurRadius: 30, offset: const Offset(0, 14))],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(QistasMetrics.radiusXl),
        child: CustomPaint(
          painter: _HeroArcs(c.accent),
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
  }
}

class _HeroArcs extends CustomPainter {
  const _HeroArcs(this.gold);

  final Color gold;

  @override
  void paint(Canvas canvas, Size size) {
    final line = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.2
      ..color = gold.withValues(alpha: 0.22);

    final centre = Offset(size.width * 0.96, -size.height * 0.08);
    for (final radius in [size.width * 0.34, size.width * 0.5, size.width * 0.66]) {
      canvas.drawCircle(centre, radius, line..color = gold.withValues(alpha: 0.2 - radius / size.width * 0.12));
    }

    // A single warm glow, so the corner has depth rather than a flat fill.
    final glow = Paint()..shader = RadialGradient(colors: [gold.withValues(alpha: 0.22), gold.withValues(alpha: 0)]).createShader(Rect.fromCircle(center: centre, radius: size.width * 0.5));
    canvas.drawCircle(centre, size.width * 0.5, glow);
  }

  @override
  bool shouldRepaint(_HeroArcs old) => old.gold != gold;
}

/// A ring that fills to [value] (0 to 1) in gold, with [child] in the middle.
class ProgressRing extends StatelessWidget {
  const ProgressRing({super.key, required this.value, required this.child, this.size = 112, this.stroke = 11});

  final double value;
  final Widget child;
  final double size;
  final double stroke;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final quiet = MediaQuery.disableAnimationsOf(context);

    return SizedBox(
      width: size,
      height: size,
      child: TweenAnimationBuilder<double>(
        tween: Tween(begin: quiet ? value : 0, end: value.clamp(0, 1)),
        duration: quiet ? Duration.zero : const Duration(milliseconds: 1000),
        curve: Curves.easeOutCubic,
        builder: (context, progress, _) => CustomPaint(
          painter: _RingPainter(progress: progress, track: c.surfaceAlt, from: c.accent, to: c.accentText, stroke: stroke),
          // Whatever the text size, the figure stays inside the ring.
          child: Padding(padding: EdgeInsets.all(stroke + 8), child: Center(child: FittedBox(fit: BoxFit.scaleDown, child: child))),
        ),
      ),
    );
  }
}

class _RingPainter extends CustomPainter {
  const _RingPainter({required this.progress, required this.track, required this.from, required this.to, required this.stroke});

  final double progress;
  final Color track;
  final Color from;
  final Color to;
  final double stroke;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final arc = rect.deflate(stroke / 2);

    canvas.drawArc(arc, 0, math.pi * 2, false, Paint()..style = PaintingStyle.stroke..strokeWidth = stroke..color = track);

    if (progress <= 0) return;
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..shader = SweepGradient(startAngle: -math.pi / 2, endAngle: math.pi * 1.5, colors: [from, to], transform: const GradientRotation(-math.pi / 2)).createShader(rect);

    canvas.drawArc(arc, -math.pi / 2, math.pi * 2 * progress, false, paint);
  }

  @override
  bool shouldRepaint(_RingPainter old) => old.progress != progress || old.track != track || old.from != from || old.to != to;
}

/// A smooth line through [values] with a soft fill beneath it, drawn left to right. Pass the colour it should take.
class Sparkline extends StatelessWidget {
  const Sparkline({super.key, required this.values, required this.color, this.height = 56});

  final List<double> values;
  final Color color;
  final double height;

  @override
  Widget build(BuildContext context) {
    final quiet = MediaQuery.disableAnimationsOf(context);

    return SizedBox(
      height: height,
      width: double.infinity,
      child: TweenAnimationBuilder<double>(
        tween: Tween(begin: quiet ? 1 : 0, end: 1),
        duration: quiet ? Duration.zero : const Duration(milliseconds: 1100),
        curve: Curves.easeOutCubic,
        builder: (context, progress, _) => CustomPaint(painter: _SparklinePainter(values, color, progress)),
      ),
    );
  }
}

class _SparklinePainter extends CustomPainter {
  const _SparklinePainter(this.values, this.color, this.progress);

  final List<double> values;
  final Color color;
  final double progress;

  @override
  void paint(Canvas canvas, Size size) {
    if (values.length < 2) return;

    final top = values.reduce(math.max);
    final bottom = values.reduce(math.min);
    final span = top - bottom == 0 ? 1.0 : top - bottom;
    const pad = 6.0;
    final step = (size.width - pad * 2) / (values.length - 1);
    final points = [
      for (var i = 0; i < values.length; i++) Offset(pad + step * i, size.height - pad - (values[i] - bottom) / span * (size.height - pad * 2)),
    ];

    // A flat run of zeros sits on the baseline rather than floating in the middle.
    final flat = top == bottom;
    final path = Path()..moveTo(points.first.dx, flat ? size.height - pad : points.first.dy);
    for (var i = 0; i < points.length - 1; i++) {
      final a = points[i];
      final b = points[i + 1];
      final midX = (a.dx + b.dx) / 2;
      path.cubicTo(midX, flat ? size.height - pad : a.dy, midX, flat ? size.height - pad : b.dy, b.dx, flat ? size.height - pad : b.dy);
    }

    final reveal = Rect.fromLTWH(0, 0, size.width * progress, size.height);
    canvas.save();
    canvas.clipRect(reveal);

    final fill = Path.from(path)
      ..lineTo(points.last.dx, size.height)
      ..lineTo(points.first.dx, size.height)
      ..close();
    canvas.drawPath(
      fill,
      Paint()..shader = LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [color.withValues(alpha: 0.28), color.withValues(alpha: 0)]).createShader(Offset.zero & size),
    );
    canvas.drawPath(path, Paint()..style = PaintingStyle.stroke..strokeWidth = 2.4..strokeCap = StrokeCap.round..strokeJoin = StrokeJoin.round..color = color);
    canvas.restore();

    if (progress >= 0.999) {
      final last = Offset(points.last.dx, flat ? size.height - pad : points.last.dy);
      canvas.drawCircle(last, 5.5, Paint()..color = color.withValues(alpha: 0.28));
      canvas.drawCircle(last, 3, Paint()..color = color);
    }
  }

  @override
  bool shouldRepaint(_SparklinePainter old) => old.progress != progress || old.color != color || old.values != values;
}

/// The initials of a name on a soft tint that is always the same for the same name.
class InitialsAvatar extends StatelessWidget {
  const InitialsAvatar(this.name, {super.key, this.size = 44});

  final String name;
  final double size;

  static String initials(String name) {
    final words = name.trim().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    if (words.isEmpty) return '?';

    String first(String word) => String.fromCharCode(word.runes.first).toUpperCase();

    return words.length == 1 ? first(words.first) : first(words.first) + first(words.last);
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final tints = [c.tintSky, c.tintSand, c.tintMint, c.tintBlush];
    final tint = tints[name.runes.fold<int>(0, (sum, r) => sum + r) % tints.length];

    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: tint, shape: BoxShape.circle),
      child: Text(initials(name), style: Theme.of(context).textTheme.titleSmall?.copyWith(color: c.ink, fontSize: size * 0.36, height: 1)),
    );
  }
}

/// A small rounded label: a status, a change, a count.
class SoftChip extends StatelessWidget {
  const SoftChip(this.label, {super.key, this.icon, this.background, this.foreground, this.maxLines = 1});

  final String label;
  final IconData? icon;
  final Color? background;
  final Color? foreground;

  /// A long sentence in a narrow place wraps rather than being cut off.
  final int maxLines;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final color = foreground ?? c.ink;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: background ?? c.surfaceAlt, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[Icon(icon, size: 14, color: color), const SizedBox(width: 5)],
          Flexible(child: Text(label, style: Theme.of(context).textTheme.labelSmall?.copyWith(color: color), maxLines: maxLines, overflow: TextOverflow.ellipsis)),
        ],
      ),
    );
  }
}

/// Dashes along a path's length, for a timeline's connecting line.
Path dashed(Path source, {double dash = 4, double gap = 4}) {
  final out = Path();
  for (final PathMetric metric in source.computeMetrics()) {
    var distance = 0.0;
    while (distance < metric.length) {
      out.addPath(metric.extractPath(distance, math.min(distance + dash, metric.length)), Offset.zero);
      distance += dash + gap;
    }
  }

  return out;
}
