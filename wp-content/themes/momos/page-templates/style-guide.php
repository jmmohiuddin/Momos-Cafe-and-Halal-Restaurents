<?php
/**
 * Template Name: Living style guide (staff only)
 *
 * Every design-system component (ui-design-system.md §7) rendered twice: in the page language and in a
 * language of the opposite direction (ar ↔ en), each panel with its own `lang`/`dir` and catalog strings,
 * so LTR and RTL can be reviewed side by side (TK-03-06 AC, DEC-057 review). Logged-in staff only
 * (TemplateRouter → 404 for visitors), `noindex`, not cached. All data is SAMPLE (View\Samples).
 *
 * @package momos
 */

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Presentation\View\Samples;

defined( 'ABSPATH' ) || exit;

if ( ! momos_core_ready() ) {
	get_header();
	echo '<div class="momo-container momo-section"><p>momos-core is required.</p></div>';
	get_footer();
	return;
}

$momos_page_lang = momos_theme_lang();
$momos_other     = Lang::is_rtl( $momos_page_lang ) ? 'en' : 'ar';
$momos_panels    = [ $momos_page_lang, $momos_other ];

wp_enqueue_style( 'momos-style-guide', get_theme_file_uri( 'assets/css/style-guide.css' ), [ 'momos-ui' ], (string) filemtime( get_theme_file_path( 'assets/css/style-guide.css' ) ) );
wp_enqueue_style( 'momos-fonts-' . $momos_other, get_theme_file_uri( 'assets/css/fonts-' . $momos_other . '.css' ), [], (string) filemtime( get_theme_file_path( 'assets/css/fonts-' . $momos_other . '.css' ) ) );

/**
 * Render a demo block in each panel language.
 *
 * @param string   $title  Section title (staff language).
 * @param string   $id     Anchor.
 * @param callable $render fn(string $lang): void — runs inside Locale::with($lang).
 */
$momos_section = static function ( string $title, string $id, callable $render ) use ( $momos_panels ): void {
	echo '<section class="momo-sg-section" id="sg-' . esc_attr( $id ) . '" aria-labelledby="sg-' . esc_attr( $id ) . '-h">';
	echo '<h2 class="momo-h3" id="sg-' . esc_attr( $id ) . '-h">' . esc_html( $title ) . '</h2><div class="momo-sg-panels momo-sg-panels--split">';
	foreach ( $momos_panels as $momos_l ) {
		$momos_dir = Lang::is_rtl( $momos_l ) ? 'rtl' : 'ltr';
		echo '<div class="momo-sg-panel" lang="' . esc_attr( $momos_l ) . '" dir="' . esc_attr( $momos_dir ) . '"><span class="momo-sg-tag" lang="en" dir="ltr">' . esc_html( strtoupper( $momos_l ) . ' · ' . $momos_dir ) . '</span>';
		Locale::with( $momos_l, static fn() => $render( $momos_l ) );
		echo '</div>';
	}
	echo '</div></section>';
};
$momos_label = static function ( string $text ): void {
	echo '<span class="momo-sg-label" lang="en" dir="ltr">' . esc_html( $text ) . '</span>';
};

