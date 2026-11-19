<?php
/**
 * SCR-A17 reservation report (FR-RES-022). Counts and rates only — no personal data. Every figure is a
 * table (no chart without a table); a zero denominator shows "Not enough data for this period".
 *
 * @var array<string,mixed> $report ReportService::build()
 * @var list<array{id:int,code:string,name:string,is_test:bool}> $branches
 * @var string $from @var string $to @var string $basis @var string $lang @var string $branch @var ?string $error
 */

defined( 'ABSPATH' ) || exit;

use Momos\Kernel\I18n\Lang;
use Momos\Reservation\Admin\ReportPage;
use Momos\Reservation\Domain\ReservationStatus;

$pct   = static fn( ?float $v ): string => null === $v ? __( 'Not enough data for this period', 'momos-core' ) : number_format_i18n( $v * 100, 1 ) . '%';
$mins  = static fn( ?int $v ): string => null === $v ? __( 'Not enough data for this period', 'momos-core' ) : sprintf( /* translators: %d minutes */ __( '%d min', 'momos-core' ), $v );
$table = static function ( string $caption, string $col, array $groups ) use ( $pct, $mins ): void {
	?>
	<table class="widefat striped momos-table" style="margin-block-end:24px">
		<caption style="text-align:start;font-weight:600;padding-block:8px"><?php echo esc_html( $caption ); ?></caption>
		<thead><tr>
			<th scope="col"><?php echo esc_html( $col ); ?></th>
			<th scope="col"><?php esc_html_e( 'Requests', 'momos-core' ); ?></th>
			<?php
			foreach ( ReservationStatus::cases() as $s ) :
				?>
				<th scope="col"><?php echo esc_html( $s->staff_label() ); ?></th><?php endforeach; ?>
			<th scope="col"><?php esc_html_e( 'Acceptance rate', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Expiry rate', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'No-show rate', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Response median', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Response p90', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Within SLA', 'momos-core' ); ?></th>
		</tr></thead>
		<tbody>
		<?php if ( ! $groups ) : ?>
			<tr><td colspan="15"><?php esc_html_e( 'No requests in this period.', 'momos-core' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $groups as $key => $m ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( (string) $key ); ?></th>
				<td><?php echo (int) $m['created']; ?></td>
				<?php
				foreach ( ReservationStatus::values() as $s ) :
					?>
					<td><?php echo (int) $m['counts'][ $s ]; ?></td><?php endforeach; ?>
				<td><?php echo esc_html( $pct( $m['acceptance_rate'] ) ); ?></td>
				<td><?php echo esc_html( $pct( $m['expiry_rate'] ) ); ?></td>
				<td><?php echo esc_html( $pct( $m['no_show_rate'] ) ); ?></td>
				<td><?php echo esc_html( $mins( $m['response_median'] ) ); ?></td>
				<td><?php echo esc_html( $mins( $m['response_p90'] ) ); ?></td>
				<td><?php echo esc_html( $pct( $m['within_sla'] ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
$t     = $report['total'];
require __DIR__ . '/reservation-styles.php';
?>
<div class="wrap momos-admin momos-res">
	<h1><?php esc_html_e( 'Reservation report', 'momos-core' ); ?></h1>
	<?php
	if ( $error ) :
		?>
		<div class="notice notice-error" role="alert"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
	<form method="get" class="momos-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( ReportPage::SLUG ); ?>">
		<label><?php esc_html_e( 'From', 'momos-core' ); ?><input type="date" name="date_from" value="<?php echo esc_attr( $from ); ?>"></label>
		<label><?php esc_html_e( 'To', 'momos-core' ); ?><input type="date" name="date_to" value="<?php echo esc_attr( $to ); ?>"></label>
		<label><?php esc_html_e( 'Date basis', 'momos-core' ); ?>
			<select name="basis">
				<option value="created" <?php selected( $basis, 'created' ); ?>><?php esc_html_e( 'Received date', 'momos-core' ); ?></option>
				<option value="requested" <?php selected( $basis, 'requested' ); ?>><?php esc_html_e( 'Requested date', 'momos-core' ); ?></option>
			</select>
		</label>
		<label><?php esc_html_e( 'Branch', 'momos-core' ); ?>
			<select name="branch">
				<option value=""><?php esc_html_e( 'All in my scope', 'momos-core' ); ?></option>
				<?php
				foreach ( $branches as $b ) :
					?>
					<option value="<?php echo esc_attr( $b['code'] ); ?>" <?php selected( $branch, $b['code'] ); ?>><?php echo esc_html( $b['code'] . ' — ' . $b['name'] ); ?></option><?php endforeach; ?>
			</select>
		</label>
		<label><?php esc_html_e( 'Reply language', 'momos-core' ); ?>
			<select name="lang">
				<option value=""><?php esc_html_e( 'All', 'momos-core' ); ?></option>
				<?php
				foreach ( Lang::codes() as $c ) :
					?>
					<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $lang, $c ); ?>><?php echo esc_html( Lang::native( $c ) ); ?></option><?php endforeach; ?>
			</select>
		</label>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply', 'momos-core' ); ?></button>
	</form>

	<div class="momos-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
		<?php
		foreach ( [
			__( 'Requests', 'momos-core' )        => number_format_i18n( (int) $t['created'] ),
			__( 'Acceptance rate', 'momos-core' ) => $pct( $t['acceptance_rate'] ),
			__( 'Expiry rate', 'momos-core' )     => $pct( $t['expiry_rate'] ),
			__( 'No-show rate', 'momos-core' )    => $pct( $t['no_show_rate'] ),
			__( 'Response median', 'momos-core' ) => $mins( $t['response_median'] ),
			__( 'Within SLA', 'momos-core' )      => $pct( $t['within_sla'] ),
		] as $label => $value ) :
			?>
			<div class="momos-card"><div class="momos-muted"><?php echo esc_html( $label ); ?></div><div class="momos-when"><?php echo esc_html( $value ); ?></div></div>
		<?php endforeach; ?>
	</div>
	<h2><?php esc_html_e( 'Details', 'momos-core' ); ?></h2>
	<?php
	$table( __( 'By branch', 'momos-core' ), __( 'Branch', 'momos-core' ), $report['by_branch'] );
	$table( __( 'By reply language', 'momos-core' ), __( 'Language', 'momos-core' ), $report['by_lang'] );
	$table( __( 'By ISO week (JST)', 'momos-core' ), __( 'Week', 'momos-core' ), $report['by_week'] );
	?>
	<h2><?php esc_html_e( 'Definitions', 'momos-core' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'Acceptance rate = accepted ÷ (accepted + declined + expired). Accepted counts every request the branch accepted, including those later attended, no-show or cancelled.', 'momos-core' ); ?></li>
		<li><?php esc_html_e( 'Expiry rate = expired ÷ (accepted + declined + expired).', 'momos-core' ); ?></li>
		<li><?php esc_html_e( 'No-show rate = no-show ÷ (attended + no-show).', 'momos-core' ); ?></li>
		<li><?php esc_html_e( 'Response time = minutes from receipt to the first accept or decline (median and 90th percentile).', 'momos-core' ); ?></li>
		<li><?php esc_html_e( 'Within SLA = share of accept/decline decisions made within the branch response target (branches without a target are excluded).', 'momos-core' ); ?></li>
		<li><?php esc_html_e( 'The TEST branch is excluded. The report contains no personal data.', 'momos-core' ); ?></li>
	</ul>
</div>
