<?php
/**
 * Plugin Name: EME Event Attendee Export
 * Plugin URI: https://www.onlinemarketing.design
 * Description: One-off export for Event Made Easy: one Excel workbook per event, named after the event, listing the people who booked it — all zipped into a single download.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Online Marketing Design
 * Author URI: https://www.onlinemarketing.design
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eme-event-attendee-export
 *
 * This is a one-off tool, not a maintained product: install it, run the
 * export, then delete it. It creates no tables and stores no options, so
 * deleting the plugin removes it cleanly (see uninstall.php).
 *
 * It reads Event Made Easy tables directly (wp_eme_events, wp_eme_bookings,
 * wp_eme_people, wp_eme_answers, wp_eme_formfields) and reuses EME's own
 * helpers — eme_get_bookings_for(), eme_get_person(), eme_answer2readable()
 * — whenever they are available. Workbooks are written natively with
 * ZipArchive + SpreadsheetML (no spreadsheet library): every cell is an
 * inline string, so spreadsheet apps never evaluate values as formulas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMEA_EXPORT_VERSION', '1.1.0' );
define( 'EMEA_EXPORT_FILE', __FILE__ );
define( 'EMEA_EXPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMEA_EXPORT_URL', plugin_dir_url( __FILE__ ) );

require_once EMEA_EXPORT_DIR . 'includes/class-exporter.php';
require_once EMEA_EXPORT_DIR . 'admin/class-admin.php';

add_action( 'plugins_loaded', 'emea_export_init' );

/**
 * Wire up the admin page once plugins are loaded, so we can tell whether
 * Event Made Easy is active before registering anything.
 */
function emea_export_init() {
	EMEA_Export_Admin::init();
}
