<?php
/**
 * Plugin and component register (FR-FND-006, Doc 11 SEC-060). Update in the same PR that adds or
 * upgrades a component; `last_review` is refreshed at each monthly update cycle (FR-SEC-009).
 * Active plugins (incl. must-use loader for momos-core) must be ≤ 15 and all listed here.
 */
defined( 'ABSPATH' ) || exit;

return [
	[ 'slug' => 'momos-core', 'name' => 'momos-core (via mu-plugin momos-loader.php)', 'type' => 'custom', 'version' => MOMOS_CORE_VERSION, 'purpose' => 'All business logic', 'owner' => 'Tech lead (Nimikh)', 'licence' => 'Proprietary (client)', 'third_party_data' => 'Cloudflare Turnstile (token, IP); email provider (recipient, message); GA4 via GTM (no PII, opt-out); Google Maps after click', 'last_review' => '2026-11-19', 'counts_toward_cap' => true ],
	[ 'slug' => 'momos', 'name' => "Theme: Momo's", 'type' => 'custom', 'version' => MOMOS_CORE_VERSION, 'purpose' => 'Presentation only', 'owner' => 'Tech lead (Nimikh)', 'licence' => 'Proprietary (client); fonts SIL OFL', 'third_party_data' => 'None (self-hosted fonts)', 'last_review' => '2026-11-19', 'counts_toward_cap' => false ],
	[ 'slug' => 'action-scheduler', 'name' => 'Action Scheduler (bundled library)', 'type' => 'library', 'version' => '3.9', 'purpose' => 'Job queue', 'owner' => 'Tech lead', 'licence' => 'GPL-3.0', 'third_party_data' => 'None', 'last_review' => '2026-11-19', 'counts_toward_cap' => false ],
	[ 'slug' => 'libphonenumber', 'name' => 'libphonenumber-for-php-lite (bundled library)', 'type' => 'library', 'version' => '8.13', 'purpose' => 'Phone normalisation (E.164)', 'owner' => 'Tech lead', 'licence' => 'Apache-2.0', 'third_party_data' => 'None', 'last_review' => '2026-11-19', 'counts_toward_cap' => false ],
];
