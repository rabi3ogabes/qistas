#!/usr/bin/env python3
"""
Reference implementation of the Qistas instalment schedule, used ONLY to generate
shared/schedule-vectors.json. It is deliberately independent of the PHP and Dart code
(different language, Decimal arithmetic, its own calendar maths) so that a vector that all
three implementations agree on is real evidence of correctness.

Rules (see docs/superpowers/specs/2026-10-07-qistas-platform-design.md section 5):
  financed  = principal - down_payment
  markup    = fixed: markup_value | percent: round_half_up(financed * markup_value / 100, 2) | none: 0
  total     = financed + markup
  each instalment = floor(total / count) in cents; the LAST instalment absorbs the remainder
  daily +1 day, weekly +7 days, biweekly +14 days; monthly, bimonthly, quarterly, semiannual and yearly step 1, 2, 3, 6
  and 12 months from the FIRST due date, on its day of the month, clamped to the month's end (never carried forward)
  custom: the shop's own rows of (due_date, amount); dates strictly increasing, amounts at least one cent, and the rows
  must add up to the total exactly
  1 to 600 instalments

    python shared/tools/generate_schedule_vectors.py
"""
import calendar
import datetime as dt
import json
import os
from decimal import ROUND_FLOOR, ROUND_HALF_UP, Decimal

TWO = Decimal("0.01")


def q2(v: Decimal) -> Decimal:
    return v.quantize(TWO, rounding=ROUND_HALF_UP)


def add_months(d: dt.date, n: int) -> dt.date:
    m = d.month - 1 + n
    y, m = d.year + m // 12, m % 12 + 1
    return dt.date(y, m, min(d.day, calendar.monthrange(y, m)[1]))


DAYS = {"daily": 1, "weekly": 7, "biweekly": 14}
MONTHS = {"monthly": 1, "bimonthly": 2, "quarterly": 3, "semiannual": 6, "yearly": 12}


def schedule(i: dict) -> dict:
    principal, down = Decimal(i["principal"]), Decimal(i["down_payment"])
    mv = Decimal(i["markup_value"])
    financed = principal - down
    markup = {"none": Decimal("0"), "fixed": mv, "percent": q2(financed * mv / 100)}[i["markup_type"]]
    total = financed + markup
    if i["frequency"] == "custom":
        rows = [{"number": n + 1, "due_date": r["due_date"], "amount": f"{Decimal(r['amount']):.2f}"} for n, r in enumerate(i["custom_schedule"])]
        dates = [dt.date.fromisoformat(r["due_date"]) for r in rows]
        assert all(a < b for a, b in zip(dates, dates[1:])), "custom dates must increase"
        assert sum(Decimal(r["amount"]) for r in rows) == total, "custom rows must sum to the total"
        return {"financed": f"{financed:.2f}", "markup": f"{markup:.2f}", "total": f"{total:.2f}", "installments": rows}
    count = i["count"]
    cents = int((total * 100).to_integral_value(rounding=ROUND_HALF_UP))
    base = cents // count
    first = dt.date.fromisoformat(i["first_due_date"])
    rows = []
    for n in range(count):
        if i["frequency"] in DAYS:
            due = first + dt.timedelta(days=DAYS[i["frequency"]] * n)
        else:
            due = add_months(first, MONTHS[i["frequency"]] * n)
        c = base if n < count - 1 else cents - base * (count - 1)
        rows.append({"number": n + 1, "due_date": due.isoformat(), "amount": f"{Decimal(c) / 100:.2f}"})
    assert sum(Decimal(r["amount"]) for r in rows) == total, "instalments must sum to the total"
    return {"financed": f"{financed:.2f}", "markup": f"{markup:.2f}", "total": f"{total:.2f}", "installments": rows}


def case(name, principal, down, mtype, mvalue, count, freq, first):
    inp = {"principal": principal, "down_payment": down, "markup_type": mtype, "markup_value": mvalue,
           "count": count, "frequency": freq, "first_due_date": first}
    return {"name": name, "input": inp, "expected": schedule(inp)}


def custom(name, principal, down, mtype, mvalue, rows):
    inp = {"principal": principal, "down_payment": down, "markup_type": mtype, "markup_value": mvalue,
           "count": len(rows), "frequency": "custom", "first_due_date": rows[0][0],
           "custom_schedule": [{"due_date": d, "amount": a} for d, a in rows]}
    return {"name": name, "input": inp, "expected": schedule(inp)}


def bad(name, **overrides):
    inp = {"principal": "100.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 3,
           "frequency": "monthly", "first_due_date": "2026-10-07"}
    inp.update(overrides)
    return {"name": name, "input": inp}


