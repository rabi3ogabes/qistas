package com.qistas.qistas

import android.appwidget.AppWidgetManager
import android.content.Context
import android.content.SharedPreferences
import android.view.View
import android.widget.RemoteViews
import es.antonborri.home_widget.HomeWidgetLaunchIntent
import es.antonborri.home_widget.HomeWidgetProvider
import kotlin.math.abs

/**
 * The home-screen widget: what is due and late today, drawn from strings the Flutter app stored and nothing else.
 *
 * The app (lib/features/glance) formats everything in the app's own language and keeps it in the plugin's preferences
 * under these keys, all strings: `enabled` ("1" / "0"), `rtl` ("1" / "0"), `due_line`, `late_line`, `amount_line`,
 * `next_1` .. `next_3`, `updated` and `updated_epoch_ms`. This class only picks a layout, sets the text and the tap. It
 * does no network, no formatting and no translating, so it cannot show anything the app's privacy rules withheld:
 * an empty string is an empty line.
 *
 * Three states: *showing* (figures stored in the last twelve hours), *open* (feature off, signed out or nothing stored
 * yet: "Open Qistas") and *stale* (figures older than twelve hours: "Open Qistas to refresh", no figures).
 *
 * This is the small widget; [GlanceMediumWidgetProvider] is the medium one.
 */
open class GlanceWidgetProvider : HomeWidgetProvider() {

    /** The medium widget lists the next instalments beside the counts; the small one shows the counts alone. */
    protected open val medium: Boolean
        get() = false

    override fun onUpdate(
        context: Context,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
        widgetData: SharedPreferences,
    ) {
        for (appWidgetId in appWidgetIds) {
            appWidgetManager.updateAppWidget(appWidgetId, draw(context, widgetData))
        }
    }

    private fun draw(context: Context, data: SharedPreferences): RemoteViews {
        val state = stateOf(data, System.currentTimeMillis())
        val rows = if (medium && state == State.SHOWING) listOf("next_1", "next_2", "next_3").map { data.stored(it) } else emptyList()

        // A medium widget with no instalments to list uses the small layout, so the counts get the whole width.
        val wide = rows.any { it.isNotEmpty() }
        val rtl = data.stored("rtl") == "1"
        val views = RemoteViews(context.packageName, layoutFor(wide = wide, rtl = rtl))

        views.setViewVisibility(R.id.glance_message, visibleIf(state == State.OPEN))
        views.setViewVisibility(R.id.glance_stale, visibleIf(state == State.STALE))
        views.setViewVisibility(R.id.glance_content, visibleIf(state == State.SHOWING))

        // One tap target, the whole widget: it opens Qistas, which lands on Today.
        views.setOnClickPendingIntent(R.id.glance_root, HomeWidgetLaunchIntent.getActivity(context, MainActivity::class.java))

        val spoken = ArrayList<String>()
        when (state) {
            State.OPEN -> spoken.add(context.getString(R.string.glance_open))
            State.STALE -> spoken.add(context.getString(R.string.glance_open_to_refresh))
            State.SHOWING -> {
                val updated = data.stored("updated")
                for ((viewId, key) in listOf(R.id.glance_due to "due_line", R.id.glance_late to "late_line", R.id.glance_amounts to "amount_line")) {
                    val line = data.stored(key)
                    views.setTextViewText(viewId, line)
                    views.setViewVisibility(viewId, visibleIf(line.isNotEmpty()))
                    if (line.isNotEmpty()) spoken.add(line)
                }
                views.setTextViewText(R.id.glance_updated, updated)
                views.setViewVisibility(R.id.glance_updated, visibleIf(updated.isNotEmpty()))

                if (wide) {
                    for ((viewId, line) in listOf(R.id.glance_next_1, R.id.glance_next_2, R.id.glance_next_3).zip(rows)) {
                        views.setTextViewText(viewId, line)
                        views.setViewVisibility(viewId, visibleIf(line.isNotEmpty()))
                        if (line.isNotEmpty()) spoken.add(line)
                    }
                }
                if (updated.isNotEmpty()) spoken.add(context.getString(R.string.glance_updated_at, updated))
            }
        }

        // The lines read aloud as one sentence-by-sentence summary; the separator dot of a row is a pause, not a word.
        views.setContentDescription(R.id.glance_root, spoken.joinToString(". ") { it.replace(" · ", ", ") })

        return views
    }

    private enum class State { SHOWING, OPEN, STALE }

    private fun stateOf(data: SharedPreferences, now: Long): State {
        // Anything but a plain "1" is the open state: "0" (feature off), nothing stored yet, or something unreadable.
        if (data.stored("enabled") != "1") return State.OPEN

        val storedAt = data.stored("updated_epoch_ms").toLongOrNull() ?: return State.STALE

        // A clock that was moved either way past the limit also makes the figures untrustworthy.
        return if (abs(now - storedAt) > STALE_AFTER_MS) State.STALE else State.SHOWING
    }

    private fun layoutFor(wide: Boolean, rtl: Boolean): Int = when {
        wide && rtl -> R.layout.glance_medium_rtl
        wide -> R.layout.glance_medium
        rtl -> R.layout.glance_small_rtl
        else -> R.layout.glance_small
    }

    private fun visibleIf(shown: Boolean): Int = if (shown) View.VISIBLE else View.GONE

    /** One stored string, trimmed; empty when it was never stored (or was stored as something else). */
    private fun SharedPreferences.stored(key: String): String = (all[key] as? String)?.trim() ?: ""

    private companion object {
        /** Figures older than this are not shown as today's: the widget asks the person to open Qistas instead. */
        const val STALE_AFTER_MS = 12L * 60L * 60L * 1000L
    }
}

/** The medium widget: the same strings, with the next instalments listed beside the counts. */
class GlanceMediumWidgetProvider : GlanceWidgetProvider() {
    override val medium: Boolean
        get() = true
}
