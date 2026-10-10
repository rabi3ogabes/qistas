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
import '../../data/models.dart';

/// The phones and browsers signed in to my account, newest seen first.
final devicesProvider = FutureProvider.autoDispose<List<Device>>((ref) => ref.watch(apiProvider).devices());

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
          onRefresh: () => ref.refresh(devicesProvider.future),
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
            ],
          ),
        ),
      ),
    );
  }
}
