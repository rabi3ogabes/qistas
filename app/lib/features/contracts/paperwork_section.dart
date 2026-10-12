import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/appearance.dart' show openExternalProvider;
import '../../data/models.dart';
import '../../data/paperwork.dart';
import '../documents/document_options_sheet.dart';
import '../reminders/reminders.dart' show whatsappNumber;
import '../settings/signature_pad.dart';
import 'contracts_screen.dart';

/// Takes a photo with the camera or picks one from the phone, drawn at most 1600 pixels on its longest side (the server
/// keeps no more). Null when the person backs out. A provider, so tests answer for the camera.
final photoPickerProvider = Provider<Future<({Uint8List bytes, String name})?> Function(ImageSource source)>((ref) => (source) async {
      final picked = await ImagePicker().pickImage(source: source, maxWidth: 1600, maxHeight: 1600, imageQuality: 85);
      if (picked == null) return null;

      return (bytes: await picked.readAsBytes(), name: picked.name);
    });

/// How a photo's short-lived link becomes a picture. A provider, so tests show pictures without a network.
final photoImageProvider = Provider<ImageProvider Function(String url)>((ref) => NetworkImage.new);

/// The paperwork kept with a contract (Win Plan K5): up to three guarantors, photos of the ID, the signed contract and
/// the invoice, the customer's signature and the contract and promissory-note PDFs. Owners, managers and accountants add
/// them while the switch is on; owners and managers remove them. An ID photo is never shown in the grid: it is opened
/// one at a time, through its own link. The customer signs on this phone, or on theirs through a link and a code that
/// are sent apart.
class PaperworkSection extends ConsumerWidget {
  const PaperworkSection({super.key, required this.contract});

  final Contract contract;

  /// Shown while this person may add paperwork, and on any contract that has some.
  static bool shows(Account? account, Contract contract) =>
      _canAdd(account) || contract.guarantors.isNotEmpty || contract.photos.isNotEmpty || contract.signature != null;

