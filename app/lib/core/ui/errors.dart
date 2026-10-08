import 'package:flutter/widgets.dart';

import '../api/api_exception.dart';
import '../l10n/translations.dart';

/// What to tell a person about a failure, in their language. The server's own message is used when it sent one
/// (it is translated there); a network failure and anything unexpected get a plain sentence.
String errorMessage(BuildContext context, Object error) {
  if (error is ApiException) {
    if (error.isNetwork) return context.t('No connection. Check your internet and try again.');
    // A server fault is told in our own words, except when the server named the situation itself
    // (the demo being full is a 503 with a message written for the reader).
    if (error.status >= 500 && (error.code == 'server_error' || error.code.startsWith('http_') || error.message.isEmpty)) {
      return context.t('Something went wrong on our side. Please try again.');
    }
    if (error.message.isNotEmpty) return error.message;
  }

  return context.t('Something went wrong. Please try again.');
}
