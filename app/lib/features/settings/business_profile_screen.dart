import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/providers.dart';
import '../../app/shell.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/documents.dart';
import '../documents/document_options_sheet.dart';
import 'signature_pad.dart';

export 'signature_pad.dart' show SignaturePad;

/// The business profile and whether this person may change it.
final businessProfileProvider = FutureProvider.autoDispose<({BusinessProfile profile, bool canEdit})>((ref) => ref.watch(apiProvider).businessProfile());

/// Picks a logo from the phone's photos: its bytes and its name, or null when the person backs out. A provider, so tests
/// answer for the gallery.
final logoPickerProvider = Provider<Future<({Uint8List bytes, String name})?> Function()>((ref) => () async {
      final picked = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1600, maxHeight: 1600);
      if (picked == null) return null;

      return (bytes: await picked.readAsBytes(), name: picked.name);
    });

/// Win Plan PP8: who the statements and receipts come from (names, phone, address, CR and VAT numbers, footer, logo, a
/// signature drawn on the screen) and how they look by default. Everyone sees it; the owner and managers change it.
class BusinessProfileScreen extends ConsumerWidget {
  const BusinessProfileScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final loaded = ref.watch(businessProfileProvider);

    return SectionScaffold(
      title: context.t('Business profile'),
      showAccount: false,
      body: loaded.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 5)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(businessProfileProvider)),
        data: (data) => _ProfileForm(profile: data.profile, canEdit: data.canEdit),
      ),
    );
  }
}

class _ProfileForm extends ConsumerStatefulWidget {
  const _ProfileForm({required this.profile, required this.canEdit});

  final BusinessProfile profile;
  final bool canEdit;

  @override
  ConsumerState<_ProfileForm> createState() => _ProfileFormState();
}

class _ProfileFormState extends ConsumerState<_ProfileForm> {
  late final Map<String, TextEditingController> _fields = {
    'name_ar': TextEditingController(text: widget.profile.nameAr),
    'name_en': TextEditingController(text: widget.profile.nameEn),
    'phone': TextEditingController(text: widget.profile.phone),
    'address': TextEditingController(text: widget.profile.address),
    'cr_number': TextEditingController(text: widget.profile.crNumber),
    'vat_number': TextEditingController(text: widget.profile.vatNumber),
    'footer': TextEditingController(text: widget.profile.footer),
  };
  final _signature = SignaturePadController();
  late BusinessProfile _profile = widget.profile;
  String? _busy;
  Map<String, List<String>> _errors = const {};

  @override
  void dispose() {
    for (final controller in _fields.values) {
      controller.dispose();
    }
    _signature.dispose();
    super.dispose();
  }

  /// Runs [action] with its button showing progress, then says [done] (already translated). True when it worked.
  Future<bool> _run(String key, Future<BusinessProfile> Function() action, String done) async {
    setState(() {
      _busy = key;
      _errors = const {};
    });
    try {
      final profile = await action();
      if (!mounted) return false;
      setState(() => _profile = profile);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(done)));

