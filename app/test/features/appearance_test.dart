import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/tokens.dart';
import 'package:qistas/data/appearance.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/fake_api.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The app wears the look chosen in the admin's Appearance page: the palette the server resolved (light and dark),
/// today's event for this workspace's country, and the Android app's welcome banner on the dashboard. It keeps the
/// last answer, so it opens in those colours with no connection, and drops an event once it has ended even offline.

String hex(Color c) => '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0').toUpperCase()}';

/// The 26 tokens the server sends for one mode: the app's 24 and the two the logo uses.
Map<String, String> tokensOf(QistasColors c, {String? primary}) => {
      'primary': primary ?? hex(c.primary), 'onPrimary': hex(c.onPrimary), 'action': hex(c.action), 'onAction': hex(c.onAction),
      'accent': hex(c.accent), 'onAccent': hex(c.onAccent), 'accentText': hex(c.accentText), 'info': hex(c.info), 'onInfo': hex(c.onInfo),
      'bg': hex(c.bg), 'surface': hex(c.surface), 'surfaceAlt': hex(c.surfaceAlt), 'ink': hex(c.ink), 'inkMuted': hex(c.inkMuted),
      'line': hex(c.line), 'positive': hex(c.positive), 'warning': hex(c.warning), 'danger': hex(c.danger), 'tintSky': hex(c.tintSky),
      'tintBlush': hex(c.tintBlush), 'tintSand': hex(c.tintSand), 'tintMint': hex(c.tintMint), 'heroFrom': hex(c.heroFrom), 'heroTo': hex(c.heroTo),
      'logoInk': '#0B1F44', 'logoAccent': '#C9A25B',
    };

Map<String, dynamic> lookJson({String primary = '#006C35', String darkPrimary = '#2E8B57', Map<String, dynamic>? banner, Map<String, dynamic>? event, Map<String, dynamic>? base}) => {
      'version': 3,
      'custom': true,
      'tokens': {'light': tokensOf(QistasColors.light, primary: primary), 'dark': tokensOf(QistasColors.dark, primary: darkPrimary)},
      'logo_url': null,
      'logo_dark_url': null,
      'banner': banner,
      'event': event,
      'base': base,
    };

Map<String, dynamic> bannerJson({String title = 'Welcome back', String key = 'k1', String? endsOn, bool dismissible = true, String? ctaUrl, String ctaLabel = ''}) => {
      'title': title, 'message': 'Every instalment, to the cent.', 'cta_label': ctaLabel, 'cta_url': ctaUrl, 'tone': 'navy',
      'dismissible': dismissible, 'image_url': null, 'ends_on': endsOn, 'key': key,
    };

/// The banner as drawn (the widget that holds it is always there, drawing nothing when there is no banner).
Finder get card => find.byKey(const ValueKey('welcome-banner'));

QistasColors colorsOn(WidgetTester tester) => Theme.of(tester.element(find.byType(Scaffold).first)).extension<QistasColors>()!;

