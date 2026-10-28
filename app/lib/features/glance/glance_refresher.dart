import 'package:dio/dio.dart' show HttpClientAdapter;
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../app/providers.dart';
import '../../core/api/api_client.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/l10n/translations.dart';
import '../../core/storage/token_store.dart';
import '../../data/qistas_api.dart';
import '../security/app_lock.dart';
import 'glance_data.dart';
import 'glance_encoder.dart';
import 'glance_privacy.dart';
import 'glance_service.dart';
import 'glance_store.dart';

/// Refreshes the home-screen widget while the app is closed: the server's silent push wakes a background isolate, which
/// runs this (see `qistasBackgroundMessage`). It has no Riverpod and no screens, so it reads what the app left on the
/// phone (the token in the secure store, the preferences, the account last seen) and does what [GlanceService] does
/// for an open app, with the same encoder and the same privacy rules.
///
/// Best effort, and it never throws: whatever goes wrong, the widget keeps the figures it has. Its log says only how a
/// run ended, never a token, a name or an amount.
class GlanceRefresher {
  GlanceRefresher({
    TokenStore? tokens,
    GlanceStore? store,
    HttpClientAdapter? adapter,
    DateTime Function()? clock,
    bool? supported,
    Future<Translations> Function(String language)? loadWords,
  })  : _tokens = tokens ?? SecureTokenStore(),
        _store = store ?? HomeWidgetStore(),
        _adapter = adapter,
        _clock = clock ?? DateTime.now,
        _supported = supported ?? isAndroidPhone,
        _loadWords = loadWords ?? Translations.load;

  final TokenStore _tokens;
  final GlanceStore _store;
  final HttpClientAdapter? _adapter;
  final DateTime Function() _clock;
  final bool _supported;
  final Future<Translations> Function(String language) _loadWords;

  /// Reads the glance and hands it to the widget. True only when the widget was given something new.
  Future<bool> run() async {
    if (!_supported) return false;

    try {
      return await _refresh();
    } on Object {
      // Nothing is said about the error itself: its text can carry what the server answered.
      _log('failed, the widget keeps what it has');

      return false;
    }
  }

  Future<bool> _refresh() async {
    final token = await _tokens.read();
    if (token == null) {
      _log('nobody is signed in');

      return false;
    }

    // A background isolate that lives on between pushes would otherwise keep reading what it read the first time.
    final prefs = await SharedPreferences.getInstance();
    await prefs.reload();

    final privacy = GlancePrivacy.fromPreferences(prefs);
    final appLockOn = _appLockOn(prefs);
    final language = LocaleController.resolve(prefs, PlatformDispatcher.instance.locale.languageCode);

    // The same client the app builds (see apiClientProvider), without the app: a 401 here only means this widget has
    // nothing left to show, and the app itself signs out the next time it opens.
    final client = ApiClient(baseUrl: AppConfig.apiBaseUrl, tokens: _tokens, language: () => language, onUnauthorized: () {}, adapter: _adapter);

    final answer = await _ask(QistasApi(client), names: privacy.namesShown(appLockOn: appLockOn));
    if (answer == null) return false;

    // Signed out (or into another account) while the answer was on its way: the widget was emptied, and stays empty.
    if (await _tokens.read() != token) {
      _log('the person signed out meanwhile, the answer was dropped');

      return false;
    }

    final words = await _loadWords(language);
    final values = encodeGlance(answer, privacy: privacy, appLockOn: appLockOn, language: words.language, rtl: words.isRtl, words: words, now: _clock());
    await _store.write(values);
    await _store.redraw();
    _log('the widget was refreshed');

    return true;
  }

  /// The glance, or null when there is nothing new to write (and the widget was left, or emptied, as it should be).
  Future<GlanceData?> _ask(QistasApi api, {required bool names}) async {
    try {
      return await api.glance(names: names);
    } on ApiException catch (error) {
      if (error.status == 401) {
        // The server no longer knows this phone's token: the session is over, so nothing of the business stays on the
        // home screen.
        await _store.clear();
        await _store.redraw();
        _log('the session is over, the widget was emptied');
      } else {
        _log(error.isNetwork ? 'no connection, the widget keeps what it has' : 'the server refused, the widget keeps what it has');
      }

      return null;
    }
  }

  /// The app lock, as the app counts it (see [AppLockState.active]): the person turned it on, or the business requires it.
  /// When the business's side cannot be read the lock is taken to be on, so only the counts reach the home screen.
  bool _appLockOn(SharedPreferences prefs) {
    final account = AuthController.cachedAccount(prefs);

    return account == null || account.requireAppLock || (prefs.getBool(AppLockController.enabledKey) ?? false);
  }

  void _log(String outcome) => debugPrint('Qistas widget refresh: $outcome');
}
