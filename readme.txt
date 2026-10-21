=== Running Calendar integration with MEC ===
Requires plugins: modern-events-calendar-lite (or Pro)
Stable tag: 1.0.2
License: GPLv2 or later

Imports upcoming races from the RunningCalendar API (https://api.runningcalendar.co.za/docs) into Modern Events Calendar.

== Setup ==
1. Upload and activate (MEC must be active).
2. Add to wp-config.php:  define( 'RUNNINGSA_API_KEY', 'your key' );
   (or paste the key in Settings > Running Calendar MEC).
3. Pick provinces (default Free State + Northern Cape), how often to fetch (6 hours, 12 hours, 1 day or 1 week), optional MEC category, then "Force fetch now".
4. To add one race, paste its slug under "Import one race".

Races Running Calendar reports as no longer available are marked cancelled in MEC.

Every event links back to its RunningCalendar page using the API "url" unchanged
(MEC "More info" button + a link in the description), as agreed with RunningCalendar.
