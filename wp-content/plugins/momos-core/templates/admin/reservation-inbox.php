<?php
/**
 * SCR-A08 reservation inbox (FR-RES-008). Rows never show email, phone or special-request text.
 *
 * @var list<array<string,mixed>> $rows     Presenter::list_row()
 * @var array<string,int>         $counts   per status in scope
 * @var int                       $overdue
 * @var array<string,array<string,string>> $views
 * @var string                    $view
 * @var list<array{id:int,code:string,name:string,is_test:bool}> $branches
 * @var array<string,mixed>       $base_q
 * @var int $page @var int $pages @var int $total @var ?string $error @var bool $can_export
 * @var \Momos\Reservation\Domain\Scope $scope
 */

defined( 'ABSPATH' ) || exit;

use Momos\Kernel\I18n\Lang;
use Momos\Reservation\Admin\InboxPage;
use Momos\Reservation\Domain\ReservationStatus;

$get = static fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ) : ''; // phpcs:ignore
require __DIR__ . '/reservation-styles.php';
?>
<div class="wrap momos-admin momos-res" id="momos-inbox" data-total="<?php echo (int) $total; ?>">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Reservation requests', 'momos-core' ); ?></h1>
	<span class="momos-badge"><?php echo $scope->is_all() ? esc_html__( 'All branches', 'momos-core' ) : esc_html( implode( ', ', array_map( static fn( $b ) => $b['code'], $branches ) ) ); ?></span>
	<?php if ( $overdue > 0 ) : ?>
		<span class="momos-flag momos-flag--alert"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d overdue', '%d overdue', $overdue, 'momos-core' ), $overdue ) ); ?></span>
	<?php endif; ?>
	<hr class="wp-header-end">

	<div id="momos-newbar" class="momos-newbar notice notice-info" role="status" hidden><p><a href="<?php echo esc_url( InboxPage::url( $base_q ) ); ?>" id="momos-newbar-link"></a></p></div>
	<div id="momos-offline" class="notice notice-warning" role="status" hidden><p></p></div>
	<?php if ( $error ) : ?>
		<div class="notice notice-error" role="alert"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<nav aria-label="<?php esc_attr_e( 'Views', 'momos-core' ); ?>">
		<ul class="momos-chips">
			<?php foreach ( $views as $key => $def ) : ?>
				<?php
				$n = null;
				if ( 'pending' === $key ) {
					$n = $counts['pending'] ?? 0;
				} elseif ( 'all' === $key ) {
					$n = array_sum( $counts );
				}
				?>
				<li><a class="momos-chip" href="<?php echo esc_url( InboxPage::url( [ 'view' => $key ] ) ); ?>" <?php echo $view === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $def['label'] ); ?>
				<?php
				if ( null !== $n ) :
					?>
					<span class="count"><?php echo (int) $n; ?></span><?php endif; ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<form method="get" class="momos-filters" role="search">
		<input type="hidden" name="page" value="<?php echo esc_attr( InboxPage::SLUG ); ?>">
		<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
		<label><?php esc_html_e( 'Search (reference or name)', 'momos-core' ); ?>
			<input type="search" name="q" value="<?php echo esc_attr( $get( 'q' ) ); ?>" minlength="2" maxlength="100">
		</label>
		<?php if ( count( $branches ) > 1 ) : ?>
			<label><?php esc_html_e( 'Branch', 'momos-core' ); ?>
				<select name="branch">
					<option value=""><?php esc_html_e( 'All in my scope', 'momos-core' ); ?></option>
					<?php foreach ( $branches as $b ) : ?>
						<option value="<?php echo esc_attr( $b['code'] ); ?>" <?php selected( $get( 'branch' ), $b['code'] ); ?>><?php echo esc_html( $b['code'] . ' — ' . $b['name'] . ( $b['is_test'] ? ' (TEST)' : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		<?php endif; ?>
		<label><?php esc_html_e( 'Status', 'momos-core' ); ?>
			<select name="status">
				<option value=""><?php esc_html_e( 'As in view', 'momos-core' ); ?></option>
				<?php foreach ( ReservationStatus::cases() as $s ) : ?>
					<option value="<?php echo esc_attr( $s->value ); ?>" <?php selected( $get( 'status' ), $s->value ); ?>><?php echo esc_html( $s->staff_label() ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label><?php esc_html_e( 'From', 'momos-core' ); ?><input type="date" name="date_from" value="<?php echo esc_attr( $get( 'date_from' ) ); ?>"></label>
		<label><?php esc_html_e( 'To', 'momos-core' ); ?><input type="date" name="date_to" value="<?php echo esc_attr( $get( 'date_to' ) ); ?>"></label>
		<label><?php esc_html_e( 'Flag', 'momos-core' ); ?>
			<select name="flags">
				<option value=""><?php esc_html_e( 'Any', 'momos-core' ); ?></option>
				<option value="needs_action" <?php selected( $get( 'flags' ), 'needs_action' ); ?>><?php esc_html_e( 'Needs action', 'momos-core' ); ?></option>
				<option value="not_notified" <?php selected( $get( 'flags' ), 'not_notified' ); ?>><?php esc_html_e( 'Guest not notified', 'momos-core' ); ?></option>
				<option value="overdue" <?php selected( $get( 'flags' ), 'overdue' ); ?>><?php esc_html_e( 'Overdue', 'momos-core' ); ?></option>
				<option value="unclaimed" <?php selected( $get( 'flags' ), 'unclaimed' ); ?>><?php esc_html_e( 'Unclaimed', 'momos-core' ); ?></option>
			</select>
		</label>
		<label><?php esc_html_e( 'Handled by', 'momos-core' ); ?>
			<select name="handled_by">
				<option value=""><?php esc_html_e( 'Anyone', 'momos-core' ); ?></option>
				<option value="me" <?php selected( $get( 'handled_by' ), 'me' ); ?>><?php esc_html_e( 'Me', 'momos-core' ); ?></option>
				<option value="none" <?php selected( $get( 'handled_by' ), 'none' ); ?>><?php esc_html_e( 'Unclaimed', 'momos-core' ); ?></option>
			</select>
		</label>
		<label><?php esc_html_e( 'Special request', 'momos-core' ); ?>
			<select name="has_special_request">
				<option value=""><?php esc_html_e( 'Any', 'momos-core' ); ?></option>
				<option value="1" <?php selected( $get( 'has_special_request' ), '1' ); ?>><?php esc_html_e( 'Has special request', 'momos-core' ); ?></option>
			</select>
		</label>
		<label><?php esc_html_e( 'Sort', 'momos-core' ); ?>
			<select name="sort">
				<option value="requested_start" <?php selected( $get( 'sort' ), 'requested_start' ); ?>><?php esc_html_e( 'Requested time', 'momos-core' ); ?></option>
				<option value="created_at" <?php selected( $get( 'sort' ), 'created_at' ); ?>><?php esc_html_e( 'Received', 'momos-core' ); ?></option>
			</select>
		</label>
		<label><?php esc_html_e( 'Order', 'momos-core' ); ?>
			<select name="order">
				<option value="asc" <?php selected( $get( 'order' ), 'asc' ); ?>><?php esc_html_e( 'Ascending', 'momos-core' ); ?></option>
				<option value="desc" <?php selected( $get( 'order' ), 'desc' ); ?>><?php esc_html_e( 'Descending', 'momos-core' ); ?></option>
			</select>
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'momos-core' ); ?></button>
	</form>

	<?php if ( ! $rows ) : ?>
		<div class="momos-card">
			<p><?php esc_html_e( 'No requests waiting.', 'momos-core' ); ?></p>
			<p><a href="<?php echo esc_url( InboxPage::url( [ 'view' => 'upcoming' ] ) ); ?>"><?php esc_html_e( 'Upcoming confirmed', 'momos-core' ); ?></a> · <a href="<?php echo esc_url( InboxPage::url( [ 'view' => 'attendance' ] ) ); ?>"><?php esc_html_e( 'Attendance due', 'momos-core' ); ?></a></p>
		</div>
	<?php else : ?>
		<table class="widefat striped momos-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'Reservation requests', 'momos-core' ); ?></caption>
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Date / time', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Party', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Guest', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Branch', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Received', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Handled by', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Flags', 'momos-core' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<?php $status = ReservationStatus::from( $r['status'] ); ?>
				<tr>
					<td class="momos-when-cell" data-label="<?php esc_attr_e( 'Date / time', 'momos-core' ); ?>">
						<a class="momos-when" href="<?php echo esc_url( InboxPage::url( [ 'id' => $r['id'] ] ) ); ?>"><?php echo esc_html( $r['requested_date'] . ' ' . $r['requested_time'] ); ?></a>
						<br><span class="momos-muted"><bdi><?php echo esc_html( $r['reference_display'] ); ?></bdi></span>
					</td>
					<td data-label="<?php esc_attr_e( 'Party', 'momos-core' ); ?>"><?php echo (int) $r['party_size']; ?></td>
					<td data-label="<?php esc_attr_e( 'Guest', 'momos-core' ); ?>"><bdi dir="auto"><?php echo esc_html( $r['guest_given_name'] ); ?></bdi> <span class="momos-muted">(<?php echo esc_html( Lang::native( $r['preferred_lang'] ) ); ?>)</span></td>
					<td data-label="<?php esc_attr_e( 'Branch', 'momos-core' ); ?>"><?php echo esc_html( $r['branch_code'] ); ?>
					<?php
					if ( $r['flags']['test_branch'] ) :
						?>
						<span class="momos-flag momos-flag--warn">TEST</span><?php endif; ?></td>
					<td data-label="<?php esc_attr_e( 'Status', 'momos-core' ); ?>"><span class="momos-badge momos-badge--<?php echo esc_attr( $status->value ); ?>"><?php echo esc_html( $status->staff_label() ); ?></span>
						<?php
						if ( 'overdue' === $r['sla_state'] ) :
							?>
							<span class="momos-flag momos-flag--alert"><?php esc_html_e( 'Overdue', 'momos-core' ); ?></span>
							<?php
elseif ( 'due' === $r['sla_state'] ) :
	?>
							<span class="momos-flag momos-flag--warn"><?php esc_html_e( 'Reply soon', 'momos-core' ); ?></span><?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Received', 'momos-core' ); ?>"><?php echo esc_html( human_time_diff( (int) strtotime( (string) $r['created_at'] ), \Momos\Kernel\Clock\Clock::utc()->getTimestamp() ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Handled by', 'momos-core' ); ?>"><?php echo $r['handled_by'] ? esc_html( sprintf( /* translators: %s: staff name */ __( 'Claimed by %s', 'momos-core' ), $r['handled_by']['name'] ) ) : esc_html__( 'Unclaimed', 'momos-core' ); ?></td>
					<td data-label="<?php esc_attr_e( 'Flags', 'momos-core' ); ?>">
						<?php
						if ( $r['flags']['needs_action'] ) :
							?>
							<span class="momos-flag momos-flag--alert"><?php esc_html_e( 'Needs action', 'momos-core' ); ?></span><?php endif; ?>
						<?php
						if ( $r['flags']['customer_not_notified'] ) :
							?>
							<span class="momos-flag momos-flag--alert"><?php esc_html_e( 'Guest not notified — call', 'momos-core' ); ?></span><?php endif; ?>
						<?php
						if ( $r['flags']['carrier_email'] ) :
							?>
							<span class="momos-flag"><?php esc_html_e( 'Carrier email', 'momos-core' ); ?></span><?php endif; ?>
						<?php
						if ( $r['flags']['has_special_request'] ) :
							?>
							<span class="momos-flag"><?php esc_html_e( 'Special request', 'momos-core' ); ?></span><?php endif; ?>
						<?php
						if ( $r['flags']['captcha_unverified'] ) :
							?>
							<span class="momos-flag momos-flag--warn"><?php esc_html_e( 'Spam check unavailable', 'momos-core' ); ?></span><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<nav class="momos-pagination" aria-label="<?php esc_attr_e( 'Pages', 'momos-core' ); ?>">
				<?php
				if ( $page > 1 ) :
					?>
					<a class="button" href="<?php echo esc_url( InboxPage::url( array_merge( $base_q, [ 'paged' => $page - 1 ] ) ) ); ?>"><?php esc_html_e( 'Previous', 'momos-core' ); ?></a><?php endif; ?>
				<span><?php echo esc_html( sprintf( /* translators: 1: page, 2: pages, 3: total */ __( 'Page %1$d of %2$d (%3$d requests)', 'momos-core' ), $page, $pages, $total ) ); ?></span>
				<?php
				if ( $page < $pages ) :
					?>
					<a class="button" href="<?php echo esc_url( InboxPage::url( array_merge( $base_q, [ 'paged' => $page + 1 ] ) ) ); ?>"><?php esc_html_e( 'Next', 'momos-core' ); ?></a><?php endif; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $can_export ) : ?>
		<details class="momos-sheet" style="margin-block-start:16px">
			<summary><?php esc_html_e( 'Export CSV', 'momos-core' ); ?></summary>
			<div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( \Momos\Reservation\Admin\ExportHandler::ACTION ); ?>">
					<?php wp_nonce_field( \Momos\Reservation\Admin\ExportHandler::ACTION ); ?>
					<fieldset><legend><?php esc_html_e( 'Branches', 'momos-core' ); ?></legend>
						<?php foreach ( $branches as $b ) : ?>
							<label><input type="checkbox" name="branch[]" value="<?php echo esc_attr( $b['code'] ); ?>" <?php checked( ! $b['is_test'] ); ?>> <?php echo esc_html( $b['code'] . ' — ' . $b['name'] . ( $b['is_test'] ? ' (TEST)' : '' ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<fieldset><legend><?php esc_html_e( 'Statuses', 'momos-core' ); ?></legend>
						<?php foreach ( ReservationStatus::cases() as $s ) : ?>
							<label><input type="checkbox" name="status[]" value="<?php echo esc_attr( $s->value ); ?>" checked> <?php echo esc_html( $s->staff_label() ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<label><?php esc_html_e( 'Requested date from', 'momos-core' ); ?> <input type="date" name="date_from" required></label>
					<label><?php esc_html_e( 'Requested date to', 'momos-core' ); ?> <input type="date" name="date_to" required></label>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %d: months */ __( 'At most %d months per export.', 'momos-core' ), \Momos\Reservation\Support\Options::int( 'momo_export_max_range_months' ) ) ); ?></p>
					<label><?php esc_html_e( 'Reason for export (required)', 'momos-core' ); ?> <input type="text" name="reason" required maxlength="200" class="regular-text"></label>
					<label><input type="checkbox" name="include_special_requests" value="1"> <?php esc_html_e( 'Include special requests', 'momos-core' ); ?></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Download CSV', 'momos-core' ); ?></button>
				</form>
			</div>
		</details>
	<?php endif; ?>
</div>
