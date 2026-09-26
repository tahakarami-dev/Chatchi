<?php
/**
 * Plain-text input cleaning.
 *
 * @package ChatChi
 */

namespace ChatChi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans short user-entered text such as names.
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
}
