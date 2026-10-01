<?php
/**
 * Database failure.
 *
 * @package ChatChi
 */

namespace ChatChi\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a query fails. Services translate it into user-facing errors.
 */
final class DatabaseException extends \RuntimeException {

	/**
	 * Builds an exception from the last wpdb error.
	 *
	 * @param \wpdb  $db        Database connection.
	 * @param string $operation Short description of what failed.
	 */
	public static function from_wpdb( \wpdb $db, string $operation ): self {
		$error = '' !== $db->last_error ? $db->last_error : 'unknown error';

		return new self( sprintf( 'ChatChi database %s failed: %s', $operation, $error ) );
	}
}
