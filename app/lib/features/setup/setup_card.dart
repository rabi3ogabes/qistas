import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/models.dart';
import 'countries.dart';
import 'setup_state.dart';

/// What sits at the top of Today until the business has been named (go-live basics): for the owner, the first-run card; for
/// anyone else in the business, one quiet line where it would be; for everyone, once it is named or when the server does
/// not know the card, nothing. The card folds away when it is saved, rather than making Today jump.
class SetupSlot extends ConsumerWidget {
  const SetupSlot({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final account = ref.watch(accountProvider);
    final Widget? shown = needsSetupCard(account)
        ? Padding(key: const ValueKey('setup-slot-card'), padding: const EdgeInsets.only(bottom: 18), child: SetupCard(key: const ValueKey('setup-card'), account: account!))
        : needsSetupNotice(account)
            ? const Padding(key: ValueKey('setup-slot-notice'), padding: EdgeInsets.only(bottom: 18), child: SetupNotice(key: ValueKey('setup-notice')))
            : null;

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 260),
      switchInCurve: Curves.easeOutCubic,
      switchOutCurve: Curves.easeInCubic,
      transitionBuilder: (child, animation) => SizeTransition(sizeFactor: animation, axisAlignment: -1, child: FadeTransition(opacity: animation, child: child)),
      child: shown ?? const SizedBox(key: ValueKey('setup-slot-none'), width: double.infinity),
    );
  }
}

/// The line a member of an unnamed business reads where the owner has the card: why documents wait, and whose job it is.
class SetupNotice extends StatelessWidget {
  const SetupNotice({super.key});

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Semantics(
      container: true,
      child: DecoratedBox(
        decoration: BoxDecoration(color: c.surfaceAlt, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd)),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(padding: const EdgeInsets.only(top: 1), child: Icon(Icons.schedule_rounded, size: 18, color: c.inkMuted)),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t('Your business owner needs to name the business before documents can be printed.'), style: text.bodySmall?.copyWith(color: c.inkMuted))),
            ],
          ),
        ),
      ),
    );
  }
}

/// "Name your business": one field for the name and the place the server found, told in a sentence the owner can accept or
/// change. Sends only the name, and the country if the owner changed it; the server works out the currency and the time zone.
class SetupCard extends ConsumerStatefulWidget {
  const SetupCard({super.key, required this.account});

  /// The owner's account, with the card's facts in it.
  final Account account;

  @override
  ConsumerState<SetupCard> createState() => _SetupCardState();
}

class _SetupCardState extends ConsumerState<SetupCard> {
  final _name = TextEditingController();

  /// The country picked, or the server's own when nothing has been picked; null when the server's is not one a business can
  /// start in and none has been picked yet.
  late String? _country = _startingCountry;

  /// The chooser is open (when the place is told in a sentence, it opens on Change).
  bool _changing = false;

  /// Said by the card itself, before the server is asked.
  String? _nameError;
  String? _countryError;

  WorkspaceSetup get _setup => widget.account.setup!;

  String? get _startingCountry => countryCodes.contains(_setup.country) ? _setup.country : null;

  /// The place has been fixed by a contract or a payment.
  bool get _locked => !_setup.canChangeRegion;

  /// The server found the place by itself and the card only asks the owner to accept it.
  bool get _told => !_locked && _setup.countryDetected && countryCodes.contains(_setup.country);

  /// The chooser is on the card from the start: the server could not tell.
  bool get _asking => !_locked && !_told;

  bool get _chooserShown => _asking || _changing;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  void _toggleChange() {
    setState(() {
      _changing = !_changing;
      // Folding the chooser away puts the place back as it was found, so nothing unseen is sent.
      if (!_changing) {
        _country = _startingCountry;
        _countryError = null;
      }
    });
    ref.read(setupControllerProvider.notifier).forgetComplaints();
  }

