<?php
/**
 * RTL-Theme license check, exactly as RTL-Theme's guide gives it.
 *
 * ChatChi starts only when the purchase is active. Before release this file
 * is encoded with RTL-Theme's encoder; RTL_License_d4a7d3cf49c760e7.php sits
 * next to it and must never be changed.
 *
 * @package ChatChi
 */

defined( 'ABSPATH' ) || exit;

// --------------------------------------------------------------------------------------------------- Start RTL License
$rtlLicenseClassName  = 'RTL_License_d4a7d3cf49c760e7';
$rtlLicenseFilePath   = __DIR__ . DIRECTORY_SEPARATOR . $rtlLicenseClassName . '.php';
$rtlLicenseFileHash   = @sha1_file($rtlLicenseFilePath);

if ( $rtlLicenseFileHash === 'c1693a5f5e49d7200ec716f39d289e00925db439' && file_exists($rtlLicenseFilePath) ) {
	require_once $rtlLicenseFilePath;

	if ( class_exists($rtlLicenseClassName) && method_exists($rtlLicenseClassName, 'isActive') ) {
		$rtlLicenseClass = new $rtlLicenseClassName();

		if ( $rtlLicenseClass->{'isActive'}() === true ) {
			// Product is Active Now, Enable Pro Features
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
