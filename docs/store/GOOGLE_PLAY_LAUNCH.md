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

### Data safety answers (as the app works today)

| Question | Answer |
|---|---|
| Does the app collect or share user data? | Collects: yes. Shares with third parties: no (hosting and database providers act on our behalf, which Google does not count as sharing). |
| Encrypted in transit? | Yes. |
| Can users ask for their data to be deleted? | Yes, in the app and on the web. |
| Personal info: name, email address | Collected, for **account management** and **app functionality**. Required. |
| Personal info: phone number, address, other info (customers' details the business enters, including an optional national ID, which is stored encrypted) | Collected, for **app functionality**. Optional, entered by the user. |
| Financial info: other financial info (the amounts of the business's contracts and payments) | Collected, for **app functionality**. |
| Location, contacts, photos, app activity, device or other IDs, crash logs, diagnostics | **Not collected** today. Update this form when push notifications (Firebase) and crash reports arrive in Sprint 1, Task 14. |

## 6. Store listing

Use the copy in the Win Plan, section 6.7 (`docs/features/win-plan.html`): title, short description, full description
bullets, and the eight screenshots, in Arabic, English, French and Urdu. A native speaker should read every
non-English line first. Google also asks for a 512 × 512 icon and a 1024 × 500 feature graphic; ask Claude to produce
both from the brand files.

## 7. Closed test, then production

1. **Testing**, then **Closed testing**, then **Create track**. Add at least 12 testers (an email list or a Google
   Group).
2. Upload `qistas-app-<version>.aab` from the latest GitHub release and roll out to the track.
3. Send the testers the opt-in link. They install the app from Google Play and keep it installed for 14 days.
4. Then **Apply for production** and answer Google's questions about the test.
5. In production, use a **staged rollout** (for example 10%, then 50%, then 100%) so a problem reaches few people.

## When something goes wrong

- *The release has no .aab*: one of the four secrets is missing or misspelt. The workflow's summary says which kind of
  build it made.
- *Google rejects the bundle as signed with a debug key*: same cause.
- *Lost upload key*: ask Play Console support to reset the upload key (possible because Google holds the signing key),
  make a new one as in step 2, and update the four secrets.
