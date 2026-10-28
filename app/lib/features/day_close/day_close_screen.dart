import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/providers.dart';
import '../../core/api/api_exception.dart';
import '../../core/design/luxe.dart';
import '../../core/design/tokens.dart';
import '../../core/design/widgets.dart';
import '../../core/l10n/formats.dart';
import '../../core/l10n/translations.dart';
import '../../core/money.dart';
import '../../core/ui/errors.dart';
import '../../data/day_close.dart';
import '../../data/models.dart';
import '../../data/qistas_api.dart' show newIdempotencyKey;
import '../billing/upgrade_sheet.dart';
import '../payments/record_payment_sheet.dart' show methodLabel;
import 'day_close_state.dart';

/// The end of the day (daily-habit wave A, H4): the cash to count, the other payments and what is worth a look, then the
/// sign-off. Open, it is a calm evening ritual: count the till on the number pad, read the difference in plain words, and
/// close. Closed, it is the seal, the sentence to share and (for an owner or a manager) the way to reopen.
///
/// Every figure arrives from the server and is only shown. The difference under the number pad is a hint worked out in
/// whole cents; the server works the real one out again when the day is closed.
class DayCloseScreen extends ConsumerStatefulWidget {
  const DayCloseScreen({super.key});

  @override
  ConsumerState<DayCloseScreen> createState() => _DayCloseScreenState();
}

class _DayCloseScreenState extends ConsumerState<DayCloseScreen> {
  CountedEntry _entry = const CountedEntry();
  final _note = TextEditingController();
  final _cashKey = GlobalKey();

  /// A close is on its way. Set before anything is awaited, so a second tap can never send a second request.
  bool _busy = false;

  // One key per attempt, as for a payment: a retry after a lost answer sends the same key and the server answers with the
  // first close. As soon as what is being closed changes, it is a different attempt and gets a new key.
  String _key = newIdempotencyKey();
  String _keyFor = '';

  String? _countedError;
  String? _dayError;
  String? _noteError;
  String? _problem;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  void _press(String key) {
    if (_busy) return;
    unawaited(HapticFeedback.selectionClick());
    setState(() {
      _entry = _entry.press(key);
      _countedError = null;
      _problem = null;
    });
  }

  void _noCash() {
    if (_busy) return;
    unawaited(HapticFeedback.selectionClick());
    setState(() {
      _entry = CountedEntry.noCash;
      _countedError = null;
      _problem = null;
    });
  }

  Future<void> _refresh() async {
    ref.invalidate(dayCloseTodayProvider);
    await ref.read(dayCloseTodayProvider.future).then<void>((_) {}, onError: (Object _) {});
  }

  void _scrollTo(GlobalKey key) => WidgetsBinding.instance.addPostFrameCallback((_) {
        final target = key.currentContext;
        if (target != null && target.mounted) {
          unawaited(Scrollable.ensureVisible(target, duration: MediaQuery.disableAnimationsOf(target) ? Duration.zero : const Duration(milliseconds: 250), alignment: 0.1));
        }
      });

  void _changed() {
    ref.invalidate(dayCloseTodayProvider);
    ref.invalidate(dayCloseTileProvider);
    ref.invalidate(dayCloseHistoryProvider);
  }

  Future<void> _close(DayCloseToday today) async {
    final counted = _entry.amount;
    if (_busy || counted == null) return;

    final fingerprint = '${counted.toDecimalString()}|${_note.text.trim()}|${today.day}';
    if (fingerprint != _keyFor) {
      _key = newIdempotencyKey();
      _keyFor = fingerprint;
    }
    setState(() {
      _busy = true;
      _countedError = null;
      _dayError = null;
      _noteError = null;
      _problem = null;
    });

    try {
      // The day the person was shown is the day that is closed, even if midnight passed while they counted.
      final close = await ref.read(apiProvider).closeDay(countedCash: counted, idempotencyKey: _key, note: _note.text, day: today.day);
      unawaited(HapticFeedback.mediumImpact());
      _changed();
      if (!mounted) return;
      setState(() {
        _busy = false;
        _entry = const CountedEntry();
        _note.clear();
        // The same figures again (after a reopening) are a new close, not a retry of this one.
        _keyFor = '';
      });
      await showDayClosedMoment(context, close);
    } on UpgradeRequired catch (e) {
      if (!mounted) return;
      setState(() => _busy = false);
      await showUpgradeSheet(context, e);
    } on ApiException catch (e) {
      if (!mounted) return;
      final counted = e.fieldError('counted_cash');
      final day = e.fieldError('day');
      final note = e.fieldError('note');
      setState(() {
        _busy = false;
        _countedError = counted;
        _dayError = day;
        _noteError = note;
        _problem = counted == null && day == null && note == null ? errorMessage(context, e) : null;
      });
      if (counted != null) _scrollTo(_cashKey);
      // The day may have been closed from another phone in the meantime: look again.
      if (day != null) _changed();
    }
  }

