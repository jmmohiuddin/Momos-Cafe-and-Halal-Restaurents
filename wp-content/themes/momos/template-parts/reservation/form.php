<?php
/**
 * CMP-ReservationForm — SCR-C09 fields 4–19 (reservation.md §6.1, screens.md §3.9). Server-rendered
 * shell; dates, times and party sizes come from API-01 and are rendered by reservation-form.js.
 * `novalidate` + localised inline errors and a focused error summary. The form is rendered visible so
 * the page does not shift when the script initialises (NFR-PERF-01, CLS ≤ 0.1); its submit button stays
 * disabled until the script has run, so it can never be submitted natively. JavaScript is required
 * (pickers + Turnstile): without it a `<head>` `<noscript>` style hides the form and the page's
 * `<noscript>` block lists the phones (CustomerPages::noscript_style).
 *
 * @package momos
 * @var array<string,mixed> $args CustomerPages::form_view()
 */

defined( 'ABSPATH' ) || exit;

$momos_v    = $args;
$momos_lang = (string) $momos_v['lang'];
$momos_pre  = (array) $momos_v['prefill'];

/** Hidden inline error slot of a field (filled by the script; `aria-describedby` points to it). */
$momos_err = static function ( string $id ): void {
	echo '<span class="momo-field__error" id="' . esc_attr( $id ) . '-error" hidden>' . momos_icon( 'alert-circle', 'momo-icon--sm' ) . '<span><span class="momo-visually-hidden">' . esc_html( momos_s( 'form.error_prefix' ) ) . ' </span><span data-res-msg></span></span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput
};
$momos_req = static fn( bool $required ): string => '<span class="momo-field__req">' . esc_html( momos_s( $required ? 'form.required' : 'form.optional' ) ) . '</span>';

