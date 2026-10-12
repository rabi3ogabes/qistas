# Guarantors, photos, signatures and the promissory note (Win Plan K5, CT21–CT23)

The paperwork of a deal kept inside the contract: who stands behind it, photos of the ID, the signed paper contract and
the invoice, the customer's signature, and the contract and promissory-note PDFs. Switched on per platform in
`/admin/features` (**Guarantors and photos** ships off).

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

## Where it shows

- **Web:** the contract page's "Guarantors and photos" card (scheduled and open contracts).
- **App:** the same section on the contract screen. Photos come from the camera or the phone's photos, already reduced
  to 1600 pixels; a photo opens full screen to zoom into.
- **API:** guarantors and photos are listed with the contract (`GET /contracts/{id}`: `guarantors`, `photos`);
  `POST /contracts/{id}/guarantors`, `PUT|DELETE /guarantors/{id}`, `POST /contracts/{id}/photos` (multipart `kind`,
  `file`), `DELETE /contracts/{id}/photos/{photo}`, `GET /files/{file}`.
