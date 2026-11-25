# runningcalendar-mec-integration

Integrate the South African Running Calendar with Modern Events Calendar on WordPress.

This plugin imports upcoming races from the [RunningCalendar API](https://api.runningcalendar.co.za/docs) into Modern Events Calendar. Choose how often that runs: every 6 hours, every 12 hours, once a day, or once a week. Running Calendar’s terms require a refresh at least once a day, so daily or more often is recommended. Each listing links back to its event page on RunningCalendar, using the `url` field exactly as the API returns it, including the tracking parameters Running Calendar adds.

Events are matched by `id` or `canonical_url`. `canonical_url` is not used as a link. Use **Resync events** on the settings page to rewrite races that were imported before `canonical_url` existed. The event’s own logo is loaded from `image_url` and is not copied onto the site. The summary and organiser name are shown on the event; the organiser link uses their `url` and ignores `app_url`. Race-level `id` values are stored so distances can be matched one-to-one when they change.

On each fetch, a race that Running Calendar reports as no longer available is marked cancelled in MEC. A race that has simply taken place stays on the calendar as a past event. Changes to race times, fees and venues are applied from both the province listing and the `updated_since` sync. You can also import one race by its slug from the settings page.

By default, each imported event also shows a “Race data from RunningCalendar” credit with the RunningCalendar logo, linked to that event’s tracked `url`. This is added when the event is viewed, so existing events get it without a re-import. It can be turned off in settings.

## API key and credentials

Contact Running Calendar at [info@runningcalendar.co.za](mailto:info@runningcalendar.co.za) for an API key and credentials. Do not use this plugin against the API without them.

## Terms of use

- Do not modify this code so that it scrapes [runningcalendar.co.za](https://runningcalendar.co.za) without permission from Running Calendar.
- Keep the link back to each event’s RunningCalendar page. Use the `url` field exactly as the API returns it, including its tracking parameters. Do not add, change, or remove those parameters.
- Match and de-duplicate events with `id` or `canonical_url`, not with `url`. Do not use `canonical_url` or `app_url` as the link.
- Leave `rel="noreferrer"` off links back to Running Calendar, so they can see which site the visit came from. `rel="noopener"` is fine.
- Do not pass the API data on to anyone else.
- Refresh what you hold at least once a day so changes and cancellations reach your users.
