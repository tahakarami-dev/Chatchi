<?php
/**
 * Permission check for administrator-only endpoints.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Site administrators only (manage_options), with the standard REST nonce.
 *
 * An agent record is not required, so an administrator whose own record was
 * deactivated can still manage the plugin.
 */
final class AdminPermission {

	/**
	 * Permission callback.
	 *
	 * @return true|\WP_Error
	 */
	public static function check() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'chatchi_not_logged_in', __( 'Please log in to continue.', 'chatchi' ), array( 'status' => 401 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return self::forbidden();
		}

		return true;
	}

	/**
	 * Returns the standard "not allowed" error.
	 */
	public static function forbidden(): \WP_Error {
		return new \WP_Error( 'chatchi_forbidden', __( 'You are not allowed to do this.', 'chatchi' ), array( 'status' => 403 ) );
	}
}
