# Hijri dates (Win Plan CT26)

A workspace can show the Umm al-Qura (Hijri) date with every date, and type or pick dates in Hijri. What is stored and
sent is always the Gregorian day: Hijri is only how dates are shown and read.

## The choice

**Settings → Instalment tools → Hijri dates**, once the **Hijri dates** switch is on in `/admin/features`:

- **Gregorian only** (the default, so nothing changes until the owner chooses);
- **Hijri beside the Gregorian date**: "1 Feb 2026 (13 Shaban 1447 AH)";
- **Hijri first**: "13 Shaban 1447 AH (1 Feb 2026)".

Switching the feature off brings every workspace back to Gregorian only; each owner's choice is kept for when it is
switched back on. The app reads the choice from the account (`tenant.hijri_dates` in `GET /api/v1/me`) and reads the
account again the moment the owner changes it.

## Where it shows

- **Web:** the contract page (schedule, payments, late fees, terms, plan changes), the contracts and payments lists,
  the customer page, the customer-payment page, investors, reminders and the dashboard's date. In table cells the
  other calendar sits on a second, quieter line.
- **PDFs:** contract and customer statements, receipts, the transactions report and the investor report, with the
  Hijri day in brackets.
- **App:** every date the app formats (`formatDay`, `formatMoment`, the greeting's date).

The contract form's live schedule preview is drawn in the browser and shows Gregorian dates only.

## Typing and picking a Hijri date

- **Web:** under the contract date, the first instalment's date and the payment date there is an **Or the Hijri date**
  field. Typing a day there (13/08/1447 or 1447-08-13, in Western, Arabic or Persian digits) fills the date field with
  its Gregorian day at once, so the schedule preview follows. Picking a Gregorian day shows its Hijri day under the
  field. Without scripts the server reads the Hijri field the same way, and a Hijri day that does not exist is an
  error on that field.
- **App:** every date picker (contract dates, payment date, plan changes, investor entries, the calculator) opens a
  sheet with **Hijri** and **Gregorian** tabs, on the calendar the workspace leads with. With Hijri dates off it is the
  phone's own date picker, as before.

## One calendar everywhere

The Umm al-Qura table for 1356–1500 AH (1937–2077) is generated once from ICU by
`php shared/tools/generate_hijri_vectors.php`, which writes:

- `shared/hijri-vectors.json`: the month lengths and 103 sample days (every month's first and last day for 1446–1449,
  both ends of the table, leap days) with their wording in all five languages;
- `web/app/Support/HijriTable.php` and `app/lib/core/l10n/hijri_table.dart`: the same table for PHP and Dart.

The website (`App\Support\Hijri`), the PDFs, the web form's script (`resources/js/hijri.js`, handed the same table) and
the app (`lib/core/l10n/hijri.dart`) all read that table, so they agree to the day whatever ICU version a server runs.
Both the PHP and the Dart tests check every sample and walk every day of the table. Outside the table there is no Hijri
date, and the Gregorian one is shown alone.

The month names are part of the vectors: `web/lang/app/*.json` and `hijri.dart` must say exactly what the generator
says. To change a name, change it in the generator, regenerate, and update both.

**What you set up:** switch **Hijri dates** on in `/admin/features`. Each owner then chooses on the tools page.
