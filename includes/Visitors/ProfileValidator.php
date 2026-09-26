<?php
/**
 * Start-form validation.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes and validates the visitor's name, email and mobile number.
 */
final class ProfileValidator {

	private const NAME_MIN_LENGTH  = 2;
	private const NAME_MAX_LENGTH  = 100;
	private const EMAIL_MAX_LENGTH = 100;

	/**
	 * Persian (۰-۹) and Arabic-Indic (٠-٩) digits mapped to ASCII.
	 */
	private const DIGIT_MAP = array(
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
	);

	/**
	 * Validates raw form input against the field configuration.
	 *
	 * Disabled and unknown fields are ignored; empty optional fields are
	 * left out so they never overwrite stored values.
	 *
	 * @param array<string, mixed>                                $input  Raw input.
	 * @param array<string, array{enabled: bool, required: bool}> $fields Field configuration.
	 * @return array<string, string>|\WP_Error Clean profile, or field errors.
	 */
	public function validate( array $input, array $fields ) {
		$profile = array();
		$errors  = array();

		foreach ( $fields as $field => $config ) {
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			$raw = isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ? (string) $input[ $field ] : '';

			if ( '' === trim( $raw ) ) {
				if ( ! empty( $config['required'] ) ) {
					$errors[ $field ] = $this->required_message( $field );
				}
				continue;
			}

			$result = $this->clean( $field, $raw );

			if ( $result instanceof \WP_Error ) {
				$errors[ $field ] = $result->get_error_message();
				continue;
			}

			$profile[ $field ] = $result;
		}

		if ( $errors ) {
			return new \WP_Error(
				'chatchi_invalid_profile',
				__( 'Please check the highlighted fields.', 'chatchi' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return $profile;
	}

	/**
	 * Normalizes an Iranian mobile number to 09XXXXXXXXX.
	 *
	 * Accepts Persian/Arabic digits, spaces, dashes, and the +98 / 0098 / 98
	 * prefixes, or the number without its leading zero.
	 *
	 * @param string $raw Raw input.
	 * @return string|null Normalized number, or null when invalid.
	 */
	public function normalize_phone( string $raw ): ?string {
		$digits = strtr( $raw, self::DIGIT_MAP );
		$digits = (string) preg_replace( '/[\s\-().]/u', '', $digits );

		$normalized = null;

		if ( preg_match( '/^(?:\+98|0098|98|0)?(9\d{9})$/', $digits, $matches ) ) {
			$normalized = '0' . $matches[1];
		}

		/**
		 * Filters the normalized mobile number.
		 *
		 * Return a string to accept the number (e.g. to allow international
		 * numbers), or null to reject it.
		 *
		 * @param string|null $normalized Normalized Iranian number, or null.
		 * @param string      $raw        Raw input.
		 */
		$normalized = apply_filters( 'chatchi_normalize_phone', $normalized, $raw );

		return is_string( $normalized ) && '' !== $normalized ? mb_substr( $normalized, 0, 20 ) : null;
	}

	/**
	 * Sanitizes and validates one non-empty field.
	 *
	 * @param string $field Field name.
	 * @param string $raw   Raw value.
	 * @return string|\WP_Error
	 */
	private function clean( string $field, string $raw ) {
		switch ( $field ) {
			case ProfileFields::NAME:
				return $this->clean_name( $raw );

			case ProfileFields::EMAIL:
				return $this->clean_email( $raw );

			case ProfileFields::PHONE:
				$phone = $this->normalize_phone( $raw );

				return null !== $phone
					? $phone
					: new \WP_Error( 'chatchi_invalid_phone', __( 'Please enter a valid mobile number, like 09123456789.', 'chatchi' ) );
		}

		return new \WP_Error( 'chatchi_unknown_field', __( 'This field is not supported.', 'chatchi' ) );
	}

	/**
	 * @param string $raw Raw name.
	 * @return string|\WP_Error
	 */
	private function clean_name( string $raw ) {
		$name   = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $raw ) ) );
		$length = mb_strlen( $name );

		if ( $length < self::NAME_MIN_LENGTH ) {
			return new \WP_Error(
				'chatchi_name_too_short',
				/* translators: %d: minimum number of characters. */
				sprintf( __( 'Name must be at least %d characters.', 'chatchi' ), self::NAME_MIN_LENGTH )
			);
		}

		if ( $length > self::NAME_MAX_LENGTH ) {
			return new \WP_Error(
				'chatchi_name_too_long',
				/* translators: %d: maximum number of characters. */
				sprintf( __( 'Name must be at most %d characters.', 'chatchi' ), self::NAME_MAX_LENGTH )
			);
		}

		return $name;
	}

	/**
	 * @param string $raw Raw email.
	 * @return string|\WP_Error
	 */
	private function clean_email( string $raw ) {
		$email = sanitize_email( trim( $raw ) );

		if ( '' === $email || ! is_email( $email ) || strlen( $email ) > self::EMAIL_MAX_LENGTH ) {
			return new \WP_Error( 'chatchi_invalid_email', __( 'Please enter a valid email address.', 'chatchi' ) );
		}

		return strtolower( $email );
	}

	/**
	 * @param string $field Field name.
	 */
	private function required_message( string $field ): string {
		switch ( $field ) {
			case ProfileFields::NAME:
				return __( 'Please enter your name.', 'chatchi' );

			case ProfileFields::EMAIL:
				return __( 'Please enter your email address.', 'chatchi' );

			case ProfileFields::PHONE:
				return __( 'Please enter your mobile number.', 'chatchi' );
		}

		return __( 'This field is required.', 'chatchi' );
	}
}
