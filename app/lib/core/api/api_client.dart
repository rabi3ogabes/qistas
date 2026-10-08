import 'dart:convert';

import 'package:dio/dio.dart';

import '../storage/token_store.dart';
import 'api_exception.dart';

/// Talks to the Qistas API: adds the sign-in token and the language to every request, and turns every failure into
/// an [ApiException] (a 402 into [UpgradeRequired]).
///
/// When the server says the token is no longer good (401) the app is signed out exactly once, however many
/// requests were in flight, through [onUnauthorized].
class ApiClient {
  ApiClient({
    required String baseUrl,
    required TokenStore tokens,
    required String Function() language,
    required void Function() onUnauthorized,
    HttpClientAdapter? adapter,
  })  : _tokens = tokens,
        _language = language,
        _onUnauthorized = onUnauthorized,
        _dio = Dio(BaseOptions(
          baseUrl: baseUrl.endsWith('/') ? baseUrl : '$baseUrl/',
          connectTimeout: const Duration(seconds: 15),
          receiveTimeout: const Duration(seconds: 30),
          sendTimeout: const Duration(seconds: 30),
          // Every status is an answer to read, not an exception: errors are mapped below.
          validateStatus: (_) => true,
          responseType: ResponseType.plain,
        )) {
    if (adapter != null) _dio.httpClientAdapter = adapter;
  }

  final Dio _dio;
  final TokenStore _tokens;
  final String Function() _language;
  final void Function() _onUnauthorized;

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  Future<Map<String, dynamic>> post(String path, {Object? body, Map<String, String>? headers}) =>
      _send('POST', path, body: body, headers: headers);

  Future<Map<String, dynamic>> put(String path, {Object? body}) => _send('PUT', path, body: body);

  Future<Map<String, dynamic>> delete(String path) => _send('DELETE', path);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Object? body,
    Map<String, String>? headers,
  }) async {
    final token = await _tokens.read();
    final Response<String> response;

    try {
      response = await _dio.request<String>(
        path.startsWith('/') ? path.substring(1) : path,
        data: body == null ? null : jsonEncode(body),
        queryParameters: query,
        options: Options(method: method, headers: {
          'Accept': 'application/json',
          'Accept-Language': _language(),
          if (body != null) 'Content-Type': 'application/json',
          if (token != null) 'Authorization': 'Bearer $token',
          ...?headers,
        }),
      );
    } on DioException {
      throw const ApiException.network();
    }

    final status = response.statusCode ?? 0;
    final decoded = _decode(response.data);

    if (status >= 200 && status < 300) return decoded;

    final error = decoded['error'];
    final details = error is Map<String, dynamic> ? error : const <String, dynamic>{};
    final code = (details['code'] ?? 'http_$status').toString();
    final message = (details['message'] ?? 'Something went wrong').toString();

    if (status == 401 && token != null) await _signOutOnce(token);

    if (status == 402) {
      throw UpgradeRequired(
        code: code,
        message: message,
        feature: (details['feature'] ?? '').toString(),
        limit: (details['limit'] as num?)?.toInt(),
        used: (details['used'] as num?)?.toInt(),
        upgradeUrl: details['upgrade_url']?.toString(),
      );
    }

    throw ApiException(
      status: status,
      code: code,
      message: message,
      fields: _fields(details['fields']),
      retryAfter: (details['retry_after'] as num?)?.toInt(),
    );
  }

  /// The token the app was last signed out for, so that five requests failing together sign it out once.
  String? _signedOutFor;

  /// A request that carried an old token must not sign out a person who has since signed in again.
  Future<void> _signOutOnce(String usedToken) async {
    if (_signedOutFor == usedToken || await _tokens.read() != usedToken) return;

    _signedOutFor = usedToken;
    _onUnauthorized();
  }

  Map<String, dynamic> _decode(String? text) {
    if (text == null || text.isEmpty) return const {};
    try {
      final value = jsonDecode(text);

      return value is Map<String, dynamic> ? value : const {};
    } on FormatException {
      return const {}; // a proxy's HTML page, say: the status alone says what happened
    }
  }

  Map<String, List<String>> _fields(Object? raw) {
    if (raw is! Map<String, dynamic>) return const {};

    return {
      for (final entry in raw.entries)
        entry.key: [for (final message in (entry.value as List<dynamic>? ?? const <dynamic>[])) message.toString()],
    };
  }
}
