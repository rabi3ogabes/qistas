import 'package:dio/dio.dart' show HttpClientAdapter;

import '../core/api/api_client.dart';
import '../core/config.dart';
import '../core/errors/error_reporter.dart';
import '../core/storage/token_store.dart';
import '../data/qistas_api.dart';

/// How a crash report reaches the server: [QistasApi.reportError] through an API client of its own, built when the first
/// report is sent.
///
/// It has its own client, not the app's, for two reasons. The token is read from the same store, so a report goes with
/// the token of whoever is signed in (or none), as the server wants; but when the server answers 401 to a report, the
/// app's client would end the session (`onUnauthorized`), and a crash report must never sign anyone out: here that
/// callback does nothing. And nothing in it can set an `Origin` header (the server refuses a report that has one).
///
/// [baseUrl], [tokens] and [adapter] are the app's own ([AppConfig.apiBaseUrl], the secure store, the platform's HTTP) unless a test brings others.
ReportSender crashReportSender({String? baseUrl, TokenStore? tokens, HttpClientAdapter? adapter}) {
  QistasApi? api;

  return (report) => (api ??= QistasApi(ApiClient(
        baseUrl: baseUrl ?? AppConfig.apiBaseUrl,
        tokens: tokens ?? SecureTokenStore(),
        language: () => 'en',
        onUnauthorized: () {},
        adapter: adapter,
      ))).reportError(report);
}
