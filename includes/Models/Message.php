<?php
/**
 * Message model.
 *
 * @package ChatChi
 */

namespace ChatChi\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A single message in a conversation.
 */
final class Message {

	public const SENDER_VISITOR = 'visitor';
	public const SENDER_AGENT   = 'agent';
	public const SENDER_SYSTEM  = 'system';

	public int $id = 0;

	public int $conversation_id = 0;

	public string $sender_type = self::SENDER_VISITOR;

	/**
	 * Visitor ID or agent ID depending on sender_type; 0 for system messages.
	 */
	public int $sender_id = 0;

	public string $body = '';

	/**
	 * Client-generated ID that makes retried sends idempotent.
	 */
	public ?string $client_id = null;

	/**
	 * Attached file (see Models\Attachment), 0 for a text message.
	 */
	public int $attachment_id = 0;

	public string $created_at = '';

	public ?string $read_at = null;

	/**
	 * Returns every valid sender type.
	 *
	 * @return string[]
	 */
	public static function sender_types(): array {
		return array( self::SENDER_VISITOR, self::SENDER_AGENT, self::SENDER_SYSTEM );
	}

	/**
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		$message                  = new self();
		$message->id              = (int) $row['id'];
		$message->conversation_id = (int) $row['conversation_id'];
		$message->sender_type     = (string) $row['sender_type'];
		$message->sender_id       = (int) $row['sender_id'];
		// Column added in schema 5; absent while an upgrade is pending.
		$message->attachment_id   = (int) ( $row['attachment_id'] ?? 0 );
		$message->body            = (string) $row['body'];
		$message->client_id       = null === $row['client_id'] ? null : (string) $row['client_id'];
		$message->created_at      = (string) $row['created_at'];
		$message->read_at         = null === $row['read_at'] ? null : (string) $row['read_at'];

		return $message;
	}

	public function is_read(): bool {
		return null !== $this->read_at;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'conversation_id' => $this->conversation_id,
			'sender_type'     => $this->sender_type,
			'sender_id'       => $this->sender_id,
			'body'            => $this->body,
			'client_id'       => $this->client_id,
			'attachment_id'   => $this->attachment_id,
			'created_at'      => $this->created_at,
			'read_at'         => $this->read_at,
		);
	}
}
