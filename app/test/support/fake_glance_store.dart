import 'package:qistas/features/glance/glance_store.dart';

/// What the phone's home-screen widget is told, recorded: the Android widget has no platform channel in a test.
class FakeGlanceStore implements GlanceStore {
  final List<Map<String, String>> writes = [];
  int clears = 0;
  int redraws = 0;

  Map<String, String> get last => writes.last;

  @override
  Future<void> write(Map<String, String> values) async => writes.add(Map.of(values));

  @override
  Future<void> clear() async => clears++;

  @override
  Future<void> redraw() async => redraws++;
}
