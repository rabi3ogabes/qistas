# Buy Pro your way (Win Plan PP13)

Owners can now pay for a plan in two ways, and each way has its own switch for the whole platform in
`/admin/features`. Both switches ship **off**:

| Switch | Where owners pay | Who confirms |
|---|---|---|
| **Pro through Google Play** | In the Android app, monthly or yearly, at Google's price, with the free trial Google offers them | Google: the server checks every purchase with Google before Pro turns on |
| **Pro by bank transfer** | On the website: they transfer, then send the reference and the receipt | The Qistas team in `/admin/billing`, or nobody when automatic approval is on |

The plan belongs to the workspace, not to the phone: a purchase made on one phone shows on the website and on every
other phone. *Restore purchases* brings it to a new phone. The Android app never mentions bank transfer or the website,
as Google Play's payments policy requires. Card payment on the website is not built yet: it waits for the choice of a
payment provider (see the last section).

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

## Not built yet: card payment on the website

Paying by card, mada or Apple Pay on the website needs a payment provider: Moyasar, Tap, HyperPay or Stripe, for
example. Card numbers never touch Qistas; the provider hosts the payment page. Once you choose one and create its
account, the checkout can be added in the same way: a hosted page, a signed notification, and one-time keys so that
nothing is charged twice.
