# End-of-day close (daily-habit features, H4)

A short evening ritual: someone counts the cash in the till, Qistas sets it against the cash recorded for the day, and the day is
signed off with a one-line summary to share. A closed day is a record that is never edited; a change that would touch it
afterwards is for an owner or a manager, and it leaves a trail.

Design: [`docs/superpowers/specs/2026-10-22-daily-habit-features-design.md`](../superpowers/specs/2026-10-22-daily-habit-features-design.md),
H4 and decision D10. API: `docs/api/openapi.yaml`, tag **Day close**.

## What the team gets

The same ritual on the phone and on the website, on the same server rules.

- **Today (app).** A row under the day's figure: "Closing time is 20:00." before the closing time, a warm gold "It is past
  20:00. Count the till and close the day." after it (the phone's clock against the workspace's closing time), and "The day is
  closed." with a seal once it is. It opens the **Close the day** screen (`/day-close`; a tapped reminder opens the same).
- **The screen.** Three cards. **Cash**: the cash expected, a number pad for the cash counted, and the difference in plain
  words (Balanced, Short by, Over by). **Other payments**: every card, transfer, cheque and other line of the day, one by one,
  with customer and note. **Worth a look**: voided payments, waived fees, payments dated earlier than today, contracts opened
  and contracts cancelled that day (more than ten contracts opened in one day, as after an import, are one line: "300
  contracts opened", with what they were sold for together). Then an optional note and **Close the day**.
- **After closing.** A seal, the sentence ("Al-Fares, 21 Oct: collected SAR 8,420 (cash 3,100, other 5,320), 14 payments,
  difference 0.") with a **Share** button in the app and a **Copy** button on the web, and the history of past closes.
- **On the web.** `Day close` in the menu (people who may close the day only) opens `/app/day-close`: the same cards, a live
  difference as the amount is typed, the day picker ("Close an earlier day", up to seven days back), the history (15 a page),
  the **Reopen** form for owners and managers, and a link to the signed PDF of each close. The dashboard shows a card
  until today is closed (it does not look at the closing time).
- **The numbers.** The day is the workspace's own calendar day (its time zone, not the server's). A payment belongs to the day
  it was **taken** (`paid_at`); a void belongs to the day it was **recorded**, so cash given back today lowers today's expected
  cash and leaves yesterday as it was. Only the ledger's money-in lines count (payments, down payments and their reversals);
  what a customer takes on an open account is owed money, never cash. One payment across a customer's contracts is one
  payment in the count, however many contracts it was spread over.
- **The day of an import.** What an import brings in as already paid ("Paid before Qistas", one line per customer, tagged
  `imported`) was taken before the shop used Qistas and never went through that day's till, so it is left out of every day's
  figures: the cash, the money by way of paying, the count, the per-person totals, the other payments and the sentence. A void
  of one is left out the same way (the void itself is still worth a look). It still pays the customer's instalments and counts
  in the reports and the dashboard as before.
- **The rules of a close.** Today and the seven days before it can be closed. A day is closed once (a double tap or a second
  phone gets "This day is already closed."; the app and the API also send an `Idempotency-Key`, so a retry after a lost answer
  returns the first close). The counted cash is an amount with at most two decimals, not negative. Each close keeps the day's
  figures as they were and a SHA-256 of them (the hash leaves out names, so the same day closed in English or in Arabic is
  signed the same). A reopened day is closed again as the next version.

## The admin switch

`day_close`, in admin → Features, group **Team and operations**. It ships **off**. A switch never deletes anything.

Switched off (for the platform, or because the plan lacks it):

- **No new closes and no reminders**, and the page and the API are hidden: the menu item, the dashboard card and the Today row
  disappear, the app's alert settings and the web's Notifications page no longer offer the two notice kinds (nor does the API's
  `meta.kinds`), and the API answers 403 `feature_unavailable`
  (platform off) or 402 `feature_locked` (plan lacks it).
- **Closed days and their summaries are kept**, and come back when it is switched on again. While it is off they cannot be
  opened through the page or the API.
- **The after-close rule is off too** (see below): while the switch is off a collector can add a back-dated payment to a day
  that was closed. Off means off; the rule returns with the feature.
- The evening reminder is checked when it runs, not when it was queued. The difference notice is sent only by a close, and a
  close is refused while the switch is off.

## Plans

| Plan | `day_close` |
|---|---|
| Free | **on** |
| Pro | **on** |

Those are the code's defaults (`Feature::defaultFor`) until the admin edits the chips under **Included in plans**. The
**summary PDF** is a document like the others and uses the plan's PDF allowance (Free: 5 a month; the same close again within
ten minutes does not count twice). Closing itself costs nothing.