  Future<void> _pick() async {
    final chosen = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => _CountrySheet(current: _country),
    );
    if (chosen == null || !mounted) return;
    setState(() {
      _country = chosen;
      _countryError = null;
    });
    ref.read(setupControllerProvider.notifier).forgetComplaints();
  }

  Future<void> _save() async {
    if (ref.read(setupControllerProvider).busy) return;

    final name = _name.text.trim();
    if (name.isEmpty) {
      setState(() => _nameError = context.t('Enter the name of your business.'));
      return;
    }
    if (_asking && _country == null) {
      setState(() => _countryError = context.t('Choose one of the countries in the list.'));
      return;
    }
    setState(() {
      _nameError = null;
      _countryError = null;
    });

    // Only what the owner changed: the name, and the country if a different one was picked.
    final changed = _chooserShown && _country != null && _country != _setup.country;
    await ref.read(setupControllerProvider.notifier).save(name: name, country: changed ? _country : null);
  }

  void _typing(String _) {
    if (_nameError != null) setState(() => _nameError = null);
    ref.read(setupControllerProvider.notifier).forgetComplaints();
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final form = ref.watch(setupControllerProvider);
    final radius = BorderRadius.circular(QistasMetrics.radiusLg);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: c.surface,
        borderRadius: radius,
        // A gold edge and no shadow: the slot clips to its own bounds while it folds away, and a shadow would show a hard edge.
        border: Border.all(color: c.accent),
      ),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(color: c.tintSand, shape: BoxShape.circle),
                  child: Icon(Icons.storefront_outlined, color: c.accentText, size: 22),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Semantics(header: true, child: Text(context.t('Name your business'), style: text.titleMedium?.copyWith(fontWeight: FontWeight.w700))),
                      const SizedBox(height: 2),
                      Text(context.t('It is printed on every receipt and statement.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            // The label and the field are one thing to a screen reader, hint included: "Business name, Business of Layla".
            MergeSemantics(
              child: QField(
                key: const ValueKey('setup-name'),
                controller: _name,
                label: context.t('Business name'),
                hint: widget.account.businessName,
                errorText: _nameError ?? form.nameError,
                textInputAction: TextInputAction.done,
                autofillHints: const [AutofillHints.organizationName],
                enabled: !form.busy,
                maxLength: 255,
                onChanged: _typing,
                onSubmitted: (_) => _save(),
              ),
            ),
            const SizedBox(height: 14),
            if (_locked)
              const _Locked(key: ValueKey('setup-locked'))
            else ...[
              if (_told) _Told(setup: _setup, changing: _changing, enabled: !form.busy, onChange: _toggleChange) else const _Asking(),
              AnimatedSize(
                duration: const Duration(milliseconds: 200),
                curve: Curves.easeOutCubic,
                alignment: AlignmentDirectional.topStart,
                child: _chooserShown
                    ? Padding(
                        padding: const EdgeInsets.only(top: 12),
                        child: _CountryField(
                          code: _country,
                          error: _countryError ?? form.countryError,
                          helper: _told ? context.t('The currency and time zone follow the country.') : null,
                          enabled: !form.busy,
                          onTap: _pick,
                        ),
                      )
                    : const SizedBox(width: double.infinity),
              ),
            ],
            if (form.failure != null) ...[
              const SizedBox(height: 14),
              QNotice(errorMessage(context, form.failure!), icon: Icons.error_outline),
            ],
            const SizedBox(height: 18),
            QButton(key: const ValueKey('setup-save'), label: context.t('Save and continue'), loading: form.busy, onPressed: _save),
          ],
        ),
      ),
    );
  }
}

/// The place, told: "We think you are in Qatar: QAR, Qatar." with a clear way to change it.
class _Told extends StatelessWidget {
  const _Told({required this.setup, required this.changing, required this.enabled, required this.onChange});

  final WorkspaceSetup setup;
  final bool changing;
  final bool enabled;
  final VoidCallback onChange;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final sentence = context.t('We think you are in :country: :currency, :zone.', {
      'country': countryName(context, setup.country),
      'currency': setup.currency,
      'zone': timeZoneCity(setup.timezone),
    });

