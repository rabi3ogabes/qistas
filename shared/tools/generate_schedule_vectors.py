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
  weekly +7 days, biweekly +14 days, monthly = same day-of-month as the FIRST due date, clamped to month end

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


def schedule(i: dict) -> dict:
    principal, down = Decimal(i["principal"]), Decimal(i["down_payment"])
    mv = Decimal(i["markup_value"])
    financed = principal - down
    markup = {"none": Decimal("0"), "fixed": mv, "percent": q2(financed * mv / 100)}[i["markup_type"]]
    total = financed + markup
    count = i["count"]
    cents = int((total * 100).to_integral_value(rounding=ROUND_HALF_UP))
    base = cents // count
    first = dt.date.fromisoformat(i["first_due_date"])
    rows = []
    for n in range(count):
        if i["frequency"] == "weekly":
            due = first + dt.timedelta(days=7 * n)
        elif i["frequency"] == "biweekly":
            due = first + dt.timedelta(days=14 * n)
        else:
            due = add_months(first, n)
        c = base if n < count - 1 else cents - base * (count - 1)
        rows.append({"number": n + 1, "due_date": due.isoformat(), "amount": f"{Decimal(c) / 100:.2f}"})
    assert sum(Decimal(r["amount"]) for r in rows) == total, "instalments must sum to the total"
    return {"financed": f"{financed:.2f}", "markup": f"{markup:.2f}", "total": f"{total:.2f}", "installments": rows}


def case(name, principal, down, mtype, mvalue, count, freq, first):
    inp = {"principal": principal, "down_payment": down, "markup_type": mtype, "markup_value": mvalue,
           "count": count, "frequency": freq, "first_due_date": first}
    return {"name": name, "input": inp, "expected": schedule(inp)}


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
]

INVALID = [
    {"name": "down payment equals principal", "input": {"principal": "100.00", "down_payment": "100.00", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "zero instalments", "input": {"principal": "100.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 0, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "more than 120 instalments", "input": {"principal": "100.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 121, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "negative markup", "input": {"principal": "100.00", "down_payment": "0", "markup_type": "fixed", "markup_value": "-5", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "zero principal", "input": {"principal": "0.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "total smaller than one cent per instalment", "input": {"principal": "0.02", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "monthly", "first_due_date": "2026-10-07"}},
    {"name": "unknown frequency", "input": {"principal": "100.00", "down_payment": "0", "markup_type": "none", "markup_value": "0", "count": 3, "frequency": "daily", "first_due_date": "2026-10-07"}},
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
