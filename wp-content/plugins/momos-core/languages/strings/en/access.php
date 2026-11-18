<?php
/**
 * Access/Audit staff strings (en). Keys: docs/specs/access-security-privacy.md §15.1.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.login.err.generic' => 'Incorrect username or password.',
	'admin.login.err.locked' => 'Too many failed attempts. Please try again after {time}.',
	'admin.login.err.locked_generic' => 'Too many failed attempts. Please try again later.',
	'admin.2fa.title' => 'Two-step verification',
	'admin.2fa.prompt' => 'Enter the 6-digit code from your authenticator app.',
	'admin.2fa.err.invalid' => 'That code is not valid. Please try again.',
	'admin.2fa.err.expired' => 'Your verification step expired. Please log in again.',
	'admin.2fa.err.too_many' => 'Too many incorrect codes. Please log in again.',
	'admin.2fa.err.confirm_saved' => 'Tick the box to confirm you have saved the codes. A new set of codes has been created — save these instead.',
	'admin.2fa.recovery_link' => 'Use a recovery code',
	'admin.2fa.recovery_label' => 'Recovery code (XXXX-XXXX)',
	'admin.2fa.verify' => 'Verify',
	'admin.2fa.enrol.title' => 'Set up two-step verification',
	'admin.2fa.enrol.intro' => 'Scan the QR code with your authenticator app (or enter the key manually), then enter the 6-digit code it shows.',
	'admin.2fa.enrol.key_label' => 'Key for manual entry:',
	'admin.2fa.enrol.qr_alt' => 'QR code for your authenticator app',
	'admin.2fa.codes.intro' => 'Save these recovery codes somewhere safe. Each code works once if you lose your phone. They will not be shown again.',
	'admin.2fa.saved_codes' => 'I have saved these codes',
	'admin.2fa.continue' => 'Continue',
	'admin.user.err.username' => 'Choose a different username.',
	'admin.user.err.branch_required' => 'Assign at least one branch.',
	'admin.user.no_branch' => 'No branch is assigned to your account. Please contact Head Office.',
	'admin.pw.err.length' => 'Use 12 to 128 characters.',
	'admin.pw.err.breached' => 'This password is too common or has appeared in a data breach. Choose another.',
	'admin.vendor.err.window' => 'Vendor access can be enabled for at most {hours} hours.',
	'admin.audit.empty' => 'No entries for these filters',
	'admin.err.forbidden_requests' => 'You don\'t have access to this branch\'s requests.',
	'admin.err.forbidden' => 'You do not have permission to do this.',
	'staff.err.forbidden_branch' => 'You do not have access to this branch.',
	'staff.err.forbidden_field' => 'You are not allowed to change: {field}.',
	'admin.lang.err.gate' => 'Cannot enable {language}: {n} launch-critical items are missing or unreviewed.',
	'admin.lang.err.signoff' => 'Record the native-review sign-off (name and date) before enabling {language}.',
];