## Rolling it out

All in `/admin/features`, on the **End-of-day close** card (only a super admin can change a switch):

1. **Off** (where it ships). Nothing is visible to anyone.
2. **Beta, for the owner's own workspace.** Choose **Beta**, open **Plans, early access and history**, and under **Early access**
   enter the workspace id or the owner's e-mail, a reason, optionally an end date, and press **Let in**. Beta opens the platform
   switch for that workspace only; the plan must still include the feature (Free and Pro both do). Try the phone checklist below.
3. **On for one friendly shop.** The cockpit has no "on for one shop" button: it is **Beta** with that shop under Early access.
   Take the owner's own entry away with **Take away** if you want, and let the shop in.
4. **On for everyone.** Choose **On**. Every workspace whose plan includes it has it.

Going back is one click (**Off**), or one step down (On to Beta). The card's "Used by N workspaces in the last 30 days" counts
the workspaces that **really closed a day** in that time (a refused close, or a close while the feature is off, counts
nothing); while N is above zero the cockpit asks for a reason before it switches the feature off or down.

## Settings

| Setting | Where | Who changes it | Default |
|---|---|---|---|
| **Closing time** (`day_close.closing_time`) | Settings → Instalment tools (the website's `/app/settings/tools`, the app's tools screen), once the feature is on | Owners and managers | 20:00; a choice of every half hour from 17:00 to 23:30, by the workspace's clock |
| **Evening reminder to close the day** | Alert settings in the app (under "Close the day"); Notifications on the web | Each person, for themselves | On, in the app only |
| **When a day is closed with a cash difference** | the same | Each owner, for themselves | On, in the app only |

The reminder kind is shown to people who may close the day; the difference kind to owners only. Neither is offered, in the
app or on the web, while the feature is off for the workspace (switched off, or not in its plan); a choice made earlier is
kept for when it comes back.

## Who can do what

| | Owner | Manager | Accountant | Collector | Viewer |
|---|---|---|---|---|---|
| Read the day's figures and the history through the API (`GET /day-close/today`, `GET /day-close`) | yes | yes | yes | yes | yes |
| Reach the screen in the app (the Today row, a tapped reminder) | yes | yes | yes | yes | no: no Today row and no reminder |
| Open the web page | yes | yes | yes | yes | no (403, no menu item) |
| Close a day | yes | yes | yes | yes | no |
| Reopen a day, with a reason | yes | yes | no | no | no |
| Make a back-dated payment or a void that touches a closed day | yes, audited | yes, audited | refused | refused | n/a |
| Get the evening reminder | yes | yes | yes | yes | no |
| Get the "closed with a difference" notice | yes, when someone who is not an owner closes | no | no | no | no |
| Fetch a close's summary PDF | yes | yes | yes | yes | yes (see Privacy) |

Reopening needs a reason of 5 to 500 characters. The reason is kept with the close (the API sends it as `reopen_reason`, the
web page shows it), not in the audit row, because free text may name a customer.

## The after-close rule: what it refuses, and what it never refuses

Once a day is closed, a change that touches it is for an owner or a manager (decision D10). Nothing in the ledger is edited:
the rule only decides who may add the line. It is asked before anything is written, by every place that writes money: a payment on
a contract, an early settlement, one payment across a customer's contracts, a void, and the down payment of a contract made with
a past start date.

