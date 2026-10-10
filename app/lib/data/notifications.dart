import 'package:flutter/foundation.dart';

import '../core/money.dart';

/// One entry in the inbox (Win Plan PP9): the morning summary or an instalment alert, as the push said it.
@immutable
class AppNotification {
  const AppNotification({required this.id, required this.type, required this.title, required this.body, required this.read, required this.createdAt, this.route});

  factory AppNotification.fromJson(Map<String, dynamic> json) {
    final data = json['data'];

    return AppNotification(
      id: json['id'] as String,
      type: json['type'] as String? ?? '',
      title: json['title'] as String? ?? '',
      body: json['body'] as String? ?? '',
      read: json['read'] == true,
      createdAt: DateTime.parse(json['created_at'] as String),
      route: data is Map ? data['route'] as String? : null,
    );
  }

  final String id;

  /// daily_digest | instalment_due | instalment_late
  final String type;
  final String title;
  final String body;
  final bool read;
  final DateTime createdAt;

  /// Where tapping it leads in the app.
  final String? route;
}

@immutable
class InboxPage {
  const InboxPage({required this.items, required this.unread});

  factory InboxPage.fromJson(Map<String, dynamic> json) => InboxPage(
        items: [for (final item in (json['data'] as List<dynamic>? ?? const [])) AppNotification.fromJson(item as Map<String, dynamic>)],
        unread: ((json['meta'] as Map<String, dynamic>? ?? const {})['unread'] as num?)?.toInt() ?? 0,
      );

  final List<AppNotification> items;
  final int unread;
}

/// What a person wants to be told, and when (Win Plan PP9). Times are "HH:MM" on the workspace's clock.
@immutable
class AlertPreferences {
  const AlertPreferences({
    this.digest = true,
    this.digestTime = '09:00',
    this.due = false,
    this.late = true,
    this.lateRepeat = 'weekly',
    this.quiet = false,
    this.quietFrom = '22:00',
    this.quietTo = '08:00',
  });

  factory AlertPreferences.fromJson(Map<String, dynamic> json) {
    final data = json['data'] as Map<String, dynamic>? ?? json;
    Map<String, dynamic> part(String key) => data[key] as Map<String, dynamic>? ?? const {};

    return AlertPreferences(
      digest: part('daily_digest')['enabled'] as bool? ?? true,
      digestTime: part('daily_digest')['time'] as String? ?? '09:00',
      due: part('instalment_due')['enabled'] as bool? ?? false,
      late: part('instalment_late')['enabled'] as bool? ?? true,
      lateRepeat: part('instalment_late')['repeat'] as String? ?? 'weekly',
      quiet: part('quiet_hours')['enabled'] as bool? ?? false,
      quietFrom: part('quiet_hours')['from'] as String? ?? '22:00',
      quietTo: part('quiet_hours')['to'] as String? ?? '08:00',
    );
  }

  final bool digest;
  final String digestTime;
  final bool due;
  final bool late;

  /// daily | weekly | monthly
  final String lateRepeat;
  final bool quiet;
  final String quietFrom;
  final String quietTo;

  Map<String, dynamic> toJson() => {
        'daily_digest': {'enabled': digest, 'time': digestTime},
        'instalment_due': {'enabled': due},
        'instalment_late': {'enabled': late, 'repeat': lateRepeat},
        'quiet_hours': {'enabled': quiet, 'from': quietFrom, 'to': quietTo},
      };

  AlertPreferences copyWith({bool? digest, String? digestTime, bool? due, bool? late, String? lateRepeat, bool? quiet, String? quietFrom, String? quietTo}) =>
      AlertPreferences(
        digest: digest ?? this.digest,
        digestTime: digestTime ?? this.digestTime,
        due: due ?? this.due,
        late: late ?? this.late,
        lateRepeat: lateRepeat ?? this.lateRepeat,
        quiet: quiet ?? this.quiet,
        quietFrom: quietFrom ?? this.quietFrom,
        quietTo: quietTo ?? this.quietTo,
      );
}

/// One customer on the remind-all checklist, with the message already written by the server.
@immutable
class DueReminder {
  const DueReminder({
    required this.installmentId,
    required this.contractId,
    required this.reference,
    required this.customerName,
    required this.amount,
    required this.dueDate,
    required this.message,
    this.whatsapp,
    this.daysLate,
  });

  factory DueReminder.fromJson(Map<String, dynamic> json) => DueReminder(
        installmentId: json['installment_id'] as String,
        contractId: json['contract_id'] as String,
        reference: json['contract_reference'] as String? ?? '',
        customerName: json['customer_name'] as String? ?? '',
        amount: Money.parse((json['amount_due'] ?? '0').toString()),
        dueDate: json['due_date'] as String? ?? '',
        message: json['message'] as String? ?? '',
        whatsapp: json['whatsapp'] as String?,
        daysLate: (json['days_late'] as num?)?.toInt(),
      );

  final String installmentId;
  final String contractId;
  final String reference;
  final String customerName;
  final Money amount;
  final String dueDate;
  final String message;

  /// Digits with the country code, as wa.me wants; null when the number is too short to be one.
  final String? whatsapp;
  final int? daysLate;
}

/// The business's wording of one reminder in one language.
@immutable
class ReminderWording {
  const ReminderWording({required this.key, required this.language, required this.body, required this.defaultBody, required this.custom});

  factory ReminderWording.fromJson(Map<String, dynamic> json) => ReminderWording(
        key: json['key'] as String,
        language: json['language'] as String,
        body: json['body'] as String? ?? '',
        defaultBody: json['default_body'] as String? ?? '',
        custom: json['custom'] == true,
      );

  /// reminder_due | reminder_late
  final String key;
  final String language;
  final String body;
  final String defaultBody;
  final bool custom;
}
