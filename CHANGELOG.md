# Changelog

## 1.1.1 - 2026-11-25

- Remove the weekly import frequency. A site that was saved as weekly is moved to daily and the cron job is rescheduled to run in five minutes, then once a day.
- Add a **Restrict data export** setting, on by default. Unauthenticated REST requests no longer expose imported events or their meta; administrators, editors, and anyone who can edit posts (including the MEC editor) still can when logged in. The public MEC iCal feed (`/?mec-ical-feed=1`) is disabled for visitors (site-wide, because MEC has no per-event filter for that feed). On imported events, “Add to Google Calendar” and “iCal / Outlook export” are hidden and their download links are blocked for visitors. Imported events are left out of RSS feeds. Event pages stay public.


## 1.1.0 - 2026-11-25

- Show a “Race data from RunningCalendar” credit with the bundled RunningCalendar logo on each imported event. The logo is an `<img>` linked to the event’s tracked API `url`. This is rendered when the event is viewed, so existing events get it without a database rewrite. A settings toggle turns it off; it is on by default.
- Ship the official RunningCalendar SVG logo (checked for scripts and event handlers) under `assets/`.
- Store each race’s API `id` (`_runningsa_race_ids` and `data-race-id` on the race list) so distances can be matched one-to-one when names or fees change.
- Apply `updated_since` updates to race times, fees and venues for events already on the calendar, including those in the selected provinces. Unchanged events are still skipped by hash.
- Update MEC location address and coordinates when the venue changes but the town and province stay the same.
- Remind on the settings screen that Running Calendar’s terms require a refresh at least once a day.
- Include the unreleased 1.0.3 work: match events by `id` or `canonical_url`, Resync events, remote event logo from `image_url`, and summary / organiser fields. Links still use the tracked `url` only.

## 1.0.2 - 2026-10-21

- Choose how often to fetch (6 hours, 12 hours, daily, weekly).
- Mark races cancelled in MEC when Running Calendar reports them as no longer available via `updated_since`.
- Import one race by slug or event URL.

## 1.0.1 - 2026-10-21

- Document Running Calendar API terms and rename visible RunningSA labels.

## 1.0.0 - 2026-10-21

- First release: daily import of selected provinces into Modern Events Calendar, with a required link back using the API `url`.