  static bool _canAdd(Account? account) => account != null && account.keepsPaperwork && account.seesIdentities;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final account = ref.watch(accountProvider);
    final canAdd = _canAdd(account);
    final canRemove = account?.canDelete == true;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Paperwork')),
        QCard(
          padding: EdgeInsets.zero,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _Heading(context.t('Guarantors')),
              if (contract.guarantors.isEmpty)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                  child: Text(context.t('No guarantors on this contract.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
                ),
              for (final (index, guarantor) in contract.guarantors.indexed) ...[
                if (index > 0) const Divider(height: 1, indent: 16, endIndent: 16),
                _GuarantorRow(
                  guarantor: guarantor,
                  onEdit: canAdd ? () => _editGuarantor(context, ref, guarantor) : null,
                  onRemove: canRemove ? () => _removeGuarantor(context, ref, guarantor) : null,
                ),
              ],
              if (canAdd && contract.guarantors.length < Guarantor.max)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 14),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: QButton(
                      label: context.t('Add a guarantor'),
                      icon: Icons.person_add_alt_1_outlined,
                      kind: QButtonKind.quiet,
                      expand: false,
                      onPressed: () => _editGuarantor(context, ref, null),
                    ),
                  ),
                ),
              const Divider(height: 1),
              _Heading(context.t('Photos')),
              if (contract.photos.isEmpty)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                  child: Text(context.t('No photos on this contract.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
                )
              else
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                  child: GridView.count(
                    crossAxisCount: 3,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    childAspectRatio: 0.82,
                    children: [
                      for (final photo in contract.photos) _PhotoTile(photo: photo, onOpen: () => _openPhoto(context, ref, photo, canRemove: canRemove)),
                    ],
                  ),
                ),
              if (canAdd && contract.photos.length < ContractPhoto.max)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: QButton(
                      label: context.t('Add a photo'),
                      icon: Icons.add_a_photo_outlined,
                      kind: QButtonKind.quiet,
                      expand: false,
                      onPressed: () => _addPhoto(context, ref),
                    ),
                  ),
                ),
              const Divider(height: 1),
              _Heading(context.t('The customer’s signature')),
              _SignatureShown(signature: contract.signature),
              if (canAdd)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      QButton(
                        label: context.t('Sign on this phone'),
                        icon: Icons.draw_outlined,
                        kind: QButtonKind.quiet,
                        expand: false,
                        onPressed: () => _signHere(context, ref),
                      ),
                      QButton(
                        label: context.t('Send a link to sign on their phone'),
                        icon: Icons.send_to_mobile_outlined,
                        kind: QButtonKind.quiet,
                        expand: false,
                        onPressed: () => _sendSigningLink(context, ref, account!),
                      ),
                    ],
                  ),
                ),
              const Divider(height: 1),
              _Heading(context.t('Documents')),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
                child: Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    QButton(
                      label: context.t('Contract'),
                      icon: Icons.description_outlined,
                      kind: QButtonKind.quiet,
                      expand: false,
                      onPressed: () => showDocumentSheet(context, DocumentRequest.agreement(contract.id, contract.reference)),
                    ),
                    if (account != null && account.printsPromissoryNotes && account.seesIdentities)
                      QButton(
                        label: context.t('Promissory note'),
                        icon: Icons.request_quote_outlined,
                        kind: QButtonKind.quiet,
                        expand: false,
                        onPressed: () => showDocumentSheet(context, DocumentRequest.promissoryNote(contract.id, contract.reference)),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Future<void> _signHere(BuildContext context, WidgetRef ref) async {
    final signed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      enableDrag: false,
      builder: (_) => _SignSheet(contractId: contract.id),
    );
    if (signed != true || !context.mounted) return;

    ref.invalidate(contractProvider(contract.id));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Signature kept with the contract.'))));
  }

  Future<void> _sendSigningLink(BuildContext context, WidgetRef ref, Account account) async {
    SigningLink link;
    try {
      link = await ref.read(apiProvider).createSigningLink(contract.id);
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
      return;
    }
    if (!context.mounted) return;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => _SigningLinkSheet(link: link, reference: contract.reference, country: account.country),
    );
  }

  Future<void> _editGuarantor(BuildContext context, WidgetRef ref, Guarantor? guarantor) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => _GuarantorSheet(contractId: contract.id, guarantor: guarantor),
    );
    if (saved != true || !context.mounted) return;

    ref.invalidate(contractProvider(contract.id));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(guarantor == null ? context.t('Guarantor added.') : context.t('Guarantor saved.'))));
  }

  Future<void> _removeGuarantor(BuildContext context, WidgetRef ref, Guarantor guarantor) async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.t('Remove :name', {'name': guarantor.name})),
        content: Text(context.t('They are taken off this contract. The contract itself does not change.')),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.t('Cancel'))),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('Remove'))),
        ],
      ),
    );
    if (sure != true || !context.mounted) return;

    try {
      await ref.read(apiProvider).removeGuarantor(guarantor.id);
      if (!context.mounted) return;
      ref.invalidate(contractProvider(contract.id));
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Guarantor removed.'))));
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  Future<void> _addPhoto(BuildContext context, WidgetRef ref) async {
    final kept = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => _PhotoSheet(contractId: contract.id),
    );
    if (kept != true || !context.mounted) return;

    ref.invalidate(contractProvider(contract.id));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Photo kept with the contract.'))));
  }

  Future<void> _openPhoto(BuildContext context, WidgetRef ref, ContractPhoto photo, {required bool canRemove}) async {
    String url;
    try {
      // An ID photo's link is asked for only now, so the server knows it was opened.
      url = photo.url ?? await ref.read(apiProvider).fileLink(photo.id);
    } on ApiException catch (e) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
      return;
    }
    if (!context.mounted) return;

    final removed = await Navigator.of(context, rootNavigator: true).push<bool>(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => _PhotoViewer(contractId: contract.id, photo: photo, url: url, canRemove: canRemove),
    ));
    if (removed != true || !context.mounted) return;

    ref.invalidate(contractProvider(contract.id));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('Photo removed.'))));
  }
}

String photoKindLabel(BuildContext context, String kind) => switch (kind) {
      'id_front' => context.t('ID, front'),
      'id_back' => context.t('ID, back'),
      'contract_photo' => context.t('Signed contract'),
      _ => context.t('Invoice'),
    };

class _Heading extends StatelessWidget {
  const _Heading(this.label);

  final String label;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
        child: Text(label, style: Theme.of(context).textTheme.labelLarge?.copyWith(color: context.qc.inkMuted)),
      );
}

class _GuarantorRow extends StatelessWidget {
  const _GuarantorRow({required this.guarantor, this.onEdit, this.onRemove});

  final Guarantor guarantor;
  final VoidCallback? onEdit;
  final VoidCallback? onRemove;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final muted = text.bodySmall?.copyWith(color: c.inkMuted);

