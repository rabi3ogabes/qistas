import 'package:flutter/foundation.dart';

import '../core/money.dart';

List<Map<String, dynamic>> _list(Object? value) => value is List<dynamic> ? value.whereType<Map<String, dynamic>>().toList() : const [];

/// A file of customers being brought in (Win Plan K9): its columns and what each was taken as, every row with what it
/// would do (or did) and its problems by column, and the totals.
@immutable
class ImportJob {
  const ImportJob({
    required this.id,
    required this.status,
    required this.filename,
    required this.columns,
    required this.fields,
    required this.rows,
    required this.summary,
  });

  factory ImportJob.fromJson(Map<String, dynamic> json) => ImportJob(
        id: json['id'] as String,
        status: (json['status'] ?? 'trial').toString(),
        filename: (json['filename'] ?? '').toString(),
        columns: _list(json['columns']).map(ImportColumn.fromJson).toList(),
        fields: {for (final field in _list(json['fields'])) field['key'].toString(): (field['label'] ?? field['key']).toString()},
        rows: _list(json['rows']).map(ImportRow.fromJson).toList(),
        summary: ImportSummary.fromJson(json['summary'] as Map<String, dynamic>? ?? const {}),
      );

  final String id;
  final String status;
  final String filename;
  final List<ImportColumn> columns;

  /// The fields a column can be taken as: key => its name in the app's language.
  final Map<String, String> fields;
  final List<ImportRow> rows;
  final ImportSummary summary;

  bool get isDone => status == 'done';
}

@immutable
class ImportColumn {
  const ImportColumn({required this.index, required this.header, this.field});

  factory ImportColumn.fromJson(Map<String, dynamic> json) =>
      ImportColumn(index: (json['index'] as num?)?.toInt() ?? 0, header: (json['header'] ?? '').toString(), field: json['field'] as String?);

  final int index;
  final String header;
  final String? field;
}

@immutable
class ImportProblem {
  const ImportProblem({required this.column, required this.message});

  factory ImportProblem.fromJson(Map<String, dynamic> json) => ImportProblem(column: (json['column'] ?? '').toString(), message: (json['message'] ?? '').toString());

  final String column;
  final String message;
}

@immutable
class ImportRow {
  const ImportRow({
    required this.row,
    required this.action,
    required this.problems,
    this.name,
    this.phone,
    this.contractType,
    this.total,
    this.paid,
    this.balance,
    this.installments,
    this.firstDue,
    this.note,
  });

  factory ImportRow.fromJson(Map<String, dynamic> json) {
    final contract = json['contract'] as Map<String, dynamic>?;
    Money? money(String key) => contract?[key] == null ? null : Money.parse(contract![key].toString());

    return ImportRow(
      row: (json['row'] as num?)?.toInt() ?? 0,
      name: json['name'] as String?,
      phone: json['phone'] as String?,
      action: (json['action'] ?? 'new').toString(),
      contractType: contract?['type'] as String?,
      total: money('total'),
      paid: money('paid'),
      balance: money('balance'),
      installments: (contract?['installments'] as num?)?.toInt(),
      firstDue: contract?['first_due'] as String?,
      problems: _list(json['problems']).map(ImportProblem.fromJson).toList(),
      note: json['note'] as String?,
    );
  }

  /// The row's number in the sheet (the header is row 1).
  final int row;
  final String? name;
  final String? phone;

  /// new, existing (added to a customer already on the books) or skip.
  final String action;

  /// scheduled, open, or null when the row brings no contract.
  final String? contractType;
  final Money? total;
  final Money? paid;
  final Money? balance;
  final int? installments;
  final String? firstDue;
  final List<ImportProblem> problems;
  final String? note;

  bool get isReady => problems.isEmpty && action != 'skip';
}

@immutable
class ImportSummary {
  const ImportSummary({required this.balance, this.rows = 0, this.ready = 0, this.problems = 0, this.customers = 0, this.contracts = 0});

  factory ImportSummary.fromJson(Map<String, dynamic> json) {
    int count(String key) => (json[key] as num?)?.toInt() ?? 0;

    return ImportSummary(
      rows: count('rows'),
      ready: count('ready'),
      problems: count('problems'),
      customers: count('customers'),
      contracts: count('contracts'),
      balance: Money.parse((json['balance'] ?? '0').toString()),
    );
  }

  final int rows;
  final int ready;
  final int problems;
  final int customers;
  final int contracts;
  final Money balance;
}
