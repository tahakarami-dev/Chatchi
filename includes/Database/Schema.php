<?php
/**
 * Table definitions and creation.
 *
 * @package ChatChi
 */

namespace ChatChi\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades ChatChi's tables with dbDelta().
 *
 * dbDelta() is picky about formatting: one column per line, two spaces after
 * PRIMARY KEY, and KEY instead of INDEX. Keep the definitions in that shape.
 *
 * Relations (conversation → visitor, message → conversation, …) are enforced
 * in the application layer; WordPress tables do not use foreign keys.
 */
final class Schema {

	/**
	 * Bump whenever a table definition changes.
	 */
	public const VERSION = '8';

	public const VERSION_OPTION = 'chatchi_db_version';

	/**
	 * Columns added after the first release, checked after every upgrade:
	 * dbDelta() does not report when it fails to add one.
	 */
	private const REQUIRED_COLUMNS = array(
		Table::CONVERSATIONS => array( 'open_visitor_id', 'last_message_id', 'page_url', 'visitor_typing_at', 'agent_typing_at', 'rating', 'rated_at', 'bot_handoff_at' ),
		Table::MESSAGES      => array( 'client_id', 'read_at', 'attachment_id', 'internal' ),
		Table::AGENTS        => array( 'avatar_id', 'is_active', 'status', 'last_seen_at' ),
		Table::VISITORS      => array( 'token_hash', 'user_id', 'phone', 'current_url', 'cart', 'context_at' ),
		Table::RATE_LIMITS   => array( 'bucket', 'hits', 'expires_at' ),
		Table::ATTACHMENTS   => array( 'conversation_id', 'message_id', 'path' ),
		Table::TAGS          => array( 'conversation_id', 'tag' ),
	);

	private \wpdb $db;

