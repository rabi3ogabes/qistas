import 'dart:ui' show DartPluginRegistrant;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../../features/glance/glance_refresher.dart';

/// What the server's silent push says in `data.type` when it wants the home-screen widget read again. It carries no
/// notification and nothing else: the phone fetches the figures itself.
const String glanceRefreshMessage = 'glance.refresh';

/// Runs when a push arrives and the app is in the background or closed (Android starts a fresh isolate for it, which
/// has no app around it, no Riverpod and no screens). Only the widget's morning refresh is handled here; every other push
/// is left to Firebase, which shows it.
///
/// Registered in [registerBackgroundMessages]. It must stay a top-level function, and the entry-point pragma keeps the
/// release build from removing it.
@pragma('vm:entry-point')
Future<void> qistasBackgroundMessage(RemoteMessage message) async {
  // The new isolate has its plugins (shared preferences, secure storage, the widget) only once they are registered.
  DartPluginRegistrant.ensureInitialized();

  await refreshGlanceFor(message, GlanceRefresher.new);
}

/// Refreshes the widget when [message] asks for it. The refresher is made only then, so any other message touches nothing
/// on the phone.
Future<void> refreshGlanceFor(RemoteMessage message, GlanceRefresher Function() refresher) async {
  if (message.data['type'] != glanceRefreshMessage) return;

  try {
    await refresher().run();
  } on Object {
    // A failed refresh is not the push service's to hear about; the widget keeps what it has.
  }
}

/// Tells Firebase to hand pushes that arrive in the background to [qistasBackgroundMessage]. Called once, before the
/// app starts. Without a Firebase project in the build (no google-services.json) there are no pushes to hand over, and
/// everything else carries on, exactly as [FirebasePushService] does.
Future<void> registerBackgroundMessages() async {
  if (kIsWeb) return;

  try {
    if (Firebase.apps.isEmpty) await Firebase.initializeApp();
    FirebaseMessaging.onBackgroundMessage(qistasBackgroundMessage);
  } on Object {
    // No Firebase project in this build yet: pushes stay off and nothing else changes.
  }
}
