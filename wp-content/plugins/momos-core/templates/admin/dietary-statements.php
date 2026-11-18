<?php
/**
 * SCR-A15 dietary statements view.
 *
 * @var array<string,array<string,mixed>> $rows
 * @var bool                              $can_edit
 * @var bool                              $can_trans
 * @var array{confirmed:bool,confirmed_by:?int,confirmed_at:?string} $cross
 * @var list<string>|false                $errors
 */

use Momos\Dietary\Domain\ClaimType;
use Momos\Kernel\I18n\Lang;

defined( 'ABSPATH' ) || exit;

$types    = ClaimType::statement_types();
$statuses = [ 'missing' => __( 'Missing', 'momos-core' ), 'draft' => __( 'Draft', 'momos-core' ), 'needs_review' => __( 'Needs review', 'momos-core' ), 'approved' => __( 'Approved', 'momos-core' ) ];
$nonce    = static fn() => wp_nonce_field( 'momos_statement_save' );
?>
<div class="wrap momos-admin">
	<h1><?php esc_html_e( 'Dietary statements', 'momos-core' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Halal and alcohol statements are shown only from approved templates; factual values ({certifier}, {cert_no} …) come from the halal register. Translations must be human translations approved per language.', 'momos-core' ); ?></p>
	<?php if ( ! $can_edit ) : ?>
		<p>🔒 <?php esc_html_e( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ); ?></p>
	<?php endif; ?>
	<?php if ( $errors ) : ?>
		<div class="notice notice-error" role="alert"><ul><?php foreach ( (array) $errors as $e ) : ?><li><?php echo esc_html( (string) $e ); ?></li><?php endforeach; ?></ul></div>
	<?php elseif ( isset( $_GET['updated'] ) ) : // phpcs:ignore ?>
		<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Saved.', 'momos-core' ); ?></p></div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Cross-contact statement', 'momos-core' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="momos_statement_save"><input type="hidden" name="mode" value="cross_contact">
		<?php $nonce(); ?>
		<label><input type="checkbox" name="confirmed" value="1" <?php checked( $cross['confirmed'] ); ?> <?php disabled( ! $can_edit ); ?>> <?php esc_html_e( 'The food-safety owner confirmed the shared-kitchen practice (shows the cross-contact note on every dish)', 'momos-core' ); ?></label>
		<?php if ( $cross['confirmed_at'] ) : ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: 1: user 2: date */ __( 'Last changed by %1$s at %2$s (UTC)', 'momos-core' ), (string) ( get_userdata( (int) $cross['confirmed_by'] )->display_name ?? '—' ), (string) $cross['confirmed_at'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( $can_edit ) : ?><p><button type="submit" class="button"><?php esc_html_e( 'Save', 'momos-core' ); ?></button></p><?php endif; ?>
	</form>

	<h2><?php esc_html_e( 'Templates', 'momos-core' ); ?></h2>
	<?php foreach ( $rows as $key => $row ) : ?>
		<div class="momos-card" id="stmt-<?php echo esc_attr( str_replace( '.', '-', $key ) ); ?>">
			<h3><code><?php echo esc_html( $key ); ?></code> — <?php echo esc_html( (string) ( $types[ $row['claim_type'] ] ?? $row['claim_type'] ) ); ?>
				<?php echo $row['approved'] ? ' ✔ ' . esc_html__( 'Approved', 'momos-core' ) : ' — ' . esc_html__( 'Not approved', 'momos-core' ); ?></h3>
			<?php if ( $can_edit ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="momos_statement_save"><input type="hidden" name="mode" value="row"><input type="hidden" name="original_key" value="<?php echo esc_attr( $key ); ?>">
					<?php $nonce(); ?>
					<p><label><?php esc_html_e( 'Key', 'momos-core' ); ?> <input type="text" name="key" value="<?php echo esc_attr( $key ); ?>" pattern="[a-z0-9_.]{3,40}" required></label>
					<label><?php esc_html_e( 'Type', 'momos-core' ); ?> <select name="claim_type"><?php foreach ( $types as $t => $label ) : ?><option value="<?php echo esc_attr( $t ); ?>" <?php selected( $row['claim_type'], $t ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
					<p><label for="stmt-ja-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Japanese text (master)', 'momos-core' ); ?></label><br>
					<textarea id="stmt-ja-<?php echo esc_attr( $key ); ?>" name="text_ja" rows="3" class="large-text" maxlength="400" required><?php echo esc_textarea( (string) $row['text_ja'] ); ?></textarea></p>
					<p><label><input type="checkbox" name="approved" value="1" <?php checked( $row['approved'] ); ?>> <?php esc_html_e( 'Approved (counsel + certifier sign-off recorded)', 'momos-core' ); ?></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save template', 'momos-core' ); ?></button></p>
				</form>
			<?php else : ?>
				<p lang="ja"><?php echo esc_html( (string) $row['text_ja'] ); ?></p>
			<?php endif; ?>
			<?php if ( $row['approved_at'] ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: 1: user 2: date */ __( 'Approved by %1$s at %2$s (UTC)', 'momos-core' ), (string) ( get_userdata( (int) $row['approved_by'] )->display_name ?? '—' ), (string) $row['approved_at'] ) ); ?></p>
			<?php endif; ?>
			<table class="widefat">
				<thead><tr><th scope="col"><?php esc_html_e( 'Language', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Translation', 'momos-core' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'momos-core' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( Lang::targets() as $lang ) : $tr = $row['i18n'][ $lang ] ?? [ 'text' => '', 'status' => 'missing' ]; ?>
					<tr>
						<th scope="row"><?php echo esc_html( Lang::native( $lang ) . ' (' . $lang . ')' ); ?></th>
						<td>
							<?php if ( $can_trans ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="momos_statement_save"><input type="hidden" name="mode" value="translation"><input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>"><input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>">
									<?php $nonce(); ?>
									<textarea name="text" rows="2" class="large-text" lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo Lang::is_rtl( $lang ) ? 'rtl' : 'ltr'; ?>" aria-label="<?php echo esc_attr( $key . ' ' . $lang ); ?>"><?php echo esc_textarea( (string) $tr['text'] ); ?></textarea>
									<select name="status" aria-label="<?php esc_attr_e( 'Status', 'momos-core' ); ?>"><?php foreach ( [ 'draft', 'needs_review', 'approved' ] as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>" <?php selected( $tr['status'], $s ); ?>><?php echo esc_html( $statuses[ $s ] ); ?></option><?php endforeach; ?></select>
									<button type="submit" class="button"><?php esc_html_e( 'Save', 'momos-core' ); ?></button>
								</form>
							<?php else : ?>
								<span lang="<?php echo esc_attr( $lang ); ?>"><?php echo esc_html( (string) $tr['text'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $statuses[ $tr['status'] ] ?? $tr['status'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endforeach; ?>

	<?php if ( $can_edit ) : ?>
		<h2><?php esc_html_e( 'Add template', 'momos-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="momos-card">
			<input type="hidden" name="action" value="momos_statement_save"><input type="hidden" name="mode" value="row">
			<?php $nonce(); ?>
			<p><label><?php esc_html_e( 'Key', 'momos-core' ); ?> <input type="text" name="key" pattern="[a-z0-9_.]{3,40}" required></label>
			<label><?php esc_html_e( 'Type', 'momos-core' ); ?> <select name="claim_type"><?php foreach ( $types as $t => $label ) : ?><option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<p><label for="stmt-new-ja"><?php esc_html_e( 'Japanese text (master)', 'momos-core' ); ?></label><br><textarea id="stmt-new-ja" name="text_ja" rows="3" class="large-text" maxlength="400" required></textarea></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add template', 'momos-core' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
<style>.momos-card{background:#fff;border:1px solid #c3c4c7;padding:1em;margin:1em 0}</style>
