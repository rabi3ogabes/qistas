import 'package:flutter/foundation.dart';

// One line of the person's own sign-in history (`GET /account/sign-ins`, go-live basics), as the API writes it. The server
// stores the codes (mobile, possible, web); the screen turns them into words. The address of the connection is sent but is
// not kept here, because nothing shows it; the user agent is not sent at all.

/// A word the server wrote, or null when it is empty.
String? _word(Object? value) {
  final text = value?.toString().trim() ?? '';

  return text.isEmpty ? null : text;
}

@immutable
class SignInEvent {
  const SignInEvent({
    required this.id,
    this.channel = 'web',
    this.outcome = 'success',
    this.deviceType = 'unknown',
    this.os,
    this.browser,
    this.appVersion,
    this.deviceName,
    this.ipCountry,
    this.proxy = 'not_detected',
    this.createdAt,
  });

  /// Tolerant: a line with nothing in it is a successful sign-in from an unknown device with no proxy seen, at no known time.
  factory SignInEvent.fromJson(Map<String, dynamic> json) => SignInEvent(
        id: (json['id'] ?? '').toString(),
        channel: _word(json['channel']) ?? 'web',
        outcome: _word(json['outcome']) ?? 'success',
        deviceType: _word(json['device_type']) ?? 'unknown',
        os: _word(json['os']),
        browser: _word(json['browser']),
        appVersion: _word(json['app_version']),
        deviceName: _word(json['device_name']),
        ipCountry: _word(json['ip_country'])?.toUpperCase(),
        proxy: _word(json['proxy']) ?? 'not_detected',
        createdAt: DateTime.tryParse((json['created_at'] ?? '').toString())?.toLocal(),
      );

  final String id;

  /// web or app.
  final String channel;

  /// success or failed (a wrong password).
  final String outcome;

  /// desktop, mobile, tablet or unknown.
  final String deviceType;
  final String? os;
  final String? browser;
  final String? appVersion;

  /// The phone's own name, which the app tells the server when it signs in.
  final String? deviceName;

  /// The country the connection came from, two capital letters; null when the server could not tell.
  final String? ipCountry;

  /// not_detected, possible or detected: the server's verdict on a proxy, never more certain than that.
  final String proxy;

  /// When it happened, in the phone's own time zone; null when the server's moment cannot be read.
  final DateTime? createdAt;

  bool get isApp => channel == 'app';

  bool get failed => outcome == 'failed';
}
