<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Notice scope (G15, FR-BR-009). */
enum NoticeScope: string {
	case SiteWide = 'site_wide';
	case Branches = 'branches';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
