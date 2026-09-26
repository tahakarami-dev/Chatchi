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
	public const VERSION = '1';

	public const VERSION_OPTION = 'chatchi_db_version';

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

		// Read before missing_tables() runs its own queries and resets it.
		$error   = $this->db->last_error;
		$missing = $this->missing_tables();

		if ( $missing ) {
			throw new DatabaseException(
				trim(
					sprintf(
						'Could not create the tables %s. %s',
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

		// token_hash stores a hash of the visitor's cookie token, never the token itself.
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
PRIMARY KEY  (id),
KEY visitor_status (visitor_id,status),
KEY status_activity (status,last_message_at),
KEY last_message_at (last_message_at),
KEY agent_id (agent_id)
) {$collate};",

			"CREATE TABLE {$messages} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
conversation_id bigint(20) unsigned NOT NULL,
sender_type varchar(20) NOT NULL,
sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
body text NOT NULL,
client_id char(36) NULL DEFAULT NULL,
created_at datetime NOT NULL,
read_at datetime NULL DEFAULT NULL,
PRIMARY KEY  (id),
KEY conversation_id (conversation_id,id),
UNIQUE KEY conversation_client (conversation_id,client_id)
) {$collate};",
		);
	}
}
