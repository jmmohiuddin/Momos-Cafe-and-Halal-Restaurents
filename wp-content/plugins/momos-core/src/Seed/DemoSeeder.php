<?php
declare(strict_types=1);

namespace Momos\Seed;

use Momos\Branch\Admin\HoursFormHandler;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Dietary\Allergens;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Clock\FrozenClock;
use Momos\Kernel\Env;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Service\BranchMenuService;
use Momos\Menu\Support\MenuItemException;
use Momos\Presentation\SystemPages;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\ExpiryService;
use Momos\Reservation\Service\SlotGenerator;
use Momos\Reservation\Service\SubmissionService;
use Momos\Reservation\Service\TransitionService;
use Momos\Seed\Data\Branches;
use Momos\Seed\Data\Content;
use Momos\Seed\Data\Menu;
use Momos\Seed\Data\People;

/**
 * `wp momos seed --demo` (NFR-MNT-02, DEC-047, content-strings.md §10, qa.md §3): realistic, clearly
 * FICTIONAL demo content created through the module services as capability-holding demo users.
 * Idempotent: objects are found again by `_momo_seed_key`, unchanged values are not re-written,
 * reservations reuse their fixed idempotency keys. Refuses to run in production (D9).
 *
 * Original values of pre-existing objects the seed edits (system/legal pages, brand and notification
 * settings) are snapshotted once in the option `momos_seed_manifest` and restored by `--reset`.
 */
final class DemoSeeder {
	public const MANIFEST = 'momos_seed_manifest';

	/** @var array<string,int> login => user id */
	private array $users = [];

	/** @var array<string,int> branch code => post id */
	private array $branches = [];

	/** @var array<string,int> */
	private array $cats = [];

	/** @var array<string,int> */
	private array $ingredients = [];

	/** @var array<string,int> */
	private array $tags = [];

	/** @var array<string,int> sku => post id */
	private array $dishes = [];

	private int $admin = 0;

	private Media $media;

	/** @var array<string,int> */
	private array $counts = [];

	/** @var callable(string):void */
	private $out;

	/** @param callable(string):void|null $out progress printer */
	public function __construct( ?string $photo_dir = null, bool $photos = true, ?callable $out = null ) {
		$this->media = new Media( $photo_dir, $photos );
		$this->out   = $out ?? static function ( string $m ): void {};
	}

	/** Refused when MOMOS_ENV (Env) or an explicitly configured WP_ENVIRONMENT_TYPE says production (D9). */
	public static function assert_allowed(): void {
		$wp_env = defined( 'WP_ENVIRONMENT_TYPE' ) ? (string) WP_ENVIRONMENT_TYPE : (string) getenv( 'WP_ENVIRONMENT_TYPE' );
		if ( Env::is_production() || 'production' === $wp_env ) {
			throw new SeedException( 'Refusing to seed demo content in production (DEC-047, NFR-MNT-02).' );
		}
	}

	/** @return array<string,int|string> summary counts */
	public function run(): array {
		self::assert_allowed();
		$previous_user = get_current_user_id();
		$clock         = Clock::get();
		try {
			$this->admin = self::admin_id();
			wp_set_current_user( $this->admin );
			$this->step( 'users', fn() => $this->users() );
			wp_set_current_user( $this->users['demo_ho'] );
			$this->step( 'taxonomies', fn() => $this->taxonomies() );
			$this->step( 'allergen names', fn() => $this->allergen_names() );
			$this->step( 'branches', fn() => $this->branches() );
			$this->step( 'assignments', fn() => $this->assignments() );
			$this->step( 'dishes', fn() => $this->dishes() );
			$this->step( 'branch menus', fn() => $this->branch_menus() );
			$this->step( 'notices', fn() => $this->notices() );
			$this->step( 'offers', fn() => $this->offers() );
			$this->step( 'pages', fn() => $this->pages() );
			$this->step( 'legal pages', fn() => $this->legal_pages() );
			$this->step( 'settings', fn() => $this->settings() );
			$this->step( 'reservations', fn() => $this->reservations() );
		} finally {
			Clock::set( $clock );
			wp_set_current_user( $previous_user );
		}
		\Momos\Branch\Service\ScheduleService::flush();
		if ( class_exists( \Momos\Kernel\Cache\CacheInvalidator::class ) ) {
			\Momos\Kernel\Cache\CacheInvalidator::purge_all();
		}
		wp_cache_flush();
		flush_rewrite_rules( false );
		return $this->counts + [ 'images' => $this->media->source() . ' (imported ' . $this->media->imported . ', generated ' . $this->media->generated . ')' ];
	}

	private function step( string $name, callable $fn ): void {
		( $this->out )( 'Seeding ' . $name . '…' );
		$fn();
	}

	public static function admin_id(): int {
		$ids = get_users( [ 'role' => 'administrator', 'fields' => 'ID', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ] );
		if ( ! $ids ) {
			throw new SeedException( 'An administrator account is required to seed demo content.' );
		}
		return (int) $ids[0];
	}

	// ================================================================== users

