<?php
/**
 * Sending and fetching files in conversations.
 *
 * @package ChatChi
 */

namespace ChatChi\Attachments;

use ChatChi\Conversations\Actor;
use ChatChi\Conversations\ConversationService;
use ChatChi\Database\Repositories\AttachmentRepository;
use ChatChi\Database\Repositories\MessageRepository;
use ChatChi\Messages\MessageService;
use ChatChi\Models\Attachment;
use ChatChi\Models\Message;
use ChatChi\Settings\Settings;
use ChatChi\Support\Failure;
use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * A file is sent as a message: its body is the file name, so inbox
 * previews and search keep working, and the message links to the file.
 *
 * Files are checked by their content, not only their name, against a short
 * list of safe types (pictures, PDF, Office documents, text and ZIP). SVG
 * and HTML are never accepted, as browsers would run scripts in them.
 */
final class AttachmentService {

	/**
	 * Allowed types: extension pattern => MIME type.
	 */
	private const TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'pdf'          => 'application/pdf',
		'txt'          => 'text/plain',
		'csv'          => 'text/csv',
		'zip'          => 'application/zip',
		'doc'          => 'application/msword',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'          => 'application/vnd.ms-excel',
		'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	);

	private ConversationService $conversations;

	private MessageService $messages;

	private MessageRepository $message_repository;

	private AttachmentRepository $attachments;

	private FileStore $store;

	private Settings $settings;

	/**
	 * @param ConversationService  $conversations      Conversation access.
	 * @param MessageService       $messages           Sending messages.
	 * @param MessageRepository    $message_repository Message storage, to link files.
	 * @param AttachmentRepository $attachments        Attachment storage.
	 * @param FileStore            $store              File storage.
	 * @param Settings             $settings           Plugin settings.
	 */
	public function __construct(
		ConversationService $conversations,
		MessageService $messages,
		MessageRepository $message_repository,
		AttachmentRepository $attachments,
		FileStore $store,
		Settings $settings
	) {
		$this->conversations      = $conversations;
		$this->messages           = $messages;
		$this->message_repository = $message_repository;
		$this->attachments        = $attachments;
		$this->store              = $store;
		$this->settings           = $settings;
	}

