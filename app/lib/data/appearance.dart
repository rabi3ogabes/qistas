import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../app/providers.dart';
import '../core/design/tokens.dart';

/// The look chosen in the admin's Appearance page, as the server resolved it for this workspace (or, before sign-in,
/// for the phone's region): the palette for light and dark, today's event if one is meant for it, and the welcome
/// banner for the Android app. The app never works colours out itself; it wears what the server sends.

/// A welcome banner for the app's dashboard, in the app's language.
class WelcomeBanner {
  const WelcomeBanner({
    required this.title,
    required this.message,
    required this.ctaLabel,
    required this.ctaUrl,
    required this.tone,
    required this.dismissible,
    required this.imageUrl,
    required this.key,
    required this.endsOn,
  });

  final String title;
  final String message;
  final String ctaLabel;

  /// A page of the website ("/pricing") or a secure address; null when there is no button.
  final String? ctaUrl;

  /// gold, navy, sand or sky.
  final String tone;
  final bool dismissible;
  final String? imageUrl;

  /// Changes whenever the banner changes: a closed banner is remembered by it, so a new one shows again.
  final String key;

  /// Its last day, so a kept banner stops on time even with no connection.
  final DateTime? endsOn;

  static WelcomeBanner? fromJson(Object? json) {
    if (json is! Map<String, dynamic>) return null;
    final title = (json['title'] ?? '').toString().trim();
    if (title.isEmpty) return null;

    return WelcomeBanner(
      title: title,
      message: (json['message'] ?? '').toString(),
      ctaLabel: (json['cta_label'] ?? '').toString(),
      ctaUrl: json['cta_url'] is String && (json['cta_url'] as String).isNotEmpty ? json['cta_url'] as String : null,
      tone: const {'gold', 'navy', 'sand', 'sky'}.contains(json['tone']) ? json['tone'] as String : 'gold',
      dismissible: json['dismissible'] != false,
      imageUrl: json['image_url'] is String ? json['image_url'] as String : null,
      key: (json['key'] ?? title).toString(),
      endsOn: json['ends_on'] is String ? DateTime.tryParse(json['ends_on'] as String) : null,
    );
  }

  /// Whether it is still within its days on [now] (the phone's own date).
  bool isShownOn(DateTime now) {
    final last = endsOn;
    if (last == null) return true;

    final today = DateTime(now.year, now.month, now.day);

    return !today.isAfter(DateTime(last.year, last.month, last.day));
  }
}

/// One look: a palette for light and dark, and the banner; while an event is on, also the moment it ends and the usual
/// look to go back to.
class Look {
  const Look({required this.version, required this.light, required this.dark, this.banner, this.eventName, this.eventUntil, this.base});

  final int version;
  final QistasColors light;
  final QistasColors dark;
  final WelcomeBanner? banner;
  final String? eventName;
  final DateTime? eventUntil;
  final Look? base;

  /// What to wear at [now]: this look, or the usual one once the event it belongs to has ended.
  Look wearAt(DateTime now) {
    final until = eventUntil;

    return until != null && !now.toUtc().isBefore(until) && base != null ? base! : this;
  }

  /// The server's answer, or null when it is not a look (a palette is the least a look must have).
  static Look? fromJson(Object? json) {
    if (json is! Map<String, dynamic>) return null;
    final tokens = json['tokens'];
    if (tokens is! Map<String, dynamic> || tokens['light'] is! Map<String, dynamic> || tokens['dark'] is! Map<String, dynamic>) return null;

    final event = json['event'];
    final base = json['base'];

    return Look(
      version: (json['version'] as num?)?.toInt() ?? 0,
      light: QistasColors.fromTokens(tokens['light'] as Map<String, dynamic>, fallback: QistasColors.light),
      dark: QistasColors.fromTokens(tokens['dark'] as Map<String, dynamic>, fallback: QistasColors.dark),
      banner: WelcomeBanner.fromJson(json['banner']),
      eventName: event is Map<String, dynamic> ? event['name']?.toString() : null,
      eventUntil: event is Map<String, dynamic> && event['until'] is String ? DateTime.tryParse(event['until'] as String)?.toUtc() : null,
      base: base is Map<String, dynamic> ? Look.fromJson({'version': json['version'], 'tokens': base['tokens'], 'banner': base['banner']}) : null,
    );
  }
}

