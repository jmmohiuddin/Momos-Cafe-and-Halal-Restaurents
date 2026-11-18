<?php
declare(strict_types=1);

namespace Momos\Presentation\Migration;

use Momos\Kernel\Migration\MigrationInterface;

/**
 * M0007 revision 2 (screens.md §0.1, i18n-seo-analytics.md §8 rows 10–11): adds the `status` system page
 * (child of `reservation`, shell for SCR-C11 served by the `reservation/status/{REF}/` rewrite) and the
 * `momo_noindex` flags of C10/C11 on installs that already ran M0007. Same idempotent steps as M0007:
 * existing pages are reused, never overwritten.
 */
final class M0007_SystemPagesR2 implements MigrationInterface {
	public function id(): string {
		return 'M0007_SystemPages_r2';
	}

	public function up(): void {
		( new M0007_SystemPages() )->up();
		delete_option( \Momos\Presentation\Templates\TemplateRouter::REWRITE_OPTION ); // Rules refreshed on next init.
	}
}
