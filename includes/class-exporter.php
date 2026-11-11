<?php
/**
 * Export: one XLSX workbook per Event Made Easy event, zipped for download.
 *
 * v1.0.0 — New. One-off tool for a WordPress site running Events Made Easy. The admin ticks
 *   events on the Attendee Export page (newest first by event start date,
 *   20 per page, optional From/To filter on the event start date) and
 *   downloads a ZIP holding one .xlsx per ticked event, named after the
 *   event. Each workbook lists the people who booked that event — one row
 *   per booking — with Person name, Email, Phone, Seats, Status, Waiting
 *   list, Paid, Booking date, Booking comment, then one column per custom
 *   booking-form answer field for that event.
 *
 *   Schema facts, read from Event Made Easy 3.3.6
 *   (events-manager.php, includes/eme-install.php, eme-rsvp.php,
 *   eme-people.php, eme-formfields.php):
 *   - wp_eme_events: event_id, event_status (0 trashed, 1 public,
 *     2 private, 3 unlisted, 5 draft, 6 draft), event_name, event_start,
 *     event_end. Trashed events are never listed.
 *   - wp_eme_bookings: booking_id, event_id, person_id, status, booking_seats,
 *     waitinglist, booking_comment, creation_date, booking_paid. Status
 *     codes are 0 trashed, 1 approved, 2 pending, 3 awaiting user
 *     confirmation. EME has NO separate cancelled / rejected / no-show
 *     status — a cancelled booking is a trashed one (status 0), so the
 *     only exclusion is status 0. Waiting list is a separate flag, not
 *     a status, so it gets its own Yes/No column.
 *   - Person details live in wp_eme_people (person_id, firstname,
 *     lastname, email, phone, ...), NOT in wp_eme_members — that table
 *     is memberships, a different thing.
 *   - Custom booking-form answers live in wp_eme_answers with
 *     type='booking', related_id=booking_id, field_id, answer. The
 *     non-dynamic answers (eme_grouping=0) become the workbook's answer
 *     columns, in field_id order — the same set EME's own CSV booking
 *     report uses (eme_get_booking_answers_fieldids). Per-seat dynamic
 *     answers (eme_grouping>0) are NOT included: the export is one row
 *     per booking by design. Field labels come from wp_eme_formfields
 *     (field_name). Answers are rendered with EME's own
 *     eme_answer2readable() when available, so multi-value answers
 *     render the way EME renders them.
 *   - Booking counts match EME's own eme_count_bookings_for(): bookings
 *     with status != 0.
 *
 *   No third-party spreadsheet library: workbooks are written natively
 *   with ZipArchive + SpreadsheetML, and cell values are stored as inline
 *   strings so spreadsheet apps never evaluate them as formulas.
 *   Bookings are read in 500-row pages so memory stays bounded by one
 *   page rather than the whole export.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMEA_Exporter {

	const PAGE_SIZE = 500;

	/**
	 * Events shown per page on the export list.
	 */
	const EVENTS_PER_PAGE = 20;

	/**
	 * Booking statuses included in the export.
	 *
	 * EME_RSVP_STATUS_APPROVED (1), EME_RSVP_STATUS_PENDING (2),
	 * EME_RSVP_STATUS_USERPENDING (3). Status 0 is trashed and is the
	 * only exclusion — EME has no cancelled/rejected/no-show status.
	 */
	const INCLUDED_STATUSES = array( 1, 2, 3 );

	/**
	 * Table prefix for the EME tables.
	 *
	 * EME_DB_PREFIX handles multisite (base prefix when the EME
	 * multisite option is on); otherwise the current blog prefix.
	 *
	 * @return string
	 */
	private static function eme_prefix() {
		global $wpdb;
		if ( defined( 'EME_DB_PREFIX' ) ) {
			return EME_DB_PREFIX;
		}
		return $wpdb->prefix;
	}

	/**
	 * Readable label for a booking status code, matching EME's own CSV
	 * booking report (eme_csv_booking_report).
	 *
	 * @param int $status Status code from wp_eme_bookings.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( intval( $status ) ) {
			case 1:
				return __( 'Approved', 'eme-event-attendee-export' );
			case 2:
				return __( 'Pending', 'eme-event-attendee-export' );
			case 3:
				return __( 'Awaiting user confirmation', 'eme-event-attendee-export' );
			case 0:
				return __( 'Trashed', 'eme-event-attendee-export' );
			default:
				/* translators: %d: unknown booking status code. */
				return sprintf( __( 'Unknown (%d)', 'eme-event-attendee-export' ), intval( $status ) );
		}
	}

	/**
	 * Sanitize the ticked event IDs from the export form.
	 *
	 * Anything that is not a positive integer is dropped; duplicates are
	 * removed. A non-array value (nothing ticked posts no field at all)
	 * means no selection.
	 *
	 * @param mixed $raw Raw emea_event_ids value.
	 * @return int[] Selected event IDs, in first-seen order.
	 */
	public static function parse_event_ids( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			$id = intval( $value );
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Validate a From/To pair from the filter form.
	 *
	 * Either end may be blank, which means open-ended (all time in that
	 * direction). A provided date must be a real calendar date in
	 * YYYY-MM-DD form, and From must not be after To.
	 *
	 * @param mixed $from_raw Raw From value.
	 * @param mixed $to_raw   Raw To value.
	 * @return array|WP_Error Array with 'from'/'to' (Y-m-d string or null).
	 */
	public static function parse_date_range( $from_raw, $to_raw ) {
		$from = self::parse_date( $from_raw );
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		$to = self::parse_date( $to_raw );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( null !== $from && null !== $to && $from > $to ) {
			return new WP_Error( 'reversed_range', __( 'The From date must be on or before the To date.', 'eme-event-attendee-export' ) );
		}
		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * Validate one date string.
	 *
	 * @param mixed $raw Raw value.
	 * @return string|null|WP_Error Y-m-d string, null when blank, WP_Error when invalid.
	 */
	private static function parse_date( $raw ) {
		$date = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $date ) {
			return null;
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'invalid_date', __( 'Dates must use the YYYY-MM-DD format.', 'eme-event-attendee-export' ) );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			return new WP_Error( 'invalid_date', __( 'One of the dates is not a real calendar date.', 'eme-event-attendee-export' ) );
		}
		return $date;
	}

	/**
	 * Events for the export list, newest first by event start date.
	 *
	 * One entry per non-trashed event: id, name, start date and booking
	 * count (bookings with status != 0, matching EME's own count). With
	 * a range, only events whose start date falls inside it are listed.
	 * An event with no start date sorts last and cannot fall inside a
	 * range. Events with no bookings at all are listed too (count 0);
	 * their workbook holds just the header row.
	 *
	 * @param string|null $from Y-m-d start or null (no lower bound).
	 * @param string|null $to   Y-m-d end or null (no upper bound).
	 * @return array Included events, each with 'id', 'name', 'count'
	 *               and 'start' (Y-m-d H:i:s or null).
	 */
	public static function get_event_summaries( $from, $to ) {
		global $wpdb;

		$prefix         = self::eme_prefix();
		$events_table   = esc_sql( $prefix . 'eme_events' );
		$bookings_table = esc_sql( $prefix . 'eme_bookings' );

		$where = array( 'event_status != 0' );
		if ( null !== $from ) {
			$where[] = $wpdb->prepare( 'DATE(event_start) >= %s', $from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( null !== $to ) {
			$where[] = $wpdb->prepare( 'DATE(event_start) <= %s', $to ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$where_sql = implode( ' AND ', $where );

		$sql    = "SELECT event_id, event_name, event_start FROM `{$events_table}` WHERE {$where_sql} ORDER BY (event_start IS NULL OR event_start = '0000-00-00 00:00:00'), event_start DESC, event_id DESC"; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$counts   = $wpdb->get_results( "SELECT event_id, COUNT(*) AS c FROM `{$bookings_table}` WHERE status != 0 GROUP BY event_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_event = array();
		foreach ( (array) $counts as $row ) {
			$by_event[ intval( $row['event_id'] ) ] = intval( $row['c'] );
		}

		$summaries = array();
		foreach ( (array) $events as $event ) {
			$id    = intval( $event['event_id'] );
			$start = isset( $event['event_start'] ) ? (string) $event['event_start'] : '';
			if ( '' === $start || '0000-00-00 00:00:00' === $start || false === strtotime( $start ) ) {
				$start = null;
			}
			$summaries[] = array(
				'id'    => $id,
				'name'  => isset( $event['event_name'] ) ? (string) $event['event_name'] : '',
				'count' => isset( $by_event[ $id ] ) ? $by_event[ $id ] : 0,
				'start' => $start,
			);
		}
		return $summaries;
	}

	/**
	 * Workbook filename for one event, deduplicated across the zip.
	 *
	 * The event name is sanitised for the filesystem; when two events
	 * sanitise to the same name the event ID is appended so no file in
	 * the zip overwrites another. A blank name falls back to event-{id}.
	 *
	 * @param string $name    Event name.
	 * @param int    $event_id Event ID.
	 * @param array  $used    Filenames already used in this zip (by reference).
	 * @return string Filename ending in .xlsx.
	 */
	public static function xlsx_filename( $name, $event_id, &$used ) {
		$name = (string) $name;
		// Decode any HTML entities (e.g. &amp;, &quot;, &#8211;, etc.).
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Strip control characters, zero-width spaces, and private-use characters.
		$name = preg_replace( '/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}\x{FFFD}\x{E000}-\x{F8FF}]/u', '', $name );
		// Replace characters that are illegal or problematic in filenames on Windows/macOS/Linux.
		$name = str_replace( array( ':', '/', '\\', '*', '?', '"', '<', '>', '|', '~', '`' ), '-', $name );
		$base = sanitize_file_name( $name );
		// Strip leading/trailing punctuation and whitespace.
		$base = trim( $base, ".-_ \t\n\r\0\x0B" );
		// Collapse multiple dashes.
		$base = preg_replace( '/-+/', '-', $base );
		// sanitize_file_name() keeps dots, so a name ending in ".xlsx"
		// would otherwise produce a doubled extension.
		if ( '.xlsx' === strtolower( substr( $base, -5 ) ) ) {
			$base = substr( $base, 0, -5 );
			$base = trim( $base, ".-_ \t\n\r\0\x0B" );
		}
		if ( '' === $base ) {
			$base = 'event-' . intval( $event_id );
		}
		// Keep the name usable inside zips and on disk. Truncate by
		// character, not by byte, so a multi-byte character is never
		// split in half.
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $base, 'UTF-8' ) > 120 ) {
				$base = mb_substr( $base, 0, 120, 'UTF-8' );
			}
		} elseif ( strlen( $base ) > 120 ) {
			$base = substr( $base, 0, 120 );
		}
		$base = trim( $base, ".-_ \t\n\r\0\x0B" );
		if ( '' === $base ) {
			$base = 'event-' . intval( $event_id );
		}
		$candidate = $base . '.xlsx';
		if ( isset( $used[ $candidate ] ) ) {
			$candidate = $base . '-' . intval( $event_id ) . '.xlsx';
		}
		$used[ $candidate ] = true;
		return $candidate;
	}

	/**
	 * Custom answer columns for one event's workbook.
	 *
	 * One column per custom booking-form field that has a non-dynamic
	 * answer (type='booking', eme_grouping=0) on any included booking
	 * of the event, in field_id order — the same set EME's own CSV
	 * booking report uses. The label is the field's field_name from
	 * wp_eme_formfields. An event with no bookings gets no answer
	 * columns (its workbook is just the fixed header row).
	 *
	 * @param int $event_id Event ID.
	 * @return array Columns as array( 'field_id' => ..., 'label' => ... ).
	 */
	public static function event_answer_columns( $event_id ) {
		global $wpdb;

		$prefix         = self::eme_prefix();
		$answers_table  = esc_sql( $prefix . 'eme_answers' );
		$bookings_table = esc_sql( $prefix . 'eme_bookings' );
		$fields_table   = esc_sql( $prefix . 'eme_formfields' );

		$booking_ids = $wpdb->get_col( $wpdb->prepare( "SELECT booking_id FROM `{$bookings_table}` WHERE event_id = %d AND status != 0", intval( $event_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $booking_ids ) ) {
			return array();
		}
		$booking_ids  = array_map( 'intval', $booking_ids );
		$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
		$field_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT field_id FROM `{$answers_table}` WHERE type = 'booking' AND eme_grouping = 0 AND related_id IN ({$placeholders}) ORDER BY field_id", ...$booking_ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( empty( $field_ids ) ) {
			return array();
		}

		$columns = array();
		foreach ( $field_ids as $field_id ) {
			$field_id = intval( $field_id );
			$label    = '';
			if ( function_exists( 'eme_get_formfield' ) ) {
				$formfield = eme_get_formfield( $field_id );
				if ( ! empty( $formfield ) && isset( $formfield['field_name'] ) ) {
					$label = (string) $formfield['field_name'];
				}
			}
			if ( '' === $label ) {
				$label = (string) $wpdb->get_var( $wpdb->prepare( "SELECT field_name FROM `{$fields_table}` WHERE field_id = %d", $field_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			if ( '' === $label ) {
				$label = 'Field ' . $field_id;
			}
			$columns[] = array(
				'field_id' => $field_id,
				'label'    => $label,
			);
		}
		return $columns;
	}

	/**
	 * Fixed columns at the head of every workbook, in order.
	 *
	 * @return string[] Header labels.
	 */
	public static function fixed_headers() {
		return array(
			__( 'Person name', 'eme-event-attendee-export' ),
			__( 'Email', 'eme-event-attendee-export' ),
			__( 'Phone', 'eme-event-attendee-export' ),
			__( 'Seats', 'eme-event-attendee-export' ),
			__( 'Status', 'eme-event-attendee-export' ),
			__( 'Waiting list', 'eme-event-attendee-export' ),
			__( 'Paid', 'eme-event-attendee-export' ),
			__( 'Booking date', 'eme-event-attendee-export' ),
			__( 'Booking comment', 'eme-event-attendee-export' ),
		);
	}

	/**
	 * Render one stored answer the way EME renders it.
	 *
	 * Uses EME's own eme_answer2readable() when available (the same
	 * call its CSV booking report makes, so multi-value answers render
	 * identically); otherwise the raw stored answer is used.
	 *
	 * @param string $answer    Stored answer value.
	 * @param int    $field_id  Form field ID.
	 * @return string
	 */
	public static function render_answer( $answer, $field_id ) {
		try {
			if ( function_exists( 'eme_answer2readable' ) && function_exists( 'eme_get_formfield' ) ) {
				$formfield = eme_get_formfield( intval( $field_id ) );
				if ( ! empty( $formfield ) ) {
					return (string) eme_answer2readable( (string) $answer, $formfield, 1, '||', 'text', 1 );
				}
			}
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fallback to raw string if EME formatting throws.
		}
		return is_scalar( $answer ) ? (string) $answer : '';
	}

	/**
	 * Page through one event's bookings, ordered as EME orders them
	 * (person last name, first name, then booking ID).
	 *
	 * Yields one page (array of rows) at a time so callers never hold
	 * the whole export in memory. Only bookings with status != 0 are
	 * included. Person details come from wp_eme_people; a booking whose
	 * person row is gone still yields a row with blank person fields.
	 *
	 * @param int $event_id Event ID.
	 * @return iterable Pages of booking rows.
	 */
	private static function booking_pages( $event_id ) {
		global $wpdb;

		$prefix         = self::eme_prefix();
		$bookings_table = esc_sql( $prefix . 'eme_bookings' );
		$people_table   = esc_sql( $prefix . 'eme_people' );

		for ( $offset = 0; ; $offset += self::PAGE_SIZE ) {
			$page = $wpdb->get_results( $wpdb->prepare( "SELECT b.*, p.firstname, p.lastname, p.email, p.phone FROM `{$bookings_table}` AS b LEFT JOIN `{$people_table}` AS p ON b.person_id = p.person_id WHERE b.event_id = %d AND b.status != 0 ORDER BY p.lastname ASC, p.firstname ASC, b.booking_id ASC LIMIT %d OFFSET %d", intval( $event_id ), self::PAGE_SIZE, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( empty( $page ) ) {
				break;
			}
			yield $page;
			if ( count( $page ) < self::PAGE_SIZE ) {
				break;
			}
		}
	}

	/**
	 * Non-dynamic answers for one page of bookings, keyed by booking ID
	 * then field ID.
	 *
	 * One query per page, not one per booking: the answers for every
	 * booking on the page are fetched in a single IN() query.
	 *
	 * @param int[] $booking_ids Booking IDs on the page.
	 * @return array booking_id => (field_id => answer string).
	 */
	private static function booking_answers_for_page( $booking_ids ) {
		global $wpdb;

		$booking_ids = array_values( array_unique( array_map( 'intval', (array) $booking_ids ) ) );
		if ( empty( $booking_ids ) ) {
			return array();
		}

		$prefix        = self::eme_prefix();
		$answers_table = esc_sql( $prefix . 'eme_answers' );

		$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT related_id, field_id, answer FROM `{$answers_table}` WHERE related_id IN ({$placeholders}) AND type = 'booking' AND eme_grouping = 0", ...$booking_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$answers      = array();
		foreach ( (array) $rows as $row ) {
			$answers[ intval( $row['related_id'] ) ][ intval( $row['field_id'] ) ] = (string) $row['answer'];
		}
		return $answers;
	}

	/**
	 * Yield one spreadsheet row per booking, in EME's booking order.
	 *
	 * @param int   $event_id        Event ID.
	 * @param array $answer_columns  Answer columns from event_answer_columns().
	 * @return iterable Rows of cell strings.
	 */
	private static function event_rows( $event_id, $answer_columns ) {
		foreach ( self::booking_pages( $event_id ) as $page ) {
			// One answers query per page — and none at all when the event
			// has no answer columns.
			$answers_by_booking = empty( $answer_columns ) ? array() : self::booking_answers_for_page( array_column( $page, 'booking_id' ) );
			foreach ( $page as $booking ) {
				$firstname  = isset( $booking['firstname'] ) ? trim( (string) $booking['firstname'] ) : '';
				$lastname   = isset( $booking['lastname'] ) ? trim( (string) $booking['lastname'] ) : '';
				$name       = trim( $firstname . ' ' . $lastname );
				$booking_id = intval( $booking['booking_id'] );
				$answers    = isset( $answers_by_booking[ $booking_id ] ) ? $answers_by_booking[ $booking_id ] : array();

				$row   = array();
				$row[] = $name;
				$row[] = isset( $booking['email'] ) ? (string) $booking['email'] : '';
				$row[] = isset( $booking['phone'] ) ? (string) $booking['phone'] : '';
				$row[] = isset( $booking['booking_seats'] ) ? (string) intval( $booking['booking_seats'] ) : '';
				$row[] = self::status_label( isset( $booking['status'] ) ? $booking['status'] : 0 );
				$row[] = ! empty( $booking['waitinglist'] ) ? __( 'Yes', 'eme-event-attendee-export' ) : __( 'No', 'eme-event-attendee-export' );
				$row[] = ! empty( $booking['booking_paid'] ) ? __( 'Yes', 'eme-event-attendee-export' ) : __( 'No', 'eme-event-attendee-export' );
				$row[] = isset( $booking['creation_date'] ) ? (string) $booking['creation_date'] : '';
				$row[] = isset( $booking['booking_comment'] ) ? (string) $booking['booking_comment'] : '';
				foreach ( $answer_columns as $column ) {
					$field_id = intval( $column['field_id'] );
					$row[]    = isset( $answers[ $field_id ] ) ? self::render_answer( $answers[ $field_id ], $field_id ) : '';
				}
				yield $row;
			}
		}
	}

	/**
	 * Build one event's workbook: every included booking, one row each.
	 *
	 * An event with no bookings gets a header-only workbook.
	 *
	 * @param int $event_id Event ID.
	 * @return string|WP_Error Path to a temporary .xlsx file, or WP_Error.
	 */
	public static function build_xlsx_for_event( $event_id ) {
		$answer_columns = self::event_answer_columns( intval( $event_id ) );
		$headers        = self::fixed_headers();
		foreach ( $answer_columns as $column ) {
			$headers[] = $column['label'];
		}
		return self::build_xlsx_file( $headers, self::event_rows( intval( $event_id ), $answer_columns ) );
	}

	/**
	 * Write a valid .xlsx workbook from headers + rows.
	 *
	 * Rows are streamed to a temporary sheet file as they are iterated, so
	 * the caller may pass a generator and the workbook never sits fully in
	 * memory. Every cell is an inline string: spreadsheet apps treat the
	 * value as text and never evaluate it as a formula, and leading zeros
	 * (phone numbers, postcodes) survive.
	 *
	 * @param array    $headers Header labels.
	 * @param iterable $rows    Rows of cell values (strings).
	 * @return string|WP_Error Path to a temporary .xlsx file, or WP_Error.
	 */
	public static function build_xlsx_file( $headers, $rows ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive is not available on this server, so spreadsheets cannot be generated.', 'eme-event-attendee-export' ) );
		}

		$sheet_path = wp_tempnam( 'emea-sheet-' );
		$sheet      = fopen( $sheet_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $sheet ) {
			wp_delete_file( $sheet_path );
			return new WP_Error( 'temp_file', __( 'Could not create a temporary file for the export.', 'eme-event-attendee-export' ) );
		}
		fwrite( $sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' );
		$row_number = 0;
		self::write_xlsx_row( $sheet, ++$row_number, $headers, true );
		foreach ( $rows as $row ) {
			self::write_xlsx_row( $sheet, ++$row_number, $row, false );
		}
		fwrite( $sheet, '</sheetData></worksheet>' );
		fclose( $sheet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$xlsx_path = wp_tempnam( 'emea-xlsx-' );
		$zip       = new ZipArchive();
		if ( true !== $zip->open( $xlsx_path, ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $sheet_path );
			wp_delete_file( $xlsx_path );
			return new WP_Error( 'zip_failed', __( 'Could not create the spreadsheet file.', 'eme-event-attendee-export' ) );
		}
		$zip->addFromString( '[Content_Types].xml', self::xlsx_content_types() );
		$zip->addFromString( '_rels/.rels', self::xlsx_root_rels() );
		$zip->addFromString( 'xl/workbook.xml', self::xlsx_workbook() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', self::xlsx_workbook_rels() );
		$zip->addFromString( 'xl/styles.xml', self::xlsx_styles() );
		$zip->addFile( $sheet_path, 'xl/worksheets/sheet1.xml' );
		$zip->close();
		wp_delete_file( $sheet_path );
		return $xlsx_path;
	}

	/**
	 * Write one row of inline-string cells to the open sheet file.
	 *
	 * @param resource $handle     Open sheet file handle.
	 * @param int      $row_number 1-based row number.
	 * @param array    $values     Cell values.
	 * @param bool     $is_header  Whether this is the bold header row.
	 */
	private static function write_xlsx_row( $handle, $row_number, $values, $is_header ) {
		$xml = '<row r="' . intval( $row_number ) . '">';
		foreach ( array_values( $values ) as $index => $value ) {
			$cell = self::column_letter( $index ) . intval( $row_number );
			$xml .= '<c r="' . $cell . '"' . ( $is_header ? ' s="1"' : '' ) . ' t="inlineStr"><is><t>' . self::xlsx_escape( (string) $value ) . '</t></is></c>';
		}
		$xml .= '</row>';
		fwrite( $handle, $xml );
	}

	/**
	 * Spreadsheet column letter for a 0-based index (0 → A, 26 → AA).
	 *
	 * @param int $index 0-based column index.
	 * @return string
	 */
	private static function column_letter( $index ) {
		$letter = '';
		$index  = intval( $index ) + 1;
		while ( $index > 0 ) {
			$remainder = ( $index - 1 ) % 26;
			$letter    = chr( 65 + $remainder ) . $letter;
			$index     = intval( ( $index - 1 ) / 26 );
		}
		return $letter;
	}

	/**
	 * Escape a cell value for XML, stripping control characters and invalid
	 * code points that are illegal in XML 1.0.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	private static function xlsx_escape( $value ) {
		$value = (string) $value;
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		$value = preg_replace( '/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		$flags = ENT_XML1 | ENT_COMPAT;
		if ( defined( 'ENT_SUBSTITUTE' ) ) {
			$flags |= ENT_SUBSTITUTE;
		}
		return htmlspecialchars( $value, $flags, 'UTF-8' );
	}

	/**
	 * [Content_Types].xml part of the workbook package.
	 *
	 * @return string
	 */
	private static function xlsx_content_types() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
			'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
			'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
			'<Default Extension="xml" ContentType="application/xml"/>' .
			'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
			'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
			'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
			'</Types>';
	}

	/**
	 * Package-level relationships part.
	 *
	 * @return string
	 */
	private static function xlsx_root_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
			'</Relationships>';
	}

	/**
	 * Workbook part: a single sheet named "Attendees".
	 *
	 * @return string
	 */
	private static function xlsx_workbook() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
			'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
			'<sheets><sheet name="Attendees" sheetId="1" r:id="rId1"/></sheets>' .
			'</workbook>';
	}

	/**
	 * Workbook relationships part (worksheet + styles).
	 *
	 * @return string
	 */
	private static function xlsx_workbook_rels() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
			'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
			'</Relationships>';
	}

	/**
	 * Styles part: normal + bold (header row) Calibri 11.
	 *
	 * @return string
	 */
	private static function xlsx_styles() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
			'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<fonts><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>' .
			'<fills><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' .
			'<borders><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
			'<cellStyleXfs><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
			'<cellXfs><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>' .
			'<cellStyles><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
			'</styleSheet>';
	}

	/**
	 * Download filename for the zip.
	 *
	 * @param string|null $from Y-m-d start or null.
	 * @param string|null $to   Y-m-d end or null.
	 * @return string
	 */
	public static function zip_filename( $from, $to ) {
		return sanitize_file_name( 'eme-attendee-export-' . ( $from ? $from : 'all' ) . '-to-' . ( $to ? $to : 'all' ) . '.zip' );
	}

	/**
	 * Build the zip: one workbook per selected event.
	 *
	 * The range selects EVENTS by start date (both ends null means every
	 * event); each workbook holds every included booking of its event.
	 * Returns the temporary zip path plus the download filename and the
	 * workbook filenames inside it. The caller owns the temp file and
	 * must delete it after streaming (stream_zip() does this).
	 *
	 * Workbook filenames are assigned across every listed event first,
	 * so the name an event gets on the list is the name it gets in the
	 * zip even when other events are deselected.
	 *
	 * @param string|null $from      Y-m-d start or null (all events).
	 * @param string|null $to        Y-m-d end or null (all events).
	 * @param int[]|null  $event_ids Selected event IDs, or null for all listed events.
	 * @return array|WP_Error Array with 'path', 'filename', 'files'.
	 */
	public static function build_zip( $from, $to, $event_ids = null ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive is not available on this server, so the export cannot be zipped.', 'eme-event-attendee-export' ) );
		}
		$summaries = self::get_event_summaries( $from, $to );
		if ( empty( $summaries ) ) {
			return new WP_Error( 'no_entries', __( 'No events were found for this export.', 'eme-event-attendee-export' ) );
		}
		if ( is_array( $event_ids ) && empty( $event_ids ) ) {
			return new WP_Error( 'no_events_selected', __( 'Select at least one event to export.', 'eme-event-attendee-export' ) );
		}

		// Assign workbook filenames across the whole list first, so a
		// deselected event never changes another event's filename.
		$used      = array();
		$filenames = array();
		foreach ( $summaries as $summary ) {
			$filenames[ intval( $summary['id'] ) ] = self::xlsx_filename( $summary['name'], $summary['id'], $used );
		}
		$selected = is_array( $event_ids ) ? array_flip( $event_ids ) : null;

		$zip_path = wp_tempnam( 'emea-export-' );
		$zip      = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $zip_path );
			return new WP_Error( 'zip_failed', __( 'Could not create the export file.', 'eme-event-attendee-export' ) );
		}
		$files = array();
		$temps = array();
		foreach ( $summaries as $summary ) {
			if ( null !== $selected && ! isset( $selected[ intval( $summary['id'] ) ] ) ) {
				continue;
			}
			$xlsx = self::build_xlsx_for_event( intval( $summary['id'] ) );
			if ( is_wp_error( $xlsx ) ) {
				$zip->close();
				foreach ( $temps as $temp ) {
					wp_delete_file( $temp );
				}
				wp_delete_file( $zip_path );
				return $xlsx;
			}
			$zip->addFile( $xlsx, $filenames[ intval( $summary['id'] ) ] );
			$temps[] = $xlsx;
			$files[] = $filenames[ intval( $summary['id'] ) ];
		}
		$zip->close();
		foreach ( $temps as $temp ) {
			wp_delete_file( $temp );
		}
		if ( empty( $files ) ) {
			wp_delete_file( $zip_path );
			return new WP_Error( 'no_events_selected', __( 'Select at least one event to export.', 'eme-event-attendee-export' ) );
		}
		return array(
			'path'     => $zip_path,
			'filename' => self::zip_filename( $from, $to ),
			'files'    => $files,
		);
	}

	/**
	 * Stream the built zip to the browser and exit.
	 *
	 * Sends download headers, streams the file, deletes the temporary zip.
	 * Returns a WP_Error instead of exiting when the zip cannot be built,
	 * so the admin handler can redirect back with a notice.
	 *
	 * @param string|null $from      Y-m-d start or null (all events).
	 * @param string|null $to        Y-m-d end or null (all events).
	 * @param int[]|null  $event_ids Selected event IDs, or null for all listed events.
	 * @return WP_Error|null WP_Error on failure, never returns on success.
	 */
	public static function stream_zip( $from, $to, $event_ids = null ) {
		$result = self::build_zip( $from, $to, $event_ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$size = filesize( $result['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'Content-Length: ' . intval( $size ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		// Keep running if the download is cancelled, so the temporary
		// zip is still deleted below.
		ignore_user_abort( true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $result['path'] );
		wp_delete_file( $result['path'] );
		exit;
	}

	/**
	 * Stream one event's XLSX workbook directly to the browser and exit.
	 *
	 * Sends download headers, streams the file, deletes the temporary XLSX.
	 * Returns a WP_Error instead of exiting when the XLSX cannot be built.
	 *
	 * @param int $event_id Event ID.
	 * @return WP_Error|null WP_Error on failure, never returns on success.
	 */
	public static function stream_xlsx( $event_id ) {
		global $wpdb;
		$event_id = intval( $event_id );
		if ( $event_id <= 0 ) {
			return new WP_Error( 'invalid_event', __( 'Invalid event ID.', 'eme-event-attendee-export' ) );
		}

		$prefix       = self::eme_prefix();
		$events_table = esc_sql( $prefix . 'eme_events' );
		$event_name   = $wpdb->get_var( $wpdb->prepare( "SELECT event_name FROM `{$events_table}` WHERE event_id = %d", $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$used     = array();
		$filename = self::xlsx_filename( (string) $event_name, $event_id, $used );

		$xlsx_path = self::build_xlsx_for_event( $event_id );
		if ( is_wp_error( $xlsx_path ) ) {
			return $xlsx_path;
		}

		$size = filesize( $xlsx_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . intval( $size ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		ignore_user_abort( true );
		readfile( $xlsx_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $xlsx_path );
		exit;
	}
}
