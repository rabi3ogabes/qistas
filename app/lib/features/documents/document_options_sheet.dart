import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/documents.dart';
import '../billing/upgrade_sheet.dart';

/// Hands a file to the phone's share sheet (WhatsApp, email, a printer, any app). A provider, so tests see what was shared.
final documentSharerProvider = Provider<Future<void> Function(String path, String subject)>(
  (ref) => (path, subject) => SharePlus.instance.share(ShareParams(files: [XFile(path, mimeType: _mimeOf(path))], subject: subject)),
);

/// What a file is, from its name, so the app it is shared to knows how to open it.
String _mimeOf(String path) => switch (path.split('.').last.toLowerCase()) {
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'zip' => 'application/zip',
      _ => 'application/pdf',
    };

/// Where a document is written before it is shared: the app's own temporary folder.
final documentFolderProvider = Provider<Future<Directory> Function()>((ref) => getTemporaryDirectory);

/// How the workspace likes its documents; the defaults when the server cannot say (an older server, no signal).
final documentPreferencesProvider = FutureProvider.autoDispose<DocumentPreferences>((ref) async {
  try {
    return await ref.watch(apiProvider).documentPreferences();
  } on ApiException {
    return const DocumentPreferences();
  }
});

/// One document the server can make (Win Plan PP8): where it is, what it is called, and which choices it has.
@immutable
class DocumentRequest {
  const DocumentRequest({
    required this.path,
    required this.title,
    required this.filename,
    this.sections = const ['overdue', 'signature'],
    this.summary = true,
    this.rolls = false,
  });

  /// A contract's statement: its terms, what is late, the schedule and its account.
  const DocumentRequest.contract(String id, String reference)
      : this(path: '/contracts/$id/statement.pdf', title: 'Statement', filename: 'statement-$reference.pdf', sections: const ['overdue', 'schedule', 'signature', 'cost']);

  /// A customer's statement: all their contracts and their account.
  DocumentRequest.customer(String id, String name)
      : this(path: '/customers/$id/statement.pdf', title: 'Statement', filename: 'statement-${_safe(name)}.pdf');

  /// A receipt for a payment; it also prints on a till roll.
  const DocumentRequest.receipt(String transactionId, String reference)
      : this(path: '/payments/$transactionId/receipt.pdf', title: 'Receipt', filename: 'receipt-$reference.pdf', sections: const ['signature'], summary: false, rolls: true);

  /// An investor's report: their figures, the contracts they fund and their wallet.
  DocumentRequest.investor(String id, String name)
      : this(path: '/investors/$id/report.pdf', title: 'Report', filename: 'report-${_safe(name)}.pdf');

  final String path;

  /// The English sentence for the sheet's title (translated when shown).
  final String title;
  final String filename;
  final List<String> sections;
  final bool summary;
  final bool rolls;

  static String _safe(String name) {
    final cleaned = name.trim().replaceAll(RegExp(r'[\\/:*?"<>|\s]+'), '-');

    return cleaned.isEmpty ? 'document' : cleaned;
  }
}

Future<void> showDocumentSheet(BuildContext context, DocumentRequest request) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => DocumentOptionsSheet(request: request),
    );

/// The choices for one document, then the share sheet. Starts from the workspace's preferences.
class DocumentOptionsSheet extends ConsumerStatefulWidget {
  const DocumentOptionsSheet({super.key, required this.request});

  final DocumentRequest request;

  @override
  ConsumerState<DocumentOptionsSheet> createState() => _DocumentOptionsSheetState();
}

class _DocumentOptionsSheetState extends ConsumerState<DocumentOptionsSheet> {
  String? _paper;
  String? _text;
  Map<String, bool>? _sections;
  bool _summary = false;
  bool _compact = false;
  bool _working = false;
  String? _problem;

  String _sectionLabel(BuildContext context, String section) => switch (section) {
        'cost' => context.t('What it cost you'),
        'overdue' => context.t('Overdue column'),
        'schedule' => context.t('Schedule'),
        _ => context.t('Signature'),
      };

  String _title(BuildContext context) => switch (widget.request.title) {
        'Receipt' => context.t('Receipt'),
        'Report' => context.t('Report'),
        _ => context.t('Statement'),
      };

