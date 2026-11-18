<?php
declare(strict_types=1);

namespace Momos\Access\Migration;

use Momos\Access\Roles\RoleInstaller;
use Momos\Kernel\Migration\MigrationInterface;

/** M0004: roles and capabilities (TRD §2.7, §6.5). Idempotent: re-applies the table exactly. */
final class M0004_Roles implements MigrationInterface {
	public function id(): string {
		return 'M0004_Roles';
	}

	public function up(): void {
		RoleInstaller::sync();
	}
}
