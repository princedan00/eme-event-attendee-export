<?php
/**
 * Event Attendee Export — event list + download.
 *
 * v1.0.0 — New. Lists every non-trashed event, newest first by event
 *   start date, 20 per page with a checkbox per event. An optional
 *   From/To filter narrows the list by event start date. The ZIP holds
 *   one .xlsx per ticked event, named after the event, each listing the
 *   people who booked it. Nothing is ticked by default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$emea_state         = isset( $_GET['emea_export'] ) ? sanitize_key( wp_unslash( $_GET['emea_export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$emea_reason        = isset( $_GET['emea_reason'] ) ? sanitize_key( wp_unslash( $_GET['emea_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$emea_from_raw      = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$emea_to_raw        = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$emea_range         = EMEA_Exporter::parse_date_range( $emea_from_raw, $emea_to_raw );
$emea_from          = is_wp_error( $emea_range ) ? null : $emea_range['from'];
$emea_to            = is_wp_error( $emea_range ) ? null : $emea_range['to'];
$emea_eme_active    = EMEA_Export_Admin::eme_active();
$emea_summary       = $emea_eme_active ? EMEA_Exporter::get_event_summaries( $emea_from, $emea_to ) : array();
$emea_zip_available = class_exists( 'ZipArchive' );
$emea_reasons       = array(
	'invalid_date'   => __( 'Dates must be real calendar dates in YYYY-MM-DD form.', 'eme-event-attendee-export' ),
	'reversed_range' => __( 'The From date must be on or before the To date.', 'eme-event-attendee-export' ),
);
?>
<div class="wrap emea-wrap">
	<h1><?php esc_html_e( 'Event Attendee Export', 'eme-event-attendee-export' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Export who attended each event: one Excel workbook per event, named after the event, zipped into a single download. Each workbook lists the people who booked that event — one row per booking — with name, email, phone, seats, status, waiting list, paid, booking date, comment, and the custom answers from the booking form. Approved, pending and awaiting-confirmation bookings are included; trashed bookings are left out. Events with no bookings still get a workbook, with just the header row.', 'eme-event-attendee-export' ); ?>
	</p>

	<?php if ( ! $emea_eme_active ) : ?>
		<div class="notice notice-error emea-notice"><p><?php esc_html_e( 'Event Made Easy is not active, so there are no events to export. Activate Event Made Easy and come back here.', 'eme-event-attendee-export' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $emea_zip_available ) : ?>
		<div class="notice notice-error emea-notice"><p><?php esc_html_e( 'ZipArchive is not available on this server, so exports cannot be created. Ask your host to enable the PHP zip extension.', 'eme-event-attendee-export' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'none_selected' === $emea_state ) : ?>
		<div class="notice notice-error emea-notice"><p><?php esc_html_e( 'Select at least one event to export.', 'eme-event-attendee-export' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'invalid' === $emea_state || is_wp_error( $emea_range ) ) : ?>
		<div class="notice notice-error emea-notice"><p><?php echo esc_html( isset( $emea_reasons[ $emea_reason ] ) ? $emea_reasons[ $emea_reason ] : ( is_wp_error( $emea_range ) ? $emea_range->get_error_message() : __( 'The date range was not valid.', 'eme-event-attendee-export' ) ) ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'failed' === $emea_state ) : ?>
		<div class="notice notice-error emea-notice"><p><?php esc_html_e( 'The export could not be created. Try again.', 'eme-event-attendee-export' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $emea_eme_active ) : ?>
		<hr />
		<h2><?php esc_html_e( 'Filter by event start date', 'eme-event-attendee-export' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( EMEA_Export_Admin::PAGE_SLUG ); ?>" />
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="emea-from"><?php esc_html_e( 'From', 'eme-event-attendee-export' ); ?></label></th>
						<td>
							<input type="date" id="emea-from" name="from" value="<?php echo esc_attr( $emea_from_raw ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank for no earliest start date.', 'eme-event-attendee-export' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="emea-to"><?php esc_html_e( 'To', 'eme-event-attendee-export' ); ?></label></th>
						<td>
							<input type="date" id="emea-to" name="to" value="<?php echo esc_attr( $emea_to_raw ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank for no latest start date.', 'eme-event-attendee-export' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<button type="submit" class="button"><?php esc_html_e( 'Filter events', 'eme-event-attendee-export' ); ?></button>
				<?php if ( '' !== $emea_from_raw || '' !== $emea_to_raw ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . EMEA_Export_Admin::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Clear filter', 'eme-event-attendee-export' ); ?></a>
				<?php endif; ?>
			</p>
		</form>

		<?php if ( $emea_summary ) : ?>
			<hr />
			<h2><?php esc_html_e( 'All events', 'eme-event-attendee-export' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %d: number of events. */
					esc_html__( '%d event(s), newest first by event start date. None are selected by default — tick the events to include. Each selected event becomes one workbook in the ZIP listing its bookings.', 'eme-event-attendee-export' ),
					count( $emea_summary )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . EMEA_Export_Admin::PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'emea_export_download' ); ?>
				<input type="hidden" name="emea_from" value="<?php echo esc_attr( $emea_from_raw ); ?>" />
				<input type="hidden" name="emea_to" value="<?php echo esc_attr( $emea_to_raw ); ?>" />
				<p>
					<button type="button" class="button-link" id="emea-export-select-page"><?php esc_html_e( 'Select all on this page', 'eme-event-attendee-export' ); ?></button>
					<span aria-hidden="true">·</span>
					<button type="button" class="button-link" id="emea-export-deselect-page"><?php esc_html_e( 'Deselect all on this page', 'eme-event-attendee-export' ); ?></button>
					<span aria-hidden="true">·</span>
					<button type="button" class="button-link" id="emea-export-select-all-events"><?php esc_html_e( 'Select all events', 'eme-event-attendee-export' ); ?></button>
					<span aria-hidden="true">·</span>
					<button type="button" class="button-link" id="emea-export-deselect-all-events"><?php esc_html_e( 'Deselect all events', 'eme-event-attendee-export' ); ?></button>
				</p>
				<table class="widefat striped" id="emea-export-table" data-per-page="<?php echo esc_attr( EMEA_Exporter::EVENTS_PER_PAGE ); ?>">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="emea-export-select-all" /> <label class="screen-reader-text" for="emea-export-select-all"><?php esc_html_e( 'Select all events on this page', 'eme-event-attendee-export' ); ?></label></td>
							<th><?php esc_html_e( 'Event', 'eme-event-attendee-export' ); ?></th>
							<th><?php esc_html_e( 'Bookings', 'eme-event-attendee-export' ); ?></th>
							<th class="emea-export-date"><?php esc_html_e( 'Event start', 'eme-event-attendee-export' ); ?></th>
							<th><?php esc_html_e( 'Workbook', 'eme-event-attendee-export' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$emea_used  = array();
						$emea_index = 0;
						foreach ( $emea_summary as $emea_event ) :
							$emea_filename = EMEA_Exporter::xlsx_filename( $emea_event['name'], $emea_event['id'], $emea_used );
							$emea_page     = (int) floor( $emea_index / EMEA_Exporter::EVENTS_PER_PAGE ) + 1;
							++$emea_index;
							?>
							<tr data-emea-export-page="<?php echo esc_attr( $emea_page ); ?>">
								<th scope="row" class="check-column"><input type="checkbox" name="emea_event_ids[]" value="<?php echo esc_attr( $emea_event['id'] ); ?>" /></th>
								<td><?php echo esc_html( $emea_event['name'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $emea_event['count'] ) ); ?></td>
								<td class="emea-export-date">
									<?php if ( null === $emea_event['start'] ) : ?>
										&mdash;
									<?php else : ?>
										<?php echo esc_html( $emea_event['start'] ); ?>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $emea_filename ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" id="emea-export-showing">
					<?php
					printf(
						/* translators: 1: first event on the page, 2: last event on the page, 3: total events. */
						esc_html__( 'Showing %1$d–%2$d of %3$d events', 'eme-event-attendee-export' ),
						1,
						min( EMEA_Exporter::EVENTS_PER_PAGE, count( $emea_summary ) ),
						count( $emea_summary )
					);
					?>
				</p>
				<div class="tablenav" id="emea-export-pager" hidden>
					<div class="tablenav-pages">
						<button type="button" class="button" data-emea-export-nav="prev">&lsaquo; <?php esc_html_e( 'Previous', 'eme-event-attendee-export' ); ?></button>
						<span id="emea-export-page-numbers"></span>
						<button type="button" class="button" data-emea-export-nav="next"><?php esc_html_e( 'Next', 'eme-event-attendee-export' ); ?> &rsaquo;</button>
					</div>
				</div>
				<p>
					<button type="submit" name="emea_export_download" value="1" class="button button-primary" id="emea-export-download" data-label-template="
					<?php
					echo esc_attr(
						/* translators: %d: number of workbooks in the ZIP. */
						__( 'Download ZIP (%d workbooks)', 'eme-event-attendee-export' )
					);
					?>
					">
						<?php
						// Nothing is ticked by default, so the label starts at 0;
						// JavaScript updates it (and disables the button) on load.
						printf(
							/* translators: %d: number of workbooks in the ZIP. */
							esc_html__( 'Download ZIP (%d workbooks)', 'eme-event-attendee-export' ),
							0
						);
						?>
					</button>
					<span class="description" style="margin-left:8px;"><code><?php echo esc_html( EMEA_Exporter::zip_filename( $emea_from, $emea_to ) ); ?></code></span>
				</p>
			</form>
		<?php else : ?>
			<hr />
			<p><?php esc_html_e( 'No events found — try a wider date range, or create an event first.', 'eme-event-attendee-export' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
