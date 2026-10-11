# Help, what's new, the calculator and the installable web app (Win Plan PP17, PP15, PL04)

## Help inside the app

**Settings → Get help** (also under the **+** button) gives people:
- short answers they can search in their own language;
- **Message us on WhatsApp**, which opens a chat with the support number. The message carries the workspace's
  support code (for example `Q-3F9A2C`) and the app version, never a password;
- **Write to us**, which sends a ticket from inside the app with the app version and the platform;
- the promise: a first reply within 24 hours, and within 4 hours for a report of missing data;
- their own questions, with the team's replies.

The team works the queue in **`/admin/support`**. Reports of missing data come first. Each ticket shows how long it has
waited, and turns red once it is past the promise. A reply goes to the person's inbox in the app and to their phone,
in their language. Any platform staff member can reply or close a ticket.

**What you set up:** in `/admin/support`, enter the support WhatsApp number in international form (only a super admin
can). Until a number is saved, people write in the app instead.

The support code is the random end of the workspace's id. A WhatsApp message gives it to you; the matching ticket in
`/admin/support` shows the same code beside the business's name.

## What's new

After each update the app shows, once, what changed since the version it last showed. A first install shows nothing.
Staff write the notes per version in **`/admin/release-notes`**: English is required, as it is shown wherever a
translation is missing. Notes appear only once they are published.

**What you set up:** before each release reaches people, write and publish its notes. For this release that is
**1.16.0**: help inside the app, a calculator under the + button, and the website's instalment calculator.

## The calculator

- **In the app:** the + button → *Calculator*. Type the price, the down payment, the profit and the number of
  instalments to see each instalment, the total and the last date. Nothing is saved. *Create a contract from this*
  opens a new contract with the same numbers and the same schedule.
- **On the website:** `/installment-calculator` in five languages (`?lang=ar` and the others), linked from the
  header, the footer and the sitemap. The numbers come from the same engine that writes contracts. The page's address
  holds the plan, so a copied link opens with the same numbers. The page's questions and answers are also given to
  search engines as FAQ data.

## The installable web app

The web app at `/app` can be installed from the browser (*Add to home screen*, or the install icon in the address
bar). It opens in its own window with the Qistas icon. A small service worker keeps only the app's own files (the
built styles and scripts, and the brand pictures) plus an offline page. It never keeps a page or an answer with
business data in it.
