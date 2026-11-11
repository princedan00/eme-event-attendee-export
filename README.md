# EME Event Attendee Export — WordPress Plugin (v1.1.0)

**What it is:** a small standalone WordPress plugin that exports, for each
Event Made Easy event, which people booked it — one real `.xlsx` workbook
per event (named after the event), all zipped into one download. Developed by
[Online Marketing Design](https://www.onlinemarketing.design).

**Get it:** download this repo as a ZIP from GitHub (Code → Download ZIP)
and upload it in wp-admin → Plugins → Add New → Upload Plugin. Or clone it
and zip the folder — the plugin files live at the repo root.

## Install and run (live test)

1. On the site, upload and activate the ZIP. Event Made Easy must already be active.
2. Go to **Events Made Easy → Attendee Export**.
3. Tick one event you know well (e.g. a recent event with a handful of
   bookings) and click **Download ZIP**.
4. Open the workbook and check the rows against what EME shows for that
   event (Approved Bookings / the event's booking list): names, emails,
   seats, statuses and the custom answers should match.
5. When you are done, deactivate and delete the plugin. It creates no
   tables and stores no options, so deleting it removes it cleanly.

## What each workbook holds

One row per booking, in EME's own booking order (last name, first name):

| Column | Source |
|---|---|
| Person name | First name + last name from `wp_eme_people` |
| Email, Phone | `wp_eme_people` |
| Seats | `booking_seats` |
| Status | Approved / Pending / Awaiting user confirmation |
| Waiting list | Yes / No (separate flag on the booking) |
| Paid | Yes / No |
| Booking date | `creation_date` as stored by EME (site local time) |
| Booking comment | `booking_comment` |
| …then one column per custom answer field | Field label from the booking form; only fields that have an answer on at least one included booking of that event |

Events with no bookings still get a workbook, with just the header row.
If two events have the same name, the second workbook gets `-{event ID}`
appended. Nothing is ticked by default; **Select all events** /
**Deselect all events** tick or untick every event across all pages, and
the optional From/To filter narrows the list by event start date.

## Defaults that can be changed

These were not answered, so the plugin uses the following — say the word
and they can be changed:

- **Included bookings:** approved, pending and awaiting user
  confirmation. **Trashed bookings are excluded.** Note: EME has **no**
  separate cancelled / rejected / no-show status code — its only booking
  statuses are 0 trashed, 1 approved, 2 pending, 3 awaiting user
  confirmation. A cancelled booking in EME *is* a trashed one, so the
  only exclusion available is trashed. If "cancelled" means something
  else on the site, tell me how it is recorded.
- **One row per booking, not one row per seat.** A booking for 2 seats is
  one row with Seats = 2.
- **Per-seat dynamic answers are not included.** EME stores per-seat
  answers separately (grouping > 0); only the booking-level answers
  become columns. EME's own CSV report appends dynamic answers to the
  right — that can be added if the client needs it.
- **Multiprice events:** the Seats column is the booking's total seats,
  not a per-price-category breakdown.
- **Person details come from `wp_eme_people`**, not `wp_eme_members`
  (that table is memberships, a different thing).

## Verification done (no live WordPress install)

- `php -l` clean on every PHP file; `node --check` clean on the JS;
  PHPCS (WordPress-Core, plugin's own `phpcs.xml`) clean — 0 errors,
  0 warnings.
- PHPUnit: **9 tests / 36 assertions green** (`tests/AttendeeExportTest.php`
  — filenames, date parsing, status labels, XLSX validity).
- End-to-end fixture harness: fake `$wpdb` over SQLite with the real EME
  table shapes and representative data (duplicate event names, trashed
  event, trashed booking, orphan person row, empty event, multi-value
  answers, a formula-looking comment). Verified: event list order and
  counts, date filter, answer columns, ZIP contents, and every workbook
  opened with openpyxl — statuses, waiting-list flag, leading-zero phone
  and formula-looking text all stored as text, header-only workbook for
  the empty event.
- Bootstrap smoke test: menu registers under Events Made Easy when EME
  is active, and under Tools (with an "EME is required" notice) when it
  is not — no fatal either way.

**Code review fix round (11 Nov 2026):** the review found one Medium (one
answers query per booking) and five Lows, all fixed: answers are now fetched
once per page — and skipped entirely when an event has no answer columns —
temp files are cleaned up on every error path, filename truncation is
multi-byte safe, the dead notice states are removed, and the docblock now
says `booking_seats`. Verified with a SQLite fixture harness: a 60-booking
event exports in 4 queries (was ~63); a 200-character Chinese event name
truncates to 120 characters plus `.xlsx` with valid UTF-8; PHPCS now reports
0 errors, 0 warnings; tests still 9/36 green.

**Not verified live:** the page has not been seen in a real wp-admin and
the export has not run against the site's real EME data — that is the
live test in the steps above.

## Files

- `eme-event-attendee-export.php` — bootstrap
- `includes/class-exporter.php` — summaries, columns, XLSX + ZIP builder
- `admin/class-admin.php` — menu, download handler, nonces, capability
- `admin/views/export.php` — event list page
- `assets/js/export.js`, `assets/css/export.css` — paging/selection
- `readme.txt`, `uninstall.php`, `phpcs.xml`
- `tests/` — PHPUnit tests + bootstrap (WP stubs)
