import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/l10n/translations.dart';
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

  /// The last answer read and the moment it was read, so a change of privacy or language can be drawn at once, without
  /// a connection, and still say when the figures are from.
  ({GlanceData data, DateTime at})? _last;
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
  /// again for what the new rules need (the names, once they are allowed). The redraw says when the figures were read,
  /// not now: a new language does not make old figures fresh.
  Future<void> restyle() async {
    if (!_supported) return;

    final last = _last;
    await _safely(last == null ? _hideStored : () => _draw(last.data, last.at, _epoch));
    await refresh();
  }

  /// A fresh process has no answer in memory, but the widget still holds the last one it was given, and that may carry
  /// names or amounts the rules no longer allow (names switched off, the app lock turned on). Until a read can say more,
  /// blank the rows that carry them and touch nothing else.
  Future<void> _hideStored() async {
    if (_ref.read(accountProvider) == null) return;

    final namesShown = _ref.read(glancePrivacyProvider).namesShown(appLockOn: _ref.read(appLockProvider).active);
    if (namesShown) return;

    final store = _ref.read(glanceStoreProvider);
    await store.write({for (final key in glanceSensitiveKeys) key: ''});
    await store.redraw();
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
      if (_last?.data.enabled != false) await _draw(GlanceData.disabled(), _ref.read(clockProvider)(), epoch);

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

    await _draw(data, _ref.read(clockProvider)(), epoch);
  }

  /// Hands the widget [data], read at [at].
  Future<void> _draw(GlanceData data, DateTime at, int epoch) async {
    final words = await _words();
    // Signed out while the answer or the words were on their way: the widget was emptied and stays empty.
    if (epoch != _epoch) return;

    _last = (data: data, at: at);
    // The rules are read now, not when the question was asked: they may have changed since.
    final values = encodeGlance(
      data,
      privacy: _ref.read(glancePrivacyProvider),
      appLockOn: _ref.read(appLockProvider).active,
      language: words.language,
      rtl: words.isRtl,
      words: words,
      now: at,
    );
    final store = _ref.read(glanceStoreProvider);
    await store.write(values);
    await store.redraw();
  }

  /// The words of the app's language now. Asked for in the very moment the language changes (the listener that redraws the
  /// widget runs inside that notification), the words provider can still hold the previous language's: ask once more.
  Future<Translations> _words() async {
    final language = _ref.read(localeProvider);
    final words = await _ref.read(translationsProvider.future);

    return words.language == language ? words : _ref.read(translationsProvider.future);
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
