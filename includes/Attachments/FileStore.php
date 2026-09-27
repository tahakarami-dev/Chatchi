<?php
/**
 * Private file storage for attachments.
 *
 * @package ChatChi
 */

namespace ChatChi\Attachments;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps attachment files under uploads/chatchi-files.
 *
 * Files are saved under a random 32-character name without an extension,
 * so they can neither be guessed nor run as scripts by the web server. On
 * Apache the folder also refuses direct requests (.htaccess); everywhere,
 * files are only handed out by the REST API after an access check.
 */
final class FileStore {

	public const DIRECTORY = 'chatchi-files';

	/**
	 * Absolute path of the storage folder, created on first use.
	 *
	 * @return string|null Null when the uploads folder is not writable.
	 */
	public function base(): ?string {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$base = trailingslashit( $uploads['basedir'] ) . self::DIRECTORY;

		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return null;
		}

		$this->protect( $base );

		return $base;
	}

	/**
	 * Moves an uploaded file into storage.
	 *
	 * @param string $source Temporary file.
	 * @return string|null Path relative to the storage folder, or null on failure.
	 */
	public function put( string $source ): ?string {
		$base = $this->base();

		if ( null === $base ) {
			return null;
		}

		$folder = gmdate( 'Y/m' );

		if ( ! wp_mkdir_p( $base . '/' . $folder ) ) {
			return null;
		}

		$relative = $folder . '/' . wp_generate_password( 32, false, false );
		$target   = $base . '/' . $relative;

		// Uploads arrive through PHP; anything else (e.g. tests) is copied.
		$moved = is_uploaded_file( $source ) ? @move_uploaded_file( $source, $target ) : @copy( $source, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Failures are handled below.

		if ( ! $moved ) {
			return null;
		}

		@chmod( $target, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best effort.

		return $relative;
	}

	/**
	 * Absolute path of a stored file, or null when it is missing or the
	 * path points outside storage.
	 *
	 * @param string $relative Path relative to the storage folder.
	 */
	public function path( string $relative ): ?string {
		$base = $this->base();

		if ( null === $base || ! preg_match( '#^\d{4}/\d{2}/[A-Za-z0-9]{32}$#', $relative ) ) {
			return null;
		}

		$path = $base . '/' . $relative;

		return is_file( $path ) ? $path : null;
	}

	/**
	 * Deletes a stored file.
	 *
	 * @param string $relative Path relative to the storage folder.
	 */
	public function delete( string $relative ): void {
		$path = $this->path( $relative );

		if ( null !== $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Adds files that stop direct access and folder listings.
	 *
	 * @param string $base Storage folder.
	 */
	private function protect( string $base ): void {
		$files = array(
			'.htaccess' => "# ChatChi: files are served by the plugin after an access check.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);

		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $base . '/' . $name ) ) {
				file_put_contents( $base . '/' . $name, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small static files in the plugin's own folder.
			}
		}
	}
}
