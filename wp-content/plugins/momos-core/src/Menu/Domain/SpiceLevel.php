<?php
declare(strict_types=1);

namespace Momos\Menu\Domain;

/** Spice level 0–3 (spec §2.2; customer labels `spice.{n}`). */
enum SpiceLevel: int {
	case None   = 0;
	case Mild   = 1;
	case Medium = 2;
	case Hot    = 3;

	/**
	 * Admin choices keyed by level. PHP stores numeric-string keys as ints, so callers compare with
	 * `(string) $key` (Renderer) or `isset( $choices[ $value ] )` (Sanitizer).
	 *
	 * @return array<int,string>
	 */
	public static function choices(): array {
		return [
			self::None->value   => __( '0 — Not spicy', 'momos-core' ),
			self::Mild->value   => __( '1 — Mild', 'momos-core' ),
			self::Medium->value => __( '2 — Medium', 'momos-core' ),
			self::Hot->value    => __( '3 — Hot', 'momos-core' ),
		];
	}
}
