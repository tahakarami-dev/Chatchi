<?php
/**
 * Plugin Name:       چت‌چی
 * Plugin URI:        https://www.rtl-theme.com/chatchi-wordpress-plugin/
 * Description:       گفتگوی زنده‌ی سریع و امن برای وردپرس و ووکامرس، با پنل اپراتور مستقل و حرفه‌ای. ربات هوش مصنوعی با GapGPT و AvalAI، پیشنهاد پاسخ به اپراتور، نمایش سبد خرید و سفارش‌های مشتری، دعوت خودکار به گفتگو، امتیازدهی و گزارش‌های فروش، اعلان در تلگرام و بله، پیامک با کاوه‌نگار و ملی‌پیامک، منوی واتساپ و تلگرام، سؤالات متداول و حالت تیره؛ کاملاً فارسی و راست‌چین.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            طاها کرمی
 * Author URI:        https://www.rtl-theme.com/author/taha-karami
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

define( 'CHATCHI_VERSION', '1.0.0' );
define( 'CHATCHI_FILE', __FILE__ );
define( 'CHATCHI_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHATCHI_URL', plugin_dir_url( __FILE__ ) );
define( 'CHATCHI_BASENAME', plugin_basename( __FILE__ ) );
define( 'CHATCHI_MIN_PHP', '7.4' );
define( 'CHATCHI_MIN_WP', '6.0' );

/**
 * Returns the PHP versions (major.minor) the license files can run on.
 *
 * RTL_License_*.php and the encoded license.php are ionCube files, and each
 * one runs only on the PHP versions it was encoded for; loading it anywhere
 * else (or without the ionCube Loader) stops the whole site. The versions are
 * read from each file's ionCube header ("//ICB0 81:0 82:4fe2" = PHP 8.1 and
 * 8.2), so the check follows whichever files RTL-Theme supplied. Files without
 * that header (license.php before encoding) do not narrow the list; if no file
 * has one, RTL-Theme's documented versions apply.
 *
 * @return string[] Supported versions, e.g. array( '8.1', '8.2' ).
 */
function chatchi_supported_php_versions() {
	$versions = null;

	foreach ( array( 'RTL_License_d4a7d3cf49c760e7.php', 'license.php' ) as $name ) {
		$head = @file_get_contents( CHATCHI_PATH . $name, false, null, 0, 160 );
		if ( ! is_string( $head ) || ! preg_match( '#//ICB0((?:\s+\d{2}:[0-9a-f]+)+)#i', $head, $match ) ) {
			continue;
		}

		preg_match_all( '#\b(\d)(\d):#', $match[1], $found, PREG_SET_ORDER );
		$file_versions = array();
		foreach ( $found as $version ) {
			$file_versions[] = $version[1] . '.' . $version[2];
		}

		$versions = null === $versions ? $file_versions : array_values( array_intersect( $versions, $file_versions ) );
	}

	return null === $versions ? array( '7.4', '8.1', '8.2' ) : $versions;
}

/**
 * Returns the unmet environment requirements, keyed by requirement name.
 *
 * @return array<string, string> Current version of each unmet requirement.
 */
function chatchi_unmet_requirements() {
	$unmet = array();

	if ( version_compare( PHP_VERSION, CHATCHI_MIN_PHP, '<' ) || ! in_array( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, chatchi_supported_php_versions(), true ) ) {
		$unmet['php'] = PHP_VERSION;
	}

	if ( ! extension_loaded( 'ionCube Loader' ) ) {
		$unmet['ioncube'] = '';
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
			/* translators: 1: supported PHP versions, e.g. "7.4 / 8.1 / 8.2", 2: current PHP version. */
			__( 'ChatChi runs on PHP %1$s. Your server is running PHP %2$s; please switch PHP version in your hosting panel.', 'chatchi' ),
			implode( ' / ', chatchi_supported_php_versions() ),
			$unmet['php']
		);
	}

	if ( isset( $unmet['ioncube'] ) ) {
		$messages[] = __( 'ChatChi needs the ionCube Loader. Please ask your host to enable it.', 'chatchi' );
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

// RTL-Theme license check (license.php, encoded for release). ChatChi starts
// only when the purchase is active; the license notices are RTL-Theme's own.
// The encoded file unpacks itself through the system temp folder; on a host
// that blocks it (open_basedir) it throws, so catch that instead of letting it
// stop the whole site, and tell the administrator what to ask the host for.
try {
	require_once CHATCHI_PATH . 'license.php';
} catch ( \Throwable $chatchi_license_error ) {
	error_log( 'ChatChi: license check could not run: ' . $chatchi_license_error->getMessage() );
	$GLOBALS['chatchi_license_error'] = $chatchi_license_error->getMessage();
	add_action( 'admin_notices', 'chatchi_license_error_notice' );
}

/**
 * Explains why ChatChi could not load its license check (server configuration).
 *
 * @return void
 */
function chatchi_license_error_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || empty( $GLOBALS['chatchi_license_error'] ) ) {
		return;
	}

	load_plugin_textdomain( 'chatchi', false, dirname( CHATCHI_BASENAME ) . '/languages' );
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: technical error message. */
				__( 'ChatChi could not run its license check because of a server setting (%s). Please ask your host to make the PHP temporary folder writable for your site.', 'chatchi' ),
				$GLOBALS['chatchi_license_error']
			)
		)
	);
}

// Orders are read only through the WooCommerce API, so both order storages
// (posts and HPOS tables) work; the chat does not touch the checkout.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CHATCHI_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CHATCHI_FILE, true );
		}
	}
);