  Future<void> _reopen(DayCloseRecord close) async {
    final reopened = await showReopenSheet(context, close);
    if (reopened == null || !mounted) return;

    _changed();
    setState(() {
      _entry = const CountedEntry();
      _keyFor = '';
      _dayError = null;
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('The day is open again. Close it again when the figures are right.'))));
  }

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(accountProvider);
    // Off means off: nothing is asked of the server, and nothing is offered.
    if (!dayCloseOn(account)) return const _NotAvailable();

    final today = ref.watch(dayCloseTodayProvider);
    final data = today.valueOrNull;
    final counting = data != null && !data.closed && data.canClose;

    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('Close the day')),
        actions: [
          IconButton(tooltip: context.t('History'), icon: const Icon(Icons.history_rounded), onPressed: () => context.push('/day-close/history')),
        ],
      ),
      body: today.when(
        skipLoadingOnReload: true,
        loading: () => const _Loading(),
        error: (error, _) => QErrorView(message: errorMessage(context, error), retryLabel: context.t('Try again'), onRetry: () => ref.invalidate(dayCloseTodayProvider)),
        data: (data) => RefreshIndicator(
          onRefresh: _refresh,
          child: SingleChildScrollView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
            child: ContentColumn(
              padding: EdgeInsets.zero,
              child: data.closed && data.close != null ? _closed(context, data, data.close!, account!) : _open(context, data, account!),
            ),
          ),
        ),
      ),
      bottomNavigationBar: counting ? _CloseBar(ready: _entry.amount != null, busy: _busy, problem: _dayError ?? _problem, onClose: () => _close(data)) : null,
    );
  }

  // ------------------------------------------------------------------ the day is open

  Widget _open(BuildContext context, DayCloseToday data, Account account) {
    final currency = account.currency;
    final counting = data.canClose;
    final close = data.close;
    final counted = _entry.amount;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (close != null && close.isReopened) ...[
          const SizedBox(height: 16),
          QNotice(
            '${context.t('This day was reopened. Close it again when the figures are right.')}${(close.reopenReason ?? '').isEmpty ? '' : '\n${context.t('Why')}: ${close.reopenReason}'}',
            tone: QTone.info,
            icon: Icons.lock_open_rounded,
          ),
        ],
        if (!counting) ...[
          const SizedBox(height: 16),
          QNotice(context.t('You can see the day’s figures. Only people who record payments can close it.'), tone: QTone.info, icon: Icons.visibility_outlined),
        ],
        QSectionTitle(context.t('Cash')),
        QCard(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t('Expected cash'), style: Theme.of(context).textTheme.labelLarge?.copyWith(color: context.qc.inkMuted)),
              const SizedBox(height: 4),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: AlignmentDirectional.centerStart,
                  child: CountUpMoney(data.expectedCash, currency, style: Theme.of(context).textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w700), color: context.qc.ink),
                ),
              ),
              if (data.nothingCollected) ...[
                const SizedBox(height: 4),
                Text(context.t('Nothing collected yet today.'), style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.qc.inkMuted)),
              ],
              if (counting) ...[
                const SizedBox(height: 16),
                Divider(height: 1, color: context.qc.line),
                const SizedBox(height: 16),
                Text(context.t('Count the notes and coins in the till and enter the total.'), style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.qc.inkMuted)),
                const SizedBox(height: 14),
                KeyedSubtree(
                  key: _cashKey,
                  child: _CountedDisplay(entry: _entry, currency: currency, error: _countedError),
                ),
                if (counted != null) ...[
                  const SizedBox(height: 12),
                  _DifferenceHint(difference: counted - data.expectedCash, currency: currency),
                ],
                const SizedBox(height: 16),
                _NumberPad(onKey: _press),
                const SizedBox(height: 10),
                QButton(label: context.t('No cash today'), kind: QButtonKind.quiet, onPressed: _busy ? null : _noCash),
              ],
            ],
          ),
        ),
        _OtherPayments(data: data, currency: currency),
        _WorthALook(data: data, currency: currency),
        if (counting) ...[
          QSectionTitle(context.t('Sign off')),
          QCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                QField(controller: _note, label: context.t('Note (optional)'), maxLines: 2, maxLength: 1000, enabled: !_busy, errorText: _noteError),
                const SizedBox(height: 12),
                Text(
                  context.t('Closing keeps the figures as they are now. An owner or a manager can reopen the day.'),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: context.qc.inkMuted),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }

  // ---------------------------------------------------------------- the day is closed

  Widget _closed(BuildContext context, DayCloseToday data, DayCloseRecord close, Account account) {
    final currency = account.currency;
    final language = ref.watch(localeProvider);
    final text = Theme.of(context).textTheme;
    final c = context.qc;
    final difference = close.difference;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 20),
        if (_dayError != null) ...[QNotice(_dayError!, icon: Icons.error_outline), const SizedBox(height: 16)],
        Center(child: DaySeal(key: const ValueKey('day-seal'), size: 112, label: context.t('Closed'))),
        const SizedBox(height: 14),
        Text(context.t('The day is closed.'), style: text.headlineSmall, textAlign: TextAlign.center),
        const SizedBox(height: 2),
        Text(formatDay(close.day, language), style: text.bodyMedium?.copyWith(color: c.inkMuted), textAlign: TextAlign.center),
        QSectionTitle(context.t('The day in one line')),
        QCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // The sentence is the server's, in the language of the request, shown as it came.
              SelectableText(close.summaryText, style: text.bodyLarge),
              const SizedBox(height: 14),
              QButton(label: context.t('Share'), icon: Icons.ios_share_rounded, kind: QButtonKind.gold, onPressed: () => ref.read(textSharerProvider)(close.summaryText, context.t('Day close'))),
            ],
          ),
        ),
        QSectionTitle(context.t('Cash')),
        QCard(
          child: Column(
            children: [
              _FactRow(label: context.t('Expected cash'), child: MoneyText(close.expectedCash, currency, style: text.titleSmall)),
              _FactRow(label: context.t('Counted cash'), child: MoneyText(close.countedCash, currency, style: text.titleSmall)),
              _FactRow(
                label: context.t('Difference'),
                child: Text(differenceWords(context, difference, currency), style: text.titleSmall?.copyWith(color: _standingColor(c, standingOf(difference))), textAlign: TextAlign.end),
              ),
              _FactRow(label: context.t('Closed by'), child: Text(close.closedBy ?? context.t('Someone no longer on the team'), style: text.titleSmall, textAlign: TextAlign.end)),
              if (close.closedAt != null) _FactRow(label: context.t('Closed at'), child: Directionality(textDirection: TextDirection.ltr, child: Text(formatMomentLong(close.closedAt!, language), style: text.titleSmall))),
              if ((close.note ?? '').isNotEmpty) _FactRow(label: context.t('Note'), child: Text(close.note!, style: text.titleSmall, textAlign: TextAlign.end)),
            ],
          ),
        ),
        _OtherPayments(data: data, currency: currency),
        _WorthALook(data: data, currency: currency),
        if (data.canReopen) ...[
          const SizedBox(height: 24),
          QButton(label: context.t('Reopen this day'), kind: QButtonKind.quiet, icon: Icons.lock_open_rounded, onPressed: () => _reopen(close)),
        ],
      ],
    );
  }
}

