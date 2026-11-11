<?php
/**
 * Admin page: Events Made Easy → Attendee Export.
 *
 * v1.0.0 — New. One-off tool. Registers a submenu page under the Events
 *   Made Easy menu when EME is active; when EME is not active the page
 *   registers under Tools instead and shows a clear notice that EME is
 *   required — nothing fatals. All actions require manage_options and a
 *   valid nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMEA_Export_Admin {

	const PAGE_SLUG  = 'eme-attendee-export';
	const CAPABILITY = 'manage_options';

	/**
	 * Whether Event Made Easy is active.
	 *
	 * EME's own functions are the test: if eme_get_event() exists, EME
	 * is loaded. This does not depend on the EME plugin folder name,
	 * which differs between the wordpress.org install and the GitHub
	 * release (events-made-easy-main).
	 *
	 * @return bool
	 */
	public static function eme_active() {
		return function_exists( 'eme_get_event' );
	}

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_eme_missing_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Register the export page.
	 *
	 * Under the EME menu when EME is active; under Tools when it is
	 * not, so the "EME is required" notice is still reachable.
	 */
	public static function register_menu() {
		$parent = self::eme_active() ? 'eme-manager' : 'tools.php';
		$hook   = add_submenu_page(
			$parent,
			__( 'Event Attendee Export', 'eme-event-attendee-export' ),
			__( 'Attendee Export', 'eme-event-attendee-export' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'enqueue_assets' ) );
		}
	}

	/**
	 * Tell the admin EME is missing, on our page only.
	 */
	public static function maybe_show_eme_missing_notice() {
		if ( ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( self::eme_active() ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Event Made Easy is not active, so there are no events to export. Activate Event Made Easy and come back here.', 'eme-event-attendee-export' ) . '</p></div>';
	}

	/**
	 * Enqueue the list CSS/JS on this page only.
	 */
	public static function enqueue_assets() {
		wp_enqueue_style(
			'emea-export',
			EMEA_EXPORT_URL . 'assets/css/export.css',
			array(),
			EMEA_EXPORT_VERSION
		);
		wp_enqueue_script(
			'emea-export',
			EMEA_EXPORT_URL . 'assets/js/export.js',
			array( 'jquery' ),
			EMEA_EXPORT_VERSION,
			true
		);
	}

	/**
	 * Handle the download POST: nonce + capability, then stream the ZIP.
	 *
	 * Runs on admin_init so the download headers go out before any
	 * admin output. On failure it redirects back to the page with a
	 * notice code instead of streaming.
	 */
	public static function handle_download() {
		if ( ! isset( $_POST['emea_export_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export attendees.', 'eme-event-attendee-export' ) );
		}
		check_admin_referer( 'emea_export_download' );

		$range = EMEA_Exporter::parse_date_range(
			isset( $_POST['emea_from'] ) ? wp_unslash( $_POST['emea_from'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			isset( $_POST['emea_to'] ) ? wp_unslash( $_POST['emea_to'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
		if ( is_wp_error( $range ) ) {
			self::redirect_with_notice( 'invalid', $range->get_error_code() );
		}

		$event_ids = EMEA_Exporter::parse_event_ids(
			isset( $_POST['emea_event_ids'] ) ? wp_unslash( $_POST['emea_event_ids'] ) : null // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		);
		if ( empty( $event_ids ) ) {
			self::redirect_with_notice( 'none_selected', '' );
		}

		$error = EMEA_Exporter::stream_zip( $range['from'], $range['to'], $event_ids );
		if ( is_wp_error( $error ) ) {
			self::redirect_with_notice( 'failed', $error->get_error_code() );
		}
	}

	/**
	 * Redirect back to the page with a notice state.
	 *
	 * @param string $state  Notice state.
	 * @param string $reason Error code, when the state needs one.
	 */
	private static function redirect_with_notice( $state, $reason ) {
		$url = add_query_arg(
			array(
				'page'        => self::PAGE_SLUG,
				'emea_export' => $state,
				'emea_reason' => $reason,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the export page.
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'eme-event-attendee-export' ) );
		}
		require EMEA_EXPORT_DIR . 'admin/views/export.php';
	}
}