	/**
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Creates missing tables and applies definition changes.
	 *
	 * @throws DatabaseException When a table is still missing afterwards.
	 */
	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $this->definitions() );

		// Read before the checks below run their own queries and reset it.
		$error = $this->db->last_error;

		$this->reconcile();

		$missing = $this->missing_tables();

		if ( ! $missing ) {
			$missing = $this->missing_columns();
		}

		if ( $missing ) {
			throw new DatabaseException(
				trim(
					sprintf(
						'Could not create or upgrade %s. %s',
						implode( ', ', $missing ),
						$error
					)
				)
			);
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Returns the prefixed names of tables that do not exist.
	 *
	 * @return string[]
	 */
	public function missing_tables(): array {
		$missing = array();

		foreach ( Table::all() as $table ) {
			$name  = $this->db->prefix . $table;
			$found = $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $name ) ) );

			if ( $found !== $name ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}

	/**
	 * Removes what stops ChatChi from writing to its own tables.
	 *
	 * A table can carry columns and indexes that are not ChatChi's, left by
	 * an older or different plugin that used the same table name. dbDelta()
	 * only adds, so they stay. Two kinds break every insert and are fixed:
	 *
	 * - a unique index that is not ChatChi's: rows ChatChi writes leave its
	 *   column at the default, so the second row is a duplicate. The index
	 *   is dropped; no data is removed.
	 * - a NOT NULL column without a default: strict SQL modes refuse rows
	 *   that do not set it. The column is made nullable; its data is kept.
	 *
	 * MySQL and MariaDB only; other engines are left alone.
	 *
	 * @return string[] What was changed, for the log.
	 */
	public function reconcile(): array {
		if ( ! $this->is_mysql() ) {
			return array();
		}

		$changes = array();

		foreach ( $this->expected() as $table => $expected ) {
			$name = $this->db->prefix . $table;

			if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $name ) ) ) !== $name ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table names; repairing the plugin's own tables.
			$unique = array();
			foreach ( (array) $this->db->get_results( "SHOW INDEX FROM `{$name}`" ) as $index ) {
				if ( 0 === (int) $index->Non_unique && 'PRIMARY' !== $index->Key_name ) {
					$unique[ $index->Key_name ] = true;
				}
			}

			foreach ( array_keys( $unique ) as $key ) {
				if ( ! in_array( strtolower( $key ), $expected['indexes'], true ) && preg_match( '/^[A-Za-z0-9_]+$/', $key ) ) {
					$this->db->query( "ALTER TABLE `{$name}` DROP INDEX `{$key}`" );
					$changes[] = "dropped unique index {$name}.{$key}";
				}
			}

			foreach ( (array) $this->db->get_results( "SHOW FULL COLUMNS FROM `{$name}`" ) as $column ) {
				$field = (string) $column->Field;

				if ( in_array( strtolower( $field ), $expected['columns'], true ) || 'NO' !== $column->Null || null !== $column->Default
					|| false !== stripos( (string) $column->Extra, 'auto_increment' ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $field )
					|| ! preg_match( '/^[a-z]+(\([0-9, ]+\))?( unsigned)?$/i', (string) $column->Type ) ) {
					continue;
				}

				$this->db->query( "ALTER TABLE `{$name}` MODIFY `{$field}` {$column->Type} NULL" );
				$changes[] = "made {$name}.{$field} nullable";
			}
			// phpcs:enable
		}

		if ( $changes ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Schema repairs must reach the server log.
			error_log( '[ChatChi] Repaired tables: ' . implode( '; ', $changes ) );
		}

		return $changes;
	}

	/**
	 * Whether a unique key name belongs to ChatChi's own tables.
	 *
	 * @param string $key Index name, as in a duplicate-entry error.
	 */
	public function is_own_key( string $key ): bool {
		$key = strtolower( $key );

		foreach ( $this->expected() as $expected ) {
			if ( 'primary' === $key || in_array( $key, $expected['indexes'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns ChatChi's columns and index names per table, read from the
	 * CREATE TABLE statements.
	 *
	 * @return array<string, array{columns: string[], indexes: string[]}>
	 */
	private function expected(): array {
		$tables = array();

		foreach ( $this->definitions() as $sql ) {
			if ( ! preg_match( '/CREATE TABLE \S*?(chatchi_[a-z_]+) \(/', $sql, $m ) ) {
				continue;
			}

			$columns = array();
			$indexes = array();

			foreach ( preg_split( '/\R/', $sql ) as $line ) {
				$line = trim( $line );

				if ( preg_match( '/^(?:UNIQUE )?KEY ([a-z_]+) \(/i', $line, $k ) ) {
					$indexes[] = strtolower( $k[1] );
				} elseif ( preg_match( '/^([a-z_]+) [a-z]+/i', $line, $c ) && ! preg_match( '/^(PRIMARY|CREATE)\b/i', $line ) ) {
					$columns[] = strtolower( $c[1] );
				}
			}

			$tables[ $m[1] ] = array(
				'columns' => $columns,
				'indexes' => $indexes,
			);
		}

		return $tables;
	}

	/**
	 * Whether the database is MySQL or MariaDB (not, e.g., SQLite).
	 */
	private function is_mysql(): bool {
		return ! ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) && ! is_a( $this->db, 'WP_SQLite_DB' );
	}

	/**
	 * Returns "table.column" for every required column that does not exist.
	 *
	 * @return string[]
	 */
	public function missing_columns(): array {
		$missing = array();

		foreach ( self::REQUIRED_COLUMNS as $table => $columns ) {
			$name     = $this->db->prefix . $table;
			$existing = array_map( 'strtolower', (array) $this->db->get_col( "SHOW COLUMNS FROM {$name}", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.

			foreach ( $columns as $column ) {
				if ( ! in_array( $column, $existing, true ) ) {
					$missing[] = $name . '.' . $column;
				}
			}
		}

		return $missing;
	}

	/**
	 * Returns the CREATE TABLE statements for the current site.
	 *
	 * @return string[]
	 */
	private function definitions(): array {
		$prefix  = $this->db->prefix;
		$collate = $this->db->get_charset_collate();

		$visitors      = $prefix . Table::VISITORS;
		$agents        = $prefix . Table::AGENTS;
		$conversations = $prefix . Table::CONVERSATIONS;
		$messages      = $prefix . Table::MESSAGES;
		$rate_limits   = $prefix . Table::RATE_LIMITS;
		$attachments   = $prefix . Table::ATTACHMENTS;
		$tags          = $prefix . Table::TAGS;

		// token_hash stores a hash of the visitor's cookie token, never the token itself.
		// open_visitor_id equals visitor_id while a conversation is open and is NULL once
		// closed; its unique key allows at most one open conversation per visitor.
		return array(
			"CREATE TABLE {$visitors} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
token_hash char(64) NOT NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
name varchar(100) NOT NULL DEFAULT '',
email varchar(100) NOT NULL DEFAULT '',
phone varchar(20) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
last_seen_at datetime NOT NULL,
current_url varchar(2048) NOT NULL DEFAULT '',
current_title varchar(255) NOT NULL DEFAULT '',
cart text NULL,
context_at datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token_hash (token_hash),
KEY user_id (user_id)
) {$collate};",

			"CREATE TABLE {$agents} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
user_id bigint(20) unsigned NOT NULL,
display_name varchar(100) NOT NULL,
avatar_id bigint(20) unsigned NOT NULL DEFAULT 0,
is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
status varchar(10) NOT NULL DEFAULT 'online',
last_seen_at datetime NULL DEFAULT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY user_id (user_id),
KEY is_active (is_active)
) {$collate};",

			"CREATE TABLE {$conversations} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
visitor_id bigint(20) unsigned NOT NULL,
agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
status varchar(20) NOT NULL DEFAULT 'new',
page_url varchar(2048) NOT NULL DEFAULT '',
last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
last_message_at datetime NOT NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
closed_at datetime NULL DEFAULT NULL,
visitor_typing_at datetime NULL DEFAULT NULL,
agent_typing_at datetime NULL DEFAULT NULL,
rating tinyint(1) unsigned NULL DEFAULT NULL,
rating_comment text NULL,
rated_at datetime NULL DEFAULT NULL,
bot_handoff_at datetime NULL DEFAULT NULL,
open_visitor_id bigint(20) unsigned NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY open_visitor (open_visitor_id),
KEY visitor_status (visitor_id,status),
KEY status_activity (status,last_message_at),
KEY last_message_at (last_message_at),
KEY agent_id (agent_id),
KEY created_at (created_at)
) {$collate};",

			"CREATE TABLE {$messages} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
conversation_id bigint(20) unsigned NOT NULL,
sender_type varchar(20) NOT NULL,
sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
body text NOT NULL,
client_id char(36) NULL DEFAULT NULL,
attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
internal tinyint(1) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
read_at datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY conversation_id (conversation_id,id),
UNIQUE KEY conversation_client (conversation_id,client_id)
) {$collate};",

			// One row per rate-limit bucket (action, subject and time window, hashed).
			// Subjects such as IP addresses are never stored in readable form.
			"CREATE TABLE {$rate_limits} (
bucket char(64) NOT NULL,
hits int(10) unsigned NOT NULL DEFAULT 0,
expires_at int(10) unsigned NOT NULL,
PRIMARY KEY  (bucket),
KEY expires_at (expires_at)
) {$collate};",

			// Files sent in conversations. The files themselves are stored under
			// uploads/chatchi-files with unguessable names and only served
			// through the REST API, after an access check.
			"CREATE TABLE {$attachments} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
conversation_id bigint(20) unsigned NOT NULL,
message_id bigint(20) unsigned NOT NULL DEFAULT 0,
file_name varchar(200) NOT NULL DEFAULT '',
mime varchar(100) NOT NULL DEFAULT '',
size bigint(20) unsigned NOT NULL DEFAULT 0,
width int(10) unsigned NOT NULL DEFAULT 0,
height int(10) unsigned NOT NULL DEFAULT 0,
path varchar(255) NOT NULL DEFAULT '',
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY conversation_id (conversation_id),
KEY message_id (message_id)
) {$collate};",

			// Labels agents put on conversations, e.g. "billing".
			"CREATE TABLE {$tags} (
conversation_id bigint(20) unsigned NOT NULL,
tag varchar(40) NOT NULL,
PRIMARY KEY  (conversation_id,tag),
KEY tag (tag)
) {$collate};",
		);
	}
}
