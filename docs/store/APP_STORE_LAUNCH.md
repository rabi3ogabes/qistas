# Putting Qistas on the iPhone: the owner's checklist

Written 12 October 2026 for Sprint 2 Task 9 (Win Plan PL02). Everything here is done by the owner in their own Apple
and Firebase accounts; nothing needs to be pasted into a chat. Apple changes its pages often: where a step says *check*,
read Apple's current page first.

## What is already done in the code

- The iPhone project: the id `com.qistas.qistas` (the same as Android), iOS 15 or later, the app's name under its icon
  in each language (قسطاس in Arabic and Urdu), and why it asks for the camera, the photos and Face ID, in Arabic,
  English, French, Spanish and Urdu. Picking a customer from the contacts needs no permission on iPhone.
- Pushes are ready to switch on: the push entitlement, background notifications, and the Firebase settings copied in
  when a build has them.
- `ITSAppUsesNonExemptEncryption` is set to *no*: Qistas uses only standard HTTPS, so TestFlight does not ask about
  export compliance for each build.
- Every change to `app/` is built for iPhone on GitHub's Macs (`.github/workflows/ios.yml`, the **iPhone app**
  workflow). Without the secrets below it stops there, and its second job (TestFlight) shows grey.
- With the secrets, the same run archives the app, signs it with Xcode's automatic signing through the App Store Connect
  API key, and uploads it to TestFlight. The version comes from `app/pubspec.yaml`; the build number is the run number.

## 1. Join the Apple Developer Program

1. <https://developer.apple.com/programs/enroll/> with the Apple Account the business will keep. Yearly fee.
2. As an **organization** (needs a D-U-N-S number; the App Store then shows the company's name) or as an individual.
3. Note the **Team ID** (Membership details). It is the `APPLE_TEAM_ID` secret.

## 2. Register the app

1. **Certificates, Identifiers & Profiles**, then **Identifiers**, then **+**, then **App IDs**: description *Qistas*,
   explicit bundle id **`com.qistas.qistas`**. Tick **Push Notifications**. In-App Purchase is on by default.
2. **App Store Connect**, then **Apps**, then **+**, then **New App**: platform iOS, name **قسطاس: تقسيط وديون** or
   **Qistas: Installment Tracker** (30 characters at most; *check* that the name is free), primary language Arabic,
   the bundle id above, SKU `qistas-ios`.

## 3. The App Store Connect API key (signs and uploads the builds)

1. **App Store Connect**, then **Users and Access**, then **Integrations**, then **App Store Connect API**, then
   **Team Keys**, then **+**. Name *GitHub*, access **Admin** (needed for Xcode's automatic signing to make the
   distribution certificate and profile itself).
2. Download the `.p8` file (Apple lets you download it once) and note the **Key ID** and the **Issuer ID** shown above
   the list. Keep the file in your password manager.

## 4. Give them to GitHub (repository secrets)

On GitHub: **rabi3ogabes/qistas**, then **Settings**, then **Secrets and variables**, then **Actions**, then **New
repository secret**:

| Name | Value |
|---|---|
| `APPLE_TEAM_ID` | the Team ID (10 characters) |
| `APP_STORE_CONNECT_ISSUER_ID` | the Issuer ID |
| `APP_STORE_CONNECT_KEY_ID` | the Key ID |
| `APP_STORE_CONNECT_PRIVATE_KEY` | the whole `.p8` file, opened in a text editor and pasted, `-----BEGIN PRIVATE KEY-----` lines included |
| `GOOGLE_SERVICE_INFO_PLIST` | optional, for pushes: see step 5 |

Then run the **iPhone app** workflow (Actions, then iPhone app, then Run workflow). Its second job now signs the app and
sends it to TestFlight; the build appears in App Store Connect once Apple has processed it.

## 5. Pushes on iPhone (optional, any time)

1. **Certificates, Identifiers & Profiles**, then **Keys**, then **+**: tick **Apple Push Notifications service
   (APNs)**, download the `.p8` and note its Key ID.
2. In the **Firebase console**, in the Qistas project (the one Android uses): **Project settings**, then **General**,
   then **Add app**, then **iOS**, bundle id `com.qistas.qistas`. Download `GoogleService-Info.plist`.
3. Still in Project settings, **Cloud Messaging**, then **Apple app configuration**: upload the APNs key from 1 with its
   Key ID and your Team ID.
4. Add the `GOOGLE_SERVICE_INFO_PLIST` secret with the whole `GoogleService-Info.plist` file as its value.

## 6. Before sending the app for review

- [ ] **Selling Pro on iPhone.** Apple does not allow an iPhone app to send people elsewhere to pay for a subscription
      it unlocks (App Review Guideline 3.1.1). Today the iPhone app has no in-app purchase (the store button is Android
      only). Before submitting, either Pro is sold through Apple's in-app purchase (the products in App Store Connect,
      and the server checking Apple's receipts, to be built once the account exists), or the iPhone app shows no way to
      buy Pro at all. *Check* the current rules for your countries.
- [ ] **App Privacy** (the "nutrition label"): the same answers as Google Play's Data safety form
      (`docs/store/GOOGLE_PLAY_LAUNCH.md`): no tracking, nothing used for ads, data linked to the user for app
      functionality.
- [ ] **Sign-in for the reviewer**: the demo switch on (Vercel `QISTAS_DEMO_LOGIN=true`) and the review notes saying
      *"On the sign-in screen tap Try the demo, then Enter as admin; no credentials are needed."*
- [ ] **Account deletion** inside the app: already there (Settings, then Security, then Delete my account), as Apple
      requires.
- [ ] **Screenshots**: Apple wants 6.9-inch iPhone pictures (1320 × 2868 or 1290 × 2796). The Play pictures in
      `docs/store/screenshots` are 1080 × 1920; ask for an iPhone set drawn the same way once the account exists.
- [ ] **TestFlight**: add yourself and the team as internal testers first; external testers need Apple's short beta
      review.

## When something goes wrong

- *The TestFlight job is grey*: one of the four App Store Connect secrets is missing or misspelt.
- *Signing fails with "No profiles for com.qistas.qistas"*: the App ID from step 2 is missing, or the API key is not
  **Admin**.
- *Signing fails over push notifications*: tick Push Notifications on the App ID (step 2.1) and run the workflow again.
