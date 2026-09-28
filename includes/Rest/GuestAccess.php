<?php
/**
 * Keeps the chat working for guests on sites that restrict the REST API.
 *
 * @package ChatChi
 */

namespace ChatChi\Rest;

use ChatChi\Core\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Guests (visitors who are not logged in) must be able to chat on any site.
 * Three things commonly stop them, while logged-in users are unaffected:
 *
 * - Security plugins and hardening snippets that close the REST API to
 *   visitors who are not logged in ("Disable REST API", "Restrict REST API
 *   access", …). The widget's routes are made for anonymous visitors and
 *   carry their own checks (same-site requests, bot checks, rate limits), so
 *   they are let through; the rest of the site's API stays closed.
 * - Firewalls and server rules that block /wp-json/ for guests. The widget
 *   then switches to admin-ajax.php, which this class answers by running the
 *   same REST request internally.
 * - Caches that store REST responses for guests (LiteSpeed Cache does by
 *   default). ChatChi's responses belong to one visitor, so they are marked
 *   as not cacheable.
 */
final class GuestAccess implements Module {

	/**
	 * admin-ajax.php action of the widget's fallback transport.
	 */
	public const AJAX_ACTION = 'chatchi_widget';

	/**
	 * Routes of the chat widget, under the plugin's namespace.
	 */
	private const WIDGET_PREFIX = '/' . RouteProvider::NAMESPACE . '/widget/';

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// After every other filter, so a plugin's "logged-in users only" wins nowhere else.
		add_filter( 'rest_authentication_errors', array( $this, 'allow_guests' ), PHP_INT_MAX );
		add_filter( 'rest_post_dispatch', array( $this, 'no_cache' ), 10, 3 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
	}

	/**
	 * Lets guests reach the widget's routes when another plugin closed the
	 * REST API to visitors who are not logged in.
	 *
	 * Only guests, only the widget's routes, and never a failed nonce check
	 * (which protects logged-in users).
	 *
	 * @param \WP_Error|null|true $result Authentication result so far.
	 * @return \WP_Error|null|true
	 */
	public function allow_guests( $result ) {
		if ( ! $result instanceof \WP_Error || is_user_logged_in() || 'rest_cookie_invalid_nonce' === $result->get_error_code() ) {
			return $result;
		}

		if ( ! self::is_widget_route( $this->current_route() ) ) {
			return $result;
		}

		/**
		 * Filters whether guests may use the chat widget when the site closes
		 * the REST API to visitors who are not logged in.
		 *
		 * @param bool      $allow  Whether to let the request through.
		 * @param \WP_Error $result The error another plugin returned.
		 */
		return apply_filters( 'chatchi_allow_guest_widget_api', true, $result ) ? null : $result;
	}

	/**
	 * Marks ChatChi's REST responses as not cacheable, for browsers, proxies
	 * and page-cache plugins alike.
	 *
	 * @param \WP_HTTP_Response|mixed $response Response.
	 * @param \WP_REST_Server|mixed   $server   Server.
	 * @param \WP_REST_Request|mixed  $request  Request.
	 * @return \WP_HTTP_Response|mixed
	 */
	public function no_cache( $response, $server, $request ) {
		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request || 0 !== strpos( $request->get_route(), '/' . RouteProvider::NAMESPACE . '/' ) ) {
			return $response;
		}

		$headers = $response->get_headers();

		if ( ! isset( $headers['Cache-Control'] ) ) {
			$response->header( 'Cache-Control', 'no-store, private' );
		}

		// LiteSpeed server and its plugin, which cache REST responses for guests.
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		do_action( 'litespeed_control_set_nocache', 'ChatChi: one visitor\'s data' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Read by page-cache plugins.
		}

		return $response;
	}

	/**
	 * The widget's fallback transport: admin-ajax.php?action=chatchi_widget
	 * &route=/widget/… runs that REST request and answers like the REST API.
	 *
	 * Only the widget's routes are reachable. The request goes through the
	 * route's own permission checks, as over REST.
	 */
	public function ajax(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- Logged-in users are checked against the REST nonce below; guests are identified by their chat cookie.
		$route = isset( $_GET['route'] ) ? sanitize_text_field( wp_unslash( $_GET['route'] ) ) : '';
		$route = '/' . RouteProvider::NAMESPACE . '/' . ltrim( $route, '/' );

		if ( ! self::is_widget_route( $route ) || false !== strpos( $route, '..' ) ) {
			$this->send( new \WP_REST_Response( array( 'code' => 'rest_no_route', 'message' => __( 'No route was found matching the URL and request method.', 'chatchi' ), 'data' => array( 'status' => 404 ) ), 404 ) );
		}

		$server  = rest_get_server();
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$request = new \WP_REST_Request( $method, $route );

		$request->set_headers( $server->get_headers( wp_unslash( $_SERVER ) ) );

		$query = wp_unslash( $_GET );
		unset( $query['action'], $query['route'] );
		$request->set_query_params( $query );

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$post = wp_unslash( $_POST );
			unset( $post['action'] );
			$request->set_body_params( $post );
			$request->set_file_params( $_FILES );
			$request->set_body( (string) file_get_contents( 'php://input' ) );
		}
		// phpcs:enable

		// As over REST: a logged-in user is only trusted with a valid nonce;
		// without one the request is a guest's.
		if ( is_user_logged_in() ) {
			$nonce = (string) $request->get_header( 'x_wp_nonce' );

			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				wp_set_current_user( 0 );
			}
		}

		$response = rest_ensure_response( rest_do_request( $request ) );

		/** This filter is documented in wp-includes/rest-api/class-wp-rest-server.php */
		$response = apply_filters( 'rest_post_dispatch', $response, $server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core REST hook, applied as the REST server does.

		$this->send( $response );
	}

	/**
	 * Whether a route is one of the widget's.
	 *
	 * @param string $route Route, e.g. /chatchi/v1/widget/session.
	 */
	public static function is_widget_route( string $route ): bool {
		return 0 === strpos( $route, self::WIDGET_PREFIX );
	}

	/**
	 * The REST route of the current request, before the server has parsed it.
	 */
	private function current_route(): string {
		global $wp;

		$route = isset( $wp->query_vars['rest_route'] ) ? (string) $wp->query_vars['rest_route'] : '';

		if ( '' === $route && isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared with the widget's prefix.
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return '/' . ltrim( untrailingslashit( $route ), '/' );
	}

	/**
	 * Prints a REST response as the REST API would, and ends the request.
	 *
	 * @param \WP_REST_Response|\WP_HTTP_Response $response Response.
	 * @return never
	 */
	private function send( $response ): void {
		$server = rest_get_server();
		$data   = $server->response_to_data( rest_ensure_response( $response ), false );

		// Anything printed before (notices, other plugins) would corrupt the JSON.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( ! headers_sent() ) {
			status_header( $response->get_status() );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'X-Content-Type-Options: nosniff' );

			foreach ( $response->get_headers() as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		echo wp_json_encode( $data );
		exit;
	}
}