get_header();
get_template_part(
	'template-parts/frame/page-banner',
	null,
	[
		'title'       => 'Design system — living style guide',
		'eyebrow'     => 'Staff only · noindex · SAMPLE data',
		'lead'        => 'Doc 08 / 08A components on the UI-001 visual baseline (DEC-057). Each component is shown in the page language and in a language of the opposite direction. Use the language switcher to review all six scripts.',
		'breadcrumbs' => false,
	]
);
?>
<div class="momo-container" lang="en" dir="ltr">
	<nav class="momo-sg-nav" aria-label="Style guide sections">
		<ul class="momo-chips momo-chips--scroll">
			<?php foreach ( [ 'tokens' => 'Tokens', 'icons' => 'Icons', 'buttons' => 'Buttons & links', 'chips' => 'Chips, tabs, filters', 'badges' => 'Badges & labels', 'feedback' => 'Notices & alerts', 'forms' => 'Forms', 'cards' => 'Cards', 'dish' => 'Dish cards', 'branch' => 'Branch cards', 'offer' => 'Offer card', 'trust' => 'Claims & allergens', 'data' => 'Map & status', 'states' => 'Empty, error, skeleton', 'nav' => 'Breadcrumbs & pagination', 'overlays' => 'Dialog & toast' ] as $momos_k => $momos_t ) : ?>
				<li><a class="momo-chip momo-chip--link" href="#sg-<?php echo esc_attr( $momos_k ); ?>"><?php echo esc_html( $momos_t ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<section class="momo-sg-section" id="sg-tokens" aria-labelledby="sg-tokens-h">
		<h2 class="momo-h3" id="sg-tokens-h">Tokens (tokens.css §3.1)</h2>
		<span class="momo-sg-label">Semantic colours</span>
		<div class="momo-sg-swatches">
			<?php foreach ( [ 'canvas', 'surface', 'surface-raised', 'tint', 'text', 'text-muted', 'action', 'action-hover', 'accent-text', 'brand', 'brand-tint', 'decor', 'gold-text', 'border', 'border-field', 'error', 'error-tint', 'warning-tint', 'warning-text', 'neutral-tint', 'focus' ] as $momos_c ) : ?>
				<div class="momo-sg-swatch"><span style="background:var(--momo-color-<?php echo esc_attr( $momos_c ); ?>)"></span><code>--momo-color-<?php echo esc_html( $momos_c ); ?></code></div>
			<?php endforeach; ?>
		</div>
		<span class="momo-sg-label">Type scale (page language)</span>
		<div class="momo-sg-stack" lang="<?php echo esc_attr( $momos_page_lang ); ?>">
			<p class="momo-display"><?php echo esc_html( momos_s( 'cta.request_table' ) ); ?></p>
			<p class="momo-h1"><?php echo esc_html( momos_s( '404.title' ) ); ?></p>
			<p class="momo-h2"><?php echo esc_html( momos_s( 'nav.branches' ) ); ?></p>
			<p class="momo-h3"><?php echo esc_html( momos_s( 'cta.halal_info' ) ); ?></p>
			<p class="momo-title"><?php echo esc_html( Samples::text( 'dish', $momos_page_lang ) ); ?></p>
			<p class="momo-lead"><?php echo esc_html( Samples::text( 'dish_desc', $momos_page_lang ) ); ?></p>
			<p><?php echo esc_html( Samples::text( 'notice', $momos_page_lang ) ); ?></p>
			<p class="momo-small momo-muted"><?php echo esc_html( momos_s( 'allergen.tell_staff' ) ); ?></p>
			<span class="momo-eyebrow"><?php echo esc_html( Samples::text( 'category', $momos_page_lang ) ); ?></span>
		</div>
	</section>

	<section class="momo-sg-section" id="sg-icons" aria-labelledby="sg-icons-h">
		<h2 class="momo-h3" id="sg-icons-h">Icons (sprite.svg; directional icons mirror in RTL)</h2>
		<ul class="momo-sg-icons">
			<?php foreach ( [ 'home', 'menu', 'store', 'map-pin', 'phone', 'clock', 'calendar', 'users', 'globe', 'chevron-start', 'chevron-end', 'chevron-down', 'arrow-start', 'arrow-end', 'external-link', 'copy', 'check', 'check-circle', 'x', 'x-circle', 'slash-circle', 'clock-x', 'clock-alert', 'hourglass', 'info', 'alert-triangle', 'alert-circle', 'flag', 'mail', 'mail-x', 'user', 'user-check', 'user-x', 'image', 'lock', 'chili-filled', 'chili-outline', 'leaf', 'sprout', 'wine', 'wine-off', 'filter', 'plus', 'minus', 'directions', 'shield-check', 'spinner', 'utensils', 'allergen-contains', 'allergen-may', 'allergen-not', 'allergen-unverified' ] as $momos_i ) : ?>
				<li><?php momos_the_icon( $momos_i, 'momo-icon--lg' ); ?><code><?php echo esc_html( $momos_i ); ?></code></li>
			<?php endforeach; ?>
		</ul>
	</section>
</div>

<div class="momo-container">
<?php
$momos_section(
	'Buttons & links (CMP-Button)',
	'buttons',
	static function ( string $l ) use ( $momos_label ): void {
		$momos_label( 'primary · secondary · text · icon · lg · sm · busy · disabled' );
		echo '<div class="momo-sg-row">';
		momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => '#', 'variant' => 'primary', 'icon' => 'calendar' ] );
		momos_component( 'button', [ 'label' => momos_s( 'cta.find_branch' ), 'url' => '#', 'variant' => 'secondary', 'icon' => 'store' ] );
		momos_component( 'button', [ 'label' => momos_s( 'cta.details' ), 'url' => '#', 'variant' => 'text' ] );
		momos_component( 'button', [ 'label' => momos_s( 'a11y.close' ), 'variant' => 'icon', 'icon' => 'x', 'aria_label' => momos_s( 'a11y.close' ) ] );
		echo '</div><div class="momo-sg-row">';
		momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => '#', 'variant' => 'primary', 'size' => 'lg' ] );
		momos_component( 'button', [ 'label' => momos_s( 'cta.see_branches' ), 'url' => '#', 'variant' => 'secondary', 'size' => 'sm', 'icon_end' => 'arrow-end' ] );
		momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'busy' => true, 'busy_label' => momos_s( 'a11y.loading' ), 'variant' => 'primary' ] );
		momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'disabled' => true, 'variant' => 'primary' ] );
		echo '</div><div class="momo-sg-row">';
		momos_component( 'button', [ 'label' => momos_s( 'cta.open_maps' ), 'url' => 'https://www.google.com/maps', 'variant' => 'secondary', 'icon' => 'map-pin', 'external' => true ] );
		momos_component( 'link', [ 'label' => momos_s( 'offers.see_all' ), 'url' => '#', 'more' => true ] );
		momos_component( 'link', [ 'label' => momos_s( 'cta.open_maps' ), 'url' => 'https://www.google.com/maps', 'external' => true ] );
		echo '</div><div class="momo-band momo-on-dark momo-sg-row" style="padding:16px;border-radius:18px">';
		momos_component( 'button', [ 'label' => momos_s( 'cta.request_table' ), 'url' => '#', 'variant' => 'primary' ] );
		momos_component( 'button', [ 'label' => momos_s( 'cta.view_menu' ), 'url' => '#', 'variant' => 'on-dark' ] );
		echo '</div>';
	}
);

