<?php
/**
 * Standalone agent panel document.
 *
 * @package ChatChi
 */

namespace ChatChi\Admin;

use ChatChi\Frontend\ColorPalette;
use ChatChi\Frontend\FontFace;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the complete HTML document of the agent panel.
 *
 * The panel is a full-screen app of its own: the WordPress admin menu, admin
 * bar, notices and other plugins' admin assets are not loaded. It talks to
 * the chatchi/v1 agent API with the standard REST nonce.
 */
final class PanelPage {

	private ColorPalette $palette;

	private FontFace $font;

	private PanelStrings $strings;

	private int $avatar_max_bytes;

	/**
	 * Nonce for this page's inline script and styles, allowed by the CSP.
	 */
	private string $csp_nonce = '';

	/**
	 * @param ColorPalette $palette          Brand colors.
	 * @param FontFace     $font             Bundled font.
	 * @param PanelStrings $strings          Interface text.
	 * @param int          $avatar_max_bytes Largest avatar upload, for client-side checks.
	 */
	public function __construct( ColorPalette $palette, FontFace $font, PanelStrings $strings, int $avatar_max_bytes ) {
		$this->palette          = $palette;
		$this->font             = $font;
		$this->strings          = $strings;
		$this->avatar_max_bytes = $avatar_max_bytes;
	}

	/**
	 * Sends security headers for the panel document. Call before render().
	 *
	 * The Content-Security-Policy only lets this page's own code run: no
	 * injected scripts, no framing by other sites, no plugins or forms
	 * posting elsewhere. Inline style attributes stay allowed because the
	 * interface sets sizes with them; they cannot run code.
	 */
	public function send_headers(): void {
		$policy = $this->content_security_policy();

		if ( '' !== $policy ) {
			header( 'Content-Security-Policy: ' . $policy );
		}

		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		header( 'Cross-Origin-Opener-Policy: same-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
	}

	/**
	 * Returns the Content-Security-Policy of the panel.
	 */
	public function content_security_policy(): string {
		$assets = $this->origin( CHATCHI_URL );
		$api    = $this->origin( rest_url() );
		$nonce  = "'nonce-" . $this->nonce() . "'";

		$directives = array(
			'default-src'     => "'self'",
			'script-src'      => trim( "'self' {$assets} {$nonce}" ),
			'style-src'       => trim( "'self' {$assets} {$nonce}" ),
			'style-src-attr'  => "'unsafe-inline'",
			'font-src'        => trim( "'self' {$assets} data:" ),
			'img-src'         => "'self' data: blob: https:",
			'connect-src'     => trim( "'self' {$api}" ),
			'frame-ancestors' => "'none'",
			'base-uri'        => "'none'",
			'form-action'     => "'self'",
			'object-src'      => "'none'",
		);

		$policy = implode(
			'; ',
			array_map(
				static function ( string $name, string $value ): string {
					return $name . ' ' . $value;
				},
				array_keys( $directives ),
				$directives
			)
		);

		/**
		 * Filters the Content-Security-Policy of the agent panel.
		 *
		 * Return an empty string to send none.
		 *
		 * @param string $policy Policy.
		 * @param string $nonce  Nonce of the page's inline script and styles.
		 */
		return (string) apply_filters( 'chatchi_panel_csp', $policy, $this->nonce() );
	}

	/**
	 * Returns the configuration handed to the panel script.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array(
			'restUrl'           => esc_url_raw( rest_url( 'chatchi/v1' ) ),
			'nonce'             => wp_create_nonce( 'wp_rest' ),
			'locale'            => str_replace( '_', '-', determine_locale() ),
			'dir'               => is_rtl() ? 'rtl' : 'ltr',
			'adminUrl'          => esc_url_raw( admin_url() ),
			'userEditUrl'       => esc_url_raw( admin_url( 'user-edit.php' ) ),
			// Shown on desktop notifications; empty when the site has no icon.
			'iconUrl'           => esc_url_raw( (string) get_site_icon_url( 192 ) ),
			'canManageAgents'   => current_user_can( 'manage_options' ),
			'canManageSettings' => current_user_can( 'manage_options' ),
			'canUpload'         => current_user_can( 'upload_files' ),
			'maxAvatarBytes'    => $this->avatar_max_bytes,
			'strings'           => $this->strings->all(),
		);
	}

	/**
	 * Prints the document.
	 */
	public function render(): void {
		$locale = str_replace( '_', '-', determine_locale() );
		$dir    = is_rtl() ? 'rtl' : 'ltr';
		$title  = sprintf(
			/* translators: %s: site name. */
			__( 'ChatChi — %s', 'chatchi' ),
			get_bloginfo( 'name' )
		);

		$json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $locale ); ?>" dir="<?php echo esc_attr( $dir ); ?>" data-theme="<?php echo esc_attr( PanelTheme::get( get_current_user_id() ) ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?php echo esc_html( $title ); ?></title>
<style nonce="<?php echo esc_attr( $this->nonce() ); ?>"><?php echo $this->font->css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from an escaped URL. ?></style>
<link rel="stylesheet" href="<?php echo esc_url( $this->asset( 'assets/shared/tokens.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $this->asset( 'assets/admin/panel.css' ) ); ?>">
<style nonce="<?php echo esc_attr( $this->nonce() ); ?>"><?php echo $this->color_overrides(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated hex colors only. ?></style>
</head>
<body>
<div id="chatchi-app" class="chatchi-app">
	<noscript><p class="chatchi-app__noscript"><?php esc_html_e( 'The ChatChi panel needs JavaScript.', 'chatchi' ); ?></p></noscript>
</div>
<script nonce="<?php echo esc_attr( $this->nonce() ); ?>">window.ChatChiPanel = <?php echo wp_json_encode( $this->config(), $json_flags ); ?>;</script>
<script src="<?php echo esc_url( $this->asset( 'assets/shared/core.js' ) ); ?>"></script>
<script src="<?php echo esc_url( $this->asset( 'assets/admin/panel.js' ) ); ?>"></script>
</body>
</html>
		<?php
	}

	/**
	 * Returns the nonce of this page view, creating it on first use.
	 */
	private function nonce(): string {
		if ( '' === $this->csp_nonce ) {
			$this->csp_nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Random nonce, not obfuscation.
		}

		return $this->csp_nonce;
	}

	/**
	 * Returns "scheme://host[:port]" of a URL when it is another origin than
	 * the site, or an empty string.
	 *
	 * @param string $url URL.
	 */
	private function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		$site  = wp_parse_url( home_url() );

		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$same   = isset( $site['host'] ) && strtolower( $site['host'] ) === strtolower( $parts['host'] )
			&& ( $site['port'] ?? null ) === ( $parts['port'] ?? null )
			&& ( $site['scheme'] ?? '' ) === $parts['scheme'];

		return $same ? '' : esc_url_raw( $origin );
	}

	/**
	 * @param string $path Path relative to the plugin root.
	 */
	private function asset( string $path ): string {
		return add_query_arg( 'ver', CHATCHI_VERSION, CHATCHI_URL . $path );
	}

	/**
	 * Returns :root overrides for the configured brand color.
	 */
	private function color_overrides(): string {
		$tokens = $this->palette->tokens();

		return sprintf(
			':root{--chatchi-primary:%s;--chatchi-primary-hover:%s;--chatchi-primary-soft-light:%s;--chatchi-primary-contrast:%s;}',
			$tokens['primary'],
			$tokens['primaryHover'],
			$tokens['primarySoft'],
			$tokens['primaryContrast']
		);
	}
}