/// What a person sees while the figures load.
class _Loading extends StatelessWidget {
  const _Loading();

  @override
  Widget build(BuildContext context) => const Padding(
        padding: EdgeInsets.fromLTRB(16, 20, 16, 0),
        child: Column(children: [QSkeleton(height: 330), SizedBox(height: 18), QSkeleton(height: 110), SizedBox(height: 18), QSkeleton(height: 90)]),
      );
}

/// A push or a link that reaches the screen while the business has the feature off.
class _NotAvailable extends StatelessWidget {
  const _NotAvailable();

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('Close the day'))),
        body: QEmpty(icon: Icons.event_busy_outlined, title: context.t('Closing the day is not switched on for this business.'), message: ''),
      );
}

Color _standingColor(QistasColors c, CashStanding standing) => switch (standing) {
      CashStanding.balanced => c.positive,
      CashStanding.short => c.danger,
      CashStanding.over => c.warning,
    };

// ============================================================================================================= the money

/// The counted cash as it is typed: the currency, then the amount in large, steady figures. A refusal from the server is
/// written under it.
class _CountedDisplay extends StatelessWidget {
  const _CountedDisplay({required this.entry, required this.currency, required this.error});

  final CountedEntry entry;
  final String currency;
  final String? error;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final empty = entry.isEmpty;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(context.t('Counted cash'), style: text.labelLarge),
        const SizedBox(height: 6),
        Semantics(
          container: true,
          excludeSemantics: true,
          label: context.t('Counted cash'),
          value: empty ? '' : '$currency ${entry.display}',
          child: Container(
            key: const ValueKey('counted-amount'),
            constraints: const BoxConstraints(minHeight: 64),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            decoration: BoxDecoration(
              color: c.surfaceAlt.withValues(alpha: 0.6),
              borderRadius: BorderRadius.circular(QistasMetrics.radiusMd),
              border: Border.all(color: error == null ? c.line : c.danger, width: error == null ? 1 : 1.5),
            ),
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Row(
                children: [
                  Text(currency, style: text.titleMedium?.copyWith(color: c.inkMuted)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      alignment: Alignment.centerRight,
                      child: Text(
                        empty ? '0.00' : entry.display,
                        // The sans face, whose figures are all one height and width: what is typed reads like a calculator's.
                        style: text.titleMedium?.copyWith(fontSize: 34, height: 1.2, fontWeight: FontWeight.w700, color: empty ? c.inkMuted.withValues(alpha: 0.5) : c.ink, fontFeatures: const [FontFeature.tabularFigures()]),
                        maxLines: 1,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        if (error != null) ...[
          const SizedBox(height: 6),
          Semantics(liveRegion: true, child: Text(error!, key: const ValueKey('counted-error'), style: text.bodySmall?.copyWith(color: c.danger))),
        ],
      ],
    );
  }
}

/// How the counted cash stands against the expected, in the server's own words: balanced, short by, over by. It is a hint.
class _DifferenceHint extends StatelessWidget {
  const _DifferenceHint({required this.difference, required this.currency});

  final Money difference;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final standing = standingOf(difference);
    final (Color background, Color foreground, IconData icon) = switch (standing) {
      CashStanding.balanced => (c.tintMint, c.positive, Icons.check_circle_outline_rounded),
      CashStanding.short => (c.tintBlush, c.danger, Icons.remove_circle_outline_rounded),
      CashStanding.over => (c.tintSand, c.warning, Icons.add_circle_outline_rounded),
    };

    return Container(
      key: const ValueKey('difference-hint'),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd)),
      child: Row(
        children: [
          Icon(icon, color: foreground, size: 22),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(context.t('Difference'), style: text.labelSmall?.copyWith(color: foreground)),
                Text(differenceWords(context, difference, currency), style: text.titleSmall?.copyWith(color: foreground)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

// ======================================================================================================== the number pad

/// Digits, a decimal point and delete, laid out as on every phone's own pad (never mirrored, in any language). Every key
/// is at least 48 dp each way and says what it is to a screen reader.
class _NumberPad extends StatelessWidget {
  const _NumberPad({required this.onKey});

  final ValueChanged<String> onKey;

  @override
  Widget build(BuildContext context) {
    Widget row(List<String> keys) => Row(
          children: [
            for (final (index, key) in keys.indexed) ...[
              if (index > 0) const SizedBox(width: 10),
              Expanded(child: _PadKey(padKey: key, onKey: onKey)),
            ],
          ],
        );

    return Directionality(
      textDirection: TextDirection.ltr,
      child: Column(
        children: [
          row(const ['1', '2', '3']),
          const SizedBox(height: 10),
          row(const ['4', '5', '6']),
          const SizedBox(height: 10),
          row(const ['7', '8', '9']),
          const SizedBox(height: 10),
          row(const ['.', '0', '<']),
        ],
      ),
    );
  }
}

class _PadKey extends StatelessWidget {
  _PadKey({required this.padKey, required this.onKey}) : super(key: ValueKey('pad-${_names[padKey] ?? padKey}'));

  static const _names = {'.': 'point', '<': 'delete'};

  final String padKey;
  final ValueChanged<String> onKey;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final label = switch (padKey) {
      '.' => context.t('Decimal point'),
      '<' => context.t('Delete the last digit'),
      _ => padKey,
    };

    return Semantics(
      button: true,
      label: label,
      excludeSemantics: true,
      onTap: () => onKey(padKey),
      child: Material(
        color: padKey == '<' ? c.surface : c.surfaceAlt,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(QistasMetrics.radiusMd), side: BorderSide(color: c.line.withValues(alpha: 0.8))),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => onKey(padKey),
          child: SizedBox(
            height: 60,
            child: Center(
              child: FittedBox(
                fit: BoxFit.scaleDown,
                child: padKey == '<'
                    ? Icon(Icons.backspace_outlined, color: c.ink, size: 24)
                    : Text(padKey, style: text.titleMedium?.copyWith(fontSize: 26, height: 1.2, fontWeight: FontWeight.w600, color: c.ink, fontFeatures: const [FontFeature.tabularFigures()])),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ====================================================================================================== the sticky button

/// The sign-off at the foot of the screen: always in reach, enabled once an amount is entered, and one tap sends one close.
/// A refusal that is about the day itself, or no connection, is written right above the button that was pressed.
class _CloseBar extends StatelessWidget {
  const _CloseBar({required this.ready, required this.busy, required this.problem, required this.onClose});

  final bool ready;
  final bool busy;
  final String? problem;
  final VoidCallback onClose;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;

    return Material(
      color: c.surface,
      elevation: 8,
      shadowColor: c.ink.withValues(alpha: 0.2),
      child: SafeArea(
        top: false,
        // As tall as its content and no taller: a bar that asked for the whole height would cover the screen it belongs to.
        child: Align(
          heightFactor: 1,
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: QistasMetrics.contentWidth),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (problem != null) ...[QNotice(problem!, icon: Icons.error_outline, key: const ValueKey('close-problem')), const SizedBox(height: 10)],
                  QButton(key: const ValueKey('close-day-button'), label: context.t('Close the day'), icon: Icons.check_rounded, loading: busy, onPressed: ready ? onClose : null),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ================================================================================================= the lists of the day

/// The day's card, transfer, cheque and other lines, as the server listed them. A void is its own negative line.
class _OtherPayments extends StatelessWidget {
  const _OtherPayments({required this.data, required this.currency});

  final DayCloseToday data;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Other payments')),
        QCard(
          padding: data.nonCash.isEmpty ? const EdgeInsets.all(18) : EdgeInsets.zero,
          child: data.nonCash.isEmpty
              ? Text(context.t('No other payments recorded for this day.'), style: text.bodyMedium?.copyWith(color: c.inkMuted))
              : Column(
                  children: [
                    for (final (index, line) in data.nonCash.indexed) ...[
                      if (index > 0) Divider(height: 1, color: c.line),
                      _LineRow(
                        title: line.customer,
                        subtitle: Text.rich(
                          TextSpan(children: [
                            line.isVoid
                                ? TextSpan(text: context.t('Payment voided'), style: TextStyle(color: c.danger))
                                : TextSpan(text: methodLabel(context, line.method)),
                            if (line.note != null) TextSpan(text: ' · ${line.note}'),
                          ]),
                          style: text.bodySmall?.copyWith(color: c.inkMuted),
                        ),
                        amount: MoneyText(line.amount, currency, style: text.titleSmall),
                      ),
                    ],
                  ],
                ),
        ),
      ],
    );
  }
}

/// What is worth a look today: voided payments, waived fees, back-dated payments, contracts opened and cancelled.
class _WorthALook extends StatelessWidget {
  const _WorthALook({required this.data, required this.currency});

  final DayCloseToday data;
  final String currency;

  String _kind(BuildContext context, String kind) => switch (kind) {
        'void' => context.t('Payment voided'),
        'waived_fee' => context.t('Late fee waived'),
        'back_dated' => context.t('Back-dated payment'),
        'opened' => context.t('Contract opened'),
        'cancelled' => context.t('Contract cancelled'),
        _ => kind,
      };

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        QSectionTitle(context.t('Worth a look')),
        QCard(
          padding: data.watch.isEmpty ? const EdgeInsets.all(18) : EdgeInsets.zero,
          child: data.watch.isEmpty
              ? Text(context.t('Nothing out of the ordinary on this day.'), style: text.bodyMedium?.copyWith(color: c.inkMuted))
              : Column(
                  children: [
                    for (final (index, item) in data.watch.indexed) ...[
                      if (index > 0) Divider(height: 1, color: c.line),
                      _LineRow(
                        title: item.label,
                        subtitle: Text(_kind(context, item.kind), style: text.bodySmall?.copyWith(color: c.inkMuted)),
                        amount: item.amount == null ? null : MoneyText(item.amount!, currency, style: text.titleSmall),
                      ),
                    ],
                  ],
                ),
        ),
      ],
    );
  }
}

class _LineRow extends StatelessWidget {
  const _LineRow({required this.title, required this.subtitle, this.amount});

  final String title;
  final Widget subtitle;
  final Widget? amount;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: Theme.of(context).textTheme.titleSmall, maxLines: 2, overflow: TextOverflow.ellipsis),
                  const SizedBox(height: 2),
                  subtitle,
                ],
              ),
            ),
            if (amount != null) ...[const SizedBox(width: 8), EndAmount(child: amount!)],
          ],
        ),
      );
}

/// A label and its value on one line, the value ending the line; under large text they stack rather than overflow.
class _FactRow extends StatelessWidget {
  const _FactRow({required this.label, required this.child});

  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(flex: 4, child: Text(label, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: context.qc.inkMuted))),
            const SizedBox(width: 12),
            Expanded(flex: 5, child: Align(alignment: AlignmentDirectional.centerEnd, child: child)),
          ],
        ),
      );
}

