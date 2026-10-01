<?php
/**
 * Plain-text input cleaning.
 *
 * @package ChatChi
 */

namespace ChatChi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans user-entered plain text such as names and messages.
 *
 * Text is stored as typed and escaped when displayed. WordPress's
 * sanitize_text_field() is not used because it stores a lone "<" or "&" as
 * HTML entities, which then show up literally wherever the text is shown.
 */
final class PlainText {

	/**
	 * Reduces input to a single line of plain text.
	 *
	 * Removes script/style blocks, real HTML tags, control characters and
	 * invalid UTF-8, and collapses whitespace. Keeps a lone "<" or "&", as in
	 * "Ali <3" or "Tom & Jerry".
	 *
	 * @param string $raw Raw input.
	 */
	public static function line( string $raw ): string {
		$text = wp_check_invalid_utf8( $raw, true );
		$text = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $text );
		$text = (string) preg_replace( '#</?[a-z][^>]*>#i', '', $text );
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Normalizes multi-line plain text such as a message.
	 *
	 * Text is kept as typed, including anything that looks like HTML, because
	 * it is always rendered as text. Line endings become "\n", control
	 * characters other than tab and newline are removed, and at most one
	 * empty line is kept between paragraphs. Zero-width non-joiners and other
	 * formatting characters used in Persian are not affected.
	 *
	 * @param string $raw Raw input.
	 */
	public static function paragraphs( string $raw ): string {
		$text = wp_check_invalid_utf8( $raw, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}
}
