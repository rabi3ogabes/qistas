import 'package:flutter/material.dart';

/// A Google Play picture (Win Plan 6.7): a large caption on the brand's navy above the real app, drawn as a phone.
///
/// The picture is [size] logical pixels, written at a pixel ratio of 2: 1080 × 1920, Play's phone size. The app is laid
/// out at [phone], a common phone's size, and drawn smaller inside the frame, so every screen reads as it does in a hand.
class StoreFrame extends StatelessWidget {
  const StoreFrame({super.key, required this.caption, required this.language, required this.app});

  static const Size size = Size(540, 960);
  static const Size phone = Size(390, 800);

  static const Color _navy = Color(0xFF0B1F44);
  static const Color _navyDeep = Color(0xFF1B3A78);
  static const Color _ivory = Color(0xFFF7F3EA);
  static const Color _gold = Color(0xFFC9A25B);

  final String caption;
  final String language;
  final Widget app;

  bool get _rtl => language == 'ar' || language == 'ur';

  /// The caption in the brand's display face for its script: Cormorant for Latin letters, IBM Plex Sans Arabic for
  /// Arabic, Noto Nastaliq for Urdu (which needs room above and below its letters).
  TextStyle get _captionStyle => switch (language) {
        'ar' => const TextStyle(fontFamily: 'IBMPlexSansArabic', fontWeight: FontWeight.w600, fontSize: 38, height: 1.35),
        'ur' => const TextStyle(fontFamily: 'NotoNastaliqUrdu', fontWeight: FontWeight.w600, fontSize: 30, height: 1.9),
        _ => const TextStyle(fontFamily: 'CormorantGaramond', fontWeight: FontWeight.w600, fontSize: 46, height: 1.08, letterSpacing: -.3),
      };

  @override
  Widget build(BuildContext context) {
    return Directionality(
      textDirection: _rtl ? TextDirection.rtl : TextDirection.ltr,
      child: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [_navy, _navyDeep]),
        ),
        child: Column(
          children: [
            SizedBox(
              height: 232,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(44, 52, 44, 24),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Container(width: 36, height: 3, decoration: BoxDecoration(color: _gold, borderRadius: BorderRadius.circular(2))),
                    const SizedBox(height: 22),
                    Text(caption, textAlign: TextAlign.center, maxLines: 2, style: _captionStyle.copyWith(color: _ivory)),
                  ],
                ),
              ),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(40, 4, 40, 40),
                child: FittedBox(
                  child: Container(
                    padding: const EdgeInsets.all(9),
                    decoration: BoxDecoration(
                      color: const Color(0xFF050E22),
                      borderRadius: BorderRadius.circular(46),
                      boxShadow: const [BoxShadow(color: Color(0x66000000), blurRadius: 48, offset: Offset(0, 22))],
                    ),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(37),
                      child: SizedBox.fromSize(
                        size: phone,
                        // The app sees a phone of its own, whatever the frame around it.
                        child: MediaQuery(
                          data: const MediaQueryData(size: phone, devicePixelRatio: 2, textScaler: TextScaler.noScaling),
                          child: app,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
