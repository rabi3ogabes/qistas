import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';

import '../../app/providers.dart';

/// The phone's own way of proving it is its owner: fingerprint or face, with the phone's PIN, pattern or password as
/// the fallback. Tests replace it.
abstract class DeviceAuth {
  /// Whether this phone can check anyone at all (it has a fingerprint, a face or at least a screen lock set up).
  Future<bool> available();

  /// Shows the phone's prompt with [reason]. True only when the person passed it.
  Future<bool> authenticate(String reason);
}

class LocalDeviceAuth implements DeviceAuth {
  final LocalAuthentication _auth = LocalAuthentication();

  @override
  Future<bool> available() async {
    if (kIsWeb) return false;
    try {
      return await _auth.isDeviceSupported();
    } on Object {
      return false;
    }
  }

  @override
  Future<bool> authenticate(String reason) async {
    try {
      return await _auth.authenticate(localizedReason: reason, persistAcrossBackgrounding: true);
    } on Object {
      // Locked out after too many tries, no screen lock any more, the prompt dismissed by the system: not passed.
      return false;
    }
  }
}

final deviceAuthProvider = Provider<DeviceAuth>((ref) => LocalDeviceAuth());

/// What time it is. Tests move it forward to stand for minutes spent away from the app.
final clockProvider = Provider<DateTime Function()>((ref) => DateTime.now);

/// Whether this phone can use the lock, asked once.
final deviceAuthAvailableProvider = FutureProvider<bool>((ref) => ref.watch(deviceAuthProvider).available());

@immutable
class AppLockState {
  const AppLockState({
    required this.enabled,
    required this.required,
    required this.lockAfter,
    required this.locked,
    required this.failures,
    required this.offerPending,
  });

  /// The person turned the lock on, on this phone.
  final bool enabled;

  /// The business requires it of everyone (the person cannot turn it off).
  final bool required;

  /// How long Qistas may sit in the background before it asks again. Zero: every time.
  final Duration lockAfter;

  /// The books are hidden until the person unlocks.
  final bool locked;

  /// Prompts failed or dismissed in a row. Five sign the person out.
  final int failures;

  /// The one-time suggestion to turn the lock on is waiting to be shown.
  final bool offerPending;

  bool get active => enabled || required;

  AppLockState copyWith({bool? enabled, bool? required, Duration? lockAfter, bool? locked, int? failures, bool? offerPending}) => AppLockState(
        enabled: enabled ?? this.enabled,
        required: required ?? this.required,
        lockAfter: lockAfter ?? this.lockAfter,
        locked: locked ?? this.locked,
        failures: failures ?? this.failures,
        offerPending: offerPending ?? this.offerPending,
      );
}

/// Locks Qistas behind the phone's fingerprint, face or PIN: when it starts, and when it comes back after
/// [AppLockState.lockAfter] in the background. The choice is kept on this phone; the business can require it.
class AppLockController extends Notifier<AppLockState> {
  static const String enabledKey = 'app_lock.enabled';
  static const String afterKey = 'app_lock.after_seconds';
  static const String offeredKey = 'app_lock.offered';
  static const String launchesKey = 'launches';

  /// The suggestion to turn the lock on comes at this launch, once.
  static const int offerAtLaunch = 3;
  static const int maxFailures = 5;
  static const List<Duration> choices = [Duration.zero, Duration(minutes: 1), Duration(minutes: 5), Duration(minutes: 15)];

  DateTime? _pausedAt;
  bool _prompting = false;

  @override
  AppLockState build() {
    final prefs = ref.read(sharedPreferencesProvider);
    final launches = (prefs.getInt(launchesKey) ?? 0) + 1;
    prefs.setInt(launchesKey, launches);

    final enabled = prefs.getBool(enabledKey) ?? false;
    final required = ref.read(accountProvider)?.requireAppLock ?? false;

    // The business can switch the requirement on while the app is open (it is seen at the next account refresh).
    ref.listen(accountProvider, (previous, next) {
      final nowRequired = next?.requireAppLock ?? false;
      if (nowRequired == state.required) return;
      state = state.copyWith(required: nowRequired, locked: nowRequired && !state.enabled ? true : state.locked);
    });
    // Someone who has just typed their password has proved who they are: no fingerprint straight after.
    ref.listen(authProvider, (previous, next) {
      final wasSignedOut = previous?.valueOrNull?.isSignedIn == false;
      if (wasSignedOut && (next.valueOrNull?.isSignedIn ?? false)) {
        state = state.copyWith(locked: false, failures: 0);
      }
    });

    return AppLockState(
      enabled: enabled,
      required: required,
      lockAfter: Duration(seconds: prefs.getInt(afterKey) ?? const Duration(minutes: 5).inSeconds),
      locked: enabled || required,
      failures: 0,
      offerPending: !enabled && !required && launches >= offerAtLaunch && !(prefs.getBool(offeredKey) ?? false),
    );
  }

  /// The app went to the background (or another screen covered it).
  void paused() {
    if (_prompting || !state.active || state.locked) return;
    _pausedAt ??= ref.read(clockProvider)();
  }

  /// The app is in front again: lock if it was away at least as long as the person chose.
  void resumed() {
    final since = _pausedAt;
    _pausedAt = null;
    if (_prompting || since == null || !state.active || state.locked) return;
    if (ref.read(clockProvider)().difference(since) >= state.lockAfter) {
      state = state.copyWith(locked: true);
    }
  }

  /// Asks the phone to confirm it is the owner. After [maxFailures] in a row the person is signed out.
  Future<void> unlock(String reason) async {
    if (!state.locked || _prompting) return;
    final passed = await _prompt(reason);
    if (passed) {
      state = state.copyWith(locked: false, failures: 0);
      return;
    }
    final failures = state.failures + 1;
    if (failures >= maxFailures) {
      state = state.copyWith(locked: false, failures: 0);
      await ref.read(authProvider.notifier).signOut();
      return;
    }
    state = state.copyWith(failures: failures);
  }

  /// Turns the lock on after the person proves they can unlock (so nobody locks themselves out).
  Future<bool> turnOn(String reason) async {
    if (state.enabled) return true;
    if (!await _prompt(reason)) return false;
    await ref.read(sharedPreferencesProvider).setBool(enabledKey, true);
    state = state.copyWith(enabled: true, locked: false, offerPending: false);
    await _markOffered();
    return true;
  }

  /// Turns it off, again only for the owner of the phone. The business's requirement keeps it on.
  Future<bool> turnOff(String reason) async {
    if (!state.enabled) return true;
    if (!await _prompt(reason)) return false;
    await ref.read(sharedPreferencesProvider).setBool(enabledKey, false);
    state = state.copyWith(enabled: false);
    return true;
  }

  Future<void> setLockAfter(Duration after) async {
    await ref.read(sharedPreferencesProvider).setInt(afterKey, after.inSeconds);
    state = state.copyWith(lockAfter: after);
  }

  /// "Not now" on the suggestion: it does not come back.
  Future<void> dismissOffer() async {
    state = state.copyWith(offerPending: false);
    await _markOffered();
  }

  Future<void> _markOffered() => ref.read(sharedPreferencesProvider).setBool(offeredKey, true);

  Future<bool> _prompt(String reason) async {
    // The phone's prompt sends the app to the background and back; that must not count as time away.
    _prompting = true;
    try {
      return await ref.read(deviceAuthProvider).authenticate(reason);
    } finally {
      _prompting = false;
      _pausedAt = null;
    }
  }
}

final appLockProvider = NotifierProvider<AppLockController, AppLockState>(AppLockController.new);
