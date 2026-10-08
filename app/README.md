# Qistas app

The Flutter app for Qistas: customers, contracts and payments for instalment businesses, on Android, iOS and the
web. It talks to the same account as the website through the REST API (`/api/v1`, see
[`docs/api/openapi.yaml`](../docs/api/openapi.yaml)) and holds no business rules of its own except the instalment
schedule preview, which is a port of the server's generator and is checked against the same test vectors
([`shared/schedule-vectors.json`](../shared/schedule-vectors.json)).

## Get it

- **Android:** download `qistas-app.apk` from the
  [latest release](https://github.com/rabi3ogabes/qistas/releases/latest), open it on the phone and allow the
  installation when asked. It is a test build signed with a debug key (uninstall an older one first).
- **In a browser:** the web build is attached to every run of the *Mobile app* workflow
  (Actions → Mobile app → `qistas-app-web`).
- It points at https://qistas-puce.vercel.app and signs in with the same email and password as the website.

## What it does for a business owner

The app is built around one question, "who do I need to deal with today?", and keeps the answer one tap away.

- **A briefing, not a list of numbers.** The dashboard greets the owner by name, says how many instalments need them
  today (late ones first), shows what is still to collect with the last fourteen days of takings, how the month is going
  against the last one, and what falls due this week.
- **Act where you read.** Each late or due instalment has **Remind** and **Record payment** on the spot. Remind writes
  the message in the app's language (friendly when only due, firmer when late) and sends it through WhatsApp, SMS or a
  call, or copies it. A number written the local way is completed with the business's own country code.
- **The gold plus.** In the middle of the bottom bar, one tap from anywhere: record a payment (asks who paid), a new
  customer, a new contract, search.
- **A moment after a payment.** What was received, what is left, how much of the contract is paid, and the receipt one tap
  away on WhatsApp.
- **Search** across customers and contracts by name, phone or contract number, remembering the last things opened.
- **Fresh when you come back.** After more than two minutes in the background the figures are fetched again.
- **Right-to-left done properly.** Arabic and Urdu mirror the whole layout, keep amounts and numbers readable, and use
  wording that reads correctly for any count.

## Run it

```sh
flutter pub get
flutter run                                   # against https://qistas-puce.vercel.app
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080/api/v1   # Android emulator to `docker compose up`
flutter run -d chrome --dart-define=API_BASE_URL=http://localhost:8080/api/v1
```

## Check it

```sh
flutter analyze        # must report no issues at all (strict mode)
flutter test           # unit, data, design and widget tests
python tool/i18n.py --sync   # translations: fails if any sentence lacks one
```

To *look* at the screens without a phone or emulator, render them with the real fonts and shadows into PNG files:

```sh
QISTAS_SCREENSHOTS=build/shots flutter test test/visual     # skipped when the variable is not set
```

## How it is built

| Part | Where |
|---|---|
| API client, token in secure storage, one sign-out however many requests fail | `lib/core/api`, `lib/core/storage` |
| Typed calls and models (money is never a `double`) | `lib/data`, `lib/core/money.dart` |
| Instalment schedule (matches the server to the cent) | `lib/domain/schedule_generator.dart` |
| Design tokens (the website's colours), widgets, light and dark | `lib/core/design` |
| The luxe layer: hero panel, count-up money, progress ring, sparkline, reveal motion | `lib/core/design/luxe.dart` |
| Brand fonts, bundled so the app never waits on a download (Geist, Cormorant Garamond, IBM Plex Sans Arabic, Noto Nastaliq Urdu, all SIL OFL) | `assets/fonts`, `tool/fonts.py` |
| Bottom bar, quick actions, search and account buttons | `lib/app/chrome.dart`, `lib/app/shell.dart` |
| Reminders, receipts, search, payment moment | `lib/features/reminders`, `lib/features/search`, `lib/features/payments` |
| Words in five languages, right-to-left for Arabic and Urdu | `lib/core/l10n`, `assets/i18n/*.json` |
| Screens | `lib/features/*` |
| Session, router, shell | `lib/app` |

Things worth knowing:

- **Translations.** The English sentence is the key: `context.t('Add customer')`. `tool/i18n.py` reuses the website's
  translation of a sentence where there is one and fails on any that is missing, and `test/l10n` checks the same.
- **Plan limits.** The app never decides by plan name. It reads the entitlements the server sends, shows the limit
  *before* a form that would be refused, and turns every `402` into the upgrade sheet.
- **Store rule.** On iOS and Android there is no purchase link: Pro is activated on the website and shows up in the
  app by itself. Only the web build offers a link to the billing page.
- **Payments are safe to retry.** Each attempt carries an `Idempotency-Key`; a double tap or a dropped connection
  records one payment.
- **Offline.** The last account (and its plan) is kept on the phone, so the app opens to something useful with no
  signal and says so; nothing is queued, because money must not be guessed.
- **Test workspace.** An admin's sandbox is marked with a banner on every screen.