/// The look the app wears. On start it is the last answer kept on the phone (so the app opens in the chosen colours
/// with no connection), then the server is asked, sending back the tag of what the app has: 304 keeps it, a new answer
/// replaces it, a failure keeps it. Asked again when the language changes, when someone signs in or out (the look
/// depends on the workspace's country), and when the app comes back after a while.
class LookController extends Notifier<Look?> {
  static String keyFor(String language) => 'look.$language';

  Timer? _eventEnds;

  @override
  Look? build() {
    final language = ref.watch(localeProvider);
    ref.listen(authProvider.select((auth) => auth.valueOrNull?.account?.tenantId), (previous, next) {
      if (previous != next) unawaited(refresh());
    });
    ref.onDispose(() => _eventEnds?.cancel());

    final saved = _saved(language);
    Future.microtask(refresh);
    _watchEventEnd(saved?.look);

    return saved?.look;
  }

  Future<void> refresh() async {
    final language = ref.read(localeProvider);
    final saved = _saved(language);

    try {
      final answer = await ref.read(apiProvider).appearance(etag: saved?.etag);
      if (answer.status == 304) return;

      final look = Look.fromJson(answer.data);
      if (look == null) return;

      await ref.read(sharedPreferencesProvider).setString(keyFor(language), jsonEncode({'etag': answer.etag, 'data': answer.data}));
      if (ref.read(localeProvider) != language) return;

      state = look;
      _watchEventEnd(look);
    } catch (_) {
      // Offline or a failing server: the app keeps the look it has.
    }
  }

  /// When an event ends while the app is open, it changes back to the usual look at that moment.
  void _watchEventEnd(Look? look) {
    _eventEnds?.cancel();
    final until = look?.eventUntil;
    if (until == null || look?.base == null) return;

    final wait = until.difference(DateTime.now().toUtc());
    if (wait.isNegative) return;

    _eventEnds = Timer(wait + const Duration(seconds: 1), () => state = look!.base);
  }

  ({Look look, String? etag})? _saved(String language) {
    final raw = ref.read(sharedPreferencesProvider).getString(keyFor(language));
    if (raw == null) return null;

    try {
      final kept = jsonDecode(raw);
      if (kept is! Map<String, dynamic>) return null;
      final look = Look.fromJson(kept['data']);

      return look == null ? null : (look: look, etag: kept['etag'] as String?);
    } on FormatException {
      return null;
    }
  }
}

final lookProvider = NotifierProvider<LookController, Look?>(LookController.new);

/// The welcome banners this person closed, by their key, kept on the phone.
class DismissedBanners extends Notifier<Set<String>> {
  static const String key = 'banners.dismissed';

  @override
  Set<String> build() => (ref.read(sharedPreferencesProvider).getStringList(key) ?? const <String>[]).toSet();

  Future<void> dismiss(String banner) async {
    // The newest fifty are plenty: an old key belongs to a banner that has long changed.
    final kept = [...state.where((k) => k != banner), banner];
    state = kept.toSet();
    await ref.read(sharedPreferencesProvider).setStringList(key, kept.length > 50 ? kept.sublist(kept.length - 50) : kept);
  }
}

final dismissedBannersProvider = NotifierProvider<DismissedBanners, Set<String>>(DismissedBanners.new);

/// Opens an address outside the app (a banner's button). Tests put their own in its place.
final openExternalProvider = Provider<Future<bool> Function(Uri uri)>((ref) => (uri) => launchUrl(uri, mode: LaunchMode.externalApplication));
