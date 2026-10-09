# App refresh, Pro inside the app, and the notifications centre: design note

What the owner asked for on 10 Oct 2026:

1. "For the application: remove the header showing test workspace, make the language in a different, better, more luxury
   way, re-arrange the settings page and make it better. The upgrade to Pro should be on the same account from the app;
   after the payment (Google Pay or Apple Pay) the account can be upgraded automatically or manually (the admin can
   enable auto upgrade), and he can upgrade or downgrade manually. Change the icon of the application to the logo."
2. "In the admin dashboard the admin can manage notifications (push, e-mail, SMS) for all the users, change the
   settings, enable or disable any type of notification, push settings for iPhone and Android. The admin can change the
   settings of Google Play and App Store payment for the monthly membership fee."

The owner's choices (asked 10 Oct): SMS provider decided later; e-mail through the owner's own SMTP server; push through
Firebase; no Apple Developer account yet, so Android ships first.

Built in three parts, each its own plan, each shipped and pushed before the next starts:

- **Part 1, app refresh (v1.3.0):** needs no outside account. Plan `docs/superpowers/plans/2026-10-10-app-refresh.md`.
- **Part 2, Pro inside the app:** Google Play Billing, admin billing settings, admin workspace plans.
- **Part 3, notifications centre:** channels, types, push/e-mail/SMS settings, user preferences, announcements.

## Decisions

| # | Question | Decision | Cost if wrong |
|---|---|---|---|
| D1 | "Google Pay / Apple Pay" for Pro | **Google Play Billing** on Android and **StoreKit (In-App Purchase)** on iPhone. Store rules forbid any other way to sell a subscription that unlocks the app's own features; the stores' payment sheets offer the person's Google Pay and Apple Pay cards, so it looks the same to them. Told the owner on 9 Oct. | rejection or removal from the stores |
| D2 | Same account | The purchase carries the workspace's id (obfuscated account id = HMAC of the tenant id) and is bound to the signed-in workspace on our server; Pro shows on the web and the app at once, because the plan lives on the server (`subscriptions`), never in the app. | none |
| D3 | Who decides a purchase is real | **Our server**, never the app: it asks Google (Play Developer API `purchases.subscriptionsv2`) and only then changes the plan. Google's real-time notifications (Pub/Sub push) keep renewals, cancellations, holds and refunds in step; a daily re-check covers a missed notification. | none |
| D4 | Automatic or manual upgrade | An admin switch. **Automatic:** a verified purchase is acknowledged and Pro starts at once. **Manual:** the purchase waits in a review list; *approving* acknowledges it and starts Pro; a purchase not approved within Google's three days is refunded by Google automatically, so nobody pays for nothing. The app says plainly which of the two happened. | none |
| D5 | Manual upgrade/downgrade | An admin **Workspaces** page: find a workspace by name or e-mail, see its plan and history, put it on Pro (open-ended or until a date) or back on Free, with a reason. Audited; the person sees it on their next screen. A store subscription keeps renewing at the store: the page says so and links the store's refund/cancel action. | none |
| D6 | Store credentials | Entered by the admin on a **Billing settings** page, stored **encrypted** (Laravel `encrypted` cast with the app key), never shown again (only "saved: client e-mail …"), with a *Test the connection* button. Never in chat, never in the repo. | a leaked key (rotate it in Google Cloud) |
| D7 | iPhone | Code paths and settings for the App Store exist, switched off and labelled "needs an Apple Developer account"; no iOS build until there is one. | none |
| D8 | Notification channels | **Push** (Firebase Cloud Messaging HTTP v1; Android now, iPhone through Firebase's APNs link later), **e-mail** (the owner's SMTP), **SMS** (one driver interface; no provider until chosen, so SMS stays off). Every message goes through one `Notifier` that checks: is the channel on, is the type on for that channel, has this person turned it off. | none |
| D9 | Types | A fixed catalogue in code (like features): account (verify e-mail, password reset, new sign-in), billing (Pro started, renewal coming, payment problem, purchase awaiting review), business (due today, overdue, payment recorded by a teammate, weekly summary), announcements. Some are **required** (password reset and e-mail verification by e-mail) and cannot be switched off, so nobody is locked out. | add types later |
| D10 | Push settings for Android and iPhone | Per platform: on/off, sound, vibration (Android), badge count, the Android notification channel's name and importance, and a *Send a test push to my phone* button. | none |
| D11 | User preferences | The app's Settings get a **Notifications** section: each type the admin allows, per channel, on or off for that person. | none |
| D12 | Announcements | The admin writes a message once (five languages, English required), picks who gets it (everyone, Free, Pro) and how (push, e-mail), sees it in a preview, and sends; a history keeps what was sent and to how many. Sent in batches through the queue. | none |
| D13 | The test-workspace strip in the app | Removed. A quiet **Test** badge on the account card in Settings keeps an admin aware without a header on every screen. | none |
| D14 | Language | The top-bar control becomes a monogram circle beside the account circle; the sheet becomes five cards, each language in its own script and typeface with a greeting in it, the current one ringed in gold. Settings shows one *Language* row with the current language. | none |
| D15 | App icon | The installed APK still shows Flutter's default icon (the launcher files were never generated). New: adaptive icon (navy background, the "q" with its gold plumb as foreground), a monochrome layer for Android 13 themed icons, the name قسطاس under the icon on an Arabic phone, and a navy launch screen with the mark (no white flash; Android 12 splash API too). | none |

## What cannot be proven here

A real Google Play purchase (needs the owner's Play Console, a published test track and a licence-tester account), real
push delivery (needs the owner's Firebase project), real e-mail delivery through the owner's SMTP, an iPhone build, and
how the launcher icon looks on each phone maker's launcher. Each is built against Google's documented formats and
tested with recorded/fake answers; the owner's first real run is the proof, with a written checklist.
