# Buy Pro your way (Win Plan PP13)

Owners can pay for a plan in three ways, and each way has its own switch for the whole platform in
`/admin/features`. All three switches ship **off**:

| Switch | Where owners pay | Who confirms |
|---|---|---|
| **Pro through Google Play** | In the Android app, monthly or yearly, at Google's price, with the free trial Google offers them | Google: the server checks every purchase with Google before Pro turns on |
| **Pro by bank transfer** | On the website: they transfer, then send the reference and the receipt | The Qistas team in `/admin/billing`, or nobody when automatic approval is on |
| **Pro by card** | On the website, inside Qistas: card, Apple Pay or Google Pay, monthly or yearly, at the plan's own price, with a free trial you set | Stripe: the plan follows Stripe's payments, renewals, failures and cancellations |

The plan belongs to the workspace, not to the phone: a purchase made one way shows on the website and on every phone.
*Restore purchases* brings a Google Play purchase to a new phone. Only one way runs at a time: while a workspace pays
one way, the others are not offered. The Android app never mentions bank transfer, cards or the website, as Google
Play's payments policy requires: a plan paid outside the store shows there only as "Renews 9 Dec 2026" or "Until …".

## What you set up

### 1. The prices

The website and the bank transfer form use the plan prices in the admin area (Pro is 12 / 120 USD today). The app shows
Google's own prices. Set the same price in both places for each country.

### 2. Bank transfer

1. Open `/admin/billing` → *How businesses pay* → *Bank transfer*.
2. In *Where owners transfer to*, write the bank, the account name and the IBAN, exactly as owners should see them.
3. Choose whether a plan turns on **as soon as the receipt arrives** (automatic approval) or **when the team approves
   it** (the default).
4. In `/admin/features`, switch on **Pro by bank transfer**.

Each request waits in `/admin/billing` with its receipt. *Approve* puts the business on the plan for the period paid. A
renewal paid before the end is added to the time left. *Reject* needs a reason, which the owner sees. The owner hears
the decision in the app's inbox and as a push. Receipts (JPG, PNG or PDF, up to 5 MB) are kept in the database: file
storage on Vercel is not permanent.

### 3. Google Play

The app's package name is `com.qistas.qistas`.

1. **Subscription.** Play Console → *Monetize* → *Subscriptions* → create the subscription `qistas_pro` with two base
   plans: `monthly` (renews every month) and `yearly` (every year). To offer the 14-day free trial, add an offer to each
   base plan with a free phase of 14 days and eligibility *New customer acquisition*.
2. **Service account.** In Google Cloud, create a service account (for example `qistas-play@…`) and a JSON key for it.
   In Play Console → *Users and permissions*, invite its e-mail address with *View financial data* and *Manage orders
   and subscriptions*. Paste the JSON file into `/admin/billing` → *Google Play* → *Service account*. It is stored
   encrypted and never shown again.
3. **Notifications.** In Google Cloud Pub/Sub, create a topic (for example `play-billing`). Give
   `google-play-developer-notifications@system.gserviceaccount.com` the *Pub/Sub Publisher* role on it. In Play Console
   → *Monetization setup* → *Real-time developer notifications*, enter the topic's full name. Then create a **push**
   subscription on the topic:
   - endpoint: `https://<your site>/webhooks/google-play`
   - authentication: on, with a service account of your choice (for example `pubsub-push@…`)
   - audience: the same endpoint address

   In `/admin/billing`, put that address under *Notification audience* and that service account's e-mail under
   *Notification service account*. A message signed by anyone else is refused.
4. In `/admin/billing`, fill in *Package name* (`com.qistas.qistas`) and *Subscription ID for Pro* (`qistas_pro`).
   The page then says *Ready to sell in the app*.
5. Put app 1.15.0 on a testing track, add your Google account as a license tester, and buy once to check.
6. In `/admin/features`, switch on **Pro through Google Play**.

What happens: the app buys using an account reference made from the workspace, so a purchase can only ever be attached
to that workspace. The server asks Google about the purchase and acknowledges it (otherwise Google would refund it after
three days). Google's notifications then renew, cancel, refund, hold and expire it. Each notification is applied once,
and always by asking Google again what is true now.

| Google says | The workspace |
|---|---|
| Active, or in a free trial | On the plan until the period Google reports |
| In grace (a renewal failed, Google retries) | Keeps the plan |
| Canceled (renewal turned off) | Keeps the plan to the end of the period paid |
| On hold, paused, expired | Back on Free (nothing is deleted) |
| Refunded or revoked | Back on Free at once |

