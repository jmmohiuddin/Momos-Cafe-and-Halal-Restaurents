<?php
/**
 * SCR-A16 Audit log (read-only).
 *
 * @var array<string,mixed>          $filters
 * @var list<array<string,mixed>>    $rows
 * @var int                          $total
 * @var int                          $pages
 * @var array<int,string>            $actors
 * @var array<int,string>            $branches
 * @var list<string>                 $types
 * @var array<string,list<string>>   $groups
 */

use Momos\Audit\Admin\AuditLogScreen;

defined( 'ABSPATH' ) || exit;

$base = [
	'page'         => AuditLogScreen::SLUG,
	'from'         => $filters['from'],
	'to'           => $filters['to'],
	'actor'        => $filters['actor'],
	'audit_action' => $filters['action'],
	'object_type'  => $filters['object_type'],
	'object_id'    => $filters['object_id'] ?: '',
	'branch'       => $filters['branch_id'] ?: '',
];
?>
<div class="wrap momos-admin momos-audit">
	<h1><?php esc_html_e( 'Audit log', 'momos-core' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Read-only record of changes and security events. Times are Japan time (JST). Personal data is masked.', 'momos-core' ); ?></p>
	<?php if ( '' !== $filters['error'] ) : ?>
		<div class="notice notice-error" role="alert"><p><?php echo esc_html( $filters['error'] ); ?></p></div>
	<?php endif; ?>

	<form method="get" class="momos-filters" role="search" aria-label="<?php esc_attr_e( 'Filter audit entries', 'momos-core' ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( AuditLogScreen::SLUG ); ?>">
		<p>
			<label for="momos-audit-from"><?php esc_html_e( 'From', 'momos-core' ); ?></label>
			<input type="date" id="momos-audit-from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>">
			<label for="momos-audit-to"><?php esc_html_e( 'To', 'momos-core' ); ?></label>
			<input type="date" id="momos-audit-to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>">

			<label for="momos-audit-actor"><?php esc_html_e( 'Actor', 'momos-core' ); ?></label>
			<select id="momos-audit-actor" name="actor">
				<option value=""><?php esc_html_e( 'All', 'momos-core' ); ?></option>
				<option value="system" <?php selected( 'system', $filters['actor'] ); ?>><?php esc_html_e( 'System', 'momos-core' ); ?></option>
				<?php foreach ( $actors as $id => $label ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( (string) $id, (string) $filters['actor'] ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="momos-audit-action"><?php esc_html_e( 'Action', 'momos-core' ); ?></label>
			<select id="momos-audit-action" name="audit_action">
				<option value=""><?php esc_html_e( 'All', 'momos-core' ); ?></option>
				<?php foreach ( $groups as $prefix => $actions ) : ?>
					<optgroup label="<?php echo esc_attr( $prefix ); ?>">
						<option value="<?php echo esc_attr( $prefix . '.*' ); ?>" <?php selected( $prefix . '.*', $filters['action'] ); ?>><?php echo esc_html( $prefix . '.*' ); ?></option>
						<?php foreach ( $actions as $a ) : ?>
							<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $a, $filters['action'] ); ?>><?php echo esc_html( $a ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="momos-audit-type"><?php esc_html_e( 'Object type', 'momos-core' ); ?></label>
			<select id="momos-audit-type" name="object_type">
				<option value=""><?php esc_html_e( 'All', 'momos-core' ); ?></option>
				<?php foreach ( $types as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, $filters['object_type'] ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="momos-audit-oid"><?php esc_html_e( 'Object ID', 'momos-core' ); ?></label>
			<input type="number" min="1" id="momos-audit-oid" name="object_id" class="small-text" value="<?php echo esc_attr( (string) ( $filters['object_id'] ?: '' ) ); ?>">
			<label for="momos-audit-branch"><?php esc_html_e( 'Branch', 'momos-core' ); ?></label>
			<select id="momos-audit-branch" name="branch">
				<option value=""><?php esc_html_e( 'All', 'momos-core' ); ?></option>
				<?php foreach ( $branches as $id => $label ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( (int) $id, (int) $filters['branch_id'] ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'momos-core' ), 'secondary', '', false ); ?>
		</p>
	</form>

	<p class="momos-audit-count" role="status">
		<?php echo esc_html( sprintf( /* translators: %s: number of entries */ _n( '%s entry', '%s entries', $total, 'momos-core' ), number_format_i18n( $total ) ) ); ?>
	</p>

	<div class="momos-table-scroll">
	<table class="widefat striped momos-audit-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Time (JST)', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actor', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Role', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Action', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Object', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Branch', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Changes', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'IP (truncated)', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Request ID', 'momos-core' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $rows ) : ?>
			<tr><td colspan="9"><?php echo esc_html( momos_t( 'admin.audit.empty', [], 'ja' === substr( get_user_locale(), 0, 2 ) ? 'ja' : 'en' ) ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $r ) : ?>
			<?php
			$oid  = null !== $r['object_id'] ? (int) $r['object_id'] : null;
			$link = AuditLogScreen::object_link( (string) $r['object_type'], $oid );
			$obj  = (string) $r['object_type'] . ( $oid ? ' #' . $oid : '' );
			?>
			<tr>
				<td><?php echo esc_html( AuditLogScreen::time_jst( (string) $r['occurred_at'] ) ); ?></td>
				<td><?php echo esc_html( 'system' === $r['actor_type'] ? __( 'System', 'momos-core' ) : AuditLogScreen::actor_label( null !== $r['actor_id'] ? (int) $r['actor_id'] : null ) ); ?></td>
				<td><?php echo esc_html( (string) $r['actor_role'] ); ?></td>
				<td><code><?php echo esc_html( (string) $r['action'] ); ?></code></td>
				<td><?php echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $obj ) . '</a>' : esc_html( $obj ); ?></td>
				<td><?php echo esc_html( AuditLogScreen::branch_code( null !== $r['branch_id'] ? (int) $r['branch_id'] : null ) ); ?></td>
				<td>
					<?php foreach ( AuditLogScreen::change_lines( $r['changes'] ) as $line ) : ?>
						<div><bdi><?php echo esc_html( $line ); ?></bdi></div>
					<?php endforeach; ?>
				</td>
				<td><?php echo esc_html( (string) $r['ip_truncated'] ); ?></td>
				<td><code><?php echo esc_html( (string) $r['request_id'] ); ?></code></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>

	<?php if ( $pages > 1 ) : ?>
		<nav class="tablenav" aria-label="<?php esc_attr_e( 'Audit pages', 'momos-core' ); ?>"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				(string) paginate_links(
					[
						'base'      => add_query_arg( array_merge( $base, [ 'paged' => '%#%' ] ), admin_url( 'admin.php' ) ),
						'format'    => '',
						'current'   => (int) $filters['page'],
						'total'     => $pages,
						'prev_text' => __( '« Previous', 'momos-core' ),
						'next_text' => __( 'Next »', 'momos-core' ),
					]
				)
			);
			?>
		</div></nav>
	<?php endif; ?>
</div>
