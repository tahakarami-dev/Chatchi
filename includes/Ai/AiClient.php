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
 * Two protocols, over plain HTTP (no SDK, so the plugin keeps running on
 * PHP 7.4 without Composer):
 *
 * - "anthropic": Claude's Messages API (POST {base}/v1/messages), with
 *   x-api-key and anthropic-version headers. The stable part of the system
 *   prompt is marked for prompt caching.
 * - "openai": any OpenAI-compatible Chat Completions API
 *   (POST {base}/chat/completions, Bearer key), which most relays offer.
 *
 * The base address is configurable, so a relay can be used where the
 * provider cannot be reached directly. The API key never appears in errors.
 */
final class AiClient {

	public const PROVIDERS = array( 'anthropic', 'openai' );

	public const ANTHROPIC_BASE = 'https://api.anthropic.com';

	public const OPENAI_BASE = 'https://api.openai.com/v1';

	public const DEFAULT_ANTHROPIC_MODEL = 'claude-opus-5';

	private const TIMEOUT = 60;

	/**
	 * Answers are short chat messages; thinking (on by default on current
	 * Claude models) counts towards this limit too.
	 */
	private const MAX_TOKENS = 4096;

	/**
	 * Asks the model.
	 *
	 * @param array<string, string>                            $config   provider, api_key, base_url, model.
	 * @param string                                           $stable   System prompt part that rarely changes (cached).
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

		if ( 'anthropic' === $provider && '' === $model ) {
			$model = self::DEFAULT_ANTHROPIC_MODEL;
		}

		if ( '' === $model ) {
			return $this->error( 'chatchi_ai_model', __( 'Enter the model name.', 'chatchi' ), 400 );
		}

		if ( ! $messages ) {
			return $this->error( 'chatchi_ai_empty', __( 'There is nothing to answer yet.', 'chatchi' ), 400 );
		}

		$base = $this->base_url( $provider, (string) ( $config['base_url'] ?? '' ) );

		return 'anthropic' === $provider
			? $this->anthropic( $base, $key, $model, $stable, $volatile, $messages )
			: $this->openai( $base, $key, $model, $stable, $volatile, $messages );
	}

	/**
	 * The API address: the configured one (https only), else the provider's.
	 *
	 * @param string $provider Provider.
	 * @param string $url      Configured address.
	 */
	public function base_url( string $provider, string $url ): string {
		$url = untrailingslashit( trim( $url ) );

		if ( self::is_valid_base( $url ) ) {
			return $url;
		}

		return 'anthropic' === $provider ? self::ANTHROPIC_BASE : self::OPENAI_BASE;
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
	 * Claude's Messages API.
	 *
	 * @param string                                           $base     API base, without /v1.
	 * @param string                                           $key      API key.
	 * @param string                                           $model    Model.
	 * @param string                                           $stable   Cached system text.
	 * @param string                                           $volatile Per-request system text.
	 * @param array<int, array{role: string, content: string}> $messages Turns.
	 * @return string|\WP_Error
	 */
	private function anthropic( string $base, string $key, string $model, string $stable, string $volatile, array $messages ) {
		$system = array(
			array(
				'type'          => 'text',
				'text'          => $stable,
				'cache_control' => array( 'type' => 'ephemeral' ),
			),
		);

		if ( '' !== $volatile ) {
			$system[] = array(
				'type' => 'text',
				'text' => $volatile,
			);
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $system,
			'messages'   => $messages,
		);

		$headers = array(
			'Content-Type'      => 'application/json',
			'x-api-key'         => $key,
			'anthropic-version' => '2023-06-01',
		);

		// Short chat answers: low effort keeps them fast and cheap, on the
		// models that support the effort setting.
		if ( preg_match( '/^claude-(?:opus-(?:4-[5678]|5)|sonnet-(?:4-6|5)|fable-5)/', $model ) ) {
			$body['output_config'] = array( 'effort' => 'low' );
		}

		// On Anthropic's own API, a request the Opus 5 / Fable 5 safety
		// classifiers decline is retried on the model Anthropic recommends.
		// Relays may not know this beta, so it is only sent there.
		if ( self::ANTHROPIC_BASE === $base && preg_match( '/^claude-(?:opus-5|fable-5)/', $model ) ) {
			$body['fallbacks']        = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		$data = $this->post( rtrim( $base, '/' ) . ( preg_match( '#/v1$#', $base ) ? '' : '/v1' ) . '/messages', $headers, $body );

		if ( $data instanceof \WP_Error ) {
			return $data;
		}

		if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
			return $this->error( 'chatchi_ai_refused', __( 'The AI declined to answer this.', 'chatchi' ), 422 );
		}

		$text = '';

		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}

		return $this->answer( $text );
	}

	/**
	 * An OpenAI-compatible Chat Completions API.
	 *
	 * @param string                                           $base     API base, including /v1 when the service uses it.
	 * @param string                                           $key      API key.
	 * @param string                                           $model    Model.
	 * @param string                                           $stable   System text.
	 * @param string                                           $volatile Per-request system text.
	 * @param array<int, array{role: string, content: string}> $messages Turns.
	 * @return string|\WP_Error
	 */
	private function openai( string $base, string $key, string $model, string $stable, string $volatile, array $messages ) {
		$body = array(
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
		);

		$data = $this->post(
			$base . '/chat/completions',
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key,
			),
			$body
		);

		if ( $data instanceof \WP_Error ) {
			return $data;
		}

		$choice = $data['choices'][0] ?? null;

		if ( is_array( $choice ) && 'content_filter' === ( $choice['finish_reason'] ?? '' ) ) {
			return $this->error( 'chatchi_ai_refused', __( 'The AI declined to answer this.', 'chatchi' ), 422 );
		}

		return $this->answer( is_array( $choice ) ? (string) ( $choice['message']['content'] ?? '' ) : '' );
	}

	/**
	 * @param string               $url     Endpoint.
	 * @param array<string, string> $headers Headers.
	 * @param array<string, mixed> $body    JSON body.
	 * @return array<string, mixed>|\WP_Error Decoded answer.
	 */
	private function post( string $url, array $headers, array $body ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'            => self::TIMEOUT,
				'redirection'        => 0,
				// Refuses local and private network addresses (the base URL is configurable).
				'reject_unsafe_urls' => true,
				'headers'            => $headers,
				'body'               => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error(
				'chatchi_ai_unreachable',
				/* translators: %s: technical reason. */
				sprintf( __( 'The AI service could not be reached (%s). If your server cannot connect to it, use a relay address.', 'chatchi' ), $response->get_error_message() ),
				502
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 && is_array( $data ) ) {
			return $data;
		}

		// Both protocols put a readable reason in error.message.
		$reason = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ? sanitize_text_field( $data['error']['message'] ) : '';

		if ( 401 === $status || 403 === $status ) {
			return $this->error( 'chatchi_ai_auth', __( 'The API key was not accepted.', 'chatchi' ) . ( '' !== $reason ? ' ' . $reason : '' ), 502 );
		}

		if ( 429 === $status ) {
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
	 * @param string $text Raw answer.
	 * @return string|\WP_Error
	 */
	private function answer( string $text ) {
		$text = trim( $text );

		return '' !== $text ? $text : $this->error( 'chatchi_ai_empty_answer', __( 'The AI returned an empty answer.', 'chatchi' ), 502 );
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
