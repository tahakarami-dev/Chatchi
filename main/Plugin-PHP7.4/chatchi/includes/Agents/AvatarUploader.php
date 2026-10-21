<?php
/**
 * Agent profile picture uploads.
 *
 * @package ChatChi
 */

namespace ChatChi\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Stores an uploaded image in the media library for use as an agent avatar.
 *
 * Only small JPEG, PNG, GIF and WebP images are accepted, checked by their
 * real content, not just their name. Attachments created here are tagged so
 * they can be cleaned up when replaced.
 */
final class AvatarUploader {

	public const META_KEY = '_chatchi_agent_avatar';

	private const DEFAULT_MAX_BYTES = 2 * MB_IN_BYTES;

	private const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
	);

	/**
	 * Returns the maximum upload size in bytes: the plugin's limit, or the
	 * server's upload limit if that is lower.
	 */
	public function max_bytes(): int {
		/**
		 * Filters the maximum agent avatar size, in bytes.
		 *
		 * @param int $bytes Maximum size.
		 */
		$limit = max( 1, (int) apply_filters( 'chatchi_avatar_max_bytes', self::DEFAULT_MAX_BYTES ) );

		return min( $limit, (int) wp_max_upload_size() );
	}

	/**
	 * Validates and stores an uploaded file.
	 *
	 * @param array<string, mixed>|null $file One entry of $_FILES.
	 * @return int|\WP_Error Attachment ID.
	 */
	public function upload( ?array $file ) {
		if ( ! $file || ! isset( $file['tmp_name'], $file['name'], $file['size'], $file['error'] ) ) {
			return $this->error( 'chatchi_avatar_missing', __( 'Choose an image to upload.', 'chatchi' ) );
		}

		// PHP's own upload limits report "too large" before our size check can run.
		$too_large = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );

		if ( $too_large || ( UPLOAD_ERR_OK === (int) $file['error'] && (int) $file['size'] > $this->max_bytes() ) ) {
			return $this->error(
				'chatchi_avatar_too_large',
				/* translators: %s: maximum file size, e.g. "2 MB". */
				sprintf( __( 'The image must be smaller than %s.', 'chatchi' ), size_format( $this->max_bytes() ) )
			);
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return $this->error( 'chatchi_avatar_failed', __( 'The image could not be uploaded.', 'chatchi' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::MIMES );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::MIMES, true ) ) {
			return $this->error( 'chatchi_avatar_type', __( 'Use a JPG, PNG, GIF or WebP image.', 'chatchi' ) );
		}

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::MIMES,
			)
		);

		if ( isset( $upload['error'] ) ) {
			return $this->error( 'chatchi_avatar_failed', (string) $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( pathinfo( (string) $file['name'], PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);

		if ( $attachment_id instanceof \WP_Error ) {
			wp_delete_file( $upload['file'] );
			return $this->error( 'chatchi_avatar_failed', __( 'The image could not be uploaded.', 'chatchi' ) );
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, self::META_KEY, 1 );

		return (int) $attachment_id;
	}

	/**
	 * Deletes an avatar attachment, but only one this plugin uploaded.
	 *
	 * Images the admin picked from elsewhere in the media library are kept.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function discard( int $attachment_id ): void {
		if ( $attachment_id > 0 && get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
