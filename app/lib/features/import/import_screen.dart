import 'dart:io';
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/ui/errors.dart';
import '../../data/imports.dart';
import '../documents/document_options_sheet.dart';

/// Where the books come from: an Excel or CSV file, a photo of the notebook taken now, or a photo or PDF report kept on
/// the phone.
enum ImportPick { sheet, camera, photoOrPdf }

/// Picks what to bring in: its bytes and its name, or null when the person backs out. A provider, so tests answer for
/// the camera and the file picker.
final importFilePickerProvider = Provider<Future<({Uint8List bytes, String name})?> Function(ImportPick)>((ref) => (pick) async {
      if (pick == ImportPick.camera) {
        // The server draws it again without its hidden data; 2000px keeps handwriting readable.
        final photo = await ImagePicker().pickImage(source: ImageSource.camera, maxWidth: 2000, maxHeight: 2000, imageQuality: 85);
        if (photo == null) return null;

        return (bytes: await photo.readAsBytes(), name: photo.name);
      }

      final file = await FilePicker.pickFile(
        type: FileType.custom,
        allowedExtensions: pick == ImportPick.sheet ? const ['xlsx', 'csv'] : const ['jpg', 'jpeg', 'png', 'pdf'],
      );
      if (file == null) return null;

      return (bytes: await file.readAsBytes(), name: file.name);
    });

/// Whether notebook photos and PDFs are offered. When the answer cannot be had, only files are offered.
final importOptionsProvider = FutureProvider.autoDispose<ImportOptions>((ref) async {
  try {
    return await ref.read(apiProvider).importOptions();
  } on ApiException {
    return const ImportOptions();
  }
});

/// Bringing a shop's books in (Win Plan K9): a file from the phone, the trial run with every problem by row and column,
/// the columns chosen again if the file named them its own way, then one confirm. Nothing is saved before it.
class ImportScreen extends ConsumerStatefulWidget {
  const ImportScreen({super.key});

  @override
  ConsumerState<ImportScreen> createState() => _ImportScreenState();
}

class _ImportScreenState extends ConsumerState<ImportScreen> {
  ImportJob? _job;
  String? _working;
  String? _problem;

  Future<void> _run(String what, Future<ImportJob?> Function() step) async {
    setState(() {
      _working = what;
      _problem = null;
    });
    try {
      final job = await step();
      if (!mounted) return;
      setState(() {
        _working = null;
        if (job != null) _job = job;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _working = null;
        _problem = e.fields['file']?.firstOrNull ?? errorMessage(context, e);
      });
    }
  }

  Future<void> _choose(ImportPick pick) => _run(pick.name, () async {
        final picked = await ref.read(importFilePickerProvider)(pick);
        if (picked == null) return null;

        return ref.read(apiProvider).startImport(picked.bytes, picked.name);
      });

  Future<void> _remap(int column, String? field) => _run('columns', () => ref.read(apiProvider).remapImport(_job!.id, {column: field}));

  Future<void> _confirm() => _run('confirm', () => ref.read(apiProvider).confirmImport(_job!.id));

  Future<void> _template() async {
    setState(() => _working = 'template');
    try {
      final bytes = await ref.read(apiProvider).document('/imports/template.xlsx', {'language': ref.read(localeProvider)});
      final folder = await ref.read(documentFolderProvider)();
      final file = File('${folder.path}${Platform.pathSeparator}qistas-customers.xlsx')..writeAsBytesSync(bytes, flush: true);
      if (!mounted) return;
      await ref.read(documentSharerProvider)(file.path, context.t('Bring your customers in'));
    } on ApiException catch (e) {
      if (mounted) setState(() => _problem = errorMessage(context, e));
    } finally {
      if (mounted) setState(() => _working = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final job = _job;

    return Scaffold(
      appBar: AppBar(title: Text(context.t('Bring your customers in'))),
      body: SafeArea(
        child: job == null ? _start(context) : _trial(context, job),
      ),
      bottomNavigationBar: job == null ? null : _actions(context, job),
    );
  }

  /// What to do next, always in reach at the bottom: confirm the rows that are ready, or go to the customers once done.
  Widget? _actions(BuildContext context, ImportJob job) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final ready = job.summary.ready;

    final Widget child;
    if (job.isDone) {
      child = QButton(label: context.t('Customers'), icon: Icons.people_outline, kind: QButtonKind.quiet, onPressed: () => context.go('/customers'));
    } else if (ready > 0) {
      child = Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (job.summary.problems > 0) ...[
            Text(
              context.t('Rows with a problem are left out. Fix them in the file and bring it in again; customers already brought in are recognised by their phone.'),
              style: text.bodySmall?.copyWith(color: c.inkMuted),
            ),
            const SizedBox(height: 8),
          ],
          QButton(
            label: context.t('Bring in the rows that are ready: :count', {'count': '$ready'}),
            icon: Icons.check,
            loading: _working == 'confirm',
            onPressed: _working == null ? _confirm : null,
          ),
        ],
      );
    } else {
      child = Text(context.t('No row is ready yet. Fix the problems in the file, or choose the columns above.'), style: text.bodyMedium?.copyWith(color: c.inkMuted));
    }

