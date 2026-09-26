<?php
/**
 * Message text normalization and validation.
 *
 * @package ChatChi
 */

namespace ChatChi\Messages;

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

	/**
	 * Returns the maximum message length in characters.
	 */
	public function max_length(): int {
		/**
		 * Filters the maximum message length, in characters.
		 *
		 * @param int $length Maximum length. Clamped to 1–10000.
		 */
		$length = (int) apply_filters( 'chatchi_message_max_length', self::DEFAULT_MAX_LENGTH );

		return min( 10000, max( 1, $length ) );
	}

	/**
	 * Normalizes and validates a message.
	 *
	 * @param mixed $raw Raw input.
	 * @return string|\WP_Error Clean text, or an error.
	 */
	public function clean( $raw ) {
		$text = is_scalar( $raw ) ? (string) $raw : '';

		$text = wp_check_invalid_utf8( $text, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		// Control characters except tab and newline. Zero-width non-joiner and
		// other formatting characters used in Persian are not affected.
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		// At most one empty line between paragraphs.
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = trim( $text );

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
