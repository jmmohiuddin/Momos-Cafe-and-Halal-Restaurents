<?php
/**
 * CMP-HoursTable (ui-design-system.md §7.4): `<table>` with caption and scope headers; Day · Opening hours
 * · Last order; today bold + "(today)" text; closed day reads "Closed". Times are LTR isolates.
 *
 * @package momos
 * @var array{rows:list<array{weekday:int,day:string,hours_html:string,lo_html:string,today:bool,closed:bool}>,caption?:string,id?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_rows = (array) ( $args['rows'] ?? [] );
if ( ! $momos_rows ) {
	return;
}
$momos_lo  = (bool) array_filter( array_column( $momos_rows, 'lo_html' ) );
$momos_tag = [ 'bdi' => [ 'dir' => true ] ];
?>
<div class="momo-table-wrap">
	<table class="momo-hours"<?php echo ! empty( $args['id'] ) ? ' id="' . esc_attr( (string) $args['id'] ) . '"' : ''; ?>>
		<caption class="momo-visually-hidden"><?php echo esc_html( (string) ( $args['caption'] ?? momos_s( 'branch.hours.title' ) ) ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php echo esc_html( momos_s( 'branch.hours.day' ) ); ?></th>
				<th scope="col"><?php echo esc_html( momos_s( 'branch.hours.col_hours' ) ); ?></th>
				<?php
				if ( $momos_lo ) :
					?>
					<th scope="col"><?php echo esc_html( momos_s( 'branch.hours.last_order' ) ); ?></th><?php endif; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $momos_rows as $momos_r ) : ?>
				<tr class="<?php echo $momos_r['today'] ? 'is-today' : ''; ?><?php echo $momos_r['closed'] ? ' is-closed' : ''; ?>">
					<th scope="row"><?php echo esc_html( (string) $momos_r['day'] ); ?>
					<?php
					if ( $momos_r['today'] ) :
						?>
						<span class="momo-hours__today"><?php echo esc_html( momos_s( 'branch.today_marker' ) ); ?></span><?php endif; ?></th>
					<td><?php echo wp_kses( (string) $momos_r['hours_html'], $momos_tag ); ?></td>
					<?php
					if ( $momos_lo ) :
						?>
						<td><?php echo wp_kses( (string) $momos_r['lo_html'], $momos_tag ); ?></td><?php endif; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
