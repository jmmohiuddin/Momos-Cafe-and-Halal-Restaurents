<?php
declare(strict_types=1);

namespace Momos\Admin\Content;

use Momos\Kernel\ModuleInterface;

/**
 * Content admin editors the theme consumes (SCR-A14 Pages & content, SCR-A18 Media; content-strings.md
 * §2.3/§3.4/§8.4/§8.7, screens.md §3.13/§3.15/§3.18/§5.14/§5.18):
 *  - FaqEditor: page meta `momo_faq_items` (JSON list) + its per-language translation (`faq_items`).
 *  - LegalPageBox: `momo_page_type`, `momo_legal_version`, `momo_legal_last_updated` (cap
 *    `momo_publish_legal_pages`; publishing legal pages is enforced by Access MetaCapMapper M5).
 *  - MediaFields: gallery attachment fields + attachment alt/caption in the translation workflow.
 */
final class ContentModule implements ModuleInterface {
	public function register(): void {
		FaqEditor::register();
		LegalPageBox::register();
		MediaFields::register();
	}
}
