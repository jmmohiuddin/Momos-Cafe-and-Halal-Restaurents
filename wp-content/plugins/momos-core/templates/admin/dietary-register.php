<?php
/**
 * SCR-A12 halal register (read-only summary + actions for CAP-DCA).
 *
 * @var list<array<string,mixed>> $rows
 * @var bool                      $can_manage
 * @var bool                      $scoped
 * @var array<string,list<int>>|null $lists
 * @var int                       $revoke
 */

use Momos\Dietary\Admin\CertificateEditor;
use Momos\Dietary\Domain\CertificateScope;
use Momos\Dietary\Domain\ClaimStatus;
use Momos\Dietary\Domain\ClaimType;
use Momos\Menu\Support\Branches;

defined( 'ABSPATH' ) || exit;

$types  = ClaimType::choices();
$scopes = CertificateScope::choices();
$states = ClaimStatus::choices();
?>
<div class="wrap momos-admin">
	<h1><?php esc_html_e( 'Halal register', 'momos-core' ); ?>
		<?php if ( $can_manage ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=momo_certificate' ) ); ?>"><?php esc_html_e( 'Add claim record', 'momos-core' ); ?></a>
		<?php endif; ?>
	</h1>
	<?php if ( ! $can_manage ) : ?>
		<p class="description">🔒 <?php esc_html_e( 'Read-only summary. Only Dietary Claims Approvers can change records or open evidence.', 'momos-core' ); ?></p>
	<?php endif; ?>

	<?php if ( $revoke ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="momos-card">
			<h2><?php echo esc_html( sprintf( /* translators: %s: record title */ __( 'Revoke “%s”', 'momos-core' ), get_the_title( $revoke ) ) ); ?></h2>
			<input type="hidden" name="action" value="momos_cert_action"><input type="hidden" name="cert" value="<?php echo (int) $revoke; ?>"><input type="hidden" name="do" value="revoke">
			<?php wp_nonce_field( 'momos_cert_revoke_' . $revoke ); ?>
			<label for="momos-revoke-reason"><?php esc_html_e( 'Reason (required)', 'momos-core' ); ?></label>
			<input id="momos-revoke-reason" type="text" name="reason" maxlength="255" required class="regular-text">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Revoke record', 'momos-core' ); ?></button>
			<p class="description"><?php esc_html_e( 'Revocation is final; certified wording and the mark disappear immediately. Renewal requires a new record.', 'momos-core' ); ?></p>
		</form>
	<?php endif; ?>

	<table class="widefat striped">
		<caption class="screen-reader-text"><?php esc_html_e( 'Claim records', 'momos-core' ); ?></caption>
		<thead><tr>
			<th scope="col"><?php esc_html_e( 'Record', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Claim type', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Certifier', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Scope', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Branches / dishes', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Valid until', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Status', 'momos-core' ); ?></th>
			<?php
			if ( $can_manage ) :
				?>
				<th scope="col"><?php esc_html_e( 'Actions', 'momos-core' ); ?></th><?php endif; ?>
		</tr></thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No records.', 'momos-core' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $r ) : ?>
			<tr>
				<td><?php echo esc_html( (string) $r['title'] ); ?></td>
				<td><?php echo esc_html( (string) ( $types[ $r['claim_type'] ] ?? $r['claim_type'] ) ); ?></td>
				<td><?php echo esc_html( '' !== $r['certifier'] ? (string) $r['certifier'] : '—' ); ?></td>
				<td><?php echo esc_html( (string) ( $scopes[ $r['scope'] ] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( implode( ', ', array_map( static fn( $b ) => Branches::code( (int) $b ) ?: get_the_title( (int) $b ), $r['branches'] ) ) ); ?><?php echo $r['dishes'] ? esc_html( ' · ' . sprintf( /* translators: %d: dishes */ _n( '%d dish', '%d dishes', count( $r['dishes'] ), 'momos-core' ), count( $r['dishes'] ) ) ) : ''; ?></td>
				<td>
					<?php echo esc_html( '' !== $r['valid_until'] ? (string) $r['valid_until'] : '—' ); ?>
					<?php if ( null !== $r['days_left'] && 'active' === $r['status'] ) : ?>
						<br><span class="<?php echo $r['days_left'] <= 30 ? 'momos-warn' : ''; ?>"><?php echo esc_html( $r['days_left'] < 0 ? __( 'Expired', 'momos-core' ) : sprintf( /* translators: %d: days */ _n( 'expires in %d day', 'expires in %d days', $r['days_left'], 'momos-core' ), $r['days_left'] ) . ( $r['days_left'] <= 30 ? ' — ' . __( 'Expires soon', 'momos-core' ) : '' ) ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( (string) ( $states[ $r['status'] ] ?? $r['status'] ) ); ?><?php echo $r['displayable'] ? '' : ( 'active' === $r['status'] ? ' — ' . esc_html__( 'not displayed', 'momos-core' ) : '' ); ?></td>
				<?php if ( $can_manage ) : ?>
					<td>
						<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $r['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'momos-core' ); ?></a>
						<?php if ( in_array( $r['status'], [ 'active', 'pending_verification' ], true ) ) : ?>
							· <a href="<?php echo esc_url( add_query_arg( 'revoke', (int) $r['id'] ) ); ?>"><?php esc_html_e( 'Revoke', 'momos-core' ); ?></a>
						<?php endif; ?>
						<?php if ( is_array( get_post_meta( (int) $r['id'], 'momo_cert_evidence', true ) ) ) : ?>
							· <a href="<?php echo esc_url( CertificateEditor::download_url( (int) $r['id'], 'evidence' ) ); ?>"><?php esc_html_e( 'Evidence', 'momos-core' ); ?></a>
						<?php endif; ?>
					</td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( null !== $lists ) : ?>
		<h2><?php esc_html_e( 'Re-verification', 'momos-core' ); ?></h2>
		<?php
		$sections = [
			'expiring'        => __( 'Certificates expiring within 30 days', 'momos-core' ),
			'reverify'        => __( 'Active records last verified more than 92 days ago', 'momos-core' ),
			'dish_stale'      => __( 'Dishes whose allergen data was verified more than 183 days ago', 'momos-core' ),
			'dish_unverified' => __( 'Dishes with unverified allergens or review required', 'momos-core' ),
		];
		foreach ( $sections as $key => $label ) :
			?>
			<h3><?php echo esc_html( $label ); ?> (<?php echo (int) count( $lists[ $key ] ); ?>)</h3>
			<?php if ( $lists[ $key ] ) : ?>
				<ul>
				<?php foreach ( array_slice( $lists[ $key ], 0, 100 ) as $id ) : ?>
					<li><?php echo current_user_can( 'edit_post', $id ) ? '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : esc_html( get_the_title( $id ) ); ?></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
<style>.momos-warn{color:#b32d2e;font-weight:600}.momos-card{background:#fff;border:1px solid #c3c4c7;padding:1em;margin:1em 0}</style>
