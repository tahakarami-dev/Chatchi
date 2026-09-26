<?php
/**
 * Settings validation.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

use ChatChi\Security\IpRange;
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

	public const PROXY_MODES = array( 'direct', 'cloudflare', 'custom' );

	public const POSITIONS = array( 'left', 'right' );

	public const TITLE_MAX_LENGTH = 60;

	public const SUBTITLE_MAX_LENGTH = 100;

	public const MAX_TRUSTED_PROXIES = 100;

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
			'proxy_mode'      => 'direct',
			'proxy_header'    => '',
			'trusted_proxies' => array(),
			// Empty means "use the default color".
			'primary_color'   => '',
			'widget_position' => 'left',
			// Empty means "use the translated default text".
			'widget_title'    => '',
			'widget_subtitle' => '',
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

		// A custom proxy needs a header; reported together with other errors.
		if ( 'custom' === ( $changes['proxy_mode'] ?? null ) && array_key_exists( 'proxy_header', $changes ) && ! isset( $errors['proxy_header'] ) && '' === ( $clean['proxy_header'] ?? '' ) ) {
			$errors['proxy_header'] = $this->missing_header_message();
		}

		return $errors ? $this->invalid( $errors ) : $clean;
	}

	private function missing_header_message(): string {
		return __( 'Enter the header your proxy puts the visitor address in.', 'chatchi' );
	}

	/**
	 * @param array<string, string> $errors Messages keyed by setting.
	 */
	private function invalid( array $errors ): \WP_Error {
		return new \WP_Error(
			'chatchi_invalid_settings',
			__( 'Some settings need your attention.', 'chatchi' ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/**
	 * Checks rules that involve more than one setting, on the merged result.
	 *
	 * @param array<string, mixed> $settings All settings after the change.
	 * @return \WP_Error|null
	 */
	public function validate_combined( array $settings ): ?\WP_Error {
		if ( 'custom' === $settings['proxy_mode'] && '' === $settings['proxy_header'] ) {
			return $this->invalid( array( 'proxy_header' => $this->missing_header_message() ) );
		}

		return null;
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

				case 'proxy_mode':
					$settings[ $key ] = in_array( $value, self::PROXY_MODES, true ) ? $value : $default;
					break;

				case 'proxy_header':
					$settings[ $key ] = is_string( $value ) && $this->is_header( $value ) ? $value : $default;
					break;

				case 'trusted_proxies':
					$settings[ $key ] = is_array( $value ) ? array_values( array_filter( array_map( array( IpRange::class, 'range' ), array_filter( $value, 'is_string' ) ) ) ) : $default;
					break;

				case 'primary_color':
					$settings[ $key ] = is_string( $value ) ? (string) $this->color( $value ) : $default;
					break;

				case 'widget_position':
					$settings[ $key ] = in_array( $value, self::POSITIONS, true ) ? $value : $default;
					break;

				case 'widget_title':
				case 'widget_subtitle':
					$settings[ $key ] = is_string( $value ) ? PlainText::line( $value ) : $default;
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

			case 'proxy_mode':
				return in_array( $value, self::PROXY_MODES, true )
					? $value
					: new \WP_Error( 'invalid', __( 'Choose how visitors reach your site.', 'chatchi' ) );

			case 'proxy_header':
				return $this->proxy_header( $value );

			case 'trusted_proxies':
				return $this->trusted_proxies( $value );

			case 'primary_color':
				$color = is_string( $value ) ? $this->color( $value ) : null;

				return null !== $color
					? $color
					: new \WP_Error( 'invalid', __( 'Enter a color code like #5B5BD6.', 'chatchi' ) );

			case 'widget_position':
				return in_array( $value, self::POSITIONS, true )
					? $value
					: new \WP_Error( 'invalid', __( 'Choose a corner for the widget.', 'chatchi' ) );

			case 'widget_title':
				return $this->short_text( $value, self::TITLE_MAX_LENGTH );

			case 'widget_subtitle':
				return $this->short_text( $value, self::SUBTITLE_MAX_LENGTH );
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
	 * @param mixed $value Raw value.
	 * @return string|\WP_Error
	 */
	private function proxy_header( $value ) {
		$value = is_string( $value ) ? trim( $value ) : null;

		if ( null === $value || ( '' !== $value && ! $this->is_header( $value ) ) ) {
			return new \WP_Error( 'invalid', __( 'Use a header name made of letters, digits and dashes, like X-Real-IP.', 'chatchi' ) );
		}

		return $value;
	}

	/**
	 * @param mixed $value Raw value.
	 * @return string[]|\WP_Error
	 */
	private function trusted_proxies( $value ) {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'invalid', __( 'Enter one address or range per line.', 'chatchi' ) );
		}

		$ranges = array();

		foreach ( $value as $entry ) {
			$entry = is_string( $entry ) ? trim( $entry ) : '';

			if ( '' === $entry ) {
				continue;
			}

			$range = IpRange::range( $entry );

			if ( null === $range ) {
				return new \WP_Error(
					'invalid',
					/* translators: %s: the invalid entry. */
					sprintf( __( '“%s” is not an IP address or range.', 'chatchi' ), mb_substr( PlainText::line( $entry ), 0, 60 ) )
				);
			}

			$ranges[ $range ] = $range;
		}

		if ( count( $ranges ) > self::MAX_TRUSTED_PROXIES ) {
			return new \WP_Error(
				'invalid',
				/* translators: %d: maximum number of entries. */
				sprintf( __( 'Enter at most %d addresses or ranges.', 'chatchi' ), self::MAX_TRUSTED_PROXIES )
			);
		}

		return array_values( $ranges );
	}

	/**
	 * Returns a color as #RRGGBB, an empty string for "default", or null
	 * when the text is not a hex color.
	 *
	 * @param string $value Raw value, e.g. "#5b5bd6", "5B5BD6" or "#abc".
	 */
	private function color( string $value ): ?string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $m ) ) {
			return null;
		}

		$hex = $m[1];

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return '#' . strtoupper( $hex );
	}

	/**
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum characters.
	 * @return string|\WP_Error
	 */
	private function short_text( $value, int $max ) {
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'invalid', __( 'Enter the text as plain text.', 'chatchi' ) );
		}

		$text = PlainText::line( $value );

		if ( mb_strlen( $text ) > $max ) {
			return new \WP_Error(
				'invalid',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'Use at most %d characters.', 'chatchi' ), $max )
			);
		}

		return $text;
	}

	/**
	 * Whether a string is a plausible HTTP header name.
	 *
	 * @param string $name Header name.
	 */
	private function is_header( string $name ): bool {
		return '' === $name || 1 === preg_match( '/^[A-Za-z0-9-]{1,64}$/', $name );
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
