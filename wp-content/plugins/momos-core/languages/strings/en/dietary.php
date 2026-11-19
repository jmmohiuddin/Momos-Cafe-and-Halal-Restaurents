<?php
/**
 * Dietary module customer strings — English (Doc 11 §8.1/§9.4 canonical drafts; FOOD + counsel review).
 * Halal/alcohol STATEMENTS are not here: they are G2 templates (option momos_dietary_statements).
 */

defined( 'ABSPATH' ) || exit;

return [
	'allergen.heading'               => 'Allergens',
	'allergen.state.contains'        => 'Contains',
	'allergen.state.may_contain'     => 'May contain (cross-contact)',
	'allergen.state.not_contained'   => 'Not contained (checked)',
	'allergen.state.not_verified'    => 'Not verified',
	'allergen.summary.contains'      => 'Contains: {list}',
	'allergen.summary.may_contain'   => 'May contain (cross-contact): {list}',
	'allergen.banner.not_verified'   => 'Allergen information not verified — please ask staff.',
	'allergen.disclaimer.voluntary'  => 'Allergen information is provided voluntarily for reference. It may change when suppliers or recipes change, and ingredients may differ between branches.',
	'allergen.cross_contact'         => 'Our kitchen also handles other ingredients and may share equipment and frying oil, so we cannot guarantee the absence of trace amounts.',
	'allergen.tell_staff'            => 'If you have a severe allergy, please tell our staff when you arrive and when ordering. We will tell you honestly if we cannot prepare a dish for you.',
	'allergen.last_verified'         => 'Information last verified on {date}.',
	'allergen.group.mandatory'       => 'Specified ingredients (9 — mandatory for packaged food)',
	'allergen.group.recommended'     => 'Recommended items (20)',
	'allergen.group.other'           => 'Other ingredients',
	'allergen.legend'                => 'Key',
	'allergen.table.caption'         => 'Allergen information',
	'allergen.table.allergen'        => 'Allergen',
	'allergen.table.status'          => 'Status',
	'allergen.toggle.relevant'       => 'Show only “contains” and “may contain”',
	'ingredients.heading'            => 'Key ingredients (not a complete list)',
	'last_verified'                  => 'Last verified: {date}',
	'halal.heading'                  => 'Halal information',
	'halal.label.certified'          => 'Halal-certified',
	'halal.label.ingredients'        => 'Halal ingredients',
	'halal.not_applicable'           => 'Not applicable',
	'halal.mark_alt'                 => 'Halal certification mark of {certifier}',
	'halal.verify_link'              => 'Verify with the certifier',
	'halal.certifier'                => 'Certifier',
	'halal.scope'                    => 'Scope',
	'halal.cert_no'                  => 'Certificate no.',
	'halal.valid_until'              => 'Valid until',
	'halal.scope.whole_kitchen'      => 'Whole kitchen',
	'halal.scope.specific_menu'      => 'Marked dishes',
	'halal.scope.specific_ingredients' => 'Specific ingredients',
	'alcohol.heading'                => 'Alcohol',
	'diet.list.separator'            => ', ',
	// FR-DIET-002(f) audit label (SCR-A16).
	'admin.audit.action.dietary.prohibited_terms_updated' => 'Prohibited wording list updated',
];
