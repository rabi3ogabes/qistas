# The home-screen widget (daily-habit features, H3)

Today's money on the phone's home screen, without opening Qistas: how many instalments are due today, how many are late,
how much, and the next few. **Android only** for now (the app reads the glance and keeps a snapshot nowhere else).

## What the owner gets

Two widgets in the phone's widget list, both named for Qistas:

- **Qistas today** (small, about 2 cells): "Due today: 3", "Late: 2", the two amounts, and the time it was last updated.
- **Qistas today and next** (medium, about 4 cells): the same, with the next three instalments beside it. When there is
  nothing to list (amounts hidden, or the app lock on) the medium widget is drawn like the small one, so the counts get
  the whole width.

Both come in light and dark (they follow the phone), in the app's own colours (ivory, navy, gold), with the amounts in a
serif face. They read left to right or right to left by the **app's** language, not the phone's. The whole widget is one
tap: it opens Qistas on Today.

## The admin switch

`home_widget`, in admin → Features, group Collecting. It ships off. Switch it on when you are ready.

Switched off (for the platform, or because the plan lacks it), nothing is deleted:

- `GET /api/v1/glance` answers `200 {"data": {"enabled": false}}` instead of an error, so a widget that is already on a
  home screen can fall back gracefully.
- The app writes that state to the widget once (it shows "Open Qistas") and stops reading; the "Home-screen widget" row
  disappears from Settings.
- The morning push stops (checked when it runs, not when it was queued).

## What it shows, and what it never shows

| Shown | When |
|---|---|
| Instalments due today, and late | always (counts) |
| The amounts due and late | the owner's "Show amounts" is on (default) and the app lock is off |
| The next three instalments: contract number, amount still owed, due date | same as amounts |
| The customer's name in place of the contract number | the owner's "Show customer names" is on (default **off**), amounts are shown, the app lock is off |
| The time the figures were read | always |

It never shows a phone number, a national ID, an address, a contract total or balance, a payment history or the
business's name. The server does not even send them: `GET /glance` holds counts, amounts, contract numbers and dates, and a
customer's name only when the app asks for names (which it does only when the rule above allows them).

## The privacy rules

Anyone who picks up the phone can read a home screen, so the widget is private by default and the app lock wins.

- **App lock on** (the person turned it on, or the business requires it): only the counts reach the widget. Amounts, rows
  and names are blank, whatever the two switches say. The stored choices are kept, so they apply again when the lock goes.
- **Names** need amounts: with amounts hidden, names are hidden too.
- The rule is written once, as `GlancePrivacy.amountsShown` / `namesShown`, and applied once, in `encodeGlance`. The request
  to the server asks for names under the same rule, so a name never travels to the phone when it may not be shown.
- Turning the lock on, changing a switch or changing the language redraws the widget at once, even with no connection. The
  redraw keeps the time the figures were read; a new language does not make old figures fresh.
- Signing out (or a session the server ended) empties the stored snapshot first, before the token and the account are
  forgotten.
- The widget is offered for the **home screen only** (`widgetCategory="home_screen"`), never for a locked screen.
- The background refresh's log says only how a run ended ("the widget was refreshed", "no connection", ...); it never holds a
  token, a name or an amount.

## The three states

All text comes already written, in the app's language, by the app (`lib/features/glance`). The Android code
(`GlanceWidgetProvider.kt`) formats and translates nothing; an empty string is an empty line.

| State | When | The widget says |
|---|---|---|
| Showing | `enabled` is "1" and `updated_epoch_ms` is within 12 hours of the phone's clock | the stored lines |
| Open | `enabled` is "0" (feature off), or nothing was ever stored (not signed in, just installed), or anything unreadable | **Open Qistas** |
| Stale | `enabled` is "1" but the figures are more than 12 hours old (or the clock was moved more than 12 hours either way) | **Open Qistas to refresh**, muted, with no figures |

In the *disabled* state the app also stores "Open Qistas to refresh" in `due_line`; the widget deliberately **ignores
it** and shows its own "Open Qistas" resource, the same as the never-stored state. These two native lines (and the widget's
names in the widget list) are in `res/values*` in English, Arabic, French, Spanish and Urdu, and follow the **phone's**
language, since the widget cannot know the app's language when it has nothing stored.

## When it is refreshed

| What | Where |
|---|---|
| The dashboard loads, a payment is recorded, the app comes back after more than two minutes away | in the app (`GlanceService`) |
| The privacy lock, a privacy switch or the language changes | in the app, from the last answer, even offline |
| **Each morning**, at the person's morning-summary time on the business's clock | a silent push from the server (`qistas:send-glance-refresh`, data only, `type` = `glance.refresh`) |
| About every 30 minutes | Android redraws the stored strings (`updatePeriodMillis` is 1 800 000; Android may delay it). This is what notices "stale". It does not call the server |

The morning push wakes a fresh background isolate even when Qistas is closed: `qistasBackgroundMessage`
(`lib/core/push/background.dart`), registered in `main()` with `FirebaseMessaging.onBackgroundMessage`, runs
`GlanceRefresher` (`lib/features/glance/glance_refresher.dart`) only for `glance.refresh`. It has no Riverpod and no
screens, so it reads what the app left on the phone and does what the open app does, with the same encoder and rules:

1. The token from the secure store. None: it does nothing.
2. The preferences (`language`, `glance.show_amounts`, `glance.show_names`, `app_lock.enabled`) and the account the app last
   saw. "App lock on" is the phone's own choice **or** the business's `require_app_lock`; if the account cannot be read
   the lock is taken to be **on** (counts only).