// ==================================================================================================================== seal

/// The seal on a closed day: a scalloped gold edge round a ring and a tick. It settles into place once, and keeps still
/// for a person who asked their phone for less motion.
class DaySeal extends StatelessWidget {
  const DaySeal({super.key, this.size = 96, this.label});

  final double size;

  /// What a screen reader says it is.
  final String? label;

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final quiet = MediaQuery.disableAnimationsOf(context);

    return Semantics(
      label: label,
      image: label != null,
      excludeSemantics: label != null,
      child: TweenAnimationBuilder<double>(
        tween: Tween(begin: quiet ? 1 : 0, end: 1),
        duration: quiet ? Duration.zero : const Duration(milliseconds: 650),
        curve: Curves.easeOutBack,
        builder: (context, progress, child) => Opacity(opacity: progress.clamp(0, 1), child: Transform.scale(scale: 0.6 + 0.4 * progress, child: child)),
        child: SizedBox(
          width: size,
          height: size,
          child: CustomPaint(
            painter: _SealPainter(fill: c.tintSand, ring: c.accent),
            child: Center(child: Icon(Icons.check_rounded, size: size * 0.4, color: c.accentText)),
          ),
        ),
      ),
    );
  }
}

class _SealPainter extends CustomPainter {
  const _SealPainter({required this.fill, required this.ring});

