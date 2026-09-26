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
	 * Returns the configuration handed to the panel script.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array(
			'restUrl'         => esc_url_raw( rest_url( 'chatchi/v1' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'locale'          => str_replace( '_', '-', determine_locale() ),
			'dir'             => is_rtl() ? 'rtl' : 'ltr',
			'adminUrl'        => esc_url_raw( admin_url() ),
			'userEditUrl'     => esc_url_raw( admin_url( 'user-edit.php' ) ),
			'canManageAgents' => current_user_can( 'manage_options' ),
			'canUpload'       => current_user_can( 'upload_files' ),
			'maxAvatarBytes'  => $this->avatar_max_bytes,
			'strings'         => $this->strings->all(),
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
<html lang="<?php echo esc_attr( $locale ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?php echo esc_html( $title ); ?></title>
<style><?php echo $this->font->css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from an escaped URL. ?></style>
<link rel="stylesheet" href="<?php echo esc_url( $this->asset( 'assets/shared/tokens.css' ) ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $this->asset( 'assets/admin/panel.css' ) ); ?>">
<style><?php echo $this->color_overrides(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated hex colors only. ?></style>
</head>
<body>
<div id="chatchi-app" class="chatchi-app">
	<noscript><p class="chatchi-app__noscript"><?php esc_html_e( 'The ChatChi panel needs JavaScript.', 'chatchi' ); ?></p></noscript>
</div>
<script>window.ChatChiPanel = <?php echo wp_json_encode( $this->config(), $json_flags ); ?>;</script>
<script src="<?php echo esc_url( $this->asset( 'assets/shared/core.js' ) ); ?>"></script>
<script src="<?php echo esc_url( $this->asset( 'assets/admin/panel.js' ) ); ?>"></script>
</body>
</html>
		<?php
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
			':root{--chatchi-primary:%s;--chatchi-primary-hover:%s;--chatchi-primary-soft:%s;--chatchi-primary-contrast:%s;}',
			$tokens['primary'],
			$tokens['primaryHover'],
			$tokens['primarySoft'],
			$tokens['primaryContrast']
		);
	}
}
