import 'dart:io';

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
import '../../data/backups.dart';
import '../documents/document_options_sheet.dart';

final backupsProvider = FutureProvider.autoDispose<BackupsOverview>((ref) => ref.watch(apiProvider).backups());

/// Win Plan PP10: when the books were last copied, the copies kept, and everything in one file through the share sheet
/// (to the owner's email, a drive, a computer). Owners, managers and accountants, on any plan.
class BackupsScreen extends ConsumerStatefulWidget {
  const BackupsScreen({super.key});

  @override
  ConsumerState<BackupsScreen> createState() => _BackupsScreenState();
}

class _BackupsScreenState extends ConsumerState<BackupsScreen> {
  /// What is being made or downloaded: xlsx, csv, or the id of a copy.
  String? _busy;

  Future<void> _share(String busy, Future<WorkspaceExport> Function() make) async {
    setState(() => _busy = busy);
    final api = ref.read(apiProvider);
    try {
      var export = await make();
      // Usually ready at once; a busy server says "pending" and is asked again for a little while.
      for (var tries = 0; !export.isReady && export.status != 'failed' && tries < 20; tries++) {
        await Future<void>.delayed(const Duration(seconds: 2));
        export = await api.workspaceExport(export.id);
      }
      if (!mounted) return;
      if (!export.isReady) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('The file could not be made. Please try again.'))));
        return;
      }

      final file = await api.downloadExport(export.id);
      final folder = await ref.read(documentFolderProvider)();
      final path = '${folder.path}${Platform.pathSeparator}${file.filename}';
      File(path).writeAsBytesSync(file.bytes, flush: true);
      if (!mounted) return;
      await ref.read(documentSharerProvider)(path, context.t('Backups & data'));
      ref.invalidate(backupsProvider);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessage(context, e))));
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final overview = ref.watch(backupsProvider);
    final language = ref.watch(localeProvider);
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return SectionScaffold(
      title: context.t('Backups & data'),
      showAccount: false,
      body: overview.when(
        loading: () => const Padding(padding: EdgeInsets.all(16), child: QSkeletonList(rows: 4)),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(backupsProvider)),
        data: (data) => RefreshIndicator(
          onRefresh: () => ref.refresh(backupsProvider.future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 40),
            children: [
              QCard(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(shape: BoxShape.circle, color: data.lastBackupAt == null ? c.surfaceAlt : c.tintMint),
                      child: Icon(data.lastBackupAt == null ? Icons.schedule_rounded : Icons.cloud_done_outlined, color: data.lastBackupAt == null ? c.inkMuted : c.positive),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(context.t('Last copy'), style: text.labelLarge?.copyWith(color: c.inkMuted)),
                          const SizedBox(height: 2),
                          Text(data.lastBackupAt == null ? context.t('Tonight') : formatMomentLong(data.lastBackupAt!, language), style: text.titleLarge),
                          const SizedBox(height: 6),
                          Text(
                            data.lastBackupAt == null
                                ? context.t('The first copy is made tonight. After that, one every night, and the last :count are kept, encrypted.', {'count': data.kept})
                                : context.t('A new copy is made every night. The last :count are kept, encrypted.', {'count': data.kept}),
                            style: text.bodySmall?.copyWith(color: c.inkMuted),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              QSectionTitle(context.t('Download everything')),
              QCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(context.t('Customers, contracts, instalments, payments, what was sold and investors, each on its own sheet. Arabic opens correctly in Excel.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)),
                    const SizedBox(height: 14),
                    QButton(label: context.t('Excel file'), icon: Icons.download_rounded, kind: QButtonKind.gold, loading: _busy == 'xlsx', onPressed: _busy == null ? () => _share('xlsx', () => ref.read(apiProvider).createExport('xlsx')) : null),
                    const SizedBox(height: 8),
                    QButton(label: context.t('CSV files (zip)'), icon: Icons.table_chart_outlined, kind: QButtonKind.quiet, loading: _busy == 'csv', onPressed: _busy == null ? () => _share('csv', () => ref.read(apiProvider).createExport('csv')) : null),
                  ],
                ),
              ),
              QSectionTitle(context.t('Copies kept')),
              if (data.copies.isEmpty)
                QCard(child: Text(context.t('Tonight’s copy will appear here.'), style: text.bodyMedium?.copyWith(color: c.inkMuted)))
              else
                QCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      for (final (index, copy) in data.copies.indexed) ...[
                        if (index > 0) const Divider(height: 1),
                        ListTile(
                          leading: Icon(copy.kind == 'nightly' ? Icons.nightlight_outlined : Icons.download_done_rounded, color: c.inkMuted),
                          title: Text(formatMomentLong(copy.createdAt, language)),
                          subtitle: Text(
                            '${copy.kind == 'nightly' ? context.t('Nightly copy') : context.t('Download')} · ${context.t('Customers: :count', {'count': copy.rowCounts['customers'] ?? 0})}',
                            style: text.bodySmall?.copyWith(color: c.inkMuted),
                          ),
                          trailing: _busy == copy.id
                              ? const SizedBox(width: 24, height: 24, child: CircularProgressIndicator(strokeWidth: 2))
                              : IconButton(tooltip: context.t('Download'), icon: const Icon(Icons.ios_share_rounded), onPressed: _busy == null ? () => _share(copy.id, () async => copy) : null),
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
