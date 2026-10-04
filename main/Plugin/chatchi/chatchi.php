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

// The license check starts the plugin when the purchase is active; license
// notices come only from RTL-Theme's own manager plugin. Without the ionCube
// Loader the encoded check would stop the whole site, so it is only loaded
// when the loader is there (and ChatChi stays off without it).
if ( extension_loaded( 'ionCube Loader' ) ) {
	require_once CHATCHI_PATH . 'license/license.php';
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
