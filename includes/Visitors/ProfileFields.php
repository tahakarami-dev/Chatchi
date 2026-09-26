<?php
/**
 * Start-form field configuration.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

defined( 'ABSPATH' ) || exit;

/**
 * Which profile fields the start form asks for, and which are required.
 *
 * Plugin Settings (Phase 14) feed this through the `chatchi_profile_fields`
 * filter; disabling every field disables the start form.
 */
final class ProfileFields {

	public const NAME  = 'name';
	public const EMAIL = 'email';
	public const PHONE = 'phone';

	/**
	 * Returns the configuration of every field.
	 *
	 * @return array<string, array{enabled: bool, required: bool}>
	 */
	public function all(): array {
		$defaults = array(
			self::NAME  => array(
				'enabled'  => true,
				'required' => true,
			),
			self::EMAIL => array(
				'enabled'  => true,
				'required' => true,
			),
			self::PHONE => array(
				'enabled'  => true,
				'required' => false,
			),
		);

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
