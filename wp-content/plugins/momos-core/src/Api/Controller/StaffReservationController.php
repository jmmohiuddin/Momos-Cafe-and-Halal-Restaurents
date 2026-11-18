<?php
declare(strict_types=1);

namespace Momos\Api\Controller;

use Momos\Api\ApiModule;
use Momos\Api\Http\ErrorResponder;
use Momos\Api\Http\SchemaValidator;
use Momos\Api\RateLimit\RateLimiter;
use Momos\Branch\Repository\BranchRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Locale;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\ExpiryService;
use Momos\Reservation\Service\ModificationService;
use Momos\Reservation\Service\NoteService;
use Momos\Reservation\Service\Presenter;
use Momos\Reservation\Service\ReservationAccess;
use Momos\Reservation\Service\SlaClock;
use Momos\Reservation\Service\TransitionService;
use Momos\Reservation\Validation\Normalizer;

/**
 * Staff reservation routes (cookie + X-WP-Nonce; rate bucket staff_user; branch isolation M8):
 *   API-10 GET   /staff/reservations
 *   API-11 GET   /staff/reservations/{id}
 *   API-12 POST  /staff/reservations/{id}/transitions
 *   API-13 POST  /staff/reservations/{id}/notes
 *   API-16 PATCH /staff/reservations/{id}
 * Permission callbacks: 401 rest_forbidden (not logged in), 403 rest_forbidden (no capability),
 * 404 not_found (unknown id), 403 forbidden_branch (outside scope; audited access.denied, no data).
 */
