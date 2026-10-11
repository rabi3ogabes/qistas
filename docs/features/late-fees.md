# Late fees (Win Plan K4, CT20)

A late fee is its own line on the contract, never added to an instalment. It is charged for each period an instalment
is late, after the contract's grace days, and it is paid, or waived with a reason, like any other line.

## The policy

**Settings → Instalment tools** (the web's `/app/settings/tools`, and the app's tools screen) once the **Late fees**
switch is on for the workspace:

- **Late fee**: an amount, or a percent of what is still owed on the instalment when the fee is charged;
- **Fee amount or percent**: zero charges nothing. Amounts can be typed with Arabic or Urdu digits;
- **How often it is charged**: once, every day, every week or every month it is late, from the day after the grace days;
- **Most fees per instalment** and **Most fees per contract**: zero means no cap. Waived fees count towards a cap, so
  a waiver is never charged again; the fee that reaches a cap is cut down to fit it;
- **Fees go to charity**: kept apart from the business's own income in every total and in the export.

Each scheduled contract keeps a copy of the policy as it stood **when the contract was made**
(`contracts.late_fee_policy`). Changing the policy later never changes an old deal. A contract made while the switch
was off, or while the fee was zero, has no fees. A cash sale never has fees. An old deal typed in today is never
charged for the days before it was entered.

## Charging

`qistas:charge-late-fees` runs every five minutes and charges each workspace once a day, soon after its own midnight
(the workspace's time zone). Each instalment's fee for a period is written once (unique instalment and period). A run
after days were missed catches them up; running it again does nothing. Nothing is charged on a paid instalment, one
set aside by a plan change or an early settlement, an open contract or a cancelled one, nor while the switch is off.
Fees already charged stay when the switch goes off: they can still be paid or waived.

## Paying

A payment pays, in this order: what is already due (the oldest instalment first), then the open late fees (oldest
first), then the instalments not yet due. A payment chosen for one instalment fills that one first. What is owed, on
the contract, the customer and the dashboard, includes open fees; open fees count as late. The contract is settled
only once every instalment and every fee is paid or waived.

- **Voiding a payment** puts back exactly what it paid on fees (a fee waived since stays waived).
- **An early settlement** adds the open fees to the payoff whole: the discount comes off the profit on instalments,
  never off a fee.
- **Investors** are credited only what went to instalments. Fees are the business's, or charity's.
- **Making a contract open** waits until its fees are collected or waived.

## Waiving

On the contract page (web and app), an owner or a manager waives an open fee and must say why. What was already paid
on it stays paid. The waiver is audited (`late_fee.waived`, with the amount forgiven and the reason) and shown on the
fee with who did it and when. API: `POST /api/v1/late-fees/{fee}/waive` with `reason`.

## Where fees show

- the contract (web and app): the policy in a sentence, each fee with its instalment, day, amount, what was paid and
  its status; the "still owed" figure says how much of it is fees;
- the payment preview: what goes to fees;
- the statement: each fee as a line on the day its period began, and its waiver;
- the export: a **Late fees** sheet, charity marked;
- `App\Domain\LateFees\LateFeeSummary`: charged, collected, waived and open over a period, the business's apart from
  charity's (the reports page in Sprint 2 Task 3 shows it).

**What you set up:** switch **Late fees** on in `/admin/features` when the owner's lawyer has approved the wording
and the practice (decision D1–D3). Then each workspace owner sets their own policy.
