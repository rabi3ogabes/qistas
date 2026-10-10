import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qistas/features/security/app_lock.dart';

import '../support/fake_api.dart';
import '../support/fake_device_auth.dart';
import '../support/harness.dart';
import '../support/samples.dart';

/// The app lock (Win Plan PP2): Qistas opens only after the phone's fingerprint, face or PIN, locks again after time
/// away, signs out after five failures, and the business can require it of everyone.

final lockScreen = find.byKey(const ValueKey('app-lock-screen'));

Future<void> goAway(WidgetTester tester, FakeClock clock, Duration away) async {
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
  await tester.pump();
  clock.advance(away);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
  tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  await settle(tester);
}

void main() {
  testWidgets('starts locked when the lock is on, and opens with the fingerprint', (tester) async {
    final auth = FakeDeviceAuth(answers: [false, true]);
    await pumpApp(tester, workspaceServer(), deviceAuth: auth, preferences: {AppLockController.enabledKey: true});

    // The phone was asked at once; that first try was dismissed, so the books stay hidden.
    expect(auth.prompts, 1);
    expect(lockScreen, findsOneWidget);
    expect(find.byKey(const ValueKey('app-lock-attempts')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('app-lock-unlock')));
    await settle(tester);

    expect(auth.prompts, 2);
    expect(lockScreen, findsNothing);
    expect(find.textContaining('Layla'), findsWidgets, reason: 'the dashboard greets the owner again');
  });

  testWidgets('locks again after five minutes away, but not after four', (tester) async {
    final auth = FakeDeviceAuth();
    final clock = FakeClock();
    await pumpApp(tester, workspaceServer(), deviceAuth: auth, clock: clock, preferences: {AppLockController.enabledKey: true});
    expect(lockScreen, findsNothing, reason: 'unlocked at start');

    await goAway(tester, clock, const Duration(minutes: 4));
    expect(lockScreen, findsNothing);
    expect(auth.prompts, 1);

    auth.answers = [false];
    await goAway(tester, clock, const Duration(minutes: 5));
    expect(lockScreen, findsOneWidget);
    expect(auth.prompts, 2);
  });

  testWidgets('signs out after five failed tries', (tester) async {
    final auth = FakeDeviceAuth(answers: [false]);
    final server = workspaceServer(routes: {'POST /auth/logout': always(json(200, <String, dynamic>{}))});
    await pumpApp(tester, server, deviceAuth: auth, preferences: {AppLockController.enabledKey: true});

    for (var i = 0; i < 4; i++) {
      await tester.tap(find.byKey(const ValueKey('app-lock-unlock')));
      await settle(tester);
    }

    expect(auth.prompts, 5);
    expect(lockScreen, findsNothing);
    expect(server.calls('POST /auth/logout'), 1);
    expect(find.text('Sign in'), findsWidgets);
  });

  testWidgets('a business that requires the lock locks every phone, and it cannot be turned off', (tester) async {
    final auth = FakeDeviceAuth();
    await pumpApp(tester, workspaceServer(account: accountJson(role: 'collector', requireAppLock: true)), deviceAuth: auth);

    expect(auth.prompts, 1, reason: 'asked at start although this person never turned it on');

    await openSettings(tester);
    await tapText(tester, 'App lock');

    final toggle = tester.widget<SwitchListTile>(find.byKey(const ValueKey('app-lock-switch')));
    expect(toggle.value, isTrue);
    expect(toggle.onChanged, isNull);
    expect(find.text('Your business requires the app lock.'), findsOneWidget);
    // Only the owner and managers decide for the business.
    expect(find.byKey(const ValueKey('app-lock-require')), findsNothing);
  });

  testWidgets('turning it on asks the phone first, then remembers the choice and how soon to ask again', (tester) async {
    final auth = FakeDeviceAuth();
    await pumpApp(tester, workspaceServer(), deviceAuth: auth);
    await openSettings(tester);
    await tapText(tester, 'App lock');

    await tester.tap(find.byKey(const ValueKey('app-lock-switch')));
    await settle(tester);
    expect(auth.reasons.single, 'Confirm it is you to turn on the app lock');

    await tester.tap(find.byKey(const ValueKey('app-lock-after-60')));
    await settle(tester);

    final prefs = await preferences();
    expect(prefs.getBool(AppLockController.enabledKey), isTrue);
    expect(prefs.getInt(AppLockController.afterKey), 60);
  });

  testWidgets('a phone with no screen lock says how to get one, and offers no switch', (tester) async {
    await pumpApp(tester, workspaceServer(), deviceAuth: FakeDeviceAuth.unavailable());
    await openSettings(tester);
    await tapText(tester, 'App lock');

    expect(find.byKey(const ValueKey('app-lock-unavailable')), findsOneWidget);
    expect(tester.widget<SwitchListTile>(find.byKey(const ValueKey('app-lock-switch'))).onChanged, isNull);
  });

  testWidgets('the owner can require it for everyone in the business', (tester) async {
    final server = workspaceServer(routes: {
      'PUT /workspace/security': always(json(200, {'data': {'require_app_lock': true}})),
    });
    await pumpApp(tester, server, deviceAuth: FakeDeviceAuth());
    await openSettings(tester);
    await tapText(tester, 'App lock');
    server.on('GET /me', always(json(200, {'data': accountJson(requireAppLock: true)})));

    await tester.tap(find.byKey(const ValueKey('app-lock-require')));
    await settle(tester);

    expect(jsonDecode(server.requestsTo('PUT /workspace/security').single.data as String), {'require_app_lock': true});
    expect(tester.widget<SwitchListTile>(find.byKey(const ValueKey('app-lock-require'))).value, isTrue);
  });

  testWidgets('suggests the lock once, on the third launch', (tester) async {
    await pumpApp(tester, workspaceServer(), deviceAuth: FakeDeviceAuth(), preferences: {AppLockController.launchesKey: 2});

    expect(find.byKey(const ValueKey('app-lock-offer')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('app-lock-offer-later')));
    await settle(tester);

    expect(find.byKey(const ValueKey('app-lock-offer')), findsNothing);
    expect((await preferences()).getBool(AppLockController.offeredKey), isTrue);
  });

  testWidgets('does not suggest it before the third launch', (tester) async {
    await pumpApp(tester, workspaceServer(), deviceAuth: FakeDeviceAuth(), preferences: {AppLockController.launchesKey: 1});

    expect(find.byKey(const ValueKey('app-lock-offer')), findsNothing);
  });
}
