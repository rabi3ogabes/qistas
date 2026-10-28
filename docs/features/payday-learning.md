# Payday learning (daily-habit features, H6)

Qistas notices the day of the month each customer really pays on, from their own payments, and shows it to the team: a line on
the customer, and a date offered (never applied) when a contract or a date is being set. It never changes anything by itself,
and it is internal: the customer is never shown it.

Design: [`docs/superpowers/specs/2026-10-22-daily-habit-features-design.md`](../superpowers/specs/2026-10-22-daily-habit-features-design.md),
H6. API: `docs/api/openapi.yaml`, schemas **PayPattern** and **Customer** (`pay_pattern`).

## What people see

- **A line on the customer** (website customer page, the app's customer page): "Usually pays around day 27 of the month", or
  "Usually pays at the end of the month" for the 28th to the 31st, with a smaller line under it, "Seen in 83% of recent months".
  It is a habit, not an appointment, so no date is shown.
- **A date offered in a new contract** (website and app): under "First instalment due", "Ahmad usually pays around day 27. Use 27
  Dec 2026?" with a **Use it** button, for a scheduled contract (not a cash sale, an open account, or one with its own dates)
  once a customer with a pattern is chosen. Choosing another customer removes it. When the date already is their payday the
  line says "This date is already their payday." and there is no button. The button only fills the date field (the schedule
  preview follows); nothing is saved until the person saves the contract, and typing a date by hand is never overridden.
- **The same offer when moving one date** (the app, "Change the payment plan" → "Move one date"): a payday before today is not
  offered, the next one is. The website's change-plan page has no offer.
- **Which date.** The next time that day comes **on or after the date in the field** (today when the field is empty): day *D* of
  that month, or of the next month when it has passed; for the end of the month, the last day of the month of the date typed. The
  rule is written once in `web/resources/js/pay-day.js` and once in Dart, and both are checked against the same hand-computed
  table, `shared/payday-vectors.json` (59 cases, leap years and 30/31-day months included).
- Not built in this wave: timing reminders for the day before the real payday. That belongs to the Coach (wave B).

## How the pattern is worked out

From a customer's payments, on the workspace's own calendar (`App\Domain\PayPattern`):

1. Only **untagged `payment` lines on monthly, non-open contracts** count. A down payment, an advance, a refund, a payoff in an
   early settlement (it carries the early-discount tag), an "unpaid" mark and what an import brought in as "Paid before Qistas"
   (one lump on the import day, tagged `imported`) say nothing about the payday; a payment that has been voided is left out;
   weekly and other schedules are ignored. A cancelled contract's earlier payments still count.
2. **The first payment of each calendar month** stands for that month, from the last 18 calendar months, and the last 12 months
   that have a payment are used.
3. At least **3 distinct months**. The day is capped at 28 (28 means the end of the month).
4. The **median** day (the lower middle one for an even count), the **spread** (the middle half of the days, nearest-rank), and
   **within**: the percent of months whose day is within 3 of the median.
5. **High** confidence: 4 or more months, spread 3 or less, within 75 or more. **Medium**: spread 5 or less, within 60 or more.
   Anything looser says nothing, so no row is stored (and a stored one is removed).

A median and a spread, not an average, so one late month does not move the answer. The result is kept in
`customer_pay_patterns` (one row a customer: day, spread, months, within, confidence, when it was worked out).

It is kept current in two ways: **after each payment or void** (right after the money is committed, in the same request; a
failure in it is reported and swallowed, so it can never fail or delay a payment), and **nightly** (below).

## The admin switch

`payday_learning`, in admin → Features, group **Collecting money**. It ships **off**. A switch never deletes anything.

Switched off (for the platform, or because the plan lacks it):

- **Suggestions disappear**: the line on the customer, the date offer in the contract form and in the change-plan screen, the
  website's contract form carries nothing, and the API leaves `pay_pattern` out of `GET /customers/{id}`.
- **Patterns stop updating**: a payment or a void registers no recompute, and the nightly command leaves the workspace exactly as
  it is. **Patterns already worked out are kept**, and show again, as they were, when it is switched back on.
- Nothing is deleted, and no other part of the product reads a pattern.

**Switching it on does not fill anything at once.** Nothing was computed while it was off, so a customer's line appears after
their next payment or void, or after the next nightly run (at midnight, server time, UTC). A pattern kept from before the switch
was turned off can be out of date until then.

## Plans

| Plan | `payday_learning` |
|---|---|
| Free | **off** |
| Pro | **on** |

Those are the code's defaults (`Feature::defaultFor`; spec decision D15) until the admin edits the chips under **Included in
plans**. Free is off on purpose: it is part of Pro.

## Rolling it out

All in `/admin/features`, on the **Payday learning** card (only a super admin can change a switch):

1. **Off** (where it ships).
2. **Beta, for the owner's own workspace.** Choose **Beta**, open **Plans, early access and history**, and under **Early access**
   enter the workspace id or the owner's e-mail, a reason, optionally an end date, and press **Let in**. Beta opens the platform
   switch only: **the workspace's plan must still include the feature, and Free does not**. A Free workspace in Beta is
   "plan locked" and sees nothing; use a Pro workspace, or switch the Free chip on under **Included in plans** for the test.
3. **On for one friendly shop.** There is no "on for one shop" button: it is **Beta** with that shop under Early access (a Pro shop).
4. **On for everyone.** Choose **On**. Every Pro workspace has it.

Then wait for a night, or record a payment, before looking for lines (see above).

## Settings

None. There is no setting and no per-person choice; the only control is the admin's switch (and the plan).

## Privacy

- **Only the customer's own payday**: a day of the month worked out from the dates of that customer's own payments. No amount,
  no name list and no other customer's data goes into it; no new personal information is collected.
- **No data leaves.** Nothing is sent to an AI or any outside service, no push or message is made from it, and it is not shown
  to the customer or in any document. It is read by the team: the customer's page and the new-contract screens, and only people
  who may add a contract or move a date get the offer to use it (a viewer sees the line, not the button).
- It is not in the customer list (one query per row is avoided), only on the customer's own page and `GET /customers/{id}`.
- Another workspace's customers never appear: the pattern is read through the customer, which is workspace-scoped, and the
  contract form carries only the patterns of the customers in its own list. A deleted customer shows none.

## Who can do what

| | Owner | Manager | Accountant | Collector | Viewer |
|---|---|---|---|---|---|
| See the line on a customer | yes | yes | yes | yes | yes |
| Be offered the date when adding a contract or moving a date | yes | yes | yes | yes | no |

Nobody configures it.

## Scheduled work

| What | When | Does |
|---|---|---|
| `qistas:learn-paydays` | once a day, at midnight server time (UTC), through the scheduler workflow | Works out again every customer's pattern in each workspace that has the feature on (asked when it runs): stores it, or removes a stored one that no longer qualifies (every payment voided or older than the window, or the customer deleted). Running it again changes only when each pattern was worked out. A workspace that fails is logged (its id and the kind of failure, never a message) and skipped; the others still run. Demo and suspended workspaces are skipped |

## Audit rows

None. The pattern is derived from the ledger and is never an input to it; nothing is written to the audit trail.

## API routes

No new route. `GET /api/v1/customers/{customer}` (`customers.show`) carries `pay_pattern` (`day`, `spread`, `months`, `within`,
`confidence`, `end_of_month`, `label`, `basis`) while the feature is on for the workspace, `null` when there is not enough to say
yet, and nothing when it is off. It is **only** on that call: never in the customer list, and not in what adding, changing or pinning a customer answers (an app
must not clear a stored pattern because an answer lacks the key). `label` and `basis` are written by the server in the language
of the request; show them as sent. On the website the same words come from the same place (`PayPatternWords`).

## Known limits

- **Monthly contracts only.** Weekly, two-weekly and other schedules and open accounts are ignored.
- **A customer who pays twice a year gets no pattern** (3 months in the last 18 are needed), and one who pays in the same
  months does not either until 3 have passed.
- **A freshly imported customer starts with no history.** What the import brought in as "Paid before Qistas" is one lump, not
  the payments it stands for, so it is left out; the line appears once three months of real payments are recorded (or typed
  in with their dates on the payment sheet).
- **It runs inside the payment's request.** The payment is already saved, nothing is locked, and a failure is swallowed; with the
  feature off only the switch is checked. A large import or the demo seeding recomputes once per payment line.
- **The nightly run is best-effort.** It is a daily schedule entry (midnight, UTC), so it runs only if a scheduler call lands in
  that minute; the GitHub workflow that calls `/internal/cron` can be late or skip a call, and then that night's run is missed
  and the next night's does the work. What keeps an active customer current is the update after each payment or void.
- **Two recomputes at once can finish out of order**; the next payment or the nightly run corrects it.
- **A deleted customer's stored row can linger** until the next nightly run; nothing shows it.
- **The cockpit's "Used by N workspaces in the last 30 days" does not count this feature** (only the widget's reads are counted
  today), so its card reads "Not used". Use the customers' lines to see it working.
