<?php
/**
 * Talking to a language model.
 *
 * @package ChatChi
 */

namespace ChatChi\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one conversation to a language model and returns its text answer.
 *
 * Every service is spoken to in the OpenAI Chat Completions format
 * (POST {base}/chat/completions with a Bearer key), which Iranian services
 * such as GapGPT and AvalAI offer, as does OpenAI itself. Plain HTTP, no
 * SDK, so the plugin keeps running on PHP 7.4 without Composer.
 *
 * Each preset has a default address; an address set in the settings
 * replaces it (for "custom" it is required). The API key never appears in
 * errors, logs or the browser.
 */
final class AiClient {

	/**
	 * Services and their default API addresses. "custom" is any other
	 * OpenAI-compatible service, with its address set in the settings.
	 */
	public const BASE_URLS = array(
		'gapgpt' => 'https://api.gapgpt.app/v1',
		'avalai' => 'https://api.avalai.ir/v1',
		'openai' => 'https://api.openai.com/v1',
		'custom' => '',
	);

	public const PROVIDERS = array( 'gapgpt', 'avalai', 'openai', 'custom' );

	private const TIMEOUT = 60;

	/**
	 * Asks the model.
	 *
	 * @param array<string, string>                            $config   provider, api_key, base_url, model.
	 * @param string                                           $stable   System prompt part that rarely changes.
	 * @param string                                           $volatile System prompt part that changes per request.
	 * @param array<int, array{role: string, content: string}> $messages Alternating user / assistant turns, first from the user.
	 * @return string|\WP_Error The answer text.
	 */
	public function complete( array $config, string $stable, string $volatile, array $messages ) {
		$provider = $config['provider'] ?? '';
		$key      = trim( (string) ( $config['api_key'] ?? '' ) );
		$model    = trim( (string) ( $config['model'] ?? '' ) );

		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			return $this->error( 'chatchi_ai_provider', __( 'Choose an AI service.', 'chatchi' ), 400 );
		}

		if ( '' === $key ) {
			return $this->error( 'chatchi_ai_key', __( 'Enter the API key of your AI service.', 'chatchi' ), 400 );
		}

		if ( '' === $model ) {
			return $this->error( 'chatchi_ai_model', __( 'Enter the model name.', 'chatchi' ), 400 );
		}

		if ( ! $messages ) {
			return $this->error( 'chatchi_ai_empty', __( 'There is nothing to answer yet.', 'chatchi' ), 400 );
		}

		$base = $this->base_url( $provider, (string) ( $config['base_url'] ?? '' ) );

		if ( '' === $base ) {
			return $this->error( 'chatchi_ai_base', __( "Enter your AI service's API address.", 'chatchi' ), 400 );
		}

		$data = $this->post(
			$base . '/chat/completions',
			$key,
			array(
				'model'    => $model,
				'messages' => array_merge(
					array(
						array(
							'role'    => 'system',
							'content' => trim( $stable . "\n\n" . $volatile ),
						),
					),
					$messages
				),
			)
		);

		if ( $data instanceof \WP_Error ) {
			return $data;
		}

		$choice = $data['choices'][0] ?? null;

		if ( is_array( $choice ) && 'content_filter' === ( $choice['finish_reason'] ?? '' ) ) {
			return $this->error( 'chatchi_ai_refused', __( 'The AI declined to answer this.', 'chatchi' ), 422 );
		}

		$text = trim( is_array( $choice ) ? (string) ( $choice['message']['content'] ?? '' ) : '' );

		return '' !== $text ? $text : $this->error( 'chatchi_ai_empty_answer', __( 'The AI returned an empty answer.', 'chatchi' ), 502 );
	}

	/**
	 * The API address: the one set in the settings, else the preset's.
	 *
	 * @param string $provider Preset.
	 * @param string $url      Address set in the settings.
	 */
	public function base_url( string $provider, string $url ): string {
		$url = untrailingslashit( trim( $url ) );

		if ( self::is_valid_base( $url ) ) {
			return $url;
		}

		return self::BASE_URLS[ $provider ] ?? '';
	}

	/**
	 * Whether an address is usable as an API base: https with a host name.
	 * Whether the host is safe to call is checked when the request is made.
	 *
	 * @param string $url Address.
	 */
	public static function is_valid_base( string $url ): bool {
		$parts = wp_parse_url( $url );

		return is_array( $parts ) && 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['query'] ) && strlen( $url ) <= 300;
	}

	/**
	 * @param string               $url  Endpoint.
	 * @param string               $key  API key.
	 * @param array<string, mixed> $body JSON body.
	 * @return array<string, mixed>|\WP_Error Decoded answer.
	 */
	private function post( string $url, string $key, array $body ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'            => self::TIMEOUT,
				'redirection'        => 0,
				// Refuses local and private network addresses (the address is configurable).
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				),
				'body'               => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error(
				'chatchi_ai_unreachable',
				/* translators: %s: technical reason. */
				sprintf( __( 'The AI service could not be reached (%s). Check the address, or ask your host whether the server can connect to it.', 'chatchi' ), $response->get_error_message() ),
				502
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 && is_array( $data ) ) {
			return $data;
		}

		$reason = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ? sanitize_text_field( $data['error']['message'] ) : '';

		if ( 401 === $status || 403 === $status ) {
			return $this->error( 'chatchi_ai_auth', trim( __( 'The API key was not accepted.', 'chatchi' ) . ' ' . $reason ), 502 );
		}

		if ( 402 === $status || 429 === $status ) {
			return $this->error( 'chatchi_ai_busy', __( 'The AI service is busy or the account has run out of credit. Try again later.', 'chatchi' ), 503 );
		}

		return $this->error(
			'chatchi_ai_failed',
			/* translators: 1: HTTP status code, 2: the service's message. */
			trim( sprintf( __( 'The AI service answered with an error (%1$d). %2$s', 'chatchi' ), $status, $reason ) ),
			502
		);
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status for REST responses.
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