3. `GET /glance` (names only when the rules allow them), then the same `encodeGlance`, store write and redraw. If the
   person signed out while the answer was on its way, the answer is dropped.
4. No connection, or a server error: the widget keeps what it has. A 401 (the server no longer knows the token) empties the
   widget, as a sign-out would.

The lock and the business's requirement are read from the account the app last saw, as the app itself does until it next
asks the server: a business that switched the requirement on is seen by the widget once the app has been opened.

**Viewers get no morning push** (the server sends it to the owner, managers, accountants and collectors, like the morning
summary). A viewer's widget is refreshed when the app is opened or resumed, and goes stale after 12 hours.

What the push cannot do: a payment recorded on another phone does not wake this one (the widget catches up at the morning
push, or when the app is opened). Android may also hold or drop a silent push (battery saver, a force-stopped app, no
network), and without the owner's Firebase project in the build (see `alerts.md`, "Turning pushes on") there is no push at
all. The "updated" time on the widget is the honest signal.

## Tapping the widget

A tap anywhere opens Qistas through the home_widget plugin's launch intent, with no address attached. Opened from closed,
the app starts on Today anyway. Already open (in the background) on another section, the shell hears the tap
(`GlanceStore.taps`, the plugin's `widgetClicked`) and goes to Today. With the app lock on, the lock screen comes first, as for
any return to the app.

## How the pieces fit (for developers)

The app and the widget share the key contract below, all strings, in the plugin's `HomeWidgetPreferences`:

| key | value |
|---|---|
| `enabled`, `rtl` | "1" / "0" |
| `due_line`, `late_line` | "Due today: 3", "Late: 2" in the app's language |
| `amount_line` | "Due SAR 825.00 · Late SAR 400.00"; empty when amounts are hidden or the lock is on |
| `next_1` .. `next_3` | "Ahmad Salem · SAR 275.00 · Oct 7"; empty when hidden, locked, or there is no such row |
| `updated`, `updated_epoch_ms` | "09:05" and the same moment in epoch milliseconds, from the phone's clock when it was read |

- Two provider classes, `GlanceWidgetProvider` (small) and `GlanceMediumWidgetProvider` (medium), because the plugin
  redraws the instances of the class it is asked for: `HomeWidgetStore.providers` asks both.
- Four layouts (`glance_small`, `glance_medium`, each with an `_rtl` twin) chosen by the stored `rtl`. The direction is set in the
  layout, and the manifest says `android:supportsRtl="true"`, which Android requires before it honours a layout direction.
- `RemoteViews` can only inflate a short list of view classes, so the layouts use `LinearLayout` and `TextView` only.
- Colours are the brand tokens (`lib/core/design/tokens.dart`) in `res/values/glance_colors.xml` and `values-night`.
- The business's own look from the admin site does not reach the widget; it is always Qistas.
- No Kotlin or XML here is compiled or run by the Dart tests: `flutter test` proves the app side and the refresher; the CI
  Android build proves the native side compiles; only a phone proves how it looks.

## Manual device checklist

Needs the owner's phone with the build from the **Mobile app** workflow, the feature switched on in the cockpit, and
signed in as someone with a few instalments due today and one late.

1. **Add both.** Long-press the home screen → Widgets → Qistas. Add "Qistas today" and "Qistas today and next". Both show
   the counts and the time. The medium one lists up to three instalments (contract numbers, not names).
2. **Resize.** Make each larger and smaller, within the limits the phone allows. Nothing is cut off at the smallest size
   (with long Arabic lines or names, the medium rows wrap to a second line).
3. **Tap.** Tap each: Qistas opens on Today. Open Customers, go home, tap the widget again: it lands on Today.
4. **Dark mode.** Switch the phone to dark: the widgets turn navy with a light ink and a lighter gold; switch back.
5. **Arabic.** In Qistas, switch the language to Arabic: the widget redraws at once, reads right to left, with the app's
   Arabic lines. Do the same with the phone in English and the app in Arabic, and the reverse. Try Urdu.
6. **Privacy switches.** Settings → Home-screen widget: turn "Show customer names" on (rows show names), then "Show amounts" off (the
   amounts and rows go blank, names too). Turn them back.
7. **App lock on, then off.** Turn the app lock on: the widget drops to the counts only, immediately. Turn it off: amounts
   come back (the app reads again). Repeat with the business requiring the lock (Settings → App lock).
8. **Airplane mode.** Turn on airplane mode and open the app, change a privacy switch or the language: the widget is
   redrawn from the last answer, never emptied by the failure.
9. **Stale.** With airplane mode on, change the phone's clock forward by 13 hours (or wait): at the next redraw (within
   about 30 minutes, or after removing and re-adding the widget) it shows "Open Qistas to refresh" and no figures. Set the
   clock back, open the app: the figures return.
10. **Sign out.** Sign out in the app: the widget turns to "Open Qistas" at once. Sign back in: the figures return.
11. **Switch the feature off.** In the cockpit (admin → Features) switch `home_widget` off and open the app: the widget
    shows "Open Qistas" and the Settings row is gone. Switch it back on and open the app.
12. **The morning push.** Close the app (swipe it away). At the person's morning-summary time (or after running
    `php artisan qistas:send-glance-refresh` when it is that time for them), the "updated" time on the widget moves
    without opening the app. Nothing is shown in the notification shade.
13. **A viewer.** Sign in as a viewer: the widget is only refreshed when the app is opened.
