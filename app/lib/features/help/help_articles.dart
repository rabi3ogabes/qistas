import 'package:flutter/widgets.dart';

import '../../core/l10n/translations.dart';

/// One short answer in Help (Win Plan PP17).
class HelpArticle {
  const HelpArticle(this.title, this.body);

  final String title;
  final String body;

  /// Every word of [query] appears in the title or the answer, whatever the case.
  bool matches(String query) {
    final words = query.toLowerCase().split(RegExp(r'\s+')).where((w) => w.isNotEmpty);
    final haystack = '$title $body'.toLowerCase();

    return words.every(haystack.contains);
  }
}

/// The answers, in the person's language. Each sentence is written out here so the translation tool finds it.
List<HelpArticle> helpArticles(BuildContext context) => [
      HelpArticle(
        context.t('Add your first customer'),
        context.t('Tap + and choose New customer. A name and a phone number are enough; the rest can wait. Pick from contacts fills them in for you.'),
      ),
      HelpArticle(
        context.t('Open an instalment contract'),
        context.t('Tap + and choose New contract. Type the price, the down payment, the profit and the number of instalments: the schedule appears as you type, so the customer sees it before you save.'),
      ),
      HelpArticle(
        context.t('Work out a plan without saving it'),
        context.t('Tap + and choose Calculator. It shows each instalment, the total and the last date at once, and Create a contract from this carries the numbers into a new contract.'),
      ),
      HelpArticle(
        context.t('Record a payment'),
        context.t('Tap + and choose Record a payment, or open the contract and record it there. A payment pays the oldest instalment first, and the receipt is ready to share.'),
      ),
      HelpArticle(
        context.t('Undo a payment recorded by mistake'),
        context.t('Open the contract, tap the payment and choose Void. Nothing is deleted: a reversal line is added, and the instalments it paid are owed again.'),
      ),
      HelpArticle(
        context.t('See who is late and remind them'),
        context.t('The dashboard lists who pays today and who is late. Remind opens WhatsApp with a message in your customer’s language, and Remind everyone goes through the list one tap at a time.'),
      ),
      HelpArticle(
        context.t('Settle a contract early'),
        context.t('Open the contract and tap Settle early. Choose a discount on the profit not yet earned, and the payoff is shown before anything is saved.'),
      ),
      HelpArticle(
        context.t('Change a payment plan'),
        context.t('Open the contract and tap Change the payment plan to spread what is left again, skip a month or move one date. Paid instalments never move, and the total stays the same.'),
      ),
      HelpArticle(
        context.t('Invite your team'),
        context.t('In Settings, open Team and invite a partner, an accountant or a collector by e-mail. Each one sees only what their role allows.'),
      ),
      HelpArticle(
        context.t('Keep a copy of your books'),
        context.t('In Settings, open Backups and data. A copy is made every night, and you can download everything in Excel at any time.'),
      ),
      HelpArticle(
        context.t('Lock the app'),
        context.t('In Settings, turn on App lock: the app asks for your fingerprint or screen lock each time it opens.'),
      ),
      HelpArticle(
        context.t('Move to Pro'),
        context.t('In Settings, tap Upgrade to Pro to see the plans. Pro follows your workspace to every phone, and Restore purchases brings it to a new one.'),
      ),
      HelpArticle(
        context.t('Delete my account'),
        context.t('In Settings, choose Delete my account. Your workspace is kept for 30 days in case you change your mind, then deleted for good.'),
      ),
    ];
