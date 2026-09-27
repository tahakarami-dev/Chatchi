<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Conversations, visitors, agents and settings are only removed when the
 * site's "Delete all data when the plugin is deleted" setting is on; by
 * default they are kept, so reinstalling brings everything back.
 *
 * The plugin itself is not loaded during uninstall, so this file is
 * self-contained. Keep the lists below in sync with ChatChi\Database\Table
 * and the options used across the plugin.
 *
 * @package ChatChi
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes ChatChi tables and options from the current site, when the site
 * asked for it. Temporary data is always removed.
 *
 * @return void
 */
function chatchi_uninstall_site() {
	global $wpdb;

	wp_clear_scheduled_hook( 'chatchi_purge_rate_limits' );
	// Pending SMS checks (one per conversation, each with its own argument).
	wp_unschedule_hook( 'chatchi_sms_check' );
	delete_option( 'chatchi_install_lock' );
	delete_transient( 'chatchi_install_backoff' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chatchi_rate_limits" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SchemaChange -- Temporary counters, recreated on install.

	$settings = get_option( 'chatchi_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	$tables = array(
		'chatchi_messages',
		'chatchi_conversations',
		'chatchi_agents',
		'chatchi_visitors',
		'chatchi_attachments',
		'chatchi_conversation_tags',
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
		'chatchi_canned_responses',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'chatchi_install_backoff' );

	chatchi_uninstall_files();
}

/**
 * Deletes files sent in conversations (uploads/chatchi-files) on the
 * current site.
 *
 * @return void
 */
function chatchi_uninstall_files() {
	$uploads = wp_upload_dir( null, false );

	if ( empty( $uploads['basedir'] ) ) {
		return;
	}

	$base = trailingslashit( $uploads['basedir'] ) . 'chatchi-files';

	if ( ! is_dir( $base ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own upload folder.
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}

	rmdir( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own upload folder.
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
