import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../data/models.dart';
import '../security/app_lock.dart';
import 'glance_data.dart';
import 'glance_encoder.dart';
import 'glance_privacy.dart';
import 'glance_store.dart';

/// Only an Android phone has the widget; elsewhere the app neither reads the glance nor keeps anything for it. Tests
/// turn it on, with a fake store.
final glanceSupportedProvider = Provider<bool>((ref) => !kIsWeb && Platform.isAndroid);

/// The settings row and the refreshing come with the feature: only once the server lists it for this workspace and it is
/// on (an older server never lists it).
bool offersWidget(Account? account) => account?.entitlements['home_widget']?.isOn ?? false;

/// Keeps the home-screen widget's figures fresh: reads the glance, applies the privacy rules and hands the widget its
/// strings. Best effort, and never in the way: every failure leaves the widget showing what it showed.
class GlanceService {
  GlanceService(this._ref);

  final Ref _ref;

  /// The last answer read, so a change of privacy or language can be drawn at once, without a connection.
  GlanceData? _last;
  Future<void>? _running;
  bool _again = false;

  /// Raised by [clear]: an answer that was on its way when the person signed out is dropped.
  int _epoch = 0;

  bool get _supported => _ref.read(glanceSupportedProvider);

  /// Reads the glance and redraws the widget. A refresh asked for while one is running is not lost: once the running
  /// one is done, the answer is read once more (it may have been asked for just after a payment).
  Future<void> refresh() {
    if (!_supported) return Future.value();

    final running = _running;
    if (running != null) {
      _again = true;

      return running;
    }

    return _running = _drain();
  }

  Future<void> _drain() async {
    try {
      do {
        _again = false;
        await _safely(_readAndDraw);
      } while (_again);
    } finally {
      _running = null;
    }
  }

  /// A choice that changes how the widget is drawn changed (the app lock, a privacy switch, the language): draw the last
  /// answer again at once, so nothing stays on a home screen under the old rules even with no connection, then read
  /// again for what the new rules need (the names, once they are allowed).
  Future<void> restyle() async {
    if (!_supported) return;

    final last = _last;
    if (last != null) await _safely(() => _draw(last, _epoch));
    await refresh();
  }

  /// Signed out: the widget has nothing of the business left to show.
  Future<void> clear() async {
    _epoch++;
    _last = null;
    if (!_supported) return;

    final store = _ref.read(glanceStoreProvider);
    await store.clear();
    await store.redraw();
  }

  Future<void> _readAndDraw() async {
    final epoch = _epoch;
    final account = _ref.read(accountProvider);
    if (account == null) return;

    if (!offersWidget(account)) {
      // The platform or the plan does not have it (any more): an installed widget must not keep its old figures. Once is
      // enough; there is nothing to ask the server.
      if (_last?.enabled != false) await _draw(GlanceData.disabled(), epoch);

      return;
    }

    final privacy = _ref.read(glancePrivacyProvider);
    final appLockOn = _ref.read(appLockProvider).active;
    final GlanceData data;
    try {
      data = await _ref.read(apiProvider).glance(names: privacy.namesShown(appLockOn: appLockOn));
    } on Object {
      // No connection, a server error or an answer nobody can read: the widget keeps the figures it has.
      return;
    }

    await _draw(data, epoch);
  }

  Future<void> _draw(GlanceData data, int epoch) async {
    final words = await _ref.read(translationsProvider.future);
    // Signed out while the answer or the words were on their way: the widget was emptied and stays empty.
    if (epoch != _epoch) return;

    _last = data;
    // The rules are read now, not when the question was asked: they may have changed since.
    final values = encodeGlance(
      data,
      privacy: _ref.read(glancePrivacyProvider),
      appLockOn: _ref.read(appLockProvider).active,
      language: words.language,
      rtl: words.isRtl,
      words: words,
      now: _ref.read(clockProvider)(),
    );
    final store = _ref.read(glanceStoreProvider);
    await store.write(values);
    await store.redraw();
  }

  /// The widget is a courtesy: whatever goes wrong while keeping it fresh must never reach the screen that asked.
  Future<void> _safely(Future<void> Function() job) async {
    try {
      await job();
    } on Object {
      // The next refresh tries again.
    }
  }
}

final glanceServiceProvider = Provider<GlanceService>((ref) => GlanceService(ref));