	private function users(): void {
		foreach ( People::users() as $login => $u ) {
			$user = get_user_by( 'login', $login );
			if ( $user && '' === (string) get_user_meta( $user->ID, Writer::KEY_META, true ) ) {
				throw new SeedException( "User {$login} exists and is not a seeded demo account; refusing to change it." );
			}
			if ( ! $user ) {
				$id = wp_insert_user(
					[
						'user_login'   => $login,
						'user_pass'    => People::DEV_PASSWORD,
						'user_email'   => str_replace( '_', '.', $login ) . '@example.com',
						'display_name' => $u['name'],
						'nickname'     => $u['name'],
						'role'         => $u['role'],
						'locale'       => $u['locale'],
					]
				);
				if ( is_wp_error( $id ) ) {
					throw new SeedException( "Could not create user {$login}: " . $id->get_error_message() );
				}
				update_user_meta( (int) $id, Writer::KEY_META, 'user:' . $login );
				update_user_meta( (int) $id, Writer::SAMPLE_META, '1' );
				$user = get_user_by( 'id', (int) $id );
			}
			$this->count( 'users' );
			if ( ! in_array( $u['role'], (array) $user->roles, true ) ) {
				$user->set_role( $u['role'] );
			}
			if ( ! wp_check_password( People::DEV_PASSWORD, $user->user_pass, $user->ID ) ) {
				wp_set_password( People::DEV_PASSWORD, $user->ID );
			}
			if ( isset( $u['langs'] ) && get_user_meta( $user->ID, 'momo_translator_langs', true ) !== $u['langs'] ) {
				update_user_meta( $user->ID, 'momo_translator_langs', $u['langs'] );
			}
			$this->users[ $login ] = (int) $user->ID;
		}
		// CAP-DCA for the demo Head Office user (granted by the administrator, audited by Access).
		foreach ( People::users() as $login => $u ) {
			if ( ! empty( $u['dca'] ) ) {
				\Momos\Access\Users\AccountService::set_dca( $this->users[ $login ], true, $this->admin );
			}
		}
	}

	private function assignments(): void {
		foreach ( People::users() as $login => $u ) {
			if ( $u['branches'] ) {
				$ids = array_map( fn( string $code ): int => $this->branches[ $code ], $u['branches'] );
				\Momos\Access\Users\AssignmentService::set( $this->users[ $login ], $ids, $this->users['demo_ho'] );
			}
		}
	}

	// ================================================================== taxonomies

	private function taxonomies(): void {
		foreach ( Menu::ingredients() as $key => $ing ) {
			$id = $this->term( ContentTypes::INGREDIENT, 'ingredient:' . $key, $ing['name']['ja'], $key, '' );
			Writer::save_fields( 'term', $id, [ 'momo_ing_class' => $ing['class'] ] );
			$this->translate_term( $id, [ 'name' => $ing['name'] ] );
			$this->ingredients[ $key ] = $id;
			$this->count( 'ingredients' );
		}
		foreach ( Menu::tags() as $key => $tag ) {
			$id = $this->term( ContentTypes::TAG, 'tag:' . $key, $tag['name']['ja'], $key, $tag['description']['ja'] );
			Writer::save_fields( 'term', $id, [ 'momo_tag_code' => $key ] );
			$this->translate_term( $id, [ 'name' => $tag['name'], 'description' => $tag['description'] ] );
			$this->tags[ $key ] = $id;
			$this->count( 'dietary_tags' );
		}
		foreach ( Menu::categories() as $slug => $cat ) {
			$id    = $this->term( ContentTypes::CATEGORY, 'category:' . $slug, $cat['name']['ja'], $slug, $cat['intro']['ja'] );
			$image = $this->media->image( $cat['image'], $this->dish_names( $cat['image'] ) ?? $cat['name'] );
			Writer::save_fields( 'term', $id, [ 'momo_cat_order' => (string) $cat['order'], 'momo_cat_image' => (string) $image ] );
			$this->translate_term( $id, [ 'name' => $cat['name'], 'description' => $cat['intro'] ] );
			$this->cats[ $slug ] = $id;
			$this->count( 'categories' );
		}
	}

	/** Draft allergen names for languages the reference data does not cover yet (recorded for --reset). */
	private function allergen_names(): void {
		$m     = self::manifest();
		$added = (array) ( $m['term_i18n'] ?? [] );
		foreach ( Menu::allergen_names() as $code => $names ) {
			$term = Allergens::term_by_code( $code );
			if ( ! $term ) {
				continue;
			}
			foreach ( $names as $lang => $name ) {
				$ours = in_array( $lang, (array) ( $added[ $term->term_id ] ?? [] ), true );
				if ( ! $ours && 'missing' !== Translations::status( 'term', $term->term_id, $lang ) ) {
					continue; // A real (or in-progress) translation exists: never overwrite it.
				}
				Writer::translate( 'term', $term->term_id, $lang, [ 'name' => $name ], $this->users['demo_tr'], $this->users['demo_ho'] );
				if ( ! $ours ) {
					$added[ $term->term_id ][] = $lang;
				}
			}
		}
		$m              = self::manifest();
		$m['term_i18n'] = $added;
		self::save_manifest( $m );
	}

