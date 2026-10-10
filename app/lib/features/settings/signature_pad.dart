import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';

import '../../core/design/tokens.dart';
import '../../core/l10n/translations.dart';

/// The ink of a signature: the navy the documents are printed in.
const _ink = Color(0xFF0B1F44);

/// The strokes of a signature, and the signature as a transparent PNG for the documents.
class SignaturePadController extends ChangeNotifier {
  final List<List<Offset>> _strokes = [];
  Size _size = Size.zero;

  bool get isEmpty => _strokes.every((stroke) => stroke.length < 2);

  void clear() {
    _strokes.clear();
    notifyListeners();
  }

  void _start(Offset point) {
    _strokes.add([point]);
    notifyListeners();
  }

  void _extend(Offset point) {
    if (_strokes.isEmpty) return;
    _strokes.last.add(point);
    notifyListeners();
  }

  /// The signature on a transparent background, three times as sharp as on screen; null when nothing was drawn.
  Future<Uint8List?> toPng({double pixelRatio = 3}) async {
    if (isEmpty || _size.isEmpty) return null;

    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder)..scale(pixelRatio);
    _paintStrokes(canvas, _strokes);
    final image = await recorder.endRecording().toImage((_size.width * pixelRatio).ceil(), (_size.height * pixelRatio).ceil());
    final data = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();

    return data?.buffer.asUint8List();
  }
}

void _paintStrokes(Canvas canvas, List<List<Offset>> strokes) {
  final paint = Paint()
    ..color = _ink
    ..strokeWidth = 2.6
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round
    ..style = PaintingStyle.stroke;

  for (final stroke in strokes) {
    if (stroke.length < 2) continue;
    // Through the midpoints, so a quick stroke reads as a pen line rather than a chain of straight pieces.
    final path = Path()..moveTo(stroke.first.dx, stroke.first.dy);
    for (var i = 1; i < stroke.length - 1; i++) {
      final mid = Offset.lerp(stroke[i], stroke[i + 1], 0.5)!;
      path.quadraticBezierTo(stroke[i].dx, stroke[i].dy, mid.dx, mid.dy);
    }
    path.lineTo(stroke.last.dx, stroke.last.dy);
    canvas.drawPath(path, paint);
  }
}

/// A place to sign with a finger or a pen, on white with a line to sign on. It takes the finger as soon as it lands, so
/// signing inside a scrolling page never scrolls the page instead.
class SignaturePad extends StatelessWidget {
  const SignaturePad({super.key, required this.controller, this.height = 180});

  final SignaturePadController controller;
  final double height;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return Semantics(
      label: context.t('Sign here with your finger, a pen or the mouse'),
      child: Container(
        height: height,
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd), border: Border.all(color: c.line)),
        child: LayoutBuilder(
          builder: (context, constraints) {
            controller._size = constraints.biggest;

            return RawGestureDetector(
              behavior: HitTestBehavior.opaque,
              gestures: {
                _DrawRecognizer: GestureRecognizerFactoryWithHandlers<_DrawRecognizer>(
                  _DrawRecognizer.new,
                  (recognizer) => recognizer
                    ..onStart = controller._start
                    ..onUpdate = controller._extend,
                ),
              },
              child: ListenableBuilder(
                listenable: controller,
                builder: (context, _) => CustomPaint(
                  painter: _SignaturePainter(controller._strokes, line: c.line),
                  child: controller.isEmpty
                      ? Align(
                          alignment: const Alignment(0, 0.92),
                          child: Text(context.t('Sign here with your finger, a pen or the mouse.'), style: Theme.of(context).textTheme.bodySmall?.copyWith(color: c.inkMuted)),
                        )
                      : const SizedBox.expand(),
                ),
              ),
            );
          },
        ),
      ),
    );
  }
}

class _SignaturePainter extends CustomPainter {
  _SignaturePainter(this.strokes, {required this.line});

  final List<List<Offset>> strokes;
  final Color line;

  @override
  void paint(Canvas canvas, Size size) {
    final baseline = size.height - 34;
    canvas.drawLine(Offset(20, baseline), Offset(size.width - 20, baseline), Paint()
      ..color = line
      ..strokeWidth = 1);
    _paintStrokes(canvas, strokes);
  }

  // The strokes list is changed in place as the finger moves: always repaint.
  @override
  bool shouldRepaint(covariant _SignaturePainter oldDelegate) => true;
}

/// Claims the pointer the moment it lands (a scrolling page around the pad never gets it), then reports where it goes.
class _DrawRecognizer extends OneSequenceGestureRecognizer {
  ValueChanged<Offset>? onStart;
  ValueChanged<Offset>? onUpdate;

  @override
  void addAllowedPointer(PointerDownEvent event) {
    super.addAllowedPointer(event);
    resolve(GestureDisposition.accepted);
    onStart?.call(event.localPosition);
  }

  @override
  void handleEvent(PointerEvent event) {
    if (event is PointerMoveEvent) onUpdate?.call(event.localPosition);
    if (event is PointerUpEvent || event is PointerCancelEvent) stopTrackingPointer(event.pointer);
  }

  @override
  void didStopTrackingLastPointer(int pointer) {}

  @override
  String get debugDescription => 'signature';
}
