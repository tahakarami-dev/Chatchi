<?php
/**
 * Admin notice for failed installs.
 *
 * @package ChatChi
 */

namespace ChatChi\Core;

use ChatChi\Core\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Tells administrators when ChatChi could not set up its database tables.
 */
final class InstallNotice implements Module {

	private Installer $installer;

	/**
	 * @param Installer $installer Installer holding the last error.
	 */
	public function __construct( Installer $installer ) {
		$this->installer = $installer;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Prints the notice when the last install failed.
	 */
	public function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$error = $this->installer->last_error();

		if ( '' === $error ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><code>%3$s</code></p></div>',
			esc_html__( 'ChatChi could not set up its database tables.', 'chatchi' ),
			esc_html__( 'Chat is unavailable until this is fixed. Setup is retried automatically every few minutes.', 'chatchi' ),
			esc_html( $error )
		);
	}
}
