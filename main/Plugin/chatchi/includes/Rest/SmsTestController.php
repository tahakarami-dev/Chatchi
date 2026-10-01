<?php
/**
 * "Send a test SMS" for the SMS settings.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Notifications\SmsGateway;
use ChatChi\Settings\SettingsValidator;
use ChatChi\Visitors\ProfileValidator;

defined( 'ABSPATH' ) || exit;

/**
 * POST /chatchi/v1/agent/settings/sms-test
 *
 * Administrators only. Sends a test SMS with the values from the form,
 * saved or not, to a number the administrator types, and reports what the
 * service answered.
 */
final class SmsTestController implements RouteProvider {

	private SmsGateway $gateway;

	private SettingsValidator $validator;

	private ProfileValidator $profile;

	/**
	 * @param SmsGateway        $gateway   SMS services.
	 * @param SettingsValidator $validator Setting rules, to check the values first.
	 * @param ProfileValidator  $profile   Mobile number rules.
	 */
	public function __construct( SmsGateway $gateway, SettingsValidator $validator, ProfileValidator $profile ) {
		$this->gateway   = $gateway;
		$this->validator = $validator;
		$this->profile   = $profile;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$text = array(
			'type'      => 'string',
			'maxLength' => 500,
			'default'   => '',
		);

		register_rest_route(
			self::NAMESPACE,
			'/agent/settings/sms-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( AdminPermission::class, 'check' ),
				'args'                => array(
					'provider' => array(
						'type'     => 'string',
						'enum'     => SettingsValidator::SMS_PROVIDERS,
						'required' => true,
					),
					'api_key'  => $text,
					'username' => $text,
					'password' => $text,
					'sender'   => $text,
					'phone'    => array(
						'type'      => 'string',
						'maxLength' => 50,
						'required'  => true,
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$clean = $this->validator->validate(
			array(
				'sms_api_key'  => (string) $request['api_key'],
				'sms_username' => (string) $request['username'],
				'sms_password' => (string) $request['password'],
				'sms_sender'   => (string) $request['sender'],
			)
		);

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		$phone = $this->profile->normalize_phone( (string) $request['phone'] );

		if ( null === $phone ) {
			return new \WP_Error( 'chatchi_invalid_settings', __( 'Enter a valid mobile number, like 09121234567.', 'chatchi' ), array( 'status' => 400 ) );
		}

		$sent = $this->gateway->send(
			(string) $request['provider'],
			array(
				'api_key'  => $clean['sms_api_key'],
				'username' => $clean['sms_username'],
				'password' => $clean['sms_password'],
				'sender'   => $clean['sms_sender'],
			),
			$phone,
			/* translators: %s: site name. */
			sprintf( __( 'ChatChi on %s can send SMS. This is a test.', 'chatchi' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) )
		);

		if ( $sent instanceof \WP_Error ) {
			return $sent;
		}

		$response = new \WP_REST_Response( array( 'sent' => true ) );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
