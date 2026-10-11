import 'package:flutter/foundation.dart';

/// How to reach the Qistas team (Win Plan PP17): the support WhatsApp number (digits only), the workspace's support code
/// and the reply promise in hours.
@immutable
class SupportInfo {
  const SupportInfo({required this.code, required this.replyHours, required this.missingDataHours, this.whatsapp});

  factory SupportInfo.fromJson(Map<String, dynamic> json) => SupportInfo(
        whatsapp: json['whatsapp'] as String?,
        code: (json['code'] ?? '').toString(),
        replyHours: (json['reply_hours'] as num?)?.toInt() ?? 24,
        missingDataHours: (json['missing_data_hours'] as num?)?.toInt() ?? 4,
      );

  final String? whatsapp;
  final String code;
  final int replyHours;
  final int missingDataHours;
}

@immutable
class SupportReply {
  const SupportReply({required this.body, required this.createdAt});

  factory SupportReply.fromJson(Map<String, dynamic> json) =>
      SupportReply(body: (json['body'] ?? '').toString(), createdAt: DateTime.tryParse((json['created_at'] ?? '').toString()) ?? DateTime.now());

  final String body;
  final DateTime createdAt;
}

/// A question or problem the person sent, with the team's replies.
@immutable
class SupportTicket {
  const SupportTicket({required this.id, required this.category, required this.message, required this.status, required this.createdAt, required this.replies});

  factory SupportTicket.fromJson(Map<String, dynamic> json) => SupportTicket(
        id: (json['id'] ?? '').toString(),
        category: (json['category'] ?? 'question').toString(),
        message: (json['message'] ?? '').toString(),
        status: (json['status'] ?? 'open').toString(),
        createdAt: DateTime.tryParse((json['created_at'] ?? '').toString()) ?? DateTime.now(),
        replies: [for (final item in (json['replies'] as List?) ?? const []) SupportReply.fromJson((item as Map).cast<String, dynamic>())],
      );

  final String id;

  /// question, problem, missing_data, billing or idea.
  final String category;
  final String message;

  /// open, answered or closed.
  final String status;
  final DateTime createdAt;
  final List<SupportReply> replies;
}

/// What is new in one app version, in the reader's language (or English).
@immutable
class ReleaseNote {
  const ReleaseNote({required this.version, required this.body});

  factory ReleaseNote.fromJson(Map<String, dynamic> json) => ReleaseNote(version: (json['version'] ?? '').toString(), body: (json['body'] ?? '').toString());

  final String version;
  final String body;
}
