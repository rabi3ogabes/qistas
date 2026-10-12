# Bringing your customers in (Win Plan K9, RP17)

A shop moving from a notebook, Excel or another app brings its customers and their balances in from one file. It sees
every row first; nothing is saved until it confirms. Switched on per platform in `/admin/features` (**Import from
Excel** ships off). Owners and managers.

## The file

- An **Excel (.xlsx)** file (its first sheet) or a **CSV** (comma, semicolon or tab; a byte-order mark is fine), one
  customer per row under a header row. What a file is comes from its bytes, never its name.
- **Columns are recognised in five languages** (`App\Import\Fields`): name, phone, ID number, address, what was sold,
  total, paid, still owed (balance), number of instalments, instalment amount, first instalment's date, contract date,
  notes. Letters are made alike before comparing (case, accents, hamza forms, taa marbuta, diacritics, punctuation), and
  a header that only contains a known name ("Mobile no.") still matches. Any column can be chosen by hand on the review.
- Amounts are read as shops write them: Arabic and Urdu digits, a currency before or after, thousands with commas or
  spaces, a decimal point or a decimal comma. Dates as 2026-03-15, 15/03/2026, 15-03-2026, 15.03.2026 or an Excel day
  number; day before month, as the region writes it. A value that cannot be read is a problem, never a guess.
- **Plan limits:** Free brings in up to **100 rows per file** (Pro: no limit), and never more customers than the plan
  keeps (the 21st customer on Free is marked, not brought in). Both come from the entitlements service.
- A ready template, in the reader's language and right to left for Arabic and Urdu, is one tap away; it has no example
  row, so nothing made up is ever brought in by mistake.

## The trial run

Every row says what it would do (a new customer; added to a customer already on the books, matched by phone however
it is written; or nothing to add) and every problem is named by its **row and column**: a missing name or phone, a
phone that is not one, an amount in words, a date that cannot be read, a paid part larger than the total, the same phone
twice in the file (the later row is left out), or the plan's customer limit.

## Old balances become opening lines (decision D8)

- **With a plan** (a number of instalments or an instalment amount, and a first instalment's date): a scheduled
  contract for the total, monthly, from the first date given; what was already paid is **one payment dated on the
  import day** ("Paid before Qistas"), applied oldest first like any payment. Late fees are never charged for days before
  the contract was made in Qistas.
- **A balance alone:** an **open contract** with the balance as its opening line. This needs open contracts switched
  on; without them the row says to add the instalments.
- **Paid in full:** the customer only.

## Confirming

One confirm brings in every row without a problem, all at once or not at all; rows with a problem are left out and
listed. The trial run is made again against the books as they are at that moment. Confirming again changes nothing.
What was read (the cells) and the planned rows are **encrypted** in the database, ID numbers are only ever shown masked,
and the cells are dropped once the import is done. Audited (`import.confirmed`).

## Where it is

- **Web:** Customers → "Bring your customers in" (`/app/import`): the file, the trial run with its columns and rows,
  confirm.
- **App:** Settings → "Bring your customers in": choose a file from the phone, the same review, confirm at the bottom.
- **API:** `POST /imports` (multipart `file`), `GET|PUT /imports/{id}` (`mapping`), `POST /imports/{id}/confirm`,
  `GET /imports/template.xlsx?language=`.