$momos_section(
	'Chips, tabs and filters (CMP-Chip / ChipGroup)',
	'chips',
	static function ( string $l ) use ( $momos_label ): void {
		$momos_label( 'link chips (category tabs; current = aria-current; scroll row on mobile)' );
		momos_component( 'chip-group', [ 'type' => 'link', 'label' => momos_s( 'nav.menu' ), 'scroll' => true, 'items' => [
			[ 'label' => Samples::text( 'category', $l ), 'url' => '#', 'current' => true ],
			[ 'label' => momos_s( 'nav.menu' ), 'url' => '#' ],
			[ 'label' => momos_s( 'cta.halal_info' ), 'url' => '#' ],
			[ 'label' => momos_s( 'nav.offers' ), 'url' => '#' ],
			[ 'label' => momos_s( 'nav.gallery' ), 'url' => '#' ],
		] ] );
		$momos_label( 'choice chips (radio) incl. disabled with reason' );
		momos_component( 'chip-group', [ 'type' => 'choice', 'name' => 'sg-choice-' . $l, 'label' => momos_s( 'branch.day.today' ), 'items' => [
			[ 'label' => '18:00', 'value' => '18', 'checked' => true ],
			[ 'label' => '18:30', 'value' => '1830' ],
			[ 'label' => '19:00', 'value' => '19', 'disabled' => true, 'reason' => '(' . momos_s( 'branch.closed_day' ) . ')' ],
		] ] );
		$momos_label( 'filter chips (checkbox)' );
		momos_component( 'chip-group', [ 'type' => 'filter', 'name' => 'sg-filter-' . $l, 'label' => momos_s( 'menu.filters.heading' ), 'items' => [
			[ 'label' => momos_s( 'spice.0' ), 'value' => 's0', 'checked' => true ],
			[ 'label' => momos_s( 'spice.1' ), 'value' => 's1' ],
			[ 'label' => momos_s( 'spice.2' ), 'value' => 's2' ],
		] ] );
		$momos_label( 'tag chips + concept tag' );
		momos_component( 'chip-group', [ 'type' => 'tag', 'label' => 'tags', 'items' => [ [ 'label' => Samples::text( 'category', $l ) ], [ 'label' => momos_s( 'offers.scope.all' ) ] ] ] );
	}
);

