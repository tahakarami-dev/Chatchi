<?php
/**
 * Removes all ChatChi data when the plugin is deleted from the Plugins screen.
 *
 * The plugin itself is not loaded during uninstall, so this file is
 * self-contained.
 *
 * @package ChatChi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes ChatChi data from the current site.
 *
 * @return void
 */
function chatchi_uninstall_site() {
	$options = array(
		'chatchi_version',
		'chatchi_installed_at',
		'chatchi_install_lock',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

if ( is_multisite() ) {
	$chatchi_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $chatchi_site_ids as $chatchi_site_id ) {
		switch_to_blog( $chatchi_site_id );
		chatchi_uninstall_site();
		restore_current_blog();
	}
} else {
	chatchi_uninstall_site();
}
