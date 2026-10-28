import 'dart:async';

import 'package:qistas/features/glance/glance_store.dart';

/// What the phone's home-screen widget is told, recorded: the Android widget has no platform channel in a test.
class FakeGlanceStore implements GlanceStore {
  final List<Map<String, String>> writes = [];
  int clears = 0;
  int redraws = 0;

  /// Runs when the widget is told to forget everything (a test looks at what else the app still holds at that moment).
  Future<void> Function()? onClear;

  Map<String, String> get last => writes.last;

  @override
  Future<void> write(Map<String, String> values) async => writes.add(Map.of(values));

  @override
  Future<void> clear() async {
    clears++;
    await onClear?.call();
  }

  @override
  Future<void> redraw() async => redraws++;

  /// A tap on the widget while the app runs: a test adds an event.
  final StreamController<void> tapped = StreamController<void>.broadcast();

  @override
  Stream<void> get taps => tapped.stream;
}