    return DecoratedBox(
      decoration: BoxDecoration(color: c.surfaceAlt, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd)),
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(14, 12, 6, 2),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(padding: const EdgeInsets.only(top: 2), child: Icon(Icons.public_rounded, size: 20, color: c.accentText)),
                const SizedBox(width: 10),
                Expanded(child: Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: Text(sentence, style: text.bodyMedium))),
              ],
            ),
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: Semantics(
                key: const ValueKey('setup-change'),
                container: true,
                button: true,
                expanded: changing,
                label: context.t('Change'),
                hint: context.t('Country'),
                onTap: enabled ? onChange : null,
                excludeSemantics: true,
                child: TextButton.icon(
                  onPressed: enabled ? onChange : null,
                  icon: AnimatedRotation(turns: changing ? 0.5 : 0, duration: const Duration(milliseconds: 200), child: const Icon(Icons.expand_more_rounded, size: 20)),
                  iconAlignment: IconAlignment.end,
                  label: Text(context.t('Change')),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The server could not tell where the business is: say so and ask (the chooser follows, on the card).
class _Asking extends StatelessWidget {
  const _Asking();

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(padding: const EdgeInsets.only(top: 2), child: Icon(Icons.public_rounded, size: 20, color: c.accentText)),
        const SizedBox(width: 10),
        Expanded(child: Text(context.t('We could not tell where you are. Choose your country: its currency and time zone follow.'), style: text.bodyMedium)),
      ],
    );
  }
}

/// The place can no longer change: the name is all that is asked, and why.
class _Locked extends StatelessWidget {
  const _Locked({super.key});

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(padding: const EdgeInsets.only(top: 2), child: Icon(Icons.lock_outline_rounded, size: 18, color: c.inkMuted)),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            context.t('The country can no longer be changed because this business already has contracts or payments. Contact support to change it.'),
            style: text.bodySmall?.copyWith(color: c.inkMuted),
          ),
        ),
      ],
    );
  }
}

/// The country, as a field that opens the list. The server's complaint about it (a refused change) is shown under it.
class _CountryField extends StatelessWidget {
  const _CountryField({required this.code, required this.error, required this.helper, required this.enabled, required this.onTap});

  final String? code;
  final String? error;
  final String? helper;
  final bool enabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final name = code == null ? context.t('Select your country') : countryName(context, code!);
    final radius = BorderRadius.circular(QistasMetrics.radiusButton);

    return Column(
      key: const ValueKey('setup-country'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(padding: const EdgeInsetsDirectional.only(bottom: 6), child: Text(context.t('Country'), style: text.labelLarge)),
        // One node for the whole field. What the sighted read under it, the server's refusal or else the helper line, is its hint,
        // so a screen reader says why Save did nothing; a refusal is also a live region, so it is announced the moment it comes.
        Semantics(
          button: true,
          enabled: enabled,
          label: context.t('Country: :name', {'name': name}),
          hint: error ?? helper,
          liveRegion: error != null,
          excludeSemantics: true,
          onTap: enabled ? onTap : null,
          child: InkWell(
            key: const ValueKey('setup-country-button'),
            borderRadius: radius,
            onTap: enabled ? onTap : null,
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: QistasMetrics.touchTarget),
              child: InputDecorator(
                decoration: InputDecoration(errorText: error, helperText: helper, helperMaxLines: 3, enabled: enabled),
                child: Row(
                  children: [
                    Expanded(child: Text(name, style: text.bodyLarge?.copyWith(color: code == null ? c.inkMuted : null))),
                    const SizedBox(width: 8),
                    Icon(Icons.arrow_drop_down_rounded, color: c.inkMuted),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// The countries a business can start in, as a list to choose from; the current one is ticked.
class _CountrySheet extends StatelessWidget {
  const _CountrySheet({required this.current});

  final String? current;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(padding: const EdgeInsetsDirectional.fromSTEB(20, 0, 20, 8), child: Text(context.t('Select your country'), style: text.titleLarge)),
        Flexible(
          child: ListView(
            shrinkWrap: true,
            padding: const EdgeInsets.only(bottom: 16),
            children: [
              for (final code in countryCodes)
                ListTile(
                  minTileHeight: QistasMetrics.touchTarget,
                  selected: code == current,
                  title: Text(countryName(context, code)),
                  trailing: code == current ? const Icon(Icons.check) : null,
                  onTap: () => Navigator.of(context).pop(code),
                ),
            ],
          ),
        ),
      ],
    );
  }
}
