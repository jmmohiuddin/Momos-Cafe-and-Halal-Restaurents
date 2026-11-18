<?php
declare(strict_types=1);

namespace Momos\Menu\Domain;

/** Spice level 0–3 (spec §2.2; customer labels `spice.{n}`). */
enum SpiceLevel: int {
	case None   = 0;
	case Mild   = 1;
	case Medium = 2;
	case Hot    = 3;

	/** @return array<string,string> admin choices */
	public static function choices(): array {
		return [
			'0' => __( '0 — Not spicy', 'momos-core' ),
			'1' => __( '1 — Mild', 'momos-core' ),
			'2' => __( '2 — Medium', 'momos-core' ),
			'3' => __( '3 — Hot', 'momos-core' ),
		];
	}
}
