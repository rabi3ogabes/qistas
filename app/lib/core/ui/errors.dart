import 'package:flutter/widgets.dart';

import '../api/api_exception.dart';
import '../l10n/translations.dart';

/// The server refuses every document (a statement, a receipt, a report) with HTTP 409 and this code while the owner has not
/// named the business: its name is printed on all of them. Not a failure to report; the owner is led to the first-run card.
const String setupRequiredCode = 'workspace_setup_required';

bool isSetupRequired(Object error) => error is ApiException && error.status == 409 && error.code == setupRequiredCode;

/// What to tell a person about a failure, in their language. The server's own message is used when it sent one
/// (it is translated there); a network failure and anything unexpected get a plain sentence.
String errorMessage(BuildContext context, Object error) {
  if (error is ApiException) {
    // Always in our own words, so it reads the same however the server worded it. (Whoever is not the owner is told whose
    // job it is by the screen that asked, which knows the role: see answerSetupRequired.)
    if (isSetupRequired(error)) return context.t('Name your business first. It is printed on every receipt.');
    if (error.isNetwork) return context.t('No connection. Check your internet and try again.');
    // A server fault is told in our own words, except when the server named the situation itself
    // (a 503 with a message written for the reader).
    if (error.status >= 500 && (error.code == 'server_error' || error.code.startsWith('http_') || error.message.isEmpty)) {
      return context.t('Something went wrong on our side. Please try again.');
    }
    if (error.message.isNotEmpty) return error.message;
  }

  return context.t('Something went wrong. Please try again.');
}