final class StaffReservationController {
	public static function routes(): void {
		register_rest_route(
			ApiModule::NS,
			'/staff/reservations',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'index' ],
				'permission_callback' => static fn( \WP_REST_Request $r ) => self::guard( $r, 'momo_view_reservations', false ),
			]
		);
		register_rest_route(
			ApiModule::NS,
			'/staff/reservations/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'show' ],
					'permission_callback' => static fn( \WP_REST_Request $r ) => self::guard( $r, 'momo_view_reservations', true ),
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ self::class, 'modify' ],
					'permission_callback' => static fn( \WP_REST_Request $r ) => self::guard( $r, 'momo_process_reservations', true ),
				],
			]
		);
		register_rest_route(
			ApiModule::NS,
			'/staff/reservations/(?P<id>\d+)/transitions',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'transition' ],
				'permission_callback' => static fn( \WP_REST_Request $r ) => self::guard( $r, 'momo_process_reservations', true ),
			]
		);
		register_rest_route(
			ApiModule::NS,
			'/staff/reservations/(?P<id>\d+)/notes',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'note' ],
				'permission_callback' => static fn( \WP_REST_Request $r ) => self::guard( $r, 'momo_process_reservations', true ),
			]
		);
	}

	/** @return true|\WP_Error */
	public static function guard( \WP_REST_Request $request, string $cap, bool $object ) {
		$route   = $request->get_route();
		$user_id = get_current_user_id();
		try {
			if ( ! $user_id ) {
				throw new ReservationError( 'rest_forbidden', [], [ 'status_override' => 401 ] );
			}
			if ( ! user_can( $user_id, $cap ) ) {
				ReservationAccess::deny( $object ? (int) $request['id'] : null, null, 'capability', $route );
				throw new ReservationError( 'rest_forbidden' );
			}
			$rl = RateLimiter::hit( 'staff_user', 'u' . $user_id );
			if ( ! $rl['allowed'] ) {
				throw new ReservationError( 'rate_limited', [], [ 'retry_after' => $rl['retry_after'] ] );
			}
			if ( $object ) {
				$row = ReservationRepository::find( (int) $request['id'] );
				if ( null === $row ) {
					throw new ReservationError( 'not_found' );
				}
				if ( ! momos_user_can_branch( (int) $row['branch_id'], $user_id ) ) {
					ReservationAccess::deny( (int) $row['id'], (int) $row['branch_id'], 'forbidden_branch', $route );
					throw new ReservationError( 'forbidden_branch' );
				}
			}
			return true;
		} catch ( ReservationError $e ) {
			$err = ErrorResponder::wp_error( $e, 'en', true );
			if ( 401 === ( $e->data['status_override'] ?? 0 ) ) {
				$data           = (array) $err->get_error_data();
				$data['status'] = 401;
				$err            = new \WP_Error( $err->get_error_code(), $err->get_error_message(), $data );
			}
			return $err;
		}
	}

	private static function fail( ReservationError $e ): \WP_REST_Response {
		return ErrorResponder::response( $e, Locale::admin(), true );
	}

	// ------------------------------------------------------------------ API-10

	public static function index( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$q      = $request->get_query_params();
			$scope  = Scope::for_user( get_current_user_id() );
			$f      = self::filters( $q, $scope );
			$result = ReservationRepository::search( $scope, $f );
			$rows   = array_map( [ Presenter::class, 'list_row' ], $result['rows'] );
			$resp   = new \WP_REST_Response( $rows, 200 );
			$pages  = (int) ceil( $result['total'] / max( 1, (int) $f['per_page'] ) );
			$resp->header( 'X-WP-Total', (string) $result['total'] );
			$resp->header( 'X-WP-TotalPages', (string) $pages );
			if ( null !== $result['new_count'] ) {
				$resp->header( 'X-Momo-New-Count', (string) $result['new_count'] );
			}
			$resp->header( 'X-Momo-Status-Counts', (string) wp_json_encode( ReservationRepository::counts_by_status( $scope, $f['branch_ids'] ) ) );
			return $resp;
		} catch ( ReservationError $e ) {
			return self::fail( $e );
		}
	}

	/**
	 * Parse and validate API-10 query parameters into repository filters.
	 *
	 * @param array<string,mixed> $q
	 * @return array<string,mixed>
	 * @throws ReservationError
	 */
	public static function filters( array $q, Scope $scope ): array {
		$fields = [];
		$f      = [ 'branch_ids' => null, 'per_page' => 20, 'page' => 1, 'sort' => 'requested_start', 'order' => 'asc' ];
		$csv    = static fn( $v ): array => array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ), 'strlen' ) );

		if ( ! empty( $q['branch'] ) ) {
			$ids = [];
			foreach ( $csv( $q['branch'] ) as $code ) {
				$b = BranchRepository::find_by_code( strtoupper( $code ), true );
				if ( ! $b ) {
					$fields['branch'] = 'invalid_format';
					continue;
				}
				if ( ! $scope->allows( (int) $b['id'] ) ) {
					ReservationAccess::deny( null, (int) $b['id'], 'forbidden_branch', 'API-10' );
					throw new ReservationError( 'forbidden_branch' );
				}
				$ids[] = (int) $b['id'];
			}
			$f['branch_ids'] = $ids;
		}
		$statuses = isset( $q['status'] ) && '' !== $q['status'] ? $csv( $q['status'] ) : [ 'pending' ];
		if ( [ 'all' ] === $statuses ) {
			$statuses = ReservationStatus::values();
		}
		foreach ( $statuses as $s ) {
			if ( ! ReservationStatus::is_valid( $s ) ) {
				$fields['status'] = 'invalid_format';
			}
		}
		$f['statuses'] = $statuses;
		foreach ( [ 'date_from', 'date_to' ] as $k ) {
			if ( ! empty( $q[ $k ] ) ) {
				$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $q[ $k ] );
				if ( ! $d || $d->format( 'Y-m-d' ) !== $q[ $k ] ) {
					$fields[ $k ] = 'invalid_format';
				} else {
					$f[ $k ] = (string) $q[ $k ];
				}
			}
		}
		if ( isset( $q['q'] ) && '' !== trim( (string) $q['q'] ) ) {
			$term = Normalizer::nfkc( (string) $q['q'] );
			if ( mb_strlen( $term ) < 2 || mb_strlen( $term ) > 100 ) {
				$fields['q'] = 'out_of_range';
			}
			$f['q'] = $term;
		}
		$flags = isset( $q['flags'] ) ? $csv( $q['flags'] ) : [];
		foreach ( $flags as $flag ) {
			if ( ! in_array( $flag, [ 'needs_action', 'not_notified', 'overdue', 'unclaimed' ], true ) ) {
				$fields['flags'] = 'invalid_format';
			}
		}
		$f['flags'] = array_values( array_diff( $flags, [ 'overdue' ] ) );
		if ( in_array( 'overdue', $flags, true ) ) {
			$ids = [];
			foreach ( ReservationRepository::pending_light( $scope ) as $r ) {
				if ( 'overdue' === SlaClock::state( $r + [ 'status' => 'pending' ] ) ) {
					$ids[] = $r['id'];
				}
			}
			$f['ids'] = $ids;
		}
		if ( isset( $q['has_special_request'] ) && '' !== $q['has_special_request'] ) {
			$f['has_special_request'] = in_array( strtolower( (string) $q['has_special_request'] ), [ '1', 'true' ], true );
		}
		if ( isset( $q['handled_by'] ) && '' !== $q['handled_by'] ) {
			if ( ! in_array( $q['handled_by'], [ 'me', 'none' ], true ) ) {
				$fields['handled_by'] = 'invalid_format';
			}
			$f['handled_by'] = (string) $q['handled_by'];
		}
		if ( ! empty( $q['since'] ) ) {
			try {
				$f['since'] = Clock::mysql_utc( new \DateTimeImmutable( (string) $q['since'] ) );
			} catch ( \Exception ) {
				$fields['since'] = 'invalid_format';
			}
		}
		if ( isset( $q['sort'] ) ) {
			if ( ! isset( ReservationRepository::SORTS[ $q['sort'] ] ) ) {
				$fields['sort'] = 'invalid_format';
			}
			$f['sort'] = (string) $q['sort'];
		}
		if ( isset( $q['order'] ) ) {
			if ( ! in_array( $q['order'], [ 'asc', 'desc' ], true ) ) {
				$fields['order'] = 'invalid_format';
			}
			$f['order'] = (string) $q['order'];
		}
		foreach ( [ 'page' => [ 1, 100000 ], 'per_page' => [ 1, 50 ] ] as $k => [ $min, $max ] ) {
			if ( isset( $q[ $k ] ) ) {
				if ( ! ctype_digit( (string) $q[ $k ] ) || (int) $q[ $k ] < $min || (int) $q[ $k ] > $max ) {
					$fields[ $k ] = 'out_of_range';
				} else {
					$f[ $k ] = (int) $q[ $k ];
				}
			}
		}
		if ( $fields ) {
			throw ReservationError::invalid( $fields );
		}
		return $f;
	}

	// ------------------------------------------------------------------ API-11

	public static function show( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		ExpiryService::expire_if_due( $id );
		$row = ReservationRepository::find( $id );
		if ( null === $row ) {
			return self::fail( new ReservationError( 'not_found' ) );
		}
		self::audit_view( $row );
		return new \WP_REST_Response( Presenter::detail( $row, get_current_user_id() ), 200 );
	}

	/** reservation.detail_viewed, de-duplicated per user per reservation per 30 min. @param array<string,mixed> $row */
	public static function audit_view( array $row ): void {
		$key = 'momos_dv_' . md5( get_current_user_id() . '|' . $row['id'] );
		if ( false === get_transient( $key ) ) {
			set_transient( $key, 1, 30 * MINUTE_IN_SECONDS );
			momos_audit( 'reservation.detail_viewed', 'reservation', (int) $row['id'], (int) $row['branch_id'], [] );
		}
	}

	// ------------------------------------------------------------------ API-12

	public static function transition( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$body   = (array) $request->get_json_params();
			$errors = SchemaValidator::validate(
				$body,
				[
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'action', 'version' ],
					'properties'           => [
						'action'              => [ 'type' => 'string', 'enum' => [ 'accept', 'decline', 'cancel', 'mark_completed', 'mark_no_show' ] ],
						'version'             => [ 'type' => 'integer', 'minimum' => 1 ],
						'reason_code'         => [ 'type' => [ 'string', 'null' ], 'maxLength' => 40 ],
						'message_to_customer' => [ 'type' => [ 'string', 'null' ], 'maxLength' => 2000 ],
						'suggested_times'     => [ 'type' => [ 'array', 'null' ], 'maxItems' => 2 ],
						'note'                => [ 'type' => [ 'string', 'null' ], 'maxLength' => 4000 ],
					],
				]
			);
			if ( $errors ) {
				throw ReservationError::invalid( $errors );
			}
			$result = TransitionService::staff( (int) $request['id'], (string) $body['action'], $body, Actor::current_staff() );
			$row    = $result['row'];
			$data   = [
				'id'                   => (int) $row['id'],
				'status'               => (string) $row['status'],
				'version'              => (int) $row['version'],
				'handled_by'           => Presenter::list_row( $row )['handled_by'],
				'notifications_queued' => $result['notifications_queued'],
			];
			foreach ( [ 'confirmed_at', 'rejected_at', 'cancelled_at', 'attended_at' ] as $k ) {
				if ( ! empty( $row[ $k ] ) ) {
					$data[ $k ] = Presenter::iso_jst( (string) $row[ $k ] );
				}
			}
			return new \WP_REST_Response( $data, 200 );
		} catch ( ReservationError $e ) {
			return self::fail( $e );
		}
	}

	// ------------------------------------------------------------------ API-13

	public static function note( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$body   = (array) $request->get_json_params();
			$errors = SchemaValidator::validate(
				$body,
				[
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => [ 'type' ],
					'properties'           => [
						'type'    => [ 'type' => 'string', 'enum' => NoteService::TYPES ],
						'text'    => [ 'type' => [ 'string', 'null' ], 'maxLength' => 4000 ],
						'version' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ],
					],
				]
			);
			if ( $errors ) {
				throw ReservationError::invalid( $errors );
			}
			return new \WP_REST_Response( NoteService::apply( (int) $request['id'], $body, Actor::current_staff() ), 201 );
		} catch ( ReservationError $e ) {
			return self::fail( $e );
		}
	}

	// ------------------------------------------------------------------ API-16

	public static function modify( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$body = (array) $request->get_json_params();
			if ( array_key_exists( 'override_rules', $body ) && ! is_bool( $body['override_rules'] ) ) {
				throw ReservationError::invalid( [ 'override_rules' => 'invalid_format' ] );
			}
			$result = ModificationService::modify( (int) $request['id'], $body, Actor::current_staff() );
			$data   = Presenter::detail( $result['row'], get_current_user_id() );
			$data['notifications_queued'] = $result['notifications_queued'];
			return new \WP_REST_Response( $data, 200 );
		} catch ( ReservationError $e ) {
			return self::fail( $e );
		}
	}
}