### 4. Cards on the website (Stripe)

Owners upgrade without leaving Qistas: the checkout page is Qistas' own, and only the card fields are Stripe's, in
Qistas' colours. Card numbers never reach Qistas' servers. Design: `docs/superpowers/specs/2026-10-27-stripe-card-billing-design.md`.

**Setting it up** (about two minutes once the Stripe account exists):

1. Create a Stripe account for the company that sells Qistas, in a country Stripe supports for businesses (the United
   Arab Emirates is; Saudi Arabia, Qatar, Kuwait, Bahrain, Oman, Egypt and Morocco are not, today).
2. In Stripe → *Developers* → *API keys*, copy the **test** secret key and publishable key. Paste both into
   `/admin/billing` → *Card payments (Stripe)* and press **Save and connect**. Qistas checks the account, makes the
   webhook for `https://<your site>/webhooks/stripe` with the events it follows, keeps its signing secret, and registers
   the site's domain for Apple Pay and Google Pay. Nothing is set up by hand in Stripe. The panel then reads *Ready to
   take cards · Test mode*. If Stripe refuses a key, its own message shows and nothing is saved.
3. Choose the free trial (14 days by default, 0 for none; each workspace has one trial, ever), and whether owners can
   enter promotion codes (made in Stripe), give a tax number, or have tax worked out by Stripe Tax. Prices include tax.
4. Check *Price check*: every paid plan's monthly and yearly price must read *Ready*. A price Stripe cannot charge (for
   example KWD with four decimals) says why.
5. In `/admin/features`, switch on **Pro by card**, then buy once on the website with the test card
   `4242 4242 4242 4242` (any future date, any CVC).
6. In Stripe → *Billing* → *Revenue recovery*: turn on Smart Retries and choose **cancel the subscription** after the
   last retry. Optional: Stripe's own customer e-mails.
7. When Stripe has verified the account, paste the **live** keys and press **Save and connect** again. Test customers are
   never reused with live keys.

*Connect again* makes a new webhook (for example after the site moves to a new address). *Disconnect* deletes the
webhook and forgets the keys; subscriptions already running keep renewing in Stripe, but Qistas stops hearing about them
until you connect again. Locally, `STRIPE_SECRET`, `STRIPE_PUBLISHABLE_KEY` and `STRIPE_WEBHOOK_SECRET` in `web/.env`
win over the saved keys (for example with `stripe listen --forward-to http://localhost:8000/webhooks/stripe`).

**What owners get.** *Plan and billing* shows the plan, the next charge, the card (with a way to change it), switching
between monthly and yearly (to yearly at once with the unused time credited, to monthly at the end of the year), cancel
and resume, receipts as PDFs, and a way to confirm a payment their bank holds. After paying they land on a welcome page,
already on Pro, without waiting for Stripe's message. Two tabs paying at the same moment, or a Play purchase in between,
end in one subscription: the second is cancelled at once and refunded, and the owner is told.

**What happens when Stripe says so.** Each Stripe message is checked against its signature, applied once, and always
read again from Stripe before anything changes:

| Stripe says the subscription is | The workspace |
|---|---|
| `trialing` | On the plan until the trial ends |
| `active` | On the plan until the period's end |
| `past_due` (a renewal failed, Stripe retries) | Keeps the plan |
| `incomplete` (the checkout is still being paid) | Nothing changes |
| `incomplete_expired`, `canceled`, `unpaid`, `paused` | Back on Free, nothing deleted |

**Notices** (in the app's inbox, as a push, and by e-mail where the owner allows it):

| When | The owner reads |
|---|---|
| Three days before a trial ends | Your Pro trial ends on 9 Nov; your card will be charged 120.00 USD. |
| A renewal is paid | Pro renewed until 9 Dec 2027. |
| A renewal fails | We couldn't charge your card for Pro. Update it in Billing; Pro stays on meanwhile. |
| The bank asks to confirm a payment | Your bank asks you to confirm the Pro payment in Billing. |
| The subscription ends | Pro has ended; your workspace is on Free. Nothing was deleted. |
| A second payment was refunded | Your workspace already had Pro, so the new card subscription was cancelled and its payment refunded. |

**For the team.** Business pages show *Card (Stripe)*, the status, monthly or yearly, the next charge or the end, the
trial end, and a link to the subscription in Stripe's dashboard. A super admin can **cancel the card subscription**: now,
refunding the part of the last payment not used yet (the business moves to Free), or at the end of the period. While a
card subscription runs, changing the plan by hand is refused, so Stripe never goes on charging for a plan that no longer
follows it.
