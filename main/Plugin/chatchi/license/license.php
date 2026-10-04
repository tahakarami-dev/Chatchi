<?php
/**
 * RTL-Theme license check.
 *
 * Starts ChatChi only when the purchase is active. This file is encoded
 * with ionCube by RTL-Theme before release; chatchi.php loads it only when
 * the ionCube Loader is installed. It shows no notices: asking the site
 * owner to activate the license is left to RTL-Theme's manager plugin.
 *
 * @package ChatChi
 */

defined( 'ABSPATH' ) || exit;

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