	private function term( string $taxonomy, string $key, string $name, string $slug, string $description ): int {
		$id = Writer::find_term( $taxonomy, $key );
		if ( ! $id ) {
			$res = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug, 'description' => $description ] );
			if ( is_wp_error( $res ) ) {
				throw new SeedException( "Could not create {$taxonomy} term {$slug}: " . $res->get_error_message() );
			}
			$id = (int) $res['term_id'];
			update_term_meta( $id, Writer::KEY_META, $key );
			update_term_meta( $id, Writer::SAMPLE_META, '1' ); // Categories carry the sample flag as term meta (content-strings.md C12).
			return $id;
		}
		$term = get_term( $id, $taxonomy );
		if ( $term instanceof \WP_Term && ( $term->name !== $name || $term->description !== $description ) ) {
			wp_update_term( $id, $taxonomy, [ 'name' => $name, 'description' => $description ] );
		}
		return $id;
	}

	/** @param array<string, array<string,string>> $fields field => lang => text */
	private function translate_term( int $id, array $fields ): void {
		foreach ( Lang::targets() as $lang ) {
			$values = [];
			foreach ( $fields as $f => $texts ) {
				$values[ $f ] = $texts[ $lang ] ?? '';
			}
			Writer::translate( 'term', $id, $lang, $values, $this->users['demo_tr'], $this->users['demo_ho'] );
		}
	}

	/** @param array<string, array<string,string>> $fields */
	private function translate_post( int $id, array $fields ): void {
		foreach ( Lang::targets() as $lang ) {
			$values = [];
			foreach ( $fields as $f => $texts ) {
				$values[ $f ] = $texts[ $lang ] ?? '';
			}
			Writer::translate( 'post', $id, $lang, $values, $this->users['demo_tr'], $this->users['demo_ho'] );
		}
	}

	/** @return array<string,string>|null dish name per language for a photo slug */
	private function dish_names( string $image ): ?array {
		foreach ( Menu::dishes() as $d ) {
			if ( $d['image'] === $image ) {
				return $d['name'];
			}
		}
		return null;
	}

	// ================================================================== branches

	private function branches(): void {
		$today = Clock::jst()->format( 'Y-m-d' );
		foreach ( Branches::all() as $b ) {
			$id                           = Writer::upsert_post(
				'momo_branch',
				'branch:' . $b['code'],
				[ 'post_title' => $b['title']['ja'], 'post_content' => '<p>' . $b['content']['ja'] . '</p>', 'post_name' => $b['slug'], 'menu_order' => $b['order'] ]
			);
			$this->branches[ $b['code'] ] = $id;
			$confirmed_at                 = (string) get_post_meta( $id, 'momo_facts_confirmed_at', true );
			Writer::save_fields(
				'post',
				$id,
				[
					'momo_branch_code'         => $b['code'],
					'momo_concept_label'       => $b['concept']['ja'],
					'momo_operating_status'    => 'open',
					'momo_area_label'          => $b['area']['ja'],
					'momo_postal_code'         => $b['postal'],
					'momo_prefecture'          => $b['pref']['ja'],
					'momo_city'                => $b['city']['ja'],
					'momo_address_line1'       => $b['line1']['ja'],
					'momo_address_line2'       => $b['line2']['ja'],
					'momo_address_ja'          => $b['address_ja'],
					'momo_lat'                 => $b['lat'],
					'momo_lng'                 => $b['lng'],
					'momo_phone_e164'          => $b['phone'],
					'momo_phone_display'       => $b['phone_display'],
					'momo_branch_email'        => $b['email'],
					'momo_facts_confirmed_by'  => 'Demo data (fictional, DEC-047)',
					'momo_facts_confirmed_at'  => '' !== $confirmed_at ? $confirmed_at : $today,
					'momo_facts_evidence_note' => 'Sample content created by wp momos seed --demo. Not real branch facts.',
				]
			);
			Writer::save_fields(
				'post',
				$id,
				[
					'momo_access_directions' => array_map( static fn( $d ) => [ 'station' => $d['station']['ja'], 'exit' => $d['exit']['ja'], 'walking_minutes' => (string) $d['walk'] ], $b['directions'] ),
					'momo_access_notes'      => $b['access_notes']['ja'],
					'momo_prayer_space'      => $b['prayer'],
					'momo_prayer_note'       => $b['prayer_note']['ja'],
					'momo_smoking_policy'    => $b['smoking'],
					'momo_step_free_access'  => $b['step_free'],
					'momo_seating_types'     => $b['seating'],
					'momo_payment_methods'   => $b['payment'],
					'momo_wifi'              => $b['wifi'],
				]
			);
			if ( $b['test'] ) {
				// `momo_is_test_branch` is editable by the Super Administrator only (manage_options).
				wp_set_current_user( $this->admin );
				try {
					Writer::save_fields( 'post', $id, [ 'momo_is_test_branch' => '1' ] );
				} finally {
					wp_set_current_user( $this->users['demo_ho'] );
				}
			}
			$this->weekly_hours( $id, $b['hours'] );
			Writer::publish( $id );
			Writer::save_fields(
				'post',
				$id,
				$b['settings'] + [
					'momo_res_notification_recipients' => $b['recipients'],
					'momo_res_enabled'                 => '1',
				]
			);
			// G7 dietary: no claim on every demo branch — nothing halal-related is approved (GATE-02).
			Writer::save_fields( 'post', $id, [ 'momo_halal_claim_type' => 'no_claim', 'momo_alcohol_served' => $b['alcohol'], 'momo_alcohol_in_cooking' => 'unknown' ] );
			$this->closures( $id, $b['closures'] );

			$tr = [
				'title'              => $b['title'],
				'content'            => array_map( static fn( string $t ) => '<p>' . $t . '</p>', $b['content'] ),
				'momo_concept_label' => $b['concept'],
				'momo_area_label'    => $b['area'],
				'momo_prefecture'    => $b['pref'],
				'momo_city'          => $b['city'],
				'momo_address_line1' => $b['line1'],
				'momo_address_line2' => $b['line2'],
				'momo_access_notes'  => $b['access_notes'],
				'momo_prayer_note'   => $b['prayer_note'],
			];
			foreach ( $b['directions'] as $i => $d ) {
				$tr[ 'access_station_' . ( $i + 1 ) ] = $d['station'];
				$tr[ 'access_exit_' . ( $i + 1 ) ]    = $d['exit'];
			}
			$this->translate_post( $id, $tr );
			$this->count( 'branches' );
		}
		\Momos\Branch\Service\ScheduleService::flush();
	}

	/** @param array<int, list<array{0:string,1:string,2:string,3:string}>> $hours */
	private function weekly_hours( int $branch_id, array $hours ): void {
		$want = [];
		$in   = [ 'set' => 'default', 'periods' => [], 'closed' => [] ];
		for ( $w = 1; $w <= 7; $w++ ) {
			$rows = $hours[ $w ] ?? [];
			if ( ! $rows ) {
				$in['closed'][ $w ] = '1';
			}
			foreach ( $rows as $p ) {
				$in['periods'][ $w ][] = [ 'label' => $p[0], 'opens' => $p[1], 'closes' => $p[2], 'last_order' => $p[3] ];
				$want[ $w ][]          = $p[1] . '-' . $p[2] . ' LO ' . $p[3] . ' (' . $p[0] . ')';
			}
		}
		if ( HoursRepository::compact( HoursRepository::rows_for_set( $branch_id, null, null ) ) === $want ) {
			return;
		}
		$res = HoursFormHandler::process_hours( $branch_id, $in, get_current_user_id() );
		if ( 'impact' === $res['status'] ) {
			$res = HoursFormHandler::process_hours( $branch_id, $in + [ 'confirm' => '1' ], get_current_user_id() );
		}
		if ( 'saved' !== $res['status'] ) {
			throw new SeedException( 'Weekly hours refused for branch ' . $branch_id . ': ' . implode( ' | ', (array) ( $res['errors'] ?? [] ) ) );
		}
	}

	/** One seeded future closure per entry; kept on re-runs while it is still upcoming. @param list<array<string,mixed>> $closures */
	private function closures( int $branch_id, array $closures ): void {
		$today  = Clock::jst();
		$stored = array_values( array_filter( (array) get_post_meta( $branch_id, '_momo_seed_closures', true ), static fn( $d ) => is_string( $d ) && $d >= $today->format( 'Y-m-d' ) ) );
		foreach ( $closures as $i => $c ) {
			if ( isset( $stored[ $i ] ) && ClosureRepository::rows_on( $branch_id, $stored[ $i ] ) ) {
				continue;
			}
			$date  = $today->modify( '+' . (int) $c['offset'] . ' days' )->format( 'Y-m-d' );
			$input = [ 'date_from' => $date, 'date_to' => $date, 'type' => $c['type'], 'public_reason' => $c['reason'], 'internal_note' => 'Demo closure (sample)', 'periods' => $c['periods'] ?? [], 'replace_existing' => '1', 'confirm' => '1' ];
			$res   = HoursFormHandler::process_closure( $branch_id, $input, get_current_user_id() );
			if ( 'saved' !== $res['status'] ) {
				throw new SeedException( 'Closure refused for branch ' . $branch_id . ': ' . implode( ' | ', (array) ( $res['errors'] ?? [] ) ) );
			}
			$stored[ $i ] = $date;
		}
		update_post_meta( $branch_id, '_momo_seed_closures', $stored );
	}

	// ================================================================== dishes

	private function dishes(): void {
		$cats = Menu::categories();
		foreach ( Menu::dishes() as $i => $d ) {
			$note    = $cats[ $d['cat'] ]['note'];
			$content = [];
			foreach ( $d['short'] as $lang => $short ) {
				$content[ $lang ] = '<p>' . $short . '</p><p>' . $note[ $lang ] . '</p>';
			}
			$id                        = Writer::upsert_post( ContentTypes::DISH, 'dish:' . $d['sku'], [ 'post_title' => $d['name']['ja'], 'post_content' => $content['ja'], 'post_name' => $d['slug'], 'menu_order' => ( $i + 1 ) * 10 ] );
			$this->dishes[ $d['sku'] ] = $id;

			// Terms first: an ingredient change flags allergen re-verification, which the G10 save clears.
			$this->set_terms( $id, ContentTypes::CATEGORY, [ $this->cats[ $d['cat'] ] ] );
			$this->set_terms( $id, ContentTypes::INGREDIENT, array_map( fn( $k ) => $this->ingredients[ $k ], $d['ingredients'] ) );
			$this->set_terms( $id, ContentTypes::TAG, array_map( fn( $k ) => $this->tags[ $k ], $d['tags'] ) );

			$image = $this->media->image( $d['image'], $d['name'] );
			if ( (int) get_post_thumbnail_id( $id ) !== $image ) {
				set_post_thumbnail( $id, $image );
			}
			Writer::save_fields(
				'post',
				$id,
				[
					'momo_short_description'        => $d['short']['ja'],
					'momo_original_name'            => $d['original'],
					'momo_dish_sku'                 => $d['sku'],
					'momo_primary_category'         => (string) $this->cats[ $d['cat'] ],
					'momo_image_illustrative'       => '1',
					'momo_featured'                 => ! empty( $d['featured'] ) ? '1' : '0',
					'momo_spice_level'              => (string) $d['spice'],
					'momo_advance_order_required'   => ! empty( $d['advance'] ) ? '1' : '0',
					'momo_advance_order_lead_hours' => ! empty( $d['advance'] ) ? (string) $d['advance'] : '',
					'momo_is_sample'                => '1',
					'momo_reference_price_jpy'      => (string) $d['price'],
				]
			);
			$this->allergens( $id, $d );
			Writer::publish( $id );
			$this->translate_post( $id, [ 'title' => $d['name'], 'content' => $content, 'momo_short_description' => $d['short'] ] );
			foreach ( Lang::targets() as $lang ) {
				Writer::translate( 'post', $image, $lang, [ 'alt' => $d['name'][ $lang ] ], $this->users['demo_tr'], $this->users['demo_ho'] );
			}
			$this->count( 'dishes' );
		}
	}

	/** @param list<int> $ids */
	private function set_terms( int $post_id, string $taxonomy, array $ids ): void {
		$current = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
		$current = is_array( $current ) ? array_map( 'intval', $current ) : [];
		sort( $current );
		sort( $ids );
		if ( $current !== $ids ) {
			$res = wp_set_object_terms( $post_id, $ids, $taxonomy, false );
			if ( is_wp_error( $res ) ) {
				throw new SeedException( "Could not assign {$taxonomy} to {$post_id}: " . $res->get_error_message() );
			}
		}
	}

	/**
	 * G10 allergen record by the CAP-DCA demo user (two-person rule: the kitchen source is someone
	 * else). Dishes with `allergens = null` keep every item "not verified".
	 *
	 * @param array<string,mixed> $d
	 */
	private function allergens( int $id, array $d ): void {
		if ( null === $d['allergens'] ) {
			return;
		}
		[ $contains, $may ] = $d['allergens'];
		$values             = [];
		foreach ( Allergens::codes() as $code ) {
			$values[ Allergens::meta_key( $code ) ] = in_array( $code, $contains, true ) ? 'contains' : ( in_array( $code, $may, true ) ? 'may_contain' : 'not_contained' );
		}
		$values['momo_alg_source_document'] = 'Demo recipe card ' . $d['sku'] . ' (sample data)';
		$values['momo_alg_sourced_by']      = 'Demo kitchen lead (fictional)';
		$current                            = [];
		foreach ( FieldRegistry::groups_for( 'post:' . ContentTypes::DISH ) as $g ) {
			$current += FieldRegistry::post_values( $id, $g );
		}
		$changed = false;
		foreach ( $values as $k => $v ) {
			if ( (string) ( $current[ $k ] ?? '' ) !== (string) $v ) {
				$changed = true;
			}
		}
		if ( ! $changed && '1' !== (string) get_post_meta( $id, 'momo_allergen_review_required', true ) ) {
			return;
		}
		$values['momo_alg_attest_not_source'] = '1';
		Writer::save_fields( 'post', $id, $values );
	}

	private function branch_menus(): void {
		foreach ( Menu::dishes() as $d ) {
			$dish = $this->dishes[ $d['sku'] ];
			foreach ( $d['branches'] as $code => $price ) {
				$branch = $this->branches[ $code ];
				$row    = BranchMenuItemRepository::find( $branch, $dish );
				if ( $row && 1 === (int) $row['is_offered'] && ( null === $price ? null === $row['price_override_jpy'] : (int) $row['price_override_jpy'] === $price ) ) {
					continue;
				}
				$this->menu_update( $branch, $dish, [ 'version' => $row ? (int) $row['version'] : 0, 'offered' => true, 'price_override_jpy' => $price, 'confirm_large_change' => true ], $this->users['demo_ho'] );
				$this->count( 'branch_menu_rows' );
			}
			if ( ! empty( $d['sold_out'] ) ) {
				// Marked by the branch's own reservation staff (momo_edit_branch_availability, branch scope).
				$branch = $this->branches[ $d['sold_out'] ];
				$row    = BranchMenuItemRepository::find( $branch, $dish );
				$active = $row && 'sold_out' === $row['availability'] && null !== $row['sold_out_until'] && Clock::from_mysql_utc( (string) $row['sold_out_until'] ) > Clock::utc();
				if ( $row && ! $active ) {
					$login = 'DNB' === $d['sold_out'] ? 'demo_rs_namba' : 'demo_rs_kitahama';
					$this->menu_update( $branch, $dish, [ 'version' => (int) $row['version'], 'availability' => 'sold_out', 'availability_reason' => 'sold_out_today' ], $this->users[ $login ] );
				}
			}
			if ( ! empty( $d['unavailable'] ) ) {
				$branch = $this->branches[ $d['unavailable'] ];
				$row    = BranchMenuItemRepository::find( $branch, $dish );
				if ( $row && 'unavailable' !== $row['availability'] ) {
					$this->menu_update( $branch, $dish, [ 'version' => (int) $row['version'], 'availability' => 'unavailable', 'availability_reason' => 'seasonal' ], $this->users['demo_ho'] );
				}
			}
		}
	}

	/** @param array<string,mixed> $input */
	private function menu_update( int $branch, int $dish, array $input, int $user_id ): void {
		$prev = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			BranchMenuService::update( $branch, $dish, $input, $user_id );
		} catch ( MenuItemException $e ) {
			throw new SeedException( sprintf( 'Branch menu update refused (branch %d, dish %d): %s', $branch, $dish, $e->getMessage() ) );
		} finally {
			wp_set_current_user( $prev );
		}
	}

	// ================================================================== notices & offers

	private function notices(): void {
		foreach ( Content::notices() as $n ) {
			$id     = Writer::upsert_post( 'momo_notice', 'notice:' . $n['key'], [ 'post_title' => $n['title']['ja'] ] );
			$values = [
				'momo_notice_body'       => $n['body']['ja'],
				'momo_notice_severity'   => $n['severity'],
				'momo_notice_scope'      => $n['scope'],
				'momo_notice_start_at'   => $this->day( (int) $n['start'], '00:00' ),
				'momo_notice_end_at'     => $this->day( (int) $n['end'], '23:59' ),
				'momo_notice_link_url'   => $n['link'],
				'momo_notice_link_label' => $n['label']['ja'],
			];
			if ( 'branches' === $n['scope'] ) {
				$values['momo_notice_branches'] = $this->scope_terms( $n['branches'] );
			}
			Writer::save_fields( 'post', $id, $values );
			Writer::publish( $id );
			$tr = [ 'title' => $n['title'], 'momo_notice_body' => $n['body'], 'momo_notice_link_label' => $n['label'] ];
			if ( '' !== $n['link'] ) {
				$tr['momo_notice_link_url'] = array_fill_keys( Lang::codes(), $n['link'] );
			}
			$this->translate_post( $id, $tr );
			$this->count( 'notices' );
		}
	}

	private function offers(): void {
		foreach ( Content::offers() as $o ) {
			$id     = Writer::upsert_post( 'momo_offer', 'offer:' . $o['key'], [ 'post_title' => $o['title']['ja'] ] );
			$values = [
				'momo_offer_summary'           => $o['summary']['ja'],
				'momo_offer_conditions'        => $o['conditions']['ja'],
				'momo_offer_start_at'          => $this->day( (int) $o['start'], '00:00' ),
				'momo_offer_end_at'            => $this->day( (int) $o['end'], '23:59' ),
				'momo_offer_scope'             => $o['scope'],
				'momo_offer_price_jpy'         => $o['price'],
				'momo_offer_cta_type'          => $o['cta'],
				'momo_offer_homepage_featured' => $o['featured'] ? '1' : '0',
				'momo_offer_order'             => (string) $o['order'],
				'momo_is_sample'               => '1',
			];
			if ( 'branches' === $o['scope'] ) {
				$values['momo_offer_branches'] = $this->scope_terms( $o['branches'] );
			}
			if ( isset( $o['cta_category'] ) ) {
				$values['momo_offer_cta_category'] = (string) $this->cats[ $o['cta_category'] ];
			}
			Writer::save_fields( 'post', $id, $values );
			$image = $this->media->image( $o['image'], $o['title'] );
			if ( (int) get_post_thumbnail_id( $id ) !== $image ) {
				set_post_thumbnail( $id, $image );
			}
			Writer::publish( $id );
			$this->translate_post( $id, [ 'title' => $o['title'], 'momo_offer_summary' => $o['summary'], 'momo_offer_conditions' => $o['conditions'] ] );
			foreach ( Lang::targets() as $lang ) {
				Writer::translate( 'post', $image, $lang, [ 'alt' => $o['title'][ $lang ] ], $this->users['demo_tr'], $this->users['demo_ho'] );
			}
			$this->count( 'offers' );
		}
	}

	/** @param list<string> $codes @return list<int> branch-scope term ids */
	private function scope_terms( array $codes ): array {
		$out = [];
		foreach ( $codes as $code ) {
			$term = BranchScopeTaxonomy::term_for_branch( $this->branches[ $code ] ) ?? ( BranchScopeTaxonomy::sync( $this->branches[ $code ] ) ? BranchScopeTaxonomy::term_for_branch( $this->branches[ $code ] ) : null );
			if ( ! $term ) {
				throw new SeedException( 'No branch scope term for ' . $code );
			}
			$out[] = (int) $term->term_id;
		}
		return $out;
	}

	/** JST "Y-m-d H:i" $days from today. */
	private function day( int $days, string $time ): string {
		return Clock::jst()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' ) . ' ' . $time;
	}

	// ================================================================== pages & settings

	private function pages(): void {
		foreach ( Content::pages() as $role => $p ) {
			$id = SystemPages::id( $role );
			if ( ! $id ) {
				( $this->out )( "  (system page '{$role}' not found — skipped)" );
				continue;
			}
			$this->snapshot_page( $id );
			$core = [];
			if ( isset( $p['excerpt'] ) ) {
				$core['post_excerpt'] = $p['excerpt']['ja'];
			}
			if ( isset( $p['content'] ) ) {
				$core['post_content'] = $p['content']['ja'];
			}
			if ( $core ) {
				Writer::update_core( $id, $core );
			}
			if ( ! empty( $p['faq'] ) ) {
				$this->faq( $id );
			}
			if ( ! empty( $p['publish'] ) ) {
				Writer::publish( $id );
			}
			if ( '1' !== (string) get_post_meta( $id, Writer::SAMPLE_META, true ) ) {
				update_post_meta( $id, Writer::SAMPLE_META, '1' );
			}
			$tr = [ 'title' => $p['title'] ];
			if ( isset( $p['excerpt'] ) ) {
				$tr['excerpt'] = $p['excerpt'];
			}
			if ( isset( $p['content'] ) ) {
				$tr['content'] = $p['content'];
			}
			if ( ! empty( $p['faq'] ) ) {
				$tr['faq_items'] = [];
				foreach ( Lang::targets() as $lang ) {
					$tr['faq_items'][ $lang ] = array_map( static fn( $r ) => [ 'id' => $r['id'], 'question' => $r['question'][ $lang ], 'answer' => $r['answer'][ $lang ] ], Content::faq() );
				}
			}
			$this->translate_post( $id, $tr );
			$this->count( 'pages' );
		}
	}

	private function faq( int $page_id ): void {
		$rows = array_map( static fn( $r ) => [ 'id' => $r['id'], 'group' => $r['group'], 'question' => $r['question']['ja'], 'answer' => $r['answer']['ja'], 'order' => $r['order'] ], Content::faq() );
		if ( class_exists( \Momos\Admin\Content\FaqEditor::class ) && method_exists( \Momos\Admin\Content\FaqEditor::class, 'save_items' ) ) {
			$current = json_decode( (string) get_post_meta( $page_id, 'momo_faq_items', true ), true );
			if ( $current !== $rows ) {
				$errors = (array) \Momos\Admin\Content\FaqEditor::save_items( $page_id, $rows );
				if ( $errors && ! isset( $errors['items'] ) && array_filter( $errors, 'is_string' ) ) {
					throw new SeedException( 'FAQ refused: ' . implode( ' | ', array_filter( $errors, 'is_string' ) ) );
				}
			}
			return;
		}
		// FAQ editor not installed yet: store the documented shape (page meta JSON, Content::faq()).
		$json = (string) wp_json_encode( $rows, JSON_UNESCAPED_UNICODE );
		if ( (string) get_post_meta( $page_id, 'momo_faq_items', true ) !== $json ) {
			update_post_meta( $page_id, 'momo_faq_items', wp_slash( $json ) );
		}
	}

	private function legal_pages(): void {
		$titles  = Content::legal_titles();
		$today   = Clock::jst()->format( 'Y-m-d' );
		$version = '0.1';
		$this->consent_version( $version, $today );
		foreach ( array_keys( SystemPages::LEGAL ) as $key ) {
			$id = SystemPages::legal_id( $key );
			if ( ! $id ) {
				( $this->out )( "  (legal page '{$key}' not found — skipped)" );
				continue;
			}
			$this->snapshot_page( $id );
			$body = '<p>' . Content::LEGAL_PLACEHOLDER . '</p>';
			Writer::update_core( $id, [ 'post_title' => $titles[ $key ]['ja'], 'post_content' => $body ] );
			$meta = [ 'momo_page_type' => 'legal', 'momo_legal_version' => $version, 'momo_legal_last_updated' => (string) ( get_post_meta( $id, 'momo_legal_last_updated', true ) ?: $today ) ];
			$this->page_meta( $id, $meta );
			Writer::publish( $id );
			if ( '1' !== (string) get_post_meta( $id, Writer::SAMPLE_META, true ) ) {
				update_post_meta( $id, Writer::SAMPLE_META, '1' );
			}
			$this->translate_post( $id, [ 'title' => $titles[ $key ], 'content' => array_fill_keys( Lang::codes(), $body ) ] );
			$this->count( 'legal_pages' );
		}
	}

	/** Legal page meta through the registered page field group when one exists (LegalPageBox), else the documented keys. @param array<string,string> $meta */
	private function page_meta( int $id, array $meta ): void {
		$registered = [];
		foreach ( FieldRegistry::groups_for( 'post:page' ) as $g ) {
			foreach ( $g['fields'] as $f ) {
				$registered[ $f['name'] ] = true;
			}
		}
		$via_fields = array_intersect_key( $meta, $registered );
		if ( $via_fields ) {
			Writer::save_fields( 'post', $id, $via_fields );
		}
		foreach ( array_diff_key( $meta, $registered ) as $k => $v ) {
			if ( (string) get_post_meta( $id, $k, true ) !== $v ) {
				update_post_meta( $id, $k, $v );
			}
		}
	}

	/** Consent registry row for the placeholder policy versions (PP0.1-RP0.1), recorded for --reset. */
	private function consent_version( string $version, string $today ): void {
		if ( ! class_exists( \Momos\Privacy\Consent\ConsentRegistry::class ) ) {
			return;
		}
		$key = \Momos\Privacy\Consent\ConsentRegistry::key_for( $version, $version );
		if ( null !== \Momos\Privacy\Consent\ConsentRegistry::find( $key ) ) {
			return;
		}
		\Momos\Privacy\Consent\ConsentRegistry::add_version( $version, $version, $today );
		$m                 = self::manifest();
		$m['consent_keys'] = array_values( array_unique( array_merge( (array) ( $m['consent_keys'] ?? [] ), [ $key ] ) ) );
		self::save_manifest( $m );
	}

	private function settings(): void {
		$brand = Content::brand();
		$this->snapshot_options( 'momo-brand', array_keys( $brand ) );
		$values = [];
		foreach ( $brand as $k => $v ) {
			$values[ $k ] = is_array( $v ) ? $v['ja'] : $v;
		}
		Writer::save_fields( 'option', 'momo-brand', $values );
		$trading = (string) \Momos\Kernel\Settings::get( 'momo_trading_name' );
		foreach ( Lang::targets() as $lang ) {
			$tr = [ 'momo_tagline' => $brand['momo_tagline'][ $lang ] ];
			if ( '' !== $trading && '' === (string) ( Translations::option_data( 'momo-brand', $lang )['momo_trading_name'] ?? '' ) ) {
				$tr['momo_trading_name'] = $trading; // The trading name is a proper name (Latin script in every language).
			}
			Writer::translate_option( 'momo-brand', $lang, $tr, $this->users['demo_ho'] );
		}
		$notify = Content::notification_settings();
		$this->snapshot_options( 'momo-settings', array_keys( $notify ) );
		Writer::save_fields( 'option', 'momo-settings', $notify );
	}

	private function snapshot_page( int $id ): void {
		$m = self::manifest();
		if ( isset( $m['pages'][ $id ] ) ) {
			return;
		}
		$post = get_post( $id );
		$meta = [];
		foreach ( (array) get_post_meta( $id ) as $k => $vals ) {
			if ( str_starts_with( (string) $k, '_momos_i18n_' ) || in_array( $k, [ 'momo_faq_items', 'momo_legal_version', 'momo_legal_last_updated', Writer::SAMPLE_META ], true ) ) {
				$meta[ $k ] = $vals[0] ?? '';
			}
		}
		global $wpdb;
		$rev_max           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d", $id ) ); // phpcs:ignore
		$m['pages'][ $id ] = [ 'post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_excerpt' => $post->post_excerpt, 'post_status' => $post->post_status, 'meta' => $meta, 'rev_max' => $rev_max ];
		self::save_manifest( $m );
	}

	/** @param list<string> $keys */
	private function snapshot_options( string $page, array $keys ): void {
		$m = self::manifest();
		if ( isset( $m['options'][ $page ] ) ) {
			return;
		}
		$values                = \Momos\Kernel\Settings::page( $page );
		$m['options'][ $page ] = [];
		foreach ( $keys as $k ) {
			$m['options'][ $page ][ $k ] = [ 'exists' => array_key_exists( $k, $values ), 'value' => $values[ $k ] ?? null ];
		}
		$i18n                      = get_option( 'momos_option_i18n', [] );
		$m['option_i18n'][ $page ] = is_array( $i18n ) && isset( $i18n[ $page ] ) ? $i18n[ $page ] : null;
		self::save_manifest( $m );
	}

	/** @return array<string,mixed> */
	public static function manifest(): array {
		$m = get_option( self::MANIFEST, [] );
		return is_array( $m ) ? $m : [];
	}

	/** @param array<string,mixed> $m */
	public static function save_manifest( array $m ): void {
		update_option( self::MANIFEST, $m, false );
	}

	// ================================================================== reservations

	/**
	 * Sample reservations in every status through SubmissionService / TransitionService /
	 * ExpiryService. Past timelines run under a frozen clock (non-production test clock, SDD §3.6) so
	 * every transition respects the real rules (lead time, cutoff, attendance window).
	 */
	private function reservations(): void {
		$now = Clock::utc();
		foreach ( People::reservations() as $r ) {
			$key = People::reservation_key( (int) $r['n'] );
			$row = ReservationRepository::find_by_idempotency_key( $key );
			if ( null === $row ) {
				$row = $this->submit( $r, $now );
			}
			$this->count( 'reservations' );
			foreach ( $r['steps'] as [ $action, $actor, $offset, $extra ] ) {
				$row = (array) ReservationRepository::find( (int) $row['id'] );
				if ( $this->step_done( $row, (string) $action ) ) {
					continue;
				}
				$at = $this->step_time( $row, (string) $action, (int) $offset, $now );
				Clock::set( new FrozenClock( $at ) );
				try {
					$this->transition( $row, (string) $action, (string) $actor, (array) $extra );
				} finally {
					Clock::set( new FrozenClock( $now ) );
				}
			}
		}
	}

	/** @param array<string,mixed> $r @return array<string,mixed> */
	private function submit( array $r, \DateTimeImmutable $now ): array {
		$branch  = $this->branches[ $r['branch'] ];
		$created = 0 === (int) $r['created'] ? $now : Clock::jst_at( Clock::jst()->modify( (int) $r['created'] . ' days' )->format( 'Y-m-d' ), '09:30' );
		$created = $created > $now ? $now : $created;
		Clock::set( new FrozenClock( $created ) );
		try {
			$date  = $this->open_date( $branch, (string) $r['time'], (int) $r['day'], $now );
			$input = [
				'idempotency_key' => People::reservation_key( (int) $r['n'] ),
				'branch_code'     => $r['branch'],
				'date'            => $date,
				'time'            => $r['time'],
				'party_size'      => (int) $r['party'],
				'name'            => $r['name'],
				'email'           => People::reservation_email( (int) $r['n'] ),
				'phone'           => '' !== $r['phone'] ? $r['phone'] : null,
				'special_request' => '' !== $r['request'] ? $r['request'] : null,
				'lang'            => $r['lang'],
				'preferred_lang'  => $r['lang'],
				'consent'         => true,
			];
			$res   = SubmissionService::submit( $input, [ 'captcha_status' => 'not_applicable', 'ip' => null, 'user_agent' => '', 'user_id' => 0 ] );
			return $res['row'];
		} catch ( ReservationError $e ) {
			throw new SeedException( sprintf( 'Sample reservation %d refused: %s %s', $r['n'], $e->error_code, (string) wp_json_encode( $e->fields ) ) );
		} finally {
			Clock::set( new FrozenClock( $now ) );
		}
	}

	/** First date ≥ today + $offset (seen from the real now) on which $time is a bookable slot. */
	private function open_date( int $branch, string $time, int $offset, \DateTimeImmutable $now ): string {
		$interval = (int) \Momos\Branch\Repository\BranchSettingsRepository::get( $branch )['slot_interval_minutes'];
		$start    = $now->setTimezone( Clock::tz() )->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' days' );
		for ( $i = 0; $i < 14; $i++ ) {
			$date = $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			if ( null === SlotGenerator::slot_problem( $branch, $date, $time, $interval ) ) {
				return $date;
			}
		}
		throw new SeedException( "No open slot {$time} for branch {$branch} near offset {$offset}." );
	}

	/** @param array<string,mixed> $row */
	private function step_done( array $row, string $action ): bool {
		$target = [ 'accept' => 'confirmed', 'decline' => 'rejected', 'cancel' => 'cancelled', 'expire' => 'expired', 'mark_completed' => 'completed', 'mark_no_show' => 'no_show' ][ $action ] ?? '';
		$order  = [ 'pending' => 0, 'confirmed' => 1, 'rejected' => 2, 'cancelled' => 2, 'expired' => 2, 'completed' => 2, 'no_show' => 2 ];
		return $row['status'] === $target || ( $order[ $row['status'] ] ?? 0 ) > ( $order[ $target ] ?? 0 );
	}

	/** @param array<string,mixed> $row */
	private function step_time( array $row, string $action, int $offset, \DateTimeImmutable $now ): \DateTimeImmutable {
		$start = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		$at    = match ( $action ) {
			'expire'                         => $start->modify( '-30 minutes' ),
			'mark_completed', 'mark_no_show' => $start->modify( '+2 hours' ),
			default                          => 0 === $offset ? $now : Clock::jst_at( $now->setTimezone( Clock::tz() )->modify( $offset . ' days' )->format( 'Y-m-d' ), '10:00' ),
		};
		$created = Clock::from_mysql_utc( (string) $row['created_at'] );
		if ( $at < $created ) {
			$at = $created->modify( '+5 minutes' );
		}
		return $at > $now ? $now : $at;
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $extra */
	private function transition( array $row, string $action, string $actor, array $extra ): void {
		try {
			if ( 'expire' === $action ) {
				if ( null === ExpiryService::expire_if_due( (int) $row['id'] ) ) {
					throw new SeedException( 'Sample reservation ' . $row['id'] . ' was not due for expiry.' );
				}
				return;
			}
			if ( 'customer' === $actor ) {
				TransitionService::customer_cancel( $row );
				return;
			}
			$uid  = $this->users[ $actor ];
			$prev = get_current_user_id();
			wp_set_current_user( $uid );
			try {
				if ( ! current_user_can( 'momo_process_reservation', (int) $row['id'] ) ) {
					throw new SeedException( "{$actor} may not process reservation {$row['id']}." );
				}
				TransitionService::staff( (int) $row['id'], $action, [ 'version' => (int) $row['version'] ] + $extra, Actor::staff( $uid ) );
			} finally {
				wp_set_current_user( $prev );
			}
		} catch ( ReservationError $e ) {
			throw new SeedException( sprintf( 'Transition %s of sample reservation %d refused: %s %s', $action, $row['id'], $e->error_code, (string) wp_json_encode( $e->fields ) ) );
		}
	}

	private function count( string $what ): void {
		$this->counts[ $what ] = ( $this->counts[ $what ] ?? 0 ) + 1;
	}
}