    return Material(
      color: c.surface,
      elevation: 8,
      child: SafeArea(top: false, child: Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 12), child: child)),
    );
  }

  Widget _start(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
      children: [
        Icon(Icons.upload_file_outlined, size: 40, color: c.accent),
        const SizedBox(height: 12),
        Text(context.t('From an Excel or CSV file. You see every row first, and nothing is saved until you confirm.'), style: text.titleMedium),
        const SizedBox(height: 8),
        Text(
          context.t('One customer per row, with a header row naming the columns. Columns are recognised in Arabic, English, French, Spanish and Urdu, and you can choose any column yourself.'),
          style: text.bodyMedium?.copyWith(color: c.inkMuted),
        ),
        const SizedBox(height: 24),
        if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 12)],
        QButton(
          label: context.t('Choose a file'),
          icon: Icons.folder_open_outlined,
          loading: _working == ImportPick.sheet.name,
          onPressed: _working == null ? () => _choose(ImportPick.sheet) : null,
        ),
        const SizedBox(height: 10),
        QButton(label: context.t('Get the template'), icon: Icons.download_outlined, kind: QButtonKind.quiet, loading: _working == 'template', onPressed: _working == null ? _template : null),
        const SizedBox(height: 16),
        Text(
          context.t('A balance with a number of instalments and a first due date becomes a contract with its plan, and what was paid becomes one payment dated today. A balance alone becomes an open account.'),
          style: text.bodySmall?.copyWith(color: c.inkMuted),
        ),
        if (ref.watch(importOptionsProvider).value case final options? when options.aiAvailable) ...[
          const SizedBox(height: 24),
          _reading(context, options),
        ],
      ],
    );
  }

  /// A notebook page or another app's PDF report, read by Claude into the same trial run (decision D8). Shown once the
  /// key is saved; on a plan without it, as part of Pro.
  Widget _reading(BuildContext context, ImportOptions options) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final reading = _working == ImportPick.camera.name || _working == ImportPick.photoOrPdf.name;

    return QCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.auto_awesome_outlined, size: 20, color: c.accent),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  options.aiIncluded
                      ? context.t('A photo of a notebook page, or the PDF reports from your old app, are read for you into the same rows. You check every row before anything is saved.')
                      : context.t('Part of Pro: a photo of a notebook page, or the PDF reports from your old app, read for you into the same rows.'),
                  style: text.bodyMedium,
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          if (options.aiIncluded) ...[
            QButton(
              label: context.t('Take a photo of the notebook'),
              icon: Icons.photo_camera_outlined,
              loading: _working == ImportPick.camera.name,
              onPressed: _working == null ? () => _choose(ImportPick.camera) : null,
            ),
            const SizedBox(height: 10),
            QButton(
              label: context.t('Choose a photo or PDF'),
              icon: Icons.picture_as_pdf_outlined,
              kind: QButtonKind.quiet,
              loading: _working == ImportPick.photoOrPdf.name,
              onPressed: _working == null ? () => _choose(ImportPick.photoOrPdf) : null,
            ),
            const SizedBox(height: 10),
            if (reading)
              Text(context.t('Reading the page. This can take up to a minute.'), style: text.bodySmall?.copyWith(color: c.inkMuted))
            else if (options.pagesLeft != null)
              Text(context.t('Pages left this month: :count', {'count': '${options.pagesLeft}'}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
          ] else
            QButton(label: context.t('See Pro'), icon: Icons.workspace_premium_outlined, kind: QButtonKind.quiet, onPressed: () => context.push('/plans')),
        ],
      ),
    );
  }

  Widget _trial(BuildContext context, ImportJob job) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final summary = job.summary;
    final currency = ref.watch(accountProvider)?.currency ?? '';

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: [
        Text(job.filename, style: text.titleMedium, maxLines: 1, overflow: TextOverflow.ellipsis),
        const SizedBox(height: 2),
        Text(job.isDone ? context.t('Brought in') : context.t('Trial run: nothing is saved yet'), style: text.bodySmall?.copyWith(color: c.inkMuted)),
        const SizedBox(height: 12),
        if (job.isDone) ...[
          QNotice(context.t('Brought in. New customers: :customers. Contracts: :contracts.', {'customers': '${summary.customers}', 'contracts': '${summary.contracts}'}), icon: Icons.check_circle_outline),
          const SizedBox(height: 12),
        ],
        if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 12)],
        QCard(
          child: Row(
            children: [
              Expanded(child: _Figure(label: job.isDone ? context.t('Brought in') : context.t('Ready to bring in'), value: '${summary.ready}', note: context.t('Rows read: :count', {'count': '${summary.rows}'}))),
              Expanded(child: _Figure(label: context.t('New customers'), value: '${summary.customers}', note: context.t('Contracts: :count', {'count': '${summary.contracts}'}))),
              Expanded(child: _Figure(label: context.t('Balances brought in'), value: summary.balance.format(currency), note: context.t('Rows with a problem: :count', {'count': '${summary.problems}'}), late: summary.problems > 0)),
            ],
          ),
        ),
        if (!job.isDone) ...[
          QSectionTitle(context.t('Columns')),
          QCard(
            child: Column(
              children: [
                for (final column in job.columns)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Expanded(child: Text(column.header.isEmpty ? context.t('Column :number', {'number': '${column.index + 1}'}) : column.header, style: text.bodyMedium, maxLines: 2, overflow: TextOverflow.ellipsis)),
                        const SizedBox(width: 12),
                        Expanded(
                          child: DropdownButton<String?>(
                            key: ValueKey('import-column-${column.index}-${column.field}'),
                            value: column.field,
                            isExpanded: true,
                            underline: const SizedBox.shrink(),
                            items: [
                              DropdownMenuItem<String?>(child: Text(context.t('Leave out'), overflow: TextOverflow.ellipsis)),
                              for (final MapEntry(key: key, value: label) in job.fields.entries) DropdownMenuItem<String?>(value: key, child: Text(label, overflow: TextOverflow.ellipsis)),
                            ],
                            onChanged: _working != null ? null : (field) => _remap(column.index, field),
                          ),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        ],
        QSectionTitle(context.t('Rows')),
        for (final row in job.rows)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: _RowCard(row: row, currency: currency),
          ),
      ],
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({required this.label, required this.value, required this.note, this.late = false});

  final String label;
  final String value;
  final String note;
  final bool late;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: text.labelSmall?.copyWith(color: c.inkMuted), maxLines: 2),
        const SizedBox(height: 4),
        FittedBox(fit: BoxFit.scaleDown, alignment: AlignmentDirectional.centerStart, child: Text(value, style: text.titleLarge?.copyWith(fontWeight: FontWeight.w700))),
        const SizedBox(height: 2),
        Text(note, style: text.labelSmall?.copyWith(color: late ? c.danger : c.inkMuted), maxLines: 2),
      ],
    );
  }
}