| What | Owner, manager | Accountant, collector |
|---|---|---|
| A payment **dated earlier than today** on a **closed** day | allowed, audited `day_close.change_after_close` | refused: "That day is closed. Ask the owner or a manager." (on `paid_at`; on `start_date` for a down payment) |
| A **void** of a payment taken on a closed day (today included), **or** any void while **today** is closed (its reversal lands in today's cash) | allowed, audited `day_close.change_after_close` | refused, on `transaction`, with the same sentence |
| A payment **dated now or today**, today **closed** | allowed, audited `day_close.entry_after_close` | **allowed**, audited `day_close.entry_after_close` |
| A payment dated on a day with **no standing close** (never closed, or reopened) | allowed, no row | allowed, no row |

It **never refuses** a payment dated today, whoever records it: a shop keeps selling after it has counted the till, and the owner
sees what came in after the count in the audit trail. It does not guard charges on an open account (owed money, not cash), fees
or waivers. A day that was reopened is open until it is closed again.

## Privacy

- **Per-person cash.** Each close keeps the cash taken by each team member (`per_member`: name, cash, count). It is in the JSON
  (`GET /day-close/today`, the history) for **every role that may read the day, a collector and a viewer included**, and in the
  summary PDF. Neither the app screen nor the web page draws it. Money recorded by someone who has since left the team sits
  under "someone who is no longer on the team".
- **The summary PDF.** It follows the policy's *view* (any role of the workspace), the same visibility the JSON gives; the web
  page, which draws the link, is closed to viewers, the app does not offer the PDF (it is opened on the web or fetched from the
  API). It holds the day, who closed it and when, expected, counted and difference, the money by way of paying and by person,
  and the non-cash payments one by one with the name the payment was taken from. No ID number, phone or address. It carries a QR
  code that opens `/verify/{code}`. If the day's payments changed after the close, the PDF says so.
- **The reminder** says only that it is time to close; **the difference notice** names the day, who closed it and the
  difference, never a customer.
- No AI or other outside service reads the figures; the only outside hop is the push notice itself, through Firebase.

## Scheduled work and notices

| What | When | Does |
|---|---|---|
| `qistas:send-day-close-reminders` | every five minutes (the scheduler workflow) | From each workspace's closing time and for **two hours** on its own clock, never past midnight (a closing time of 22:30, 23:00 or 23:30 gets 90, 60 or 30 minutes), each person who may close the day gets **one** reminder per evening, only while the day is **not closed** and has payments or something worth a look. A day with neither is skipped, and that skip is final for the evening. In a person's quiet hours the reminder is not sent; it can still go out if the quiet hours end inside the window. A workspace that fails is logged and skipped; the others still get theirs. Nothing is sent while the switch is off |
| The difference notice (not a scheduled job) | right after a close is saved | When someone who is not an owner closes with a difference, each owner is told once (key per close and owner). In an owner's **quiet hours** it is written to the inbox with no push (a close happens once, so it is not dropped); an owner who has switched the in-app channel off and takes it by e-mail only gets nothing then, and the difference is still on the day-close page |

Both notices open `/day-close`. See `alerts.md` and `notifications.md` for the channels.

## Audit rows

| Action | Written when | Holds |
|---|---|---|
| `day_close.closed` | a day is closed | day, version, expected, counted, difference |
| `day_close.reopened` | a day is reopened | day, version |
| `day_close.change_after_close` | an owner or manager makes a back-dated payment or a void that touches a closed day | day, change (`payment` or `void`) |
| `day_close.entry_after_close` | a payment dated today is recorded after today was closed | day |

## API routes

Names only; `docs/api/openapi.yaml` is the reference. All sit behind the `day_close` feature.

| Name | Route |
|---|---|
| `day-close.today` | `GET /api/v1/day-close/today` |
| `day-close.index` | `GET /api/v1/day-close` |
| `day-close.store` | `POST /api/v1/day-close` |
| `day-close.reopen` | `POST /api/v1/day-close/{close}/reopen` |
| `day-close.summary` | `GET /api/v1/day-close/{close}/summary.pdf` |
| `app.day-close.show`, `.store`, `.reopen`, `.summary` | the same four on the website, under `/app/day-close` |

## Known limits

- **A payment and a close at the same instant can miss each other.** A close reads the day's figures inside its own transaction,
  but a payment taken at that very moment shares no lock with it: the close can leave the payment out, and nothing is audited. A
  full fix needs a lock every payment shares and is not built. The summary PDF says when the day's payments changed after the
  close.
- **While the switch is off, closed days are not protected** (see Admin switch).
- **Voided cash is counted on the day of the void**, as the voider's own negative line in the per-person totals. A day that only
  gave money back reads "collected SAR -120 ..., 0 payments": accurate, but awkward.
- **The quiet-hours exception is for the difference notice only.** Everything else waits for the quiet hours to end, or, for the
  evening reminder, is not sent.
- **The tile and the web card.** The app's gold emphasis compares the phone's clock with the workspace's closing time (a phone
  in another zone shows it at the wrong hour); the web dashboard card shows all day until the day is closed.
