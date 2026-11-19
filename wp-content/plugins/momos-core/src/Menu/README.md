# Menu

**Purpose.** The brand menu and per-branch offering (F-MENU-01…05; spec `docs/specs/menu-dietary.md`): dish CPT
and taxonomies, field groups G8/G9/G11/G13, publish validation and dish lifecycle, menu URLs, branch menu items
(offered / price / availability / sold out, SCR-A07, API-14/15), price resolution, public menu queries and the
sold-out reset job. No dietary rules here — those are in Dietary.

## Public services
- `Service\MenuQueryService` — `categories()`, `dishes_in_category()`, `featured()`, `related()`, `card()`,
  `tags()`, `branches_offering()`, `branch_offers_dish()`, `selector_branches()`, filters for the menu page.
- `Service\PriceResolver` / `Service\PriceFormatter` — branch price vs. brand price, tax-inclusive yen display.
- `Service\BranchMenuService` + `Repository\BranchMenuItemRepository` — branch menu items with optimistic
  versioning; `Service\SoldOutPolicy` (sold out until end of business day).
- `Service\DishPublishValidator`, `Service\DishLifecycle`, `Service\CategoryGuard`.

## Hooks
- Fires: `momos/branch_menu_item/updated` (after commit), filter `momos/menu/branch_periods`; purges via
  `momos/cache/purge` (`Support\CachePurge`).
- Consumes: `momos/fields/saved`, `momos/fields/publish_errors/*`.

## Data
CPT `momo_dish`; taxonomies `momo_menu_category`, `momo_ingredient`, `momo_dietary_tag`; dish meta (code
`D0000`, brand price, spice level, …); table `momo_branch_menu_items`.

## Capabilities
`momo_edit_branch_menu`, `momo_edit_branch_prices`, `momo_edit_branch_availability` (branch-scoped, meta cap
`momo_edit_branch_menu_item`), `momo_manage_menu_categories`, dish caps `edit_momo_dishes` … (Menu Manager / HO).

## Admin screens · REST
**Momo's → Branch menus** (`momo-branch-menu`), **Menu → Category order** (`momo-category-order`), dish
editor. REST `GET|PATCH /momos/v1/staff/branches/{code}/menu-items[/{dish_id}]` (`Rest\BranchMenuController`).

## Jobs
`momos_job_soldout_reset` (resets sold-out items at the end of the business day; heartbeat).

## Tests
`MenuTest`, `MenuBranchMenuTest`.
