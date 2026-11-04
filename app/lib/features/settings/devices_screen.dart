import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../core/ui/time_ago.dart';
import '../../data/models.dart';
import '../../data/sign_in_event.dart';
import '../security/app_lock.dart' show clockProvider;
import '../setup/countries.dart';

/// The phones and browsers signed in to my account, newest seen first.
final devicesProvider = FutureProvider.autoDispose<List<Device>>((ref) => ref.watch(apiProvider).devices());

/// The person's own latest sign-ins, newest first, or null from a server that does not have the list (it answers 404): then the
/// section is not drawn at all. Read only while the Devices screen is open.
final signInsProvider = FutureProvider.autoDispose<List<SignInEvent>?>((ref) async {
  try {
    return await ref.watch(apiProvider).signIns();
  } on ApiException catch (e) {
    if (e.isNotFound) return null;
    rethrow;
  }
});

/// Win Plan PP16: which devices are signed in, when and where each was last seen, and signing any one out. A phone the
/// owner does not recognise stands out by its place and its last time.
class DevicesScreen extends ConsumerWidget {
  const DevicesScreen({super.key});

  Future<void> _signOut(BuildContext context, WidgetRef ref, Device device) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(device.current ? context.t('Sign out of this phone?') : context.t('Sign out :device?', {'device': device.name})),
        content: Text(device.current ? context.t('You will need your password to come back.') : context.t('That device will need the password to come back.')),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.t('Cancel'))),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('Sign out'))),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;

    if (device.current) {
      await ref.read(authProvider.notifier).signOut();
      return;
    }
    try {
      await ref.read(apiProvider).signOutDevice(device.id);
      ref.invalidate(devicesProvider);
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('That phone is signed out.'))));
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final devices = ref.watch(devicesProvider);
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);

    return SectionScaffold(
      title: context.t('Devices'),
      showAccount: false,
      body: devices.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 3)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(devicesProvider)),
        data: (list) => RefreshIndicator(
          onRefresh: () {
            ref.invalidate(signInsProvider);

            return ref.refresh(devicesProvider.future);
          },
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
            children: [
              Text(context.t('Every phone signed in to your account. Sign out any you do not recognise.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 16),
              QCard(
                padding: EdgeInsets.zero,
                child: Column(
                  children: [
                    for (final (index, device) in list.indexed) ...[
                      if (index > 0) const Divider(height: 1),
                      ListTile(
                        contentPadding: const EdgeInsetsDirectional.fromSTEB(16, 8, 4, 8),
                        leading: CircleAvatar(
                          backgroundColor: device.current ? c.tintMint : c.surfaceAlt,
                          child: Icon(device.name.toLowerCase().contains('web') ? Icons.laptop_rounded : Icons.smartphone_rounded, color: device.current ? c.positive : c.inkMuted),
                        ),
                        title: Wrap(
                          spacing: 8,
                          runSpacing: 4,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [Text(device.name), if (device.current) QBadge(context.t('This device'), tone: QTone.ok)],
                        ),
                        subtitle: Text(
                          [
                            device.lastUsedAt == null ? context.t('Not used since signing in') : context.t('Last seen :when', {'when': formatMomentLong(device.lastUsedAt!, language)}),
                            ?device.lastUsedIp,
                          ].join('   '),
                          style: text.bodySmall?.copyWith(color: c.inkMuted),
                        ),
                        trailing: IconButton(
                          tooltip: context.t('Sign out :device', {'device': device.name}),
                          icon: const Icon(Icons.logout_rounded),
                          onPressed: () => _signOut(context, ref, device),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const _RecentSignIns(),
            ],
          ),
        ),
      ),
    );
  }
}

/// "Recent sign-ins": what signed in to this account lately, from where, whether a proxy shows and when, one calm row each
/// in plain words, with a failed attempt marked in text. Nothing at all from a server that has no such list.
class _RecentSignIns extends ConsumerWidget {
  const _RecentSignIns();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final signIns = ref.watch(signInsProvider);
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    // An older server: no heading, no error, no empty sentence. While it is asked, the heading and placeholders.
    if (signIns.hasValue && signIns.value == null) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Recent sign-ins')),
        signIns.when(
          loading: () => const QCard(child: QSkeletonList(rows: 3)),
          error: (error, _) => QCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(errorMessage(context, error), style: text.bodyMedium),
                const SizedBox(height: 4),
                TextButton(onPressed: () => ref.invalidate(signInsProvider), child: Text(context.t('Try again'))),
              ],
            ),
          ),
          data: (events) {
            if (events == null) return const SizedBox.shrink();
            if (events.isEmpty) return QCard(child: Text(context.t('No sign-ins are recorded yet.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)));
            final now = ref.watch(clockProvider)();
            final language = ref.watch(localeProvider);

            return Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(context.t('If one of these is not you, change your password.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
                ),
                QCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      for (final (index, event) in events.indexed) ...[
                        if (index > 0) const Divider(height: 1),
                        _SignInRow(event: event, now: now, language: language),
                      ],
                    ],
                  ),
                ),
              ],
            );
          },
        ),
      ],
    );
  }
}

class _SignInRow extends StatelessWidget {
  _SignInRow({required this.event, required this.now, required this.language}) : super(key: ValueKey('sign-in-${event.id}'));

  final SignInEvent event;
  final DateTime now;
  final String language;

  /// Where it came from, in the website's words: "Desktop · Chrome on Windows · Qatar", or for the phone app "Pixel 8 · Android
  /// · App 1.29.0 · Qatar". A part that is not known is left out. The user agent is never shown, nor the address.
  String _device(BuildContext context) {
    final kind = switch (event.deviceType) {
      'desktop' => context.t('Desktop'),
      'mobile' => context.t('Mobile'),
      'tablet' => context.t('Tablet'),
      _ => context.t('Unknown device'),
    };
    final os = event.os;
    final browser = event.browser;
    final version = event.appVersion;
    final country = event.ipCountry;
    final parts = event.isApp
        ? [
            event.deviceName ?? kind,
            ?os,
            if (version == null) context.t('App') else context.t('App :version', {'version': version}),
            if (country != null) countryName(context, country),
          ]
        : [
            kind,
            if (browser != null && os != null) context.t(':browser on :system', {'browser': browser, 'system': os}) else ?(browser ?? os),
            if (country != null) countryName(context, country),
          ];

    return parts.join(' · ');
  }

  String _verdict(BuildContext context) => switch (event.proxy) {
        'detected' => context.t('Proxy detected'),
        'possible' => context.t('Proxy possible'),
        _ => context.t('Proxy not detected'),
      };

  IconData get _icon => event.isApp
      ? Icons.smartphone_rounded
      : switch (event.deviceType) {
          'desktop' => Icons.laptop_rounded,
          'tablet' => Icons.tablet_mac_rounded,
          'mobile' => Icons.smartphone_rounded,
          _ => Icons.devices_other_rounded,
        };

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final moment = event.createdAt;
    final detail = [_verdict(context), if (moment != null) timeAgo(context, moment, now, language)].join(' · ');
    final noted = event.proxy == 'possible' || event.proxy == 'detected';

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: MergeSemantics(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            CircleAvatar(
              backgroundColor: event.failed ? c.tintBlush : c.surfaceAlt,
              child: Icon(_icon, color: event.failed ? c.danger : c.inkMuted),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(_device(context), style: text.titleSmall),
                  const SizedBox(height: 2),
                  Text(detail, style: text.bodySmall?.copyWith(color: noted ? c.warning : c.inkMuted)),
                  if (event.failed) ...[const SizedBox(height: 6), QBadge(context.t('Wrong password'), tone: QTone.warn)],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
