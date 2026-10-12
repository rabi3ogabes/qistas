import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart' show PdfPageFormat;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/day_picker.dart';
import '../../core/ui/errors.dart';
import '../../data/documents.dart';
import '../../data/investors.dart';
import '../../data/models.dart';
import '../billing/upgrade_sheet.dart';
import '../investors/investors_screen.dart';
import '../products/products_screen.dart';

/// Hands a file to the phone's share sheet (WhatsApp, email, a printer, any app). A provider, so tests see what was shared.
final documentSharerProvider = Provider<Future<void> Function(String path, String subject)>(
  (ref) => (path, subject) => SharePlus.instance.share(ShareParams(files: [XFile(path, mimeType: _mimeOf(path))], subject: subject)),
);

/// Sends a PDF to the phone's print service (Win Plan RP10): Android's, which reaches every printer whose app registers
/// with it, the till-roll receipt printers included. A roll is printed at its own width. A provider, so tests see what
/// was printed.
final documentPrinterProvider = Provider<Future<void> Function(Uint8List bytes, String name, String paper)>(
  (ref) => (bytes, name, paper) => Printing.layoutPdf(onLayout: (_) async => bytes, name: name, format: paperFormat(paper)),
);

/// The page a paper choice prints on.
PdfPageFormat paperFormat(String paper) => switch (paper) {
      '80mm' => PdfPageFormat.roll80,
      '58mm' => PdfPageFormat.roll57,
      'a5' => PdfPageFormat.a5,
      _ => PdfPageFormat.a4,
    };

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

/// Whether a document covers a period, and what it covers until days are chosen.
enum DocumentPeriod {
  /// No period: the document as it stands today.
  none,

  /// The whole account until days are chosen (a statement).
  whole,

