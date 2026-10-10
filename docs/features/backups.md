# Backups and data (Win Plan PP10)

What a business sees, and what the platform owner still has to switch on.

## In the product (done)

- **Nightly copies.** Every real business gets its own copy of its books every night (Excel, one sheet each: customers,
  contracts, instalments, payments, what was sold, investors, investor entries). The newest **seven** are kept. Each copy
  is compressed and encrypted with the app key (`APP_KEY`) and kept in the database (`workspace_exports`), because the
  host's disk is thrown away and no file bucket is set up. The scheduler runs `qistas:export-workspaces` every hour; each
  run copies up to 25 businesses that have not had a copy for 20 hours, so no run is long.
- **Download everything.** Owners, managers and accountants can take everything at any time, as one Excel file or as
  CSV files in a zip (each CSV starts with a UTF-8 byte-order mark so Arabic opens correctly in Excel). It works on
  every plan, after a paid plan has lapsed, and while the business is being deleted. A download is kept a day.
  - Web: Settings → Backups & data. App: Settings → Backups & data (the file goes to the phone's share sheet).
  - A file only ever leaves through a link that works for **five minutes** (`/exports/{id}/file`, signed).
- **Activity log.** Owners and managers see who added, changed, recorded, voided or removed what, filtered by person,
  kind and day (web: Activity log in the account menu; app: Settings → Activity log). Customers' changes are logged by
  the names of the fields that changed, never their values.

## For the platform owner (needs you)

These protect the whole database, not one business. They are settings on the hosting accounts; nothing in the code
can turn them on.

1. **Supabase Pro** for the `qistas` project (daily database backups, kept 7 days). In the Supabase dashboard:
   Organization → Billing → upgrade the organization that owns `qistas`.
2. **Point-in-time recovery** add-on (restore the database to any second in the last days, not only last night's
   backup). Supabase dashboard → Project `qistas` → Database → Backups → Point in Time.
3. When the number of businesses grows, the nightly copies take room in the database (roughly the size of each
   business's Excel file, times seven). Moving them to object storage (Supabase Storage or S3) is a contained change in
   `App\Exports\ExportService` if the database plan becomes tight.

## Not built yet

- The optional weekly copy to the owner's own Google Drive. It needs a Google Cloud OAuth client (owner's account) for
  the narrow `drive.file` permission.
