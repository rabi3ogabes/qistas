# Qistas: the plan for the app, Pro in the app, and notifications

Three parts, built in this order. Each part is finished, tested, pushed to GitHub and (for the app) released as a new
Android version before the next one starts, so you can try each part on its own.

---

## Part 1 — The app refresh (version 1.3.0) · starting now

Nothing is needed from you for this part.

**The real app icon and launch screen**
- The app you installed still shows Flutter's default blue logo, because the Android icon files were never generated
  from the Qistas mark. It gets the Qistas "q" with its gold plumb on navy.
- It is an adaptive icon, so it fits round, square and teardrop launchers. Phones with themed icons (Android 13 and
  later) get a single-colour version.
- On an Arabic phone the name under the icon is **قسطاس**; elsewhere it is **Qistas**.
- Opening the app shows a navy launch screen with the mark, with no white flash.

**No test-workspace strip**
- The yellow "Test workspace · Sample data…" strip is removed from the top of every screen.
- A small *Test* badge on your account card in Settings still tells you when you are in the test workspace.

**A finer language picker**
- At the top of each screen the language becomes a small round button showing the language's first letter (En, ع,
  Fr, Es, ار), matching the round account button beside it.
- Tapping it opens five cards. Each shows the language in its own script and lettering, with a greeting
  (Welcome · أهلًا بك · Bienvenue · Bienvenido · خوش آمدید). The current one has a gold ring.
- The app changes language instantly.

**Settings, reorganised** (top to bottom)
1. **You:** your initials, name, e-mail, business, role and plan.
2. **Your plan:** what you use against the Free limits, and the way to Pro.
3. **Preferences:** language, and appearance (System / Light / Dark).
4. **Workspace:** your instalment tools.
5. **Security:** two-step sign-in, sign out, and sign out everywhere (both ask first).
6. **Help:** the website, privacy, terms and the app version.

**The app follows your Appearance page**
- The colours you publish in the admin's Appearance page (light and dark) also colour the app.
- The Android app's welcome banner shows on the app's dashboard, in the phone's language, and can be closed.
- The app remembers the last look, so it opens in your colours even with no connection.

You get a new APK on its own GitHub release, "Qistas app 1.3.0".

**Progress (10 Oct):** the icon and launch screen, the strip removal, the language cards and the new Settings are done
and pushed. "The app follows your Appearance page" comes after the event themes below, so the app respects events from
the start.

---

## Part 1B — Seasonal event themes (added 10 Oct, built before the app learns your look)

Dress Qistas for a national day or a season, **only for the countries you choose and only on the days you choose**;
everyone else keeps the usual look.

**Example:** Saudi National Day.
- Choose the ready-made "Saudi National Day" event. It fills in:
  - the name
  - the country (Saudi Arabia)
  - green and white colours that pass the readability checks
  - a greeting banner in all five languages
  - the next 23 September
- Change the dates to "today, for 2 days" if you like, and press **Schedule**.
- Visitors and users in Saudi Arabia see the green look and the greeting on the website, the web app and the Android
  app on those days. Everyone else sees nothing different. On the day after, it ends by itself.

**What you control for each event**
- **Who:** one country, several, or everyone. A signed-in user counts as their business's country; a visitor counts as
  the country they browse from.
- **When:** first day to last day, in that country's own time, so midnight in Riyadh is midnight in Riyadh.
- **Where:** the website, the web app, the Android app, any mix.
- **What:** the colours (or keep the usual ones), the logo and pictures (or keep the usual ones), and a welcome banner
  for each place.

**Smart touches**
- Ready-made events:
  - Saudi National Day and Founding Day
  - National days of the UAE, Kuwait, Qatar, Bahrain and Oman
  - White Friday
  - Ramadan and the two Eids (you set those dates each year, because they follow the moon)
- A **timeline** of the next twelve months, with today marked. Each event shows *On now*, *Starts in 3 days* or *Ended*.
- **Preview as:** pick a country and a date, and see exactly what that visitor would see.
- **Overlap warnings:** if two events meet, the page says which one people will see. The more specific one wins: a
  Saudi-only event beats one for everyone.
- **Stop** an event at any moment. Every change is recorded in the audit log.

---

