=== Running Calendar integration with MEC ===
Requires plugins: modern-events-calendar-lite (or Pro)
Stable tag: 1.1.1
License: GPLv2 or later

Imports upcoming races from the RunningCalendar API (https://api.runningcalendar.co.za/docs) into Modern Events Calendar.

== Setup ==
1. Upload and activate (MEC must be active).
2. Add to wp-config.php:  define( 'RUNNINGSA_API_KEY', 'your key' );
   (or paste the key in Settings > Running Calendar MEC).
3. Pick provinces (default Free State + Northern Cape), how often to fetch (6 hours, 12 hours, or 1 day; weekly is no longer offered, because daily or more often is required by Running Calendar’s terms), optional MEC category, then "Force fetch now".
4. To add one race, paste its slug under "Import one race".
5. "Resync events" rewrites imported races so they store canonical_url. Links keep the tracked url.

Races Running Calendar reports as no longer available are marked cancelled in MEC.
Each event shows the API summary, organiser, and event logo. The event logo is loaded from image_url and is not copied.
By default a “Race data from RunningCalendar” credit with the RunningCalendar logo is shown on each imported event, linked with the tracked url. Turn this off in settings if needed. Existing events show it immediately, without a re-import.

By default, Restrict data export is on: unauthenticated REST requests do not expose imported events or their meta (logged-in administrators, editors, and the MEC editor still can). The public MEC iCal feed (/?mec-ical-feed=1) is disabled for visitors, site-wide (MEC has no per-event filter for that feed). On imported events, Add to Google Calendar and iCal / Outlook export are hidden and their download links are blocked for visitors. Imported events are left out of RSS feeds. Event pages stay public.

Every event links back to its RunningCalendar page using the API "url" unchanged, including its tracking parameters (MEC "More info" button and a link in the description). Match events on id or canonical_url, not on url.
