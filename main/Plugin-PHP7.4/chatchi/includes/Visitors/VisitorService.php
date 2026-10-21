<?php
/**
 * Visitor use cases.
 *
 * @package ChatChi
 */

namespace ChatChi\Visitors;

use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Models\Visitor;
use ChatChi\Support\Failure;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point for everything the rest of the plugin needs about visitors.
 *
 * Returns WP_Error instead of throwing, so REST controllers can pass errors
 * straight through.
 */
final class VisitorService {

	private VisitorIdentity $identity;

	private VisitorRepository $visitors;

	private ProfileFields $fields;

	private ProfileValidator $validator;

	/**
	 * @param VisitorIdentity   $identity  Current-visitor resolution.
	 * @param VisitorRepository $visitors  Visitor storage.
	 * @param ProfileFields     $fields    Start-form configuration.
	 * @param ProfileValidator  $validator Start-form validation.
	 */
	public function __construct( VisitorIdentity $identity, VisitorRepository $visitors, ProfileFields $fields, ProfileValidator $validator ) {
		$this->identity  = $identity;
		$this->visitors  = $visitors;
		$this->fields    = $fields;
		$this->validator = $validator;
	}

	/**
	 * Returns the visitor making the current request, or null.
	 *
	 * @return Visitor|null|\WP_Error
	 */
	public function current() {
		try {
			return $this->identity->current();
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}
	}

	/**
	 * Identifies the visitor from the start form, creating them if needed.
	 *
	 * A returning visitor who submits the form again gets their details
	 * updated with the newly submitted, non-empty fields.
	 *
	 * @param array<string, mixed> $input   Raw start-form input.
	 * @param bool                 $offline Validate against the leave-a-message form.
	 * @return Visitor|\WP_Error
	 */
	public function start( array $input, bool $offline = false ) {
		$profile = $this->validator->validate( $input, $offline ? $this->fields->offline() : $this->fields->all() );

		if ( $profile instanceof \WP_Error ) {
			return $profile;
		}

		try {
			$existing = $this->identity->current();

			if ( ! $existing ) {
				return $this->identity->current_or_create( $profile );
			}

			if ( $profile && $this->visitors->update_profile( $existing->id, $profile ) ) {
				foreach ( $profile as $field => $value ) {
					$existing->{$field} = $value;
				}
			}

			return $existing;
		} catch ( \Throwable $e ) {
			return $this->failure( $e );
		}
	}

	/**
	 * Returns the start-form configuration for the widget.
	 *
	 * @return array{enabled: bool, fields: array<string, array{enabled: bool, required: bool}>, offline_fields: array<string, array{enabled: bool, required: bool}>}
	 */
	public function form_config(): array {
		return array(
			'enabled'        => $this->fields->form_enabled(),
			'fields'         => $this->fields->all(),
			'offline_fields' => $this->fields->offline(),
		);
	}

	/**
	 * Logs an unexpected failure and returns a safe, generic error.
	 *
	 * @param \Throwable $error Failure.
	 */
	private function failure( \Throwable $error ): \WP_Error {
		return Failure::report( $error, 'Visitor', 'chatchi_visitor_unavailable' );
	}
}
