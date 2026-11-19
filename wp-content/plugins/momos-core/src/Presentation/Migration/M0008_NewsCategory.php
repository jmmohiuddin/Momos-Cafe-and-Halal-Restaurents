<?php
declare(strict_types=1);

namespace Momos\Presentation\Migration;

use Momos\Kernel\Migration\MigrationInterface;
use Momos\Presentation\View\Content;

/**
 * M0008 (news) — the native post category `news` ("News"/ニュース) that the News list reads (FR-CORP-005(b),
 * AC-CORP-05-01: only posts in the News category are listed). Idempotent: an existing `news` category is kept
 * as it is (name and description are editorial).
 */
final class M0008_NewsCategory implements MigrationInterface {
	public function id(): string {
		return 'M0008_NewsCategory';
	}

	public function up(): void {
		if ( get_term_by( 'slug', Content::NEWS_CATEGORY, 'category' ) instanceof \WP_Term ) {
			return;
		}
		wp_insert_term( 'ニュース', 'category', [ 'slug' => Content::NEWS_CATEGORY ] );
	}
}
