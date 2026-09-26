<?php
/**
 * Settings validation.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Checks and normalizes setting changes before they are saved, and repairs
 * stored values when they are read back.
 */
final class SettingsValidator {

	public const WELCOME_MAX_LENGTH = 500;

	public const MAX_DISPLAY_IDS = 100;

	public const DISPLAY_MODES = array( 'all', 'include', 'exclude' );

	public const DISPLAY_TARGETS = array( 'home', 'pages', 'posts', 'products' );

	private ContentLookup $content;

	/**
	 * @param ContentLookup $content Content lookup, to check picked items.
	 */
	public function __construct( ContentLookup $content ) {
		$this->content = $content;
	}

	/**
	 * Returns the default value of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'welcome_enabled' => true,
			// Empty means "use the translated default message".
			'welcome_message' => '',
			'display_mode'    => 'all',
			'display_targets' => array(),
			'display_ids'     => array(),
		);
	}

	/**
	 * Validates a set of changes.
	 *
	 * Unknown keys are ignored. Every invalid field is reported at once.
	 *
	 * @param array<string, mixed> $changes Changed settings.
	 * @return array<string, mixed>|\WP_Error Clean changes, or an error with
	 *                                         per-field messages in data.fields.
	 */
	public function validate( array $changes ) {
		$clean  = array();
		$errors = array();

		foreach ( array_keys( $this->defaults() ) as $key ) {
			if ( ! array_key_exists( $key, $changes ) ) {
				continue;
			}

			$result = $this->validate_field( $key, $changes[ $key ] );

			if ( $result instanceof \WP_Error ) {
				$errors[ $key ] = $result->get_error_message();
			} else {
				$clean[ $key ] = $result;
			}
		}

		if ( $errors ) {
			return new \WP_Error(
				'chatchi_invalid_settings',
				__( 'Some settings need your attention.', 'chatchi' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return $clean;
	}

	/**
	 * Brings stored values back into shape.
	 *
	 * Protects the front end from values written by other code or left over
	 * from older versions: anything malformed falls back to its default.
	 * Picked items are not looked up here, so reading settings stays free of
	 * database queries.
	 *
	 * @param mixed $stored Raw option value.
	 * @return array<string, mixed>
	 */
	public function repair( $stored ): array {
		$defaults = $this->defaults();
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = array();

		foreach ( $defaults as $key => $default ) {
			$value = $stored[ $key ] ?? $default;

			switch ( $key ) {
				case 'welcome_enabled':
					$settings[ $key ] = (bool) $value;
					break;

				case 'welcome_message':
					$settings[ $key ] = is_string( $value ) ? $value : $default;
					break;

				case 'display_mode':
					$settings[ $key ] = in_array( $value, self::DISPLAY_MODES, true ) ? $value : $default;
					break;

				case 'display_targets':
					$settings[ $key ] = is_array( $value ) ? array_values( array_intersect( self::DISPLAY_TARGETS, $value ) ) : $default;
					break;

				case 'display_ids':
					$settings[ $key ] = is_array( $value ) ? $this->ids( $value ) : $default;
					break;
			}
		}

		return $settings;
	}

	/**
	 * @param string $key   Setting name.
	 * @param mixed  $value Raw value.
	 * @return mixed|\WP_Error
	 */
	private function validate_field( string $key, $value ) {
		switch ( $key ) {
			case 'welcome_enabled':
				return rest_sanitize_boolean( $value );

			case 'welcome_message':
				return $this->welcome_message( $value );

			case 'display_mode':
				return in_array( $value, self::DISPLAY_MODES, true )
					? $value
					: new \WP_Error( 'invalid', __( 'Choose where the widget appears.', 'chatchi' ) );

			case 'display_targets':
				return $this->display_targets( $value );

			case 'display_ids':
				return $this->display_ids( $value );
		}

		return new \WP_Error( 'invalid', __( 'Unknown setting.', 'chatchi' ) );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string|\WP_Error
	 */
	private function welcome_message( $value ) {
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'invalid', __( 'Enter the message as text.', 'chatchi' ) );
		}

		$text = PlainText::paragraphs( $value );

		if ( mb_strlen( $text ) > self::WELCOME_MAX_LENGTH ) {
			return new \WP_Error(
				'invalid',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'The welcome message can be at most %d characters.', 'chatchi' ), self::WELCOME_MAX_LENGTH )
			);
		}

		return $text;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string[]|\WP_Error
	 */
	private function display_targets( $value ) {
		if ( ! is_array( $value ) || array_diff( $value, self::DISPLAY_TARGETS ) ) {
			return new \WP_Error( 'invalid', __( 'Choose page types from the list.', 'chatchi' ) );
		}

		// Stored in a fixed order so saving the same choice twice is a no-op.
		return array_values( array_intersect( self::DISPLAY_TARGETS, $value ) );
	}

	/**
	 * @param mixed $value Raw value.
	 * @return int[]|\WP_Error
	 */
	private function display_ids( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'invalid', __( 'Choose items from the search results.', 'chatchi' ) );
		}

		foreach ( $value as $id ) {
			if ( ! $this->is_id( $id ) ) {
				return new \WP_Error( 'invalid', __( 'Choose items from the search results.', 'chatchi' ) );
			}
		}

		$ids = $this->ids( $value );

		if ( count( $ids ) > self::MAX_DISPLAY_IDS ) {
			return new \WP_Error(
				'invalid',
				/* translators: %d: maximum number of items. */
				sprintf( __( 'You can choose at most %d items.', 'chatchi' ), self::MAX_DISPLAY_IDS )
			);
		}

		if ( count( $this->content->find( $ids ) ) !== count( $ids ) ) {
			return new \WP_Error( 'invalid', __( 'Some of the chosen items no longer exist or are not published.', 'chatchi' ) );
		}

		return $ids;
	}

	/**
	 * Returns the unique post IDs in a list, skipping anything else.
	 *
	 * @param array<mixed> $values Raw values.
	 * @return int[]
	 */
	private function ids( array $values ): array {
		$ids = array();

		foreach ( $values as $value ) {
			if ( $this->is_id( $value ) ) {
				$ids[ (int) $value ] = (int) $value;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Whether a value is a positive integer or a string of digits.
	 *
	 * @param mixed $value Raw value.
	 */
	private function is_id( $value ): bool {
		return ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && (int) $value > 0;
	}
}
