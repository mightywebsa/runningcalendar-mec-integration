=== RunningSA integration with MEC ===
Requires plugins: modern-events-calendar-lite (or Pro)
Stable tag: 1.0.0
License: GPLv2 or later

Imports upcoming races from the RunningCalendar API (https://api.runningcalendar.co.za/docs) into Modern Events Calendar once a day.

== Setup ==
1. Upload and activate (MEC must be active).
2. Add to wp-config.php:  define( 'RUNNINGSA_API_KEY', 'your key' );
   (or paste the key in Settings > RunningSA MEC).
3. Pick provinces (default Free State + Northern Cape), optional MEC category, then "Force fetch now".

Every event links back to its RunningCalendar page using the API "url" unchanged
(MEC "More info" button + a link in the description), as agreed with RunningCalendar.
