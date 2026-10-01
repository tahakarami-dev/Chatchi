<?php
/**
 * Proactive (automatic) chat invitations.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Rules for inviting visitors to chat: on which pages, after how many
 * seconds, with what message, and how often.
 *
 * A rule: id, enabled, target ("all", "home", "product", "shop", "cart",
 * "checkout" or "url"), url (text the address must contain, for "url"),
 * delay (seconds, 0–600), message (≤ 300 characters) and frequency
 * ("session", "day" or "always").
 */
final class ProactiveRules {

	public const MAX_RULES = 10;

	public const MESSAGE_MAX_LENGTH = 300;

	public const TARGETS = array( 'all', 'home', 'product', 'shop', 'cart', 'checkout', 'url' );

	public const FREQUENCIES = array( 'session', 'day', 'always' );

	/**
	 * Validates rules strictly, for saving.
	 *
	 * @param mixed $value Raw rules.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function clean( $value ) {
		if ( ! is_array( $value ) ) {
			return self::error( __( 'The invitations could not be read. Please try again.', 'chatchi' ) );
		}

		if ( count( $value ) > self::MAX_RULES ) {
			/* translators: %d: maximum number of invitations. */
			return self::error( sprintf( __( 'You can have at most %d invitations.', 'chatchi' ), self::MAX_RULES ) );
		}

		$rules = array();

		foreach ( array_values( $value ) as $index => $raw ) {
			$rule = is_array( $raw ) ? self::rule( $raw ) : null;

			if ( null === $rule ) {
				/* translators: %d: invitation number. */
				return self::error( sprintf( __( 'Invitation %d is not complete.', 'chatchi' ), $index + 1 ) );
			}

			if ( '' === $rule['message'] ) {
				/* translators: %d: invitation number. */
				return self::error( sprintf( __( 'Write the message of invitation %d.', 'chatchi' ), $index + 1 ) );
			}

			if ( mb_strlen( $rule['message'] ) > self::MESSAGE_MAX_LENGTH ) {
				/* translators: 1: invitation number, 2: maximum characters. */
				return self::error( sprintf( __( 'The message of invitation %1$d can be at most %2$d characters.', 'chatchi' ), $index + 1, self::MESSAGE_MAX_LENGTH ) );
			}

			if ( 'url' === $rule['target'] && '' === $rule['url'] ) {
				/* translators: %d: invitation number. */
				return self::error( sprintf( __( 'Enter the page address for invitation %d.', 'chatchi' ), $index + 1 ) );
			}

			$rules[] = $rule;
		}

		return $rules;
	}

	/**
	 * Brings stored rules back into shape; broken rules are dropped.
	 *
	 * @param mixed $value Stored value.
	 * @return array<int, array<string, mixed>>
	 */
	public static function repair( $value ): array {
		$rules = array();

		foreach ( is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_RULES ) : array() as $raw ) {
			$rule = is_array( $raw ) ? self::rule( $raw ) : null;

			if ( $rule && '' !== $rule['message'] ) {
				$rule['message'] = mb_substr( $rule['message'], 0, self::MESSAGE_MAX_LENGTH );
				$rules[]         = $rule;
			}
		}

		return $rules;
	}

	/**
	 * Enabled rules, as sent to the widget.
	 *
	 * @param array<int, array<string, mixed>> $rules Rules.
	 * @return array<int, array<string, mixed>>
	 */
	public static function active( array $rules ): array {
		return array_values(
			array_map(
				static function ( array $rule ): array {
					unset( $rule['enabled'] );
					return $rule;
				},
				array_filter(
					$rules,
					static function ( array $rule ): bool {
						return $rule['enabled'];
					}
				)
			)
		);
	}

	/**
	 * One rule, cleaned; null when a field is invalid.
	 *
	 * @param array<string, mixed> $raw Raw rule.
	 * @return array<string, mixed>|null
	 */
	private static function rule( array $raw ): ?array {
		$target    = $raw['target'] ?? 'all';
		$frequency = $raw['frequency'] ?? 'session';
		$delay     = $raw['delay'] ?? 0;

		if ( ! in_array( $target, self::TARGETS, true ) || ! in_array( $frequency, self::FREQUENCIES, true ) ) {
			return null;
		}

		if ( ! is_numeric( $delay ) || (int) $delay < 0 || (int) $delay > 600 || (string) (int) $delay !== trim( (string) $delay ) ) {
			return null;
		}

		$id = isset( $raw['id'] ) && is_string( $raw['id'] ) && preg_match( '/^[a-z0-9]{1,20}$/', $raw['id'] ) ? $raw['id'] : strtolower( wp_generate_password( 8, false, false ) );

		return array(
			'id'        => $id,
			'enabled'   => rest_sanitize_boolean( $raw['enabled'] ?? true ),
			'target'    => $target,
			'url'       => 'url' === $target ? mb_substr( PlainText::line( is_string( $raw['url'] ?? null ) ? $raw['url'] : '' ), 0, 200 ) : '',
			'delay'     => (int) $delay,
			'message'   => PlainText::paragraphs( is_string( $raw['message'] ?? null ) ? $raw['message'] : '' ),
			'frequency' => $frequency,
		);
	}

	/**
	 * @param string $message Message.
	 */
	private static function error( string $message ): \WP_Error {
		return new \WP_Error( 'invalid', $message );
	}
}
