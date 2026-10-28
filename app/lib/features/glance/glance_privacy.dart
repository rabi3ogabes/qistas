import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';

/// What the owner chose to show on the home-screen widget, which anyone who picks up the phone can read. Kept on this
/// phone. The app lock overrides both: see [amountsShown] and [namesShown], the only place the rules are written.
@immutable
class GlancePrivacy {
  const GlancePrivacy({this.showAmounts = true, this.showNames = false});

  final bool showAmounts;
  final bool showNames;

  GlancePrivacy copyWith({bool? showAmounts, bool? showNames}) => GlancePrivacy(showAmounts: showAmounts ?? this.showAmounts, showNames: showNames ?? this.showNames);

  /// Amounts reach the widget unless the owner hid them or the app lock is on (then only counts do).
  bool amountsShown({required bool appLockOn}) => showAmounts && !appLockOn;

  /// Names reach the widget only when the owner allowed them, the amounts are shown and the app lock is off. The
  /// request to the server asks for them under this same rule, so a name never even travels to the phone otherwise.
  bool namesShown({required bool appLockOn}) => showNames && amountsShown(appLockOn: appLockOn);

  @override
  bool operator ==(Object other) => other is GlancePrivacy && other.showAmounts == showAmounts && other.showNames == showNames;

  @override
  int get hashCode => Object.hash(showAmounts, showNames);
}

/// The two switches, remembered on this phone: amounts on and names off until the owner says otherwise.
class GlancePrivacyController extends Notifier<GlancePrivacy> {
  static const String amountsKey = 'glance.show_amounts';
  static const String namesKey = 'glance.show_names';

  @override
  GlancePrivacy build() {
    final prefs = ref.read(sharedPreferencesProvider);

    return GlancePrivacy(showAmounts: prefs.getBool(amountsKey) ?? true, showNames: prefs.getBool(namesKey) ?? false);
  }

  Future<void> setShowAmounts(bool on) async {
    state = state.copyWith(showAmounts: on);
    await ref.read(sharedPreferencesProvider).setBool(amountsKey, on);
  }

  Future<void> setShowNames(bool on) async {
    state = state.copyWith(showNames: on);
    await ref.read(sharedPreferencesProvider).setBool(namesKey, on);
  }
}

final glancePrivacyProvider = NotifierProvider<GlancePrivacyController, GlancePrivacy>(GlancePrivacyController.new);