class _RowCard extends ConsumerWidget {
  const _RowCard({required this.row, required this.currency});

  final ImportRow row;
  final String currency;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = ref.watch(localeProvider);
    final (label, tone) = row.problems.isNotEmpty
        ? (context.t('Not brought in'), c.danger)
        : switch (row.action) {
            'existing' => (context.t('Added to a customer you have'), c.accent),
            'skip' => (context.t('Nothing to add'), c.inkMuted),
            _ => (context.t('New customer'), c.positive),
          };

    return QCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Text(context.t('Row :number', {'number': '${row.row}'}), style: text.labelSmall?.copyWith(color: c.inkMuted)),
              const Spacer(),
              Text(label, style: text.labelMedium?.copyWith(color: tone, fontWeight: FontWeight.w600)),
            ],
          ),
          const SizedBox(height: 4),
          Text(row.name ?? '—', style: text.titleSmall),
          if (row.phone != null) Directionality(textDirection: TextDirection.ltr, child: Text(row.phone!, style: text.bodySmall?.copyWith(color: c.inkMuted))),
          if (row.contractType == 'open' && row.balance != null) ...[
            const SizedBox(height: 6),
            Text(context.t('Open account, owing :amount', {'amount': row.balance!.format(currency)}), style: text.bodyMedium),
          ] else if (row.contractType == 'scheduled') ...[
            const SizedBox(height: 6),
            Text(context.t('Instalments: :count, the first on :date', {'count': '${row.installments}', 'date': formatDay(row.firstDue ?? '', language)}), style: text.bodyMedium),
            Text(context.t('Paid :paid, still owed :owed', {'paid': (row.paid ?? .zero).format(currency), 'owed': (row.balance ?? .zero).format(currency)}), style: text.bodySmall?.copyWith(color: c.inkMuted)),
          ],
          for (final problem in row.problems) ...[
            const SizedBox(height: 4),
            Text('${problem.column}: ${problem.message}', style: text.bodySmall?.copyWith(color: c.danger)),
          ],
          if (row.problems.isEmpty && row.note != null) ...[
            const SizedBox(height: 4),
            Text(row.note!, style: text.bodySmall?.copyWith(color: c.inkMuted)),
          ],
        ],
      ),
    );
  }
}
