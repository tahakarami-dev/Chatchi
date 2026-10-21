<?php
/**
 * Saved replies for agents.
 *
 * @package ChatChi
 */

namespace ChatChi\Canned;

use ChatChi\Support\PlainText;

defined( 'ABSPATH' ) || exit;

/**
 * Canned responses: short, reusable answers agents insert with "/" in the
 * composer. Kept in one option that is not autoloaded; a site has at most
 * a few hundred, and they are only read by the agent panel.
 *
 * Each response has a title, an optional shortcut (letters, digits, "-" and
 * "_") and a body. Bodies may contain {name} (the visitor's name) and
 * {agent} (the replying agent's name), filled in by the panel.
 */
final class CannedResponses {

	public const OPTION = 'chatchi_canned_responses';

	public const MAX_ITEMS = 500;

	public const TITLE_MAX_LENGTH = 100;

	public const SHORTCUT_MAX_LENGTH = 30;

	public const BODY_MAX_LENGTH = 2000;

	/**
	 * All responses, sorted by title.
	 *
	 * @return array<int, array{id: int, title: string, shortcut: string, body: string}>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$items  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $item ) {
			if ( is_array( $item ) && isset( $item['id'], $item['title'], $item['body'] ) ) {
				$items[] = array(
					'id'       => (int) $item['id'],
					'title'    => (string) $item['title'],
					'shortcut' => (string) ( $item['shortcut'] ?? '' ),
					'body'     => (string) $item['body'],
				);
			}
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['title'], $b['title'] ) ?: $a['id'] <=> $b['id'];
			}
		);

		return $items;
	}

	/**
	 * Adds a response.
	 *
	 * @param array<string, mixed> $input title, shortcut, body.
	 * @return array<string, mixed>|\WP_Error The new response.
	 */
	public function create( array $input ) {
		$items = $this->all();

		if ( count( $items ) >= self::MAX_ITEMS ) {
			return new \WP_Error(
				'chatchi_canned_limit',
				/* translators: %d: maximum number of saved replies. */
				sprintf( __( 'You can save at most %d replies.', 'chatchi' ), self::MAX_ITEMS ),
				array( 'status' => 400 )
			);
		}

		$clean = $this->validate( $input, $items, 0 );

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		$clean['id'] = $this->next_id( $items );
		$items[]     = $clean;
		$this->save( $items );

		return $clean;
	}

	/**
	 * Changes a response.
	 *
	 * @param int                  $id    Response ID.
	 * @param array<string, mixed> $input Any of title, shortcut, body.
	 * @return array<string, mixed>|\WP_Error The updated response.
	 */
	public function update( int $id, array $input ) {
		$items = $this->all();
		$index = $this->index_of( $items, $id );

		if ( null === $index ) {
			return $this->not_found();
		}

		$clean = $this->validate( $input + $items[ $index ], $items, $id );

		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		$clean['id']     = $id;
		$items[ $index ] = $clean;
		$this->save( $items );

		return $clean;
	}

	/**
	 * Deletes a response.
	 *
	 * @param int $id Response ID.
	 * @return true|\WP_Error
	 */
	public function delete( int $id ) {
		$items = $this->all();
		$index = $this->index_of( $items, $id );

		if ( null === $index ) {
			return $this->not_found();
		}

		array_splice( $items, $index, 1 );
		$this->save( $items );

		return true;
	}

	/**
	 * Checks and cleans a response.
	 *
	 * @param array<string, mixed>        $input  Raw input.
	 * @param array<int, array>           $items  Existing responses, to keep shortcuts unique.
	 * @param int                         $own_id ID of the response being edited, 0 for a new one.
	 * @return array{title: string, shortcut: string, body: string}|\WP_Error
	 */
	private function validate( array $input, array $items, int $own_id ) {
		$errors   = array();
		$title    = PlainText::line( is_string( $input['title'] ?? null ) ? $input['title'] : '' );
		$body     = PlainText::paragraphs( is_string( $input['body'] ?? null ) ? $input['body'] : '' );
		$shortcut = strtolower( trim( is_string( $input['shortcut'] ?? null ) ? $input['shortcut'] : '' ) );
		$shortcut = ltrim( $shortcut, '/' );

		if ( '' === $title ) {
			$errors['title'] = __( 'Give the reply a title.', 'chatchi' );
		} elseif ( mb_strlen( $title ) > self::TITLE_MAX_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors['title'] = sprintf( __( 'Use at most %d characters.', 'chatchi' ), self::TITLE_MAX_LENGTH );
		}

		if ( '' === $body ) {
			$errors['body'] = __( 'Write the reply text.', 'chatchi' );
		} elseif ( mb_strlen( $body ) > self::BODY_MAX_LENGTH ) {
			/* translators: %d: maximum number of characters. */
			$errors['body'] = sprintf( __( 'Use at most %d characters.', 'chatchi' ), self::BODY_MAX_LENGTH );
		}

		if ( '' !== $shortcut ) {
			if ( ! preg_match( '/^[a-z0-9_-]{1,' . self::SHORTCUT_MAX_LENGTH . '}$/', $shortcut ) ) {
				$errors['shortcut'] = __( 'Use English letters, digits, "-" or "_" only, like thanks or shipping-time.', 'chatchi' );
			} else {
				foreach ( $items as $item ) {
					if ( $item['id'] !== $own_id && $item['shortcut'] === $shortcut ) {
						$errors['shortcut'] = __( 'Another reply already uses this shortcut.', 'chatchi' );
						break;
					}
				}
			}
		}

		if ( $errors ) {
			return new \WP_Error(
				'chatchi_invalid_canned',
				__( 'Some fields need your attention.', 'chatchi' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		return array(
			'title'    => $title,
			'shortcut' => $shortcut,
			'body'     => $body,
		);
	}

	/**
	 * @param array<int, array> $items Responses.
	 */
	private function next_id( array $items ): int {
		$max = 0;

		foreach ( $items as $item ) {
			$max = max( $max, $item['id'] );
		}

		return $max + 1;
	}

	/**
	 * @param array<int, array> $items Responses.
	 * @param int               $id    Response ID.
	 */
	private function index_of( array $items, int $id ): ?int {
		foreach ( $items as $index => $item ) {
			if ( $item['id'] === $id ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array> $items Responses.
	 */
	private function save( array $items ): void {
		$rows = array();

		foreach ( $items as $item ) {
			$rows[] = array(
				'id'       => $item['id'],
				'title'    => $item['title'],
				'shortcut' => $item['shortcut'],
				'body'     => $item['body'],
			);
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $rows, '', false );
		} else {
			update_option( self::OPTION, $rows, false );
		}

		/**
		 * Fires after the saved replies change.
		 *
		 * @param array $rows All saved replies.
		 */
		do_action( 'chatchi_canned_responses_updated', $rows );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'chatchi_canned_not_found', __( 'This saved reply no longer exists.', 'chatchi' ), array( 'status' => 404 ) );
	}
}
