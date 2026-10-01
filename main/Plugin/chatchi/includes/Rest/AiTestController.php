<?php
/**
 * "Test the connection" for the AI settings.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Ai\AiClient;
use ChatChi\Settings\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/**
 * POST /chatchi/v1/agent/settings/ai-test
 *
 * Administrators only. Sends a tiny request with the values from the form,
 * saved or not, and returns the model's answer or the service's error.
 */
final class AiTestController implements RouteProvider {

	private AiClient $client;

	private SettingsValidator $validator;

	/**
	 * @param AiClient          $client    The language model.
	 * @param SettingsValidator $validator Setting rules, to check the values first.
	 */
	public function __construct( AiClient $client, SettingsValidator $validator ) {
		$this->client    = $client;
		$this->validator = $validator;
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
			'/agent/settings/ai-test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( AdminPermission::class, 'check' ),
				'args'                => array(
					'provider' => array(
						'type'     => 'string',
						'enum'     => SettingsValidator::AI_PROVIDERS,
						'required' => true,
					),
					'api_key'  => $text,
					'base_url' => $text,
					'model'    => $text,
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
				'ai_api_key'  => (string) $request['api_key'],
				'ai_base_url' => (string) $request['base_url'],
				'ai_model'    => (string) $request['model'],
			)
		);

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		$answer = $this->client->complete(
			array(
				'provider' => (string) $request['provider'],
				'api_key'  => $clean['ai_api_key'],
				'base_url' => $clean['ai_base_url'],
				'model'    => $clean['ai_model'],
			),
			'You are testing a connection. Reply with one short friendly sentence in the language of the user message.',
			'',
			array(
				array(
					'role'    => 'user',
					'content' => __( 'Hello! Is the connection working?', 'chatchi' ),
				),
			)
		);

		if ( $answer instanceof \WP_Error ) {
			return $answer;
		}

		$response = new \WP_REST_Response( array( 'text' => mb_substr( $answer, 0, 300 ) ) );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
