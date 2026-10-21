# runningcalendar-mec-integration

Integrate the South African Running Calendar with Modern Events Calendar on WordPress.

This plugin imports upcoming races from the [RunningCalendar API](https://api.runningcalendar.co.za/docs) into Modern Events Calendar once a day. Each listing links back to its event page on RunningCalendar, using the `url` field exactly as the API returns it.

## API key and credentials

Contact Running Calendar at [info@runningcalendar.co.za](mailto:info@runningcalendar.co.za) for an API key and credentials. Do not use this plugin against the API without them.

## Terms of use

- Do not modify this code so that it scrapes [runningcalendar.co.za](https://runningcalendar.co.za) without permission from Running Calendar.
- Keep the link back to each event’s RunningCalendar page. Use the `url` field exactly as the API returns it.
- Do not add your own tracking parameters to that URL. Running Calendar adds tracking to those links on their side.
- Leave `rel="noreferrer"` off links back to Running Calendar, so they can see which site the visit came from. `rel="noopener"` is fine.

## Setup

Requires Modern Events Calendar (Lite or Pro), PHP 7.4+, and WordPress 5.8+. The plugin checks `class_exists( 'MEC' )` and `post_type_exists( 'mec-events' )`. While that check fails, wp-admin shows an error notice and no races are imported.

1. Activate MEC, then this plugin. Activation schedules `runningsa_mec_daily_fetch` for five minutes later, then daily. Deactivation clears that hook and leaves imported events in place.
2. Set the key in `wp-config.php`. The constant wins over the settings field:

   ```php
   define( 'RUNNINGSA_API_KEY', 'your key' );
   ```

   **Settings → Running Calendar MEC** can store a key when the constant is absent. A blank field keeps the saved key. "Remove saved key" clears it.
3. Choose provinces. The default is `free-state` and `northern-cape`. One API call is made per selected province, with a one-second pause between provinces. An optional MEC category is added to every imported event.
4. Save, then use **Force fetch now**. That clears the 10-minute response cache and imports immediately. Save province changes before fetching.

The settings page shows the next cron time and the last run (`runningsa_mec_last_run`): time, trigger (`cron` or `manual`), fetched, created, updated, unchanged, and errors. "not scheduled" means the daily event is missing. Deactivate and activate the plugin to register it again. WordPress has to run WP-Cron for the daily hook to fire.

## What an import does

`RunningSA_MEC_Importer::run()` calls `GET https://api.runningcalendar.co.za/v1/events` with `country=ZA`, the province slug, `per_page=100`, and `Authorization: Bearer`. The client follows `links.next` for up to 20 pages, pausing one second between pages. Each response is cached in a transient for 10 minutes. The client spaces calls for a 60-request-per-minute limit.

An event is saved only when it has an id, a name, a `YYYY-MM-DD` date, and an `https://` `url`. A bad url is an error and that event is skipped.

MEC's `save_event()` writes the post, the `mec_*` meta, and the schedule tables. The description lists each race (name, start time, fee) and ends with "View this race on RunningCalendar", using `url` unchanged and `rel="noopener"`. MEC "More info" is the same url, titled "View on RunningCalendar", target `_blank`. An event whose `verification_status` is not `verified` includes a sentence telling readers to check the race page.

Dedupe meta:

| Meta | Role |
|---|---|
| `_runningsa_id` | RunningCalendar event id. |
| `_runningsa_url` | Canonical url, unchanged. |
| `_runningsa_hash` | Hash of the API payload, the selected MEC category, and the plugin version. A match is counted as unchanged. |

A trashed match stays in the trash. An existing event keeps the status an editor set, including `draft`. New events are published. There is no finish time in the API: the start is the earliest race `start_time` (or all-day when no race has a time), and the stored end is the last start plus one hour with the end time hidden. Cost is `From R…` from the lowest `race_fee_from`, or `Free` when that fee is `0`. Locations are `mec_location` terms keyed by town and province (`_runningsa_loc`). Missing town and province uses MEC location id `1`.

A transient `runningsa_mec_lock` (10 minutes) blocks a second import. The message is "An import is already running." That refusal is not written into `runningsa_mec_last_run`.

## Troubleshooting

| What you see | What it means |
|---|---|
| Admin error that MEC is required | MEC is inactive. The cron callback returns the same error. |
| `No API key set.` | Neither `RUNNINGSA_API_KEY` nor a saved key is set. |
| Next run says "not scheduled" | The daily event was cleared. Deactivate and activate the plugin. |
| `An import is already running.` | Wait for the in-flight import to delete the lock, or for the 10-minute transient to expire. |
| A race never reappears after it was trashed | The importer skips trash on purpose. Restore the post if it should update again. |
| Unchanged count stays high | The hash still matches. A different MEC category, plugin version, or API payload produces an update. |
| Force fetch looks stale | Force fetch deletes `runningsa_mec_` transients first. A cron run uses the 10-minute cache. |

The **Logs** tab reads option `runningsa_mec_log` (capped at 500 lines, not autoloaded). Errors are stored even when debug is off. Other lines require the debug checkbox. The logger removes the API key and any `rc_live_…` token from the message before it is stored.
