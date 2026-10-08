import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/api/api_client.dart';
import 'package:qistas/core/api/api_exception.dart';
import 'package:qistas/core/storage/token_store.dart';

import '../support/fake_api.dart';

ApiClient clientFor(FakeAdapter adapter, {TokenStore? tokens, String language = 'en', void Function()? onUnauthorized}) => ApiClient(
      baseUrl: 'https://qistas.test/api/v1',
      tokens: tokens ?? MemoryTokenStore('qst_secret-token'),
      language: () => language,
      onUnauthorized: onUnauthorized ?? () {},
      adapter: adapter,
    );

void main() {
  group('a request', () {
    test('carries the token, the language and asks for JSON', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': <String, dynamic>{}}));

      await clientFor(adapter, language: 'ar').get('/me');

      expect(adapter.last.uri.toString(), 'https://qistas.test/api/v1/me');
      expect(adapter.last.headers['Authorization'], 'Bearer qst_secret-token');
      expect(adapter.last.headers['Accept-Language'], 'ar');
      expect(adapter.last.headers['Accept'], 'application/json');
    });

    test('has no Authorization header before sign-in', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': <String, dynamic>{}}));

      await clientFor(adapter, tokens: MemoryTokenStore()).get('/plans');

      expect(adapter.last.headers.containsKey('Authorization'), isFalse);
    });

    test('sends a JSON body, and extra headers such as an Idempotency-Key', () async {
      final adapter = FakeAdapter((_) => json(201, {'data': <String, dynamic>{}}));

      await clientFor(adapter).post('/contracts/1/payments', body: {'amount': '10.00', 'method': 'cash'}, headers: {'Idempotency-Key': 'k-1'});

      expect(adapter.lastBody, {'amount': '10.00', 'method': 'cash'});
      expect(adapter.last.headers['Idempotency-Key'], 'k-1');
      expect(adapter.last.headers['Content-Type'], 'application/json');
    });

    test('puts query parameters in the address', () async {
      final adapter = FakeAdapter((_) => json(200, {'data': <Object?>[]}));

      await clientFor(adapter).get('/customers', query: {'q': 'ahmad', 'page': 2});

      expect(adapter.last.uri.queryParameters, {'q': 'ahmad', 'page': '2'});
    });

    test('answers an empty 204 as nothing', () async {
      final adapter = FakeAdapter((_) => const FakeResponse(204, ''));

      expect(await clientFor(adapter).delete('/customers/1'), isEmpty);
    });
  });

  group('a failure', () {
    test('is a validation error with the fields named', () async {
      final adapter = FakeAdapter((_) => apiError(422, 'validation_failed', 'Some of the information is not valid.', extra: {
            'fields': {'phone': ['Enter a phone number.'], 'name': <String>[]},
          }));

      final error = await clientFor(adapter).post('/customers', body: {}).then<ApiException?>((_) => null, onError: (Object e) => e as ApiException);

      expect(error, isNotNull);
      expect(error!.status, 422);
      expect(error.isValidation, isTrue);
      expect(error.fieldError('phone'), 'Enter a phone number.');
      expect(error.fieldError('name'), isNull);
      expect(error.message, 'Some of the information is not valid.');
    });

    test('is an upgrade prompt for HTTP 402, with what was hit', () async {
      final adapter = FakeAdapter((_) => apiError(402, 'limit_reached', 'You have reached the limit of 5 customers on your plan.', extra: {
            'feature': 'customers', 'limit': 5, 'used': 5, 'upgrade_url': 'https://qistas.test/app/billing',
          }));

      Object? caught;
      try {
        await clientFor(adapter).post('/customers', body: {});
      } on Object catch (e) {
        caught = e;
      }

      expect(caught, isA<UpgradeRequired>());
      final upgrade = caught! as UpgradeRequired;
      expect([upgrade.feature, upgrade.limit, upgrade.used, upgrade.isLocked], ['customers', 5, 5, false]);
      expect(upgrade.upgradeUrl, 'https://qistas.test/app/billing');
    });

    test('knows a locked feature from a full one', () async {
      final adapter = FakeAdapter((_) => apiError(402, 'feature_locked', 'CSV export is not included in your plan.', extra: {'feature': 'export_csv', 'limit': null, 'used': null}));

      final error = await clientFor(adapter).get('/export').then<UpgradeRequired?>((_) => null, onError: (Object e) => e as UpgradeRequired);

      expect(error!.isLocked, isTrue);
      expect(error.limit, isNull);
    });

    test('is a plain network error when there is no answer at all', () async {
      final error = await clientFor(offlineAdapter()).get('/me').then<ApiException?>((_) => null, onError: (Object e) => e as ApiException);

      expect(error!.isNetwork, isTrue);
      expect(error.code, 'network');
    });

    test('survives a body that is not JSON', () async {
      final adapter = FakeAdapter((_) => const FakeResponse(502, '<html>Bad gateway</html>'));

      final error = await clientFor(adapter).get('/me').then<ApiException?>((_) => null, onError: (Object e) => e as ApiException);

      expect([error!.status, error.code], [502, 'http_502']);
    });

    test('says how long to wait when rate limited', () async {
      final adapter = FakeAdapter((_) => apiError(429, 'rate_limited', 'Too many requests.', extra: {'retry_after': 42}));

      final error = await clientFor(adapter).get('/me').then<ApiException?>((_) => null, onError: (Object e) => e as ApiException);

      expect(error!.retryAfter, 42);
    });
  });

  group('an expired or revoked token', () {
    test('signs the app out exactly once, however many requests were in flight', () async {
      var signedOut = 0;
      final adapter = FakeAdapter((_) => apiError(401, 'unauthenticated', 'Sign in to continue.'));
      final client = clientFor(adapter, onUnauthorized: () => signedOut++);

      await Future.wait([for (var i = 0; i < 3; i++) client.get('/me').then<void>((_) {}, onError: (Object _) {})]);

      expect(signedOut, 1);
    });

    test('does not sign anyone out for a wrong password (no token was sent)', () async {
      var signedOut = 0;
      final adapter = FakeAdapter((_) => apiError(401, 'invalid_credentials', 'These credentials do not match our records.'));

      await clientFor(adapter, tokens: MemoryTokenStore(), onUnauthorized: () => signedOut++)
          .post('/auth/login', body: {})
          .then<void>((_) {}, onError: (Object _) {});

      expect(signedOut, 0);
    });

    test('does not sign out a person who has signed in again since the request was sent', () async {
      var signedOut = 0;
      final tokens = MemoryTokenStore('qst_old');
      final gate = Completer<void>();
      final adapter = FakeAdapter((_) async {
        await gate.future;

        return apiError(401, 'unauthenticated', 'Sign in to continue.');
      });
      final client = clientFor(adapter, tokens: tokens, onUnauthorized: () => signedOut++);

      final pending = client.get('/me').then<void>((_) {}, onError: (Object _) {});
      await Future<void>.delayed(Duration.zero);
      await tokens.write('qst_new'); // signed in again while the old request was on its way
      gate.complete();
      await pending;

      expect(signedOut, 0);
    });
  });

  test('the token is never printed, even when everything goes wrong', () async {
    final printed = <String>[];
    await runZoned(() async {
      for (final response in [json(200, {'data': <String, dynamic>{}}), apiError(401, 'unauthenticated', 'x'), apiError(500, 'server_error', 'y')]) {
        await clientFor(FakeAdapter((_) => response)).get('/me').then<void>((_) {}, onError: (Object _) {});
      }
      await clientFor(offlineAdapter()).get('/me').then<void>((_) {}, onError: (Object _) {});
    }, zoneSpecification: ZoneSpecification(print: (self, parent, zone, line) => printed.add(line)));

    expect(printed.where((line) => line.contains('qst_secret-token')), isEmpty);
  });
}