    return InkWell(
      onTap: onEdit,
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 4, 10),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(guarantor.name, style: text.titleSmall),
                  if (guarantor.relation != null) Text(guarantor.relation!, style: muted),
                  const SizedBox(height: 2),
                  Wrap(
                    spacing: 12,
                    children: [
                      Directionality(textDirection: TextDirection.ltr, child: Text(guarantor.phone, style: text.bodyMedium)),
                      if (guarantor.nationalId != null) Directionality(textDirection: TextDirection.ltr, child: Text(guarantor.nationalId!, style: muted)),
                    ],
                  ),
                ],
              ),
            ),
            if (onRemove != null)
              IconButton(
                tooltip: context.t('Remove :name', {'name': guarantor.name}),
                icon: const Icon(Icons.person_remove_outlined),
                onPressed: onRemove,
              ),
          ],
        ),
      ),
    );
  }
}

class _PhotoTile extends ConsumerWidget {
  const _PhotoTile({required this.photo, required this.onOpen});

  final ContractPhoto photo;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final radius = BorderRadius.circular(QistasMetrics.radiusMd);
    final url = photo.url;

    return InkWell(
      onTap: onOpen,
      borderRadius: radius,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Expanded(
            child: ClipRRect(
              borderRadius: radius,
              child: photo.isPrivate || url == null
                  ? ColoredBox(
                      color: c.accent.withValues(alpha: 0.12),
                      child: Center(child: Icon(Icons.lock_outline_rounded, color: c.accent, semanticLabel: context.t('Private'))),
                    )
                  : Image(
                      image: ref.watch(photoImageProvider)(url),
                      fit: BoxFit.cover,
                      gaplessPlayback: true,
                      errorBuilder: (_, _, _) => ColoredBox(color: c.surfaceAlt, child: Icon(Icons.image_not_supported_outlined, color: c.inkMuted)),
                    ),
            ),
          ),
          const SizedBox(height: 6),
          Text(photoKindLabel(context, photo.kind), maxLines: 1, overflow: TextOverflow.ellipsis, style: text.bodySmall?.copyWith(color: c.inkMuted)),
        ],
      ),
    );
  }
}

/// Adds a guarantor, or changes one. A blank ID number keeps the one saved; a switch removes it.
class _GuarantorSheet extends ConsumerStatefulWidget {
  const _GuarantorSheet({required this.contractId, this.guarantor});

  final String contractId;
  final Guarantor? guarantor;

  @override
  ConsumerState<_GuarantorSheet> createState() => _GuarantorSheetState();
}

class _GuarantorSheetState extends ConsumerState<_GuarantorSheet> {
  late final _name = TextEditingController(text: widget.guarantor?.name);
  late final _phone = TextEditingController(text: widget.guarantor?.phone);
  late final _relation = TextEditingController(text: widget.guarantor?.relation);
  final _nationalId = TextEditingController();
  bool _removeId = false;
  bool _saving = false;
  Map<String, List<String>> _fields = const {};
  String? _problem;

  @override
  void dispose() {
    for (final controller in [_name, _phone, _relation, _nationalId]) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() {
      _saving = true;
      _fields = const {};
      _problem = null;
    });
    final api = ref.read(apiProvider);
    try {
      final existing = widget.guarantor;
      if (existing == null) {
        await api.addGuarantor(widget.contractId, name: _name.text, phone: _phone.text, relation: _relation.text, nationalId: _nationalId.text);
      } else {
        await api.updateGuarantor(existing.id, name: _name.text, phone: _phone.text, relation: _relation.text, nationalId: _nationalId.text, removeNationalId: _removeId);
      }
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _fields = e.fields;
        _problem = e.fields.isEmpty || e.fields.containsKey('guarantors') ? errorMessage(context, e) : null;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final hasId = widget.guarantor?.nationalId != null;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(widget.guarantor == null ? context.t('Add a guarantor') : widget.guarantor!.name, style: text.titleLarge),
            const SizedBox(height: 16),
            if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 12)],
            QField(controller: _name, label: context.t('Name'), errorText: _fields['name']?.firstOrNull, enabled: !_saving, autofocus: widget.guarantor == null),
            const SizedBox(height: 12),
            QField(controller: _phone, label: context.t('Phone'), errorText: _fields['phone']?.firstOrNull, keyboardType: TextInputType.phone, latin: true, enabled: !_saving),
            const SizedBox(height: 12),
            QField(
              controller: _relation,
              label: context.t('How they know the customer (optional)'),
              hint: context.t('For example: brother, employer, neighbour.'),
              errorText: _fields['relation']?.firstOrNull,
              enabled: !_saving,
              maxLength: 60,
            ),
            const SizedBox(height: 12),
            QField(
              controller: _nationalId,
              label: context.t('ID number (optional)'),
              helper: hasId ? context.t('Saved as :id. Leave empty to keep it.', {'id': widget.guarantor!.nationalId!}) : context.t('Kept encrypted, and shown with all but the last three digits hidden.'),
              errorText: _fields['national_id']?.firstOrNull,
              keyboardType: TextInputType.number,
              latin: true,
              enabled: !_saving && !_removeId,
            ),
            if (hasId)
              SwitchListTile.adaptive(
                contentPadding: EdgeInsets.zero,
                title: Text(context.t('Remove the saved ID number')),
                value: _removeId,
                onChanged: _saving ? null : (on) => setState(() => _removeId = on),
              ),
            const SizedBox(height: 16),
            QButton(
              label: widget.guarantor == null ? context.t('Add the guarantor') : context.t('Save'),
              icon: Icons.check,
              loading: _saving,
              onPressed: _save,
            ),
          ],
        ),
      ),
    );
  }
}

