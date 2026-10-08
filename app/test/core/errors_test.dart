import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/api/api_exception.dart';
import 'package:qistas/core/l10n/translations.dart';
import 'package:qistas/core/ui/errors.dart';

/// Runs [errorMessage] inside a real widget tree, in English.
Future<String> messageFor(WidgetTester tester, Object error) async {
  late String message;
  await tester.pumpWidget(
    TranslationsScope(
      translations: const Translations.english(),
      child: Builder(builder: (context) {
        message = errorMessage(context, error);

        return const SizedBox.shrink();
      }),
    ),
  );

  return message;
}

void main() {
  group('what a person is told', () {
    testWidgets('a lost connection is said in our words', (tester) async {
      expect(await messageFor(tester, const ApiException.network()), 'No connection. Check your internet and try again.');
    });

    testWidgets('a fault on the server is said in our words, never as a code or a proxy’s page', (tester) async {
      for (final error in const [
        ApiException(status: 500, code: 'server_error', message: 'Server Error'),
        ApiException(status: 502, code: 'http_502', message: 'Something went wrong'),
        ApiException(status: 500, code: 'whatever', message: ''),
      ]) {
        expect(await messageFor(tester, error), 'Something went wrong on our side. Please try again.');
      }
    });

    testWidgets('a situation the server named itself is told as it wrote it, even on a 503', (tester) async {
      const busy = ApiException(status: 503, code: 'demo_busy', message: 'The demo is busy right now. Please try again in a few minutes.');

      expect(await messageFor(tester, busy), 'The demo is busy right now. Please try again in a few minutes.');
    });

    testWidgets('a refusal carries the server’s own sentence', (tester) async {
      const refused = ApiException(status: 403, code: 'account_suspended', message: 'This account is suspended.');

      expect(await messageFor(tester, refused), 'This account is suspended.');
    });

    testWidgets('anything unexpected gets a plain sentence', (tester) async {
      expect(await messageFor(tester, StateError('boom')), 'Something went wrong. Please try again.');
    });
  });
}