      return true;
    } on ApiException catch (e) {
      if (!mounted) return false;
      setState(() => _errors = e.fields);
      final message = e.fields['file']?.firstOrNull ?? (e.fields.isEmpty ? errorMessage(context, e) : null);
      if (message != null) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

      return false;
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  Future<void> _saveProfile() => _run('profile', () => ref.read(apiProvider).saveBusinessProfile({for (final entry in _fields.entries) entry.key: entry.value.text}), context.t('Business profile saved.'));

  Future<void> _chooseLogo() async {
    final picked = await ref.read(logoPickerProvider)();
    if (picked == null || !mounted) return;
    await _run('logo', () => ref.read(apiProvider).uploadBusinessPicture('logo', picked.bytes, picked.name), context.t('Logo saved.'));
  }

  Future<void> _saveSignature() async {
    setState(() => _busy = 'signature');
    final png = await _signature.toPng();
    if (png == null || !mounted) {
      if (mounted) setState(() => _busy = null);
      return;
    }
    if (await _run('signature', () => ref.read(apiProvider).uploadBusinessPicture('signature', png, 'signature.png'), context.t('Signature saved.'))) _signature.clear();
  }

  Uint8List? _bytes(String? dataUri) {
    if (dataUri == null) return null;
    try {
      return UriData.parse(dataUri).contentAsBytes();
    } on FormatException {
      return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final edit = widget.canEdit;
    final labels = {
      'name_ar': context.t('Name in Arabic'),
      'name_en': context.t('Name in English'),
      'phone': context.t('Phone'),
      'address': context.t('Address'),
      'cr_number': context.t('Commercial registration (CR)'),
      'vat_number': context.t('VAT number'),
      'footer': context.t('Footer line'),
    };
    final logo = _bytes(_profile.logo);
    final signature = _bytes(_profile.signature);

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(context.t('Who your statements and receipts come from, and how they look.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
          QSectionTitle(context.t('Your business')),
          QCard(
            child: edit
                ? Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      for (final entry in labels.entries) ...[
                        QField(
                          controller: _fields[entry.key]!,
                          label: entry.value,
                          errorText: _errors[entry.key]?.firstOrNull,
                          latin: const {'phone', 'cr_number', 'vat_number', 'name_en'}.contains(entry.key),
                          keyboardType: entry.key == 'phone' ? TextInputType.phone : null,
                          maxLength: entry.key == 'address' || entry.key == 'footer' ? 255 : 120,
                          enabled: _busy == null,
                        ),
                        const SizedBox(height: 12),
                      ],
                      QButton(label: context.t('Save the profile'), icon: Icons.check, loading: _busy == 'profile', onPressed: _busy == null ? _saveProfile : null),
                    ],
                  )
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (final entry in labels.entries)
                        QFact(entry.value, _fields[entry.key]!.text.isEmpty ? '—' : _fields[entry.key]!.text, latin: const {'phone', 'cr_number', 'vat_number'}.contains(entry.key)),
                    ],
                  ),
          ),
          QSectionTitle(context.t('Logo')),
          QCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(
                  height: 96,
                  alignment: Alignment.center,
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd), border: Border.all(color: c.line)),
                  child: logo == null
                      ? Text(context.t('No logo yet'), style: text.bodySmall?.copyWith(color: c.inkMuted))
                      : Image.memory(logo, fit: BoxFit.contain, errorBuilder: (_, _, _) => const SizedBox.shrink()),
                ),
                const SizedBox(height: 8),
                Text(context.t('Kept now and printed at the top of every document with Pro.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                if (edit) ...[
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      QButton(label: logo == null ? context.t('Choose a logo') : context.t('Replace the logo'), icon: Icons.image_outlined, kind: QButtonKind.quiet, expand: false, loading: _busy == 'logo', onPressed: _busy == null ? _chooseLogo : null),
                      if (logo != null)
                        QButton(label: context.t('Remove'), kind: QButtonKind.text, expand: false, onPressed: _busy == null ? () => _run('logo', () => ref.read(apiProvider).removeBusinessPicture('logo'), context.t('Logo removed.')) : null),
                    ],
                  ),
                ],
              ],
            ),
          ),
          QSectionTitle(context.t('Signature')),
          QCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (signature != null) ...[
                  Container(
                    height: 90,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd), border: Border.all(color: c.line)),
                    child: Image.memory(signature, fit: BoxFit.contain, errorBuilder: (_, _, _) => const SizedBox.shrink()),
                  ),
                  const SizedBox(height: 12),
                ] else if (!edit)
                  Text(context.t('No signature yet.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                if (edit) ...[
                  SignaturePad(controller: _signature),
                  const SizedBox(height: 12),
                  ListenableBuilder(
                    listenable: _signature,
                    builder: (context, _) => Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: [
                        QButton(
                          label: signature == null ? context.t('Save the signature') : context.t('Replace the signature'),
                          icon: Icons.draw_outlined,
                          expand: false,
                          loading: _busy == 'signature',
                          onPressed: _busy == null && !_signature.isEmpty ? _saveSignature : null,
                        ),
                        QButton(label: context.t('Start again'), kind: QButtonKind.text, expand: false, onPressed: _signature.isEmpty ? null : _signature.clear),
                        if (signature != null)
                          QButton(label: context.t('Remove the signature'), kind: QButtonKind.text, expand: false, onPressed: _busy == null ? () => _run('signature', () => ref.read(apiProvider).removeBusinessPicture('signature'), context.t('Signature removed.')) : null),
                      ],
                    ),
                  ),
                ],
              ],
            ),
          ),
          QSectionTitle(context.t('How documents look')),
          _Preferences(canEdit: edit),
        ],
      ),
    );
  }
}

