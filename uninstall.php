<?php
/**
 * Removes all ChatChi data when the plugin is deleted from the Plugins screen.
 *
 * The plugin itself is not loaded during uninstall, so this file is
 * self-contained. Keep the lists below in sync with ChatChi\Database\Table
 * and the options used across the plugin.
 *
 * @package ChatChi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes ChatChi tables and options from the current site.
 *
 * @return void
 */
function chatchi_uninstall_site() {
	global $wpdb;

	$tables = array(
		'chatchi_rate_limits',
		'chatchi_messages',
		'chatchi_conversations',
		'chatchi_agents',
		'chatchi_visitors',
	);

	foreach ( $tables as $table ) {
		// Table names come from the fixed list above plus the site prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$options = array(
		'chatchi_version',
		'chatchi_installed_at',
		'chatchi_install_lock',
		'chatchi_install_error',
		'chatchi_db_version',
		'chatchi_settings',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'chatchi_install_backoff' );
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
