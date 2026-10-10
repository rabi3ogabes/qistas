# Instalment alerts, the morning summary and remind-all (Win Plan PP9)

## What people get

- **Morning summary**: once a day, at the time each person chooses (09:00 by default, on the business's own clock),
  how many instalments are due today and how many are late, with the amounts. A day with nothing due and nobody
  late sends nothing. It also counts the customers past the business's late limit (Settings → Instalment tools, 30
  days by default).
- **Instalment alerts**: on an instalment's due date (off by default, since the summary already says who pays today)
  and, once its grace days are over, again every day, week (the default) or month, until it is paid. Several in one
  morning arrive as one alert that names the customers.
- **Quiet hours**: nothing arrives in them; it waits until they end.
- **Inbox**: every alert is kept in the app (the bell on the dashboard), pushes or not.
- **Remind everyone**: a checklist of who pays today (or who is late). "Next" opens WhatsApp with the message
  written; coming back ticks the customer off. On the web it is under the dashboard's "Due today".
- **Your wording**: owners and managers write the reminders in their own words, in each of the five languages, with
  `:name`, `:amount`, `:date`, `:reference` and `:business` filled in. Without it, the default wording is used.

Collectors, accountants, managers and owners get alerts; viewers do not.

## Switches (admin → Features)

- `instalment_alerts`: instalment alerts, the inbox's instalment entries, remind-all and the wording.
- `daily_digest`: the morning summary.

Both ship off. Switch them on in `/admin/features` when you are ready. Each person's choices and the wording are
kept while a switch is off.

## Turning pushes on (owner)

Until these two steps are done, alerts reach the inbox only.

1. In the [Firebase console](https://console.firebase.google.com), create a project for Qistas and add an Android
   app with the package name `com.qistas.qistas`. Download `google-services.json` and add its whole contents as the
   GitHub repository secret **`GOOGLE_SERVICES_JSON`** (Settings → Secrets and variables → Actions). The next app
   build takes pushes.
2. In Firebase → Project settings → Service accounts, generate a new private key. Add the whole JSON file (or its
   base64) to Vercel as the environment variable **`QISTAS_FCM_CREDENTIALS`** (sensitive, Production), then
   redeploy. The website starts sending pushes.

Never paste either file into a chat or commit it to the repository.

## How it runs

The scheduler (the GitHub workflow that calls `/internal/cron` every five minutes) runs `qistas:send-digests` and
`qistas:instalment-alerts`. Each alert is logged under a unique key (`notification_log.dedupe_key`), so running them
again never sends the same thing twice. A phone Firebase reports gone is forgotten.
