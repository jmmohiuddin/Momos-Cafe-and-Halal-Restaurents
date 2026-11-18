<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Locale;
use Momos\Presentation\PageContext;
use Momos\Presentation\SystemPages;

/**
 * Mobile action bar items by page type (ui-design-system.md §8.6, FR-CORP-008(c)(d), US-CORP-009).
 * Branch accepting requests: Request · Call · Directions; branch not accepting / temporarily closed:
 * Call · Directions; C09–C11: none; every other page: Request a table · Branches.
 */
final class ActionBar {
	/**
	 * @return list<array{key:string,label:string,aria:string,url:string,icon:string,primary:bool,external:bool,event:string,branch:string}>
	 */
	public static function items( ?string $type = null, int $branch_id = 0, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		$type = $type ?? PageContext::page_type();
		if ( PageContext::hides_action_bar( $type ) ) {
			return [];
		}
		$request = momos_t( 'cta.request_table', [], $lang );
		if ( 'branch' === $type && $branch_id && class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			$b = \Momos\Branch\Repository\BranchRepository::get( $branch_id );
			if ( null !== $b ) {
				$name  = \Momos\Branch\Repository\BranchRepository::name( $branch_id, $lang );
				$items = [];
				if ( self::accepting( $branch_id, $b ) ) {
					$items[] = self::make( 'request', $request, add_query_arg( 'branch', $b['code'], (string) SystemPages::url( 'reservation', $lang ) ), 'calendar', true, false, 'click_reservation', $b['code'] );
				}
				if ( '' !== $b['phone_e164'] ) {
					$items[] = self::make( 'call', momos_t( 'cta.call', [], $lang ), \Momos\Presentation\Format\PhoneFormatter::tel_href( $b['phone_e164'] ), 'phone', false, false, 'click_phone', $b['code'], '' !== $name ? momos_t( 'cta.call', [], $lang ) . ' ' . $name : '' );
				}
				$dir = self::directions_url( $b );
				if ( '' !== $dir ) {
					$items[] = self::make( 'directions', momos_t( 'cta.directions', [], $lang ), $dir, 'directions', false, true, 'click_directions', $b['code'], '' !== $name ? momos_t( 'cta.directions_to', [ 'branch' => $name ], $lang ) : '' );
				}
				return $items;
			}
		}
		return [
			self::make( 'request', $request, (string) SystemPages::url( 'reservation', $lang ), 'calendar', true, false, 'click_reservation', '' ),
			self::make( 'branches', momos_t( 'nav.branches', [], $lang ), (string) SystemPages::url( 'branches', $lang ), 'store', false, false, '', '' ),
		];
	}

	/** Branch accepts online requests: open (not temporarily closed), branch toggle and global switch on. */
	public static function accepting( int $branch_id, array $branch ): bool {
		if ( 'open' !== $branch['operating_status'] ) {
			return false;
		}
		if ( class_exists( \Momos\Branch\Service\GlobalSettings::class ) && ! \Momos\Branch\Service\GlobalSettings::reservations_globally_enabled() ) {
			return false;
		}
		if ( class_exists( \Momos\Branch\Repository\BranchSettingsRepository::class ) ) {
			return (bool) ( \Momos\Branch\Repository\BranchSettingsRepository::get( $branch_id )['enabled'] ?? false );
		}
		return false;
	}

	/** Google Maps directions URL (link only; no Maps JS on load). '' without coordinates/address. */
	public static function directions_url( array $branch ): string {
		if ( null !== ( $branch['lat'] ?? null ) && null !== ( $branch['lng'] ?? null ) ) {
			$args = [ 'api' => '1', 'destination' => $branch['lat'] . ',' . $branch['lng'] ];
			if ( '' !== (string) ( $branch['place_id'] ?? '' ) ) {
				$args['destination_place_id'] = (string) $branch['place_id'];
			}
			return add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.google.com/maps/dir/' );
		}
		if ( '' !== (string) ( $branch['address_ja'] ?? '' ) ) {
			return add_query_arg( [ 'api' => '1', 'destination' => rawurlencode( (string) $branch['address_ja'] ) ], 'https://www.google.com/maps/dir/' );
		}
		return '';
	}

	/** @return array{key:string,label:string,aria:string,url:string,icon:string,primary:bool,external:bool,event:string,branch:string} */
	private static function make( string $key, string $label, string $url, string $icon, bool $primary, bool $external, string $event, string $branch, string $aria = '' ): array {
		return compact( 'key', 'label', 'aria', 'url', 'icon', 'primary', 'external', 'event', 'branch' );
	}
}
