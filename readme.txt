=== Running Calendar integration with MEC ===
Requires plugins: modern-events-calendar-lite (or Pro)
Stable tag: 1.0.0
License: GPLv2 or later

Imports upcoming races from the RunningCalendar API (https://api.runningcalendar.co.za/docs) into Modern Events Calendar once a day.

== Setup ==
1. Upload and activate (MEC must be active).
2. Add to wp-config.php:  define( 'RUNNINGSA_API_KEY', 'your key' );
   (or paste the key in Settings > Running Calendar MEC). The constant wins.
   A blank settings field keeps the saved key.
3. Pick provinces (default Free State + Northern Cape), optional MEC category, then "Force fetch now".

Activation schedules runningsa_mec_daily_fetch (first run in 5 minutes, then daily).
Deactivation removes the cron job and leaves imported events in place.

== Import behaviour ==
* One API call per province to GET /v1/events (country=ZA, per_page=100), Bearer token, 10-minute cache, 1 second between calls.
* Events are matched on post meta _runningsa_id. An unchanged payload is skipped via _runningsa_hash.
* An event needs an https url. That url is stored unchanged on MEC "More info" and in the description (rel="noopener" only).
* Trashed events stay trashed. Existing drafts stay drafts. New events are published.
* A second import within 10 minutes is refused ("An import is already running.").
* Errors are always logged. Turn on debug under Settings > Running Calendar MEC > Logs for the rest. The log hides the API key.

== Terms ==
* Do not modify this plugin to scrape runningcalendar.co.za.
* Do not add tracking parameters to the event url. Leave rel="noreferrer" off the link back.
