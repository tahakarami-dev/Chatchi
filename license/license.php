<?php
/**
 * RTL-Theme license check.
 *
 * Starts ChatChi only when the purchase is active. This file is encoded
 * with ionCube by RTL-Theme before release; chatchi.php loads it only when
 * the ionCube Loader is installed.
 *
 * @package ChatChi
 */

defined( 'ABSPATH' ) || exit;

$chatchi_licensed = false;

// --------------------------------------------------------------------------------------------------- Start RTL License
$rtlLicenseClassName  = 'RTL_License_d4a7d3cf49c760e7';
$rtlLicenseFilePath   = __DIR__ . DIRECTORY_SEPARATOR . $rtlLicenseClassName . '.php';
$rtlLicenseFileHash   = @sha1_file($rtlLicenseFilePath);

if ( $rtlLicenseFileHash === '952626f1eaeb55bdad784d1b70408827ae4b4a60' && file_exists($rtlLicenseFilePath) ) {
	require_once $rtlLicenseFilePath;

	if ( class_exists($rtlLicenseClassName) && method_exists($rtlLicenseClassName, 'isActive') ) {
		$rtlLicenseClass = new $rtlLicenseClassName();

		if ( $rtlLicenseClass->{'isActive'}() === true ) {
			// Product is Active Now, Enable Pro Features
			$chatchi_licensed = true;

			add_action(
				'plugins_loaded',
				function () {
					chatchi()->boot();
				}
			);
		}
	}
}
// ----------------------------------------------------------------------------------------------------- End RTL License

if ( ! $chatchi_licensed ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			load_plugin_textdomain( 'chatchi', false, dirname( CHATCHI_BASENAME ) . '/languages' );

			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'ChatChi is not active yet. Activate the license of your RTL-Theme purchase to start using it.', 'chatchi' )
			);
		}
	);
}