$momos_section(
	'Badges, status and labels',
	'badges',
	static function ( string $l ) use ( $momos_label ): void {
		$momos_label( 'reservation status badges (pill)' );
		echo '<div class="momo-sg-row">';
		foreach ( [ 'pending', 'confirmed', 'rejected', 'cancelled', 'expired', 'completed' ] as $momos_s ) {
			momos_component( 'badge', [ 'status' => $momos_s ] );
		}
		echo '</div>';
		$momos_label( 'status badge (block, C10/C11 headline)' );
		momos_component( 'badge', [ 'status' => 'pending', 'block' => true ] );
		$momos_label( 'allergen chips (shape + colour + text)' );
		echo '<div class="momo-sg-row">';
		foreach ( Samples::allergens( $l )['groups'] as $momos_g ) {
			foreach ( $momos_g['rows'] as $momos_r ) {
				momos_component( 'allergen-chip', [ 'state' => $momos_r['state'], 'name' => $momos_r['name'] ] );
			}
		}
		echo '</div>';
		$momos_label( 'dietary / spice / availability / advance order' );
		momos_component( 'dish-labels', [ 'card' => Samples::dish( $l ) + [ 'tags' => [ [ 'code' => 'vegetarian', 'label' => momos_s( 'menu.filter.diet' ) ] ] ] ] );
		$momos_label( 'open-now badge: open · temporarily closed · loading skeleton' );
		echo '<div class="momo-sg-row">';
		momos_component( 'open-status', [ 'status' => Samples::branch( $l )['open'] ] );
		momos_component( 'open-status', [ 'status' => Samples::branch( $l, true )['open'] ] );
		momos_component( 'open-status', [ 'loading' => true ] );
		echo '</div>';
		$momos_label( 'price (tax incl., LTR isolated) + SAMPLE marker' );
		echo '<div class="momo-sg-row">';
		momos_component( 'price', [ 'html' => Samples::dish( $l )['price_html'], 'sample' => true ] );
		echo '</div>';
	}
);

$momos_section(
	'Notice banner and alerts',
	'feedback',
	static function ( string $l ) use ( $momos_label ): void {
		$momos_label( 'notice — info (dismissible) / warning (branch card placement)' );
		momos_component( 'notice', [ 'severity' => 'info', 'title' => momos_s( 'a11y.notice' ), 'body' => Samples::text( 'notice', $l ), 'dismissible' => true, 'card' => true, 'id' => 9001 + ( 'ar' === $l ? 1 : 0 ) ] );
		echo '<div class="momo-mt-4"></div>';
		momos_component( 'notice', [ 'severity' => 'warning', 'body' => Samples::text( 'notice', $l ), 'card' => true, 'link_url' => '#', 'link_label' => momos_s( 'cta.details' ) ] );
		$momos_label( 'alerts — info / success / warning / error' );
		echo '<div class="momo-sg-stack">';
		momos_component( 'alert', [ 'type' => 'info', 'body' => momos_s( 'lang.not_available', [ 'language' => Lang::native( $l ) ] ) ] );
		momos_component( 'alert', [ 'type' => 'success', 'body' => momos_s( 'a11y.copied' ), 'role' => 'status' ] );
		momos_component( 'alert', [ 'type' => 'warning', 'body' => momos_s( 'allergen.banner.not_verified' ) ] );
		momos_component( 'alert', [ 'type' => 'error', 'title' => momos_s( 'common.error.title' ), 'body' => momos_s( 'common.error.body' ) ] );
		echo '</div>';
	}
);

