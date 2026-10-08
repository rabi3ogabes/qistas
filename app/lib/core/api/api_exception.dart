/// Something the API refused or something that stopped it from answering. [code] never changes and is for the
/// program; [message] is for a person and comes translated from the server.
class ApiException implements Exception {
  const ApiException({
    required this.status,
    required this.code,
    required this.message,
    this.fields = const {},
    this.retryAfter,
  });

  /// No answer at all: offline, a timeout, a server that is down.
  const ApiException.network([this.message = 'No connection'])
      : status = 0,
        code = 'network',
        fields = const {},
        retryAfter = null;

  final int status;
  final String code;
  final String message;

  /// For a 422: what is wrong with which field.
  final Map<String, List<String>> fields;

  /// For a 429: seconds to wait.
  final int? retryAfter;

  bool get isNetwork => status == 0;

  bool get isValidation => code == 'validation_failed';

  bool get isNotFound => status == 404;

  /// The first thing wrong with [field], if the server named it.
  String? fieldError(String field) {
    final messages = fields[field];

    return messages == null || messages.isEmpty ? null : messages.first;
  }

  @override
  String toString() => 'ApiException($status $code: $message)';
}

/// HTTP 402: the plan does not allow that. The app must always answer this with the upgrade sheet, never with a
/// generic error: it says what was hit, how much is used and where to go.
class UpgradeRequired extends ApiException {
  const UpgradeRequired({
    required super.code,
    required super.message,
    required this.feature,
    this.limit,
    this.used,
    this.upgradeUrl,
  }) : super(status: 402);

  /// The feature key, such as customers or active_contracts.
  final String feature;
  final int? limit;
  final int? used;
  final String? upgradeUrl;

  /// The plan does not include the feature at all (rather than being full).
  bool get isLocked => code == 'feature_locked';
}
