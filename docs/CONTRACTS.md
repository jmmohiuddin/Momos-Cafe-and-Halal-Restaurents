# Cross-module contracts (wave 1)

Modules are built in parallel. These public signatures are binding; implement exactly these (more
methods are fine). Domain hooks follow SDD §2.5 and fire after commit via `AfterCommit::dispatch`.

## Branch module (`Momos\Branch\…`) — owner: Branch team
```php
// Post type momo_branch; canonical ID = the post ID (single post per branch, translations in meta — IMPL-002).
final class Repository\BranchRepository {
  public static function get( int $branch_id ): ?array;            // ['id','code','name'(ja),'slug','status'(post_status),'operating_status','is_test','phone_e164','phone_display','email','address_ja','lat','lng', 'concept'] or null
  public static function published_ids( bool $include_test = false ): array; // list<int>, ordered by menu_order/title
  public static function name( int $branch_id, ?string $lang = null ): string; // translated name ('' if not approved in $lang)
}
final class Repository\BranchSettingsRepository {
  public static function get( int $branch_id ): array;  // all G6 momo_res_* values with documented defaults applied, ints cast:
  // enabled(bool) party_min party_max lead_time_minutes booking_window_days slot_interval_minutes decision_cutoff_minutes
  // response_sla_minutes sla_clock show_response_promise(bool) max_pending_per_email customer_cancel_cutoff_minutes policy_page(int|null)
  public static function active_recipients( int $branch_id ): array; // list<array{email:string, language:'ja'|'en'}>
}
final class Service\ScheduleService {
  // Effective opening periods for a JST business date, after weekly hours (valid_from/until), special hours and closures.
  // Each period: ['period_no'=>int,'label'=>string,'opens_at'=>'H:i','closes_at'=>'H:i','closes_next_day'=>bool,'last_order_at'=>?'H:i']
  public static function periods_on( int $branch_id, string $date_ymd ): array;   // [] when closed
  public static function is_closed_on( int $branch_id, string $date_ymd ): bool;
  public static function closure_on( int $branch_id, string $date_ymd ): ?array;   // closure row incl. public_reason, or null
  public static function open_status( int $branch_id, ?\DateTimeImmutable $now = null ): array; // ['state'=>'open'|'closed'|'closing_soon'|'opens_later', 'until'=>?'H:i','next_open'=>?array{date,time}]
  public static function week( int $branch_id ): array; // weekly table for display: weekday(1=Mon..7=Sun) => list<period>
}
```
Hooks fired: `momos/branch/schedule_changed (int $branch_id, array $dates)`, `momos/branch/settings_changed (int, array $keys)`,
`momos/branch/identity_changed (int)`, `momos/offer/visibility_changed (int, bool)`.

## Menu / Dietary modules — owner: Menu & Dietary team
```php
final class Menu\Service\MenuQueryService {
  public static function categories( string $lang ): array;                 // visible categories ordered: list<WP_Term>
  public static function dishes_in_category( int $term_id, string $lang, ?int $branch_id = null ): array; // list<int> dish IDs
  public static function featured( string $lang, int $limit = 6 ): array;   // list<int>
  public static function branch_offers_dish( int $branch_id, int $dish_id ): ?array; // branch_menu_items row or null
}
final class Menu\Service\PriceResolver { public static function price( int $dish_id, ?int $branch_id = null ): ?int; } // JPY tax-incl.
final class Dietary\Service\ClaimResolver {
  public static function branch_claim( int $branch_id ): array; // ['type'=>'certified'|'halal_ingredients'|'no_claim'|'none','statement'=>string (current lang), 'certifier'=>?, 'scope'=>?, 'number'=>?, 'valid_until'=>?, 'mark_url'=>?, 'register_url'=>?, 'alcohol_served'=>'yes'|'no'|'unknown','alcohol_in_cooking'=>…]
  public static function dish_claim( int $dish_id, ?int $branch_id = null ): array;
}
final class Dietary\Service\AllergenPresenter { public static function for_dish( int $dish_id, string $lang ): array; } // ['complete'=>bool,'contains'=>[labels],'may_contain'=>[…],'not_verified'=>[…]]
```
Hooks: `momos/branch_menu_item/updated`, `momos/dish/published`, `momos/dish/retired`, `momos/dietary/claims_changed`,
`momos/certificate/status_changed`, `momos/dietary/review_required`.

## Reservation module — owner: Reservation team
```php
final class Reservation\Repository\ReservationRepository {
  public static function find( int $id ): ?array;            // full row (assoc) incl. PII (callers enforce caps)
  public static function find_by_ref( string $public_ref ): ?array;
}
final class Reservation\Service\TokenService { public static function status_url( array $reservation ): string; } // /{lang}/reservation/status/{REF}/#t={token}
```
Hooks fired (after commit): `momos/reservation/created (int $id)`, `momos/reservation/status_changed (int $id, string $from, string $to, array $actor)`,
`momos/reservation/modified (int $id, array $before, array $after, array $actor)`, `momos/reservation/sla_reminder (int $id)`,
`momos/reservation/sla_escalated (int $id)`, `momos/reservation/closure_conflict (int $id)`.
`$actor = ['type'=>'customer'|'staff'|'system','id'=>?int,'display'=>?string]`.

## Notification module — owner: Notification team
```php
final class Notification\Notifier {
  // Enqueue (dedupe by $dedupe_key) and let the worker send. Returns notification id or null if deduped.
  public static function queue( string $template_key /* E01..E11 */, array $recipient /* ['type'=>'customer'|'branch'|'head_office'|'user','email'=>…,'lang'=>…,'user_id'=>?] */, array $context /* reservation_id, branch_id, variant, … */, string $dedupe_key ): ?int;
}
```
Notification subscribes to the reservation hooks above (it owns *which* emails are sent for each transition,
per docs/specs/notifications.md) and fires `momos/notification/failed (int $notification_id, ?int $reservation_id)`;
Reservation listens to set `needs_action_flag`.

## Access / Audit — owner: Access team
- `momos_audit()` / `momos/audit/log` action (see src/functions.php) → Audit module writes `momo_audit_log`.
- `momos_user_branch_ids()`, `momos_user_can_branch()` (src/functions.php; Access may short-circuit via filter `momos/access/user_branch_ids`).
- Meta caps (MetaCapMapper): `momo_view_reservation` / `momo_process_reservation` (args: reservation id),
  `momo_edit_branch` (args: branch id, aspect), `momo_edit_branch_menu_item` (branch id, dish id, fields), `momo_download_evidence` (certificate id).
- Hook `momos/access/denied (array $denial)`; `momos/user/assignment_changed (int $user_id, array $added, array $removed)`.

## Strings
Customer/email strings: `languages/strings/<lang>/<module>.php`; key prefixes: `branch.`, `menu.`, `diet.`, `res.`, `email.`,
`nav.`, `common.`, `legal.`, `seo.`, `a11y.` — use Doc 07 §6 keys verbatim where they exist (`res.title`, …).
