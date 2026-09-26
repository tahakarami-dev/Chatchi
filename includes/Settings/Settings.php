<?php
/**
 * Plugin settings store.
 *
 * @package ChatChi
 */

namespace ChatChi\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin settings, kept in one autoloaded option.
 *
 * Values are validated when saved and repaired when read, so callers can
 * rely on every key existing with the right type.
 */
final class Settings {

	public const OPTION = 'chatchi_settings';

	private SettingsValidator $validator;

	/**
	 * Settings of the current request, or null before the first read.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * @param SettingsValidator $validator Validation rules and defaults.
	 */
	public function __construct( SettingsValidator $validator ) {
		$this->validator = $validator;
	}

	/**
	 * Returns every setting.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = $this->validator->repair( get_option( self::OPTION, array() ) );
		}

		/**
		 * Filters the plugin settings as the plugin uses them.
		 *
		 * Changes are not saved. Return values of the same types.
		 *
		 * @param array<string, mixed> $settings Settings.
		 */
		return (array) apply_filters( 'chatchi_settings', $this->cache );
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed Null for unknown settings.
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Validates and saves changed settings; others keep their values.
	 *
	 * @param array<string, mixed> $changes Changed settings.
	 * @return array<string, mixed>|\WP_Error All settings after saving.
	 */
	public function update( array $changes ) {
		$clean = $this->validator->validate( $changes );

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		$stored   = $this->validator->repair( get_option( self::OPTION, array() ) );
		$settings = array_merge( $stored, $clean );

		if ( $settings !== $stored ) {
			update_option( self::OPTION, $settings, true );
		}

		$this->cache = null;

		/**
		 * Fires after settings were saved.
		 *
		 * @param array<string, mixed> $settings All settings.
		 * @param array<string, mixed> $changes  The validated changes.
		 */
		do_action( 'chatchi_settings_updated', $settings, $clean );

		return $this->all();
	}

	/**
	 * Returns the translated default welcome message.
	 */
	public function default_welcome_message(): string {
		return __( 'Hi! 👋 How can we help you today?', 'chatchi' );
	}

	/**
	 * Returns the welcome message to show, or an empty string when it is off.
	 */
	public function welcome_message(): string {
		if ( ! $this->get( 'welcome_enabled' ) ) {
			return '';
		}

		$message = (string) $this->get( 'welcome_message' );

		return '' !== $message ? $message : $this->default_welcome_message();
	}
}
