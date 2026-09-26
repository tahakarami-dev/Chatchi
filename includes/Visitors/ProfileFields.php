<?php
/**
 * Start-form field configuration.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Which profile fields the start form asks for, and which are required.
 *
 * The admin chooses per field in Settings (off / optional / required); the
 * `chatchi_profile_fields` filter has the final say. Disabling every field
 * disables the start form.
 */
final class ProfileFields {

	public const NAME  = 'name';
	public const EMAIL = 'email';
	public const PHONE = 'phone';

	private ?Settings $settings;

	/**
	 * @param Settings|null $settings Plugin settings; without them the built-in defaults apply.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Returns the configuration of every field.
	 *
	 * @return array<string, array{enabled: bool, required: bool}>
	 */
	public function all(): array {
		$modes    = array(
			self::NAME  => $this->mode( 'form_name', 'required' ),
			self::EMAIL => $this->mode( 'form_email', 'required' ),
			self::PHONE => $this->mode( 'form_phone', 'optional' ),
		);
		$defaults = array();

		foreach ( $modes as $field => $mode ) {
			$defaults[ $field ] = array(
				'enabled'  => 'off' !== $mode,
				'required' => 'required' === $mode,
			);
		}

		/**
		 * Filters the start-form fields.
		 *
		 * @param array<string, array{enabled: bool, required: bool}> $fields Field configuration.
		 */
		$filtered = apply_filters( 'chatchi_profile_fields', $defaults );

		$fields = array();
		foreach ( $defaults as $field => $default ) {
			$config  = isset( $filtered[ $field ] ) && is_array( $filtered[ $field ] ) ? $filtered[ $field ] : $default;
			$enabled = ! empty( $config['enabled'] );

			$fields[ $field ] = array(
				'enabled'  => $enabled,
				'required' => $enabled && ! empty( $config['required'] ),
			);
		}

		return $fields;
	}

	/**
	 * Returns "off", "optional" or "required" for a field from the settings.
	 *
	 * @param string $key      Setting name.
	 * @param string $fallback Mode without settings.
	 */
	private function mode( string $key, string $fallback ): string {
		return $this->settings ? (string) $this->settings->get( $key ) : $fallback;
	}

	/**
	 * Returns the configuration of the enabled fields only.
	 *
	 * @return array<string, array{enabled: bool, required: bool}>
	 */
	public function enabled(): array {
		return array_filter(
			$this->all(),
			static function ( array $config ): bool {
				return $config['enabled'];
			}
		);
	}

	/**
	 * Whether the start form should be shown at all.
	 */
	public function form_enabled(): bool {
		return (bool) $this->enabled();
	}
}
