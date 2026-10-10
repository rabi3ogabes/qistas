import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/design/tokens.dart';
import '../../core/l10n/translations.dart';

/// Opens the camera and answers the first barcode or IMEI it reads, or null when the person backs out. A provider, so
/// tests answer for the camera.
final barcodeScannerProvider = Provider<Future<String?> Function(BuildContext)>((ref) => scanBarcode);

Future<String?> scanBarcode(BuildContext context) => Navigator.of(context).push<String>(
      MaterialPageRoute(fullscreenDialog: true, builder: (_) => const ScannerScreen()),
    );

/// The camera, with a frame to aim at; the first code it reads closes it.
class ScannerScreen extends StatefulWidget {
  const ScannerScreen({super.key});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> {
  final _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates);
  bool _done = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _detected(BarcodeCapture capture) {
    if (_done) return;
    final value = capture.barcodes.map((b) => b.rawValue?.trim()).whereType<String>().where((v) => v.isNotEmpty).firstOrNull;
    if (value == null) return;
    _done = true;
    Navigator.of(context).pop(value);
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(context.t('Scan the serial or IMEI')),
        actions: [IconButton(tooltip: context.t('Torch'), icon: const Icon(Icons.flashlight_on_outlined), onPressed: _controller.toggleTorch)],
      ),
      body: Stack(
        fit: StackFit.expand,
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: _detected,
            errorBuilder: (context, error) => Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text(context.t('The camera is not available. Allow Qistas to use it in the phone’s settings, or type the number.'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white)),
              ),
            ),
          ),
          IgnorePointer(
            child: Center(
              child: Container(
                width: 280,
                height: 160,
                decoration: BoxDecoration(border: Border.all(color: c.accent, width: 2), borderRadius: BorderRadius.circular(QistasMetrics.radiusLg)),
              ),
            ),
          ),
          Positioned(
            left: 24,
            right: 24,
            bottom: 48,
            child: Text(context.t('Hold the barcode or the IMEI inside the frame.'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white70)),
          ),
        ],
      ),
    );
  }
}
