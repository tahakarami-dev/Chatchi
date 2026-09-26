<?php
/**
 * Activation handler.
 *
 * @package ChatChi
 */

namespace ChatChi\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is activated.
 */
final class Activator {

	/**
	 * Installs ChatChi on the current site.
	 *
	 * On network activation nothing runs here: looping over every site in one
	 * request does not scale, so each site installs itself on its first
	 * request through Installer::maybe_upgrade().
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			return;
		}

		( new Installer() )->install();

		/**
		 * Fires after ChatChi is activated on a single site.
		 */
		do_action( 'chatchi_activated' );
	}
}