/// What the photo shows, then the camera or the phone's photos.
class _PhotoSheet extends ConsumerStatefulWidget {
  const _PhotoSheet({required this.contractId});

  final String contractId;

  @override
  ConsumerState<_PhotoSheet> createState() => _PhotoSheetState();
}

class _PhotoSheetState extends ConsumerState<_PhotoSheet> {
  String? _kind;
  ImageSource? _sending;
  String? _problem;

  Future<void> _take(ImageSource source) async {
    final kind = _kind;
    if (kind == null) return;

    final picked = await ref.read(photoPickerProvider)(source);
    if (picked == null || !mounted) return;

    setState(() {
      _sending = source;
      _problem = null;
    });
    try {
      await ref.read(apiProvider).addContractPhoto(widget.contractId, kind: kind, bytes: picked.bytes, filename: picked.name);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _sending = null;
        _problem = e.fields['file']?.firstOrNull ?? e.fields['kind']?.firstOrNull ?? errorMessage(context, e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final busy = _sending != null;

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(context.t('Add a photo'), style: text.titleLarge),
          const SizedBox(height: 4),
          Text(context.t('Location and camera details are removed before the photo is kept.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
          const SizedBox(height: 16),
          if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 12)],
          Text(context.t('What it shows'), style: text.labelLarge),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final kind in contractPhotoKinds)
                ChoiceChip(label: Text(photoKindLabel(context, kind)), selected: _kind == kind, onSelected: busy ? null : (_) => setState(() => _kind = kind)),
            ],
          ),
          const SizedBox(height: 20),
          QButton(
            label: context.t('Take a photo'),
            icon: Icons.photo_camera_outlined,
            loading: _sending == ImageSource.camera,
            onPressed: _kind == null || busy ? null : () => _take(ImageSource.camera),
          ),
          const SizedBox(height: 10),
          QButton(
            label: context.t('Choose from the phone'),
            icon: Icons.photo_library_outlined,
            kind: QButtonKind.quiet,
            loading: _sending == ImageSource.gallery,
            onPressed: _kind == null || busy ? null : () => _take(ImageSource.gallery),
          ),
        ],
      ),
    );
  }
}

/// One photo, full screen, to zoom into. Answers true when it was removed.
class _PhotoViewer extends ConsumerStatefulWidget {
  const _PhotoViewer({required this.contractId, required this.photo, required this.url, required this.canRemove});

  final String contractId;
  final ContractPhoto photo;
  final String url;
  final bool canRemove;

  @override
  ConsumerState<_PhotoViewer> createState() => _PhotoViewerState();
}

class _PhotoViewerState extends ConsumerState<_PhotoViewer> {
  bool _removing = false;

  Future<void> _remove() async {
    final sure = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.t('Remove this photo')),
        content: Text(context.t('It is taken off the contract and cannot be opened again.')),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.t('Cancel'))),
          TextButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('Remove'))),
        ],
      ),
    );
    if (sure != true || !mounted) return;

    setState(() => _removing = true);
    try {
      await ref.read(apiProvider).removeContractPhoto(widget.contractId, widget.photo.id);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _removing = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(photoKindLabel(context, widget.photo.kind)),
        actions: [
          if (widget.canRemove)
            IconButton(tooltip: context.t('Remove this photo'), icon: const Icon(Icons.delete_outline), onPressed: _removing ? null : _remove),
        ],
      ),
      body: InteractiveViewer(
        maxScale: 5,
        child: Center(
          child: Image(
            image: ref.watch(photoImageProvider)(widget.url),
            fit: BoxFit.contain,
            errorBuilder: (_, _, _) => Text(context.t('This photo could not be opened. Try again.'), style: const TextStyle(color: Colors.white70)),
          ),
        ),
      ),
    );
  }
}

/// The customer's signature, with when and how it was given; or that there is none yet.
class _SignatureShown extends ConsumerWidget {
  const _SignatureShown({required this.signature});

