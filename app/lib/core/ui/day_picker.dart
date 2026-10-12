import 'package:flutter/material.dart';

import '../design/tokens.dart';
import '../design/widgets.dart';
import '../l10n/formats.dart';
import '../l10n/hijri.dart';
import '../l10n/translations.dart';

/// Picks a day (Win Plan CT26). While the workspace shows the Gregorian calendar only, this is the phone's own date
/// picker, as before. With Hijri dates on, a sheet offers both calendars and opens on the one the workspace leads with;
/// either way the day returned is the Gregorian one, which is what the server stores.
Future<DateTime?> pickDay(BuildContext context, {required DateTime initial, required DateTime first, required DateTime last, required String language}) {
  final start = initial.isBefore(first) ? first : (initial.isAfter(last) ? last : initial);
  if (!HijriDisplay.on) {
    return showDatePicker(context: context, initialDate: start, firstDate: first, lastDate: last, locale: Locale(language));
  }

  return showModalBottomSheet<DateTime>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    showDragHandle: true,
    builder: (_) => _DayPickerSheet(initial: start, first: first, last: last, language: language),
  );
}

class _DayPickerSheet extends StatefulWidget {
  const _DayPickerSheet({required this.initial, required this.first, required this.last, required this.language});

  final DateTime initial;
  final DateTime first;
  final DateTime last;
  final String language;

  @override
  State<_DayPickerSheet> createState() => _DayPickerSheetState();
}

class _DayPickerSheetState extends State<_DayPickerSheet> {
  late DateTime _day = DateTime(widget.initial.year, widget.initial.month, widget.initial.day);
  late bool _hijri = HijriDisplay.mode == 'first' && HijriDate.fromGregorian(_day) != null;

  bool get _inRange => !_day.isBefore(DateTime(widget.first.year, widget.first.month, widget.first.day)) && !_day.isAfter(widget.last);

  /// Moves to the Hijri day chosen, keeping the day within the month (a 30th in a 29-day month becomes the 29th).
  void _setHijri({int? year, int? month, int? day}) {
    final now = HijriDate.fromGregorian(_day);
    if (now == null) return;
    final y = year ?? now.year;
    final m = month ?? now.month;
    final length = HijriDate.monthLength(y, m);
    if (length == null) return;
    final picked = HijriDate.gregorian(y, m, (day ?? now.day).clamp(1, length));
    if (picked != null) setState(() => _day = picked);
  }

  @override
  Widget build(BuildContext context) {
    final c = context.qc;
    final text = Theme.of(context).textTheme;
    final language = widget.language;
    final hijri = HijriDate.fromGregorian(_day);
    final iso = '${_day.year.toString().padLeft(4, '0')}-${_day.month.toString().padLeft(2, '0')}-${_day.day.toString().padLeft(2, '0')}';
    final gregorianWords = westernDigits(MaterialLocalizations.of(context).formatMediumDate(_day));

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(context.t('Choose a day'), style: text.headlineSmall),
          const SizedBox(height: 14),
          SegmentedButton<bool>(
            segments: [
              ButtonSegment(value: true, label: Text(context.t('Hijri')), enabled: hijri != null),
              ButtonSegment(value: false, label: Text(context.t('Gregorian'))),
            ],
            selected: {_hijri},
            showSelectedIcon: false,
            onSelectionChanged: (chosen) => setState(() => _hijri = chosen.first),
          ),
          const SizedBox(height: 16),
          if (_hijri && hijri != null)
            Row(
              children: [
                Expanded(
                  flex: 2,
                  child: KeyedSubtree(
                    key: const ValueKey('hijri-day'),
                    child: DropdownButtonFormField<int>(
                    key: ValueKey('day-$iso'),
                    isExpanded: true,
                    initialValue: hijri.day,
                    decoration: InputDecoration(labelText: context.t('Day')),
                    items: [for (var d = 1; d <= HijriDate.monthLength(hijri.year, hijri.month)!; d++) DropdownMenuItem(value: d, child: Text('$d'))],
                    onChanged: (d) => _setHijri(day: d),
                  ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 4,
                  child: KeyedSubtree(
                    key: const ValueKey('hijri-month'),
                    child: DropdownButtonFormField<int>(
                    key: ValueKey('month-$iso'),
                    initialValue: hijri.month,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: context.t('Month')),
                    items: [for (var m = 1; m <= 12; m++) DropdownMenuItem(value: m, child: Text(HijriDate.monthName(m, language), overflow: TextOverflow.ellipsis))],
                    onChanged: (m) => _setHijri(month: m),
                  ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  flex: 3,
                  child: KeyedSubtree(
                    key: const ValueKey('hijri-year'),
                    child: DropdownButtonFormField<int>(
                    key: ValueKey('year-$iso'),
                    isExpanded: true,
                    initialValue: hijri.year,
                    decoration: InputDecoration(labelText: context.t('Year')),
                    items: [
                      for (var y = (HijriDate.fromGregorian(widget.first)?.year ?? hijri.year); y <= (HijriDate.fromGregorian(widget.last)?.year ?? hijri.year); y++)
                        DropdownMenuItem(value: y, child: Text('$y')),
                    ],
                    onChanged: (y) => _setHijri(year: y),
                  ),
                  ),
                ),
              ],
            )
          else
            CalendarDatePicker(
              key: const ValueKey('gregorian'),
              initialDate: _inRange ? _day : widget.first,
              firstDate: widget.first,
              lastDate: widget.last,
              onDateChanged: (d) => setState(() => _day = DateTime(d.year, d.month, d.day)),
            ),
          const SizedBox(height: 12),
          // The other calendar's day, so the person sees what they chose in both.
          Text(
            _hijri ? gregorianWords : (hijri?.format(language) ?? gregorianWords),
            key: const ValueKey('day-other'),
            textAlign: TextAlign.center,
            style: text.titleMedium?.copyWith(color: c.accentText),
          ),
          if (!_inRange) ...[
            const SizedBox(height: 8),
            Text(
              context.t('Choose a day between :first and :last.', {'first': formatMoment(widget.first, language), 'last': formatMoment(widget.last, language)}),
              textAlign: TextAlign.center,
              style: text.bodySmall?.copyWith(color: c.danger),
            ),
          ],
          const SizedBox(height: 16),
          QButton(label: context.t('Choose'), kind: QButtonKind.gold, onPressed: _inRange ? () => Navigator.of(context).pop(_day) : null),
        ],
      ),
    );
  }
}
