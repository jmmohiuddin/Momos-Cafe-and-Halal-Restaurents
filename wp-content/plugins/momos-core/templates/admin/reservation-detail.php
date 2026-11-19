<?php
/**
 * SCR-A09 reservation detail (FR-RES-009..015). PII (email, phone, special request) only when the
 * detail contains it (momo_view_customer_pii). Actions are no-JS <details> sheets posting to
 * admin-post with a per-reservation nonce; only allowed_actions are rendered.
 *
 * @var array<string,mixed>      $d        Presenter::detail()
 * @var array<string,mixed>|null $branch
 * @var array<string,mixed>      $settings
 * @var list<string>             $slots    valid times on the requested date
 * @var array{type:string,text:string}|null $notice
 * @var string $back @var bool $is_ho @var int $user_id
 */

defined( 'ABSPATH' ) || exit;

use Momos\Kernel\I18n\Lang;
use Momos\Reservation\Admin\InboxPage;
use Momos\Reservation\Domain\DeclineReason;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Domain\StaffCancelReason;

$status       = ReservationStatus::from( (string) $d['status'] );
$allowed      = (array) $d['allowed_actions'];
$guest        = (string) $d['guest_given_name'];
$langnm       = Lang::native( (string) $d['preferred_lang'] ) . ' / ' . ( Lang::ALL[ $d['preferred_lang'] ]['english'] ?? '' );
$reasons      = [
	'fully_booked'              => __( 'Fully booked', 'momos-core' ),
	'closed_or_private_event'   => __( 'Closed or private event', 'momos-core' ),
	'party_size_not_supported'  => __( 'Party size not supported', 'momos-core' ),
	'incomplete_information'    => __( 'Incomplete information', 'momos-core' ),
	'other'                     => __( 'Other', 'momos-core' ),
	'customer_request_by_phone' => __( "Cancelled at guest's request by phone", 'momos-core' ),
	'branch_unable_to_serve'    => __( 'Branch unable to serve', 'momos-core' ),
	'duplicate'                 => __( 'Duplicate request', 'momos-core' ),
	'suspected_spam'            => __( 'Suspected spam', 'momos-core' ),
	'agreed_with_guest'         => __( 'Agreed with guest', 'momos-core' ),
	'flag_resolved'             => __( 'Flag resolved', 'momos-core' ),
];
$event_labels = [
	'created'             => __( 'Request received', 'momos-core' ),
	'status_changed'      => __( 'Status changed', 'momos-core' ),
	'assigned'            => __( 'Claimed', 'momos-core' ),
	'released'            => __( 'Released', 'momos-core' ),
	'note'                => __( 'Internal note', 'momos-core' ),
	'modified'            => __( 'Details changed', 'momos-core' ),
	'notification_failed' => __( 'Email to guest failed', 'momos-core' ),
	'customer_contacted'  => __( 'Guest contacted', 'momos-core' ),
	'sla_reminder'        => __( 'Reminder sent', 'momos-core' ),
	'sla_escalated'       => __( 'Escalated to Head Office', 'momos-core' ),
	'closure_conflict'    => __( 'Conflicts with closure or changed hours', 'momos-core' ),
	'pii_purged'          => __( 'Personal data removed', 'momos-core' ),
];
$st_label     = static fn( ?string $s ): string => $s && ReservationStatus::tryFrom( $s ) ? ReservationStatus::from( $s )->staff_label() : (string) $s;
$form_open    = static function ( string $op ) use ( $d ): void {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="' . esc_attr( InboxPage::ACTION ) . '">';
	echo '<input type="hidden" name="id" value="' . (int) $d['id'] . '">';
	echo '<input type="hidden" name="version" value="' . (int) $d['version'] . '">';
	echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	wp_nonce_field( 'momos_res_action_' . (int) $d['id'] );
};
$email_note   = sprintf( /* translators: 1: guest given name, 2: language */ __( '%1$s will receive an email in %2$s.', 'momos-core' ), $guest, Lang::native( (string) $d['preferred_lang'] ) );
require __DIR__ . '/reservation-styles.php';
?>
<div class="wrap momos-admin momos-res" id="momos-detail">
	<p><a href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Back to inbox', 'momos-core' ); ?></a></p>
	<h1>
		<span class="momos-badge momos-badge--<?php echo esc_attr( $status->value ); ?>"><?php echo esc_html( $status->staff_label() ); ?></span>
		<bdi dir="ltr"><?php echo esc_html( (string) $d['reference_display'] ); ?></bdi>
		<?php
		if ( $d['flags']['test_branch'] ) :
			?>
			<span class="momos-flag momos-flag--warn">TEST BRANCH — not a real restaurant</span><?php endif; ?>
	</h1>
	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo 'error' === $notice['type'] ? 'alert' : 'status'; ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>

	<div class="momos-grid momos-grid--detail">
		<div>
			<section class="momos-card" aria-labelledby="momos-h-request">
				<h2 id="momos-h-request"><?php esc_html_e( 'Request', 'momos-core' ); ?></h2>
				<dl class="momos-dl">
					<dt><?php esc_html_e( 'Date / time', 'momos-core' ); ?></dt><dd class="momos-when"><?php echo esc_html( $d['requested_date'] . ' ' . $d['requested_time'] ); ?> JST</dd>
					<dt><?php esc_html_e( 'Party', 'momos-core' ); ?></dt><dd><?php echo (int) $d['party_size']; ?></dd>
					<dt><?php esc_html_e( 'Branch', 'momos-core' ); ?></dt><dd><?php echo esc_html( $d['branch_code'] . ' — ' . $d['branch_name'] ); ?></dd>
					<dt><?php esc_html_e( 'Guest', 'momos-core' ); ?></dt><dd><bdi dir="auto"><?php echo esc_html( (string) $d['guest_name'] ); ?></bdi></dd>
					<?php if ( array_key_exists( 'email', $d ) ) : ?>
						<dt><?php esc_html_e( 'Phone', 'momos-core' ); ?></dt><dd><?php echo $d['phone_e164'] ? '<a href="' . esc_url( 'tel:' . $d['phone_e164'] ) . '" dir="ltr">' . esc_html( (string) $d['phone_e164'] ) . '</a>' : esc_html__( 'Not given', 'momos-core' ); ?></dd>
						<dt><?php esc_html_e( 'Email', 'momos-core' ); ?></dt><dd><a href="<?php echo esc_url( 'mailto:' . $d['email'] ); ?>" dir="ltr"><?php echo esc_html( (string) $d['email'] ); ?></a></dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'Reply language', 'momos-core' ); ?></dt><dd><?php echo esc_html( $langnm ); ?></dd>
					<dt><?php esc_html_e( 'Special request', 'momos-core' ); ?></dt>
					<dd>
						<?php if ( ! array_key_exists( 'special_request', $d ) ) : ?>
							<?php echo $d['flags']['has_special_request'] ? esc_html__( 'Yes (hidden — no permission to view guest details)', 'momos-core' ) : esc_html__( 'No special request', 'momos-core' ); ?>
						<?php elseif ( '' === trim( (string) $d['special_request'] ) ) : ?>
							<?php esc_html_e( 'No special request', 'momos-core' ); ?>
						<?php else : ?>
							<div dir="auto" style="white-space:pre-wrap"><?php echo esc_html( (string) $d['special_request'] ); ?></div>
						<?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Received', 'momos-core' ); ?></dt><dd><?php echo esc_html( (string) $d['created_at'] ); ?></dd>
					<?php if ( 'pending' === $d['status'] ) : ?>
						<dt><?php esc_html_e( 'Expires if undecided at', 'momos-core' ); ?></dt><dd><?php echo esc_html( (string) $d['decision_deadline'] ); ?></dd>
					<?php endif; ?>
					<?php if ( $d['decision_reason_code'] ) : ?>
						<dt><?php esc_html_e( 'Reason', 'momos-core' ); ?></dt><dd><?php echo esc_html( $reasons[ $d['decision_reason_code'] ] ?? (string) $d['decision_reason_code'] ); ?></dd>
					<?php endif; ?>
					<?php if ( $d['message_to_customer'] ) : ?>
						<dt><?php esc_html_e( 'Message to guest', 'momos-core' ); ?></dt><dd dir="auto"><?php echo esc_html( (string) $d['message_to_customer'] ); ?></dd>
					<?php endif; ?>
					<?php if ( $d['suggested_times'] ) : ?>
						<dt><?php esc_html_e( 'Suggested times', 'momos-core' ); ?></dt><dd><?php echo esc_html( implode( ', ', array_map( static fn( $t ) => $t['date'] . ' ' . $t['time'], $d['suggested_times'] ) ) ); ?></dd>
					<?php endif; ?>
				</dl>
			</section>

			<?php if ( $d['flags']['customer_not_notified'] || $d['flags']['needs_action'] || $d['flags']['captcha_unverified'] ) : ?>
				<section class="momos-card" aria-labelledby="momos-h-flags">
					<h2 id="momos-h-flags"><?php esc_html_e( 'Needs attention', 'momos-core' ); ?></h2>
					<ul>
						<?php
						if ( $d['flags']['customer_not_notified'] ) :
							?>
							<li><span class="momos-flag momos-flag--alert"><?php esc_html_e( 'Guest not notified', 'momos-core' ); ?></span> <?php echo esc_html( momos_t( 'staff.warn.customer_not_notified' ) ); ?></li><?php endif; ?>
						<?php
						if ( $d['flags']['needs_action'] ) :
							?>
							<li><span class="momos-flag momos-flag--alert"><?php esc_html_e( 'Needs action', 'momos-core' ); ?></span> <?php echo esc_html( momos_t( 'staff.warn.needs_action_closure' ) ); ?></li><?php endif; ?>
						<?php
						if ( $d['flags']['captcha_unverified'] ) :
							?>
							<li><span class="momos-flag momos-flag--warn"><?php esc_html_e( 'Spam check unavailable', 'momos-core' ); ?></span> <?php echo esc_html( momos_t( 'staff.warn.captcha_unverified' ) ); ?></li><?php endif; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section class="momos-card" aria-labelledby="momos-h-timeline">
				<h2 id="momos-h-timeline"><?php esc_html_e( 'Timeline', 'momos-core' ); ?></h2>
				<ol class="momos-timeline" reversed>
					<?php foreach ( $d['notifications'] as $n ) : ?>
						<li><strong><?php echo esc_html( sprintf( /* translators: 1: template, 2: status */ __( 'Email %1$s: %2$s', 'momos-core' ), $n['template'] . ( $n['variant'] ? ' ' . $n['variant'] : '' ), $n['status'] ) ); ?></strong>
							<span class="momos-muted"><?php echo esc_html( $n['recipient_masked'] . ' · ' . strtoupper( (string) $n['language'] ) . ' · ' . sprintf( /* translators: %d: attempts */ __( 'attempts %d/5', 'momos-core' ), $n['attempts'] ) . ' · ' . ( $n['sent_at'] ?? $n['created_at'] ) ); ?></span></li>
					<?php endforeach; ?>
					<?php foreach ( $d['events'] as $e ) : ?>
						<li>
							<strong><?php echo esc_html( $event_labels[ $e['type'] ] ?? $e['type'] ); ?></strong>
							<?php
							if ( $e['from'] || $e['to'] ) :
								?>
								<?php echo esc_html( ( $e['from'] ? $st_label( $e['from'] ) . ' → ' : '' ) . $st_label( $e['to'] ) ); ?><?php endif; ?>
							<?php
							if ( $e['reason'] ) :
								?>
								· <?php echo esc_html( $reasons[ $e['reason'] ] ?? $e['reason'] ); ?><?php endif; ?>
							<br><span class="momos-muted"><?php echo esc_html( (string) $e['at'] . ' · ' . ( 'system' === $e['actor_type'] ? __( 'System', 'momos-core' ) : ( 'customer' === $e['actor_type'] ? __( 'Guest', 'momos-core' ) : (string) $e['actor_display'] ) ) ); ?></span>
							<?php if ( 'modified' === $e['type'] && ! empty( $e['payload']['before'] ) ) : ?>
								<?php
								// Payload {before:{requested_date,requested_time,party_size},after:{…}}; older rows used date/time/party.
								$momos_mv = static fn( array $side, string $k1, string $k2 ): string => (string) ( $side[ $k1 ] ?? $side[ $k2 ] ?? '' );
								$momos_b  = (array) $e['payload']['before'];
								$momos_a  = (array) ( $e['payload']['after'] ?? [] );
								?>
								<br><?php echo esc_html( sprintf( '%s %s (%d) → %s %s (%d)', $momos_mv( $momos_b, 'requested_date', 'date' ), $momos_mv( $momos_b, 'requested_time', 'time' ), (int) $momos_mv( $momos_b, 'party_size', 'party' ), $momos_mv( $momos_a, 'requested_date', 'date' ), $momos_mv( $momos_a, 'requested_time', 'time' ), (int) $momos_mv( $momos_a, 'party_size', 'party' ) ) ); ?>
								<?php
								if ( ! empty( $e['payload']['override_reason'] ) ) :
									?>
									<br><?php echo esc_html( __( 'Rules overridden:', 'momos-core' ) . ' ' . $e['payload']['override_reason'] ); ?><?php endif; ?>
							<?php endif; ?>
							<?php
							if ( null !== $e['note'] && '' !== $e['note'] ) :
								?>
								<div dir="auto" style="white-space:pre-wrap"><?php echo esc_html( (string) $e['note'] ); ?></div><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
			<?php
			/** Extension point for other modules' panels (Notification: emails + Resend, TK-10-07). */
			do_action( 'momos/admin/reservation_detail', (int) $d['id'] );
			?>
		</div>

		<div>
			<section class="momos-card" aria-labelledby="momos-h-handler">
				<h2 id="momos-h-handler"><?php esc_html_e( 'Handled by', 'momos-core' ); ?></h2>
				<p><?php echo $d['handled_by'] ? esc_html( (string) $d['handled_by']['name'] ) : esc_html__( 'Unclaimed', 'momos-core' ); ?></p>
				<?php if ( in_array( 'claim', $allowed, true ) ) : ?>
					<?php $form_open( 'claim' ); ?>
					<button type="submit" class="button" <?php echo $d['handled_by'] ? 'data-momos-takeover="' . esc_attr( (string) $d['handled_by']['name'] ) . '"' : ''; ?>><?php esc_html_e( 'Claim', 'momos-core' ); ?></button>
					</form>
				<?php endif; ?>
				<?php if ( in_array( 'release', $allowed, true ) ) : ?>
					<?php $form_open( 'release' ); ?><button type="submit" class="button"><?php esc_html_e( 'Release', 'momos-core' ); ?></button></form>
				<?php endif; ?>
			</section>

			<section class="momos-card momos-actionbar" aria-labelledby="momos-h-actions">
				<h2 id="momos-h-actions"><?php esc_html_e( 'Actions', 'momos-core' ); ?></h2>
				<?php if ( in_array( 'accept', $allowed, true ) ) : ?>
					<details class="momos-sheet"><summary><?php esc_html_e( 'Accept', 'momos-core' ); ?></summary><div>
						<?php $form_open( 'accept' ); ?>
						<p><?php echo esc_html( $email_note ); ?></p>
						<label><?php esc_html_e( 'Message to guest (optional)', 'momos-core' ); ?>
							<textarea name="message_to_customer" maxlength="500" rows="3" data-momos-counter="500"></textarea></label>
						<p class="description"><?php esc_html_e( 'Write in simple English or Japanese; it is sent as written.', 'momos-core' ); ?></p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Accept and email guest', 'momos-core' ); ?></button>
						</form>
					</div></details>
				<?php endif; ?>
				<?php if ( in_array( 'decline', $allowed, true ) ) : ?>
					<details class="momos-sheet"><summary><?php esc_html_e( 'Decline', 'momos-core' ); ?></summary><div>
						<?php $form_open( 'decline' ); ?>
						<p><?php echo esc_html( $email_note ); ?></p>
						<fieldset><legend><?php esc_html_e( 'Reason (required)', 'momos-core' ); ?></legend>
							<?php foreach ( DeclineReason::values() as $r ) : ?>
								<label><input type="radio" name="reason_code" value="<?php echo esc_attr( $r ); ?>" required> <?php echo esc_html( $reasons[ $r ] ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<label><?php esc_html_e( 'Message to guest (optional)', 'momos-core' ); ?>
							<textarea name="message_to_customer" maxlength="500" rows="3" data-momos-counter="500"></textarea></label>
						<fieldset><legend><?php esc_html_e( 'Suggest up to two other times (optional)', 'momos-core' ); ?></legend>
							<?php for ( $i = 1; $i <= 2; $i++ ) : ?>
								<label><?php echo esc_html( sprintf( /* translators: %d: 1 or 2 */ __( 'Time %d', 'momos-core' ), $i ) ); ?>
									<input type="date" name="suggest_date_<?php echo (int) $i; ?>"> <input type="time" name="suggest_time_<?php echo (int) $i; ?>" step="<?php echo (int) $settings['slot_interval_minutes'] * 60; ?>"></label>
							<?php endfor; ?>
							<p class="description"><?php esc_html_e( 'Times are not held for the guest. Check they are within opening hours.', 'momos-core' ); ?></p>
						</fieldset>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Decline and email guest', 'momos-core' ); ?></button>
						</form>
					</div></details>
				<?php endif; ?>
				<?php if ( in_array( 'cancel', $allowed, true ) ) : ?>
					<details class="momos-sheet"><summary><?php esc_html_e( 'Cancel request', 'momos-core' ); ?></summary><div>
						<?php $form_open( 'cancel' ); ?>
						<fieldset><legend><?php esc_html_e( 'Reason (required)', 'momos-core' ); ?></legend>
							<?php foreach ( StaffCancelReason::values() as $r ) : ?>
								<label><input type="radio" name="reason_code" value="<?php echo esc_attr( $r ); ?>" required> <?php echo esc_html( $reasons[ $r ] ); ?>
								<?php
								if ( 'suspected_spam' === $r ) :
									?>
									— <em><?php esc_html_e( 'No email will be sent to the guest.', 'momos-core' ); ?></em><?php endif; ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p><?php echo esc_html( $email_note ); ?></p>
						<label><?php esc_html_e( 'Message to guest (optional)', 'momos-core' ); ?>
							<textarea name="message_to_customer" maxlength="500" rows="3" data-momos-counter="500"></textarea></label>
						<button type="submit" class="button"><?php esc_html_e( 'Cancel request', 'momos-core' ); ?></button>
						</form>
					</div></details>
				<?php endif; ?>
				<?php if ( in_array( 'mark_completed', $allowed, true ) ) : ?>
					<details class="momos-sheet"><summary><?php esc_html_e( 'Attended / No-show', 'momos-core' ); ?></summary><div>
						<p><?php esc_html_e( 'No email is sent to the guest.', 'momos-core' ); ?></p>
						<?php $form_open( 'mark_completed' ); ?><button type="submit" class="button button-primary"><?php esc_html_e( 'Attended', 'momos-core' ); ?></button></form>
						<?php $form_open( 'mark_no_show' ); ?><button type="submit" class="button"><?php esc_html_e( 'No-show', 'momos-core' ); ?></button></form>
					</div></details>
				<?php endif; ?>
				<?php if ( in_array( 'modify', $allowed, true ) ) : ?>
					<details class="momos-sheet"><summary><?php esc_html_e( 'Change details', 'momos-core' ); ?></summary><div>
						<?php $form_open( 'modify' ); ?>
						<p><?php esc_html_e( 'Reason: Agreed with guest.', 'momos-core' ); ?> <?php echo esc_html( $email_note ); ?></p>
						<label><?php esc_html_e( 'Date', 'momos-core' ); ?> <input type="date" name="requested_date" value="<?php echo esc_attr( (string) $d['requested_date'] ); ?>" required></label>
						<label><?php esc_html_e( 'Time', 'momos-core' ); ?> <input type="time" name="requested_time" value="<?php echo esc_attr( (string) $d['requested_time'] ); ?>" step="<?php echo (int) $settings['slot_interval_minutes'] * 60; ?>" required list="momos-slots"></label>
						<datalist id="momos-slots">
						<?php
						foreach ( $slots as $s ) :
							?>
							<option value="<?php echo esc_attr( $s ); ?>"><?php endforeach; ?></datalist>
						<p class="description"><?php echo esc_html( sprintf( /* translators: 1: date, 2: times */ __( 'Times on %1$s: %2$s', 'momos-core' ), (string) $d['requested_date'], $slots ? implode( ', ', $slots ) : '—' ) ); ?></p>
						<label><?php esc_html_e( 'Party', 'momos-core' ); ?> <input type="number" name="party_size" min="1" max="50" value="<?php echo (int) $d['party_size']; ?>" inputmode="numeric" required></label>
						<label><?php esc_html_e( 'Internal note (optional)', 'momos-core' ); ?> <textarea name="note" maxlength="1000" rows="2"></textarea></label>
						<?php if ( $is_ho ) : ?>
							<label><input type="checkbox" name="override_rules" value="1"> <?php esc_html_e( 'Override rules (lead time, booking window, party size)', 'momos-core' ); ?></label>
							<label><?php esc_html_e( 'Override reason', 'momos-core' ); ?> <input type="text" name="override_reason" maxlength="200" class="regular-text"></label>
						<?php endif; ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save and email guest', 'momos-core' ); ?></button>
						</form>
					</div></details>
				<?php endif; ?>
				<?php if ( in_array( 'mark_contacted', $allowed, true ) ) : ?>
					<details class="momos-sheet"><summary><?php esc_html_e( 'Guest contacted', 'momos-core' ); ?></summary><div>
						<?php $form_open( 'customer_contacted' ); ?>
						<label><?php esc_html_e( 'Note (optional)', 'momos-core' ); ?> <textarea name="text" maxlength="1000" rows="2"></textarea></label>
						<button type="submit" class="button"><?php esc_html_e( 'Mark as contacted', 'momos-core' ); ?></button></form>
					</div></details>
				<?php endif; ?>
				<?php if ( in_array( 'flag_resolved', $allowed, true ) ) : ?>
					<details class="momos-sheet"><summary><?php esc_html_e( 'Resolve flag', 'momos-core' ); ?></summary><div>
						<?php $form_open( 'flag_resolved' ); ?>
						<label><?php esc_html_e( 'What was done (required)', 'momos-core' ); ?> <textarea name="text" maxlength="1000" rows="2" required></textarea></label>
						<button type="submit" class="button"><?php esc_html_e( 'Resolve', 'momos-core' ); ?></button></form>
					</div></details>
				<?php endif; ?>
				<?php if ( ! array_diff( $allowed, [ 'note' ] ) ) : ?>
					<p class="momos-muted"><?php esc_html_e( 'No status action is possible for this request.', 'momos-core' ); ?></p>
				<?php endif; ?>
			</section>

			<?php if ( in_array( 'note', $allowed, true ) ) : ?>
				<section class="momos-card" aria-labelledby="momos-h-note">
					<h2 id="momos-h-note"><?php esc_html_e( 'Add internal note', 'momos-core' ); ?></h2>
					<?php $form_open( 'note' ); ?>
					<label class="screen-reader-text" for="momos-note"><?php esc_html_e( 'Internal note', 'momos-core' ); ?></label>
					<textarea id="momos-note" name="text" maxlength="1000" rows="3" required data-momos-counter="1000"></textarea>
					<p class="description"><?php esc_html_e( 'Never sent to the guest.', 'momos-core' ); ?></p>
					<button type="submit" class="button"><?php esc_html_e( 'Add note', 'momos-core' ); ?></button>
					</form>
				</section>
			<?php endif; ?>

			<section class="momos-card" aria-labelledby="momos-h-rules">
				<h2 id="momos-h-rules"><?php esc_html_e( 'Branch rules', 'momos-core' ); ?></h2>
				<dl class="momos-dl">
					<dt><?php esc_html_e( 'Party', 'momos-core' ); ?></dt><dd><?php echo (int) $settings['party_min'] . '–' . (int) $settings['party_max']; ?></dd>
					<dt><?php esc_html_e( 'Lead time', 'momos-core' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d minutes */ __( '%d min', 'momos-core' ), (int) $settings['lead_time_minutes'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Decision cutoff', 'momos-core' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d minutes */ __( '%d min before start', 'momos-core' ), (int) $settings['decision_cutoff_minutes'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Booking window', 'momos-core' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d days */ __( '%d days', 'momos-core' ), (int) $settings['booking_window_days'] ) ); ?></dd>
					<dt><?php esc_html_e( 'Online requests', 'momos-core' ); ?></dt><dd><?php echo $settings['enabled'] ? esc_html__( 'On', 'momos-core' ) : esc_html__( 'Off', 'momos-core' ); ?></dd>
				</dl>
			</section>
		</div>
	</div>
</div>
