<?php
/**
 * SCR-A10 "Momo's access" section (user-new.php, user-edit.php, profile.php).
 *
 * @var \WP_User|null             $user
 * @var bool                      $editable
 * @var int                       $uid
 * @var array<int,string>         $branches
 * @var list<int>                 $assigned
 * @var string                    $role
 * @var bool                      $is_sa
 * @var bool                      $vendor
 * @var bool                      $disabled
 * @var \DateTimeImmutable|null   $expires
 * @var \DateTimeImmutable|null   $bg_until
 * @var bool                      $has_dca
 * @var bool                      $dca_ok
 * @var bool                      $enrolled
 * @var int                       $recovery
 * @var string                    $phone
 * @var string                    $last_login
 * @var bool                      $own
 * @var bool                      $optin
 * @var bool                      $scoped
 */

use Momos\Access\Roles\RoleInstaller;
use Momos\Access\TwoFactor\TwoFactor;
use Momos\Access\Admin\UserAccessFields;
use Momos\Kernel\Clock\Clock;

defined( 'ABSPATH' ) || exit;

$jst  = static fn( ?\DateTimeImmutable $d ): string => $d ? $d->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) . ' JST' : '—';
$lang = str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en';
$max_window = Clock::jst()->modify( '+72 hours' )->format( 'Y-m-d\TH:i' );
$max_bg     = Clock::jst()->modify( '+8 hours' )->format( 'Y-m-d\TH:i' );
$now_local  = Clock::jst()->format( 'Y-m-d\TH:i' );
?>
<h2 id="momos-access"><?php esc_html_e( "Momo's access", 'momos-core' ); ?></h2>
<?php if ( $editable ) : ?>
	<?php wp_nonce_field( UserAccessFields::ACTION, UserAccessFields::NONCE ); ?>
<?php elseif ( $own ) : ?>
	<p class="description">🔒 <?php esc_html_e( 'Your role, branches and access settings can only be changed by Head Office.', 'momos-core' ); ?></p>
