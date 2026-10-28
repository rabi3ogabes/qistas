# Notifications: what reaches people, and how (Sprint 3 Task 1b)

Every notice can reach a person **in the app** (the inbox, and a push to each Android phone and iPhone), **by e-mail**
and **by text message**. The admin decides which kind may use which channel for everyone; each person chooses, kind by
kind and channel by channel, among what the admin allows and what is set up.

## The kinds of notice

| Kind | Who | When | Goes by default |
|---|---|---|---|
| Morning summary | everyone who collects or keeps the books | at the time they choose | in the app |
| On the due date | the same | an instalment falls due (off by default) | in the app |
| When an instalment is late | the same | after the grace days, repeating daily, weekly or monthly | in the app |
| Evening reminder to close the day | everyone who may close the day (not viewers) | from the workspace's closing time, for two hours, while the day is open and has payments or something to look at | in the app |
| When a day is closed with a cash difference | owners | someone who is not an owner closed a day and the cash counted did not match | in the app |
| Before your plan ends | owners | 7 days and 1 day before a plan that does not renew by itself ends (a bank transfer, or a store subscription they cancelled) | in the app and by e-mail |
| When your plan renews | owners | Google Play renewed it, or the team confirmed a bank transfer | in the app and by e-mail |
| When a payment fails | owners | Google Play could not take the payment (grace, on hold), or a transfer was not confirmed | in the app and by e-mail |
| When your plan ends | owners | the paid plan ended (after the grace days) or was refunded: the workspace is on Free, nothing deleted | in the app and by e-mail |
| Support replies | the person who wrote | the team answered | in the app and by e-mail |

The morning summary and the instalment alerts also need their platform switches (**Morning summary**, **Instalment
alerts and remind-all**) in `/admin/features`; the two end-of-day notices need **End-of-day close**. Billing and support notices need no switch.
Each notice goes once: its log line has a unique key, so a rerun or a retried store message never repeats it.

## Who decides what

1. **The channel is set up.** In the app: always (the inbox works without Firebase; pushes to phones need the Firebase
   key). E-mail: once the SMTP server is saved in `/admin/mail`, and only to a verified address. Text messages: once an
   SMS provider is chosen (none yet: the channel is not offered anywhere).
2. **The admin allows it**, in `/admin/notifications`: a grid of every kind by every channel. Everything is allowed until
   the admin changes it.
3. **The person chose it**: in the app (Settings, then Alert settings, "How notices reach you") or on the web (account
   menu, then Notifications). Owners see the billing kinds; others do not.

## Setting it up (owner)

| What | Where | Unblocks |
|---|---|---|
| The SMTP server | `/admin/mail` (with a "send a test" button) | Every e-mail, including verification and password reset |
| The Firebase service account | `/admin/notifications` (Firebase console: Project settings, then Service accounts, then Generate new private key) | Pushes from the server |
| `google-services.json` | GitHub secret `GOOGLE_SERVICES_JSON` (see `docs/store/GOOGLE_PLAY_LAUNCH.md`) | Android phones registering for pushes |
| The Apple push key, and `GoogleService-Info.plist` | Firebase (Cloud Messaging, Apple app configuration), GitHub secret `GOOGLE_SERVICE_INFO_PLIST` (see `docs/store/APP_STORE_LAUNCH.md`) | iPhones registering for pushes |
| An SMS provider | Sprint 3 Task 3, once chosen | Text messages (and a phone number on each account) |

**As of 12 October 2026 none of these is set on the live site**: no phone has registered for pushes, e-mail goes to the
log, and the alert switches are off. The code paths are tested end to end with fakes.

## Where it is in the code

`App\Notifications\{Catalog, Channels, Preferences}`, `App\Notifications\Alerts\Notifier` (sends on each channel),
`App\Notifications\Push\{PushSettings, FcmPushSender}` (iPhones get the default sound), `App\Mail\NoticeMail`,
`App\Sms\{SmsSender, NoSmsProvider}`, `App\Billing\BillingNotices` with `qistas:billing-notices` (hourly) and the store's
renewals through `StoreSubscriptions`; `/admin/notifications`; `/app/settings/notifications`; the app's alert settings
screen. API: `GET|PUT /api/v1/notifications/preferences` (each kind's `channels`, and `meta` with the kinds, the
channels set up for this person, and what the admin allows).
