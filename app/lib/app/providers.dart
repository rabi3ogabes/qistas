import 'dart:convert';

import 'package:dio/dio.dart' show HttpClientAdapter;
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/api/api_client.dart';
import '../core/api/api_exception.dart';
import '../core/config.dart';
import '../core/l10n/translations.dart';
import '../core/storage/token_store.dart';
import '../data/models.dart';
import '../data/qistas_api.dart';

/// Overridden in main() (and in tests) with the opened preferences.
final sharedPreferencesProvider = Provider<SharedPreferences>((ref) => throw UnimplementedError('sharedPreferencesProvider must be overridden'));

final tokenStoreProvider = Provider<TokenStore>((ref) => SecureTokenStore());

/// How long the splash stays at least; tests and reduced motion make it zero.
final splashDurationProvider = Provider<Duration>((ref) => const Duration(milliseconds: 1400));

final splashDoneProvider = FutureProvider<void>((ref) => Future<void>.delayed(ref.watch(splashDurationProvider)));

/// Fonts come from Google Fonts at run time; tests turn this off so nothing touches the network.
final webFontsProvider = Provider<bool>((ref) => true);

// ------------------------------------------------------------------ preferences

/// The app's language, remembered between launches. First launch: the phone's language if the app speaks it.
class LocaleController extends Notifier<String> {
  static const String key = 'language';

  @override
  String build() {
    final saved = ref.read(sharedPreferencesProvider).getString(key);
    if (saved != null && AppConfig.locales.contains(saved)) return saved;

    final device = WidgetsBinding.instance.platformDispatcher.locale.languageCode;

    return AppConfig.locales.contains(device) ? device : 'en';
  }

  Future<void> choose(String language) async {
    if (!AppConfig.locales.contains(language)) return;
    state = language;
    await ref.read(sharedPreferencesProvider).setString(key, language);
  }
}

final localeProvider = NotifierProvider<LocaleController, String>(LocaleController.new);

final translationsProvider = FutureProvider<Translations>((ref) => Translations.load(ref.watch(localeProvider)));

class ThemeModeController extends Notifier<ThemeMode> {
  static const String key = 'theme_mode';

  @override
  ThemeMode build() {
    final saved = ref.read(sharedPreferencesProvider).getString(key);

    return ThemeMode.values.firstWhere((mode) => mode.name == saved, orElse: () => ThemeMode.system);
  }

  Future<void> choose(ThemeMode mode) async {
    state = mode;
    await ref.read(sharedPreferencesProvider).setString(key, mode.name);
  }
}

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(ThemeModeController.new);

const String onboardedKey = 'onboarded';

// ---------------------------------------------------------------------- the API

/// Tests replace the network with a fake; the app uses the platform's own.
final httpAdapterProvider = Provider<HttpClientAdapter?>((ref) => null);

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient(
      baseUrl: AppConfig.apiBaseUrl,
      tokens: ref.watch(tokenStoreProvider),
      language: () => ref.read(localeProvider),
      onUnauthorized: () => ref.read(authProvider.notifier).sessionEnded(),
      adapter: ref.watch(httpAdapterProvider),
    ));

final apiProvider = Provider<QistasApi>((ref) => QistasApi(ref.watch(apiClientProvider)));

// ----------------------------------------------------------------- the session

@immutable
class AuthState {
  const AuthState.signedOut({this.sessionEnded = false})
      : account = null,
        offline = false;

  const AuthState.signedIn(Account this.account, {this.offline = false}) : sessionEnded = false;

  final Account? account;

  /// The account shown is the copy kept on this phone: there was no connection to ask the server.
  final bool offline;

  /// The server ended the session (an expired or revoked token): say so once on the sign-in screen.
  final bool sessionEnded;

  bool get isSignedIn => account != null;
}

/// Who is signed in. Starts by looking for a token on this phone and asking the server who it belongs to.
class AuthController extends AsyncNotifier<AuthState> {
  static const String accountKey = 'account';

  SharedPreferences get _prefs => ref.read(sharedPreferencesProvider);

  @override
  Future<AuthState> build() async {
    final token = await ref.read(tokenStoreProvider).read();
    if (token == null) return const AuthState.signedOut();

    try {
      final account = await ref.read(apiProvider).me();
      await _remember(account);

      return AuthState.signedIn(account);
    } on ApiException catch (e) {
      if (e.isNetwork) {
        // No connection at launch: show what was last known rather than a blank screen.
        final cached = _cached();

        return cached == null ? const AuthState.signedOut() : AuthState.signedIn(cached, offline: true);
      }
      // Anything else (a revoked token, a suspended account) means this phone is no longer signed in.
      await _forget();

      return const AuthState.signedOut(sessionEnded: true);
    }
  }

  Future<void> signIn({required String email, required String password, String? code, String? recoveryCode}) async {
    final session = await ref.read(apiProvider).login(
          email: email.trim(),
          password: password,
          deviceName: deviceName(),
          code: code,
          recoveryCode: recoveryCode,
        );
    await _signedIn(session);
  }

  Future<void> register({
    required String name,
    required String email,
    required String password,
    required String businessName,
    required String country,
  }) async {
    final session = await ref.read(apiProvider).register(
          name: name.trim(),
          email: email.trim(),
          password: password,
          businessName: businessName.trim(),
          country: country,
          locale: ref.read(localeProvider),
          deviceName: deviceName(),
        );
    await _signedIn(session);
  }

  /// Asks the server again who this is and what the plan allows: after a payment, a limit, a change of plan.
  Future<void> refresh() async {
    final current = state.valueOrNull;
    if (current == null || !current.isSignedIn) return;

    try {
      final account = await ref.read(apiProvider).me();
      await _remember(account);
      state = AsyncData(AuthState.signedIn(account));
    } on ApiException {
      // Keep what is on screen: the next call will say what is wrong.
    }
  }

  Future<void> signOut() async {
    try {
      await ref.read(apiProvider).logout();
    } on ApiException {
      // The token is dropped on this phone either way.
    }
    await _forget();
    state = const AsyncData(AuthState.signedOut());
  }

  Future<void> signOutEverywhere() async {
    try {
      await ref.read(apiProvider).logoutEverywhere();
    } on ApiException {
      // Still sign out here.
    }
    await _forget();
    state = const AsyncData(AuthState.signedOut());
  }

  /// The server said the token is no good: back to the sign-in screen, once, with an explanation.
  Future<void> sessionEnded() async {
    await _forget();
    state = const AsyncData(AuthState.signedOut(sessionEnded: true));
  }

  Future<void> _signedIn(SignedIn session) async {
    await ref.read(tokenStoreProvider).write(session.token);
    await _remember(session.account);
    state = AsyncData(AuthState.signedIn(session.account));
  }

  Future<void> _remember(Account account) => _prefs.setString(accountKey, jsonEncode(account.toJson()));

  Account? _cached() {
    final raw = _prefs.getString(accountKey);
    if (raw == null) return null;

    try {
      return Account.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } on Object {
      return null;
    }
  }

  Future<void> _forget() async {
    await ref.read(tokenStoreProvider).clear();
    await _prefs.remove(accountKey);
  }

  /// What this phone is called in the list of signed-in devices.
  static String deviceName() => kIsWeb ? 'Qistas (web browser)' : 'Qistas app (${defaultTargetPlatform.name})';
}

final authProvider = AsyncNotifierProvider<AuthController, AuthState>(AuthController.new);

/// The signed-in account, or null.
final accountProvider = Provider<Account?>((ref) => ref.watch(authProvider).valueOrNull?.account);
