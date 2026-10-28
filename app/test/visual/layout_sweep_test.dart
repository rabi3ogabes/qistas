import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/app/router.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';

/// Every main screen lays out on a small phone, on a phone with large text, and on a tablet, in a left-to-right and a
/// right-to-left language: nothing runs off the screen, nothing overlaps. Each overflow is collected, so one run lists
/// them all with the screen, size and language it happened on.
void main() {
  const sizes = {
    'small phone': (Size(320, 640), 1.0),
    'phone, large text': (Size(360, 780), 1.3),
    'tablet': (Size(800, 1280), 1.0),
  };

  const screens = [
    '/',
    '/customers',
    '/customers/new',
    '/customers/c1',
    '/contracts',
    '/contracts/new',
    '/contracts/k1',
    '/payments',
    '/more',
    '/profile',
    '/settings/alerts',
    '/settings/business',
    '/settings/tags',
    '/settings/widget',
    '/tools',
    '/reports',
    '/products',
    '/investors',
    '/team',
    '/calculator',
    '/plans',
    '/inbox',
    '/help',
    '/search',
  ];

  FakeServer server() => workspaceServer(routes: {
        'GET /notifications/preferences': always(json(200, {
          'data': {
            'daily_digest': {'enabled': true, 'time': '09:00'},
            'instalment_due': {'enabled': true},
            'instalment_late': {'enabled': true, 'repeat': 'weekly'},
            'quiet_hours': {'enabled': false, 'from': '22:00', 'to': '07:00'},
          },
        })),
      });

  for (final language in ['en', 'ar', 'fr', 'ur']) {
    for (final entry in sizes.entries) {
      final (size, textScale) = entry.value;

      testWidgets('every main screen fits: ${entry.key}, $language', (tester) async {
        final problems = <String>[];
        final previous = FlutterError.onError;
        var where = '';
        FlutterError.onError = (details) {
          final text = details.exceptionAsString();
          if (text.contains('overflowed') || text.contains('RenderBox was not laid out') || text.contains('unbounded')) {
            // The widget that overflowed, by the line of the app's code that made it.
            final made = RegExp(r'lib/[\w/]+\.dart:\d+').firstMatch(details.toString())?.group(0) ?? '?';
            problems.add('$where: ${text.split('\n').first} ($made)');
          } else {
            previous?.call(details);
          }
        };
        addTearDown(() => FlutterError.onError = previous);

        await pumpApp(tester, server(), size: size, textScale: textScale, language: language);
        final router = ProviderScope.containerOf(tester.element(find.byType(MaterialApp).first)).read(routerProvider);

        for (final path in screens) {
          where = path;
          router.go(path);
          await settle(tester);
        }

        // The framework's own handler must be back before anything is asserted.
        FlutterError.onError = previous;
        expect(problems.toSet().toList(), isEmpty);
      });
    }
  }
}