  final ContractSignature? signature;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final shown = signature;
    if (shown == null) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        child: Text(context.t('Not signed yet.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
      );
    }

    final language = ref.watch(localeProvider);
    final day = formatDay(isoDay(shown.signedAt), language);

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (shown.png.isNotEmpty)
            Container(
              constraints: const BoxConstraints(maxWidth: 280, maxHeight: 96),
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd), border: Border.all(color: c.line)),
              child: Image.memory(shown.png, fit: BoxFit.contain, semanticLabel: context.t('The customer’s signature')),
            ),
          const SizedBox(height: 6),
          Text(
            shown.throughLink ? context.t('Signed on their own phone on :date', {'date': day}) : context.t('Signed in person on :date', {'date': day}),
            style: text.bodySmall?.copyWith(color: c.inkMuted),
          ),
        ],
      ),
    );
  }
}

/// The customer signs on this phone, handed to them. Answers true once the signature is kept.
class _SignSheet extends ConsumerStatefulWidget {
  const _SignSheet({required this.contractId});

  final String contractId;

  @override
  ConsumerState<_SignSheet> createState() => _SignSheetState();
}

class _SignSheetState extends ConsumerState<_SignSheet> {
  final _pad = SignaturePadController();
  bool _saving = false;
  String? _problem;

  @override
  void initState() {
    super.initState();
    _pad.addListener(_redraw);
  }

  void _redraw() => setState(() {});

  @override
  void dispose() {
    _pad
      ..removeListener(_redraw)
      ..dispose();
    super.dispose();
  }

  Future<void> _keep() async {
    setState(() {
      _saving = true;
      _problem = null;
    });
    final png = await _pad.toPng();
    if (png == null) {
      if (mounted) setState(() => _saving = false);
      return;
    }
    try {
      await ref.read(apiProvider).signContract(widget.contractId, png);
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _problem = e.fields['file']?.firstOrNull ?? errorMessage(context, e);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(context.t('The customer’s signature'), style: text.titleLarge),
          const SizedBox(height: 4),
          Text(context.t('Hand the screen to the customer to sign here.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
          const SizedBox(height: 16),
          if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 12)],
          SignaturePad(controller: _pad, height: 200),
          const SizedBox(height: 16),
          QButton(label: context.t('Keep the signature'), icon: Icons.check, loading: _saving, onPressed: _pad.isEmpty ? null : _keep),
          const SizedBox(height: 8),
          QButton(label: context.t('Start again'), kind: QButtonKind.text, onPressed: _saving || _pad.isEmpty ? null : _pad.clear),
        ],
      ),
    );
  }
}

/// The link and its code, once: the link goes by WhatsApp and the code by text message, so a forwarded link alone signs
/// nothing.
class _SigningLinkSheet extends ConsumerWidget {
  const _SigningLinkSheet({required this.link, required this.reference, required this.country});

  final SigningLink link;
  final String reference;
  final String country;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final open = ref.read(openExternalProvider);
    final phone = link.phone ?? '';
    final whatsapp = whatsappNumber(phone, country);
    final message = context.t('Please read and sign your contract :reference here: :url', {'reference': reference, 'url': link.url});
    final codeMessage = context.t('Your code to sign contract :reference: :code', {'reference': reference, 'code': link.code});

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(context.t('Send the link and the code apart'), style: text.titleLarge),
          const SizedBox(height: 4),
          Text(context.t('The link works once, for a day. Without the code it opens nothing, so a forwarded link signs nothing.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
          const SizedBox(height: 20),
          Container(
            padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 12),
            decoration: BoxDecoration(color: c.accent.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(QistasMetrics.radiusMd)),
            child: Column(
              children: [
                Text(context.t('Code'), style: text.labelMedium?.copyWith(color: c.inkMuted)),
                const SizedBox(height: 4),
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(link.code, style: text.headlineMedium?.copyWith(fontWeight: FontWeight.w700, letterSpacing: 6)),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          if (whatsapp != null) ...[
            QButton(
              label: context.t('Send the link on WhatsApp'),
              icon: Icons.chat_outlined,
              onPressed: () => open(Uri.https('wa.me', '/$whatsapp', {'text': message})),
            ),
            const SizedBox(height: 10),
          ],
          QButton(
            label: context.t('Send the code by text message'),
            icon: Icons.sms_outlined,
            kind: QButtonKind.quiet,
            onPressed: () => open(Uri(scheme: 'sms', path: phone, queryParameters: {'body': codeMessage})),
          ),
        ],
      ),
    );
  }
}
