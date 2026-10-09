# Competitor review and the road to the app stores

Written 9 Oct 2026. Companion to [`QISTAS_FEATURES_BRIEF.md`](QISTAS_FEATURES_BRIEF.md): the brief says what Qistas will do; this note says
what a rival already does, where Qistas can beat it, and what stands between Qistas and a ranked store listing.

## 1. What was read, and what could not be seen

Read in full: the competitor's product page, its user guide and four help articles
(<https://omar1985.com/explanations/installment-and-accounting.html> and the pages it links to). The app is
**"تقسيط ومحاسبة" (Installment and Accounting)** by omar1985.com.

Not seen: its store listing. A web search found no Google Play page for it, so **its rating, download count and store rank are
unknown to me**. Other rivals that did show up in the search (App Store listings only, not examined): *أقساط (Aksat)* for shop
owners and collectors, *قسطي*, and *فاتورتك (Your Invoice)*. A real market scan (ranks, ratings, reviews, in Saudi Arabia, the UAE,
Egypt and Iraq) needs the stores themselves or an ASO tool; it is the first thing to do before spending on launch.

## 2. What the competitor does (as its own pages state)

| Area | Detail |
|---|---|
| Platform | **Android only.** It runs on a PC only through an Android emulator (the guide says 4 GB of RAM). No web, no iOS. |
| Where data lives | **On the phone.** Free: ads, a backup file you must move yourself. Pro: one-time purchase, no ads, automatic Google-account backup. "Pro with Online": subscription through Google Play, server storage, several devices, up to **three** members, daily backups, a deletion log. Uploading local data to the server is manual. |
| Model | **Investors** (the people who fund a contract) and **customers**; a customer can hold contracts with several investors. Investor account with deposits and withdrawals. |
| Contracts | Three kinds: **scheduled** (fixed instalments), **open** (no schedule, payments as they come), **cash** (one or a few payments). Convert to open, archive, edit. |
| Entries | Each payment is "له" (customer paid, green) or "عليه" (customer owes, red). Badges: down payment, refunded, **early-settlement discount**, unpaid. |
| Reports | Customer report and contract report, shared as a file or printed. Late payers list, with notifications. Sends a report or opens WhatsApp to the customer. |
| Trust features | **Biometric app lock**, **encrypted backups** that reject edits, **conflict protection** between devices, who created and last edited each entry, an **emergency mode** when its server is down, and **account deletion inside the app**. |
| Support | "Up to a week". Backup copies: one per account per month after identity checks. |

**Where it is weak:** the books live on one phone (a lost phone is a lost business unless a backup exists); Android only; no web
console or dashboard described; only two named reports; no scoring, forecast, document templates, e-signature or
automatic reminders; slow human support; and its page admits it needs a mid-to-high-end phone.

## 3. Where Qistas is already ahead

Built and live: web, Android and (planned) iOS on one cloud ledger that survives a lost phone; five languages with proper
right-to-left; roles (owner, manager, accountant, collector, viewer) instead of "up to three members"; an immutable ledger with an
audit trail; a dashboard briefing with what needs the owner today; WhatsApp, SMS and call reminders in one tap; plan limits and an
admin console with per-feature Off/Beta/On switches; PDFs with Arabic and QR codes (renderer built; templates next).

Designed in the brief but not built yet: payday and Hijri-aware schedules, guarantors, e-signed contract PDFs, proof of payment,
cheques, automatic WhatsApp reminders with one-tap "promise to pay", **Qistas Score** and a likely-late radar, cash-flow forecast,
no-dues certificate, exports and accountant pack, branches, collector routes, **photo-of-notebook import** and an Arabic voice assistant.

## 4. What to take from the competitor (not in the brief yet)

| # | Idea | Why it matters | Effort |
|---|---|---|---|
| 1 | **Delete my account** inside the app and on the web | Google Play and Apple both **require** it. Without it a store review can reject the app. | S |
| 2 | **App lock** (fingerprint, face, device PIN) | A money app that opens straight to customers' balances feels unsafe; the competitor advertises it. | S |
| 3 | **Investor / funder ledger** | Many Arab lenders lend pooled money: deposits, withdrawals, which contracts an investor funded, and each one's share of the profit. A real, under-served need. | M |
| 4 | **Open account** contract type (دفتر حساب مفتوح) with له / عليه lines | Shopkeepers who still keep a paper notebook of running balances. It is the doorway for the largest group of non-users. | M |
| 5 | **Push and daily-briefing notifications** | "3 instalments are due today" at 9 a.m. brings owners back every day; this is the single biggest retention lever. | M |
| 6 | **Archive** for settled and cancelled contracts, and an **activity log** the owner can read ("who deleted what") | The audit data exists; showing it is a trust feature. | S |
| 7 | **"Your data is yours"**: one-tap full export (Excel and PDF) and a monthly backup file e-mailed to the owner | Answers the competitor's backup anxiety head-on and costs little. | S |
| 8 | **Works offline in the field** (the brief's G4, brought forward as a simple read-only cache plus queued receipts) | Collectors work where the signal is bad. Payments already carry an idempotency key, so retries are safe. | M |
| 9 | **Ask for a rating at a happy moment** (after the third recorded payment, once, never after an error) | Ratings drive store rank; timing decides whether they are 5 stars. | S |

## 5. What stands between today and a ranked store listing

| Blocker | Who | Status |
|---|---|---|
| Release signing: the APK is signed with a **debug key**. Play needs an upload key and Play App Signing | owner creates the keystore secret, I wire the build | open |
| **iOS build**: none exists. Needs an Apple Developer account (paid yearly) and a Mac build runner (GitHub macOS or Codemagic) | owner buys the account, I add the build | open |
| Developer accounts: Google Play Console (one-time fee) and Apple Developer | owner | open. A new personal Play account must also run a **closed test with enough testers for two weeks** before production; check Google's current rule |
| In-app **account deletion** and a public deletion page (idea 1) | me | open |
| **Subscriptions inside the app**: both stores require their own billing for digital subscriptions. Today the app shows no purchase link (correct), so Pro is activated on the website | me, after the owner opens a billing account | open. Recommended later: RevenueCat |
| Store paperwork: privacy policy URL, Data safety (Play) and App Privacy (Apple) forms, age rating, support URL, terms | owner reviews, I draft | open |
| Quality bar the stores measure: crash-free sessions above 99.5 %, no ANRs, fast start, a smaller download (an app bundle instead of one 58 MB APK) | me | open |
| Listing assets: localized screenshots, feature graphic, 20-second video | I design, owner approves | open |

## 6. Store listing (first drafts; native Arabic review needed before submitting)

Character counts are checked.

| Field | Arabic | English |
|---|---|---|
| Play title (30) | قسطاس: تقسيط وديون ومحاسبة | Qistas: Installments & Debts |
| Play short description (80) | دفتر التقسيط الذكي: عقود وأقساط وتحصيل وتذكيرات واتساب للتجار والمقرضين | Smart instalment ledger: contracts, collections and WhatsApp reminders for shops |
| App Store name (30) | قسطاس: تقسيط وديون | Qistas: Installment Tracker |
| App Store subtitle (30) | عقود وأقساط وتذكيرات واتساب | Contracts, dues & reminders |
| App Store keywords (100) | تقسيط,أقساط,دين,ديون,محاسبة,تحصيل,عقود,عملاء,دفتر,مبيعات,كمبيالة,سداد,مستثمر,فاتورة,إيصال,متأخرات | installment,instalment,credit,debt,ledger,collections,lending,loan,customers,invoice,receipt |

**Screenshot story (six, in each language):** 1 today's briefing ("كل قسط في موعده"); 2 one-tap WhatsApp reminder; 3 a contract with its
schedule; 4 recording a payment and the receipt; 5 the late list and forecast; 6 Arabic right-to-left and dark mode.
Lead with the Arabic listing for Saudi Arabia, then the UAE, Egypt and Iraq, then English, French, Spanish and Urdu.

## 7. What "number 1" can honestly mean

No one can promise first place: rank in a store follows installs, how fast they arrive, ratings, retention and uninstalls, and
the rivals' own strength; none of that can be bought or set from the code. What can be done is to remove every reason to be
rejected or rated down, ship the features that make owners open the app daily, and measure. A realistic target is a **top-10
place in Finance for the keywords تقسيط, أقساط and دفتر ديون in one country within 90 days of launch**, then widen. Weekly numbers
to watch: installs per day, day-1 and day-7 retention, crash-free rate, rating and review count, and conversion from free to Pro.

## 8. Suggested order

1. Delete account, app lock, archive and activity log (ideas 1, 2, 6): small and they remove store-review risk.
2. Release signing, app bundle, localized store assets, privacy forms (owner steps listed above).
3. Daily briefing notifications and the rating prompt (5, 9).
4. Open account and the investor ledger (3, 4): the two features no rival in this group handles well.
5. iOS build and in-app subscriptions once the accounts exist.
6. The brief's Milestones M1 to M3 continue in parallel.
