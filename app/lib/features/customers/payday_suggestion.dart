import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/providers.dart';
import '../../core/design/tokens.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../data/models.dart';
import '../../domain/payday.dart';

/// Payday learning (daily-habit wave A, H6) on the phone: the day a customer usually pays on, and the offer to use it as a date.
/// The offer is only ever an offer: a date changes when the person taps the button and at no other time.

/// Payday learning is on for this workspace (the platform has it on and the plan has it). With it off the server leaves the
/// pattern out altogether, and a pattern read earlier is not shown either.
bool paydayLearningOn(Account? account) => account?.entitlements['payday_learning']?.isOn ?? false;

/// Whether a screen where a date is typed may offer the customer's usual payday: the feature is on and the person may write.
/// (A viewer cannot reach those screens, and is not offered what they could not use.)
bool offersPaydaySuggestion(Account? account) => paydayLearningOn(account) && (account?.canWrite ?? false);

/// A sentence with its `:name`, `:day` and `:date` filled in, in one pass over the template: a customer called ":day" stays
/// ":day", and the website's script does the same.
String _fill(String template, Map<String, String> values) => template.replaceAllMapped(RegExp(r':(name|day|date)'), (found) => values[found[1]] ?? found[0]!);

/// "Ahmad Salem usually pays around day 27. Use 27 Dec 2026?", or "...This date is already their payday." when the date is
/// already that day. The name is the customer's whole name, and the date is said as the app says its dates.
String paydaySentence(BuildContext context, {required String name, required PayPattern pattern, required DateTime suggested, required bool onPayday, required String language}) {
  final says = _fill(
    pattern.endOfMonth ? context.t(':name usually pays at the end of the month.') : context.t(':name usually pays around day :day.'),
    {'name': name, 'day': '${pattern.day}'},
  );
  final then = onPayday ? context.t('This date is already their payday.') : _fill(context.t('Use :date?'), {'date': _dateWords(suggested, language)});

  return '$says $then';
}

/// A date kept in one piece, so it does not break across two lines of a narrow phone.
String _dateWords(DateTime day, String language) => formatMoment(day, language).replaceAll(RegExp(r'\s'), ' ');

/// The row under a date field: what the customer usually pays on, and a button that sets the date to it.
///
/// [from] is what the field holds now. The row works the date out from it, so changing the field changes only the sentence.
/// Nothing is shown when [from] is not a date, or when there is no date to offer.
class PaydaySuggestion extends ConsumerWidget {
  const PaydaySuggestion({super.key, required this.name, required this.pattern, required this.from, required this.onUse, this.notBefore});

  /// The customer's whole name.
  final String name;
  final PayPattern pattern;
  final DateTime? from;

  /// The earliest day the field can take (a date that is moved cannot go back past today): a payday before it is not offered,
  /// the next one is.
  final DateTime? notBefore;

  /// The person tapped "Use it": set the field to this date. Null while the form cannot take a change (it is saving).
  final ValueChanged<DateTime>? onUse;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final start = from;
    final earliest = start != null && notBefore != null && notBefore!.isAfter(start) ? notBefore : start;
    final suggested = earliest == null ? null : nextPayday(earliest, pattern);
    if (start == null || suggested == null) return const SizedBox.shrink();

    final c = context.qc;
    final language = ref.watch(localeProvider);
    final onPayday = suggested.year == start.year && suggested.month == start.month && suggested.day == start.day;
    final use = onUse;

    return DecoratedBox(
      key: const ValueKey('pay-suggestion'),
      decoration: BoxDecoration(
        color: c.surfaceAlt,
        borderRadius: BorderRadius.circular(QistasMetrics.radiusMd),
        border: Border.all(color: c.line.withValues(alpha: 0.75)),
      ),
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(12, 12, 12, 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(padding: const EdgeInsets.only(top: 2), child: Icon(Icons.event_repeat_outlined, size: 20, color: c.accentText)),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // A live region: the sentence changes when the date does, and a screen reader says what it became.
                  Semantics(
                    liveRegion: true,
                    container: true,
                    child: Text(
                      paydaySentence(context, name: name, pattern: pattern, suggested: suggested, onPayday: onPayday, language: language),
                      key: const ValueKey('pay-suggestion-text'),
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  ),
                  if (!onPayday)
                    Align(
                      alignment: AlignmentDirectional.centerStart,
                      child: Semantics(
                        button: true,
                        enabled: use != null,
                        label: context.t('Use it'),
                        hint: formatMoment(suggested, language),
                        onTap: use == null ? null : () => use(suggested),
                        excludeSemantics: true,
                        child: TextButton(
                          key: const ValueKey('pay-suggestion-use'),
                          style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 4), minimumSize: const Size(QistasMetrics.touchTarget, QistasMetrics.touchTarget)),
                          onPressed: use == null ? null : () => use(suggested),
                          child: Text(context.t('Use it')),
                        ),
                      ),
                    )
                  else
                    const SizedBox(height: 8),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The chip on a customer's own page: the server's words for their usual payday and how often it held, as sent. It names no
/// date: it is a habit, not an appointment.
class PaydayChip extends StatelessWidget {
  const PaydayChip(this.pattern, {super.key});

  final PayPattern pattern;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return MergeSemantics(
      child: DecoratedBox(
        key: const ValueKey('pay-chip'),
        decoration: BoxDecoration(
          color: c.surfaceAlt,
          borderRadius: BorderRadius.circular(QistasMetrics.radiusMd),
          border: Border.all(color: c.line.withValues(alpha: 0.75)),
        ),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
          child: Row(
            children: [
              Icon(Icons.event_repeat_outlined, size: 20, color: c.accentText),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(pattern.label, style: text.titleSmall),
                    if (pattern.basis.trim().isNotEmpty) Text(pattern.basis, style: text.bodySmall?.copyWith(color: c.inkMuted)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
