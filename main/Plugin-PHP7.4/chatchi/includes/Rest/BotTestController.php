<?php
/**
 * "Send a test" for the Telegram and Bale settings.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Notifications\BotNotifier;
use ChatChi\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/**
 * POST /chatchi/v1/agent/settings/bot-test
 *
 * Administrators only. Sends a test message with the values from the form,
 * saved or not, and reports what the service answered.
 */
final class BotTestController implements RouteProvider {

	private BotNotifier $bots;

	private SettingsValidator $validator;

	/**
	 * @param BotNotifier       $bots      Bot messages.
	 * @param SettingsValidator $validator Setting rules, to check the values first.
	 */
	public function __construct( BotNotifier $bots, SettingsValidator $validator ) {
		$this->bots      = $bots;
		$this->validator = $validator;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/agent/settings/bot-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( AdminPermission::class, 'check' ),
				'args'                => array(
					'service' => array(
						'type'     => 'string',
						'enum'     => BotNotifier::SERVICES,
						'required' => true,
					),
					'token'   => array(
						'type'      => 'string',
						'maxLength' => 200,
						'default'   => '',
					),
					'chat_id' => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
					'api_url' => array(
						'type'      => 'string',
						'maxLength' => 300,
						'default'   => '',
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
		$service = (string) $request['service'];
		$values  = array(
			$service . '_token'   => (string) $request['token'],
			$service . '_chat_id' => (string) $request['chat_id'],
		);

		if ( 'telegram' === $service ) {
			$values['telegram_api_url'] = (string) $request['api_url'];
		}

		$clean = $this->validator->validate( $values );

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		if ( '' === $clean[ $service . '_token' ] || '' === $clean[ $service . '_chat_id' ] ) {
			return new \WP_Error( 'chatchi_bot_incomplete', __( 'Enter the bot token and the chat ID first.', 'chatchi' ), array( 'status' => 400 ) );
		}

		$sent = $this->bots->send(
			$service,
			$clean[ $service . '_token' ],
			$clean[ $service . '_chat_id' ],
			/* translators: %s: site name. */
			'✅ ' . sprintf( __( 'ChatChi on %s is connected. New chats will be announced here.', 'chatchi' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ),
			true,
			'telegram' === $service ? $clean['telegram_api_url'] : null
		);

		if ( $sent instanceof \WP_Error ) {
			return $sent;
		}

		$response = new \WP_REST_Response( array( 'sent' => true ) );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
