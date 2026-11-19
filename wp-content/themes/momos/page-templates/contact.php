<?php
/**
 * SCR-C12 Contact (Should; screens.md §3.12, content-strings.md §8.5, FR-CNT-001, BR-CNT-01, DEC-038).
 * Banner · reservation hint (+ link C09) · form card (name, email, topic, branch (optional), message,
 * notice at collection, consent) · branch phones. Posts to `admin-post.php?action=momos_contact`
 * (Presentation\Contact\ContactForm: nonce, honeypot, rate limit, mail to Head Office, nothing stored).
 * States: error summary (`role="alert"`, focused) + inline errors from `?contact_error=` codes; success
 * `contact.success` (`role="status"`). Values are not echoed back after a redirect (nothing is stored).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_lang   = momos_theme_lang();
$momos_cf     = \Momos\Presentation\Contact\ContactForm::class;
$momos_errors = $momos_cf::error_codes();
$momos_sent   = isset( $_GET['sent'] ) && empty( $momos_errors ); // phpcs:ignore WordPress.Security.NonceVerification
$momos_brs    = class_exists( \Momos\Presentation\View\BranchLinks::class ) ? \Momos\Presentation\View\BranchLinks::all() : [];
$momos_field  = [ 'name' => 'contact-name', 'email' => 'contact-email', 'topic' => 'contact-topic', 'branch' => 'contact-branch', 'message' => 'contact-message', 'message_length' => 'contact-message', 'consent' => 'contact-consent' ];
$momos_msgs   = [];
foreach ( $momos_errors as $momos_code ) {
	$momos_msgs[ $momos_field[ $momos_code ] ?? 'contact-form' ] = momos_s( 'contact.err.' . $momos_code );
}
$momos_err   = static fn( string $f ): string => (string) ( $momos_msgs[ $momos_field[ $f ] ] ?? '' );
$momos_pp    = class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::legal_url( 'privacy' ) : null;
$momos_topic = [ '' => momos_s( 'contact.topic.choose' ) ];
foreach ( $momos_cf::TOPICS as $momos_t ) {
	$momos_topic[ $momos_t ] = momos_s( 'contact.topic.' . $momos_t );
}
$momos_branch_opts = [ '' => momos_s( 'contact.branch.none' ) ];
foreach ( $momos_brs as $momos_b ) {
	$momos_branch_opts[ (string) $momos_b['code'] ] = (string) $momos_b['name'];
}
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => momos_s( 'nav.contact' ), 'lead' => momos_s( 'contact.lead' ) ] );
?>
<div class="momo-container momo-section momo-contact">
	<div class="momo-contact__main momo-stack">
		<div class="momo-alert momo-alert--info" role="note">
			<?php momos_the_icon( 'calendar' ); ?>
			<p><?php echo esc_html( momos_s( 'contact.res_hint' ) ); ?> <a href="<?php echo esc_url( momos_page_url( 'reservation' ) ); ?>"><?php echo esc_html( momos_s( 'cta.request_table' ) ); ?></a></p>
		</div>

		<div id="contact-status">
			<?php if ( $momos_sent ) : ?>
				<?php momos_component( 'alert', [ 'type' => 'success', 'body' => momos_s( 'contact.success' ), 'role' => 'status' ] ); ?>
			<?php endif; ?>
		</div>

		<form class="momo-card momo-form" id="contact-form" method="post" action="<?php echo esc_url( $momos_cf::action_url() ); ?>" novalidate data-momo-busy-form>
			<h2><?php echo esc_html( momos_s( 'contact.form.title' ) ); ?></h2>
			<div id="contact-errors">
				<?php momos_component( 'error-summary', [ 'title' => momos_s( 'contact.err.summary' ), 'errors' => $momos_msgs ] ); ?>
			</div>
			<input type="hidden" name="action" value="<?php echo esc_attr( $momos_cf::ACTION ); ?>">
			<input type="hidden" name="lang" value="<?php echo esc_attr( $momos_lang ); ?>">
			<?php wp_nonce_field( $momos_cf::NONCE, '_momos_nonce', false ); ?>
			<div class="momo-form__grid">
				<?php
				momos_component( 'field', [ 'id' => 'contact-name', 'name' => 'name', 'label' => momos_s( 'contact.label.name' ), 'required' => true, 'autocomplete' => 'name', 'maxlength' => $momos_cf::NAME_MAX, 'error' => $momos_err( 'name' ) ] );
				momos_component( 'field', [ 'id' => 'contact-email', 'name' => 'email', 'type' => 'email', 'label' => momos_s( 'contact.label.email' ), 'help' => momos_s( 'contact.help.email' ), 'required' => true, 'autocomplete' => 'email', 'inputmode' => 'email', 'error' => $momos_err( 'email' ), 'attrs' => [ 'maxlength' => (string) $momos_cf::EMAIL_MAX ] ] );
				momos_component( 'field', [ 'id' => 'contact-topic', 'name' => 'topic', 'type' => 'select', 'label' => momos_s( 'contact.label.topic' ), 'required' => true, 'options' => $momos_topic, 'error' => $momos_err( 'topic' ) ] );
				if ( count( $momos_branch_opts ) > 1 ) {
					momos_component( 'field', [ 'id' => 'contact-branch', 'name' => 'branch', 'type' => 'select', 'label' => momos_s( 'contact.label.branch' ), 'options' => $momos_branch_opts, 'error' => $momos_err( 'branch' ) ] );
				}
				?>
			</div>
			<?php momos_component( 'field', [ 'id' => 'contact-message', 'name' => 'message', 'type' => 'textarea', 'rows' => 6, 'label' => momos_s( 'contact.label.message' ), 'required' => true, 'maxlength' => $momos_cf::MESSAGE_MAX, 'error' => $momos_err( 'message' ) ] ); ?>
			<div class="momo-hp" aria-hidden="true">
				<label for="contact-website"><?php echo esc_html( momos_s( 'contact.website' ) ); ?></label>
				<input type="text" id="contact-website" name="website" value="" tabindex="-1" autocomplete="off">
			</div>
			<p class="momo-small momo-muted"><?php echo esc_html( momos_s( 'notice.contact' ) ); ?></p>
			<?php
			$momos_consent = null !== $momos_pp
				? strtr( esc_html( momos_s( 'consent.contact.link' ) ), [ '{link}' => '<a href="' . esc_url( $momos_pp ) . '" target="_blank" rel="noopener">' . esc_html( momos_s( 'ftr.privacy' ) ) . '<span class="momo-visually-hidden"> ' . esc_html( momos_s( 'a11y.new_tab' ) ) . '</span></a>' ] )
				: esc_html( momos_s( 'consent.contact' ) );
			momos_component( 'choice', [ 'id' => 'contact-consent', 'name' => 'consent', 'label_html' => $momos_consent, 'required' => true, 'error' => $momos_err( 'consent' ) ] );
			// Turnstile (managed, WCAG 3.3.8) — FR-SEC-006; test mode posts a test token and loads nothing.
			$momos_cap = $momos_cf::captcha();
			if ( is_array( $momos_cap ) ) {
				echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( $momos_cap['site_key'] ) . '" data-action="' . esc_attr( $momos_cap['action'] ) . '" data-language="' . esc_attr( $momos_lang ) . '" data-appearance="interaction-only"></div>';
				if ( 'test' === $momos_cap['mode'] ) {
					echo '<input type="hidden" name="' . esc_attr( $momos_cap['field'] ) . '" value="test-pass">';
				}
			}
			momos_component( 'button', [ 'label' => momos_s( 'contact.submit' ), 'busy_label' => momos_s( 'contact.submit.busy' ), 'type' => 'submit', 'variant' => 'primary', 'size' => 'lg', 'icon' => 'send', 'attrs' => [ 'data-busy-label' => momos_s( 'contact.submit.busy' ) ] ] );
			?>
		</form>
	</div>

	<?php if ( array_filter( $momos_brs, static fn( $b ) => '' !== $b['tel'] ) ) : ?>
		<aside class="momo-card momo-card--tint momo-contact__phones" aria-labelledby="contact-phones">
			<h2 id="contact-phones" class="momo-h3"><?php echo esc_html( momos_s( 'contact.phones.title' ) ); ?></h2>
			<ul class="momo-list-reset momo-stack">
				<?php foreach ( $momos_brs as $momos_b ) : ?>
					<?php if ( '' !== $momos_b['tel'] ) : ?>
						<li>
							<span class="momo-strong"><?php echo esc_html( (string) $momos_b['name'] ); ?></span><br>
							<a class="momo-link" href="<?php echo esc_attr( (string) $momos_b['tel'] ); ?>" data-momo-event="click_phone" data-momo-cta="footer" data-momo-branch="<?php echo esc_attr( (string) $momos_b['code'] ); ?>"><?php momos_the_icon( 'phone', 'momo-icon--sm' ); ?><?php echo momos_ltr( (string) $momos_b['phone'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</aside>
	<?php endif; ?>
</div>
<?php
get_footer();