  final Color fill;
  final Color ring;

  @override
  void paint(Canvas canvas, Size size) {
    final centre = size.center(Offset.zero);
    final radius = size.shortestSide / 2;
    const lobes = 16;
    const steps = 360;

    // The edge swells and dips sixteen times round the circle, like pressed wax.
    final edge = Path();
    for (var step = 0; step <= steps; step++) {
      final angle = 2 * math.pi * step / steps;
      final reach = radius * (0.95 + 0.05 * math.cos(lobes * angle));
      final point = centre + Offset(math.cos(angle), math.sin(angle)) * reach;
      step == 0 ? edge.moveTo(point.dx, point.dy) : edge.lineTo(point.dx, point.dy);
    }
    edge.close();

    canvas.drawPath(edge, Paint()..color = fill);
    canvas.drawPath(edge, Paint()..style = PaintingStyle.stroke..strokeWidth = 2..color = ring);
    canvas.drawCircle(centre, radius * 0.74, Paint()..style = PaintingStyle.stroke..strokeWidth = 1.5..color = ring.withValues(alpha: 0.75));
  }

  @override
  bool shouldRepaint(_SealPainter old) => old.fill != fill || old.ring != ring;
}

// ====================================================================================================== after the close

/// The moment after the day is closed: the seal, how the cash stood, the sentence to share, and the share sheet one tap away.
Future<void> showDayClosedMoment(BuildContext context, DayCloseRecord close) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (context) => DayClosedSheet(close: close),
    );

