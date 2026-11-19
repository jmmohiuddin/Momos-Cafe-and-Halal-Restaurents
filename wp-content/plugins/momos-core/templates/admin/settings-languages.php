<?php
/**
 * SCR-A15 Settings → Languages (language launch gate).
 *
 * @var array<string, array{enabled:bool,gaps:list<string>,record:array<string,mixed>}> $rows
 * @var array{0:string,1:list<string>}|false $msg
 * @var string $today
 */

use Momos\Admin\Settings\LanguageGate;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;

defined( 'ABSPATH' ) || exit;
?>
<?php if ( is_array( $msg ) ) : ?>
	<div class="notice <?php echo 'error' === $msg[0] ? 'notice-error' : 'notice-success'; ?>" role="<?php echo 'error' === $msg[0] ? 'alert' : 'status'; ?>">
		<?php foreach ( (array) $msg[1] as $line ) : ?>
			<p><?php echo esc_html( (string) $line ); ?></p>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
<h2><?php esc_html_e( 'Public languages', 'momos-core' ); ?></h2>
<p class="description"><?php esc_html_e( 'A language becomes public only when all launch-critical content is translated and reviewed and the native-review sign-off is recorded. Hiding a live language removes it from search results and links.', 'momos-core' ); ?></p>
<div class="momos-table-scroll">
<table class="widefat striped momos-languages">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Language', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'State', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Launch-critical gaps', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Native-review sign-off', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Last change', 'momos-core' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Action', 'momos-core' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td><?php echo esc_html( Lang::native( 'ja' ) . ' (Japanese)' ); ?></td>
			<td><?php esc_html_e( 'Enabled', 'momos-core' ); ?></td>
			<td colspan="4"><?php esc_html_e( 'Source language — always public.', 'momos-core' ); ?></td>
		</tr>
		<?php foreach ( $rows as $code => $row ) : ?>
			<?php
			$rec  = $row['record'];
			$fid  = 'momos-lang-' . $code;
			$by   = isset( $rec['changed_by'] ) ? get_userdata( (int) $rec['changed_by'] ) : false;
			$when = isset( $rec['changed_at'] ) ? Clock::from_mysql_utc( (string) $rec['changed_at'] ) : null;
			?>
			<tr>
				<td><?php echo esc_html( Lang::native( $code ) . ' (' . Lang::ALL[ $code ]['english'] . ')' ); ?></td>
				<td><?php echo esc_html( $row['enabled'] ? __( 'Enabled', 'momos-core' ) : __( 'Hidden', 'momos-core' ) ); ?></td>
				<td>
					<?php if ( $row['gaps'] ) : ?>
						<?php echo esc_html( sprintf( /* translators: %d: number of items */ _n( '%d item missing or unreviewed', '%d items missing or unreviewed', count( $row['gaps'] ), 'momos-core' ), count( $row['gaps'] ) ) ); ?>
					<?php else : ?>
						<?php esc_html_e( 'None reported', 'momos-core' ); ?>
					<?php endif; ?>
					<?php if ( class_exists( \Momos\Kernel\I18n\Admin\TranslationGaps::class ) ) : ?>
						<br><a href="<?php echo esc_url( admin_url( 'admin.php?page=momos-translation-gaps&lang=' . $code . '&lccs=1' ) ); ?>">
							<?php echo esc_html( sprintf( /* translators: %d: percent */ __( 'Launch-critical content: %d%% approved', 'momos-core' ), \Momos\Kernel\I18n\Admin\TranslationGaps::lccs_percent( $code ) ) ); ?>
						</a>
					<?php endif; ?>
				</td>
				<td>
					<form id="<?php echo esc_attr( $fid ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( LanguageGate::ACTION ); ?>">
						<input type="hidden" name="lang" value="<?php echo esc_attr( $code ); ?>">
						<input type="hidden" name="op" value="<?php echo $row['enabled'] ? 'disable' : 'enable'; ?>">
						<?php wp_nonce_field( LanguageGate::ACTION ); ?>
						<?php if ( ! $row['enabled'] ) : ?>
							<label for="<?php echo esc_attr( $fid ); ?>-by"><?php esc_html_e( 'Reviewer name', 'momos-core' ); ?></label>
							<input type="text" id="<?php echo esc_attr( $fid ); ?>-by" name="signoff_by" maxlength="100" value="<?php echo esc_attr( (string) ( $rec['lccs_signoff_by'] ?? '' ) ); ?>">
							<label for="<?php echo esc_attr( $fid ); ?>-at"><?php esc_html_e( 'Date', 'momos-core' ); ?></label>
							<input type="date" id="<?php echo esc_attr( $fid ); ?>-at" name="signoff_at" max="<?php echo esc_attr( $today ); ?>" value="<?php echo esc_attr( (string) ( $rec['lccs_signoff_at'] ?? '' ) ); ?>">
							<label for="<?php echo esc_attr( $fid ); ?>-ref"><?php esc_html_e( 'Reference', 'momos-core' ); ?></label>
							<input type="text" id="<?php echo esc_attr( $fid ); ?>-ref" name="signoff_ref" maxlength="200" value="<?php echo esc_attr( (string) ( $rec['lccs_signoff_ref'] ?? '' ) ); ?>">
						<?php else : ?>
							<?php echo esc_html( trim( (string) ( $rec['lccs_signoff_by'] ?? '' ) . ' ' . (string) ( $rec['lccs_signoff_at'] ?? '' ) ) ?: '—' ); ?>
						<?php endif; ?>
					</form>
				</td>
				<td><?php echo esc_html( $when ? ( ( $by ? $by->display_name : '' ) . ' ' . $when->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) ) : '—' ); ?></td>
				<td>
					<?php if ( $row['enabled'] ) : ?>
						<button type="submit" form="<?php echo esc_attr( $fid ); ?>" class="button" onclick="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Hiding a live language removes it from search results and links. Continue?', 'momos-core' ); ?>"><?php esc_html_e( 'Hide', 'momos-core' ); ?></button>
					<?php else : ?>
						<button type="submit" form="<?php echo esc_attr( $fid ); ?>" class="button button-primary"><?php esc_html_e( 'Enable', 'momos-core' ); ?></button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
</div>