void main() {
  group('the palette', () {
    test('reads every colour the app uses, for light and dark, from the server’s answer', () {
      final look = Look.fromJson(lookJson())!;

      expect(hex(look.light.primary), '#006C35');
      expect(hex(look.dark.primary), '#2E8B57');
      expect(look.light.tintMint, QistasColors.light.tintMint);
      expect(look.dark.heroTo, QistasColors.dark.heroTo);
    });

    test('keeps the built-in colour for a missing or broken one, and refuses an answer with no palette', () {
      final data = lookJson();
      ((data['tokens'] as Map)['light'] as Map)
        ..remove('accent')
        ..['info'] = 'red;}';

      final look = Look.fromJson(data)!;

      expect(look.light.accent, QistasColors.light.accent);
      expect(look.light.info, QistasColors.light.info);
      expect(Look.fromJson({'version': 1}), isNull);
      expect(Look.fromJson({'tokens': 'nope'}), isNull);
    });

    testWidgets('dresses the app in the chosen colours', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson()}, headers: {'ETag': '"v3"'}))}));

      expect(hex(colorsOn(tester).primary), '#006C35');
    });

    testWidgets('opens in the last colours it was given, with no connection, without a flash of the factory look', (tester) async {
      final server = workspaceServer()..offline = true;
      await pumpApp(tester, server, preferences: {
        'look.en': jsonEncode({'etag': '"v3"', 'data': lookJson()}),
        'account': jsonEncode(sampleAccount().toJson()),
      });

      expect(hex(colorsOn(tester).primary), '#006C35');
    });

    testWidgets('asks with the tag of the look it has, and keeps it when nothing changed', (tester) async {
      final server = workspaceServer(routes: {'GET /appearance': always(const FakeResponse(304, ''))});
      await pumpApp(tester, server, preferences: {'look.en': jsonEncode({'etag': '"v3"', 'data': lookJson()})});

      expect(server.requestsTo('GET /appearance').first.headers['If-None-Match'], '"v3"');
      expect(hex(colorsOn(tester).primary), '#006C35');
    });

    testWidgets('keeps the look it has when the server cannot answer', (tester) async {
      final server = workspaceServer(routes: {'GET /appearance': always(apiError(500, 'server_error', 'Down'))});
      await pumpApp(tester, server, preferences: {'look.en': jsonEncode({'etag': '"v3"', 'data': lookJson()})});

      expect(server.calls('GET /appearance'), greaterThan(0));
      expect(hex(colorsOn(tester).primary), '#006C35');
    });

    testWidgets('goes back to the usual look once an event has ended, even with no connection', (tester) async {
      final ended = lookJson(primary: '#006C35', event: {'id': 'e1', 'name': 'Saudi National Day', 'ends_on': '2020-09-24', 'until': '2020-09-24T21:00:00+00:00'}, base: {'tokens': {'light': tokensOf(QistasColors.light, primary: '#0F3D2E'), 'dark': tokensOf(QistasColors.dark)}, 'banner': null});
      final server = workspaceServer()..offline = true;
      await pumpApp(tester, server, preferences: {'look.en': jsonEncode({'etag': '"v3"', 'data': ended}), 'account': jsonEncode(sampleAccount().toJson())});

      expect(hex(colorsOn(tester).primary), '#0F3D2E');
    });
  });

  group('the welcome banner', () {
    testWidgets('shows on the dashboard in the app’s language', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson(banner: bannerJson(title: 'أهلًا بعودتك'))}))}), language: 'ar');

      expect(card, findsOneWidget);
      expect(find.text('أهلًا بعودتك'), findsOneWidget);
    });

    // One scene per test: a second start in the same test keeps the first one's saved preferences.
    testWidgets('can be closed, and remembers it on the phone', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson(banner: bannerJson(key: 'k1'))}))}));

      await tester.tap(find.byTooltip('Close'));
      await settle(tester);

      expect(find.text('Welcome back'), findsNothing);
      expect((await SharedPreferences.getInstance()).getStringList('banners.dismissed'), contains('k1'));
    });

    testWidgets('stays closed after a restart', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson(banner: bannerJson(key: 'k1'))}))}), preferences: {'banners.dismissed': <String>['k1']});

      expect(find.text('Welcome back'), findsNothing);
    });

    testWidgets('shows again when the admin changes it', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson(banner: bannerJson(key: 'k2', title: 'Something new'))}))}), preferences: {'banners.dismissed': <String>['k1']});

      expect(find.text('Something new'), findsOneWidget);
    });

    testWidgets('has no close button when the admin made it stay', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson(banner: bannerJson(dismissible: false))}))}));

      expect(find.text('Welcome back'), findsOneWidget);
      expect(find.byTooltip('Close'), findsNothing);
    });

    testWidgets('is shown from what the app kept while it is within its days', (tester) async {
      final server = workspaceServer()..offline = true;
      await pumpApp(tester, server, preferences: {'look.en': jsonEncode({'etag': '"v3"', 'data': lookJson(banner: bannerJson(endsOn: '2999-12-31'))}), 'account': jsonEncode(sampleAccount().toJson())});

      expect(card, findsOneWidget);
    });

    testWidgets('is not shown after its last day, even from what the app kept', (tester) async {
      final server = workspaceServer()..offline = true;
      await pumpApp(tester, server, preferences: {'look.en': jsonEncode({'etag': '"v3"', 'data': lookJson(banner: bannerJson(endsOn: '2020-01-31'))}), 'account': jsonEncode(sampleAccount().toJson())});

      expect(card, findsNothing);
    });

    testWidgets('opens its button’s page on the website', (tester) async {
      final opened = <Uri>[];
      await pumpApp(
        tester,
        workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson(banner: bannerJson(ctaUrl: '/pricing', ctaLabel: 'See plans'))}))}),
        overrides: [openExternalProvider.overrideWithValue((uri) async {
          opened.add(uri);
          return true;
        })],
      );

      await tester.tap(find.text('See plans'));
      await settle(tester);

      expect(opened.single.toString(), endsWith('/pricing'));
      expect(opened.single.scheme, 'https');
    });

    testWidgets('is not there when none is published', (tester) async {
      await pumpApp(tester, workspaceServer(routes: {'GET /appearance': always(json(200, {'data': lookJson()}))}));

      expect(card, findsNothing);
    });
  });
}
