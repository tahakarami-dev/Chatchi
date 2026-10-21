<?php
/**
 * Frequently asked questions shown in the widget.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Questions and answers visitors can read before starting a chat.
 *
 * An item: id, question (one line) and answer (plain text paragraphs;
 * links in it are made clickable by the widget, never HTML).
 */
final class FaqItems {

	public const MAX_ITEMS = 30;

	public const QUESTION_MAX_LENGTH = 150;

	public const ANSWER_MAX_LENGTH = 1000;

	/**
	 * Validates items strictly, for saving.
	 *
	 * @param mixed $value Raw items.
	 * @return array<int, array{id: string, question: string, answer: string}>|\WP_Error
	 */
	public static function clean( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'invalid', __( 'The questions could not be read. Please try again.', 'chatchi' ) );
		}

		if ( count( $value ) > self::MAX_ITEMS ) {
			/* translators: %d: maximum number of questions. */
			return new \WP_Error( 'invalid', sprintf( __( 'You can add at most %d questions.', 'chatchi' ), self::MAX_ITEMS ) );
		}

		$items = array();

		foreach ( array_values( $value ) as $index => $raw ) {
			$item = self::item( is_array( $raw ) ? $raw : array() );

			if ( '' === $item['question'] || '' === $item['answer'] ) {
				/* translators: %d: question number. */
				return new \WP_Error( 'invalid', sprintf( __( 'Write both the question and the answer of item %d.', 'chatchi' ), $index + 1 ) );
			}

			if ( mb_strlen( $item['question'] ) > self::QUESTION_MAX_LENGTH ) {
				/* translators: 1: question number, 2: maximum characters. */
				return new \WP_Error( 'invalid', sprintf( __( 'Question %1$d can be at most %2$d characters.', 'chatchi' ), $index + 1, self::QUESTION_MAX_LENGTH ) );
			}

			if ( mb_strlen( $item['answer'] ) > self::ANSWER_MAX_LENGTH ) {
				/* translators: 1: question number, 2: maximum characters. */
				return new \WP_Error( 'invalid', sprintf( __( 'The answer of item %1$d can be at most %2$d characters.', 'chatchi' ), $index + 1, self::ANSWER_MAX_LENGTH ) );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Brings stored items back into shape; incomplete ones are dropped.
	 *
	 * @param mixed $value Stored value.
	 * @return array<int, array{id: string, question: string, answer: string}>
	 */
	public static function repair( $value ): array {
		$items = array();

		foreach ( is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_ITEMS ) : array() as $raw ) {
			$item = self::item( is_array( $raw ) ? $raw : array() );

			if ( '' !== $item['question'] && '' !== $item['answer'] ) {
				$item['question'] = mb_substr( $item['question'], 0, self::QUESTION_MAX_LENGTH );
				$item['answer']   = mb_substr( $item['answer'], 0, self::ANSWER_MAX_LENGTH );
				$items[]          = $item;
			}
		}

		return $items;
	}

	/**
	 * @param array<string, mixed> $raw Raw item.
	 * @return array{id: string, question: string, answer: string}
	 */
	private static function item( array $raw ): array {
		$id = isset( $raw['id'] ) && is_string( $raw['id'] ) && preg_match( '/^[a-z0-9]{1,20}$/', $raw['id'] ) ? $raw['id'] : strtolower( wp_generate_password( 8, false, false ) );

		return array(
			'id'       => $id,
			'question' => PlainText::line( is_string( $raw['question'] ?? null ) ? $raw['question'] : '' ),
			'answer'   => PlainText::paragraphs( is_string( $raw['answer'] ?? null ) ? $raw['answer'] : '' ),
		);
	}
}
