import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../app/router.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/design/tokens.dart';
import '../../core/l10n/translations.dart';
import '../../core/push/push_service.dart';
import '../../data/models.dart';
import '../../data/notifications.dart';

/// The inbox: the morning summary and the instalment alerts, newest first (Win Plan PP9).
final inboxProvider = FutureProvider.autoDispose<InboxPage>((ref) => ref.watch(apiProvider).inbox());

final alertPreferencesProvider = FutureProvider.autoDispose<AlertPreferences>((ref) => ref.watch(apiProvider).alertPreferences());

/// The business's reminder wording in every language.
final reminderWordingProvider = FutureProvider.autoDispose<List<ReminderWording>>((ref) => ref.watch(apiProvider).reminderWording());

/// Alerts show once the server lists either kind and it is on (an older server has neither).
bool showsAlerts(Account? account) =>
    (account?.entitlements['instalment_alerts']?.isOn ?? false) || (account?.entitlements['daily_digest']?.isOn ?? false);

/// "Remind everyone" and the business's own wording come with the instalment alerts.
bool remindsEveryone(Account? account) => account?.entitlements['instalment_alerts']?.isOn ?? false;

/// Keeps this phone registered for pushes while someone is signed in, and opens what a tapped push points to.
final pushRegistrationProvider = Provider<PushRegistration>((ref) {
  final registration = PushRegistration(ref);
  ref.onDispose(registration.dispose);

  return registration;
});

class PushRegistration {
  PushRegistration(this._ref) {
    _ref.listen<AsyncValue<AuthState>>(authProvider, (_, next) {
      final state = next.valueOrNull;
      if (state != null && state.isSignedIn && !state.offline) unawaited(_register());
    }, fireImmediately: true);
    _subscriptions.add(_ref.read(pushServiceProvider).taps.listen((route) => _ref.read(routerProvider).push(route)));
  }

  final Ref _ref;
  final _subscriptions = <StreamSubscription<Object?>>[];
  bool _listening = false;

  Future<void> _register() async {
    final push = _ref.read(pushServiceProvider);
    final token = await push.start();
    if (token == null) return;

    await _send(token);
    if (!_listening) {
      _listening = true;
      _subscriptions
        ..add(push.tokenRefresh.listen(_send))
        // A push that arrives while the app is open lands in the inbox too: show it there.
        ..add(push.arrivals.listen((_) => _ref.invalidate(inboxProvider)));
    }
  }

  Future<void> _send(String token) async {
    _ref.read(registeredPushTokenProvider).value = token;
    try {
      await _ref.read(apiProvider).registerPushToken(token, platform: defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android', appVersion: AppConfig.appVersion);
    } on ApiException {
      // The next sign-in or token refresh tries again; alerts still reach the inbox meanwhile.
    }
  }

  void dispose() {
    for (final subscription in _subscriptions) {
      unawaited(subscription.cancel());
    }
  }
}

/// The bell at the top of the dashboard, with how many alerts are unread.
class InboxButton extends ConsumerWidget {
  const InboxButton({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (!showsAlerts(ref.watch(accountProvider))) return const SizedBox.shrink();
    final unread = ref.watch(inboxProvider).valueOrNull?.unread ?? 0;
    final c = context.qc;

    return IconButton(
      tooltip: context.t('Alerts'),
      onPressed: () async {
        await context.push('/inbox');
        ref.invalidate(inboxProvider);
      },
      icon: Badge(
        isLabelVisible: unread > 0,
        label: Text(unread > 99 ? '99+' : '$unread'),
        backgroundColor: c.accent,
        textColor: c.onAccent,
        child: const Icon(Icons.notifications_none_rounded),
      ),
    );
  }
}