$momos_section(
	'Form fields (CMP-Field, Choice, Stepper, ErrorSummary)',
	'forms',
	static function ( string $l ) use ( $momos_label ): void {
		echo '<div class="momo-sg-stack">';
		momos_component( 'error-summary', [ 'title' => momos_s( 'common.error.title' ), 'errors' => [ 'sg-email-' . $l => Samples::text( 'label_email', $l ) ] ] );
		momos_component( 'field', [ 'id' => 'sg-name-' . $l, 'label' => Samples::text( 'label_name', $l ), 'required' => true, 'autocomplete' => 'name' ] );
		momos_component( 'field', [ 'id' => 'sg-email-' . $l, 'type' => 'email', 'label' => Samples::text( 'label_email', $l ), 'required' => true, 'autocomplete' => 'email', 'value' => 'guest@example', 'error' => Samples::text( 'label_email', $l ) ] );
		momos_component( 'field', [ 'id' => 'sg-tel-' . $l, 'type' => 'tel', 'label' => Samples::text( 'label_phone', $l ), 'autocomplete' => 'tel', 'inputmode' => 'tel', 'help' => Samples::text( 'area', $l ) ] );
		momos_component( 'field', [ 'id' => 'sg-branch-' . $l, 'type' => 'select', 'label' => momos_s( 'menu.branch_select.label' ), 'options' => [ '' => momos_s( 'menu.branch_select.all' ), 'SMP' => Samples::text( 'branch', $l ) ] ] );
		momos_component( 'field', [ 'id' => 'sg-msg-' . $l, 'type' => 'textarea', 'label' => Samples::text( 'label_message', $l ), 'maxlength' => 80, 'value' => Samples::text( 'dish_desc', $l ) ] );
		momos_component( 'choice', [ 'id' => 'sg-consent-' . $l, 'name' => 'consent', 'label_html' => esc_html( momos_s( 'ftr.privacy' ) ) . ' <a href="#" target="_blank" rel="noopener">' . esc_html( momos_s( 'ftr.privacy' ) ) . '<span class="momo-visually-hidden"> ' . esc_html( momos_s( 'a11y.new_tab' ) ) . '</span></a>' ] );
		momos_component( 'stepper', [ 'id' => 'sg-party-' . $l, 'label' => momos_s( 'a11y.stepper.inc' ), 'min' => 1, 'max' => 8, 'value' => 2 ] );
		echo '</div>';
	}
);

$momos_section(
	'Generic cards (default · tint · pillar · link)',
	'cards',
	static function ( string $l ): void {
		echo '<div class="momo-grid momo-grid--2">';
		momos_component( 'card', [ 'title' => momos_s( 'cta.halal_info' ), 'text' => Samples::text( 'statement', $l ), 'icon' => 'shield-check' ] );
		momos_component( 'card', [ 'title' => momos_s( 'nav.menu' ), 'text' => Samples::text( 'dish_desc', $l ), 'variant' => 'tint' ] );
		momos_component( 'card', [ 'title' => momos_s( 'nav.branches' ), 'url' => '#', 'icon' => 'store', 'variant' => 'link', 'more' => momos_s( 'cta.see_branches' ) ] );
		momos_component( 'card', [ 'title' => momos_s( 'cta.request_table' ), 'url' => '#', 'icon' => 'calendar', 'variant' => 'link' ] );
		echo '</div>';
		echo '<div class="momo-mt-8">';
		momos_component( 'section-header', [ 'eyebrow' => momos_s( 'nav.offers' ), 'title' => momos_s( 'offers.title' ), 'lead' => momos_s( 'offers.intro' ), 'link' => [ 'label' => momos_s( 'offers.see_all' ), 'url' => '#' ], 'h' => 3 ] );
		momos_component( 'step-list', [ 'steps' => [ [ 'icon' => 'calendar', 'text' => momos_s( 'cta.request_table' ) ], [ 'icon' => 'store', 'text' => momos_s( 'nav.branches' ) ], [ 'icon' => 'mail', 'text' => momos_s( 'status.pending' ) ] ] ] );
		echo '</div>';
	}
);

$momos_section(
	'Dish cards (CMP-DishCard: list on mobile, plate grid ≥ 768 px)',
	'dish',
	static function ( string $l ): void {
		echo '<div class="momo-grid momo-grid--2 momo-grid--dishes">';
		momos_component( 'dish-card', [ 'card' => Samples::dish( $l ), 'eyebrow' => Samples::text( 'category', $l ), 'sample' => true ] );
		momos_component( 'dish-card', [ 'card' => array_merge( Samples::dish( $l, 0, 'available' ), [ 'advance_order_label' => null ] ), 'eyebrow' => Samples::text( 'category', $l ), 'sample' => true ] );
		echo '</div>';
	}
);

