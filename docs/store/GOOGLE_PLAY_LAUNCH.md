# Putting Qistas on Google Play: the owner's checklist

Written 11 October 2026 for Sprint 1 (Win Plan PP1). Everything here is done by the owner in their own accounts; nothing
needs to be pasted into a chat. Google changes its forms and rules often: where a step says *check*, read Google's
current page before relying on it.

## What is already done in the code

- Every push to `main` builds the Android app in GitHub Actions (`.github/workflows/app.yml`) and publishes it on the
  repository's Releases page.
- As soon as the four upload-key secrets below exist, the same build is signed with the owner's **upload key** and also
  produces **`qistas-app-<version>.aab`**, the file Google Play wants. Without the secrets it stays a debug-signed test
  build, as today.
- In-app account deletion (Settings, then Security, then Delete my account) and the public page
  <https://qistas-puce.vercel.app/account/delete>, both required by Google Play.
- Privacy policy: <https://qistas-puce.vercel.app/privacy>. Terms: <https://qistas-puce.vercel.app/terms>.
- The version code goes up by itself with every build (the GitHub run number).

## 1. Open a Google Play developer account

1. Go to <https://play.google.com/console> with the Google account the business will keep. A one-time registration fee
   applies.
2. Choose **Organization** if the business has a D-U-N-S number (the listing then shows the company name), otherwise
   **Personal**.
3. Complete identity verification.
4. *Check*: new **personal** accounts must run a **closed test with at least 12 testers for 14 days** before Google lets
   the app into production (step 7). Organization accounts may not need it.

## 2. Make the upload key (once, on your own computer)

The upload key proves that a new version comes from you. Google keeps the real signing key (Play App Signing), so a
lost upload key can be replaced through Play support, but keep it safe all the same.

1. Install Java (any recent JDK). Then, in PowerShell, in a folder you back up:

   ```powershell
   keytool -genkey -v -keystore qistas-upload.jks -keyalg RSA -keysize 2048 -validity 10000 -alias qistas-upload
   ```

2. Choose a strong password when asked, and answer the name and organisation questions.
3. Keep `qistas-upload.jks` and the password in your password manager and a second safe place. Never email them, never
   put them in the repository, never paste them in a chat.

## 3. Give the key to GitHub (four repository secrets)

On GitHub: **rabi3ogabes/qistas**, then **Settings**, then **Secrets and variables**, then **Actions**, then **New
repository secret**. Add:

| Name | Value |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | The keystore as text. In PowerShell: `[Convert]::ToBase64String([IO.File]::ReadAllBytes("qistas-upload.jks")) \| Set-Clipboard`, then paste. |
| `ANDROID_KEY_ALIAS` | `qistas-upload` |
| `ANDROID_KEY_PASSWORD` | the key password |
| `ANDROID_STORE_PASSWORD` | the keystore password (the same one if you used one) |

Then re-run the latest **Mobile app** workflow, or push any change to `app/`. The release for that version now has
`qistas-app-<version>.aab`, and its notes say *Signed with the Qistas upload key*.

## 4. Create the app in Play Console

1. **Create app**. Name: **قسطاس: تقسيط وديون ومحاسبة** (Arabic, the default language) or **Qistas: Installment
   Tracker** (English). Type: App. Price: Free (paid plans are sold inside the app later).
2. Accept the declarations.
3. Turn on **Play App Signing** when you upload the first bundle (recommended by Google).

## 5. App content (the forms Google requires)

| Form | What to answer |
|---|---|
| Privacy policy | `https://qistas-puce.vercel.app/privacy` (move it to your own domain later). |
| App access | Some functions need a sign-in. Tell the reviewer: *“On the sign-in screen tap Try the demo, then Enter as admin; no credentials are needed.”* Keep the demo switch on (Vercel `QISTAS_DEMO_LOGIN=true`) while the app is under review. |
| Ads | The app contains **no ads**. |
| Content rating | Fill the questionnaire honestly: a business tool with no violence, gambling or user-to-user content. |
| Target audience | 18 and over (it is for businesses). |
| Financial features | Qistas does not lend money and is not a loan app: it is accounting software in which a business records its own instalment sales. Answer the questions that way. *Check* Google's current financial-services policy first. |
| Data safety | See the next table. |
| Account deletion | In the app (Settings, then Security, then Delete my account) and on the web at `https://qistas-puce.vercel.app/account/delete`. |

### Data safety answers (as the app works today, 12 October 2026)

