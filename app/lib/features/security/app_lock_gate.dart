import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/design/qistas_symbol.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import 'app_lock.dart';

/// Sits above every screen. While Qistas is locked it shows the lock instead of the books (the screens underneath keep
/// their place, so unlocking returns to exactly where the person was). While the app is in the background with the
/// lock on, a plain cover hides the books from the recent-apps view. It also carries the one-time suggestion to turn
/// the lock on.
class AppLockGate extends ConsumerStatefulWidget {
  const AppLockGate({super.key, required this.child});

  final Widget child;

  @override
  ConsumerState<AppLockGate> createState() => _AppLockGateState();
}

class _AppLockGateState extends ConsumerState<AppLockGate> with WidgetsBindingObserver {
  AppLifecycleState _lifecycle = AppLifecycleState.resumed;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final lock = ref.read(appLockProvider.notifier);
    if (state == AppLifecycleState.resumed) {
      lock.resumed();
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      lock.paused();
    }
    setState(() => _lifecycle = state);
  }

  @override
  Widget build(BuildContext context) {
    final signedIn = ref.watch(accountProvider) != null;
    final lock = ref.watch(appLockProvider);
    final showLock = signedIn && lock.active && lock.locked;
    final cover = signedIn && lock.active && !showLock && _lifecycle != AppLifecycleState.resumed;
    final offer = signedIn && !showLock && lock.offerPending && (ref.watch(deviceAuthAvailableProvider).valueOrNull ?? false);

    return Stack(
      children: [
        // The books stay mounted underneath, but cannot be read, reached or tapped while locked.
        Positioned.fill(
          child: ExcludeSemantics(
            excluding: showLock,
            child: IgnorePointer(ignoring: showLock, child: TickerMode(enabled: !showLock, child: widget.child)),
          ),
        ),
        if (offer) const Positioned(left: 0, right: 0, bottom: 0, child: _LockOffer()),
        if (showLock) const Positioned.fill(child: AppLockScreen()),
        if (cover) const Positioned.fill(child: _Cover()),
      ],
    );
  }
}

/// What a locked Qistas shows: the mark, one sentence, and the way in. It asks the phone at once when it appears.
class AppLockScreen extends ConsumerStatefulWidget {
  const AppLockScreen({super.key});

  @override
  ConsumerState<AppLockScreen> createState() => _AppLockScreenState();
}

class _AppLockScreenState extends ConsumerState<AppLockScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _unlock());
  }

  Future<void> _unlock() async {
    if (!mounted) return;
    final available = await ref.read(deviceAuthAvailableProvider.future);
    if (!available || !mounted) return;
    await ref.read(appLockProvider.notifier).unlock(context.t('Unlock Qistas to see your books'));
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final lock = ref.watch(appLockProvider);
    final available = ref.watch(deviceAuthAvailableProvider).valueOrNull ?? true;
    final left = AppLockController.maxFailures - lock.failures;

    return Material(
      key: const ValueKey('app-lock-screen'),
      color: c.bg,
      child: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: SingleChildScrollView(
              padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const QistasSymbol(height: 72),
                  const SizedBox(height: 28),
                  Text(context.t('Qistas is locked'), style: text.headlineSmall, textAlign: TextAlign.center),
                  const SizedBox(height: 10),
                  Text(
                    available
                        ? context.t('Unlock with your fingerprint, your face or your phone’s PIN.')
                        : context.t('Your business requires a screen lock. Set a fingerprint, face or PIN in your phone’s settings, then come back.'),
                    style: text.bodyLarge?.copyWith(color: c.inkMuted),
                    textAlign: TextAlign.center,
                  ),
                  if (lock.failures > 0) ...[
                    const SizedBox(height: 14),
                    Text(
                      context.t('Not unlocked. Attempts left: :count', {'count': '$left'}),
                      key: const ValueKey('app-lock-attempts'),
                      style: text.bodyMedium?.copyWith(color: c.warning),
                      textAlign: TextAlign.center,
                    ),
                  ],
                  const SizedBox(height: 32),
                  if (available)
                    QButton(
                      key: const ValueKey('app-lock-unlock'),
                      label: context.t('Unlock'),
                      icon: Icons.fingerprint_rounded,
                      onPressed: _unlock,
                    ),
                  const SizedBox(height: 8),
                  QButton(
                    key: const ValueKey('app-lock-sign-out'),
                    label: context.t('Sign out'),
                    kind: QButtonKind.text,
                    onPressed: () => ref.read(authProvider.notifier).signOut(),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Hides the books from the recent-apps view while the app is in the background with the lock on.
class _Cover extends StatelessWidget {
  const _Cover();

  @override
  Widget build(BuildContext context) => ColoredBox(color: context.qc.bg, child: const Center(child: QistasSymbol(height: 64)));
}

/// The one-time suggestion, from the third launch: lock Qistas with the fingerprint or face.
class _LockOffer extends ConsumerWidget {
  const _LockOffer();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final lock = ref.read(appLockProvider.notifier);

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 88),
        child: Material(
          key: const ValueKey('app-lock-offer'),
          color: c.surface,
          elevation: 8,
          shadowColor: Colors.black26,
          borderRadius: BorderRadius.circular(20),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(18, 16, 18, 12),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.fingerprint_rounded, color: c.accentText, size: 28),
                    const SizedBox(width: 12),
                    Expanded(child: Text(context.t('Lock Qistas with your fingerprint?'), style: text.titleMedium)),
                  ],
                ),
                const SizedBox(height: 6),
                Text(context.t('Your customers’ balances stay private if someone else picks up your phone.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
                const SizedBox(height: 10),
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    TextButton(key: const ValueKey('app-lock-offer-later'), onPressed: lock.dismissOffer, child: Text(context.t('Not now'))),
                    const SizedBox(width: 4),
                    FilledButton(
                      key: const ValueKey('app-lock-offer-accept'),
                      onPressed: () => lock.turnOn(context.t('Confirm it is you to turn on the app lock')),
                      child: Text(context.t('Turn on')),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
