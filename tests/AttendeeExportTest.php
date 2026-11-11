<?php
/**
 * Unit tests for the EME Event Attendee Export exporter.
 *
 * Covers the pure logic: event-ID parsing, date-range parsing, status
 * labels, workbook filenames, the zip filename, and XLSX building
 * (valid package, inline-string cells, formula-like values stored as
 * text, leading zeros preserved). Database-backed methods
 * (get_event_summaries, event_answer_columns, build_zip) are covered by
 * the SQLite fixture harness in /tmp/emea-harness/fixture.php.
 */

use PHPUnit\Framework\TestCase;

final class AttendeeExportTest extends TestCase {

	public function test_parse_event_ids_drops_invalid_and_deduplicates() {
		$this->assertSame( array( 3, 7 ), EMEA_Exporter::parse_event_ids( array( '3', 0, -1, 'abc', 7, 3 ) ) );
		$this->assertSame( array(), EMEA_Exporter::parse_event_ids( null ) );
		$this->assertSame( array(), EMEA_Exporter::parse_event_ids( '5' ) );
	}

	public function test_parse_date_range_open_ended_and_valid() {
		$this->assertSame(
			array(
				'from' => '2026-01-01',
				'to'   => '2026-12-31',
			),
			EMEA_Exporter::parse_date_range( '2026-01-01', '2026-12-31' )
		);
		$this->assertSame(
			array(
				'from' => null,
				'to'   => null,
			),
			EMEA_Exporter::parse_date_range( '', '' )
		);
		$this->assertSame(
			array(
				'from' => '2026-03-01',
				'to'   => null,
			),
			EMEA_Exporter::parse_date_range( '2026-03-01', '' )
		);
	}

	public function test_parse_date_range_rejects_reversed_and_impossible_dates() {
		$reversed = EMEA_Exporter::parse_date_range( '2026-12-31', '2026-01-01' );
		$this->assertInstanceOf( WP_Error::class, $reversed );
		$this->assertSame( 'reversed_range', $reversed->get_error_code() );

		$impossible = EMEA_Exporter::parse_date_range( '2026-02-30', '' );
		$this->assertInstanceOf( WP_Error::class, $impossible );
		$this->assertSame( 'invalid_date', $impossible->get_error_code() );

		$wrong_format = EMEA_Exporter::parse_date_range( '01/03/2026', '' );
		$this->assertInstanceOf( WP_Error::class, $wrong_format );
		$this->assertSame( 'invalid_date', $wrong_format->get_error_code() );
	}

	public function test_status_labels_match_eme() {
		$this->assertSame( 'Approved', EMEA_Exporter::status_label( 1 ) );
		$this->assertSame( 'Pending', EMEA_Exporter::status_label( 2 ) );
		$this->assertSame( 'Awaiting user confirmation', EMEA_Exporter::status_label( 3 ) );
		$this->assertSame( 'Trashed', EMEA_Exporter::status_label( 0 ) );
		$this->assertSame( 'Unknown (99)', EMEA_Exporter::status_label( 99 ) );
	}

	public function test_xlsx_filename_sanitises_and_deduplicates() {
		$used = array();
		$this->assertSame( 'Ready-for-2027.xlsx', EMEA_Exporter::xlsx_filename( 'Ready for 2027', 5, $used ) );
		// Same name again: the event ID is appended.
		$this->assertSame( 'Ready-for-2027-9.xlsx', EMEA_Exporter::xlsx_filename( 'Ready for 2027', 9, $used ) );
		// A name that is already an .xlsx does not double the extension.
		$this->assertSame( 'Report.xlsx', EMEA_Exporter::xlsx_filename( 'Report.xlsx', 11, $used ) );
		// A blank name falls back to event-{id}.
		$this->assertSame( 'event-12.xlsx', EMEA_Exporter::xlsx_filename( '///', 12, $used ) );
	}

	public function test_xlsx_filename_truncates_long_names() {
		$used = array();
		$name = EMEA_Exporter::xlsx_filename( str_repeat( 'a', 200 ), 1, $used );
		$this->assertSame( 125, strlen( $name ) ); // 120 chars + .xlsx
	}

	public function test_zip_filename() {
		$this->assertSame( 'eme-attendee-export-all-to-all.zip', EMEA_Exporter::zip_filename( null, null ) );
		$this->assertSame( 'eme-attendee-export-2026-01-01-to-2026-06-30.zip', EMEA_Exporter::zip_filename( '2026-01-01', '2026-06-30' ) );
	}

	public function test_fixed_headers() {
		$this->assertSame(
			array( 'Person name', 'Email', 'Phone', 'Seats', 'Status', 'Waiting list', 'Paid', 'Booking date', 'Booking comment' ),
			EMEA_Exporter::fixed_headers()
		);
	}

	public function test_build_xlsx_file_is_a_valid_workbook_with_inline_strings() {
		$headers = array( 'Person name', 'Email', 'Phone' );
		$rows    = array(
			array( 'Tan Wei Li', 'wei@example.com', '06512345' ),
			array( '=SUM(A1:A2)', 'x@example.com', '91234567' ),
		);
		$path = EMEA_Exporter::build_xlsx_file( $headers, $rows );
		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		try {
			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $path ) );
			$this->assertNotFalse( $zip->locateName( '[Content_Types].xml' ) );
			$this->assertNotFalse( $zip->locateName( 'xl/workbook.xml' ) );
			$this->assertNotFalse( $zip->locateName( 'xl/worksheets/sheet1.xml' ) );
			$this->assertNotFalse( $zip->locateName( 'xl/styles.xml' ) );
			$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
			$zip->close();

			// Header row is bold (style 1); values are inline strings.
			$this->assertStringContainsString( '<c r="A1" s="1" t="inlineStr"><is><t>Person name</t></is></c>', $sheet );
			// Leading zero survives: the phone is text, not a number.
			$this->assertStringContainsString( '<t>06512345</t>', $sheet );
			// Formula-looking value is stored as text, never as a formula.
			$this->assertStringContainsString( '<t>=SUM(A1:A2)</t>', $sheet );
			$this->assertStringNotContainsString( '<f>', $sheet );
		} finally {
			wp_delete_file( $path );
		}
	}
}
