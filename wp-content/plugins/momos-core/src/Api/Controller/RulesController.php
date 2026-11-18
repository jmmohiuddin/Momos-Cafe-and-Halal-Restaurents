<?php
declare(strict_types=1);

namespace Momos\Api\Controller;

use Momos\Api\ApiModule;
use Momos\Api\Http\ErrorResponder;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Service\Presenter;
use Momos\Reservation\Service\SlotGenerator;
use Momos\Reservation\Service\SubmissionService;

/**
 * API-01 `GET /momos/v1/branches/{code}/reservation-rules?lang=` (FR-RES-003, spec §5.2): public,
 * cacheable for 5 min; computed slots per date in JST. No staff or customer data.
 */
final class RulesController {
	public static function routes(): void {
		register_rest_route(
			ApiModule::NS,
			'/branches/(?P<code>[A-Z]{3})/reservation-rules',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get' ],
				'permission_callback' => '__return_true', // Public route (ROLE-00); no personal data.
				'args'                => [
					'lang' => [ 'type' => 'string', 'enum' => Lang::codes(), 'required' => false ],
				],
			]
		);
	}

	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		$lang = Lang::is_valid( (string) $request['lang'] ) ? (string) $request['lang'] : Lang::SOURCE;
		Locale::set( $lang );
		$branch = SubmissionService::resolve_branch( (string) $request['code'], get_current_user_id() );
		if ( null === $branch ) {
			return ErrorResponder::response( new ReservationError( 'branch_not_found' ), $lang, false );
		}
		$id       = (int) $branch['id'];
		$settings = BranchSettingsRepository::get( $id );
		$acc      = SlotGenerator::accepting( $branch, $settings );
		$now      = Clock::utc();
		$body     = [
			'branch_code'          => (string) $branch['code'],
			'branch_name'          => Presenter::branch_name( $id, $lang ),
			'accepting'            => $acc['accepting'],
			'not_accepting_reason' => $acc['reason'],
			'reopen_date'          => 'temporarily_closed' === $acc['reason'] ? $branch['reopen_date'] : null,
			'is_test'              => (bool) $branch['is_test'],
			'timezone'             => 'Asia/Tokyo',
			'rules'                => [
				'party_min'                => (int) $settings['party_min'],
				'party_max'                => (int) $settings['party_max'],
				'lead_time_minutes'        => (int) $settings['lead_time_minutes'],
				'booking_window_days'      => (int) $settings['booking_window_days'],
				'slot_interval_minutes'    => (int) $settings['slot_interval_minutes'],
				'response_promise_minutes' => $settings['show_response_promise'] && $settings['response_sla_minutes'] > 0 ? (int) $settings['response_sla_minutes'] : null,
			],
			'contact'              => [ 'phone_e164' => (string) $branch['phone_e164'], 'phone_display' => (string) $branch['phone_display'] ],
			'days'                 => $acc['accepting'] ? SlotGenerator::days( $id, $settings, $now, $lang ) : [],
			'generated_at'         => $now->setTimezone( Clock::tz() )->format( 'c' ),
		];
		$response = new \WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', $branch['is_test'] ? 'no-store, private' : 'public, max-age=300, s-maxage=300' );
		return $response;
	}
}