  /// The sections this person may choose: the cost only for someone who sees the business's money.
  List<String> get _offered {
    final seesMoney = ref.read(accountProvider)?.role != 'collector';

    return [for (final section in widget.request.sections) if (section != 'cost' || seesMoney) section];
  }

  void _start(DocumentPreferences preferences) {
    _paper ??= preferences.paper;
    _text ??= preferences.text;
    _sections ??= {for (final section in _offered) section: preferences.sections[section] ?? false};
  }

  Future<void> _share() async {
    setState(() {
      _working = true;
      _problem = null;
    });

    final query = <String, dynamic>{
      'paper': _paper,
      'text': _text,
      for (final entry in _sections!.entries) 'sections[${entry.key}]': entry.value ? '1' : '0',
      if (_summary) 'summary': '1',
      if (_compact) 'compact': '1',
      'language': ref.read(localeProvider),
    };

    try {
      final bytes = await ref.read(apiProvider).document(widget.request.path, query);
      final folder = await ref.read(documentFolderProvider)();
      final file = File('${folder.path}${Platform.pathSeparator}${widget.request.filename}')..writeAsBytesSync(bytes, flush: true);
      if (!mounted) return;

      final subject = _title(context);
      await ref.read(documentSharerProvider)(file.path, subject);
      if (mounted) Navigator.of(context).pop();
    } on UpgradeRequired catch (e) {
      if (!mounted) return;
      setState(() => _working = false);
      await showUpgradeSheet(context, e);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _working = false;
          _problem = errorMessage(context, e);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final preferences = ref.watch(documentPreferencesProvider);

    return preferences.when(
      loading: () => const Padding(padding: EdgeInsets.all(24), child: QSkeletonList(rows: 3)),
      error: (_, _) => const SizedBox.shrink(),
      data: (loaded) {
        _start(loaded);
        final papers = [
          ('a4', 'A4'),
          ('a5', 'A5'),
          if (widget.request.rolls) ...[('80mm', context.t('Till roll, 80 mm')), ('58mm', context.t('Till roll, 58 mm'))],
        ];

        return SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(20, 0, 20, 24 + MediaQuery.viewInsetsOf(context).bottom),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(_title(context), style: text.titleLarge),
              const SizedBox(height: 4),
              Text(context.t('Choose how it looks, then share it.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
              const SizedBox(height: 20),
              Text(context.t('Paper'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final (value, label) in papers)
                    ChoiceChip(label: Text(label), selected: _paper == value, onSelected: _working ? null : (_) => setState(() => _paper = value)),
                ],
              ),
              const SizedBox(height: 16),
              Text(context.t('Text size'), style: text.labelLarge),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  for (final (value, label) in [('small', context.t('Small')), ('normal', context.t('Normal')), ('large', context.t('Large'))])
                    ChoiceChip(label: Text(label), selected: _text == value, onSelected: _working ? null : (_) => setState(() => _text = value)),
                ],
              ),
              const SizedBox(height: 12),
              for (final section in _offered)
                SwitchListTile.adaptive(
                  contentPadding: EdgeInsets.zero,
                  title: Text(_sectionLabel(context, section)),
                  value: _sections![section] ?? false,
                  onChanged: _working ? null : (on) => setState(() => _sections![section] = on),
                ),
              if (widget.request.summary)
                SwitchListTile.adaptive(
                  contentPadding: EdgeInsets.zero,
                  title: Text(context.t('One-page summary')),
                  value: _summary,
                  onChanged: _working ? null : (on) => setState(() => _summary = on),
                ),
              SwitchListTile.adaptive(
                contentPadding: EdgeInsets.zero,
                title: Text(context.t('Smaller header')),
                value: _compact,
                onChanged: _working ? null : (on) => setState(() => _compact = on),
              ),
              if (_problem != null) ...[
                const SizedBox(height: 8),
                Text(_problem!, style: text.bodyMedium?.copyWith(color: c.danger)),
              ],
              const SizedBox(height: 16),
              QButton(label: context.t('Share'), icon: Icons.ios_share_rounded, loading: _working, onPressed: _share),
            ],
          ),
        );
      },
    );
  }
}