<?php endif; ?>
<table class="form-table momos-access" role="presentation">
	<?php if ( $uid ) : ?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Role', 'momos-core' ); ?></th>
		<td><?php echo esc_html( '' !== $role ? RoleInstaller::role_label( $role ) : __( '(no role)', 'momos-core' ) ); ?></td>
	</tr>
	<?php endif; ?>

	<tr>
		<th scope="row"><?php esc_html_e( 'Branches', 'momos-core' ); ?></th>
		<td>
			<?php if ( $editable ) : ?>
				<fieldset id="momos_branches" aria-describedby="momos-branches-help">
					<legend class="screen-reader-text"><?php esc_html_e( 'Branches', 'momos-core' ); ?></legend>
					<?php if ( ! $branches ) : ?>
						<p><?php esc_html_e( 'No branches exist yet.', 'momos-core' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $branches as $bid => $label ) : ?>
						<label style="display:block"><input type="checkbox" name="momos_branches[]" value="<?php echo (int) $bid; ?>" <?php checked( in_array( (int) $bid, $assigned, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description" id="momos-branches-help"><?php esc_html_e( 'Required for Branch Managers and Reservation Staff (at least one). Other roles see all branches; their assignments are removed.', 'momos-core' ); ?></p>
			<?php else : ?>
				<?php
				$codes = array_map( static fn( int $b ): string => (string) ( $branches[ $b ] ?? '#' . $b ), $assigned );
				echo esc_html( $codes ? implode( ', ', $codes ) : ( $scoped ? momos_t( 'admin.user.no_branch', [], $lang ) : __( 'All branches (role-based)', 'momos-core' ) ) );
				?>
			<?php endif; ?>
		</td>
	</tr>

	<tr>
		<th scope="row"><label for="momos_phone"><?php esc_html_e( 'Phone (optional)', 'momos-core' ); ?></label></th>
		<td>
			<?php if ( $editable ) : ?>
				<input type="tel" name="momos_phone" id="momos_phone" class="regular-text" value="<?php echo esc_attr( $phone ); ?>" autocomplete="off" dir="ltr">
				<p class="description"><?php esc_html_e( 'Used for out-of-band identity checks (e.g. two-factor reset).', 'momos-core' ); ?></p>
			<?php else : ?>
				<?php echo esc_html( '' !== $phone ? $phone : '—' ); ?>
			<?php endif; ?>
		</td>
	</tr>

	<?php if ( 'momo_translator' === $role ) : ?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Translation languages', 'momos-core' ); ?></th>
		<td>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Translation languages', 'momos-core' ); ?></legend>
				<input type="hidden" name="momos_translator_langs_present" value="1">
				<?php foreach ( \Momos\Kernel\I18n\Lang::targets() as $tl ) : ?>
					<label class="momos-inline-choice"><input type="checkbox" name="momos_translator_langs[]" value="<?php echo esc_attr( $tl ); ?>" <?php checked( in_array( $tl, $translator_langs, true ) ); ?> <?php disabled( ! $editable ); ?>> <?php echo esc_html( \Momos\Kernel\I18n\Lang::ALL[ $tl ]['native'] ?? $tl ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Languages this translator may edit and approve. None selected = all languages.', 'momos-core' ); ?></p>
			</fieldset>
		</td>
	</tr>
	<?php endif; ?>

	<?php if ( $uid ) : ?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Dietary Claims Approver', 'momos-core' ); ?></th>
		<td>
			<?php if ( $editable && $dca_ok ) : ?>
				<input type="hidden" name="momos_dca_present" value="1">
				<label><input type="checkbox" name="momos_dca" value="1" <?php checked( $has_dca ); ?>> <?php esc_html_e( 'May approve halal/dietary claims and certificates (CAP-DCA)', 'momos-core' ); ?></label>
				<?php if ( in_array( 'administrator', (array) $user->roles, true ) ) : ?>
					<p><label for="momos_dca_ref"><?php esc_html_e( 'Owner request reference (required for a Super Administrator)', 'momos-core' ); ?></label><br>
					<input type="text" name="momos_dca_ref" id="momos_dca_ref" maxlength="100" class="regular-text"></p>
				<?php endif; ?>
			<?php else : ?>
				<?php echo esc_html( $has_dca ? __( 'Yes', 'momos-core' ) : __( 'No', 'momos-core' ) ); ?>
				<?php if ( $editable && ! $dca_ok ) : ?>
					<p class="description"><?php esc_html_e( 'Only Head Office users (or a Super Administrator on Owner request) can hold this permission; never vendor accounts.', 'momos-core' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Account status', 'momos-core' ); ?></th>
		<td>
			<?php if ( $editable && ! $vendor ) : ?>
				<input type="hidden" name="momos_disabled_present" value="1">
				<label><input type="checkbox" name="momos_disabled" value="1" <?php checked( $disabled ); ?>> <?php esc_html_e( 'Account disabled (cannot log in; all sessions end immediately; branch assignments are kept)', 'momos-core' ); ?></label>
			<?php else : ?>
				<?php echo esc_html( $disabled ? __( 'Disabled', 'momos-core' ) : __( 'Active', 'momos-core' ) ); ?>
			<?php endif; ?>
			<?php if ( $expires ) : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: date/time */ __( 'Access window ends: %s', 'momos-core' ), $jst( $expires ) ) ); ?></p>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: date/time */ __( 'Last login: %s', 'momos-core' ), $jst( Clock::from_mysql_utc( $last_login ) ) ) ); ?></p>
		</td>
	</tr>

	<?php if ( $is_sa && $editable ) : ?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Vendor account', 'momos-core' ); ?></th>
		<td>
			<input type="hidden" name="momos_vendor_present" value="1">
			<label><input type="checkbox" name="momos_vendor" value="1" <?php checked( $vendor ); ?>> <?php esc_html_e( 'Offshore/vendor account: kept disabled, time-boxed access only, never sees customer personal data', 'momos-core' ); ?></label>
		</td>
	</tr>
	<?php elseif ( $vendor ) : ?>
	<tr><th scope="row"><?php esc_html_e( 'Vendor account', 'momos-core' ); ?></th><td><?php esc_html_e( 'Yes', 'momos-core' ); ?></td></tr>
	<?php endif; ?>

	<?php if ( $vendor && $editable ) : ?>
	<tr>
		<th scope="row"><label for="momos_vendor_until"><?php esc_html_e( 'Enable vendor access until (JST)', 'momos-core' ); ?></label></th>
		<td>
			<input type="datetime-local" name="momos_vendor_until" id="momos_vendor_until" min="<?php echo esc_attr( $now_local ); ?>" max="<?php echo esc_attr( $max_window ); ?>">
			<p class="description"><?php esc_html_e( 'At most 72 hours. The account is disabled again automatically at the end of the window.', 'momos-core' ); ?></p>
			<?php if ( ! $disabled ) : ?>
				<label><input type="checkbox" name="momos_vendor_end_now" value="1"> <?php esc_html_e( 'End the access window now', 'momos-core' ); ?></label>
			<?php endif; ?>
		</td>
	</tr>
	<?php if ( $is_sa ) : ?>
	<tr>
		<th scope="row"><label for="momos_bg_until"><?php esc_html_e( 'Break-glass (customer data) until (JST)', 'momos-core' ); ?></label></th>
		<td>
			<p><?php echo esc_html( sprintf( /* translators: %s: date/time */ __( 'Current: %s', 'momos-core' ), $jst( $bg_until ) ) ); ?></p>
			<input type="datetime-local" name="momos_bg_until" id="momos_bg_until" min="<?php echo esc_attr( $now_local ); ?>" max="<?php echo esc_attr( $max_bg ); ?>">
			<p><label for="momos_bg_ref"><?php esc_html_e( 'Owner approval reference (no personal data)', 'momos-core' ); ?></label><br>
			<input type="text" name="momos_bg_ref" id="momos_bg_ref" maxlength="100" class="regular-text"></p>
			<p class="description"><?php esc_html_e( 'At most 8 hours. Allows viewing reservations and customer details only — export and data-subject tools stay blocked.', 'momos-core' ); ?></p>
			<?php if ( $bg_until ) : ?>
				<label><input type="checkbox" name="momos_bg_revoke" value="1"> <?php esc_html_e( 'Revoke break-glass now', 'momos-core' ); ?></label>
			<?php endif; ?>
		</td>
	</tr>
	<?php endif; ?>
	<?php endif; ?>

	<tr>
		<th scope="row"><?php esc_html_e( 'Two-factor authentication', 'momos-core' ); ?></th>
		<td>
			<?php if ( $enrolled ) : ?>
				<?php echo esc_html( sprintf( /* translators: %d: number of codes */ __( 'Enrolled — %d recovery codes left', 'momos-core' ), $recovery ) ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Not enrolled', 'momos-core' ); ?>
			<?php endif; ?>
			<?php if ( $editable && $enrolled ) : ?>
				<p><label><input type="checkbox" name="momos_2fa_reset" value="1"> <?php esc_html_e( 'Reset two-factor (lost device). Confirm the person\'s identity by a known phone number or in person first. All sessions end; the user enrols again at next login.', 'momos-core' ); ?></label></p>
			<?php elseif ( $own && ! $enrolled && ! TwoFactor::enforced() ) : ?>
				<p><label><input type="checkbox" name="momos_2fa_optin" value="1" <?php checked( $optin ); ?>> <?php esc_html_e( 'Set up two-factor authentication at my next login', 'momos-core' ); ?></label></p>
				<?php wp_nonce_field( 'momos_2fa_optin', '_momos_optin_nonce' ); ?>
			<?php endif; ?>
		</td>
	</tr>
	<?php endif; ?>
</table>
