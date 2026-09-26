<?php
/**
 * Reporting unexpected failures.
 *
 * @package ChatChi
 */

namespace ChatChi\Support;

use ChatChi\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an unexpected exception into a safe REST error.
 *
 * - The real message always goes to the server's PHP error log.
 * - Site administrators also get it in the response (data.details), so the
 *   cause of a "not sent" can be seen right in the widget or panel; visitors
 *   never see it.
 * - A database error that points to a missing table or column means an
 *   upgrade did not finish. The installer is then scheduled to run again on
 *   the next request, which repairs the tables (dbDelta only adds).
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
	 * Whether a database error says a table or column is missing.
	 *
	 * @param string $message Error message (MySQL/MariaDB or SQLite).
	 */
	public static function is_schema_error( string $message ): bool {
		return 1 === preg_match( "/Unknown column|doesn't exist|no such table|no such column|has no column named/i", $message );
	}

	/**
	 * Makes the next request run the installer again when the error says a
	 * table or column is missing.
	 *
	 * @param \Throwable $error What went wrong.
	 */
	public static function repair_if_needed( \Throwable $error ): void {
		if ( self::is_schema_error( $error->getMessage() ) ) {
			delete_option( Installer::VERSION_OPTION );
			delete_transient( Installer::BACKOFF_TRANSIENT );
		}
	}
}
