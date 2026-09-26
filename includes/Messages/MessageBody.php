<?php
/**
 * Message text normalization and validation.
 *
 * @package ChatChi
 */

namespace ChatChi\Messages;

use ChatChi\Settings\Settings;
use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans the text of a chat message.
 *
 * Messages are plain text and are stored as typed: HTML is not stripped
 * (a message like "x < y" or "<b>" must arrive intact) and every consumer
 * must render it as text (esc_html() in PHP, textContent in JavaScript).
 * Percent-encoded sequences are kept too, so pasted links with Persian
 * slugs survive, which sanitize_textarea_field() would break.
 */
final class MessageBody {

	private const DEFAULT_MAX_LENGTH = 2000;

	private ?Settings $settings;

	/**
	 * @param Settings|null $settings Plugin settings; without them the built-in defaults apply.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Returns the maximum message length in characters.
	 */
	public function max_length(): int {
		/**
		 * Filters the maximum message length, in characters.
		 *
		 * @param int $length Maximum length. Clamped to 1–10000.
		 */
		$default = $this->settings ? (int) $this->settings->get( 'message_max_length' ) : self::DEFAULT_MAX_LENGTH;
		$length  = (int) apply_filters( 'chatchi_message_max_length', $default );

		return min( 10000, max( 1, $length ) );
	}

	/**
	 * Normalizes and validates a message.
	 *
	 * @param mixed $raw Raw input.
	 * @return string|\WP_Error Clean text, or an error.
	 */
	public function clean( $raw ) {
		$text = PlainText::paragraphs( is_scalar( $raw ) ? (string) $raw : '' );

		if ( '' === $text ) {
			return new \WP_Error(
				'chatchi_empty_message',
				__( 'Type a message first.', 'chatchi' ),
				array( 'status' => 400 )
			);
		}

		$max = $this->max_length();

		if ( mb_strlen( $text ) > $max ) {
			return new \WP_Error(
				'chatchi_message_too_long',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'Messages can be at most %d characters.', 'chatchi' ), $max ),
				array( 'status' => 400 )
			);
		}

		return $text;
	}
}