$momos_section(
	'Branch cards (light · on-dark · temporarily closed)',
	'branch',
	static function ( string $l ): void {
		echo '<div class="momo-sg-stack">';
		momos_component( 'branch-card', [ 'branch' => Samples::branch( $l ) ] );
		echo '<div class="momo-band" style="padding:16px;border-radius:24px">';
		momos_component( 'branch-card', [ 'branch' => Samples::branch( $l ), 'variant' => 'on-dark' ] );
		echo '</div>';
		momos_component( 'branch-card', [ 'branch' => Samples::branch( $l, true ) ] );
		echo '</div>';
	}
);

$momos_section(
	'Offer card (CMP-OfferCard)',
	'offer',
	static function ( string $l ): void {
		momos_component( 'offer-card', [ 'offer' => Samples::offer( $l ) ] );
	}
);

$momos_section(
	'Claim statement block and allergen table',
	'trust',
	static function ( string $l ) use ( $momos_label ): void {
		$momos_label( 'brand / no-claim statement (template text from Dietary ClaimResolver)' );
		momos_component( 'claim-statement', [ 'claim' => Samples::claim( $l ), 'context' => 'brand', 'link' => true ] );
		$momos_label( 'certified layout (SAMPLE values; mark only when the resolver supplies one)' );
		momos_component( 'claim-statement', [ 'claim' => Samples::claim( $l, true ), 'context' => 'branch' ] );
		$momos_label( 'dish line' );
		momos_component( 'claim-statement', [ 'claim' => Samples::claim( $l ), 'context' => 'dish' ] );
		$momos_label( 'allergen table' );
		momos_component( 'allergen-table', [ 'data' => Samples::allergens( $l ), 'id' => 'sg-allergens-' . $l ] );
	}
);

$momos_section(
	'Map facade (no Maps JS on load)',
	'data',
	static function ( string $l ): void {
		momos_component( 'map-facade', [ 'name' => Samples::text( 'branch', $l ), 'maps_url' => 'https://www.google.com/maps/search/?api=1&query=34.6937,135.5023', 'directions_url' => Samples::branch( $l )['directions'], 'branch_code' => 'SMP' ] );
	}
);

$momos_section(
	'Empty, error and loading states',
	'states',
	static function ( string $l ) use ( $momos_label ): void {
		momos_component( 'empty-state', [ 'title' => momos_s( 'offers.empty' ), 'action' => [ 'label' => momos_s( 'cta.view_menu' ), 'url' => '#' ] ] );
		momos_component( 'empty-state', [ 'variant' => 'error', 'title' => momos_s( 'common.error.title' ), 'body' => momos_s( 'common.error.body' ), 'action' => [ 'label' => momos_s( 'common.retry' ), 'retry' => true ] ] );
		$momos_label( 'skeleton (sized like final content, no shimmer)' );
		momos_component( 'skeleton', [ 'shape' => 'dish', 'count' => 1 ] );
	}
);

$momos_section(
	'Breadcrumbs and pagination',
	'nav',
	static function ( string $l ): void {
		momos_component( 'breadcrumbs', [ 'items' => [ [ 'name' => momos_s( 'bc.home' ), 'url' => '#' ], [ 'name' => momos_s( 'nav.menu' ), 'url' => '#' ], [ 'name' => Samples::text( 'category', $l ), 'url' => '#' ], [ 'name' => Samples::text( 'dish', $l ) ] ] ] );
		echo '<div class="momo-mt-8"></div>';
		momos_component( 'pagination', [ 'current' => 3, 'total' => 9, 'url' => '#page-%d' ] );
	}
);

$momos_section(
	'Dialog / bottom sheet and toast',
	'overlays',
	static function ( string $l ): void {
		echo '<div class="momo-sg-row">';
		momos_component( 'button', [ 'label' => momos_s( 'a11y.menu.open' ), 'variant' => 'secondary', 'attrs' => [ 'data-momo-dialog-open' => 'sg-dialog-' . $l, 'aria-haspopup' => 'dialog' ] ] );
		momos_component( 'toast', [ 'label' => momos_s( 'a11y.copy' ), 'message' => momos_s( 'branch.copied' ) ] );
		echo '</div>';
		momos_component( 'dialog', [ 'id' => 'sg-dialog-' . $l, 'title' => momos_s( 'cta.request_table' ), 'body' => Samples::text( 'notice', $l ), 'primary' => [ 'label' => momos_s( 'cta.request_table' ), 'url' => '#' ] ] );
	}
);
?>
</div>
<?php
get_footer();
