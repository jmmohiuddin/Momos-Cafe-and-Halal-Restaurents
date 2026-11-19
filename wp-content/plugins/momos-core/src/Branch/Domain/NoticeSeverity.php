<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Notice severity (G15, FR-BR-009). */
enum NoticeSeverity: string {
	case Info    = 'info';
	case Warning = 'warning';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