Written from the app's code. Its Android permissions are the internet and the fingerprint lock, plus what its parts add:
the camera (scanning a code, photographing a page or a contract), notifications and Google Play billing. There is no ad,
analytics or crash-reporting kit. *Check* each answer against Google's current form, and update it when a switch below
changes what the app handles.

| Question | Answer |
|---|---|
| Does the app collect or share user data? | Collects: yes. Shares with third parties: **no**. Hosting, the database, e-mail delivery and (once switched on) the AI provider that reads notebook photos act on our behalf, which Google does not count as sharing. |
| Encrypted in transit? | Yes (HTTPS only; the app refuses plain HTTP). |
| Can users ask for their data to be deleted? | Yes, in the app and on the web. |
| Personal info: name, email address | Collected, for **account management** and **app functionality**. Required. |
| Personal info: phone number, address, other info (the customers' details a business enters, including an optional national ID, stored encrypted) | Collected, for **app functionality**. Optional, entered by the user. Picking a customer from the phone's contacts copies only that one contact's name and number. |
| Financial info: other financial info (the business's contracts and payments) | Collected, for **app functionality**. Required. |
| Financial info: purchase history | Collected once **Pro through Google Play** is switched on (the purchase is checked with Google), for **account management**. |
| Photos | Optional: the shop's logo today; contract and ID photos once **Guarantors, photos and signatures** is on; notebook pages once **Reading notebook photos and PDFs** is on. For **app functionality**. A photo is drawn again without its location before it is kept or sent. |
| Files and docs | Optional: Excel, CSV or PDF files once **Import from Excel** is on, for **app functionality**. |
| Device or other IDs | Collected once push notifications are set up (the Firebase token that lets the server send this phone an alert), for **app functionality**. |
| Location, contacts list, messages, audio, calendar, health, web history, app activity, crash logs, diagnostics | **Not collected.** |

## 6. Store listing

Everything is ready in `docs/store/`:

| What | Where |
|---|---|
| Title, short description and full description, in Arabic, English, French, Spanish and Urdu | `docs/store/listing/<language>.md`. Play's limits are checked by `app/test/store/listing_test.dart`. Each file says which lines join the full description when a feature is switched on; add them as you switch features on. A native speaker reads every non-English line first. |
| Phone screenshots, 1080 × 1920, seven per language | `docs/store/screenshots/<language>/`, in Play's order: Today, a WhatsApp reminder, the plan calculator, a contract, a statement, the app lock, five languages by night. Arabic leads in Arabic listings. |
| App icon, 512 × 512 | `docs/store/graphics/icon-512.png` |
| Feature graphic, 1024 × 500 | `docs/store/graphics/feature-graphic.png` |

The screenshots are drawn from the real app with sample data in each market's names and money. They show only what
is switched on for everyone today. After switching features on (investors, flexible schedules, remind-all), or after a
redesign, draw them again and commit them:

```powershell
cd app
$env:QISTAS_SCREENSHOTS = "../docs/store/screenshots"; flutter test test/visual/store_screenshots_test.dart; Remove-Item Env:QISTAS_SCREENSHOTS
```

## 7. Closed test, then production

1. **Testing**, then **Closed testing**, then **Create track**. Add at least 12 testers (an email list or a Google
   Group).
2. Upload `qistas-app-<version>.aab` from the latest GitHub release and roll out to the track.
3. Send the testers the opt-in link. They install the app from Google Play and keep it installed for 14 days.
4. Then **Apply for production** and answer Google's questions about the test.
5. In production, use a **staged rollout** (for example 10%, then 50%, then 100%) so a problem reaches few people.

### Before pressing "Apply for production"

- [ ] The switches you want at launch are on in `/admin/features`, and the listing's waiting lines for them are added
      to `docs/store/listing/*.md`; the screenshots are drawn again if the screens changed.
- [ ] The Data safety form matches the table above for the switches that are on.
- [ ] The demo switch (Vercel `QISTAS_DEMO_LOGIN=true`) is on for the review, and the App access answer says how to use
      it.
- [ ] A support e-mail and the privacy policy link are filled in the store listing's contact details.
- [ ] "What's new" for the release, at most 500 characters in each language.
- [ ] The release is the newest `qistas-app-<version>.aab`, signed with the upload key.
- [ ] The privacy policy names every kind of provider in use, including the AI provider before AI reading is switched
      on (with the lawyer).

## When something goes wrong

- *The release has no .aab*: one of the four secrets is missing or misspelt. The workflow's summary says which kind of
  build it made.
- *Google rejects the bundle as signed with a debug key*: same cause.
- *Lost upload key*: ask Play Console support to reset the upload key (possible because Google holds the signing key),
  make a new one as in step 2, and update the four secrets.
