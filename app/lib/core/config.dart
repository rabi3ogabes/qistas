/// Settings fixed when the app is built. The app contains no secrets: only where the API lives.
///
///     flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080/api/v1     (Android emulator to docker compose)
class AppConfig {
  const AppConfig._();

  /// The API every screen talks to.
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://qistas-puce.vercel.app/api/v1',
  );

  /// Where Pro is bought: the website. (A store build uses the store's own billing instead.)
  static const String webUrl = String.fromEnvironment('WEB_URL', defaultValue: 'https://qistas-puce.vercel.app');

  /// Shown on the settings screen; the build sets it from pubspec (--dart-define=APP_VERSION=1.7.0).
  static const String appVersion = String.fromEnvironment('APP_VERSION', defaultValue: '1.7.0');

  /// Languages the app speaks, in the order they are offered.
  static const List<String> locales = ['en', 'ar', 'fr', 'es', 'ur'];

  /// Languages written right to left.
  static const Set<String> rtlLocales = {'ar', 'ur'};
}
