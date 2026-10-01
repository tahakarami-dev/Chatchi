<?php
/**
 * Other ways to reach the site: WhatsApp, Telegram, Bale and phone.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

use ChatChi\Support\PlainText;
use ChatChi\Visitors\ProfileValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Contact channels shown next to the chat in the widget's menu.
 *
 * A channel: type ("whatsapp", "telegram", "bale" or "phone"), value (the
 * number or username, stored normalized) and an optional label. Links are
 * built here from the normalized value only, so a stored value can never
 * become an arbitrary URL.
 */
final class ContactChannels {

	public const MAX_CHANNELS = 6;

	public const LABEL_MAX_LENGTH = 40;

	public const TYPES = array( 'whatsapp', 'telegram', 'bale', 'phone' );

	/**
	 * Validates channels strictly, for saving.
	 *
	 * @param mixed $value Raw channels.
	 * @return array<int, array{type: string, value: string, label: string}>|\WP_Error
	 */
	public static function clean( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'invalid', __( 'The contact channels could not be read. Please try again.', 'chatchi' ) );
		}

		if ( count( $value ) > self::MAX_CHANNELS ) {
			/* translators: %d: maximum number of channels. */
			return new \WP_Error( 'invalid', sprintf( __( 'You can add at most %d contact channels.', 'chatchi' ), self::MAX_CHANNELS ) );
		}

		$channels = array();

		foreach ( array_values( $value ) as $index => $raw ) {
			$type = is_array( $raw ) && isset( $raw['type'] ) && in_array( $raw['type'], self::TYPES, true ) ? $raw['type'] : null;

			if ( null === $type ) {
				/* translators: %d: channel number. */
				return new \WP_Error( 'invalid', sprintf( __( 'Choose the type of channel %d.', 'chatchi' ), $index + 1 ) );
			}

			$normalized = self::normalize( $type, is_string( $raw['value'] ?? null ) ? $raw['value'] : '' );

			if ( null === $normalized ) {
				return new \WP_Error( 'invalid', self::value_error( $type, $index + 1 ) );
			}

			$label = PlainText::line( is_string( $raw['label'] ?? null ) ? $raw['label'] : '' );

			if ( mb_strlen( $label ) > self::LABEL_MAX_LENGTH ) {
				/* translators: 1: channel number, 2: maximum characters. */
				return new \WP_Error( 'invalid', sprintf( __( 'The label of channel %1$d can be at most %2$d characters.', 'chatchi' ), $index + 1, self::LABEL_MAX_LENGTH ) );
			}

			$channels[] = array(
				'type'  => $type,
				'value' => $normalized,
				'label' => $label,
			);
		}

		return $channels;
	}

	/**
	 * Brings stored channels back into shape; broken ones are dropped.
	 *
	 * @param mixed $value Stored value.
	 * @return array<int, array{type: string, value: string, label: string}>
	 */
	public static function repair( $value ): array {
		$channels = array();

		foreach ( is_array( $value ) ? array_slice( array_values( $value ), 0, self::MAX_CHANNELS ) : array() as $raw ) {
			if ( ! is_array( $raw ) || ! in_array( $raw['type'] ?? null, self::TYPES, true ) ) {
				continue;
			}

			$normalized = self::normalize( $raw['type'], is_string( $raw['value'] ?? null ) ? $raw['value'] : '' );

			if ( null !== $normalized ) {
				$channels[] = array(
					'type'  => $raw['type'],
					'value' => $normalized,
					'label' => mb_substr( PlainText::line( is_string( $raw['label'] ?? null ) ? $raw['label'] : '' ), 0, self::LABEL_MAX_LENGTH ),
				);
			}
		}

		return $channels;
	}

	/**
	 * Channels with their links, as sent to the widget.
	 *
	 * @param array<int, array{type: string, value: string, label: string}> $channels Clean channels.
	 * @return array<int, array{type: string, label: string, url: string}>
	 */
	public static function links( array $channels ): array {
		return array_map(
			static function ( array $channel ): array {
				return array(
					'type'  => $channel['type'],
					'label' => $channel['label'],
					'url'   => self::url( $channel['type'], $channel['value'] ),
				);
			},
			$channels
		);
	}

	/**
	 * @param string $type  Channel type.
	 * @param string $value Normalized value.
	 */
	private static function url( string $type, string $value ): string {
		switch ( $type ) {
			case 'whatsapp':
				return 'https://wa.me/' . $value;
			case 'telegram':
				return 'https://t.me/' . $value;
			case 'bale':
				return 'https://ble.ir/' . $value;
			default:
				return 'tel:' . $value;
		}
	}

	/**
	 * The stored form of a number or username; null when it is not valid.
	 *
	 * - WhatsApp: international number without "+" (0912… becomes 98912…).
	 * - Telegram / Bale: the username, without "@" or a link in front.
	 * - Phone: digits, optionally with a leading "+".
	 *
	 * @param string $type Channel type.
	 * @param string $raw  What the administrator typed.
	 */
	public static function normalize( string $type, string $raw ): ?string {
		$raw = trim( strtr( $raw, ProfileValidator::DIGIT_MAP ) );

		if ( 'telegram' === $type || 'bale' === $type ) {
			$name = preg_replace( '#^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me|ble\.ir)/#i', '', $raw );
			$name = ltrim( (string) $name, '@' );
			$name = rtrim( $name, '/' );

			return preg_match( '/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $name ) ? $name : null;
		}

		$plus   = 0 === strpos( $raw, '+' );
		$digits = (string) preg_replace( '/[\s\-().]/', '', ltrim( $raw, '+' ) );

		if ( ! preg_match( '/^\d+$/', $digits ) ) {
			return null;
		}

		if ( 'whatsapp' === $type ) {
			if ( 0 === strpos( $digits, '00' ) ) {
				$digits = substr( $digits, 2 );
			} elseif ( ! $plus && preg_match( '/^09\d{9}$/', $digits ) ) {
				$digits = '98' . substr( $digits, 1 );
			}

			return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? $digits : null;
		}

		return strlen( $digits ) >= 3 && strlen( $digits ) <= 15 ? ( $plus ? '+' : '' ) . $digits : null;
	}

	/**
	 * @param string $type   Channel type.
	 * @param int    $number Channel number, from 1.
	 */
	private static function value_error( string $type, int $number ): string {
		if ( 'telegram' === $type || 'bale' === $type ) {
			/* translators: %d: channel number. */
			return sprintf( __( 'Enter a valid username for channel %d, like @mystore.', 'chatchi' ), $number );
		}

		if ( 'whatsapp' === $type ) {
			/* translators: %d: channel number. */
			return sprintf( __( 'Enter a valid WhatsApp number for channel %d, like 09121234567 or +989121234567.', 'chatchi' ), $number );
		}

		/* translators: %d: channel number. */
		return sprintf( __( 'Enter a valid phone number for channel %d.', 'chatchi' ), $number );
	}
}
