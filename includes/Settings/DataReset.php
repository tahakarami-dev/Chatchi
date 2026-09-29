<?php
/**
 * Resetting and deleting ChatChi's data from the panel.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

use ChatChi\Ai\Assistant;
use ChatChi\Attachments\FileStore;
use ChatChi\Canned\CannedResponses;
use ChatChi\Database\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Three levels, each including nothing it does not name:
 *
 * - settings: every setting back to its default; conversations, agents
 *   and saved replies stay.
 * - conversations: conversations, messages, visitors, files, tags and
 *   ratings are deleted; settings, agents and saved replies stay.
 * - all: both of the above, plus agents, saved replies, AI usage and each
 *   agent's panel preferences, as on a fresh install. Administrators get
 *   their agent record back the next time they open the panel.
 *
 * Tables are emptied, never dropped, so the plugin keeps working at once.
 */
final class DataReset {

	public const SCOPES = array( 'settings', 'conversations', 'all' );

	private \wpdb $db;

	private Settings $settings;

	private FileStore $files;

	/**
	 * @param \wpdb     $db       Database connection.
	 * @param Settings  $settings Plugin settings.
	 * @param FileStore $files    Files sent in conversations.
	 */
	public function __construct( \wpdb $db, Settings $settings, FileStore $files ) {
		$this->db       = $db;
		$this->settings = $settings;
		$this->files    = $files;
	}

	/**
	 * Row counts shown before deleting.
	 *
	 * @return array{conversations: int, messages: int, visitors: int, files: int, agents: int, canned: int}
	 */
	public function summary(): array {
		$canned = get_option( CannedResponses::OPTION, array() );

		return array(
			'conversations' => $this->count( Table::CONVERSATIONS ),
			'messages'      => $this->count( Table::MESSAGES ),
			'visitors'      => $this->count( Table::VISITORS ),
			'files'         => $this->count( Table::ATTACHMENTS ),
			'agents'        => $this->count( Table::AGENTS ),
			'canned'        => is_array( $canned ) ? count( $canned ) : 0,
		);
	}

	/**
	 * Runs one reset.
	 *
	 * @param string $scope One of SCOPES.
	 * @return array<string, int>|\WP_Error What was deleted, as counts.
	 */
	public function run( string $scope ) {
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			return new \WP_Error( 'chatchi_invalid_scope', __( 'Choose what to reset.', 'chatchi' ), array( 'status' => 400 ) );
		}

		$before = $this->summary();
		$done   = array();

		if ( 'settings' === $scope || 'all' === $scope ) {
			$this->settings->reset();
			$done['settings'] = 1;
		}

		if ( 'conversations' === $scope || 'all' === $scope ) {
			$this->delete_conversations();
			$done += array(
				'conversations' => $before['conversations'],
				'messages'      => $before['messages'],
				'visitors'      => $before['visitors'],
				'files'         => $before['files'],
			);
		}

		if ( 'all' === $scope ) {
			$this->empty_table( Table::AGENTS );
			delete_option( CannedResponses::OPTION );
			delete_option( Assistant::USAGE_OPTION );
			delete_metadata( 'user', 0, 'chatchi_panel_theme', '', true );
			$done['agents'] = $before['agents'];
			$done['canned'] = $before['canned'];
		}

		/**
		 * Fires after data was reset from the panel.
		 *
		 * @param string             $scope settings, conversations or all.
		 * @param array<string, int> $done  What was deleted, as counts.
		 */
		do_action( 'chatchi_data_reset', $scope, $done );

		return $done;
	}

	/**
	 * Conversations and everything that belongs to them.
	 */
	private function delete_conversations(): void {
		foreach ( array( Table::MESSAGES, Table::TAGS, Table::ATTACHMENTS, Table::CONVERSATIONS, Table::VISITORS, Table::RATE_LIMITS ) as $table ) {
			$this->empty_table( $table );
		}

		$this->files->clear();

		// Pending SMS checks, SMS cooldowns and AI answers in progress.
		wp_unschedule_hook( 'chatchi_sms_check' );
		$this->db->query( "DELETE FROM {$this->db->options} WHERE option_name LIKE 'chatchi\_bot\_lock\_%' OR option_name LIKE '\_transient\_chatchi\_sms\_%' OR option_name LIKE '\_transient\_timeout\_chatchi\_sms\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param string $table Unprefixed name from Table.
	 */
	private function empty_table( string $table ): void {
		// Fixed table names from Table plus the site prefix.
		$this->db->query( "DELETE FROM {$this->db->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param string $table Unprefixed name from Table.
	 */
	private function count( string $table ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
