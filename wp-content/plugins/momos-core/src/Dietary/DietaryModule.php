<?php
declare(strict_types=1);

namespace Momos\Dietary;

use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\ModuleInterface;

/**
 * Dietary module (F-DIET-01/02; spec docs/specs/menu-dietary.md): allergen reference (M0003), statement
 * templates (G2), branch dietary group (G7), dish dietary & allergens (G10), certificate register
 * (G14, private evidence store), claim guards (CAP-DCA, two-person rule, claim tags, ingredient
 * watcher, wording guard, mark protection), ClaimResolver / AllergenPresenter, certificate expiry job.
 *
 * Domain hooks emitted: `momos/dietary/claims_changed (string $context, int $id)`,
 * `momos/dietary/review_required (int $dish_id)`, `momos/certificate/status_changed (int $id, string $from, string $to)`,
 * `momos/certificate/expiry_alert (int $id, string $variant, array $recipient, string $dedupe_key)`.
 */
final class DietaryModule implements ModuleInterface {
	public function register(): void {
		add_action( 'init', [ ContentTypes::class, 'register' ], 5 );
		add_action( 'init', [ Fields\DietaryFields::class, 'register' ], 7 );
		add_filter( 'use_block_editor_for_post_type', [ ContentTypes::class, 'disable_block_editor' ], 10, 2 );
		add_filter(
			'momos/migrations',
			static function ( array $list ): array {
				$list[] = new Migration\M0003_SeedAllergens();
				$list[] = new Migration\M0003_SeedStatements();
				return $list;
			}
		);

		Guard\ClaimFieldGuard::register();
		Guard\ClaimTagGuard::register();
		Guard\IngredientChangeWatcher::register();
		Guard\WordingGuard::register();
		Guard\CertificateGuard::register();
		Guard\AllergenGuard::register();

		Schedule::recurring( Job\CertificateExpiryJob::HOOK, Job\CertificateExpiryJob::INTERVAL );
		add_action( Job\CertificateExpiryJob::HOOK, [ Job\CertificateExpiryJob::class, 'run' ] );

		if ( is_admin() ) {
			Admin\CertificateEditor::register();
			Admin\CertificateRegister::register();
			Admin\StatementsPage::register();
			Admin\ClaimTermScanPage::register();
			Admin\DishDietaryBox::register();
		}
	}
}
