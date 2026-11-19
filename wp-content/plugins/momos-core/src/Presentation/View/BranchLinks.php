<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;
use Momos\Presentation\Format\PhoneFormatter;

/**
 * Branch rows for the header dropdown, mobile menu phones and footer (FR-CORP-008/009): published,
 * non-test, not permanently closed, translated in the language, HO order. Empty list while the Branch
 * module is absent (documented empty state: items not rendered).
 */
final class BranchLinks {
	/** @var array<string, list<array<string,mixed>>> */
	private static array $cache = [];

	public static function flush(): void {
		self::$cache = [];
	}

	/**
	 * @return list<array{id:int,code:string,name:string,area:string,url:string,phone_e164:string,phone:string,tel:string,temp_closed:bool}>
	 */
	public static function all( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		if ( isset( self::$cache[ $lang ] ) ) {
			return self::$cache[ $lang ];
		}
		$out = [];
		if ( class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			try {
				foreach ( \Momos\Branch\Repository\BranchRepository::public_ids( $lang ) as $id ) {
					$row = self::row( (int) $id, $lang );
					if ( null !== $row ) {
						$out[] = $row;
					}
				}
			} catch ( \Throwable $e ) {
				\Momos\Kernel\Log\Logger::error( 'presentation.branch_links_failed', [ 'error' => $e->getMessage() ] );
				$out = [];
			}
		}
		self::$cache[ $lang ] = $out;
		return $out;
	}

	/** @return array{id:int,code:string,name:string,area:string,url:string,phone_e164:string,phone:string,tel:string,temp_closed:bool}|null */
	public static function row( int $id, string $lang ): ?array {
		$b = \Momos\Branch\Repository\BranchRepository::get( $id );
		if ( null === $b ) {
			return null;
		}
		$name = \Momos\Branch\Repository\BranchRepository::name( $id, $lang );
		if ( '' === $name ) {
			return null; // Not approved in $lang: hidden, never mixed (BR-I18N-02).
		}
		$e164 = (string) $b['phone_e164'];
		return [
			'id'          => $id,
			'code'        => (string) $b['code'],
			'name'        => $name,
			'area'        => Translations::post( $id, 'momo_area_label', $lang ),
			'url'         => Url::localize( (string) get_permalink( $id ), $lang ),
			'phone_e164'  => $e164,
			'phone'       => '' !== $e164 ? PhoneFormatter::display( $e164, (string) $b['phone_display'], $lang ) : '',
			'tel'         => '' !== $e164 ? PhoneFormatter::tel_href( $e164 ) : '',
			'temp_closed' => 'temporarily_closed' === $b['operating_status'],
		];
	}
}
