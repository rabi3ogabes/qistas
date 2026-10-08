import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where the sign-in token lives. It is the one secret the app holds, so it never goes anywhere else: not a log, not
/// a preference file, not a crash report.
abstract class TokenStore {
  Future<String?> read();

  Future<void> write(String token);

  Future<void> clear();
}

/// The platform's secure storage: the Keychain on iOS, the Keystore on Android. (On the web the browser offers
/// nothing safer than its own storage; the token is encrypted there, which is best effort.)
class SecureTokenStore implements TokenStore {
  SecureTokenStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  static const String _key = 'qistas.token';
  final FlutterSecureStorage _storage;

  @override
  Future<String?> read() => _storage.read(key: _key);

  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

/// For tests, and for a session that must not outlive the process.
class MemoryTokenStore implements TokenStore {
  MemoryTokenStore([this._token]);

  String? _token;

  @override
  Future<String?> read() async => _token;

  @override
  Future<void> write(String token) async => _token = token;

  @override
  Future<void> clear() async => _token = null;
}
