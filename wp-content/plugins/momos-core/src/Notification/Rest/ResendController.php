<?php
declare(strict_types=1);

namespace Momos\Notification\Rest;

use Momos\Notification\Service\ResendService;

/**
 * `POST /momos/v1/staff/reservations/{id}/notifications/{notification_id}/resend` (spec §8.3,
 * proposal — to be added to the TRD API list). Header `X-WP-Nonce` (cookie auth). Response 201
 * `{notification_id, status:"queued"}`. Errors from the canonical catalogue:
 * 401/403 rest_forbidden, 403 forbidden_branch, 404 not_found, 409 invalid_transition, 429 rate_limited.
 */
final class ResendController {
	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route(
			'momos/v1',
			'/staff/reservations/(?P<id>\d+)/notifications/(?P<notification_id>\d+)/resend',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'resend' ],
				'permission_callback' => [ self::class, 'permission' ],
				'args'                => [
					'id'              => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
					'notification_id' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1 ],
				],
			]
		);
	}

	public static function permission(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Please log in.', 'momos-core' ), [ 'status' => 401 ] );
		}
		// CHG-010: resending always exposes the reservation → view primitive + (process | log access).
		// Branch scope of the reservation is checked per object in ResendService::user_can().
		if ( ! current_user_can( 'momo_view_reservations' ) || ( ! current_user_can( 'momo_process_reservations' ) && ! current_user_can( 'momo_view_notification_log' ) ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You are not allowed to do this.', 'momos-core' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function resend( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$out = ResendService::resend( (int) $req['notification_id'], get_current_user_id(), (int) $req['id'] );
		if ( $out['ok'] ) {
			return new \WP_REST_Response( [ 'notification_id' => $out['id'], 'status' => 'queued' ], 201 );
		}
		$map                  = [
			'forbidden_branch'   => [ 403, __( 'You do not have access to this branch.', 'momos-core' ) ],
			'not_found'          => [ 404, __( 'Not found.', 'momos-core' ) ],
			'invalid_transition' => [ 409, __( 'This email cannot be resent.', 'momos-core' ) ],
			'rate_limited'       => [ 429, __( 'Too many requests. Please try again later.', 'momos-core' ) ],
		];
		[ $status, $message ] = $map[ $out['code'] ] ?? [ 409, __( 'This email cannot be resent.', 'momos-core' ) ];
		return new \WP_Error( (string) $out['code'], $message, [ 'status' => $status ] );
	}
}