class DayClosedSheet extends ConsumerWidget {
  const DayClosedSheet({super.key, required this.close});

  final DayCloseRecord close;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final currency = ref.watch(accountProvider)?.currency ?? '';
    final standing = standingOf(close.difference);

    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(child: DaySeal(key: const ValueKey('closed-seal'), size: 104, label: context.t('Closed'))),
            const SizedBox(height: 16),
            Text(context.t('The day is closed.'), style: text.headlineSmall, textAlign: TextAlign.center),
            const SizedBox(height: 6),
            Text(differenceWords(context, close.difference, currency), style: text.titleMedium?.copyWith(color: _standingColor(c, standing), fontWeight: FontWeight.w600), textAlign: TextAlign.center),
            const SizedBox(height: 18),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: c.tintSand, borderRadius: BorderRadius.circular(QistasMetrics.radiusMd)),
              // The sentence is the server's, in the language of the request, shown as it came.
              child: SelectableText(close.summaryText, key: const ValueKey('closed-sentence'), style: text.bodyMedium),
            ),
            const SizedBox(height: 20),
            QButton(label: context.t('Share'), icon: Icons.ios_share_rounded, kind: QButtonKind.gold, onPressed: () => ref.read(textSharerProvider)(close.summaryText, context.t('Day close'))),
            const SizedBox(height: 8),
            QButton(label: context.t('Done'), kind: QButtonKind.text, onPressed: () => Navigator.of(context).pop()),
          ],
        ),
      ),
    );
  }
}