	/**
	 * Allowed types, after the chatchi_attachment_types filter.
	 *
	 * @return array<string, string> Extension pattern => MIME type.
	 */
	public function types(): array {
		/**
		 * Filters the file types allowed in conversations.
		 *
		 * Scriptable types (SVG, HTML, PHP…) are always refused, whatever
		 * this returns.
		 *
		 * @param array<string, string> $types Extension pattern => MIME type.
		 */
		$types = apply_filters( 'chatchi_attachment_types', self::TYPES );

		return array_filter(
			is_array( $types ) ? $types : self::TYPES,
			static function ( $mime, $extensions ): bool {
				$scriptable_mime = preg_match( '#^(image/svg|text/html|application/xhtml|text/xml|application/xml|text/javascript|application/javascript|application/x-(httpd-)?php|application/x-sh)#i', (string) $mime );
				$scriptable_ext  = preg_match( '/(^|\|)(svgz?|s?html?|xhtml|xml|m?js|php\d?|phtml|phar|sh|exe|bat|cmd)(\||$)/i', (string) $extensions );

				return is_string( $mime ) && ! $scriptable_mime && ! $scriptable_ext;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Extensions for the file picker's accept attribute, e.g. ".jpg,.png".
	 */
	public function accept(): string {
		$extensions = array();

		foreach ( array_keys( $this->types() ) as $pattern ) {
			foreach ( explode( '|', $pattern ) as $extension ) {
				$extensions[] = '.' . $extension;
			}
		}

		return implode( ',', $extensions );
	}

	/**
	 * Largest allowed file, in bytes.
	 */
	public function max_bytes(): int {
		$setting = max( 1, (int) $this->settings->get( 'attachment_max_mb' ) ) * MB_IN_BYTES;

		return (int) min( $setting, wp_max_upload_size() );
	}

	/**
	 * Whether visitors may send files. Agents always may.
	 */
	public function enabled_for_visitors(): bool {
		return (bool) $this->settings->get( 'attachments_enabled' );
	}

	/**
	 * Settings the widget and panel need.
	 *
	 * @param Actor|null $actor Who will upload; null for visitors before they start.
	 * @return array{enabled: bool, max_bytes: int, accept: string}
	 */
	public function client_config( ?Actor $actor = null ): array {
		return array(
			'enabled'   => ( $actor && $actor->is_agent() ) || $this->enabled_for_visitors(),
			'max_bytes' => $this->max_bytes(),
			'accept'    => $this->accept(),
		);
	}

	/**
	 * Stores an uploaded file and sends it as a message.
	 *
	 * @param Actor                     $actor           Sender.
	 * @param int                       $conversation_id Conversation ID.
	 * @param array<string, mixed>|null $file            Entry of $_FILES.
	 * @param string|null               $client_id       Client UUID, makes retries safe.
	 * @return Message|\WP_Error The new message, with attachment_id set.
	 */
	public function upload( Actor $actor, int $conversation_id, ?array $file, ?string $client_id = null ) {
		$conversation = $this->conversations->get( $actor, $conversation_id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		if ( $actor->is_visitor() && ! $this->enabled_for_visitors() ) {
			return $this->error( 'chatchi_attachments_disabled', __( 'Sending files is turned off.', 'chatchi' ), 403 );
		}

		if ( $conversation->is_closed() ) {
			return $this->error( 'chatchi_conversation_closed', __( 'This conversation has been closed.', 'chatchi' ), 409 );
		}

		$checked = $this->check( $file );

		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		$path = $this->store->put( (string) $file['tmp_name'] );

		if ( null === $path ) {
			return $this->error( 'chatchi_attachment_failed', __( 'The file could not be saved. Please try again.', 'chatchi' ), 500 );
		}

		try {
			$attachment = $this->attachments->create( $conversation->id, $checked + array( 'path' => $path ) );
		} catch ( \Throwable $e ) {
			$this->store->delete( $path );
			return Failure::report( $e, 'Attachment', 'chatchi_attachment_failed' );
		}

		$message = $this->messages->send( $actor, $conversation->id, $this->caption( $attachment->file_name ), $client_id );

		if ( $message instanceof \WP_Error || $message->attachment_id > 0 ) {
			// Refused, or a retry of a file that was already sent.
			$this->discard( $attachment );
			return $message;
		}

		try {
			$this->message_repository->attach( $message->id, $attachment->id );
			$this->attachments->set_message( $attachment->id, $message->id );
		} catch ( \Throwable $e ) {
			return Failure::report( $e, 'Attachment', 'chatchi_attachment_failed' );
		}

		$message->attachment_id = $attachment->id;

		return $message;
	}

	/**
	 * Returns a file the actor may download.
	 *
	 * @param Actor $actor           Reader.
	 * @param int   $conversation_id Conversation ID from the URL.
	 * @param int   $attachment_id   Attachment ID.
	 * @return array{0: Attachment, 1: string}|\WP_Error The attachment and its absolute path.
	 */
	public function download( Actor $actor, int $conversation_id, int $attachment_id ) {
		$conversation = $this->conversations->get( $actor, $conversation_id );

		if ( $conversation instanceof \WP_Error ) {
			return $conversation;
		}

		try {
			$attachment = $this->attachments->find( $attachment_id );
		} catch ( \Throwable $e ) {
			return Failure::report( $e, 'Attachment', 'chatchi_attachment_failed' );
		}

		$path = $attachment && $attachment->conversation_id === $conversation->id && $attachment->message_id > 0 ? $this->store->path( $attachment->path ) : null;

		if ( null === $path ) {
			return $this->error( 'chatchi_attachment_not_found', __( 'This file is no longer available.', 'chatchi' ), 404 );
		}

		return array( $attachment, $path );
	}

	/**
	 * Attachments of the given messages, keyed by attachment ID.
	 *
	 * @param Message[] $messages Messages.
	 * @return array<int, Attachment>
	 */
	public function for_messages( array $messages ): array {
		$ids = array();

		foreach ( $messages as $message ) {
			if ( $message->attachment_id > 0 ) {
				$ids[] = $message->attachment_id;
			}
		}

		if ( ! $ids ) {
			return array();
		}

		try {
			return $this->attachments->find_many( $ids );
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'Attachment', 'chatchi_attachment_failed' );
			return array();
		}
	}

	/**
	 * Checks an upload; returns the details to store.
	 *
	 * @param array<string, mixed>|null $file Entry of $_FILES.
	 * @return array{file_name: string, mime: string, size: int, width: int, height: int}|\WP_Error
	 */
	private function check( ?array $file ) {
		if ( ! $file || ! isset( $file['tmp_name'], $file['name'], $file['size'], $file['error'] ) ) {
			return $this->error( 'chatchi_attachment_missing', __( 'Choose a file to send.', 'chatchi' ), 400 );
		}

		$too_large = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );

		if ( $too_large || ( UPLOAD_ERR_OK === (int) $file['error'] && (int) $file['size'] > $this->max_bytes() ) ) {
			return $this->error(
				'chatchi_attachment_too_large',
				/* translators: %s: maximum file size, e.g. "5 MB". */
				sprintf( __( 'The file must be smaller than %s.', 'chatchi' ), size_format( $this->max_bytes() ) ),
				413
			);
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] <= 0 || ! is_file( (string) $file['tmp_name'] ) ) {
			return $this->error( 'chatchi_attachment_failed', __( 'The file could not be uploaded.', 'chatchi' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$types = $this->types();
		$name  = $this->clean_name( (string) $file['name'] );
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], $name, $types );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], $types, true ) ) {
			return $this->error( 'chatchi_attachment_type', __( 'This type of file cannot be sent. Use a picture, PDF, Word, Excel, text or ZIP file.', 'chatchi' ), 415 );
		}

