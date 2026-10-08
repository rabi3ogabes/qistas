import 'package:flutter/material.dart';

import 'tokens.dart';

/// The Qistas mark, "Plumb q": a ring, a stem, and a small gold plumb bob hanging below it. Drawn from the same
/// shapes as the logo files (viewBox 160 x 226), so it is exact at any size and takes the theme's colours.
///
/// [drop] moves the bob: 0 is hanging in place, 1 is lifted out of view. The splash animates it settling.
class QistasSymbol extends StatelessWidget {
  const QistasSymbol({super.key, this.height = 56, this.drop = 0, this.ink, this.accent});

  final double height;
  final double drop;
  final Color? ink;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final box = height * 160 / 226;

    return Semantics(
      label: 'Qistas',
      image: true,
      child: SizedBox(width: box, height: height, child: CustomPaint(painter: _SymbolPainter(ink ?? c.ink, accent ?? c.accent, drop))),
    );
  }
}

class _SymbolPainter extends CustomPainter {
  _SymbolPainter(this.ink, this.accent, this.drop);

  final Color ink;
  final Color accent;
  final double drop;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.scale(size.width / 160, size.height / 226);
    canvas.translate(10, 10);

    final inkPaint = Paint()..color = ink..isAntiAlias = true;
    // The ring: an outer circle of radius 70 with a hole of radius 44.
    final ring = Path()
      ..fillType = PathFillType.evenOdd
      ..addOval(Rect.fromCircle(center: const Offset(70, 70), radius: 70))
      ..addOval(Rect.fromCircle(center: const Offset(70, 70), radius: 44));
    canvas.drawPath(ring, inkPaint);
    canvas.drawRect(const Rect.fromLTWH(114, 0, 26, 176), inkPaint);

    // The bob, hanging from the stem; lifted by [drop] of the height above it.
    canvas.drawCircle(Offset(127, 184 - drop * 230), 22, Paint()..color = accent..isAntiAlias = true);
  }

  @override
  bool shouldRepaint(_SymbolPainter old) => old.ink != ink || old.accent != accent || old.drop != drop;
}
