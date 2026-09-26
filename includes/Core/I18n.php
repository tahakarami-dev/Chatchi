<?php
/**
 * Translation loading.
 *
 * @package ChatChi
 */

namespace ChatChi\Core;

use ChatChi\Core\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the `chatchi` text domain from the bundled languages directory.
 */
final class I18n implements Module {

	public const TEXT_DOMAIN = 'chatchi';

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// Early on init, so other init callbacks that use ChatChi get translated strings.
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
	}

	/**
	 * Loads bundled translations. Files in wp-content/languages take priority.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( CHATCHI_BASENAME ) . '/languages' );
	}
}