  /// This month so far until days are chosen (a payments report).
  month,
}

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
    this.period = DocumentPeriod.none,
    this.filters = false,
  });

  /// A contract's statement: its terms, what is late, the schedule and its account.
  const DocumentRequest.contract(String id, String reference)
      : this(path: '/contracts/$id/statement.pdf', title: 'Statement', filename: 'statement-$reference.pdf', sections: const ['overdue', 'schedule', 'signature', 'cost']);

  /// The contract as it was agreed (Win Plan K5): the parties, the guarantors, the plan, the schedule and the signature.
  const DocumentRequest.agreement(String id, String reference)
      : this(path: '/contracts/$id/agreement.pdf', title: 'Contract', filename: 'contract-$reference.pdf', sections: const ['signature'], summary: false);

  /// A promissory note for what is still owed (Win Plan K5): offered only once its switch is on.
  const DocumentRequest.promissoryNote(String id, String reference)
      : this(path: '/contracts/$id/promissory-note.pdf', title: 'Promissory note', filename: 'promissory-note-$reference.pdf', sections: const ['signature'], summary: false);

  /// A customer's statement: all their contracts and their account.
  DocumentRequest.customer(String id, String name)
      : this(path: '/customers/$id/statement.pdf', title: 'Statement', filename: 'statement-${_safe(name)}.pdf', period: DocumentPeriod.whole, filters: true);

  /// Every payment of a period (this month unless another is chosen), narrowed to an investor or a product if asked.
  const DocumentRequest.payments()
      : this(path: '/reports/transactions.pdf', title: 'Payments report', filename: 'payments-report.pdf', sections: const ['signature'], period: DocumentPeriod.month, filters: true);

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
  final DocumentPeriod period;

  /// Whether it can be narrowed to one investor's contracts or to the contracts that sold one product (Win Plan RP11).
  final bool filters;

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
  DateTime? _from;
  DateTime? _to;
  String? _investor;
  String? _product;

  /// What is being done, 'share' or 'print', so only that button spins and nothing else can be pressed meanwhile.
  String? _doing;
  String? _problem;

  bool get _working => _doing != null;

  @override
  void initState() {
    super.initState();
    if (widget.request.period == DocumentPeriod.month) {
      final now = DateTime.now();
      _from = DateTime(now.year, now.month);
      _to = DateTime(now.year, now.month, now.day);
    }
  }

  String _sectionLabel(BuildContext context, String section) => switch (section) {
        'cost' => context.t('What it cost you'),
        'overdue' => context.t('Overdue column'),
        'schedule' => context.t('Schedule'),
        _ => context.t('Signature'),
      };

  String _title(BuildContext context) => switch (widget.request.title) {
        'Receipt' => context.t('Receipt'),
        'Report' => context.t('Report'),
        'Payments report' => context.t('Payments report'),
        'Contract' => context.t('Contract'),
        'Promissory note' => context.t('Promissory note'),
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

  /// The document as chosen, from the server; null (and the reason on screen, or the upgrade sheet) when it cannot be had.
  Future<Uint8List?> _fetch(String doing) async {
    setState(() {
      _doing = doing;
      _problem = null;
    });

    final query = <String, dynamic>{
      'paper': _paper,
      'text': _text,
      for (final entry in _sections!.entries) 'sections[${entry.key}]': entry.value ? '1' : '0',
      if (_summary) 'summary': '1',
      if (_compact) 'compact': '1',
      if (_from != null) 'from': isoDay(_from!),
      if (_to != null) 'to': isoDay(_to!),
      if (_investor != null) 'investor': _investor,
      if (_product != null) 'product': _product,
      'language': ref.read(localeProvider),
    };

    try {
      return await ref.read(apiProvider).document(widget.request.path, query);
    } on UpgradeRequired catch (e) {
      if (mounted) {
        setState(() => _doing = null);
        await showUpgradeSheet(context, e);
      }
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _doing = null;
          _problem = errorMessage(context, e);
        });
      }
    }

    return null;
  }

  Future<void> _share() async {
    final bytes = await _fetch('share');
    if (bytes == null) return;

    final folder = await ref.read(documentFolderProvider)();
    final file = File('${folder.path}${Platform.pathSeparator}${widget.request.filename}')..writeAsBytesSync(bytes, flush: true);
    if (!mounted) return;

    final subject = _title(context);
    await ref.read(documentSharerProvider)(file.path, subject);
    if (mounted) Navigator.of(context).pop();
  }

  /// Straight to a printer through the phone's print service, on the paper chosen (Win Plan RP10).
  Future<void> _print() async {
    final bytes = await _fetch('print');
    if (bytes == null || !mounted) return;

    try {
      await ref.read(documentPrinterProvider)(bytes, widget.request.filename, _paper!);
      if (mounted) Navigator.of(context).pop();
    } on Exception {
      // A phone without a print service, or one that refused the job.
      if (mounted) {
        setState(() {
          _doing = null;
          _problem = context.t('This phone could not print it. Share it to the printer’s app instead.');
        });
      }
    }
  }

  Future<void> _pickDay({required bool from}) async {
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final earliest = DateTime(now.year - 10);
    final picked = await pickDay(
      context,
      initial: from ? (_from ?? DateTime(now.year, now.month)) : (_to ?? today),
      first: from ? earliest : (_from ?? earliest),
      last: from ? (_to ?? today) : today,
      language: ref.read(localeProvider),
    );
    if (picked != null) setState(() => from ? _from = picked : _to = picked);
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final preferences = ref.watch(documentPreferencesProvider);
    final account = ref.watch(accountProvider);
    // Investors only to those who see them, and only when there is more than the business's own money to choose from.
    final investors = widget.request.filters && (account?.seesReports ?? false) && (account?.entitlement('investors').isOn ?? false)
        ? ref.watch(investorsProvider).valueOrNull?.investors ?? const <Investor>[]
        : const <Investor>[];
    final products = widget.request.filters ? ref.watch(productsProvider).valueOrNull ?? const <Product>[] : const <Product>[];

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
              Text(context.t('Choose how it looks, then share or print it.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
              if (widget.request.period != DocumentPeriod.none) ...[
                const SizedBox(height: 20),
                Text(context.t('Period'), style: text.labelLarge),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: _DayField(
                        key: const ValueKey('document-from'),
                        label: context.t('From'),
                        day: _from,
                        empty: context.t('From the start'),
                        onTap: _working ? null : () => _pickDay(from: true),
                        onClear: widget.request.period == DocumentPeriod.whole && !_working ? () => setState(() => _from = null) : null,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _DayField(
                        key: const ValueKey('document-to'),
                        label: context.t('To'),
                        day: _to,
                        empty: context.t('Today'),
                        onTap: _working ? null : () => _pickDay(from: false),
                        onClear: widget.request.period == DocumentPeriod.whole && !_working ? () => setState(() => _to = null) : null,
                      ),
                    ),
                  ],
                ),
              ],
              if (investors.length > 1) ...[
                const SizedBox(height: 14),
                DropdownButtonFormField<String?>(
                  key: const ValueKey('document-investor'),
                  initialValue: _investor,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: context.t('Investor')),
                  items: [
                    DropdownMenuItem(child: Text(context.t('All investors'))),
                    for (final investor in investors) DropdownMenuItem(value: investor.id, child: Text(investor.name, overflow: TextOverflow.ellipsis)),
                  ],
                  onChanged: _working ? null : (id) => setState(() => _investor = id),
                ),
              ],
              if (products.isNotEmpty) ...[
                const SizedBox(height: 14),
                DropdownButtonFormField<String?>(
                  key: const ValueKey('document-product'),
                  initialValue: _product,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: context.t('Product')),
                  items: [
                    DropdownMenuItem(child: Text(context.t('All products'))),
                    for (final product in products) DropdownMenuItem(value: product.id, child: Text(product.name, overflow: TextOverflow.ellipsis)),
                  ],
                  onChanged: _working ? null : (id) => setState(() => _product = id),
                ),
              ],
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
              Row(
                children: [
                  Expanded(
                    child: QButton(
                      label: context.t('Print'),
                      icon: Icons.print_outlined,
                      kind: QButtonKind.quiet,
                      loading: _doing == 'print',
                      onPressed: _working ? null : _print,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: QButton(
                      label: context.t('Share'),
                      icon: Icons.ios_share_rounded,
                      loading: _doing == 'share',
                      onPressed: _working ? null : _share,
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }
}

/// A day to tap and choose, or the words for no day (the start of the account, today); a cross clears a chosen one.
class _DayField extends ConsumerWidget {
  const _DayField({super.key, required this.label, required this.day, required this.empty, required this.onTap, this.onClear});

  final String label;
  final DateTime? day;
  final String empty;
  final VoidCallback? onTap;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final language = ref.watch(localeProvider);
    final chosen = day;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(QistasMetrics.radiusMd),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: label,
          enabled: onTap != null,
          suffixIcon: chosen != null && onClear != null
              ? IconButton(tooltip: context.t('Clear'), icon: const Icon(Icons.close_rounded, size: 18), onPressed: onClear)
              : const Icon(Icons.calendar_today_outlined, size: 18),
        ),
        child: Text(chosen == null ? empty : formatDay(isoDay(chosen), language), maxLines: 1, overflow: TextOverflow.ellipsis),
      ),
    );
  }
}
