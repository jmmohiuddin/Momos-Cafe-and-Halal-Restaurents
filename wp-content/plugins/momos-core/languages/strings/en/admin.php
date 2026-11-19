<?php
/**
 * Admin module staff strings (en): SCR-A02 dashboard widgets (docs/specs/access-security-privacy.md §13.1).
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dash.needs_action.title' => 'Needs action',
	'admin.dash.needs_action.flagged' => '{n} requests flagged as needing action',
	'admin.dash.needs_action.not_notified' => '{n} guests not yet notified',
	'admin.dash.needs_action.none' => 'Nothing needs action.',
	'admin.dash.attendance.title' => 'Attendance to record',
	'admin.dash.attendance.count' => '{n} reservations without attendance',
	'admin.dash.attendance.none' => 'All attendance is recorded.',
	'admin.dash.pending.overdue' => '{n} past the response target',
	'admin.dash.pending.item' => '{ref} — {date} {time}, {party} guests ({branch})',
	'admin.dash.allergen.title' => 'Allergen data not verified',
	'admin.dash.allergen.count' => '{n} dishes with unverified allergen data or a review flag',
	'admin.dash.allergen.none' => 'All allergen data is verified.',
	'admin.dash.offers_own.title' => 'My offers awaiting approval',
	'admin.dash.offers_own.count' => '{n} of your offers are waiting for approval',
	'admin.dash.offers_own.none' => 'None of your offers is waiting for approval.',
	'admin.dash.offers_ending.title' => 'Offers ending soon',
	'admin.dash.offers_ending.item' => '{title} — ends {date}',
	'admin.dash.offers_ending.none' => 'No offer ends in the next 7 days.',
	'admin.dash.demo.title' => 'Demo content remaining',
	'admin.dash.demo.count' => '{n} sample items are still on the site',
	'admin.dash.demo.illustrative' => '{n} illustrative images in use',
	'admin.dash.demo.none' => 'No demo content remains.',
	'admin.dash.tr_jobs.title' => 'My translation jobs',
	'admin.dash.tr_jobs.count' => '{language}: {n} open items',
	'admin.dash.tr_jobs.none' => 'No open translation jobs.',
];
