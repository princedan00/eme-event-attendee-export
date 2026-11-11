<?php
/**
 * PHPUnit bootstrap for EME Event Attendee Export.
 *
 * Defines the small set of WordPress functions the exporter uses, so the
 * pure-logic methods (filename sanitising, date parsing, status labels,
 * XLSX building) can be tested without WordPress. Anything that needs
 * $wpdb is covered by the SQLite fixture harness instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Simplified stand-in for WordPress sanitize_file_name(): strip
	 * tags and percent-encoded characters, turn whitespace into dashes,
	 * and drop anything that is not a word character, dot or dash.
	 */
	function sanitize_file_name( $name ) {
		$name = strip_tags( $name );
		$name = preg_replace( '/%[0-9a-f]{2}/i', '', $name );
		$name = preg_replace( '/\s+/', '-', $name );
		$name = preg_replace( '/[^a-zA-Z0-9._-]+/', '', $name );
		$name = trim( $name, '.-' );
		return $name;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $prefix = '' ) {
		return tempnam( sys_get_temp_dir(), $prefix );
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $value ) {
		return addslashes( $value );
	}
}

require_once __DIR__ . '/../includes/class-exporter.php';
