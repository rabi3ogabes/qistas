import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Pushes on this phone (Win Plan PP9). Firebase does the work once the owner's Firebase project is in the build
/// (google-services.json); until then [start] answers null and alerts wait in the inbox.
abstract class PushService {
  /// Starts listening and gives this phone's token, or null when pushes cannot reach it (no Firebase in this build,
  /// or the person said no to notifications).
  Future<String?> start();

  /// A new token from Firebase, to register again.
  Stream<String> get tokenRefresh;

  /// Where a tapped push leads in the app ("/remind", "/contracts/…").
  Stream<String> get taps;

  /// A push that arrived while the app is open.
  Stream<void> get arrivals;
}

/// The token this phone registered with the server, kept on its own so signing out can stop pushes without the
/// sign-in state depending on the push registration (which itself follows the sign-in state).
final registeredPushTokenProvider = Provider<RegisteredPushToken>((ref) => RegisteredPushToken());

class RegisteredPushToken {
  String? value;

  /// The token, forgotten as it is handed over.
  String? take() {
    final token = value;
    value = null;

    return token;
  }
}

final pushServiceProvider = Provider<PushService>((ref) {
  final service = kIsWeb ? NoPushService() : FirebasePushService();
  ref.onDispose(service.dispose);

  return service;
});

/// A build that takes no pushes (the web build, tests).
class NoPushService implements PushService {
  @override
  Future<String?> start() async => null;

  @override
  Stream<String> get tokenRefresh => const Stream.empty();

  @override
  Stream<String> get taps => const Stream.empty();

  @override
  Stream<void> get arrivals => const Stream.empty();

  void dispose() {}
}

class FirebasePushService extends NoPushService {
  final _taps = StreamController<String>.broadcast();
  final _arrivals = StreamController<void>.broadcast();
  final _refresh = StreamController<String>.broadcast();
  final _subscriptions = <StreamSubscription<Object?>>[];
  Future<String?>? _started;

  @override
  Future<String?> start() => _started ??= _start();

  Future<String?> _start() async {
    try {
      if (Firebase.apps.isEmpty) await Firebase.initializeApp();
    } on Object {
      // No Firebase project in this build yet: pushes stay off and nothing else changes.
      return null;
    }

    try {
      final messaging = FirebaseMessaging.instance;
      final allowed = await messaging.requestPermission();
      if (allowed.authorizationStatus == AuthorizationStatus.denied) return null;

      _subscriptions
        ..add(FirebaseMessaging.onMessageOpenedApp.listen(_tapped))
        ..add(FirebaseMessaging.onMessage.listen((_) => _arrivals.add(null)))
        ..add(messaging.onTokenRefresh.listen(_refresh.add));
      final opening = await messaging.getInitialMessage();
      if (opening != null) _tapped(opening);

      return await messaging.getToken();
    } on Object {
      return null;
    }
  }

  void _tapped(RemoteMessage message) {
    final route = message.data['route'];
    if (route is String && route.startsWith('/')) _taps.add(route);
  }

  @override
  Stream<String> get tokenRefresh => _refresh.stream;

  @override
  Stream<String> get taps => _taps.stream;

  @override
  Stream<void> get arrivals => _arrivals.stream;

  @override
  void dispose() {
    for (final subscription in _subscriptions) {
      unawaited(subscription.cancel());
    }
    unawaited(_taps.close());
    unawaited(_arrivals.close());
    unawaited(_refresh.close());
  }
}
