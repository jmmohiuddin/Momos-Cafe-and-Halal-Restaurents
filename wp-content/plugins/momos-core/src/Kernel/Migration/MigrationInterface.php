<?php
declare(strict_types=1);

namespace Momos\Kernel\Migration;

interface MigrationInterface {
	/** Stable identifier, e.g. "M0001_CreateTables". Never reused. */
	public function id(): string;

	/** Idempotent: safe to run again. */
	public function up(): void;
}
