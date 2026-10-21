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