/// The paper, text size, sections and the shop's own words every document starts from.
class _Preferences extends ConsumerStatefulWidget {
  const _Preferences({required this.canEdit});

  final bool canEdit;

  @override
  ConsumerState<_Preferences> createState() => _PreferencesState();
}

class _PreferencesState extends ConsumerState<_Preferences> {
  DocumentPreferences? _value;
  final Map<String, TextEditingController> _words = {for (final term in const ['customer', 'contract', 'investor', 'instalment']) term: TextEditingController()};
  bool _saving = false;


  @override
  void dispose() {
    for (final controller in _words.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      final value = _value!;
      final saved = await ref.read(apiProvider).saveDocumentPreferences(DocumentPreferences(
        paper: value.paper,
        text: value.text,
        sections: value.sections,
        wording: {for (final entry in _words.entries) entry.key: entry.value.text.trim()},
      ));
      ref.invalidate(documentPreferencesProvider);
      if (!mounted) return;
      setState(() => _value = saved);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Document choices saved.'))));
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final c = context.qc;
    final loaded = ref.watch(documentPreferencesProvider);
    final sectionLabels = {'cost': context.t('What it cost you'), 'overdue': context.t('Overdue column'), 'schedule': context.t('Schedule'), 'signature': context.t('Signature')};
    final termLabels = {'customer': context.t('Customer'), 'contract': context.t('Contract'), 'investor': context.t('Investor'), 'instalment': context.t('Instalment')};

    return QCard(
      child: loaded.when(
        loading: () => const QSkeletonList(rows: 2),
        error: (_, _) => const SizedBox.shrink(),
        data: (preferences) {
          if (_value == null) {
            _value = preferences;
            for (final entry in _words.entries) {
              entry.value.text = preferences.wording[entry.key] ?? '';
            }
          }
          final value = _value!;
          final edit = widget.canEdit && !_saving;
          void change(DocumentPreferences next) => setState(() => _value = next);

          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t('Every statement, report and receipt starts from these. You can change them for one document when you make it.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 16),
              Text(context.t('Paper'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(spacing: 8, children: [
                for (final paper in const ['a4', 'a5'])
                  ChoiceChip(
                    label: Text(paper.toUpperCase()),
                    selected: value.paper == paper,
                    onSelected: edit ? (_) => change(DocumentPreferences(paper: paper, text: value.text, sections: value.sections, wording: value.wording)) : null,
                  ),
              ]),
              const SizedBox(height: 16),
              Text(context.t('Text size'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(spacing: 8, children: [
                for (final (size, label) in [('small', context.t('Small')), ('normal', context.t('Normal')), ('large', context.t('Large'))])
                  ChoiceChip(
                    label: Text(label),
                    selected: value.text == size,
                    onSelected: edit ? (_) => change(DocumentPreferences(paper: value.paper, text: size, sections: value.sections, wording: value.wording)) : null,
                  ),
              ]),
              const SizedBox(height: 8),
              for (final entry in sectionLabels.entries)
                SwitchListTile.adaptive(
                  contentPadding: EdgeInsets.zero,
                  title: Text(entry.value),
                  value: value.sections[entry.key] ?? false,
                  onChanged: edit ? (on) => change(DocumentPreferences(paper: value.paper, text: value.text, sections: {...value.sections, entry.key: on}, wording: value.wording)) : null,
                ),
              const SizedBox(height: 8),
              Text(context.t('Your own words'), style: text.labelLarge),
              const SizedBox(height: 4),
              Text(context.t('Call things what you call them, for example Partner instead of Investor. Leave a box empty to keep the usual word.'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 12),
              for (final entry in termLabels.entries) ...[
                QField(controller: _words[entry.key]!, label: entry.value, hint: entry.value, enabled: edit, maxLength: 30),
                const SizedBox(height: 8),
              ],
              if (widget.canEdit) QButton(label: context.t('Save the choices'), icon: Icons.check, loading: _saving, onPressed: _saving ? null : _save),
            ],
          );
        },
      ),
    );
  }
}
