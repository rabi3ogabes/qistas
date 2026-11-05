import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/chrome.dart';
import 'package:qistas/app/crash_report_sender.dart';
import 'package:qistas/app/providers.dart';
import 'package:qistas/core/api/api_exception.dart';
import 'package:qistas/core/storage/token_store.dart';

import '../../support/fake_api.dart';
import '../../support/harness.dart';

/// How `main.dart` sends a crash report: with an API client of its own, so that a refusal can never change the session.
void main() {
  const report = {'class': 'StateError', 'message': 'Bad state: x', 'trace': '#0 main', 'version': '1.29.0', 'os': 'android', 'os_version': 'Linux', 'device': 'phone'};

  test('posts the report to /client-errors with the token of whoever is signed in, and nothing that says where it comes from', () async {
    final adapter = FakeAdapter((_) => const FakeResponse(204, ''));

    await crashReportSender(baseUrl: 'https://qistas.test/api/v1', tokens: MemoryTokenStore('qst_secret-token'), adapter: adapter)(report);

    expect(adapter.last.method, 'POST');
    expect(adapter.last.uri.toString(), 'https://qistas.test/api/v1/client-errors');
    expect(adapter.last.headers['Authorization'], 'Bearer qst_secret-token');
    expect(adapter.lastBody, report);
    final names = adapter.last.headers.keys.map((name) => name.toLowerCase());
    expect(names, isNot(contains('origin')));
    expect(names, isNot(contains('sec-fetch-site')));
  });

  test('sends no token when nobody is signed in', () async {
    final adapter = FakeAdapter((_) => const FakeResponse(204, ''));

    await crashReportSender(baseUrl: 'https://qistas.test/api/v1', tokens: MemoryTokenStore(), adapter: adapter)(report);

    expect(adapter.last.headers.containsKey('Authorization'), isFalse);
  });

  test('builds its client once, however many reports it sends', () async {
    final adapter = FakeAdapter((_) => const FakeResponse(204, ''));
    final send = crashReportSender(baseUrl: 'https://qistas.test/api/v1', tokens: MemoryTokenStore(), adapter: adapter);

    await send(report);
    await send(report);

    expect(adapter.requests, hasLength(2));
  });

  test('a refusal reaches the caller as an ApiException with its status', () async {
    final adapter = FakeAdapter((_) => const FakeResponse(403, ''));

    await expectLater(
      crashReportSender(baseUrl: 'https://qistas.test/api/v1', tokens: MemoryTokenStore(), adapter: adapter)(report),
      throwsA(isA<ApiException>().having((e) => e.status, 'status', 403)),
    );
  });

  testWidgets('a 401 to a crash report does not sign the app out, and does not touch the token', (tester) async {
    await pumpApp(tester, workspaceServer());
    final container = ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first));
    final tokens = container.read(tokenStoreProvider);
    expect(container.read(authProvider).valueOrNull?.isSignedIn, isTrue);
    expect(await tokens.read(), isNotNull);

    final adapter = FakeAdapter((_) => apiError(401, 'unauthenticated', 'Unauthenticated.'));
    Object? failure;
    unawaited(crashReportSender(tokens: tokens, adapter: adapter)(report).catchError((Object error) => failure = error));
    await settle(tester);

    expect(adapter.requests, hasLength(1));
    expect(adapter.last.headers['Authorization'], startsWith('Bearer '), reason: 'the token went along, as for any call');
    expect(failure, isA<ApiException>().having((e) => e.status, 'status', 401));
    expect(container.read(authProvider).valueOrNull?.isSignedIn, isTrue, reason: 'still signed in');
    expect(await tokens.read(), isNotNull, reason: 'the token is still there');
    expect(find.byType(AccountButton), findsWidgets, reason: 'and still on a screen of the app, not on the sign-in');
  });
}
