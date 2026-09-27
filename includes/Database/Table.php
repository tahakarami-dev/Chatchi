<?php
/**
 * Custom table names.
 *
 * @package ChatChi
 */

namespace ChatChi\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Unprefixed names of ChatChi's custom tables.
 *
 * The site prefix ($wpdb->prefix) is added at query time so every site of a
 * multisite network gets its own set of tables.
 */
final class Table {

	public const VISITORS      = 'chatchi_visitors';
	public const AGENTS        = 'chatchi_agents';
	public const CONVERSATIONS = 'chatchi_conversations';
	public const MESSAGES      = 'chatchi_messages';
	public const RATE_LIMITS   = 'chatchi_rate_limits';
	public const ATTACHMENTS   = 'chatchi_attachments';
	public const TAGS          = 'chatchi_conversation_tags';

	/**
	 * Returns every table name, unprefixed.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::VISITORS,
			self::AGENTS,
			self::CONVERSATIONS,
			self::MESSAGES,
			self::RATE_LIMITS,
			self::ATTACHMENTS,
			self::TAGS,
		);
	}
}