- **The wording is a percent** ("Seen in 83% of recent months"), not "4 of the last 5 months" as the design sketched.

## What the owner must check on a phone

How it reads and feels needs a phone; the arithmetic is tested against the shared table in both languages. With the feature on
for a **Pro** workspace (Beta, see above):

1. **Make a pattern.** For one customer with a monthly contract, record three payments dated in three different months (the
   payment sheet takes an earlier date), each on about the 27th. The customer's page shows "Usually pays around day 27 of the
   month" and "Seen in 100% of recent months" after the third. (Or wait for the nightly run.)
2. **The offer.** New contract, choose that customer: under "First instalment due", "<name> usually pays around day 27. Use
   <date>?". Tap **Use it**: the date changes and the schedule preview follows. Choose another customer: the offer goes. Pick the
   date by hand: it is left alone.
3. **Move one date.** On a contract of that customer: Change the payment plan, Move one date: the same offer, never a date that
   has passed.
4. **Arabic.** The line and the offer read right to left, with the digits of the date as the rest of the app shows them; try
   once at the largest text size.
5. **Switch it off** (cockpit): the line and the offers go. Switch it on: the line is back.
6. **A viewer** sees the line and no **Use it**.
7. **On the website**, do 1 and 2 once: the customer page and the new-contract form show the same words as the phone.