// ========================================================================================================== reopening

/// Asks why a closed day should be opened again. The server says if the reason is too short; its words are shown under
/// the field. Answers with the close as it now stands, or null when the person backed out.
Future<DayCloseRecord?> showReopenSheet(BuildContext context, DayCloseRecord close) => showModalBottomSheet<DayCloseRecord>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (context) => ReopenSheet(close: close),
    );

class ReopenSheet extends ConsumerStatefulWidget {
  const ReopenSheet({super.key, required this.close});

  final DayCloseRecord close;

  @override
  ConsumerState<ReopenSheet> createState() => _ReopenSheetState();
}

class _ReopenSheetState extends ConsumerState<ReopenSheet> {
  final _reason = TextEditingController();
  bool _busy = false;
  String? _reasonError;
  String? _problem;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _reasonError = null;
      _problem = null;
    });

    try {
      final reopened = await ref.read(apiProvider).reopenDay(widget.close.id, _reason.text);
      if (mounted) Navigator.of(context).pop(reopened);
    } on UpgradeRequired catch (e) {
      if (!mounted) return;
      setState(() => _busy = false);
      await showUpgradeSheet(context, e);
    } on ApiException catch (e) {
      if (!mounted) return;
      final reason = e.fieldError('reason');
      final day = e.fieldError('day');
      setState(() {
        _busy = false;
        _reasonError = reason;
        _problem = day ?? (reason == null ? errorMessage(context, e) : null);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: ContentColumn(
          padding: EdgeInsets.zero,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(context.t('Reopen this day'), style: text.headlineSmall),
              const SizedBox(height: 6),
              Text(
                context.t('The close stays on record as it was signed. The day opens again so it can be corrected, then closed once more.'),
                style: text.bodyMedium?.copyWith(color: c.inkMuted),
              ),
              const SizedBox(height: 18),
              if (_problem != null) ...[QNotice(_problem!, icon: Icons.error_outline), const SizedBox(height: 14)],
              QField(
                controller: _reason,
                label: context.t('Reason'),
                helper: context.t('Kept in the audit log.'),
                errorText: _reasonError,
                maxLines: 3,
                maxLength: 500,
                enabled: !_busy,
                autofocus: true,
              ),
              const SizedBox(height: 20),
              QButton(label: context.t('Reopen the day'), kind: QButtonKind.danger, loading: _busy, onPressed: _submit),
              const SizedBox(height: 4),
              QButton(label: context.t('Cancel'), kind: QButtonKind.text, onPressed: _busy ? null : () => Navigator.of(context).pop()),
            ],
          ),
        ),
      ),
    );
  }
}
