<?php
/**
 * Reporting unexpected failures.
 *
 * @package ChatChi
 */

namespace ChatChi\Support;

use ChatChi\Core\Installer;
use ChatChi\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an unexpected exception into a safe REST error.
 *
 * - The real message always goes to the server's PHP error log.
 * - Site administrators also get it in the response (data.details), so the
 *   cause of a "not sent" can be seen right in the widget or panel; visitors
 *   never see it.
 * - A database error that points to a missing table or column (an upgrade
 *   that did not finish), or to a column or unique index that is not
 *   ChatChi's (left in the table by an older or different plugin), runs
 *   the installer right away, which repairs the tables, so a retry of the
 *   same request succeeds (the widget retries once by itself). If that
 *   repair fails, it is tried again on the next request.
 *   See Schema::install() and Schema::reconcile().
 */
final class Failure {

	/**
	 * @param \Throwable $error   What went wrong.
	 * @param string     $context Short label for the log, e.g. "Message".
	 * @param string     $code    Error code for the response.
	 */
	public static function report( \Throwable $error, string $context, string $code ): \WP_Error {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Unexpected failures must reach the server log.
		error_log( '[ChatChi] ' . $context . ' error: ' . $error->getMessage() );

		self::repair_if_needed( $error );

		$data = array( 'status' => 500 );

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			$data['details'] = $error->getMessage();
		}

		return new \WP_Error( $code, __( 'Something went wrong. Please try again.', 'chatchi' ), $data );
	}

	/**
	 * Whether a database error means the tables need repairing.
	 *
	 * @param string $message Error message (MySQL/MariaDB or SQLite).
	 */
	public static function is_schema_error( string $message ): bool {
		// Missing tables or columns.
		if ( preg_match( "/Unknown column|doesn't exist|no such table|no such column|has no column named/i", $message ) ) {
			return true;
		}

		// A NOT NULL column ChatChi does not know about (strict SQL mode).
		if ( preg_match( "/Field '[^']+' doesn't have a default value/i", $message ) ) {
			return true;
		}

		// A duplicate on a unique index that is not ChatChi's. Duplicates on
		// ChatChi's own unique keys are expected and handled where they occur.
		if ( preg_match( "/Duplicate entry .* for key '(?:[^'.]+\\.)?([^'.]+)'/i", $message, $m ) ) {
			return ! self::schema()->is_own_key( $m[1] );
		}

		return false;
	}

	private static function schema(): Schema {
		global $wpdb;

		return new Schema( $wpdb );
	}

	/**
	 * Makes the next request run the installer again when the error says a
	 * table or column is missing.
	 *
	 * @param \Throwable $error What went wrong.
	 */
	public static function repair_if_needed( \Throwable $error ): void {
		static $attempted = false;

		if ( $attempted || ! self::is_schema_error( $error->getMessage() ) ) {
			return;
		}

		$attempted = true;

		delete_transient( Installer::BACKOFF_TRANSIENT );

		try {
			if ( \ChatChi\Plugin::instance()->container()->get( Installer::class )->install() ) {
				return;
			}
		} catch ( \Throwable $e ) {
			// Recorded by the installer; fall through to a retry on the next request.
			unset( $e );
		}

		delete_option( Installer::VERSION_OPTION );
		delete_transient( Installer::BACKOFF_TRANSIENT );
	}
}
