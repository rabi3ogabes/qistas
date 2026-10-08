import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

/// A canned answer.
class FakeResponse {
  const FakeResponse(this.status, this.body);

  final int status;
  final String body;
}

/// An answer in the API's own shape.
FakeResponse json(int status, Object body) => FakeResponse(status, jsonEncode(body));

FakeResponse apiError(int status, String code, String message, {Map<String, Object?> extra = const {}}) =>
    json(status, {
      'error': {'code': code, 'message': message, ...extra},
    });

/// Stands in for the network in tests: records every request and answers from [handler].
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter(this.handler);

  final FutureOr<FakeResponse> Function(RequestOptions options) handler;
  final List<RequestOptions> requests = [];

  RequestOptions get last => requests.last;

  Map<String, dynamic> get lastBody => jsonDecode(last.data as String) as Map<String, dynamic>;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests.add(options);
    final response = await handler(options);

    return ResponseBody.fromString(response.body, response.status, headers: {
      Headers.contentTypeHeader: ['application/json'],
    });
  }

  @override
  void close({bool force = false}) {}
}

/// An adapter whose network is down.
FakeAdapter offlineAdapter() => FakeAdapter((options) => throw DioException.connectionError(requestOptions: options, reason: 'offline'));