- **The difference notice goes out in the closer's request**, so a slow push provider can delay the answer to the person who
  closed.
- **Payment dates on statements, exports and the contract page** are shown on the UTC calendar, so a payment taken between
  midnight and 03:00 in the Gulf can look a day earlier there. This predates the wave and is not changed by it; the close
  itself uses the workspace's own day.
- **A viewer cannot reach the close by normal navigation, but the data is open to them.** The web page refuses viewers (403)
  and has no menu item for them; the app shows them no Today row and sends them no reminder. The JSON API (`GET
  /day-close/today`, the history) and the summary PDF, though, follow the policy's *view* and answer every role of the
  workspace, a viewer included, per-person cash and all (see Privacy). Limiting those to owners and managers is a one-line
  policy change, the owner's decision.
- **A shop that wants a hard lock** (no changes at all after a close) can have it added; today it is the owner-or-manager rule.

## What the owner must check on a phone

The close is tested end to end in code, but how it feels, and the pushes, need a phone with the Firebase key set up
(`alerts.md`, "Turning pushes on"). With the feature on for your own workspace (Beta):

1. **Today.** The row shows the closing time; set Closing time to a half hour from now (Settings → Instalment tools) and watch it
   turn gold after that time.
2. **Close it.** Record a cash payment and a transfer. Open the screen: the cash expected equals the cash payment, the transfer is
   under Other payments. Type a smaller amount: "Short by ..."; type the right one: "Balanced". Close the day
   with one tap: the sheet shows the sentence; **Share** opens the share sheet with exactly that text (try WhatsApp).
3. **No double close.** Close again from a second phone or the web: "This day is already closed."
4. **Reminder.** At the closing time you get "Time to close the day"; tapping it opens the screen. Close the day and confirm no
   second reminder comes.
5. **Difference notice.** Sign in as a manager or collector on another phone, close with a difference: the owner's phone gets
   "Cash difference at the close". Turn quiet hours on for the owner: the notice sits in the inbox with no sound.
6. **The rule.** As a collector, record a payment dated yesterday on a closed day: refused with "That day is closed. Ask the
   owner or a manager." As the owner, the same works. A payment dated today is accepted after the close.
7. **Reopen.** As the owner, **Reopen this day** with a reason; close it again (version 2). A collector does not see Reopen.
8. **A viewer.** Sign in as a viewer: Today has no close row, no reminder comes at the closing time, and `/app/day-close` on the
   web answers "Forbidden" with no menu item. (The API and the PDF still answer a viewer: see Known limits.)
9. **Arabic.** The seal, the pad (digits), the difference words and the history read right to left; do it once at the largest text size.
10. **The PDF.** On the web, open the PDF of a close and scan its QR code: the verify page opens.
11. **Switch it off** (cockpit): the row, the menu item and the reminders go; switch it on: your closes are still there.
