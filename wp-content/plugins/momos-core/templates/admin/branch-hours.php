<?php
/**
 * SCR-A04 Hours & closures editor view (spec §7.2). Variables: $view (HoursEditorPage::view_data()).
 * All output escaped; times are JST.
 *
 * @var array<string,mixed> $view
 */

use Momos\Branch\Admin\HoursEditorPage;
use Momos\Branch\Admin\HoursFormHandler;
use Momos\Kernel\Support\Crockford;

defined( 'ABSPATH' ) || exit;

$momos_v          = $view;
$momos_bid        = (int) $momos_v['branch_id'];
$momos_edit       = (bool) $momos_v['can_edit'];
$momos_state      = $momos_v['state'];
$momos_errors     = ( $momos_state && 'errors' === $momos_state['result']['status'] ) ? (array) $momos_state['result']['errors'] : [];
$momos_impact     = ( $momos_state && 'impact' === $momos_state['result']['status'] ) ? (array) $momos_state['result']['affected'] : [];
$momos_dis        = $momos_edit ? '' : ' disabled';
$momos_post       = admin_url( 'admin-post.php' );
$momos_err_attr   = static function ( string $key ) use ( $momos_errors ): string {
	return isset( $momos_errors[ $key ] ) ? ' aria-invalid="true" aria-describedby="momos-err-' . esc_attr( $key ) . '"' : '';
};
$momos_period_row = static function ( string $name, array $p, string $err_key ) use ( $momos_v, $momos_dis, $momos_err_attr ): void {
	$crosses = ! empty( $p['opens_at'] ) && ! empty( $p['closes_at'] ) && (string) $p['closes_at'] < (string) $p['opens_at'];
	echo '<div class="momos-period" data-err="' . esc_attr( $err_key ) . '">';
	echo '<label><span class="screen-reader-text">' . esc_html__( 'Type', 'momos-core' ) . '</span><select name="' . esc_attr( $name ) . '[label]"' . $momos_dis . '>'; // phpcs:ignore
	foreach ( $momos_v['labels'] as $k => $l ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( (string) ( $p['label'] ?? 'all_day' ), $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select></label> ';
	foreach ( [ 'opens' => [ 'opens_at', __( 'Opens', 'momos-core' ) ], 'closes' => [ 'closes_at', __( 'Closes', 'momos-core' ) ], 'last_order' => [ 'last_order_at', __( 'Last order', 'momos-core' ) ] ] as $field => [ $src, $label ] ) {
		echo '<label>' . esc_html( $label ) . ' <input type="text" inputmode="numeric" size="5" maxlength="5" placeholder="HH:MM" autocomplete="off" name="' . esc_attr( $name . '[' . $field . ']' ) . '" value="' . esc_attr( (string) ( $p[ $src ] ?? '' ) ) . '"' . $momos_err_attr( $err_key ) . $momos_dis . '></label> '; // phpcs:ignore
	}
	echo '<span class="momos-crosses"' . ( $crosses ? '' : ' hidden' ) . '>' . esc_html__( 'crosses midnight', 'momos-core' ) . '</span> ';
	if ( '' === $momos_dis ) {
		echo '<button type="button" class="button-link momos-period-remove">' . esc_html__( 'Remove', 'momos-core' ) . '</button>';
	}
	echo '</div>';
};
?>
<div class="wrap momos-admin momos-hours">
	<h1><?php esc_html_e( 'Hours & closures', 'momos-core' ); ?></h1>
	<p class="description"><?php esc_html_e( 'All times are Japan time (JST). Changes are live immediately and are recorded in the audit log.', 'momos-core' ); ?></p>

	<?php if ( 'saved' === $momos_v['msg'] ) : ?>
		<div class="notice notice-success" role="status"><p>
			<?php esc_html_e( 'Saved.', 'momos-core' ); ?>
			<?php if ( $momos_v['affected'] > 0 ) : ?>
				<?php /* translators: %d: number of reservations */ echo esc_html( sprintf( __( 'This affects %d pending or confirmed requests. They are marked "Needs action".', 'momos-core' ), (int) $momos_v['affected'] ) ); ?>
				<a href="<?php echo esc_url( $momos_v['inbox_url'] ); ?>"><?php esc_html_e( 'Open "Needs action" in the inbox', 'momos-core' ); ?></a>
			<?php endif; ?>
		</p></div>
	<?php elseif ( in_array( $momos_v['msg'], [ 'set_deleted', 'closure_deleted' ], true ) ) : ?>
		<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Deleted.', 'momos-core' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $momos_errors ) : ?>
		<div class="notice notice-error" role="alert"><p><?php esc_html_e( 'Nothing was saved. Please correct the following:', 'momos-core' ); ?></p><ul>
			<?php foreach ( $momos_errors as $momos_k => $momos_msg ) : ?>
				<li id="momos-err-<?php echo esc_attr( (string) $momos_k ); ?>"><?php echo esc_html( (string) $momos_msg ); ?></li>
			<?php endforeach; ?>
		</ul></div>
	<?php endif; ?>

	<?php if ( $momos_impact ) : ?>
		<div class="notice notice-warning momos-impact" role="alert">
			<p><strong><?php /* translators: %d: number of reservations */ echo esc_html( sprintf( __( 'This affects %d pending or confirmed requests', 'momos-core' ), count( $momos_impact ) ) ); ?></strong></p>
			<p><?php esc_html_e( 'They will not be cancelled. They are marked "Needs action" so staff can contact the guests.', 'momos-core' ); ?></p>
			<table class="widefat striped"><thead><tr>
				<th scope="col"><?php esc_html_e( 'Reference', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Date', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Time (JST)', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Party', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'momos-core' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $momos_impact as $momos_r ) : ?>
				<tr><td><bdi dir="ltr"><?php echo esc_html( Crockford::display( (string) $momos_r['public_ref'] ) ); ?></bdi></td><td><?php echo esc_html( (string) $momos_r['requested_date'] ); ?></td>
				<td><?php echo esc_html( (string) $momos_r['requested_time'] ); ?></td><td><?php echo (int) $momos_r['party_size']; ?></td><td><?php echo esc_html( (string) $momos_r['status'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<form method="post" action="<?php echo esc_url( $momos_post ); ?>" class="momos-impact__actions">
				<input type="hidden" name="action" value="<?php echo esc_attr( 'closures' === $momos_v['tab'] ? 'momos_save_closure' : 'momos_save_hours' ); ?>">
				<input type="hidden" name="branch_id" value="<?php echo (int) $momos_bid; ?>">
				<input type="hidden" name="_momos_nonce" value="<?php echo esc_attr( $momos_v['nonce'] ); ?>">
				<input type="hidden" name="momos_token" value="<?php echo esc_attr( (string) $momos_state['token'] ); ?>">
				<input type="hidden" name="confirm" value="1">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save anyway', 'momos-core' ); ?></button>
				<a class="button" href="<?php echo esc_url( HoursEditorPage::url( $momos_bid, $momos_v['tab'] ) ); ?>"><?php esc_html_e( 'Cancel', 'momos-core' ); ?></a>
			</form>
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="momos-branch-select">
		<input type="hidden" name="post_type" value="momo_branch">
		<input type="hidden" name="page" value="<?php echo esc_attr( HoursEditorPage::SLUG ); ?>">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $momos_v['tab'] ); ?>">
		<label for="momos-branch"><?php esc_html_e( 'Branch', 'momos-core' ); ?></label>
		<select id="momos-branch" name="branch" onchange="this.form.submit()">
			<?php foreach ( $momos_v['branches'] as $momos_b ) : ?>
				<option value="<?php echo (int) $momos_b['id']; ?>"<?php selected( $momos_bid, (int) $momos_b['id'] ); ?>><?php echo esc_html( $momos_b['title'] . ( $momos_b['code'] ? ' (' . $momos_b['code'] . ')' : '' ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<noscript><button type="submit" class="button"><?php esc_html_e( 'Show', 'momos-core' ); ?></button></noscript>
	</form>

	<?php if ( ! $momos_bid ) : ?>
		<p><?php esc_html_e( 'No branch is assigned to your account.', 'momos-core' ); ?></p></div>
		<?php
		return;
	endif;
	?>

	<?php if ( ! $momos_edit ) : ?>
		<p class="momos-readonly-note"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Read only: you cannot change the hours of this branch.', 'momos-core' ); ?></p>
	<?php endif; ?>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Sections', 'momos-core' ); ?>">
		<a class="nav-tab<?php echo 'weekly' === $momos_v['tab'] ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( HoursEditorPage::url( $momos_bid, 'weekly' ) ); ?>"<?php echo 'weekly' === $momos_v['tab'] ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Weekly hours', 'momos-core' ); ?></a>
		<a class="nav-tab<?php echo 'closures' === $momos_v['tab'] ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( HoursEditorPage::url( $momos_bid, 'closures' ) ); ?>"<?php echo 'closures' === $momos_v['tab'] ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Closures & special hours', 'momos-core' ); ?></a>
	</nav>

	<div class="momos-hours__layout">
	<div class="momos-hours__main">
	<?php if ( 'weekly' === $momos_v['tab'] ) : ?>
		<p class="momos-sets">
			<strong><?php esc_html_e( 'Schedule:', 'momos-core' ); ?></strong>
			<a href="<?php echo esc_url( add_query_arg( 'set', 'default', HoursEditorPage::url( $momos_bid ) ) ); ?>"<?php echo 'default' === $momos_v['set_key'] ? ' aria-current="true" class="current"' : ''; ?>><?php esc_html_e( 'Default', 'momos-core' ); ?></a>
			<?php foreach ( $momos_v['sets'] as $momos_s ) : ?>
				<?php
				if ( null === $momos_s['valid_from'] ) {
					continue;
				}
				$momos_key = HoursFormHandler::set_key( $momos_s['valid_from'], $momos_s['valid_until'] );
				?>
				| <a href="<?php echo esc_url( add_query_arg( 'set', $momos_key, HoursEditorPage::url( $momos_bid ) ) ); ?>"<?php echo $momos_key === $momos_v['set_key'] ? ' aria-current="true" class="current"' : ''; ?>><?php echo esc_html( $momos_s['valid_from'] . ' – ' . ( $momos_s['valid_until'] ?? '' ) ); ?></a>
			<?php endforeach; ?>
			<?php if ( $momos_edit ) : ?>
				| <a href="<?php echo esc_url( add_query_arg( 'set', 'new', HoursEditorPage::url( $momos_bid ) ) ); ?>"><?php esc_html_e( 'Add schedule from date', 'momos-core' ); ?></a>
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( $momos_post ); ?>" class="momos-week-form" data-momos-dirty-watch>
			<input type="hidden" name="action" value="momos_save_hours">
			<input type="hidden" name="branch_id" value="<?php echo (int) $momos_bid; ?>">
			<input type="hidden" name="_momos_nonce" value="<?php echo esc_attr( $momos_v['nonce'] ); ?>">
			<input type="hidden" name="set" value="<?php echo esc_attr( $momos_v['set_key'] ); ?>">
			<input type="hidden" name="loaded_max_updated_at" value="<?php echo esc_attr( (string) $momos_v['loaded_at'] ); ?>">
			<?php if ( 'default' !== $momos_v['set_key'] ) : ?>
				<p>
					<label><?php esc_html_e( 'Valid from', 'momos-core' ); ?> <input type="date" name="valid_from" required value="<?php echo esc_attr( (string) $momos_v['set']['valid_from'] ); ?>"<?php echo $momos_err_attr( 'validity' ) . $momos_dis; // phpcs:ignore ?>></label>
					<label><?php esc_html_e( 'Valid until (optional)', 'momos-core' ); ?> <input type="date" name="valid_until" value="<?php echo esc_attr( (string) $momos_v['set']['valid_until'] ); ?>"<?php echo $momos_dis; // phpcs:ignore ?>></label>
					<span class="description"><?php esc_html_e( 'Weekdays with periods here replace the default schedule on those dates.', 'momos-core' ); ?></span>
				</p>
			<?php endif; ?>
			<?php if ( $momos_v['res_enabled'] ) : ?>
				<p class="description"><?php esc_html_e( 'Online requests are on: every period needs a last-order time.', 'momos-core' ); ?></p>
			<?php endif; ?>
			<table class="widefat momos-week">
				<caption class="screen-reader-text"><?php esc_html_e( 'Weekly opening periods', 'momos-core' ); ?></caption>
				<tbody>
				<?php for ( $momos_w = 1; $momos_w <= 7; $momos_w++ ) : ?>
					<?php $momos_periods = $momos_v['week'][ $momos_w ] ?? []; ?>
					<tr data-weekday="<?php echo (int) $momos_w; ?>">
						<th scope="row"><?php echo esc_html( HoursEditorPage::weekday_short( $momos_w ) ); ?></th>
						<td>
							<label class="momos-closed"><input type="checkbox" name="closed[<?php echo (int) $momos_w; ?>]" value="1"<?php checked( isset( $momos_state['input']['closed'][ $momos_w ] ) ); echo $momos_dis; // phpcs:ignore ?>> <?php esc_html_e( 'Closed all day (regular closing day)', 'momos-core' ); ?></label>
							<?php
							if ( ! $momos_periods ) :
								?>
								<span class="description"><?php esc_html_e( 'Currently closed', 'momos-core' ); ?></span><?php endif; ?>
							<div class="momos-periods">
							<?php
							$momos_rows = $momos_periods;
							if ( $momos_edit && count( $momos_rows ) < 6 ) {
								$momos_rows[] = [ 'label' => 'all_day', 'opens_at' => '', 'closes_at' => '', 'last_order_at' => '' ];
							}
							foreach ( array_values( $momos_rows ) as $momos_i => $momos_p ) {
								$momos_period_row( 'periods[' . $momos_w . '][' . $momos_i . ']', $momos_p, 'w' . $momos_w . 'p' . ( $momos_i + 1 ) );
							}
							?>
							</div>
							<?php if ( $momos_edit ) : ?>
								<button type="button" class="button momos-period-add"><?php esc_html_e( 'Add period', 'momos-core' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endfor; ?>
				</tbody>
			</table>
			<?php if ( $momos_edit ) : ?>
				<p>
					<button type="button" class="button momos-copy-monday"><?php esc_html_e( 'Copy Monday to all weekdays', 'momos-core' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save weekly hours', 'momos-core' ); ?></button>
				</p>
			<?php endif; ?>
		</form>
		<?php if ( $momos_edit && ! in_array( $momos_v['set_key'], [ 'default', 'new' ], true ) ) : ?>
			<form method="post" action="<?php echo esc_url( $momos_post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this dated schedule?', 'momos-core' ) ); ?>');">
				<input type="hidden" name="action" value="momos_delete_hours_set">
				<input type="hidden" name="branch_id" value="<?php echo (int) $momos_bid; ?>">
				<input type="hidden" name="_momos_nonce" value="<?php echo esc_attr( $momos_v['nonce'] ); ?>">
				<input type="hidden" name="set" value="<?php echo esc_attr( $momos_v['set_key'] ); ?>">
				<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete this dated schedule', 'momos-core' ); ?></button>
			</form>
		<?php endif; ?>

	<?php else : ?>
		<h2><?php esc_html_e( 'Upcoming closures and special hours', 'momos-core' ); ?></h2>
		<?php if ( ! $momos_v['closures'] ) : ?>
			<p><?php esc_html_e( 'No closures or special hours in the next 90 days.', 'momos-core' ); ?></p>
		<?php else : ?>
			<table class="widefat striped momos-closures">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Date', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Hours', 'momos-core' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Public reason', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Internal note (staff only)', 'momos-core' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'momos-core' ); ?></span></th>
				</tr></thead><tbody>
				<?php foreach ( $momos_v['closures'] as $momos_date => $momos_c ) : ?>
					<?php $momos_past = $momos_date < $momos_v['today']; ?>
					<tr class="<?php echo $momos_past ? 'momos-past' : ''; ?>">
						<td><?php echo esc_html( $momos_date . ' (' . HoursEditorPage::weekday_short( (int) ( new DateTimeImmutable( $momos_date ) )->format( 'N' ) ) . ')' ); ?></td>
						<td><?php echo esc_html( 'closed_all_day' === $momos_c['closure_type'] ? __( 'Closed all day', 'momos-core' ) : HoursEditorPage::periods_text( $momos_c['periods'] ) ); ?></td>
						<td><?php echo esc_html( $momos_v['reasons'][ $momos_c['public_reason'] ] ?? $momos_c['public_reason'] ); ?></td>
						<td><?php echo esc_html( (string) $momos_c['internal_note'] ); ?></td>
						<td>
							<?php if ( $momos_past ) : ?>
								<span class="description"><?php esc_html_e( 'Past (read only)', 'momos-core' ); ?></span>
							<?php elseif ( $momos_edit ) : ?>
								<form method="post" action="<?php echo esc_url( $momos_post ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this entry? Reservations already marked "Needs action" stay marked.', 'momos-core' ) ); ?>');">
									<input type="hidden" name="action" value="momos_delete_closure">
									<input type="hidden" name="branch_id" value="<?php echo (int) $momos_bid; ?>">
									<input type="hidden" name="_momos_nonce" value="<?php echo esc_attr( $momos_v['nonce'] ); ?>">
									<input type="hidden" name="date" value="<?php echo esc_attr( $momos_date ); ?>">
									<button type="submit" class="button-link button-link-delete"><?php /* translators: %s: date */ echo esc_html( sprintf( __( 'Delete %s', 'momos-core' ), $momos_date ) ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $momos_edit ) : ?>
			<?php $momos_in = ( $momos_state && isset( $momos_state['input']['date_from'] ) ) ? $momos_state['input'] : []; ?>
			<h2><?php esc_html_e( 'Add closure or special hours', 'momos-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $momos_post ); ?>" class="momos-closure-form" data-momos-dirty-watch>
				<input type="hidden" name="action" value="momos_save_closure">
				<input type="hidden" name="branch_id" value="<?php echo (int) $momos_bid; ?>">
				<input type="hidden" name="_momos_nonce" value="<?php echo esc_attr( $momos_v['nonce'] ); ?>">
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><label for="momos-date-from"><?php esc_html_e( 'Date (from)', 'momos-core' ); ?></label></th>
						<td><input type="date" id="momos-date-from" name="date_from" required min="<?php echo esc_attr( $momos_v['today'] ); ?>" value="<?php echo esc_attr( (string) ( $momos_in['date_from'] ?? '' ) ); ?>"<?php echo $momos_err_attr( 'date' ); // phpcs:ignore ?>>
						<label for="momos-date-to"><?php esc_html_e( 'to (optional, max. 31 days)', 'momos-core' ); ?></label>
						<input type="date" id="momos-date-to" name="date_to" min="<?php echo esc_attr( $momos_v['today'] ); ?>" value="<?php echo esc_attr( (string) ( $momos_in['date_to'] ?? '' ) ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Type', 'momos-core' ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Type', 'momos-core' ); ?></legend>
							<label><input type="radio" name="type" value="closed_all_day"<?php checked( ( $momos_in['type'] ?? 'closed_all_day' ), 'closed_all_day' ); ?>> <?php esc_html_e( 'Closed all day', 'momos-core' ); ?></label>
							<label><input type="radio" name="type" value="special_hours"<?php checked( ( $momos_in['type'] ?? '' ), 'special_hours' ); ?>> <?php esc_html_e( 'Special hours', 'momos-core' ); ?></label>
						</fieldset></td></tr>
					<tr class="momos-special"><th scope="row"><?php esc_html_e( 'Special periods', 'momos-core' ); ?></th>
						<td><div class="momos-periods">
						<?php
						$momos_sp = array_values( array_filter( (array) ( $momos_in['periods'] ?? [] ), 'is_array' ) );
						$momos_sp = array_map( static fn( $p ) => [ 'label' => $p['label'] ?? 'all_day', 'opens_at' => $p['opens'] ?? '', 'closes_at' => $p['closes'] ?? '', 'last_order_at' => $p['last_order'] ?? '' ], $momos_sp );
						while ( count( $momos_sp ) < 2 ) {
							$momos_sp[] = [ 'label' => 'all_day', 'opens_at' => '', 'closes_at' => '', 'last_order_at' => '' ];
						}
						foreach ( $momos_sp as $momos_i => $momos_p ) {
							$momos_period_row( 'periods[' . $momos_i . ']', $momos_p, 'sp' . ( $momos_i + 1 ) );
						}
						?>
						</div><button type="button" class="button momos-period-add"><?php esc_html_e( 'Add period', 'momos-core' ); ?></button></td></tr>
					<tr><th scope="row"><label for="momos-reason"><?php esc_html_e( 'Public reason', 'momos-core' ); ?></label></th>
						<td><select id="momos-reason" name="public_reason">
							<?php foreach ( $momos_v['reasons'] as $momos_k => $momos_l ) : ?>
								<option value="<?php echo esc_attr( $momos_k ); ?>"<?php selected( ( $momos_in['public_reason'] ?? 'other' ), $momos_k ); ?>><?php echo esc_html( $momos_l ); ?></option>
							<?php endforeach; ?>
						</select><p class="description"><?php esc_html_e( 'Shown to customers in their language.', 'momos-core' ); ?></p></td></tr>
					<tr><th scope="row"><label for="momos-note"><?php esc_html_e( 'Internal note', 'momos-core' ); ?></label></th>
						<td><input type="text" id="momos-note" name="internal_note" maxlength="255" class="regular-text" value="<?php echo esc_attr( (string) ( $momos_in['internal_note'] ?? '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Staff only — never shown to customers or sent in emails.', 'momos-core' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Existing entries', 'momos-core' ); ?></th>
						<td><label><input type="checkbox" name="replace_existing" value="1"<?php echo $momos_err_attr( 'replace_existing' ); // phpcs:ignore ?>> <?php esc_html_e( 'Replace existing entries', 'momos-core' ); ?></label></td></tr>
				</tbody></table>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save closure', 'momos-core' ); ?></button>
				<a href="<?php echo esc_url( $momos_v['notice_url'] ); ?>"><?php esc_html_e( 'Post a notice for these dates', 'momos-core' ); ?></a></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
	</div>

	<aside class="momos-hours__preview" aria-labelledby="momos-preview-h">
		<h2 id="momos-preview-h"><?php esc_html_e( 'Next 7 days (customer view, JST)', 'momos-core' ); ?></h2>
		<ul>
		<?php foreach ( $momos_v['preview'] as $momos_d ) : ?>
			<li><strong><?php echo esc_html( HoursEditorPage::weekday_short( (int) $momos_d['weekday'] ) . ' ' . $momos_d['date'] ); ?></strong>
				<?php echo esc_html( HoursEditorPage::periods_text( $momos_d['periods'] ) ); ?>
				<?php if ( $momos_d['closure'] ) : ?>
					<em>(<?php echo esc_html( $momos_v['reasons'][ $momos_d['closure']['public_reason'] ] ?? '' ); ?>)</em>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
		</ul>
	</aside>
	</div>
</div>
<style>
.momos-hours__layout{display:flex;gap:24px;flex-wrap:wrap}.momos-hours__main{flex:1 1 640px;min-inline-size:0}.momos-hours__preview{flex:0 1 280px}
.momos-period{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-block-end:6px}.momos-period input,.momos-period select,.momos-hours .button{min-block-size:44px}
.momos-week th{inline-size:110px}.momos-crosses{font-size:12px;color:#996800}.momos-past{opacity:.6}.momos-sets .current{font-weight:600}
@media (max-width:782px){.momos-week th,.momos-week td{display:block;inline-size:auto}}
</style>
<script>
( function () {
	var root = document.querySelector( '.momos-hours' );
	if ( ! root ) { return; }
	function renumber( container, prefix ) {
		container.querySelectorAll( '.momos-period' ).forEach( function ( row, i ) {
			row.querySelectorAll( '[name]' ).forEach( function ( el ) {
				el.name = el.name.replace( /^(periods(?:\[\d+\])?)\[\d+\]/, prefix ? prefix + '[' + i + ']' : '$1[' + i + ']' );
			} );
		} );
	}
	root.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( t.classList.contains( 'momos-period-add' ) ) {
			var list = t.parentNode.querySelector( '.momos-periods' );
			var rows = list.querySelectorAll( '.momos-period' );
			if ( ! rows.length || rows.length >= 6 ) { return; }
			var clone = rows[ rows.length - 1 ].cloneNode( true );
			clone.querySelectorAll( 'input' ).forEach( function ( i ) { i.value = ''; i.removeAttribute( 'aria-invalid' ); } );
			list.appendChild( clone );
			var tr = t.closest( 'tr[data-weekday]' );
			renumber( list, tr ? 'periods[' + tr.getAttribute( 'data-weekday' ) + ']' : 'periods' );
		}
		if ( t.classList.contains( 'momos-period-remove' ) ) {
			var row = t.closest( '.momos-period' );
			var box = row.parentNode;
			if ( box.querySelectorAll( '.momos-period' ).length > 1 ) { row.remove(); } else { row.querySelectorAll( 'input' ).forEach( function ( i ) { i.value = ''; } ); }
			var tr2 = box.closest( 'tr[data-weekday]' );
			renumber( box, tr2 ? 'periods[' + tr2.getAttribute( 'data-weekday' ) + ']' : 'periods' );
		}
		if ( t.classList.contains( 'momos-copy-monday' ) ) {
			var mon = root.querySelector( 'tr[data-weekday="1"] .momos-periods' );
			root.querySelectorAll( 'tr[data-weekday]' ).forEach( function ( tr3 ) {
				var w = tr3.getAttribute( 'data-weekday' );
				if ( '1' === w ) { return; }
				var target = tr3.querySelector( '.momos-periods' );
				target.innerHTML = mon.innerHTML;
				var src = mon.querySelectorAll( 'input,select' ), dst = target.querySelectorAll( 'input,select' );
				dst.forEach( function ( el, i ) { el.value = src[ i ].value; } );
				renumber( target, 'periods[' + w + ']' );
				var c = tr3.querySelector( '.momos-closed input' ); if ( c ) { c.checked = root.querySelector( 'tr[data-weekday="1"] .momos-closed input' ).checked; }
			} );
		}
	} );
	root.addEventListener( 'input', function ( e ) {
		var row = e.target.closest( '.momos-period' );
		if ( ! row ) { return; }
		var o = row.querySelector( '[name$="[opens]"]' ).value, c = row.querySelector( '[name$="[closes]"]' ).value;
		row.querySelector( '.momos-crosses' ).hidden = ! ( /^\d{1,2}:\d{2}$/.test( o ) && /^\d{1,2}:\d{2}$/.test( c ) && c.padStart( 5, '0' ) < o.padStart( 5, '0' ) );
	} );
	var dirty = false;
	root.querySelectorAll( '[data-momos-dirty-watch]' ).forEach( function ( f ) {
		f.addEventListener( 'change', function () { dirty = true; } );
		f.addEventListener( 'submit', function () { dirty = false; } );
	} );
	window.addEventListener( 'beforeunload', function ( e ) { if ( dirty ) { e.preventDefault(); e.returnValue = ''; } } );
	root.querySelectorAll( '.momos-closed input' ).forEach( function ( cb ) {
		var box = cb.closest( 'td' ).querySelector( '.momos-periods' );
		var syncClosed = function () { box.hidden = cb.checked; };
		cb.addEventListener( 'change', syncClosed );
		syncClosed();
	} );
	var special = root.querySelector( '.momos-special' );
	if ( special ) {
		var sync = function () { var r = root.querySelector( 'input[name="type"]:checked' ); special.hidden = ! r || 'special_hours' !== r.value; };
		root.querySelectorAll( 'input[name="type"]' ).forEach( function ( r ) { r.addEventListener( 'change', sync ); } );
		sync();
	}
} )();
</script>