		// WordPress may correct a wrong extension to match the content.
		if ( ! empty( $check['proper_filename'] ) ) {
			$name = $this->clean_name( (string) $check['proper_filename'] );
		}

		$width  = 0;
		$height = 0;

		if ( 0 === strpos( $check['type'], 'image/' ) ) {
			$size = @getimagesize( (string) $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid images are refused below.

			if ( ! $size ) {
				return $this->error( 'chatchi_attachment_type', __( 'This picture could not be read.', 'chatchi' ), 415 );
			}

			$width  = (int) $size[0];
			$height = (int) $size[1];
		}

		return array(
			'file_name' => $name,
			'mime'      => $check['type'],
			'size'      => (int) $file['size'],
			'width'     => $width,
			'height'    => $height,
		);
	}

	/**
	 * A safe display name: no folders, no control characters, and short
	 * enough to store, keeping the extension.
	 *
	 * @param string $name Original file name.
	 */
	private function clean_name( string $name ): string {
		$name = PlainText::line( wp_basename( str_replace( '\\', '/', $name ) ) );
		$name = str_replace( array( '"', '/', ':', '*', '?', '<', '>', '|' ), '', $name );

		if ( mb_strlen( $name ) > 150 ) {
			$extension = pathinfo( $name, PATHINFO_EXTENSION );
			$name      = mb_substr( pathinfo( $name, PATHINFO_FILENAME ), 0, 140 ) . ( '' !== $extension ? '.' . $extension : '' );
		}

		return '' !== trim( $name, ' .' ) ? $name : 'file';
	}

	/**
	 * The message text for a file: its name, within the message length.
	 *
	 * @param string $name File name.
	 */
	private function caption( string $name ): string {
		$max = max( 20, (int) $this->settings->get( 'message_max_length' ) );

		return mb_strlen( $name ) > $max ? mb_substr( $name, 0, $max - 1 ) . '…' : $name;
	}

	/**
	 * Removes a stored file that was not sent.
	 *
	 * @param Attachment $attachment Attachment.
	 */
	private function discard( Attachment $attachment ): void {
		$this->store->delete( $attachment->path );

		try {
			$this->attachments->delete( $attachment->id );
		} catch ( \Throwable $e ) {
			Failure::report( $e, 'Attachment', 'chatchi_attachment_failed' );
		}
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
