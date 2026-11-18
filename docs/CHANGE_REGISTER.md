# Change register

Changes to documented behaviour (scope, interface or rule) made during the build. Each needs the listed approval before release.

| ID | Change | Reason | Docs affected | Approval |
|---|---|---|---|---|
| CHG-001 | No paid plugins; equivalent features in momos-core (IMPL-001) | Purchases outside authorization | TRD §1.2–1.3 | Owner/CTO |
| CHG-002 | "Reservations" is its own top-level admin menu (`momos-reservations`), not a submenu of Momo's | Reservation spec §7 vs ENGINEERING.md; staff reach it in one click | Doc 09 §2.1 | CTO (accepted) |
| CHG-003 | Idempotency replay with a different email/branch returns 400 instead of replaying | Prevents a leaked key disclosing another submitter's status token | API-02 | Security lead |
| CHG-004 | Staff deep link `page=momo-reservations&reservation=N` redirects to `page=momos-reservations&id=N` | Single canonical admin URL | Notification spec | CTO (accepted) |
| CHG-005 | Contact form posts to admin-post (`momos_contact`) instead of the proposed REST route | Works without JS; REST route was only [Proposed] | screens.md §7 | CTO (accepted) |
| CHG-006 | `momos-ui.css` 47.7 KB minified (7.9 KB gzip) vs 30 KB minified budget | Semantic-token rule + larger component set | Doc 09 U1 | Review in Wave 3 perf pass |
| CHG-007 | Offer expiry runs every 15 min (idempotent) rather than once at 00:05 JST | Superseded: kernel now has `Schedule::daily_at`; revisit | Branch spec | CTO |
