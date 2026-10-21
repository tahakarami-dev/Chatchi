<?php
/**
 * Attachment storage.
 *
 * @package ChatChi
 */

namespace ChatChi\Database\Repositories;

use ChatChi\Database\DatabaseException;
use ChatChi\Database\Table;
use ChatChi\Models\Attachment;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of {prefix}chatchi_attachments.
 *
 * @method Attachment|null find( int $id )
 * @method array<int, Attachment> find_many( int[] $ids )
 */
final class AttachmentRepository extends Repository {

	private const FILE_NAME_LENGTH = 200;

	/**
	 * {@inheritDoc}
	 */
	protected function table_name(): string {
		return Table::ATTACHMENTS;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function columns(): array {
		return array(
			'conversation_id' => '%d',
			'message_id'      => '%d',
			'file_name'       => '%s',
			'mime'            => '%s',
			'size'            => '%d',
			'width'           => '%d',
			'height'          => '%d',
			'duration'        => '%d',
			'path'            => '%s',
			'created_at'      => '%s',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function hydrate( array $row ): Attachment {
		return Attachment::from_row( $row );
	}

	/**
	 * Records a stored file.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $file            file_name, mime, size, width, height, duration, path.
	 *
	 * @throws DatabaseException When the insert fails.
	 */
	public function create( int $conversation_id, array $file ): Attachment {
		$id = $this->insert_row(
			array(
				'conversation_id' => $conversation_id,
				'message_id'      => 0,
				'file_name'       => $this->fit( (string) $file['file_name'], self::FILE_NAME_LENGTH ),
				'mime'            => (string) $file['mime'],
				'size'            => max( 0, (int) $file['size'] ),
				'width'           => max( 0, (int) ( $file['width'] ?? 0 ) ),
				'height'          => max( 0, (int) ( $file['height'] ?? 0 ) ),
				'duration'        => max( 0, (int) ( $file['duration'] ?? 0 ) ),
				'path'            => (string) $file['path'],
				'created_at'      => $this->now(),
			)
		);

		$attachment = $this->find( $id );

		if ( ! $attachment instanceof Attachment ) {
			throw new DatabaseException( sprintf( 'ChatChi attachment %d could not be read after insert.', $id ) );
		}

		return $attachment;
	}

	/**
	 * Links the attachment to the message that carries it.
	 *
	 * @param int $id         Attachment ID.
	 * @param int $message_id Message ID.
	 */
	public function set_message( int $id, int $message_id ): bool {
		return $this->update_row( $id, array( 'message_id' => $message_id ) );
	}

	/**
	 * Deletes an attachment row.
	 *
	 * @param int $id Attachment ID.
	 */
	public function delete( int $id ): bool {
		return $this->delete_row( $id );
	}
}
