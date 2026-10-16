# Forms that save once, read clearly, and your profile

Asked by the owner on 16 October 2026: "many forms show errors and are not working properly, the form saved twice on
the database", pages "not showing properly" on phones, and "add profile edit to edit all the information on the
application and web version".

## What was wrong

| Problem | Cause | Fix |
|---|---|---|
| A form saved twice (the live audit log shows the same sign-in and the same demo sign-up twice within a second) | No form stopped a second press while a slow page (about 3 s on the live site) was loading | Both layers below |
| Adding a customer on the web failed with a server error when the business had tags (also from the app) | `CreateCustomer` gave the `tags` list to the model as if it were a column | Tags are taken out and attached after the customer is saved |
| Mistakes named the code's fields: "The principal field is required", "The customer id field is required"; Arabic said "حقل principal مطلوب" | Validators had no names for our fields | `App\Support\FieldNames`: every validator names fields the way the form labels them, in the person's language |
| A list field (`wording[late]`, `play_products[pro]`) lost what was typed and never showed its mistake | `<x-field>` looked them up by the bracket name; Laravel files them under `wording.late` | The component reads the dotted name |
| Instalment tools: each setting had its own Save, so changing three and pressing one lost two; a mistake showed in every field | One form per setting, all reading `old('value')` | One form, one Save; nothing saved when one value is wrong, every mistake under its own setting |
| A feature switched off wrote an error to the log on every visit | Refusals were reported like crashes | `EntitlementException` is never reported |
| Lists on phones (customers, contracts, payments, imports, reports, admin) ran to the card's edges and squeezed details into narrow columns | The stacked-table style had no padding and right-aligned every value | Each row is a small card: the first cell is its headline, the others two abreast with their name above, long cells full width |
| Admin notifications grid wider than a phone; file pickers were the browser's bare control; the document options panel ran off a tablet screen; a Latin business name in Arabic was cut at its start | Missing phone styles | Fixed in `components.css` / `app-shell.css` / `admin.css` |

## One press, one save

- **In the browser** (`resources/js/forms.js`): a form that is sending ignores further presses and shows a spinner on
  the button that sent it (the button keeps its name and value; it is not disabled). Every POST/PUT/DELETE carries a
  one-time `_once` token. A download lets the form go after 1.5 s; anything else after 8 s, keeping the same token.
  Coming back to a page the browser kept gives every form a fresh token. When a page comes back with mistakes, the
  first field to fix is scrolled into view and focused. Mark a form `data-repeatable` to opt out, `data-download` when
  it answers with a file.
- **On the server** (`App\Http\Middleware\PreventDuplicateSubmissions`, web group): for a signed-in person, a second
  request with the same token waits for the first (up to 15 s) and is answered with the first one's redirect; nothing
  runs twice. A first press that came back with mistakes, a download or a page is let go, so the same form can be sent
  again. Sign-in and sign-up are guarded by the browser lock only (they change the session itself).

## Your profile

- **Web:** `/app/profile`, first in the account menu (and in "More" on a phone). Photo (JPEG, PNG or WebP up to 8 MB,
  drawn again without hidden data, kept within 400 × 400 in `user_photos`), name, e-mail (a new one is verified again
  with a link), phone (optional; Arabic digits are read; for text-message notices once a provider is chosen), language,
  and the password (current one required, the platform's password rules). Links to Security, Notifications and Delete
  my account. The avatar in the menu shows the photo.
- **API:** `GET /me` now has `user.phone`, `user.photo` (data URI or null) and `user.member_since`; `PUT /me`,
  `PUT /me/password` (204), `POST /me/photo` (multipart `photo`), `DELETE /me/photo`. Each change answers with the
  account, as `GET /me` does.
- **Activity log:** `profile.updated` with the names of the fields changed (never their values),
  `profile.photo_changed`, `profile.photo_removed`.
- The phone feeds `App\Notifications\Channels::for()` for SMS once an SMS provider exists.

## Checked

- Tests: `tests/Feature/App/{DuplicateSubmissionTest,ValidationMessagesTest,FieldComponentTest}.php`,
  `tests/Feature/Account/ProfileTest.php`, `tests/Feature/Entitlements/QuietRefusalsTest.php`, additions to
  `CustomerListsTest` and `TenantSettingsTest`. 30 of 30 deliberate breaks caught.
- The migration runs on PostgreSQL (PGlite) as well as SQLite.
- A browser run on the local site: adding a customer works, a double click makes one customer, the contract form's
  mistakes read "The customer field is required." and "The price field is required."
