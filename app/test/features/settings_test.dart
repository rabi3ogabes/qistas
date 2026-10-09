import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/core/design/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../support/harness.dart';
import '../support/samples.dart';

/// Settings, in the order a person looks for things: who I am, my plan, how the app looks and speaks, my workspace,
/// keeping my account safe, and help. Every row says what it holds; the ones that leave or sign out ask first.

Finder group(String title) => find.byKey(ValueKey('settings-group-$title'));

void main() {
  testWidgets('lists who you are, your plan, preferences, security and help, in that order', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openSettings(tester);

    expect(find.text('Layla Haddad'), findsWidgets);
    expect(find.text('layla@example.com'), findsOneWidget);
    expect(find.text('Al-Fares Electronics'), findsOneWidget);

    final order = ['plan', 'preferences', 'security', 'help'];
    double top(String title) {
      final finder = group(title);
      expect(finder, findsOneWidget, reason: 'no "$title" group');
      return tester.getTopLeft(finder).dy;
    }

    // The page scrolls: measure each group where it sits in the scroll, not on screen.
    final scroll = find.descendant(of: find.byType(SingleChildScrollView), matching: find.byType(Scrollable)).first;
    final tops = <double>[];
    for (final title in order) {
      await tester.scrollUntilVisible(group(title), 200, scrollable: scroll);
      tops.add(top(title) + tester.state<ScrollableState>(scroll).position.pixels);
    }
    expect(tops, orderedEquals([...tops]..sort()));
  });

  testWidgets('shows a Free plan’s use against its limits, with the way to Pro', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openSettings(tester);

    final plan = group('plan');
    expect(find.descendant(of: plan, matching: find.text('Free')), findsOneWidget);
    expect(find.descendant(of: plan, matching: find.text('3 of 5')), findsOneWidget);
    expect(find.descendant(of: plan, matching: find.text('Upgrade to Pro')), findsOneWidget);
  });

  testWidgets('says Pro is on for a Pro workspace, without limits to count', (tester) async {
    await pumpApp(tester, workspaceServer(account: accountJson(plan: 'pro')));
    await openSettings(tester);

    final plan = group('plan');
    expect(find.descendant(of: plan, matching: find.text('Pro is on')), findsOneWidget);
    expect(find.descendant(of: plan, matching: find.text('Upgrade to Pro')), findsNothing);
    expect(find.descendant(of: plan, matching: find.byType(QMeter)), findsNothing);
  });

  testWidgets('switches between the phone’s look, light and dark, and remembers it', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openSettings(tester);

    await tapText(tester, 'Dark');
    expect(Theme.of(tester.element(find.byType(Scaffold).first)).brightness, Brightness.dark);
    expect((await SharedPreferences.getInstance()).getString('theme_mode'), 'dark');

    await tapText(tester, 'Light');
    expect(Theme.of(tester.element(find.byType(Scaffold).first)).brightness, Brightness.light);
    expect((await SharedPreferences.getInstance()).getString('theme_mode'), 'light');
  });

  testWidgets('says whether two-step sign-in is on', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openSettings(tester);

    final row = find.byKey(const ValueKey('settings-two-step'));
    await tester.scrollUntilVisible(row, 200, scrollable: find.descendant(of: find.byType(SingleChildScrollView), matching: find.byType(Scrollable)).first);
    expect(find.descendant(of: row, matching: find.text('Off')), findsOneWidget);
  });

  testWidgets('asks before signing out, and Cancel keeps you signed in', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openSettings(tester);

    await tapText(tester, 'Sign out');
    expect(find.text('Sign out?'), findsOneWidget);
    await tapText(tester, 'Cancel');

    expect(find.text('Sign out?'), findsNothing);
    expect(group('security'), findsOneWidget, reason: 'still in Settings, still signed in');
  });

  testWidgets('shows the app’s version', (tester) async {
    await pumpApp(tester, workspaceServer());
    await openSettings(tester);

    final help = group('help');
    await tester.scrollUntilVisible(help, 200, scrollable: find.descendant(of: find.byType(SingleChildScrollView), matching: find.byType(Scrollable)).first);
    expect(find.descendant(of: help, matching: find.text('1.3.0')), findsOneWidget);
  });

  testWidgets('holds together in Arabic with the text at twice the size on a small phone', (tester) async {
    await pumpApp(tester, workspaceServer(), language: 'ar', textScale: 2, size: const Size(360, 740));
    await openSettings(tester);

    expect(tester.takeException(), isNull);
    expect(Directionality.of(tester.element(group('preferences'))), TextDirection.rtl);
  });
}
