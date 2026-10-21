<?php
/**
 * Sending SMS through Iranian SMS services.
 *
 * @package ChatChi
 */

namespace ChatChi\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one text message through Kavenegar or Melipayamak and turns their
 * answers into a clear result.
 *
 * - Kavenegar: POST https://api.kavenegar.com/v1/{API key}/sms/send.json
 *   with receptor, message and (optional) sender.
 * - Melipayamak: POST https://rest.payamak-panel.com/api/SendSMS/SendSMS
 *   with username, password, to, from and text; RetStatus 1 means sent.
 *
 * Credentials never appear in error messages or logs.
 */
final class SmsGateway {

	private const TIMEOUT = 10;

	private const ENDPOINTS = array(
		'kavenegar'   => 'https://api.kavenegar.com/v1/%s/sms/send.json',
		'melipayamak' => 'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
	);

	/**
	 * Sends a message.
	 *
	 * @param string               $provider    "kavenegar" or "melipayamak".
	 * @param array<string, string> $credentials api_key, or username and password; sender.
	 * @param string               $to          Iranian mobile number, 09xxxxxxxxx.
	 * @param string               $text        Message.
	 * @return true|\WP_Error
	 */
	public function send( string $provider, array $credentials, string $to, string $text ) {
		if ( ! isset( self::ENDPOINTS[ $provider ] ) ) {
			return new \WP_Error( 'chatchi_sms_provider', __( 'Choose an SMS service.', 'chatchi' ), array( 'status' => 400 ) );
		}

		if ( 'kavenegar' === $provider ) {
			$key = (string) ( $credentials['api_key'] ?? '' );

			if ( '' === $key || ! preg_match( '#^[A-Za-z0-9+/=_\-]+$#', $key ) ) {
				return new \WP_Error( 'chatchi_sms_credentials', __( 'Enter the API key of your Kavenegar account.', 'chatchi' ), array( 'status' => 400 ) );
			}

			$url  = sprintf( $this->endpoint( $provider ), rawurlencode( $key ) );
			$body = array_filter(
				array(
					'receptor' => $to,
					'message'  => $text,
					'sender'   => (string) ( $credentials['sender'] ?? '' ),
				),
				'strlen'
			);
		} else {
			if ( '' === (string) ( $credentials['username'] ?? '' ) || '' === (string) ( $credentials['password'] ?? '' ) || '' === (string) ( $credentials['sender'] ?? '' ) ) {
				return new \WP_Error( 'chatchi_sms_credentials', __( 'Enter the username, password and sender line of your Melipayamak panel.', 'chatchi' ), array( 'status' => 400 ) );
			}

			$url  = $this->endpoint( $provider );
			$body = array(
				'username' => (string) $credentials['username'],
				'password' => (string) $credentials['password'],
				'to'       => $to,
				'from'     => (string) $credentials['sender'],
				'text'     => $text,
				'isflash'  => 'false',
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'body'        => $body,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'chatchi_sms_unreachable',
				/* translators: %s: technical reason. */
				sprintf( __( 'The SMS service could not be reached (%s). Some servers cannot connect to it; ask your host.', 'chatchi' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return 'kavenegar' === $provider ? $this->kavenegar_result( $status, $data ) : $this->melipayamak_result( $status, $data );
	}

	/**
	 * @param string $provider Provider.
	 */
	private function endpoint( string $provider ): string {
		/**
		 * Filters the address of an SMS service's API, e.g. for a relay.
		 *
		 * @param string $url      Default address (Kavenegar's contains %s for the API key).
		 * @param string $provider "kavenegar" or "melipayamak".
		 */
		return (string) apply_filters( 'chatchi_sms_api_url', self::ENDPOINTS[ $provider ], $provider );
	}

	/**
	 * @param int   $status HTTP status.
	 * @param mixed $data   Decoded body.
	 * @return true|\WP_Error
	 */
	private function kavenegar_result( int $status, $data ) {
		$code = is_array( $data ) && isset( $data['return']['status'] ) ? (int) $data['return']['status'] : $status;

		if ( 200 === $code ) {
			return true;
		}

		$messages = array(
			401 => __( 'The API key is not valid or the account is not active.', 'chatchi' ),
			403 => __( 'The API key is not valid or the account is not active.', 'chatchi' ),
			411 => __( 'The mobile number is not valid.', 'chatchi' ),
			412 => __( 'The sender line is not valid or does not belong to this account.', 'chatchi' ),
			418 => __( 'The account does not have enough credit.', 'chatchi' ),
		);

		return $this->failure( $messages[ $code ] ?? null, $code, is_array( $data ) ? (string) ( $data['return']['message'] ?? '' ) : '' );
	}

	/**
	 * @param int   $status HTTP status.
	 * @param mixed $data   Decoded body.
	 * @return true|\WP_Error
	 */
	private function melipayamak_result( int $status, $data ) {
		$code = is_array( $data ) && isset( $data['RetStatus'] ) ? (int) $data['RetStatus'] : -1;

		if ( 200 === $status && 1 === $code ) {
			return true;
		}

		// Documented failure values of RetStatus / Value.
		$value    = is_array( $data ) && isset( $data['Value'] ) ? (string) $data['Value'] : '';
		$messages = array(
			'0'  => __( 'The username or password is wrong.', 'chatchi' ),
			'2'  => __( 'The account does not have enough credit.', 'chatchi' ),
			'3'  => __( 'The daily sending limit was reached.', 'chatchi' ),
			'4'  => __( 'The sending limit was reached.', 'chatchi' ),
			'5'  => __( 'The sender line is not valid or does not belong to this account.', 'chatchi' ),
			'6'  => __( 'The SMS service is being updated; try again later.', 'chatchi' ),
			'7'  => __( 'The message contains a blocked word.', 'chatchi' ),
			'9'  => __( 'Shared lines cannot send through the web service; use a dedicated line.', 'chatchi' ),
			'10' => __( 'The account is not active.', 'chatchi' ),
			'11' => __( 'The message was not sent.', 'chatchi' ),
			'12' => __( 'The account documents are not complete.', 'chatchi' ),
		);
		$key      = isset( $messages[ $value ] ) ? $value : (string) $code;

		return $this->failure( $messages[ $key ] ?? null, 200 === $status ? $code : $status, is_array( $data ) ? (string) ( $data['StrRetStatus'] ?? '' ) : '' );
	}

	/**
	 * @param string|null $known   Our explanation, when the code is known.
	 * @param int         $code    The service's code.
	 * @param string      $message The service's own message.
	 */
	private function failure( ?string $known, int $code, string $message ): \WP_Error {
		$text = $known ?? sprintf(
			/* translators: 1: error code, 2: the service's message. */
			__( 'The SMS service refused the message (code %1$d). %2$s', 'chatchi' ),
			$code,
			sanitize_text_field( $message )
		);

		return new \WP_Error( 'chatchi_sms_failed', trim( $text ), array( 'status' => 502 ) );
	}
}
