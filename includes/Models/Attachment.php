<?php
/**
 * Attachment model.
 *
 * @package ChatChi
 */

namespace ChatChi\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A file sent in a conversation.
 *
 * The file itself lives under uploads/chatchi-files at $path (relative,
 * with an unguessable name and no extension) and is only served through
 * the REST API after an access check.
 */
final class Attachment {

	public int $id = 0;

	public int $conversation_id = 0;

	/**
	 * The message that carries the file; 0 until it is sent.
	 */
	public int $message_id = 0;

	/**
	 * Original file name, cleaned; shown to people, never used on disk.
	 */
	public string $file_name = '';

	public string $mime = '';

	public int $size = 0;

	/**
	 * Pixel size of images; 0 for other files.
	 */
	public int $width = 0;

	public int $height = 0;

	public string $path = '';

	public string $created_at = '';

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		$attachment                  = new self();
		$attachment->id              = (int) $row['id'];
		$attachment->conversation_id = (int) $row['conversation_id'];
		$attachment->message_id      = (int) $row['message_id'];
		$attachment->file_name       = (string) $row['file_name'];
		$attachment->mime            = (string) $row['mime'];
		$attachment->size            = (int) $row['size'];
		$attachment->width           = (int) $row['width'];
		$attachment->height          = (int) $row['height'];
		$attachment->path            = (string) $row['path'];
		$attachment->created_at      = (string) $row['created_at'];

		return $attachment;
	}

	/**
	 * Whether browsers may show the file inline as a picture.
	 */
	public function is_image(): bool {
		return in_array( $this->mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'conversation_id' => $this->conversation_id,
			'message_id'      => $this->message_id,
			'file_name'       => $this->file_name,
			'mime'            => $this->mime,
			'size'            => $this->size,
			'width'           => $this->width,
			'height'          => $this->height,
			'path'            => $this->path,
			'created_at'      => $this->created_at,
		);
	}
}
