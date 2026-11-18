<?php
declare(strict_types=1);

namespace Momos\Kernel;

/**
 * A business module of momos-core (SDD §2.1). register() only attaches hooks; it must not
 * query the database or produce output.
 */
interface ModuleInterface {
	public function register(): void;
}
