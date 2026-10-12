# Guarantors, photos, signatures and the promissory note (Win Plan K5, CT21–CT23)

The paperwork of a deal kept inside the contract: who stands behind it, photos of the ID, the signed paper contract and
the invoice, the customer's signature, and the contract and promissory-note PDFs. Switched on per platform in
`/admin/features`: **Guarantors, photos and signatures** and **Promissory note** both ship off.

## Guarantors

- Up to **three** on a contract, in the order they were added: a name, a phone, how they know the customer (the owner's
  own words) and an ID number. A fourth is refused.
- The ID number is **encrypted** in the database (the model's cast) and only ever shown **masked** (`••••••432`).
  Collectors and viewers never see it, not even masked; they do see the name and the phone, which is what a collector
  needs.
- Owners, managers and accountants add and change them (while the switch is on); owners and managers remove them
  (whether it is on or not). When changing one, a blank ID number keeps the one saved; "Remove the saved ID number"
  clears it.
- Every change is in the audit log (`guarantor.added`, `guarantor.changed` with the field names, `guarantor.removed`),
  never with the ID number.

## Photos

- What a contract keeps: the customer's **ID (front and back)**, the **signed paper contract** and the **invoice**, as
  JPEG or PNG, at most 6 MB sent, at most **twelve** on a contract.
- Each picture is checked by what its bytes are (never its name), then **drawn again**: location, camera and every
  other hidden detail is gone, it is turned upright, and it is kept at most **1600 pixels** on its longest side.
- **Where they are kept:** the private `files` disk. On Vercel (no `FILESYSTEM_DISK`) that is the database
  (`file_blobs`, decision D5): it lasts, and it is backed up with everything else. With `FILESYSTEM_DISK=s3` it is a
  bucket instead; the paths are the same, so moving later is a copy. See `docs/DEPLOY.md`.
- **Handing them out:** only through links that work for **five minutes** (signed routes for the database, presigned
  URLs for a bucket). Ordinary photos are shown with their link. An **ID photo is never shown in the list**: it is a
  locked tile, opened one at a time (`GET /api/v1/files/{file}`, or `/app/files/{file}` on the web), only by owners,
  managers and accountants, and each opening is written to the audit log (`file.viewed`).
- Owners, managers and accountants add photos; owners and managers remove them (the bytes leave storage, the record
  stays, marked deleted).

## The customer's signature (decision D6)

- **In person:** the owner hands over the screen and the customer signs on the pad (web contract page, or the app's
  "Sign on this phone").
- **On their own phone:** "Send a link to sign on their phone" makes a link and a six-digit code, shown to the owner
  **once** (only their hashes are kept). The link goes by WhatsApp and the code by text message, **apart**, so a
  forwarded link alone opens nothing. The customer's page (`/sign/{token}`, five languages, never indexed) asks for the
  code before it shows anything about the contract, then shows the deal and the schedule and the pad. The link works
  **once**, for **24 hours**; **five wrong codes** close it; a new link closes the one before.
- Each signature is drawn again by the server as a clean PNG and kept with the contract, with when and how it was given
  (and, through a link, the address and browser it came from). Signing again keeps the old one as history. Audited
  (`contract.signed`, `signature_link.created`). Same switch as guarantors and photos.

## The contract and the promissory note (PDFs)

- **Contract** (`contract_agreement`): the shop and the customer, the guarantors, what was sold, the price and the
  plan, the schedule, a short statement of what the buyer agrees to, and the customer's signature. ID numbers are
  masked, and left out for collectors and viewers.
- **Promissory note** (`promissory_note`, سند لأمر): what is still owed on the instalments (late fees are not part of
  it), in figures and in words (ICU spells numbers and names currencies in every language), payable on the last
  instalment's due date to the order of the shop, with the drawer and the guarantors and their signature lines. Behind
  its own switch, **Promissory note**, which ships off and stays off until a lawyer approves the wording. Owners,
  managers and accountants only.
- Both carry the verification QR like every document, and count against the plan's documents.

### Questions for the lawyer (before switching the note on)

- The note's wording and form under the Commercial Papers Law, including a single maturity (the last instalment's day)
  versus "on demand", and whether a place of issue is required.
- Whether the note and the contract must carry the full ID numbers (they print them masked today).
- The contract's short "what the buyer agrees to" text, and whether the business should be able to add its own terms.
- Whether a signature drawn on a phone through a link with a one-time code is enough for the note, or only for the
  contract.
- The amount in words: Arabic is written by Qistas's own rules (as on a cheque: "عشرة آلاف وثمانمائة"), English,
  French and Spanish by ICU. ICU has no Urdu rules, so a note printed in Urdu writes the amount in English words; print
  it in Arabic or English until that is settled.

## Where it shows

- **Web:** the contract page's "Paperwork" card (scheduled and open contracts): guarantors, photos, the signature, and
  the contract and promissory-note PDFs.
- **App:** the same section on the contract screen. Photos come from the camera or the phone's photos, already reduced
  to 1600 pixels; a photo opens full screen to zoom into.
- **API:** guarantors, photos and the latest signature are listed with the contract (`GET /contracts/{id}`:
  `guarantors`, `photos`, `signature`); `POST /contracts/{id}/guarantors`, `PUT|DELETE /guarantors/{id}`,
  `POST /contracts/{id}/photos` (multipart `kind`, `file`), `DELETE /contracts/{id}/photos/{photo}`, `GET /files/{file}`,
  `POST /contracts/{id}/signature` (multipart `file`, a PNG), `POST /contracts/{id}/signature-links`,
  `GET /contracts/{id}/agreement.pdf`, `GET /contracts/{id}/promissory-note.pdf`.
