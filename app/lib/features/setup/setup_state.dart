import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/l10n/translations.dart';
import '../../data/models.dart';

// The first-run card on the phone (go-live basics): who is shown it, what saving it does, and where a refused document
// request leads. The server decides everything that matters (it refuses a country that may no longer change); nothing here
// does more than ask and show.

/// Whether Today shows the card to this person: they own the business, and the server says it has not been named yet. A
/// server that does not know the card sends no `setup`, and there is nothing to show.
bool needsSetupCard(Account? account) => account != null && account.isOwner && account.setup?.confirmed == false;

/// Whether Today shows this person the quiet line instead: the business has not been named, and it is not theirs to name.
bool needsSetupNotice(Account? account) => account != null && !account.isOwner && account.setup?.confirmed == false;

/// What the card is showing about the last save: working, or what the server (or the line) said was wrong.
@immutable
class SetupForm {
  const SetupForm({this.busy = false, this.nameError, this.countryError, this.failure});

  /// A save is on its way: nothing more is sent until it is answered.
  final bool busy;

  /// The server's own sentence about the name, or about the country (a refused change of region); shown next to the field.
  final String? nameError;
  final String? countryError;

  /// Anything else that went wrong (no connection, a refusal, a fault), for the card to word.
  final Object? failure;
}

/// Saves the card. Lives for the whole session, so an answer that comes after the card has gone is still applied.
class SetupController extends Notifier<SetupForm> {
  @override
  SetupForm build() {
    // Whatever the last save complained about belongs to the person who made it: another person signing in starts clean.
    ref.watch(accountProvider.select((account) => account?.userId));

    return const SetupForm();
  }

  /// Sends the name and, only if the person changed it, the country (never a time zone: the server works that out from the
  /// country). Returns whether it was saved. A second call while one is on its way does nothing, so a double tap sends once.
  Future<bool> save({required String name, String? country}) async {
    if (state.busy) return false;
    state = const SetupForm(busy: true);

    try {
      final tenant = await ref.read(apiProvider).confirmSetup(name: name, country: country);
      // The account takes the answer at once, and the card, which only shows for an unnamed business, folds away.
      await ref.read(authProvider.notifier).tenantChanged(tenant);
      state = const SetupForm();

      return true;
    } on ApiException catch (e) {
      final aboutName = e.fieldError('name');
      final aboutCountry = e.fieldError('country');
      state = e.isValidation && (aboutName != null || aboutCountry != null) ? SetupForm(nameError: aboutName, countryError: aboutCountry) : SetupForm(failure: e);

      return false;
    } on Object catch (e) {
      // An answer the app cannot read must not leave the card working for ever.
      state = SetupForm(failure: e);

      return false;
    }
  }

  /// Forgets what the last save complained about, once the person starts putting it right.
  void forgetComplaints() {
    if (!state.busy && (state.nameError != null || state.countryError != null || state.failure != null)) state = const SetupForm();
  }
}

final setupControllerProvider = NotifierProvider<SetupController, SetupForm>(SetupController.new);

/// A document was asked for and the server answered 409 `workspace_setup_required` (see `isSetupRequired`): none is made
/// until the owner has named the business.
///
/// The owner is taken to Today, where the card is, with a short message ([leave] first closes whatever they were in: a
/// sheet, say), and null comes back. Anyone else stays where they are and gets the sentence to show there: whose job it is.
/// The account is asked for again on the way, so the card is there even if the phone had not yet heard the business was
/// unnamed.
String? answerSetupRequired(BuildContext context, WidgetRef ref, {VoidCallback? leave}) {
  final account = ref.read(accountProvider);
  if (account == null || !account.isOwner) return context.t('Your business owner needs to name the business before documents can be printed.');

  final router = GoRouter.of(context);
  final messenger = ScaffoldMessenger.of(context);
  final message = context.t('Name your business first. It is printed on every receipt.');

  unawaited(ref.read(authProvider.notifier).refresh());
  leave?.call();
  router.go('/');
  messenger
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));

  return null;
}
