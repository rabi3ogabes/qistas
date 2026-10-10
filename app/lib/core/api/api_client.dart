import 'dart:convert';
import 'dart:typed_data';

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
    void Function()? onFeatureUnavailable,
    HttpClientAdapter? adapter,
  })  : _tokens = tokens,
        _language = language,
        _onUnauthorized = onUnauthorized,
        _onFeatureUnavailable = onFeatureUnavailable,
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
  final void Function()? _onFeatureUnavailable;

  /// When a switch was last reported, so a screen that keeps asking cannot make the app ask the server forever.
  DateTime? _featureReportedAt;

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send('GET', path, query: query);

  /// A GET that sends back the tag of what the app already has: 304 means "keep it", and the answer's own tag comes
  /// back with a 200 to send next time.
  Future<({int status, Map<String, dynamic> body, String? etag})> getConditional(String path, {String? etag}) async {
    final token = await _tokens.read();
    final Response<String> response;

    try {
      response = await _dio.request<String>(
        path.startsWith('/') ? path.substring(1) : path,
        options: Options(method: 'GET', headers: {
          'Accept': 'application/json',
          'Accept-Language': _language(),
          if (token != null) 'Authorization': 'Bearer $token',
          if (etag != null && etag.isNotEmpty) 'If-None-Match': etag,
        }),
      );
    } on DioException {
      throw const ApiException.network();
    }

    final status = response.statusCode ?? 0;
    if (status == 304) return (status: 304, body: const <String, dynamic>{}, etag: etag);
    if (status >= 200 && status < 300) return (status: status, body: _decode(response.data), etag: response.headers.value('etag'));

    final details = _decode(response.data)['error'];
    final error = details is Map<String, dynamic> ? details : const <String, dynamic>{};

    throw ApiException(status: status, code: (error['code'] ?? 'http_$status').toString(), message: (error['message'] ?? 'Something went wrong').toString());
  }

  Future<Map<String, dynamic>> post(String path, {Object? body, Map<String, String>? headers, Map<String, dynamic>? query}) =>
      _send('POST', path, body: body, headers: headers, query: query);

  Future<Map<String, dynamic>> put(String path, {Object? body}) => _send('PUT', path, body: body);

  /// A file the server makes, such as a statement as a PDF: its bytes, or the same errors as any other request.
  Future<Uint8List> getBytes(String path, {Map<String, dynamic>? query}) async {
    final token = await _tokens.read();
    final Response<List<int>> response;

    try {
      response = await _dio.request<List<int>>(
        path.startsWith('/') ? path.substring(1) : path,
        queryParameters: query,
        options: Options(method: 'GET', responseType: ResponseType.bytes, receiveTimeout: const Duration(seconds: 60), headers: {
          'Accept': 'application/pdf, application/json',
          'Accept-Language': _language(),
          if (token != null) 'Authorization': 'Bearer $token',
        }),
      );
    } on DioException {
      throw const ApiException.network();
    }

    final status = response.statusCode ?? 0;
    final bytes = Uint8List.fromList(response.data ?? const []);
    if (status >= 200 && status < 300) return bytes;

    await _fail(status, _decode(utf8.decode(bytes, allowMalformed: true)), token);
  }

  /// Sends a file (a logo, a signature) as `multipart/form-data` under [field].
  Future<Map<String, dynamic>> upload(String path, {required String field, required List<int> bytes, required String filename}) =>
      _send('POST', path, form: FormData.fromMap({field: MultipartFile.fromBytes(bytes, filename: filename)}));

  Future<Map<String, dynamic>> delete(String path) => _send('DELETE', path);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Object? body,
    Map<String, String>? headers,
    FormData? form,
  }) async {
    final token = await _tokens.read();
    final Response<String> response;

    try {
      response = await _dio.request<String>(
        path.startsWith('/') ? path.substring(1) : path,
        data: form ?? (body == null ? null : jsonEncode(body)),
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

    return _fail(status, decoded, token);
  }

  /// Turns an error answer into the right exception (and signs out once on a 401).
  Future<Never> _fail(int status, Map<String, dynamic> decoded, String? token) async {
    final error = decoded['error'];
    final details = error is Map<String, dynamic> ? error : const <String, dynamic>{};
    final code = (details['code'] ?? 'http_$status').toString();
    final message = (details['message'] ?? 'Something went wrong').toString();

    if (status == 401 && token != null) await _signOutOnce(token);

    // The platform has switched that feature off: tell the app once in a while, so it reads what is on now and removes
    // the screen that offered it. (A plan that lacks a feature is the 402 below, and stays on screen, locked.)
    if (status == 403 && code == 'feature_unavailable') _reportFeatureUnavailable();

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

  void _reportFeatureUnavailable() {
    final now = DateTime.now();
    final last = _featureReportedAt;
    if (_onFeatureUnavailable == null || (last != null && now.difference(last) < const Duration(seconds: 10))) return;

    _featureReportedAt = now;
    _onFeatureUnavailable();
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
