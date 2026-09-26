<?php
/**
 * Plugin Name:       ChatChi | چت‌چی
 * Plugin URI:        https://github.com/tahakarami-dev/Chatchi
 * Description:       Lightweight, secure live chat for WordPress with a standalone, SaaS-style agent panel.
 * Version:           0.9.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Taha Karami
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chatchi
 * Domain Path:       /languages
 *
 * @package ChatChi
 */

// This file must stay parseable on old PHP versions so the requirements
// notice can be shown instead of a fatal error. Keep modern syntax out of it.

defined( 'ABSPATH' ) || exit;

if ( defined( 'CHATCHI_VERSION' ) ) {
	// Another copy of the plugin is already loaded.
	return;
}

define( 'CHATCHI_VERSION', '0.9.0' );
define( 'CHATCHI_FILE', __FILE__ );
define( 'CHATCHI_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHATCHI_URL', plugin_dir_url( __FILE__ ) );
define( 'CHATCHI_BASENAME', plugin_basename( __FILE__ ) );
define( 'CHATCHI_MIN_PHP', '7.4' );
define( 'CHATCHI_MIN_WP', '6.0' );

/**
 * Returns the unmet environment requirements, keyed by requirement name.
 *
 * @return array<string, string> Current version of each unmet requirement.
 */
function chatchi_unmet_requirements() {
	$unmet = array();

	if ( version_compare( PHP_VERSION, CHATCHI_MIN_PHP, '<' ) ) {
		$unmet['php'] = PHP_VERSION;
	}

	$wp_version = get_bloginfo( 'version' );
	if ( version_compare( $wp_version, CHATCHI_MIN_WP, '<' ) ) {
		$unmet['wp'] = $wp_version;
	}

	return $unmet;
}

/**
 * Prints an admin notice for each unmet requirement.
 *
 * @return void
 */
function chatchi_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// The I18n module never boots when requirements are unmet.
	load_plugin_textdomain( 'chatchi', false, dirname( CHATCHI_BASENAME ) . '/languages' );

	$unmet    = chatchi_unmet_requirements();
	$messages = array();

	if ( isset( $unmet['php'] ) ) {
		$messages[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'ChatChi requires PHP %1$s or higher. Your server is running PHP %2$s.', 'chatchi' ),
			CHATCHI_MIN_PHP,
			$unmet['php']
		);
	}

	if ( isset( $unmet['wp'] ) ) {
		$messages[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'ChatChi requires WordPress %1$s or higher. You are running WordPress %2$s.', 'chatchi' ),
			CHATCHI_MIN_WP,
			$unmet['wp']
		);
	}

	foreach ( $messages as $message ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}

if ( chatchi_unmet_requirements() ) {
	add_action( 'admin_notices', 'chatchi_requirements_notice' );
	return;
}

require_once CHATCHI_PATH . 'includes/Autoloader.php';
ChatChi\Autoloader::register();

register_activation_hook( CHATCHI_FILE, array( 'ChatChi\Core\Activator', 'activate' ) );
register_deactivation_hook( CHATCHI_FILE, array( 'ChatChi\Core\Deactivator', 'deactivate' ) );

// Declared inside a condition so it only exists once requirements are met;
// top-level functions are declared at compile time, even after an early return.
if ( ! function_exists( 'chatchi' ) ) {
	/**
	 * Returns the ChatChi plugin instance.
	 *
	 * @return ChatChi\Plugin
	 */
	function chatchi() {
		return ChatChi\Plugin::instance();
	}
}

add_action(
	'plugins_loaded',
	function () {
		chatchi()->boot();
	}
);
