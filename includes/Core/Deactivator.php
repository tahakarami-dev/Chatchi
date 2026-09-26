<?php
/**
 * Deactivation handler.
 *
 * @package ChatChi
 */

namespace ChatChi\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated. Stored data is kept; see uninstall.php.
 */
final class Deactivator {

	/**
	 * Releases runtime resources owned by the plugin.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 */
	public static function deactivate( $network_wide = false ): void {
		delete_option( Installer::LOCK_OPTION );

		/**
		 * Fires when ChatChi is deactivated, so modules can clear scheduled events.
		 *
		 * @param bool $network_wide Whether the plugin is being network-deactivated.
		 */
		do_action( 'chatchi_deactivated', (bool) $network_wide );
	}
}
