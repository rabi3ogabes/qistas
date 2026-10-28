import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:home_widget/home_widget.dart';

import 'glance_encoder.dart';

/// Where the home-screen widget's strings are kept, and how it is told to draw them. The phone's implementation is
/// [HomeWidgetStore]; tests replace it, because widget tests have no platform channels.
abstract class GlanceStore {
  /// Keeps [values] (the keys of [glanceKeys]) where the widget reads them.
  Future<void> write(Map<String, String> values);

  /// Forgets every key, so the widget has nothing of the business left to show.
  Future<void> clear();

  /// Asks the widget to draw what is kept now.
  Future<void> redraw();
}

/// The Android widget's storage, through the `home_widget` package. It never throws: on a phone or a platform without
/// the widget (iOS, desktop, the web) the platform channel is missing, and nothing is stored or drawn.
class HomeWidgetStore implements GlanceStore {
  /// The widget's class in the app's Android package (written in Kotlin, with the widget itself).
  static const String provider = 'GlanceWidgetProvider';

  @override
  Future<void> write(Map<String, String> values) async {
    for (final key in _sensitiveFirst(values.keys)) {
      await _guard(() => HomeWidget.saveWidgetData<String>(key, values[key]));
    }
  }

  @override
  Future<void> clear() async {
    for (final key in _sensitiveFirst(glanceKeys)) {
      await _guard(() => HomeWidget.saveWidgetData<String>(key, null, deleteFile: false));
    }
  }

  /// The keys that can carry amounts and names, then the rest: the widget is told one key at a time, and a write that is
  /// cut short must have changed the rows that matter before the counts.
  List<String> _sensitiveFirst(Iterable<String> keys) => [...keys.where(glanceSensitiveKeys.contains), ...keys.where((key) => !glanceSensitiveKeys.contains(key))];

  @override
  Future<void> redraw() => _guard(() => HomeWidget.updateWidget(androidName: provider));

  Future<void> _guard(Future<Object?> Function() call) async {
    try {
      await call();
    } on Object {
      // No widget on this platform, or its class is not in this build: there is nothing to keep fresh.
    }
  }
}

final glanceStoreProvider = Provider<GlanceStore>((ref) => HomeWidgetStore());