$momos_new_tab = '<span class="momo-visually-hidden"> ' . esc_html( momos_s( 'a11y.new_tab' ) ) . '</span>';
$momos_link    = static fn( ?string $url, string $label ): string => null !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . $momos_new_tab . '</a>' : esc_html( $label );
// Consent label: both policy links in the page language (new tab, labelled). The catalog sentence is
// split only at the policy names it contains, never concatenated from fragments.
// A policy whose name is not found verbatim in the sentence is linked right after it instead.
$momos_consent = esc_html( momos_s( 'res.label.consent' ) );
$momos_extra   = [];
foreach ( [ [ 'ftr.privacy', $momos_v['privacy_url'] ], [ 'ftr.res_policy', $momos_v['res_policy_url'] ] ] as [ $momos_k, $momos_u ] ) {
	$momos_name = momos_s( $momos_k );
	if ( null === $momos_u || '' === $momos_name ) {
		continue;
	}
	if ( str_contains( $momos_consent, esc_html( $momos_name ) ) ) {
		$momos_consent = (string) preg_replace( '/' . preg_quote( esc_html( $momos_name ), '/' ) . '/u', str_replace( '$', '\\$', $momos_link( (string) $momos_u, $momos_name ) ), $momos_consent, 1 );
	} else {
		$momos_extra[] = $momos_link( (string) $momos_u, $momos_name );
	}
}
if ( $momos_extra ) {
	$momos_consent .= ' (' . implode( ' · ', $momos_extra ) . ')';
}
?>
<form class="momo-res-form" id="momo-res-form" novalidate data-momo-res-form aria-describedby="res-summary">
	<div class="momo-errsum" id="res-errsum" role="alert" tabindex="-1" hidden>
		<?php momos_the_icon( 'alert-circle' ); ?>
		<div>
			<h2 data-res-errsum-title></h2>
			<ul data-res-errsum-list></ul>
		</div>
	</div>

	<div class="momo-res-layout">
		<div class="momo-res-main momo-stack">
			<?php
			// TEST banner only for viewers who are offered the TEST branch at all (assigned staff, FR-RES-026 /
			// reservation spec §3.14); anonymous pages and unassigned staff carry no trace of it. It is shown
			// when the selected branch is TEST (prefill here, then reservation-form.js on every change).
			if ( array_filter( (array) $momos_v['branches'], static fn( $b ) => ! empty( $b['is_test'] ) ) ) :
				?>
			<div class="momo-alert momo-alert--warning" data-res-test-banner<?php echo empty( $momos_v['is_test'] ) ? ' hidden' : ''; ?>>
				<?php momos_the_icon( 'alert-triangle' ); ?>
				<div><p class="momo-strong"><?php echo esc_html( momos_s( 'res.test_banner' ) ); ?></p></div>
			</div>
			<?php endif; ?>

			<!-- 4. Branch -->
			<div class="momo-field" data-res-field="branch">
				<label class="momo-field__label" for="res-branch"><?php echo esc_html( momos_s( 'res.label.branch' ) ); ?> <?php echo $momos_req( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
				<select class="momo-input" id="res-branch" name="branch_code" required aria-describedby="res-branch-error res-branch-info">
					<option value=""><?php echo esc_html( momos_s( 'res.err.branch.required' ) ); ?></option>
					<?php foreach ( (array) $momos_v['branches'] as $momos_b ) : ?>
						<option value="<?php echo esc_attr( (string) $momos_b['code'] ); ?>"<?php selected( $momos_pre['branch'], (string) $momos_b['code'] ); ?><?php disabled( ! $momos_b['accepting'] ); ?>><?php echo esc_html( $momos_b['accepting'] ? (string) $momos_b['name'] : momos_s( 'res.branch.not_accepting', [ 'branch' => (string) $momos_b['name'] ] ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="momo-field__help" id="res-branch-info" role="status" data-res-branch-info></span>
				<?php $momos_err( 'res-branch' ); ?>
			</div>
			<?php
			// FR-BR-009(b): notices of the selected branch (page language only). Server-rendered for the
			// preselected branch; reservation-form.js re-renders them from the config on every change.
			$momos_sel = array_values( array_filter( (array) $momos_v['branches'], static fn( $b ) => '' !== (string) $momos_pre['branch'] && (string) $b['code'] === (string) $momos_pre['branch'] ) );
			$momos_nts = (array) ( $momos_sel[0]['notices'] ?? [] );
			?>
			<div class="momo-stack" data-res-notices role="group" aria-live="polite" aria-label="<?php echo esc_attr( momos_s( 'branch.notices.label' ) ); ?>"<?php echo $momos_nts ? '' : ' hidden'; ?>>
				<?php foreach ( $momos_nts as $momos_n ) : ?>
					<?php momos_component( 'notice', [ 'id' => (int) $momos_n['id'], 'severity' => (string) $momos_n['severity'], 'title' => (string) $momos_n['title'], 'body' => (string) $momos_n['body'], 'link_url' => (string) $momos_n['link_url'], 'link_label' => (string) $momos_n['link_label'], 'upcoming' => ! empty( $momos_n['upcoming'] ), 'start_ms' => $momos_n['start_ms'] ?? null, 'end_ms' => $momos_n['end_ms'] ?? null, 'card' => true ] ); ?>
				<?php endforeach; ?>
			</div>

			<!-- 5. Date -->
			<fieldset class="momo-fieldset momo-field" data-res-field="date" id="res-date-group">
				<legend class="momo-field__label"><?php echo esc_html( momos_s( 'res.label.date' ) ); ?> <?php echo $momos_req( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></legend>
				<div class="momo-chips" data-res-date-chips hidden></div>
				<label class="momo-visually-hidden" for="res-date"><?php echo esc_html( momos_s( 'res.label.date' ) ); ?></label>
				<select class="momo-input" id="res-date" name="date" required aria-describedby="res-date-error" disabled>
					<option value=""><?php echo esc_html( momos_s( 'res.date.choose' ) ); ?></option>
				</select>
				<?php $momos_err( 'res-date' ); ?>
			</fieldset>

			<!-- 6. Time -->
			<fieldset class="momo-fieldset momo-field" data-res-field="time" id="res-time-group" aria-describedby="res-time-error">
				<legend class="momo-field__label"><?php echo esc_html( momos_s( 'res.label.time' ) ); ?> <?php echo $momos_req( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></legend>
				<div class="momo-res-skel" data-res-time-skel hidden aria-busy="true">
					<span class="momo-visually-hidden"><?php echo esc_html( momos_s( 'res.loading_times' ) ); ?></span>
					<span class="momo-skel momo-skel--text" aria-hidden="true"></span><span class="momo-skel momo-skel--text" aria-hidden="true"></span>
				</div>
				<div class="momo-res-times" data-res-times></div>
				<div role="status" data-res-no-times-region><?php // Always in the DOM so revealing the message is announced (WCAG 4.1.3, TC-A11Y-003). ?>
					<p class="momo-field__help" data-res-no-times hidden><?php echo esc_html( momos_s( 'res.info.no_times' ) ); ?></p>
				</div>
				<?php $momos_err( 'res-time' ); ?>
			</fieldset>

			<!-- 7. Party -->
			<fieldset class="momo-fieldset momo-field" data-res-field="party" id="res-party-group" aria-describedby="res-party-error">
				<legend class="momo-field__label"><?php echo esc_html( momos_s( 'res.label.party' ) ); ?> <?php echo $momos_req( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></legend>
				<div class="momo-chips" data-res-party-chips></div>
				<div class="momo-stepper" data-res-party-stepper hidden>
					<button type="button" class="momo-btn momo-btn--icon" data-step="-1" aria-label="<?php echo esc_attr( momos_s( 'a11y.stepper.dec' ) ); ?>" aria-controls="res-party"><?php momos_the_icon( 'minus' ); ?></button>
					<input class="momo-input" id="res-party" type="text" inputmode="numeric" pattern="[0-9]*" dir="ltr" autocomplete="off" aria-label="<?php echo esc_attr( momos_s( 'res.label.party' ) ); ?>" aria-describedby="res-party-error">
					<button type="button" class="momo-btn momo-btn--icon" data-step="1" aria-label="<?php echo esc_attr( momos_s( 'a11y.stepper.inc' ) ); ?>" aria-controls="res-party"><?php momos_the_icon( 'plus' ); ?></button>
				</div>
				<button type="button" class="momo-btn momo-btn--text momo-res-more" data-res-party-more aria-expanded="false" aria-controls="res-party-call" hidden></button>
				<div class="momo-alert momo-alert--info" id="res-party-call" data-res-party-call hidden>
					<?php momos_the_icon( 'phone' ); ?>
					<div><p data-res-msg></p></div>
				</div>
				<?php $momos_err( 'res-party' ); ?>
			</fieldset>

			<!-- 8–10. Name, email, phone -->
			<div class="momo-form__grid momo-res-contact">
				<div class="momo-field" data-res-field="name">
					<label class="momo-field__label" for="res-name"><?php echo esc_html( momos_s( 'res.label.name' ) ); ?> <?php echo $momos_req( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
					<span class="momo-field__help" id="res-name-help"><?php echo esc_html( momos_s( 'res.hint.name' ) ); ?></span>
					<input class="momo-input" id="res-name" name="name" type="text" autocomplete="name" dir="auto" maxlength="100" required aria-describedby="res-name-help res-name-error">
					<?php $momos_err( 'res-name' ); ?>
				</div>
				<div class="momo-field" data-res-field="email">
					<label class="momo-field__label" for="res-email"><?php echo esc_html( momos_s( 'res.label.email' ) ); ?> <?php echo $momos_req( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
					<span class="momo-field__help" id="res-email-help"><?php echo esc_html( momos_s( 'res.hint.email' ) ); ?></span>
					<input class="momo-input" id="res-email" name="email" type="email" inputmode="email" autocomplete="email" dir="ltr" maxlength="254" required aria-describedby="res-email-help res-email-error">
					<?php $momos_err( 'res-email' ); ?>
				</div>
				<div class="momo-field" data-res-field="phone">
					<label class="momo-field__label" for="res-phone"><?php echo esc_html( momos_s( 'res.label.phone' ) ); ?></label>
					<span class="momo-field__help" id="res-phone-help"><?php echo esc_html( momos_s( 'res.hint.phone' ) ); ?></span>
					<input class="momo-input" id="res-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" maxlength="32" aria-describedby="res-phone-help res-phone-error">
					<?php $momos_err( 'res-phone' ); ?>
				</div>
			</div>

			<!-- 11. Special request -->
			<div class="momo-field" data-res-field="note">
				<label class="momo-field__label" for="res-note"><?php echo esc_html( momos_s( 'res.label.note' ) ); ?></label>
				<span class="momo-field__help" id="res-note-help"><?php echo esc_html( momos_s( 'res.hint.note' ) ); ?></span>
				<textarea class="momo-input" id="res-note" name="special_request" rows="4" dir="auto" maxlength="1000" data-momo-counter="1000" aria-describedby="res-note-help res-note-error"></textarea>
				<span class="momo-field__counter" aria-live="polite" data-momo-counter-for="res-note" data-template="<?php echo esc_attr( momos_s( 'form.counter' ) ); ?>" hidden></span>
				<?php $momos_err( 'res-note' ); ?>
			</div>
			<?php if ( ! empty( $momos_v['separate_consent'] ) ) : ?>
				<div class="momo-field" data-res-field="special_consent">
					<label class="momo-choice" for="res-special-consent">
						<input type="checkbox" id="res-special-consent" name="special_request_consent" value="1" aria-describedby="res-special-consent-error">
						<span><?php echo esc_html( momos_s( 'res.label.special_consent' ) ); ?></span>
					</label>
					<?php $momos_err( 'res-special-consent' ); ?>
				</div>
			<?php endif; ?>

			<!-- 12. Reply language -->
			<div class="momo-field" data-res-field="lang">
				<label class="momo-field__label" for="res-lang"><?php echo esc_html( momos_s( 'res.label.lang' ) ); ?></label>
				<select class="momo-input" id="res-lang" name="preferred_lang" aria-describedby="res-lang-error">
					<?php foreach ( (array) $momos_v['langs'] as $momos_code => $momos_native ) : ?>
						<option value="<?php echo esc_attr( (string) $momos_code ); ?>" lang="<?php echo esc_attr( (string) $momos_code ); ?>"<?php selected( $momos_lang, (string) $momos_code ); ?>><?php echo esc_html( (string) $momos_native ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php $momos_err( 'res-lang' ); ?>
			</div>

			<!-- 13. Policy text (only when the branch policy page is published in this language, DEC-054) -->
			<p class="momo-small" data-res-policy hidden><a href="#" target="_blank" rel="noopener"><span data-res-msg></span><?php echo $momos_new_tab; // phpcs:ignore WordPress.Security.EscapeOutput ?></a></p>
		</div>

		<!-- 14. Live summary (FR-RES-027) -->
		<aside class="momo-card momo-card--tint momo-res-summary" id="res-summary" aria-labelledby="res-summary-title">
			<h2 class="momo-h3" id="res-summary-title"><?php echo esc_html( momos_s( 'res.summary.title' ) ); ?></h2>
			<dl class="momo-res-dl">
				<div><dt><?php echo esc_html( momos_s( 'res.label.branch' ) ); ?></dt><dd data-res-sum="branch"></dd></div>
				<div><dt><?php echo esc_html( momos_s( 'res.label.date' ) ); ?></dt><dd data-res-sum="date"></dd></div>
				<div><dt><?php echo esc_html( momos_s( 'res.label.time' ) ); ?></dt><dd data-res-sum="time"></dd></div>
				<div><dt><?php echo esc_html( momos_s( 'res.label.party' ) ); ?></dt><dd data-res-sum="party"></dd></div>
			</dl>
			<p class="momo-res-replyto" data-res-sum="email" hidden></p>
		</aside>

		<div class="momo-res-submit momo-stack">
			<!-- 15–16. Notice at collection + consent -->
			<p class="momo-small momo-muted"><?php echo esc_html( momos_s( 'res.notice.collection' ) ); ?></p>
			<div class="momo-field" data-res-field="consent">
				<label class="momo-choice" for="res-consent">
					<input type="checkbox" id="res-consent" name="consent" value="1" required aria-describedby="res-consent-error" data-consent-version="<?php echo esc_attr( (string) $momos_v['consent_version'] ); ?>">
					<span><?php echo wp_kses( $momos_consent, [ 'a' => [ 'href' => true, 'target' => true, 'rel' => true ], 'span' => [ 'class' => true ] ] ); ?></span>
				</label>
				<?php $momos_err( 'res-consent' ); ?>
			</div>
			<!-- 17. No payment -->
			<p class="momo-small momo-strong"><?php echo esc_html( momos_s( 'res.no_payment' ) ); ?></p>

			<div class="momo-hp" aria-hidden="true">
				<label for="res-website"><?php echo esc_html( momos_s( 'contact.website' ) ); ?></label>
				<input type="text" id="res-website" name="website" value="" tabindex="-1" autocomplete="off">
			</div>
			<div class="momo-res-captcha" id="res-captcha" data-res-captcha></div>

			<div class="momo-alert momo-alert--error" id="res-banner" data-res-banner role="alert" hidden>
				<?php momos_the_icon( 'alert-circle' ); ?>
				<div class="momo-stack">
					<p data-res-banner-msg></p>
					<p class="momo-small" data-res-banner-ref hidden></p>
					<p data-res-banner-actions hidden><a class="momo-btn momo-btn--secondary" data-res-banner-tel href="#"></a></p>
				</div>
			</div>

			<!-- 18–19. Submit + call alternative -->
			<button type="submit" class="momo-btn momo-btn--primary momo-btn--lg" data-res-submit disabled><?php momos_the_icon( 'send' ); ?><span data-res-submit-label><?php echo esc_html( momos_s( 'res.submit' ) ); ?></span></button>
			<p class="momo-small" data-res-call-alt hidden></p>
		</div>
	</div>
</form>
