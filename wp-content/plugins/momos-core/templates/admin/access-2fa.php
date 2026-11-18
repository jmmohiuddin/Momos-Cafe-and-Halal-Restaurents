<?php
/**
 * SCR-A01 second step: TOTP code, enrolment wizard and recovery codes (wp-login.php).
 *
 * @var int          $uid
 * @var string       $token
 * @var string       $stage  code|enrol|codes
 * @var string       $error  catalog key or ''
 * @var list<string> $codes  recovery codes (stage codes, shown once)
 * @var string       $qr     inline SVG (stage enrol)
 * @var string       $secret base32 key in groups (stage enrol)
 * @var string       $lang   ja|en
 */

defined( 'ABSPATH' ) || exit;

$t      = static fn( string $key, array $args = [] ): string => momos_t( $key, $args, $lang );
$action = esc_url( site_url( 'wp-login.php?action=momos_2fa', 'login_post' ) );
$svg_ok = [ 'svg' => [ 'xmlns' => true, 'role' => true, 'width' => true, 'height' => true, 'viewbox' => true, 'shape-rendering' => true ], 'title' => [], 'rect' => [ 'width' => true, 'height' => true, 'fill' => true ], 'path' => [ 'd' => true, 'fill' => true ] ];
?>
<?php if ( '' !== $error ) : ?>
	<div id="login_error" class="notice notice-error" role="alert"><p><?php echo esc_html( $t( $error ) ); ?></p></div>
<?php endif; ?>

<form name="momos2fa" id="loginform" class="momos-2fa" action="<?php echo $action; // phpcs:ignore -- escaped above. ?>" method="post" autocomplete="off">
	<input type="hidden" name="momos_uid" value="<?php echo (int) $uid; ?>">
	<input type="hidden" name="momos_2fa_token" value="<?php echo esc_attr( $token ); ?>">

	<?php if ( 'enrol' === $stage ) : ?>
		<input type="hidden" name="momos_step" value="enrol">
		<p><?php echo esc_html( $t( 'admin.2fa.enrol.intro' ) ); ?></p>
		<?php if ( '' !== $qr ) : ?>
			<p class="momos-2fa-qr" style="text-align:center"><?php echo wp_kses( $qr, $svg_ok ); ?></p>
		<?php endif; ?>
		<p><?php echo esc_html( $t( 'admin.2fa.enrol.key_label' ) ); ?><br>
			<code class="momos-2fa-secret" style="font-size:1.1em;letter-spacing:.05em;user-select:all" dir="ltr"><?php echo esc_html( $secret ); ?></code></p>
		<p>
			<label for="momos_code"><?php echo esc_html( $t( 'admin.2fa.prompt' ) ); ?></label>
			<input type="text" name="momos_code" id="momos_code" class="input" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus dir="ltr">
		</p>
		<p class="submit"><button type="submit" class="button button-primary button-large"><?php echo esc_html( $t( 'admin.2fa.verify' ) ); ?></button></p>

	<?php elseif ( 'codes' === $stage ) : ?>
		<input type="hidden" name="momos_step" value="enrol_done">
		<p><?php echo esc_html( $t( 'admin.2fa.codes.intro' ) ); ?></p>
		<ul class="momos-2fa-codes" style="columns:2;font-family:monospace;font-size:1.1em" dir="ltr">
			<?php foreach ( $codes as $c ) : ?>
				<li><?php echo esc_html( $c ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p><label><input type="checkbox" name="momos_saved" value="1" required> <?php echo esc_html( $t( 'admin.2fa.saved_codes' ) ); ?></label></p>
		<p class="submit"><button type="submit" class="button button-primary button-large"><?php echo esc_html( $t( 'admin.2fa.continue' ) ); ?></button></p>

	<?php else : ?>
		<input type="hidden" name="momos_step" value="verify">
		<p id="momos-code-wrap">
			<label for="momos_code"><?php echo esc_html( $t( 'admin.2fa.prompt' ) ); ?></label>
			<input type="text" name="momos_code" id="momos_code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus dir="ltr">
		</p>
		<details id="momos-recovery">
			<summary><?php echo esc_html( $t( 'admin.2fa.recovery_link' ) ); ?></summary>
			<p>
				<label for="momos_recovery"><?php echo esc_html( $t( 'admin.2fa.recovery_label' ) ); ?></label>
				<input type="text" name="momos_recovery" id="momos_recovery" class="input" autocomplete="off" maxlength="12" dir="ltr">
			</p>
		</details>
		<p class="submit"><button type="submit" class="button button-primary button-large"><?php echo esc_html( $t( 'admin.2fa.verify' ) ); ?></button></p>
	<?php endif; ?>
</form>
