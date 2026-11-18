<?php
/**
 * Claim-term scan view. @var list<array<string,mixed>>|null $results
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap momos-admin">
	<h1><?php esc_html_e( 'Claim-term scan', 'momos-core' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Finds halal, certification, allergen-safety and ordering wording in published content and translations (all languages), and images whose file name or alt text contains “halal” or “seal”. Statements rendered from the halal register are not listed. Review every hit before GATE-02 and monthly.', 'momos-core' ); ?></p>
	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( \Momos\Dietary\Admin\ClaimTermScanPage::SLUG ); ?>">
		<input type="hidden" name="run" value="1">
		<?php wp_nonce_field( 'momos_claim_scan', '_wpnonce', false ); ?>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Run scan', 'momos-core' ); ?></button>
	</form>
	<?php if ( null !== $results ) : ?>
		<p role="status"><?php echo esc_html( sprintf( /* translators: %d: hits */ _n( '%d hit', '%d hits', count( $results ), 'momos-core' ), count( $results ) ) ); ?></p>
		<?php if ( $results ) : ?>
		<table class="widefat striped">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Type', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Title', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Language', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Field', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Matched term', 'momos-core' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Edit', 'momos-core' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $results as $r ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $r['object_type'] ); ?></td>
					<td><?php echo esc_html( (string) $r['title'] ); ?></td>
					<td><?php echo esc_html( (string) $r['lang'] ); ?></td>
					<td><?php echo esc_html( (string) $r['field'] ); ?></td>
					<td><bdi><?php echo esc_html( (string) $r['term'] ); ?></bdi></td>
					<td><?php if ( '' !== $r['edit_link'] ) : ?><a href="<?php echo esc_url( (string) $r['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'momos-core' ); ?></a><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	<?php endif; ?>
</div>
