import 'package:flutter/foundation.dart';

/// A business's books as one file (Win Plan PP10): a nightly copy or a download someone asked for.
@immutable
class WorkspaceExport {
  const WorkspaceExport({required this.id, required this.kind, required this.format, required this.status, required this.size, required this.rowCounts, required this.createdAt});

  factory WorkspaceExport.fromJson(Map<String, dynamic> json) => WorkspaceExport(
        id: json['id'] as String,
        kind: json['kind'] as String? ?? 'manual',
        format: json['format'] as String? ?? 'xlsx',
        status: json['status'] as String? ?? 'ready',
        size: (json['size'] as num?)?.toInt() ?? 0,
        rowCounts: {for (final entry in (json['row_counts'] as Map<String, dynamic>? ?? const {}).entries) entry.key: (entry.value as num).toInt()},
        createdAt: DateTime.parse(json['created_at'] as String),
      );

  final String id;

  /// nightly | manual
  final String kind;

  /// xlsx | csv
  final String format;

  /// pending | ready | failed
  final String status;
  final int size;
  final Map<String, int> rowCounts;
  final DateTime createdAt;

  bool get isReady => status == 'ready';
}

/// When the books were last copied, and the copies kept.
@immutable
class BackupsOverview {
  const BackupsOverview({required this.lastBackupAt, required this.copies, required this.kept});

  factory BackupsOverview.fromJson(Map<String, dynamic> json) {
    final data = json['data'] as Map<String, dynamic>? ?? const {};
    final meta = json['meta'] as Map<String, dynamic>? ?? const {};
    final last = data['last_backup_at'] as String?;

    return BackupsOverview(
      lastBackupAt: last == null ? null : DateTime.parse(last),
      copies: [for (final copy in (data['copies'] as List<dynamic>? ?? const [])) WorkspaceExport.fromJson(copy as Map<String, dynamic>)],
      kept: (meta['kept'] as num?)?.toInt() ?? 7,
    );
  }

  final DateTime? lastBackupAt;
  final List<WorkspaceExport> copies;
  final int kept;
}

/// One line of the activity log: what happened, by whom, when.
@immutable
class ActivityEntry {
  const ActivityEntry({required this.id, required this.at, required this.kind, required this.summary, this.person});

  factory ActivityEntry.fromJson(Map<String, dynamic> json) => ActivityEntry(
        id: json['id'] as String,
        at: DateTime.parse(json['at'] as String),
        kind: json['kind'] as String?,
        summary: json['summary'] as String? ?? '',
        person: (json['person'] as Map<String, dynamic>?)?['name'] as String?,
      );

  final String id;
  final DateTime at;
  final String? kind;
  final String summary;
  final String? person;
}

/// A page of the activity log, with the people to filter by.
@immutable
class ActivityPage {
  const ActivityPage({required this.entries, required this.currentPage, required this.lastPage, required this.people});

  factory ActivityPage.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>? ?? const {};

    return ActivityPage(
      entries: [for (final entry in (json['data'] as List<dynamic>? ?? const [])) ActivityEntry.fromJson(entry as Map<String, dynamic>)],
      currentPage: (meta['current_page'] as num?)?.toInt() ?? 1,
      lastPage: (meta['last_page'] as num?)?.toInt() ?? 1,
      people: [
        for (final person in (meta['people'] as List<dynamic>? ?? const []))
          (id: (person as Map<String, dynamic>)['id'] as String, name: person['name'] as String),
      ],
    );
  }

  final List<ActivityEntry> entries;
  final int currentPage;
  final int lastPage;
  final List<({String id, String name})> people;

  bool get hasMore => currentPage < lastPage;
}
