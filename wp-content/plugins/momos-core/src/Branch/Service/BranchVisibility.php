<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Domain\OperatingStatus;
use Momos\Branch\Repository\BranchRepository;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

/**
 * Public visibility of branches and offers (FR-RES-026/030 TEST branch, BR-BR-04 permanent
 * closure, BR-I18N-02, FR-CORP-010 "no offer URLs", spec §3.1/§4.9/§9):
 *  - front-end queries never return TEST or closed_permanently branches;
 *  - /branches/{closed}/ → 301 to /{lang}/branches/; /branches/{test}/ → 404 unless assigned staff (noindex);
 *  - core REST wp/v2/momo_branch is GET-only, public fields only, without TEST/closed branches;
 *  - offers/notices have no single URLs (404).
 */
final class BranchVisibility {
	/** Meta exposed read-only in core REST as `momos` (language-independent public facts; never email, recipients or notes). */
	public const REST_META = [ 'momo_branch_code', 'momo_operating_status', 'momo_reopen_date', 'momo_postal_code', 'momo_address_ja', 'momo_lat', 'momo_lng', 'momo_phone_e164', 'momo_phone_display', 'momo_prayer_space', 'momo_smoking_policy', 'momo_step_free_access', 'momo_wifi' ];

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
		add_action( 'pre_get_posts', [ self::class, 'pre_get_posts' ] );
		add_action( 'template_redirect', [ self::class, 'template_redirect' ], 5 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'rest_pre_dispatch' ], 10, 3 );
		add_filter( 'rest_momo_branch_query', [ self::class, 'rest_query' ], 10, 2 );
		add_filter( 'rest_prepare_momo_branch', [ self::class, 'rest_prepare' ], 10, 3 );
		add_filter( 'oembed_request_post_id', [ self::class, 'oembed_post_id' ], 10, 1 );
	}

	/**
	 * oEmbed never describes a TEST, permanently closed or unpublished branch (also when the URL
	 * is `?p=ID`, which core resolves without a query) — QA-009, FR-RES-026.
	 *
	 * @param int|false $post_id
	 * @return int|false
	 */
	public static function oembed_post_id( $post_id ) {
		$id = (int) $post_id;
		if ( $id > 0 && 'momo_branch' === get_post_type( $id ) && ! self::is_public( $id, Lang::SOURCE ) ) {
			return 0;
		}
		return $post_id;
	}

	public static function is_public( int $branch_id, ?string $lang = null ): bool {
		return BranchRepository::is_public( $branch_id, $lang );
	}

	/** register_post_meta with auth callbacks (field write guard) and REST exposure of public facts. */
	public static function register_meta(): void {
		$keys = array_merge(
			\Momos\Branch\Fields\BranchFields::IDENTITY_KEYS,
			[ 'momo_branch_email', 'momo_is_test_branch', 'momo_facts_confirmed_by', 'momo_facts_confirmed_at', 'momo_facts_evidence_note', 'momo_external_ref', 'momo_first_published_at', 'momo_access_notes', 'momo_prayer_space', 'momo_prayer_note', 'momo_smoking_policy', 'momo_step_free_access', 'momo_wifi', 'momo_map_preview_image' ],
			array_keys( \Momos\Branch\Repository\BranchSettingsRepository::DEFAULTS )
		);
		foreach ( array_unique( $keys ) as $key ) {
			$settings = str_starts_with( $key, 'momo_res_' );
			$cap      = $settings ? 'momo_edit_branch_settings' : ( in_array( $key, [ 'momo_is_test_branch', 'momo_external_ref' ], true ) ? 'manage_options' : 'momo_edit_branch_identity' );
			register_post_meta(
				'momo_branch',
				$key,
				[
					'single'        => true,
					'show_in_rest'  => false, // Public facts are exposed read-only as `momos` in rest_prepare().

					'auth_callback' => static function ( $allowed, $meta_key, $post_id, $user_id ) use ( $cap ): bool {
						return user_can( $user_id, $cap ) && momos_user_can_branch( (int) $post_id, (int) $user_id );
					},
				]
			);
		}
	}

	/** Front-end queries: never TEST or permanently closed branches; never offers/notices singles. */
	public static function pre_get_posts( \WP_Query $q ): void {
		// get_posts() (suppress_filters) is used by the module's own repositories, which apply
		// their own rules. REST requests are filtered too: core wp/v2/search and the oEmbed URL
		// lookup query branches through WP_Query (QA-009, spec §4.9).
		if ( is_admin() || wp_doing_cron() || $q->get( 'suppress_filters' ) ) {
			return;
		}
		$pt = (array) $q->get( 'post_type' );
		if ( in_array( 'momo_branch', $pt, true ) || ( $q->is_main_query() && 'momo_branch' === $q->get( 'post_type' ) ) ) {
			if ( $q->is_main_query() && $q->is_singular() ) {
				return; // template_redirect decides (301 / 404 / staff preview).
			}
			$mq   = (array) $q->get( 'meta_query' );
			$mq[] = self::public_meta_query();
			$q->set( 'meta_query', $mq );
		}
	}

	/** @return array<int|string,mixed> */
	public static function public_meta_query(): array {
		return [
			'relation' => 'AND',
			[
				'relation' => 'OR',
				[ 'key' => 'momo_is_test_branch', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'momo_is_test_branch', 'value' => '1', 'compare' => '!=' ],
			],
			[
				'relation' => 'OR',
				[ 'key' => 'momo_operating_status', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'momo_operating_status', 'value' => OperatingStatus::ClosedPermanently->value, 'compare' => '!=' ],
			],
		];
	}

	public static function template_redirect(): void {
		if ( isset( $_GET['post_type'] ) && in_array( $_GET['post_type'], [ 'momo_offer', 'momo_notice' ], true ) ) { // phpcs:ignore -- read-only routing check.
			self::not_found();
			return;
		}
		if ( ! is_singular( 'momo_branch' ) ) {
			return;
		}
		$id   = (int) get_queried_object_id();
		$lang = class_exists( Locale::class ) ? Locale::current() : 'ja';
		if ( OperatingStatus::ClosedPermanently->value === BranchRepository::operating_status( $id ) ) {
			wp_safe_redirect( Url::to( '/branches/', $lang ), 301 );
			exit;
		}
		if ( BranchRepository::is_test( $id ) ) {
			if ( ! BranchAccess::can_view( $id ) ) {
				self::not_found();
				return;
			}
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			return;
		}
		// Untranslated in the current language → 404 (screens §3.5, BR-I18N-02, DEC-027);
		// editors keep a noindex preview like other singular content.
		if ( ! Translations::post_visible( $id, $lang ) ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				self::not_found();
				return;
			}
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
	}

	private static function not_found(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Core REST for branches is GET-only (405), single TEST/closed → 404.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function rest_pre_dispatch( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		$route = (string) $request->get_route();
		if ( ! preg_match( '#^/wp/v2/momo_branch(?:/(\d+))?#i', $route, $m ) ) {
			return $result;
		}
		if ( ! in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
			return new \WP_Error( 'method_not_allowed', __( 'Branches are read-only through this API.', 'momos-core' ), [ 'status' => 405 ] );
		}
		if ( ! empty( $m[1] ) ) {
			$id = (int) $m[1];
			if ( BranchRepository::is_test( $id ) || OperatingStatus::ClosedPermanently->value === BranchRepository::operating_status( $id ) ) {
				return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'momos-core' ), [ 'status' => 404 ] );
			}
		}
		return $result;
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public static function rest_query( array $args, \WP_REST_Request $request ): array {
		$mq                 = (array) ( $args['meta_query'] ?? [] );
		$mq[]               = self::public_meta_query();
		$args['meta_query'] = $mq; // phpcs:ignore
		return $args;
	}

	/** Strip non-public data from REST responses (content raw, author, internal meta). */
	public static function rest_prepare( \WP_REST_Response $response, \WP_Post $post, \WP_REST_Request $request ): \WP_REST_Response {
		$data = $response->get_data();
		unset( $data['author'], $data['guid'], $data['template'], $data['password'] );
		unset( $data['meta'] );
		$facts = [];
		foreach ( self::REST_META as $key ) {
			$v = get_post_meta( $post->ID, $key, true );
			if ( '' !== $v && 'unknown' !== $v ) {
				$facts[ $key ] = $v;
			}
		}
		$data['momos'] = $facts;
		$response->set_data( $data );
		return $response;
	}
}