## Part 2 — Pro inside the app, on the same account

**Why Google Play and not Google Pay directly:** Google and Apple allow a subscription that unlocks an app's features to
be sold only through their own billing (Google Play Billing, Apple In-App Purchase). Their payment sheet still lets
people pay with the Google Pay or Apple Pay cards they already use, so for the customer it looks the same. Anything
else gets the app rejected or removed. Google keeps 15% of subscription payments.

**What your customers get**
- An **Upgrade to Pro** button in the app, showing the monthly price in their own currency. They pay on Google's
  payment sheet and stay in the app.
- Pro on **the same account**, on the phone and the website at once.
- A **Manage subscription** link that opens their Google Play subscriptions to cancel or change their payment card.

**What your server does**
- It checks every purchase with Google before granting Pro; the app's word is never trusted.
- Google tells the server about every renewal, cancellation, missed payment and refund. The plan follows: a missed
  payment keeps Pro for the grace period, then the account returns to Free. Nothing is ever deleted.

**What you control in the admin**
- **Billing settings:**
  - Google Play on or off, the app's package name, the subscription's product ID, and the Google Cloud access key.
    The key is stored encrypted and never shown again.
  - A **Test the connection** button.
  - **Upgrade mode:**
    - *Automatic:* Pro starts the moment Google confirms the payment.
    - *I approve each purchase:* purchases wait in a review list. Approving starts Pro. A purchase you don't approve
      within 3 days is refunded by Google automatically, so nobody pays for nothing.
  - An App Store section, ready but off until you have an Apple Developer account.
- **Workspaces:** find any account by name or e-mail, see its plan and history, move it to Pro (open-ended or until a
  date) or back to Free, with a reason. Every change is recorded in the audit log, and the person sees it at once.

**What you will need to do** (I will give you a step-by-step guide when we reach this part)
1. A Google Play Console developer account (one-time US$25), and the app uploaded to an internal testing track.
2. In Play Console, a subscription called "Qistas Pro" with a monthly price.
3. In Google Cloud, an access key (service account) that Play Console allows to read purchases, and a notification
   topic so Google can tell us about renewals.
4. Paste the key and IDs into the admin's Billing settings. Never send them in chat.

---

## Part 3 — The notifications centre

**Channels**
- **Push notifications** through Firebase: Android first, iPhone ready for when the iPhone app exists.
- **E-mail** through your own mail server. This also means e-mail verification and password reset e-mails actually
  get sent; today they are only written to the server log.
- **SMS:** ready for any provider you choose later; off until then.

**What you control in the admin**
- **Settings for each channel:**
  - Your mail server's details, with *Send me a test e-mail*.
  - The Firebase key, with *Send a test push to my phone*.
  - Push settings for Android and iPhone separately: on/off, sound, vibration, badge count, and the Android
    notification category's name.
- **Every notification type, on or off for each channel:**
  - Instalments due today, overdue instalments, a payment recorded by a teammate, and the weekly summary.
  - Pro started, renewal coming up, payment problem, and purchase waiting for review.
  - New sign-in to your account, and announcements.
  - Password reset and e-mail verification can't be switched off, so nobody gets locked out.
- **Announcements:** write a message once in five languages (English required), choose who gets it (everyone, Free
  or Pro) and how (push, e-mail), preview it and send it. A history keeps what was sent and to how many people.

**What your users get**
- A **Notifications** section in the app's Settings, where each person turns off what they don't want, within what
  you allow.

**What you will need to do**
1. A free Firebase project for Qistas, with an Android app in it (I will guide you). You give me the file Google
   creates for the app (`google-services.json`) as a GitHub secret, and paste the Firebase access key into the admin
   settings.
2. Your mail server's details (host, port, user, password, and the "from" address), entered in the admin settings.
3. SMS: when you choose a provider.

---

## What can't be proven from here

- A real Google Play purchase: it needs your Play Console, a test track and a test account.
- Real push delivery and real e-mail delivery: they need your Firebase project and your mail server.
- The iPhone app: it needs an Apple Developer account.
- How the icon looks on every phone maker's launcher.

Each part is built to Google's documented formats and tested with recorded answers. Your first real run is the proof;
I will give you a short checklist for it.
