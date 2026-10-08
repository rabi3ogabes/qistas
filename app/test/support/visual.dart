import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

/// Pictures of the app, for looking at: real brand fonts and icons instead of the test font, written as PNG files.
///
///     QISTAS_SCREENSHOTS=build/shots flutter test test/visual
///
/// Without that variable the visual tests are skipped, so an ordinary `flutter test` never writes files.
final String? screenshotFolder = Platform.environment['QISTAS_SCREENSHOTS'];

final GlobalKey screenshotKey = GlobalKey(debugLabel: 'screenshot');

const Map<String, List<String>> _brandFonts = {
  'Geist': ['Geist-Regular', 'Geist-Medium', 'Geist-SemiBold', 'Geist-Bold'],
  'CormorantGaramond': ['CormorantGaramond-SemiBold', 'CormorantGaramond-Bold'],
  'IBMPlexSansArabic': ['IBMPlexSansArabic-Regular', 'IBMPlexSansArabic-Medium', 'IBMPlexSansArabic-SemiBold', 'IBMPlexSansArabic-Bold'],
  'NotoNastaliqUrdu': ['NotoNastaliqUrdu-Medium', 'NotoNastaliqUrdu-SemiBold'],
};

bool _loaded = false;

/// Registers the bundled brand fonts and the Material icon font with the test engine.
Future<void> loadBrandFonts() async {
  if (_loaded) return;
  _loaded = true;

  Future<void> load(String family, List<File> files) async {
    final loader = FontLoader(family);
    for (final file in files) {
      loader.addFont(Future.value(ByteData.view(file.readAsBytesSync().buffer)));
    }
    await loader.load();
  }

  for (final entry in _brandFonts.entries) {
    await load(entry.key, [for (final name in entry.value) File('assets/fonts/$name.ttf')]);
  }

  final sdk = Platform.environment['FLUTTER_ROOT'];
  if (sdk != null) {
    final icons = File('$sdk/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf');
    if (icons.existsSync()) await load('MaterialIcons', [icons]);
  }
}

/// Writes what the screen shows now to `<folder>/<name>.png`.
Future<void> snapshot(WidgetTester tester, String name, {double pixelRatio = 2}) async {
  final folder = screenshotFolder;
  if (folder == null) return;

  final boundary = tester.renderObject<RenderRepaintBoundary>(find.byKey(screenshotKey));
  final bytes = await tester.runAsync(() async {
    final ui.Image image = await boundary.toImage(pixelRatio: pixelRatio);
    final data = await image.toByteData(format: ui.ImageByteFormat.png);

    return data!.buffer.asUint8List();
  });

  Directory(folder).createSync(recursive: true);
  File('$folder/$name.png').writeAsBytesSync(Uint8List.fromList(bytes!));
}
