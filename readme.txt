=== EME Event Attendee Export ===
Contributors: Online Marketing Design
Tags: events, export, excel, attendees, events made easy
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-off export for Event Made Easy: one Excel workbook per event, named after the event, listing the people who booked it — all zipped into a single download.

== Description ==

This is a one-off tool, not a maintained product: install it, run the export, then delete it. It creates no tables and stores no options, so deleting the plugin removes it cleanly.

On the **Events Made Easy → Attendee Export** page you tick the events to export (newest first by event start date, 20 per page, with an optional From/To filter on the event start date) and download a ZIP holding one `.xlsx` workbook per ticked event, named after the event. Each workbook lists the people who booked that event — one row per booking — with Person name, Email, Phone, Seats, Status, Waiting list, Paid, Booking date, Booking comment, then one column per custom answer field from the booking form.

Included bookings: approved, pending, and awaiting user confirmation. Trashed bookings are left out. Event Made Easy has no separate cancelled / rejected / no-show status — a cancelled booking is a trashed one — so the only exclusion is trashed. Events with no bookings still get a workbook, with just the header row.

Workbooks are written natively with ZipArchive + SpreadsheetML (no spreadsheet library). Every cell is stored as text, so spreadsheet apps never evaluate values as formulas.

== Installation ==

1. In wp-admin, go to Plugins → Add New → Upload Plugin and upload `eme-event-attendee-export.zip`.
2. Activate the plugin. Event Made Easy must already be active.
3. Go to Events Made Easy → Attendee Export, tick the events, and download the ZIP.
4. When you are done, deactivate and delete the plugin — it leaves nothing behind.

== Frequently Asked Questions ==

= Where do I find the export page? =

Events Made Easy → Attendee Export. If Event Made Easy is not active, the page appears under Tools instead and tells you EME is required.

= Which bookings are included? =

Approved, pending, and awaiting user confirmation. Trashed bookings are excluded. Waiting-list bookings are included and marked Yes in the Waiting list column.

= What are the custom answer columns? =

One column per custom field from the booking form that has an answer on at least one included booking of that event, in field order. Per-seat dynamic answers are not included — the export is one row per booking.

= What if two events have the same name? =

The second workbook gets `-{event ID}` appended, so no file in the ZIP overwrites another.

== Changelog ==

= 1.1.0 =
* Added individual event XLSX export action in the events table.
* Redesigned date filter toolbar with a slim, modern inline UI.
* Fixed filename and XML character sanitization for event names with colons, trailing dots, or special/box characters.

= 1.0.0 =
* First release. One-off export: one .xlsx per event, zipped.
