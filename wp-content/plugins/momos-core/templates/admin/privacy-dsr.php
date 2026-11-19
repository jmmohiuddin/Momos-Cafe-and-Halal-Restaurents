<?php
/**
 * SCR-A19 Data-subject requests (Access spec §10.5, §13.1). Logical CSS properties only (RTL-safe).
 *
 * @var string                     $token
 * @var array{by:string,ids:list<int>}|null $result
 * @var list<array<string,mixed>>  $view
 * @var array<string,mixed>|null   $notice
 * @var string                     $lang     admin language for momos_t() keys (ja|en)
 * @var array<string,mixed>        $retention last retention run
 * @var list<array<string,mixed>>  $consent  consent version history
 * @var string                     $current  key stored on new reservations
 * @var string                     $in_force registry version in force (latest effective_from ≤ now)
 * @var list<string>               $langs
 */

use Momos\Kernel\Clock\Clock;
use Momos\Privacy\Admin\DsrScreen;

defined( 'ABSPATH' ) || exit;

$post_url = admin_url( 'admin-post.php' );
?>
<style>
.momos-dsr .momos-dsr-search { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-block: 16px; }
.momos-dsr .momos-dsr-field { display: flex; flex-direction: column; gap: 4px; margin: 0; }
.momos-dsr .momos-dsr-field label { font-weight: 600; }
.momos-dsr input[type="text"], .momos-dsr input[type="email"], .momos-dsr select, .momos-dsr .button { min-block-size: 44px; }
.momos-dsr .momos-dsr-search input[type="text"] { inline-size: min(28rem, 100%); max-inline-size: 100%; }
.momos-dsr .momos-dsr-ref { unicode-bidi: isolate; direction: ltr; font-family: monospace; }
.momos-dsr fieldset { border: 1px solid #c3c4c7; padding-block: 8px 16px; padding-inline: 16px; margin-block: 16px; background: #fff; }
.momos-dsr legend { font-weight: 600; padding-inline: 4px; }
.momos-dsr .momos-dsr-fields { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
.momos-dsr .momos-dsr-anon details { margin-block: 8px; border: 1px solid #dcdcde; padding-block: 8px; padding-inline: 12px; background: #fff; }
.momos-dsr .momos-dsr-anon summary { cursor: pointer; font-weight: 600; min-block-size: 32px; }
.momos-dsr .momos-dsr-warn { color: #8a1f1f; font-weight: 600; }
.momos-dsr .momos-dsr-info { margin-block-start: 32px; }
.momos-dsr table.widefat td, .momos-dsr table.widefat th { vertical-align: middle; }
</style>
<div class="wrap momos-admin momos-dsr">
	<h1><?php esc_html_e( 'Data requests', 'momos-core' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Find, export, correct or anonymise the reservation data of one person. Search by the exact email address or a reservation reference. Every step is recorded in the audit log (without the searched value).', 'momos-core' ); ?></p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>" <?php echo 'error' === $notice['type'] ? 'role="alert"' : 'role="status"'; ?>>
			<p><?php echo esc_html( $notice['text'] ); ?>
			<?php if ( ! empty( $notice['link'] ) ) : ?>
				<a href="<?php echo esc_url( $notice['link'] ); ?>"><?php esc_html_e( 'Open the reservation to cancel it', 'momos-core' ); ?></a>
			<?php endif; ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="momos-dsr-search" role="search" autocomplete="off">
		<input type="hidden" name="action" value="momos_dsr_search">
		<?php wp_nonce_field( DsrScreen::NONCE, '_momos_dsr_nonce' ); ?>
		<p class="momos-dsr-field"><label for="momos-dsr-q"><?php esc_html_e( 'Email address or reservation reference', 'momos-core' ); ?></label>
			<input type="text" id="momos-dsr-q" name="q" required maxlength="254" spellcheck="false" autocapitalize="off" dir="ltr" aria-describedby="momos-dsr-q-help"></p>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Search', 'momos-core' ); ?></button>
		<p id="momos-dsr-q-help" class="description"><?php esc_html_e( 'Exact match only (no partial search). Anonymised reservations are not shown.', 'momos-core' ); ?></p>
	</form>

	<?php if ( null !== $result ) : ?>
		<h2><?php esc_html_e( 'Search result', 'momos-core' ); ?></h2>
		<?php if ( ! $view ) : ?>
			<p><?php echo esc_html( momos_t( 'admin.dsr.empty', [], $lang ) ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $post_url ); ?>" autocomplete="off">
				<?php wp_nonce_field( DsrScreen::NONCE, '_momos_dsr_nonce' ); ?>
				<input type="hidden" name="r" value="<?php echo esc_attr( $token ); ?>">
				<table class="widefat striped">
					<caption class="screen-reader-text"><?php esc_html_e( 'Reservations found', 'momos-core' ); ?></caption>
					<thead><tr>
						<td class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Select', 'momos-core' ); ?></span></td>
						<th scope="col"><?php esc_html_e( 'Reference', 'momos-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Branch', 'momos-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Requested date and time (JST)', 'momos-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'momos-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email language', 'momos-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created (JST)', 'momos-core' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $view as $v ) : ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo (int) $v['id']; ?>" id="momos-dsr-id-<?php echo (int) $v['id']; ?>" checked></th>
							<td><label for="momos-dsr-id-<?php echo (int) $v['id']; ?>"><span class="momos-dsr-ref"><?php echo esc_html( $v['ref'] ); ?></span></label></td>
							<td><?php echo esc_html( $v['branch'] ); ?></td>
							<td><?php echo esc_html( $v['when'] ); ?></td>
							<td><?php echo esc_html( $v['status'] ); ?></td>
							<td><?php echo esc_html( $v['lang'] ); ?></td>
							<td><?php echo esc_html( $v['created'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<fieldset>
					<legend><?php esc_html_e( 'Correct selected', 'momos-core' ); ?></legend>
					<p class="description"><?php esc_html_e( 'Only the fields you fill in are changed. Values are validated like the reservation form.', 'momos-core' ); ?></p>
					<div class="momos-dsr-fields">
						<p class="momos-dsr-field"><label for="momos-dsr-name"><?php esc_html_e( 'Name', 'momos-core' ); ?></label><input type="text" id="momos-dsr-name" name="name" maxlength="100"></p>
						<p class="momos-dsr-field"><label for="momos-dsr-email"><?php esc_html_e( 'Email', 'momos-core' ); ?></label><input type="email" id="momos-dsr-email" name="email" maxlength="254" dir="ltr"></p>
						<p class="momos-dsr-field"><label for="momos-dsr-phone"><?php esc_html_e( 'Phone', 'momos-core' ); ?></label><input type="text" id="momos-dsr-phone" name="phone" maxlength="30" dir="ltr" inputmode="tel"></p>
						<p class="momos-dsr-field"><label for="momos-dsr-lang"><?php esc_html_e( 'Email language', 'momos-core' ); ?></label>
							<select id="momos-dsr-lang" name="preferred_lang">
								<option value=""><?php esc_html_e( '— no change —', 'momos-core' ); ?></option>
								<?php foreach ( $langs as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( \Momos\Kernel\I18n\Lang::native( $code ) . ' (' . $code . ')' ); ?></option>
								<?php endforeach; ?>
							</select></p>
						<p class="momos-dsr-field"><label for="momos-dsr-creason"><?php esc_html_e( 'Reason', 'momos-core' ); ?></label>
							<select id="momos-dsr-creason" name="reason" required>
								<option value=""><?php esc_html_e( 'Choose…', 'momos-core' ); ?></option>
								<?php foreach ( DsrScreen::correct_reasons() as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></p>
						<p class="momos-dsr-field"><label for="momos-dsr-cref"><?php esc_html_e( 'Register reference (optional)', 'momos-core' ); ?></label><input type="text" id="momos-dsr-cref" name="request_ref" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9._/#-]{0,39}" dir="ltr"></p>
					</div>
					<button type="submit" class="button" name="action" value="momos_dsr_correct"><?php esc_html_e( 'Save corrections', 'momos-core' ); ?></button>
				</fieldset>

				<fieldset>
					<legend><?php esc_html_e( 'Export', 'momos-core' ); ?></legend>
					<p class="description"><?php esc_html_e( 'Downloads a JSON file with the selected reservations, consent, history and email metadata. The file is not stored on the server; hand it over securely.', 'momos-core' ); ?></p>
					<button type="submit" class="button" name="action" value="momos_dsr_export" formnovalidate><?php esc_html_e( 'Export selected (JSON)', 'momos-core' ); ?></button>
				</fieldset>
			</form>

			<section class="momos-dsr-anon" aria-labelledby="momos-dsr-anon-h">
				<h2 id="momos-dsr-anon-h"><?php esc_html_e( 'Anonymise', 'momos-core' ); ?></h2>
				<p class="description"><?php esc_html_e( 'One reservation per confirmation. Name, email, phone, special request, messages and notes are removed permanently; date, branch, party size and status are kept for statistics.', 'momos-core' ); ?></p>
				<?php foreach ( $view as $v ) : ?>
					<details>
						<summary><span class="momos-dsr-ref"><?php echo esc_html( $v['ref'] ); ?></span> — <?php echo esc_html( $v['branch'] . ' ' . $v['when'] ); ?></summary>
						<?php if ( $v['blocked'] ) : ?>
							<p class="notice notice-warning inline"><?php echo esc_html( momos_t( 'admin.dsr.blocked_active', [], $lang ) ); ?>
								<?php if ( '' !== $v['detail_url'] ) : ?>
									<a href="<?php echo esc_url( $v['detail_url'] ); ?>"><?php esc_html_e( 'Open the reservation to cancel it', 'momos-core' ); ?></a>
								<?php endif; ?></p>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" autocomplete="off">
								<input type="hidden" name="action" value="momos_dsr_anonymize">
								<input type="hidden" name="r" value="<?php echo esc_attr( $token ); ?>">
								<input type="hidden" name="id" value="<?php echo (int) $v['id']; ?>">
								<?php wp_nonce_field( DsrScreen::NONCE, '_momos_dsr_nonce' ); ?>
								<p class="momos-dsr-warn" id="momos-dsr-warn-<?php echo (int) $v['id']; ?>"><?php echo esc_html( momos_t( 'admin.dsr.confirm', [ 'ref' => $v['ref'] ], $lang ) ); ?></p>
								<div class="momos-dsr-fields">
									<p class="momos-dsr-field"><label for="momos-dsr-confirm-<?php echo (int) $v['id']; ?>"><?php esc_html_e( 'Reference', 'momos-core' ); ?></label>
										<input type="text" id="momos-dsr-confirm-<?php echo (int) $v['id']; ?>" name="confirm_ref" required maxlength="9" dir="ltr" spellcheck="false" autocapitalize="characters" aria-describedby="momos-dsr-warn-<?php echo (int) $v['id']; ?>"></p>
									<p class="momos-dsr-field"><label for="momos-dsr-areason-<?php echo (int) $v['id']; ?>"><?php esc_html_e( 'Reason', 'momos-core' ); ?></label>
										<select id="momos-dsr-areason-<?php echo (int) $v['id']; ?>" name="reason" required>
											<option value=""><?php esc_html_e( 'Choose…', 'momos-core' ); ?></option>
											<?php foreach ( DsrScreen::erase_reasons() as $k => $label ) : ?>
												<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select></p>
									<p class="momos-dsr-field"><label for="momos-dsr-aref-<?php echo (int) $v['id']; ?>"><?php esc_html_e( 'Register reference (optional)', 'momos-core' ); ?></label>
										<input type="text" id="momos-dsr-aref-<?php echo (int) $v['id']; ?>" name="request_ref" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9._/#-]{0,39}" dir="ltr"></p>
								</div>
								<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Anonymise this reservation', 'momos-core' ); ?></button>
							</form>
						<?php endif; ?>
					</details>
				<?php endforeach; ?>
			</section>
		<?php endif; ?>
	<?php endif; ?>

	<section class="momos-dsr-info" aria-labelledby="momos-dsr-ret-h">
		<h2 id="momos-dsr-ret-h"><?php esc_html_e( 'Retention job', 'momos-core' ); ?></h2>
		<?php if ( empty( $retention['finished_at'] ) ) : ?>
			<p><?php esc_html_e( 'The retention job has not run yet. It runs daily at 03:15 (JST).', 'momos-core' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				/* translators: 1: date and time (JST), 2: OK or failed */
				echo esc_html( sprintf( __( 'Last run: %1$s (JST) — %2$s', 'momos-core' ), wp_date( 'Y-m-d H:i', (int) Clock::from_mysql_utc( (string) $retention['finished_at'] )?->getTimestamp(), Clock::tz() ), ! empty( $retention['ok'] ) ? __( 'completed', 'momos-core' ) : __( 'completed with errors', 'momos-core' ) ) );
				?>
			</p>
			<ul>
				<?php foreach ( DsrScreen::retention_lines( $retention ) as $line ) : ?>
					<li><code><?php echo esc_html( $line ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2 id="momos-dsr-consent-h"><?php esc_html_e( 'Consent versions', 'momos-core' ); ?></h2>
		<p>
			<?php
			/* translators: %s: consent key, e.g. PP1.0-RP1.0 */
			echo esc_html( sprintf( __( 'New reservations store: %s', 'momos-core' ), $current ) );
			?>
		</p>
		<?php if ( $in_force !== $current ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %s: consent key, e.g. PP1.1-RP1.0 */
				echo esc_html( sprintf( __( 'The registry version in force is %s. Select it as the current consent version key in Settings → Reservations.', 'momos-core' ), $in_force ) );
				?>
			</p></div>
		<?php endif; ?>
		<table class="widefat striped" aria-labelledby="momos-dsr-consent-h">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Key', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Effective from (JST)', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Reservations', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'State', 'momos-core' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $consent as $c ) : ?>
				<tr>
					<td><span class="momos-dsr-ref"><?php echo esc_html( $c['consent_key'] ); ?></span></td>
					<td><?php echo esc_html( '' !== $c['effective_from'] ? $c['effective_from'] : '—' ); ?></td>
					<td><?php echo (int) $c['reservations']; ?></td>
					<td><?php echo esc_html( $c['consent_key'] === $current ? __( 'Stored on new reservations', 'momos-core' ) : ( $c['active'] ? __( 'In force', 'momos-core' ) : ( $c['in_registry'] ? __( 'Registered', 'momos-core' ) : __( 'Not in the registry', 'momos-core' ) ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
</div>
