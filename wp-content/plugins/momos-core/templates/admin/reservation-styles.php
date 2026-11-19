<?php
/**
 * Shared styles of the reservation staff screens (SCR-A08/A09/A17). Logical properties only (RTL-safe);
 * cards below 768 px, table from 1024 px; tap targets ≥ 44 px [Ruling C-12].
 */

defined( 'ABSPATH' ) || exit;
?>
<style>
.momos-res .momos-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-block: 12px; padding: 0; list-style: none; }
.momos-res .momos-chip { display: inline-flex; align-items: center; min-block-size: 44px; padding-inline: 14px; border: 1px solid #c3c4c7; border-radius: 999px; text-decoration: none; background: #fff; }
.momos-res .momos-chip[aria-current="page"] { background: #2271b1; border-color: #2271b1; color: #fff; }
.momos-res .momos-chip .count { margin-inline-start: 6px; font-weight: 600; }
.momos-res .momos-filters { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: flex-end; margin-block-end: 12px; }
.momos-res .momos-filters label { display: flex; flex-direction: column; gap: 4px; font-weight: 600; }
.momos-res .momos-filters input, .momos-res .momos-filters select, .momos-res .button { min-block-size: 44px; }
.momos-res .momos-flag { display: inline-block; margin-inline-end: 6px; padding-inline: 6px; border-radius: 4px; background: #f0f0f1; font-size: 12px; }
.momos-res .momos-flag--warn { background: #fcf0d0; color: #6b4b00; }
.momos-res .momos-flag--alert { background: #f6e1e1; color: #8a1f1f; }
.momos-res .momos-when { font-size: 1.15em; font-weight: 600; }
.momos-res .momos-newbar { position: sticky; inset-block-start: 32px; z-index: 5; margin-block: 8px; }
.momos-res .momos-grid { display: grid; gap: 16px; }
.momos-res .momos-grid > *, .momos-res .momos-grid > * > * { min-inline-size: 0; } /* AC-RES-04-05: wide panels never widen the page */
.momos-res .momos-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px; }
.momos-res .momos-card h2 { margin-block-start: 0; font-size: 1.1em; }
.momos-res dl.momos-dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0; }
.momos-res dl.momos-dl dt { font-weight: 600; }
.momos-res dl.momos-dl dd { margin: 0; overflow-wrap: anywhere; }
.momos-res details.momos-sheet { border: 1px solid #dcdcde; border-radius: 8px; margin-block-end: 8px; background: #fff; }
.momos-res details.momos-sheet > summary { min-block-size: 44px; display: flex; align-items: center; padding-inline: 12px; cursor: pointer; font-weight: 600; }
.momos-res details.momos-sheet > div { padding: 12px; border-block-start: 1px solid #dcdcde; }
.momos-res details.momos-sheet fieldset { margin-block-end: 12px; }
.momos-res details.momos-sheet label { display: block; margin-block: 6px; }
.momos-res textarea { inline-size: 100%; }
.momos-res .momos-timeline { list-style: none; margin: 0; padding: 0; }
.momos-res .momos-timeline li { padding-block: 8px; border-block-end: 1px solid #f0f0f1; }
.momos-res .momos-muted { color: #50575e; }
.momos-res .momos-pagination { display: flex; gap: 8px; align-items: center; margin-block: 12px; }
@media (min-width: 1024px) {
	.momos-res .momos-grid--detail { grid-template-columns: 2fr 1fr; }
}
@media (max-width: 767px) {
	.momos-res table.momos-table thead { display: none; }
	.momos-res table.momos-table, .momos-res table.momos-table tbody, .momos-res table.momos-table tr, .momos-res table.momos-table td { display: block; inline-size: 100%; box-sizing: border-box; } /* AC-RES-04-05: no horizontal scroll at 390 px */
	.momos-res table.momos-table tr { margin-block-end: 12px; border: 1px solid #dcdcde; border-radius: 8px; background: #fff; }
	.momos-res table.momos-table td::before { content: attr(data-label) ": "; font-weight: 600; }
	.momos-res table.momos-table td.momos-when-cell::before { content: none; }
	.momos-res .momos-grid table { display: block; max-inline-size: 100%; overflow-x: auto; }
	.momos-res .momos-actionbar { position: sticky; inset-block-end: 0; background: #fff; padding-block: 8px; border-block-start: 1px solid #dcdcde; }
}
</style>