VALID = [
    case("month-end clamp: 31 Jan monthly x3", "1000.00", "0.00", "none", "0", 3, "monthly", "2026-01-31"),
    case("100.00 over 3 leaves a remainder on the last", "100.00", "0.00", "none", "0", 3, "monthly", "2026-04-10"),
    case("single instalment", "250.00", "0.00", "none", "0", 1, "monthly", "2026-10-07"),
    case("percent markup 10 on financed amount with down payment", "12000.00", "2000.00", "percent", "10", 12, "monthly", "2026-03-15"),
    case("weekly x4", "400.00", "0.00", "none", "0", 4, "weekly", "2026-10-07"),
    case("biweekly x6 with down payment", "1800.00", "300.00", "none", "0", 6, "biweekly", "2026-11-01"),
    case("fixed markup", "2000.00", "500.00", "fixed", "250.00", 5, "monthly", "2026-12-05"),
    case("60 instalments across a leap year, 31 Dec", "60000.00", "0.00", "none", "0", 60, "monthly", "2026-12-31"),
    case("percent markup rounds half up", "333.33", "0.00", "percent", "7.5", 4, "monthly", "2026-09-30"),
    case("leap day: 31 Jan 2028 monthly x3", "900.00", "0.00", "none", "0", 3, "monthly", "2028-01-31"),
    case("tiny amount splits into cents", "0.03", "0.00", "none", "0", 3, "monthly", "2026-10-07"),
    case("large principal", "99999999999.99", "0.00", "none", "0", 7, "monthly", "2026-10-07"),
    case("interest-free with down payment, 30th of month", "5000.00", "1000.00", "none", "0", 8, "monthly", "2026-06-30"),
    case("percent markup 0 behaves like none", "800.00", "0.00", "percent", "0", 4, "weekly", "2026-02-27"),
    case("daily x5 across a month end", "500.00", "0.00", "none", "0", 5, "daily", "2026-10-29"),
    case("every two months from 31 Jan keeps the 31st", "600.00", "0.00", "none", "0", 4, "bimonthly", "2027-01-31"),
    case("quarterly from 30 Nov clamps into February", "1200.00", "0.00", "none", "0", 4, "quarterly", "2026-11-30"),
    case("every six months from 31 Aug", "2000.00", "0.00", "percent", "5", 4, "semiannual", "2026-08-31"),
    case("yearly from 29 Feb 2028 falls back to the 28th", "3000.00", "0.00", "none", "0", 3, "yearly", "2028-02-29"),
    case("600 monthly instalments, fifty years", "600000.00", "0.00", "none", "0", 600, "monthly", "2026-11-15"),
    custom("the shop's own dates with a fixed markup", "1000.00", "0.00", "fixed", "100.00",
           [("2026-11-15", "500.00"), ("2027-03-01", "350.00"), ("2027-06-20", "250.00")]),
    custom("own dates after a down payment", "5000.00", "1000.00", "none", "0",
           [("2026-12-31", "1000.00"), ("2027-12-31", "3000.00")]),
]

INVALID = [
    {"name": "down payment equals principal", "input": {"principal": "100.00", "down_payment": "100.00", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "zero instalments", "input": {"principal": "100.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 0, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    bad("more than 600 instalments", count=601, principal="601.00"),
    {"name": "negative markup", "input": {"principal": "100.00", "down_payment": "0", "markup_type": "fixed", "markup_value": "-5", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "zero principal", "input": {"principal": "0.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "total smaller than one cent per instalment", "input": {"principal": "0.02", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    bad("unknown frequency", frequency="hourly"),
    bad("own dates that do not add up to the total", frequency="custom", count=2,
        custom_schedule=[{"due_date": "2026-11-01", "amount": "50.00"}, {"due_date": "2026-12-01", "amount": "40.00"}]),
    bad("own dates that do not increase", frequency="custom", count=2,
        custom_schedule=[{"due_date": "2026-12-01", "amount": "50.00"}, {"due_date": "2026-11-01", "amount": "50.00"}]),
    bad("an own date that is not a date", frequency="custom", count=2,
        custom_schedule=[{"due_date": "2026-11-01", "amount": "50.00"}, {"due_date": "01/12/2026", "amount": "50.00"}]),
    bad("an own amount of nothing", frequency="custom", count=2,
        custom_schedule=[{"due_date": "2026-11-01", "amount": "100.00"}, {"due_date": "2026-12-01", "amount": "0.00"}]),
    bad("own dates with no rows", frequency="custom", count=0, custom_schedule=[]),
    {"name": "malformed date", "input": {"principal": "100.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "monthly", "first_due_date": "07/10/2026"}},
]

if __name__ == "__main__":
    out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "schedule-vectors.json")
    doc = {"_note": "GENERATED by shared/tools/generate_schedule_vectors.py (independent Decimal reference). PHP and Dart must reproduce every case.",
           "valid": VALID, "invalid": INVALID}
    with open(os.path.normpath(out), "w", encoding="utf-8", newline="\n") as fh:
        json.dump(doc, fh, indent=2, ensure_ascii=False)
        fh.write("\n")
    print(f"wrote {len(VALID)} valid and {len(INVALID)} invalid vectors")
