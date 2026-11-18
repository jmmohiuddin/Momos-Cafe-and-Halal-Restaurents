# Spec — UI Design System, Component Library and Customer Page Frame

Implementation-ready specification for the **classic theme `momos`** (`wp-content/themes/momos`) and the presentation
partials of `momos-core` (`Momos\Presentation`). Developers should not need the original documentation package.
Sources: Doc 08 §0–§3, §4.0, §4.15, §4.18, §5–§8 + Open items; Doc 08A §3, §5 (UI-CHG-001..081), §6; Doc 07 §5.0,
§6.1–6.6, §6.10; Doc 06 §3.1–3.8; Doc 04 FR-CORP-008/009, FR-I18N-001..006, NFR-PERF-03, NFR-ACC-01, NFR-CMP-01;
Doc 04A US-CORP-009, US-I18N-001..003; Doc 09 §1.9 U1–U11, §2.8–2.10, §3.6; Doc 10 §2.1, §2.4.9; Doc 11 §4 (CSP);
Doc 12 TC-NAV/I18N/RTL/A11Y/CMP/PERF; Doc 13 EP-03 TK-03-01..16; Doc 17 DEC-036/046/047/057/060/061/062/067.

Labels: **[Doc]** documented value · **[TBD → default]** open decision, build the documented proposal as configuration ·
**[Proposed]** added by this spec where the docs are silent (review before release) · ⟦…⟧ placeholder for a business
fact that must not be invented. Domain rules (prices, halal, allergens, reservations, offers) are owned by the module
specs (`menu-dietary.md`, `branch.md`, `reservation.md`, …); this spec owns **how they look** and the shared frame.

---

## 0. Build adaptation (IMPL-001/002/005)

| Documented mechanism | This build |
|---|---|
| Hello Elementor child theme `momos-child` + `tokens.css` + `momos-ui.css` | Classic theme `momos` (no parent). `assets/css/tokens.css` (§3) + `assets/css/momos-ui.css` (base + components, **≤ 30 KB minified**, Doc 09 U1). Theme contains no business logic (DEC-008) |
| Elementor Kit global colours / typography presets / breakpoints | Not used. Tokens in §3 are the single source; no Customizer colour/font controls [Proposed]. Theme declares `add_theme_support('elementor')`-compatible markup only (IMPL-001) |
| Theme Builder T01 header / T02 footer / T09 404 | `header.php`, `footer.php`, `404.php` + `template-parts/frame/*.php` (§8) |
| Loop item templates T11–T14 | `template-parts/cards/{branch-card,footer-branch,dish-card,offer-card}.php` |
| momos-core Elementor widgets / dynamic tags (`momos-notice-banner`, `momos-dish-price`, …) | `momos-core` render functions with the **same slugs**: `Momos\Presentation\Components::render('notice-banner', $args)` → partial `momos-core/templates/widgets/notice-banner.php`. Partials emit the `.momo-*` component classes of this spec (one class namespace; Doc 08 §7.2's `.momos-*` widget classes are folded into `.momo-*`) [Proposed] |
| WPML language switcher widget / "suggest language" bar | Theme partials `lang-switcher.php` / `lang-suggestion.php` fed by `Momos\Kernel\I18n\Lang::enabled()`, `Lang::native()`, `Url::localize()` and `Translations::post_visible()` (IMPL-002) |
| WPML-synced menu "Primary" + `wp_nav_menu_objects` Offers filter | Navigation defined **in code** (items, string keys, URLs via `momos_url()`); "Offers" added when `OfferQuery::hasActive($lang)` (DEC-060) [Proposed: no WP menu, so no per-language menu translation] |
| Elementor Popup mobile menu | Native `<dialog>` opened with `showModal()` (focus containment, Esc, inert background) |
| Rank Math breadcrumbs widget | `template-parts/frame/breadcrumbs.php` fed by `Momos\Seo\BreadcrumbFilter` trail (same trail as BreadcrumbList JSON-LD) |
| WPML String Translation | `momos_t($key, $args, $lang)` catalogs `momos-core/languages/strings/{lang}/*.php`; theme strings use the same keys (catalog file `theme.php` in each language) [Proposed] |
| `<html lang dir>` from WPML | `language_attributes` filter → `lang="{ja|en|ar|ur|hi|bn}" dir="{ltr|rtl}"` from `Locale::current()` |
| UI-001 repository (prototype CSS) | Not attached (IMPL-005); values below come from Doc 08/08A extraction |

---

## 1. Requirement coverage (traceability)

| ID | Statement (one line) | Priority |
|---|---|---|
| F-CORP-08 / FR-CORP-008 | Global header (logo, nav Menu · Branches ▾ · [Offers] · Halal · About · Reservation, switcher, "Request a table"); mobile menu dialog; sticky mobile action bar; targets ≥ 44 px | Must |
| FR-CORP-008(f) / DEC-060 | "Offers" nav item after "Branches" only while ≥ 1 active offer translated in the page language; footer link always | Must |
| FR-CORP-009 | Footer: branch list with `tel:`, Explore incl. Offers, 5 legal links + Analytics settings, social, © year + legal operator | Must |
| F-I18N-01 / FR-I18N-001 | `<html lang dir>`; logical CSS only; mirror only directional icons; DOM order = reading order (no `row-reverse`) | Must |
| FR-I18N-002 | Switcher: enabled languages only, native names, `lang`/`hreflang`, `aria-current`, same page or language home + notice | Must |
| FR-I18N-003 | Suggestion bar: client-side, never redirects, dismissal cookie 1 year | Must |
| FR-I18N-004 / DEC-061 | Self-hosted WOFF2 per language; Epilogue + Plus Jakarta Sans (Latin), Noto JP/Naskh/Nastaliq/Devanagari/Bengali; ≤ 3 families, ≤ 6 files per page; Urdu line-height ↑ | Must |
| FR-I18N-005 | Bidi isolation of numbers/prices/phones/dates/refs/Latin names; `dir="auto"` user text; LTR email/phone/URL inputs | Must |
| FR-I18N-006 / DEC-062 | 24 h time, Doc 07 §6.10 date formats, Western digits out, "JST" label non-ja only | Must |
| BR-I18N-02 | No mixed-language fallback; untranslated items hidden | Must |
| NFR-ACC-01 / DEC-036 | WCAG 2.2 AA on all customer templates; 0 critical/serious axe | Must |
| NFR-PERF-01/03 | LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≤ 200 ms; ≤ 1.5 MB initial; hero ≤ 200 KB; cards ≤ 80 KB; fonts only for current language | Must |
| NFR-CMP-01 | Latest 2 majors Chrome/Safari/Edge/Firefox/Samsung; LINE/IG/FB in-app; widths 360/390/768/1024/1366/1920 | Must |
| NFR-I18N-01 | 6 languages, RTL correct for ar/ur on all templates, 0 mixed-language pages | Must |
| DEC-046 / DEC-067 | Provisional wordmark + tokens until official assets; all identity values swappable via tokens/brand settings | Must |
| DEC-047 / BR-CORP-03 | "Image for illustration" caption below flagged dish/offer images only; SAMPLE marker in comps | Must |
| DEC-057 | UI-001 is the visual baseline; deviations only for scope/a11y/RTL/perf (side-by-side review 390/1366, ja+ar) | Must |
| US-CORP-009 | Sticky bar Request/Call/Directions on branch pages; Call/Directions only when not accepting; Esc closes menu | Must |
| US-I18N-001/002/003 | Switch to same page; Arabic/Urdu render correctly; suggestion without redirect | Must |
| TK-03-01..16 | EP-03 tasks (theme scaffold, tokens, fonts, RTL foundation, components, icons, images, a11y review, identity swap) | Must |
| SCR-C (all) frame · SCR-C19 · SCR-C20 · SCR-A frame · SCR-E layout | Frame, 404, maintenance, admin frame, email layout (§8–§10) | Must |

Out of this spec (owned elsewhere): per-screen content of C01–C22/A01–A20 (module specs), API contracts, claim wording
rules (only rendering is here).

---

## 2. Theme file layout [Proposed]

```
wp-content/themes/momos/
  style.css                       theme header only (Text Domain momos)
  functions.php                   requires inc/*.php; no business logic
  inc/setup.php                   theme supports (title-tag, html5, post-thumbnails, responsive-embeds), image sizes, nav: none
  inc/assets.php                  enqueue tokens.css, momos-ui.css, fonts-{lang}.css, frame.js; preload 1 font
  inc/icons.php                   momos_icon(string $name, int $size = 20, bool $dir = false): string  (inline <svg><use>)
  inc/frame.php                   page-context helpers (page type, branch context, current nav section)
  header.php / footer.php / index.php / page.php / single.php / 404.php
  template-parts/frame/           skip-link, lang-suggestion, notice-banner, header, nav-primary, lang-switcher,
                                  mobile-menu, mobile-action-bar, footer, breadcrumbs, page-banner
  template-parts/cards/           dish-card, branch-card, offer-card, category-tile, footer-branch
  template-parts/ui/              button, chip, field, choice, status-badge, empty-state, dialog, accordion, section-header
  assets/css/tokens.css           §3 (verbatim)
  assets/css/momos-ui.css         base (§3.2) + components (§7), logical properties only
  assets/css/fonts-{ja,en,ar,ur,hi,bn}.css   @font-face for that language only (§4.4)
  assets/fonts/<family>/*.woff2 + OFL.txt
  assets/icons/sprite.svg         §6 icon set
  assets/js/frame.js              disclosure menus, <dialog> menu, action-bar keyboard hide, suggestion bar, toast (vanilla, defer, ≤ 10 KB) [Proposed budget]
wp-content/maintenance.php        SCR-C20 static drop-in (§8.12)
```

Rules: every customer string through `momos_t()`; every fact (price, hours, phone, address, halal, allergen) through a
momos-core component/helper — the CI template scan (Doc 09 §2.9.4) runs on `themes/momos/**/*.php` and fails on `¥`
amounts, Japanese phone patterns `0\d{1,4}-\d{1,4}-\d{4}`, time ranges `\d{1,2}:\d{2}\s*[-–〜]`, "halal certified",
"100%", "ハラール認証".

---

## 3. Design tokens (ready to paste)

No dark mode in Phase 1 (`color-scheme: light`, Doc 08 §2.2.7). Values are provisional until DEC-046; only this file
and brand-settings images change when official identity arrives (TK-03-13). Breakpoints cannot be custom properties:
use the constants in §5.1.

### 3.1 `assets/css/tokens.css`

```css
/* themes/momos/assets/css/tokens.css — Doc 08 §2 (provisional until DEC-046). Components use semantic tokens only. */
:root {
  color-scheme: light;

  /* ---- Primitive palette (Doc 08 §2.2.1) ---- */
  --momo-c-cream-100:#FAF3EB; --momo-c-cream-050:#FDF8F3; --momo-c-white:#FFFFFF;
  --momo-c-peach-100:#FBEADE; --momo-c-peach-300:#EED0B8; --momo-c-peach-glow:#FDE3CF;
  --momo-c-orange-600:#C64F0E; --momo-c-orange-800:#A03B00;
  --momo-c-gold-400:#D99B43;   --momo-c-gold-700:#97651F;
  --momo-c-emerald-800:#1E3D34; --momo-c-emerald-700:#2B5A4B; --momo-c-emerald-100:#E6EFEA;
  --momo-c-ink-900:#221A17; --momo-c-ink-600:#6E625D; --momo-c-taupe-500:#8C7167;
  --momo-c-red-700:#BA1A1A; --momo-c-red-900:#8F1D14; --momo-c-red-050:#FBE9E7;
  --momo-c-amber-050:#FDF1DC; --momo-c-amber-900:#6B4410; --momo-c-amber-950:#5C3A08; --momo-c-amber-400:#FBBF24;
  --momo-c-stone-100:#EFE9E5; --momo-c-stone-700:#4A3F3A; --momo-c-mint-200:#C9D6D1;
  --momo-c-wp-blue:#2271B1; /* admin primary only (Doc 08 §3.8) */

  /* ---- Semantic colour (Doc 08 §2.2.2) ---- */
  --momo-color-canvas:var(--momo-c-cream-100);          /* page background */
  --momo-color-surface:var(--momo-c-white);             /* cards, menus, dialogs */
  --momo-color-surface-raised:var(--momo-c-cream-050);  /* header, sheets, field fill */
  --momo-color-tint:var(--momo-c-peach-100);            /* icon discs, chip hover, skeleton */
  --momo-color-text:var(--momo-c-ink-900);
  --momo-color-text-muted:var(--momo-c-ink-600);
  --momo-color-text-on-dark:var(--momo-c-white);
  --momo-color-text-on-dark-muted:var(--momo-c-mint-200);
  --momo-color-action:var(--momo-c-orange-600);         /* FILL ONLY (never text) */
  --momo-color-action-hover:var(--momo-c-orange-800);
  --momo-color-accent-text:var(--momo-c-orange-800);    /* eyebrows, small orange text */
  --momo-color-link:var(--momo-c-orange-800);           /* always underlined */
  --momo-color-brand:var(--momo-c-emerald-800);
  --momo-color-brand-2:var(--momo-c-emerald-700);       /* gradient end only */
  --momo-color-brand-tint:var(--momo-c-emerald-100);
  --momo-color-decor:var(--momo-c-gold-400);            /* decoration only, never text */
  --momo-color-gold-text:var(--momo-c-gold-700);        /* gold text on surface/canvas only */
  --momo-color-border:var(--momo-c-peach-300);          /* decorative; never sole control boundary */
  --momo-color-border-field:var(--momo-c-taupe-500);    /* inputs, checkboxes, unselected choice chips */
  --momo-color-disabled-text:var(--momo-c-taupe-500);
  --momo-color-error:var(--momo-c-red-700);
  --momo-color-error-strong:var(--momo-c-red-900);
  --momo-color-error-tint:var(--momo-c-red-050);
  --momo-color-warning-tint:var(--momo-c-amber-050);
  --momo-color-warning-text:var(--momo-c-amber-950);
  --momo-color-pending-text:var(--momo-c-amber-900);
  --momo-color-neutral-tint:var(--momo-c-stone-100);
  --momo-color-neutral-text:var(--momo-c-stone-700);
  --momo-color-success:var(--momo-c-emerald-800);
  --momo-color-overlay:rgba(34,26,23,.5);               /* dialog scrim */
  --momo-color-focus:var(--momo-c-emerald-800);         /* ring on light surfaces */
  --momo-color-focus-on-dark:var(--momo-c-amber-400);   /* ring on emerald surfaces */
  --momo-color-toast-bg:var(--momo-c-ink-900);
  --momo-gradient-band:linear-gradient(180deg,var(--momo-c-emerald-800),var(--momo-c-emerald-700)); /* direction-neutral [Proposed] */
  --momo-banner-glow:radial-gradient(760px 280px at 50% 0,var(--momo-c-peach-glow),transparent 70%);

  /* ---- Reservation status (Doc 08 §2.2.4) bg / text / border ---- */
  --momo-status-pending-bg:#FDF1DC;   --momo-status-pending-fg:#6B4410;   --momo-status-pending-bd:#97651F;
  --momo-status-confirmed-bg:#E6EFEA; --momo-status-confirmed-fg:#1E3D34; --momo-status-confirmed-bd:#1E3D34;
  --momo-status-rejected-bg:#FBE9E7;  --momo-status-rejected-fg:#8F1D14;  --momo-status-rejected-bd:#BA1A1A;
  --momo-status-neutral-bg:#EFE9E5;   --momo-status-neutral-fg:#4A3F3A;   --momo-status-neutral-bd:#8C7167; /* cancelled, expired, no_show */

  /* ---- Allergen state icon colours (Doc 08 §2.2.5) ---- */
  --momo-allergen-contains:#BA1A1A; --momo-allergen-may:#97651F; --momo-allergen-not:#1E3D34; --momo-allergen-unverified:#6E625D;

  /* ---- Typography: families (Latin default = en) ---- */
  --momo-font-heading-latin:"Epilogue","Plus Jakarta Sans","Noto Sans",system-ui,sans-serif;
  --momo-font-text-latin:"Plus Jakarta Sans","Noto Sans",system-ui,sans-serif;
  --momo-font-heading:var(--momo-font-heading-latin);
  --momo-font-text:var(--momo-font-text-latin);
  --momo-font-price:var(--momo-font-heading);           /* Epilogue in en; script text family elsewhere */
  --momo-font-scale:1; --momo-lh-body:1.6; --momo-lh-heading:1.15; --momo-fw-heading:800; --momo-fw-heading-sub:700;
  --momo-eyebrow-transform:uppercase; --momo-eyebrow-track:.12em; --momo-eyebrow-size:.75rem; --momo-track-body:0;

  /* ---- Type scale (Doc 08 §2.3.2) size / line-height / weight / tracking ---- */
  --momo-type-display:clamp(2.25rem,1.6rem + 2.6vw,3.5rem);  --momo-lh-display:1.12; --momo-track-display:-.02em;
  --momo-type-h1:clamp(2rem,1.5rem + 2vw,2.75rem);           --momo-lh-h1:1.15;      --momo-track-h1:-.015em;
  --momo-type-h2:clamp(1.625rem,1.3rem + 1.4vw,2.25rem);     --momo-lh-h2:1.2;       --momo-track-h2:-.01em;
  --momo-type-h3:1.375rem;   --momo-lh-h3:1.3;    --momo-track-h3:-.005em;  /* weight 700 */
  --momo-type-title:1.125rem; --momo-lh-title:1.35;                          /* weight 700 */
  --momo-type-lead:1.125rem;  --momo-lh-lead:1.6;                            /* weight 400 */
  --momo-type-body:1rem;                                                     /* lh = --momo-lh-body */
  --momo-type-body-sm:.875rem; --momo-lh-body-sm:1.5;
  --momo-type-label:.875rem;   --momo-lh-label:1.25; --momo-track-label:.01em; /* weight 700 */
  --momo-type-price:1.125rem;  --momo-lh-price:1.2;                          /* weight 800 (700 non-Latin) */
  --momo-type-micro:.75rem;    --momo-lh-micro:1.35; --momo-track-micro:.02em; /* Latin only; weight 700 (see §15 C5) */
  --momo-fs-body:calc(var(--momo-type-body) * var(--momo-font-scale));
  --momo-fs-body-sm:calc(var(--momo-type-body-sm) * var(--momo-font-scale));
  --momo-fs-label:calc(var(--momo-type-label) * var(--momo-font-scale));
  --momo-fs-lead:calc(var(--momo-type-lead) * var(--momo-font-scale));
  --momo-fs-title:calc(var(--momo-type-title) * var(--momo-font-scale));

  /* ---- Spacing (4 px base, Doc 08 §2.4) ---- */
  --momo-space-1:4px; --momo-space-2:8px; --momo-space-3:12px; --momo-space-4:16px; --momo-space-5:20px;
  --momo-space-6:24px; --momo-space-8:32px; --momo-space-10:40px; --momo-space-12:48px; --momo-space-16:64px; --momo-space-18:72px;
  --momo-section-y:clamp(48px,6vw,72px);

  /* ---- Sizing / touch targets ---- */
  --momo-target-min:24px; --momo-target-primary:44px; --momo-target-bar:48px;
  --momo-control-h:48px; --momo-btn-h-lg:52px; --momo-btn-h-sm:40px;
  --momo-chip-h:40px; --momo-chip-choice-h:44px;
  --momo-icon-sm:16px; --momo-icon:20px; --momo-icon-lg:24px;
  --momo-dish-plate:130px; --momo-dish-plate-tablet:104px; --momo-dish-plate-list:88px; --momo-dish-plate-related:96px;
  --momo-dish-plate-overlap:58px; --momo-plate-ring:5px;
  --momo-tile-img:96px; --momo-tile-img-hub:120px; --momo-hero-img-mobile-max:240px; --momo-admin-thumb:48px;

  /* ---- Radii and borders (Doc 08 §2.5) ---- */
  --momo-radius-pill:999px; --momo-radius-card:24px; --momo-radius-sheet:28px; --momo-radius-media:20px;
  --momo-radius-field:14px; --momo-radius-tile:18px; --momo-radius-admin:8px; --momo-radius-circle:50%;
  --momo-border-w:1px; --momo-border-w-field:1.5px; --momo-border-w-selected:2px; --momo-accent-bar-w:4px;
  --momo-focus-w:2px; --momo-focus-offset:2px;

  /* ---- Layout (Doc 08 §2.6) — mobile values; overridden at breakpoints in momos-ui.css ---- */
  --momo-container:1200px; --momo-container-pad:24px; --momo-reading:720px; --momo-reading-tablet:640px; --momo-wide:1280px;
  --momo-gutter:16px; --momo-margin:16px;
  --momo-header-h:60px; --momo-bar-h:64px;

  /* ---- Elevation (Doc 08 §2.7) ---- */
  --momo-shadow-none:none;
  --momo-shadow-soft:0 10px 30px -12px rgba(74,40,20,.22);
  --momo-shadow-plate:0 10px 22px -8px rgba(60,30,10,.45);
  --momo-shadow-primary:0 8px 18px -8px rgba(198,79,14,.7);
  --momo-shadow-header:0 10px 28px -16px rgba(74,40,20,.4);
  --momo-shadow-overlay:0 0 60px rgba(0,0,0,.3);
  --momo-z-content:0; --momo-z-sticky:40; --momo-z-header:50; --momo-z-bar:60; --momo-z-scrim:90;
  --momo-z-dialog:100; --momo-z-toast:120; --momo-z-skip:200;

  /* ---- Motion (Doc 08 §2.8) — used only inside prefers-reduced-motion:no-preference ---- */
  --momo-dur-fast:150ms; --momo-dur-base:200ms; --momo-dur-slow:300ms; --momo-ease:cubic-bezier(.2,.8,.2,1);
  --momo-lift:-4px; --momo-press-scale:.97;

  /* ---- Imagery (Doc 08 §2.10) ---- */
  --momo-ratio-branch:4/3; --momo-ratio-offer:4/3; --momo-ratio-branch-hero:16/9; --momo-ratio-map:16/9; --momo-ratio-gallery:1/1;
}

/* ---- Per-script overrides (Doc 08 §2.3.1/2.3.3; keyed off <html lang>) ---- */
:root:lang(ja){ --momo-font-heading:"Noto Sans JP","Hiragino Sans","Yu Gothic",sans-serif; --momo-font-text:"Noto Sans JP","Hiragino Sans","Yu Gothic",sans-serif;
  --momo-font-price:var(--momo-font-text); --momo-lh-body:1.8; --momo-lh-heading:1.35; --momo-fw-heading:700; --momo-track-body:.02em;
  --momo-eyebrow-transform:none; --momo-eyebrow-track:0; --momo-eyebrow-size:.875rem; }
:root:lang(ar){ --momo-font-heading:"Noto Naskh Arabic","Segoe UI",Tahoma,serif; --momo-font-text:"Noto Naskh Arabic","Plus Jakarta Sans","Segoe UI",Tahoma,serif;
  --momo-font-price:var(--momo-font-text); --momo-font-scale:1.1; --momo-lh-body:1.8; --momo-lh-heading:1.4; --momo-fw-heading:700;
  --momo-eyebrow-transform:none; --momo-eyebrow-track:0; --momo-eyebrow-size:.875rem; }
:root:lang(ur){ --momo-font-heading:"Noto Nastaliq Urdu","Noto Naskh Arabic",serif; --momo-font-text:"Noto Nastaliq Urdu","Plus Jakarta Sans","Noto Naskh Arabic",serif;
  --momo-font-price:var(--momo-font-text); --momo-font-scale:1.15; --momo-lh-body:2; --momo-lh-heading:1.7; --momo-fw-heading:700;
  --momo-lh-h1:1.7; --momo-lh-h2:1.7; --momo-lh-h3:1.7; --momo-lh-title:1.9; --momo-lh-label:1.9; --momo-lh-body-sm:2;
  --momo-control-h:52px; --momo-btn-h-lg:56px; --momo-chip-h:48px; --momo-chip-choice-h:48px;
  --momo-eyebrow-transform:none; --momo-eyebrow-track:0; --momo-eyebrow-size:1rem; }
:root:lang(hi){ --momo-font-heading:"Noto Sans Devanagari","Nirmala UI",sans-serif; --momo-font-text:"Noto Sans Devanagari","Plus Jakarta Sans","Nirmala UI",sans-serif;
  --momo-font-price:var(--momo-font-text); --momo-font-scale:1.05; --momo-lh-body:1.7; --momo-lh-heading:1.4; --momo-fw-heading:700;
  --momo-eyebrow-transform:none; --momo-eyebrow-track:0; --momo-eyebrow-size:.875rem; }
:root:lang(bn){ --momo-font-heading:"Noto Sans Bengali","Nirmala UI",sans-serif; --momo-font-text:"Noto Sans Bengali","Plus Jakarta Sans","Nirmala UI",sans-serif;
  --momo-font-price:var(--momo-font-text); --momo-font-scale:1.05; --momo-lh-body:1.7; --momo-lh-heading:1.4; --momo-fw-heading:700;
  --momo-eyebrow-transform:none; --momo-eyebrow-track:0; --momo-eyebrow-size:.875rem; }
```

### 3.2 Base rules (top of `momos-ui.css`; mandatory)

```css
*,*::before,*::after{box-sizing:border-box}
html{ -webkit-text-size-adjust:100%; scroll-padding-top:var(--momo-header-h);
      scroll-padding-bottom:calc(var(--momo-bar-h) + env(safe-area-inset-bottom)); }   /* UI-CHG-047, WCAG 2.4.11 */
body{ margin:0; background:var(--momo-color-canvas); color:var(--momo-color-text); font-family:var(--momo-font-text);
      font-size:var(--momo-fs-body); line-height:var(--momo-lh-body); letter-spacing:var(--momo-track-body); }
h1,h2,h3,.momo-h1,.momo-h2,.momo-h3,.momo-display{ font-family:var(--momo-font-heading); font-weight:var(--momo-fw-heading); }
h3,.momo-h3,.momo-title{ font-weight:var(--momo-fw-heading-sub); }
:root:is(:lang(ar),:lang(ur),:lang(hi),:lang(bn),:lang(ja)) :is(h1,h2,h3,.momo-display){ letter-spacing:0; } /* tracking Latin only */
a{ color:var(--momo-color-link); text-decoration:underline; text-underline-offset:.15em; }
:focus-visible{ outline:var(--momo-focus-w) solid var(--momo-color-focus); outline-offset:var(--momo-focus-offset); }
.momo-on-dark :focus-visible{ outline-color:var(--momo-color-focus-on-dark); }
:lang(ja){ line-break:strict; word-break:normal; }
.momo-icon{ inline-size:var(--momo-icon); block-size:var(--momo-icon); flex:none; fill:none; stroke:currentColor; stroke-width:2;
            stroke-linecap:round; stroke-linejoin:round; }
[dir="rtl"] .momo-icon--dir{ transform:scaleX(-1); }                  /* FR-I18N-001(c); :dir(rtl) equivalent */
.momo-visually-hidden:not(:focus):not(:active){ position:absolute; inline-size:1px; block-size:1px; overflow:hidden;
            clip-path:inset(50%); white-space:nowrap; }
img{ max-inline-size:100%; block-size:auto; }
[hidden]{ display:none !important; }
@media (min-width:768px){ :root{ --momo-header-h:78px; --momo-gutter:20px; --momo-margin:24px; }
  html{ scroll-padding-bottom:0; } }                                    /* action bar is mobile-only */
@media (min-width:1024px){ :root{ --momo-gutter:24px; } }
@media (prefers-reduced-motion:no-preference){ html{ scroll-behavior:smooth; } }
/* All transitions/animations are declared ONLY inside @media (prefers-reduced-motion:no-preference). */
```

CI checks: stylelint `property-disallowed-list` + `declaration-property-value-disallowed-list` ban `margin-left/right`,
`padding-left/right`, `left`, `right`, `border-left/right*`, `float: left|right`, `text-align: left|right`,
`flex-direction: row-reverse` in theme and momos-core CSS (TC-RTL-006); `color-no-hex` outside `tokens.css`
(Doc 09 §2.10 rule 2); `momos-ui.css` minified ≤ 30 KB.

### 3.3 Contrast evidence (keep when tokens change — TK-03-03 AC "contrast matrix passes")

| Pair | Ratio | Pair | Ratio |
|---|---|---|---|
| text on canvas / surface / tint | 15.54 / 17.10 / ≥ 14.21 | white on action `#C64F0E` (button) | 4.64 |
| muted on canvas / surface / tint | 5.35 / 5.89 / 5.02 | white on action-hover `#A03B00` | 6.73 |
| accent-text on canvas / surface / tint | 6.12 / 6.73 / 5.74 | white on brand `#1E3D34` | 11.85 |
| gold-text on surface / canvas / raised | 5.00 / 4.55 / 4.74 | mint on brand | 7.91 |
| border-field vs surface / raised / canvas | 4.49 / 4.26 / 4.08 | focus emerald on canvas / surface | 10.77 / 11.85 |
| error on surface / canvas | 6.46 / 5.87 | focus amber on emerald | 7.10 |
| warning-text on warning-tint | 9.11 | error-strong on error-tint | 7.61 |
| pending fg on bg | 7.64 | neutral fg on bg | 8.46 |

Prohibited pairs: `--momo-color-decor` as text (2.41:1); `--momo-color-action` as text (use accent-text);
`--momo-color-border` as the only boundary of a control (1.46:1).

---

## 4. Typography

### 4.1 Families per language (DEC-061; FR-I18N-004)

| Lang | Text family | Heading family | Latin runs | Files loaded (weights) |
|---|---|---|---|---|
| en | Plus Jakarta Sans | Epilogue | — | Epilogue 700, 800 · PJS 400, 700 = **4** |
| ja | Noto Sans JP | Noto Sans JP | own Latin glyphs | Noto Sans JP 400, 700 (unicode-range slices) |
| ar | Noto Naskh Arabic | Noto Naskh Arabic (700) | Plus Jakarta Sans | Naskh 400, 700 · PJS 400, 700 = 4 |
| ur | Noto Nastaliq Urdu | Noto Nastaliq Urdu (700) | Plus Jakarta Sans | Nastaliq 400, 700 · PJS 400, 700 = 4 |
| hi | Noto Sans Devanagari | Noto Sans Devanagari (700) | Plus Jakarta Sans | Devanagari 400, 700 · PJS 400, 700 = 4 |
| bn | Noto Sans Bengali | Noto Sans Bengali (700) | Plus Jakarta Sans | Bengali 400, 700 · PJS 400, 700 = 4 |

Epilogue is **never** loaded on non-Latin pages (the wordmark is an outlined SVG) — AC-I18N-01-01. No italics anywhere.
Noto Sans is a stack fallback only (not downloaded).

### 4.2 Scale usage

| Token | Size mobile → desktop | LH | Weight | Use |
|---|---|---|---|---|
| display | 36 → 56 px | 1.12 | 800 (700 non-Latin) | Home hero H1 only |
| h1 | 32 → 44 | 1.15 | 800 | Page banner H1 |
| h2 | 26 → 36 | 1.2 | 800 | Section H2 |
| h3 | 22 | 1.3 | 700 | Sub-sections, panel titles |
| title | 18 | 1.35 | 700 | Card titles |
| lead | 18 | 1.6 | 400 | Intro paragraphs |
| body | 16 | 1.6 | 400 | Running text |
| body-sm | 14 | 1.5 | 400 | Card descriptions, hints, captions |
| label | 14 | 1.25 | 700, +.01em | Buttons, chips, field labels |
| eyebrow | 12 (Latin, uppercase, .12em) / 14 non-Latin (16 ur) no transform | 1.2 | 700 | Section eyebrow |
| price | 18 | 1.2 | 800 (Epilogue in en) | Price amount |
| micro | 12 | 1.35 | 700 | Badges, small print — Latin pages only |

Minimums: Latin ≥ 12 px; running text ≥ 14 px; non-Latin ≥ 14 px for any text and ≥ 16 px running text; ur controls
≥ 16 px labels. Inputs ≥ 16 px (prevents iOS zoom). Per-script multipliers (`--momo-font-scale`): ja 1.0, ar 1.1,
ur 1.15, hi 1.05, bn 1.05 (body/labels only; display/H1/H2 keep clamp values). ur: line clamps disabled on titles;
`overflow: visible` on labels; no fixed-height pills with `overflow:hidden`.

### 4.3 Font loading rules

| Rule | Value |
|---|---|
| Hosting | `themes/momos/assets/fonts/` WOFF2; CSP `font-src 'self' data:` (Doc 11); no Google Fonts, no icon fonts |
| Enqueue | `fonts-{lang}.css` for the current language only (+ nothing else) |
| `font-display` | `swap` on every face |
| Preload | one file: current language text face 400 (en `plus-jakarta-sans-400`, ja kana/common slice 400, ar `noto-naskh-arabic-400`, …) with `crossorigin` |
| Subsets | Latin: Basic Latin + Latin-1 + punctuation, `¥ ‐ – — · ›`; ja: unicode-range slices (kana + JIS X 0208 level 1 + symbols); ar: Arabic + Supplement + presentation forms; ur: full Urdu coverage; hi: Devanagari; bn: Bengali |
| Budgets per page view [TBD → default, measure in PROTO] | en ≤ 140 KB; ja ≤ 300 KB of slices; ar ≤ 120 KB + Latin ≤ 70 KB; ur ≤ 450 KB + ≤ 70 KB; hi ≤ 160 KB + ≤ 70 KB; bn ≤ 180 KB + ≤ 70 KB; all within NFR-PERF-03 1.5 MB |
| Licences | SIL OFL 1.1 — ship `OFL.txt` per family |
| Fallback stacks | as `tokens.css` (§3.1) |

### 4.4 `@font-face` naming [Proposed]
`assets/fonts/{epilogue|plus-jakarta-sans|noto-sans-jp|noto-naskh-arabic|noto-nastaliq-urdu|noto-sans-devanagari|noto-sans-bengali}/{family}-{weight}[-{slice}].woff2`.
Family names in `@font-face` exactly as in the stacks ("Epilogue", "Plus Jakarta Sans", "Noto Sans JP", …).

---

## 5. Layout, grid, breakpoints, elevation, motion

### 5.1 Breakpoints and grid (mobile-first; constants)

| | Mobile ≤ 767 | Tablet 768–1023 | Desktop ≥ 1024 | Wide ≥ 1366 |
|---|---|---|---|---|
| Media query | default | `(min-width:768px)` | `(min-width:1024px)` | `(min-width:1366px)` |
| Columns / gutter / outer margin | 4 / 16 / 16 | 8 / 20 / 24 | 12 / 24 / auto (container 1200 + 2×24) | same |
| Card grids | 1 (dish = list card) | 2 | 3 | 4 for category tiles + featured dishes |
| Reading width | 100 % | 640 | 720 (≈ 68 ch) | 720 |
| Header | 60 px: logo · switcher · menu button | 78 px: logo · switcher · CTA · menu button | 78 px: logo · nav pill · switcher · CTA | same |
| Mobile action bar | visible (except C09–C11, keyboard open) | hidden | hidden | hidden |
| Two-column templates (C05 8+4, C08/C09 7+5) | stacked | stacked | side column sticky (`top: calc(var(--momo-header-h) + 16px)`) | same |
| Footer | stacked columns, bottom padding += bar height | 2×2 | 4 columns | same |
| Test widths | 360, 390 | 768 | 1024, 1366 | 1920 (gallery wide 1280 only) |

Layout rules: reflow at 320 px and 400 % zoom without 2-D scroll except labelled data-table scroll containers
(`<div role="region" aria-label tabindex="0">`); WCAG 1.4.12 text-spacing overrides must not clip (no fixed heights on
text containers); explicit `width`/`height` on images; no `position:fixed` inside transformed parents (in-app browsers);
no `backdrop-filter` on large sticky surfaces (UI-CHG-055); landscape phones ≥ 768 use tablet header.

### 5.2 Elevation and layers
Cards rest with border only (`--momo-shadow-none`); hover/dropdowns `soft`; plates `plate`; primary buttons `primary`;
header after scroll `header` (class `.is-scrolled` set by `IntersectionObserver` on a sentinel); dialogs/panel
`overlay`. Shadows are symmetric (no directional offset) — RTL-safe. z-index only via `--momo-z-*`.

### 5.3 Motion
Only `transform`/`opacity` animate; all inside `@media (prefers-reduced-motion:no-preference)`; with `reduce` changes
are instant. Uses: button press `scale(.97)` 150 ms; card hover lift −4 px + `soft` shadow 200 ms; chip selection
200 ms; mobile panel / dialog enter-exit 300 ms `--momo-ease`. **Never:** marquee, autoplay, carousels/sliders,
rotating or infinite animations, countdowns, skeleton shimmer, Elementor-style entrance animations (UI-CHG-038).

---

## 6. Iconography (Doc 08 §2.9; UI-CHG-037)

| Aspect | Spec |
|---|---|
| Format | One inline SVG sprite `assets/icons/sprite.svg`; rendered `<svg class="momo-icon" aria-hidden="true" focusable="false"><use href="…#i-{name}"/></svg>` via `momos_icon()` |
| Grid | 24 px viewBox, 2 px stroke, round caps/joins, `currentColor` |
| Sizes | 16 (inline with small text), 20 (default), 24 (action bar, headers); plate-free icon discs 40–48 px with `--momo-color-tint` |
| Required set | home, menu, store, map-pin, phone, clock, calendar, users, globe, chevron-start, chevron-end, chevron-down, arrow-start, arrow-end, external-link, copy, check, check-circle, x, x-circle, slash-circle, clock-x, clock-alert, hourglass, info, alert-triangle, alert-circle, flag, mail, mail-x, user, user-check, user-x, image, lock, chili-filled, chili-outline, leaf (vegetarian), sprout (vegan), wine, wine-off, filter, plus, minus, directions, shield-check (halal block disc — not a seal shape), spinner; allergen state shapes ● ◐ ○ ? are SVG too |
| Mirroring | `momo-icon--dir` (mirrors in RTL) only on: chevron-start/end, arrow-start/end, directions, breadcrumb separator. Never: phone, clock, map-pin, check, logo, media controls, allergen shapes, globe |
| Meaning | Never alone: visible text label or accessible name on the control |
| Licence | Prototype icons (ownership per PR-001 ⟦confirm⟧); additions from MIT/ISC set (e.g., Lucide) with licence file in `assets/icons/LICENSE`; no Material Symbols |
| Dietary/allergen icons (TK-03-10) | SVG per dietary tag; non-text contrast ≥ 3:1; always paired with text |

---

## 7. Component library (Doc 08 §3)

Conventions for **every** component: states default / hover / focus-visible / active / disabled / error (where
applicable) + RTL variant (TK-03-06 AC); targets ≥ 24×24 (≥ 44 primary, ≥ 48 bar); logical properties; text from
`momos_t()`; facts from momos-core. "Partial" = theme `template-parts/…` or momos-core `templates/widgets/{slug}.php`.

### 7.1 Global and navigation (frame details in §8)

| Component · class · partial | Anatomy | Variants / sizes | States | A11y / RTL |
|---|---|---|---|---|
| CMP-SkipLink · `.momo-skip` · frame/skip-link | Link `skip.main` → `<main id="main" tabindex="-1">`; first focusable element | — | Hidden until focus; on focus: `inset-inline-start:8px; inset-block-start:8px`, surface bg, focus ring, z 200 | WCAG 2.4.1 |
| CMP-Header · `.momo-header` | §8.3 | default (opaque raised surface); `.is-scrolled` adds `--momo-shadow-header`; no transparent-over-hero | nav item rest / hover (tint bg, accent text) / current (`aria-current="page"`, action fill, white text — static pill, no sliding indicator) / focus | `<header>`; `<nav aria-label>`; sticky; logo at inline-start |
| CMP-Logo · `.momo-logo` | Outlined SVG wordmark: "MOMO's" (Epilogue 800, ink) + "CAFE & HALAL RESTAURANT" (PJS 700, .14em, uppercase, emerald) inside link to language home | on-light; on-dark (white + mint); compact (mobile, single line "MOMO's"); heights 40 desktop / 32 mobile / 40 footer; clear space ≥ 0.5 × height | — | Link name `a11y.logo_home`; SVG `aria-hidden`; never mirrored; never the halal seal; replaced via brand setting `momo_logo_primary`/`momo_logo_inverse` (DEC-046) |
| CMP-LangSwitcher · `.momo-lang` | Trigger: globe + current **native name** + chevron-down; list of enabled languages in order 日本語 · English · العربية · اردو · हिन्दी · বাংলা | header dropdown; mobile-menu grid (2 columns × 3 rows, 48 px buttons); footer plain links | current: emerald fill, white text, check icon, `aria-current="true"`; hover tint; disabled languages not rendered | Disclosure `<button aria-expanded aria-controls>`; each `<a lang hreflang dir>`; Esc closes + focus to trigger; list aligned `inset-inline-end`; no flags; ≥ 44 px |
| CMP-LangSuggestionBar · `.momo-langbar` | Text `lang.suggest` written in suggested language · button `lang.switch` · close × (`a11y.close`) | — | shown once; dismissed/switched → cookie | `role="region"` `aria-label`; own `lang`/`dir`; brand-tint bg, emerald text 14 px; never redirects |
| CMP-NoticeBanner · `.momo-notice` | Icon · title (bold) · body (≤ ⟦TBD 300 chars⟧) · optional link · optional dismiss (site-wide info only) | `--info` (brand tint, emerald text, accent bar emerald) · `--warning` (warning tint, warning text, 4 px gold-text bar); scope site-wide (below header) / branch (top of branch page; one-line chip on branch cards); mobile: title + 1 line, "More" expands | scheduled/active/expired by JST; untranslated → not rendered | `role="region" aria-label=a11y.notice`; not `alert`; `border-inline-start` accent; icon + text + colour |
| CMP-MobileMenu · `.momo-mmenu` | §8.5 | — | closed (not rendered by `<dialog>`), open (slides from inline-end 300 ms; instant under reduce) | `<dialog aria-labelledby>`; focus to close button; Esc/scrim close; focus returns to trigger; rows ≥ 52 px |
| CMP-MobileActionBar · `.momo-bar` | §8.6 | default pages / branch pages / branch not accepting | pressed darkens; hidden ≥ 768, on C09–C11, and while keyboard open | `<nav aria-label=a11y.nav.quick>`; labels always visible |
| CMP-Footer · `.momo-footer` | §8.7 | — | links underline on hover/focus | `<footer>`; each column `<nav aria-labelledby>` |
| CMP-Breadcrumbs · `.momo-breadcrumbs` | Home › … › Current; separator by CSS (chevron, mirrored); current not a link | 14 px; muted links; current `--momo-color-accent-text` | — | `<nav aria-label=a11y.breadcrumb><ol>`; current `aria-current="page"`; not on Home, C09, C10, C11 |

### 7.2 Actions and inputs

| Component · class | Anatomy | Variants / sizes | States | A11y / RTL |
|---|---|---|---|---|
| CMP-Button · `.momo-btn` | Optional leading icon + verb-first label | `--primary` (action fill, white, `shadow-primary`) · `--secondary` (surface, 1.5 px border-field, ink) · `--on-dark` (white fill, emerald label) · `--text` (accent-text, underline) · `--icon` (44×44 circle, surface, border) · `--danger` (admin: white, error border+text). Heights: default 48 (pad 12/22), `--lg` 52 (hero; 56 ur), `--sm` 40 (admin/inline). Radius pill; label token; wraps to 2 lines on mobile rather than truncate | rest · hover (primary → `#A03B00`) · focus ring · active (scale .97, motion-gated) · busy (`aria-busy="true"`, spinner, label `res.submit.busy`, disabled against resubmission) · disabled (rare; `aria-disabled` + visible reason, disabled-text colour) | Real `<button>`/`<a>`; icon at inline-start; directional icons mirrored. One primary per section. Never "Order", "Book", "Confirm" for requests |
| CMP-Chip / ChipGroup · `.momo-chip` | Pill, padding 0 16 px, label 14/700, optional leading icon | `--link` (category nav; current = action fill + `aria-current`) · `--choice` (radio; 44 px; 48 ur) · `--filter` (checkbox) · `--tag` (non-interactive). Height 40 (48 ur) | unselected: surface + border-field + ink · selected: action fill, white, check icon · hover tint · focus · disabled: dashed border, muted label, `aria-disabled`, reason text "(closed)" | Group = `<fieldset><legend>`; choice = native radios (arrow keys); filter = checkboxes; link chips = links in `<nav>`. Link chips scroll horizontally on mobile (`overflow-x:auto; scroll-snap-type:x proximity`, edge fade + visible partial chip, starts at inline-start); choice chips always wrap |
| CMP-Field · `.momo-field` | Label 14/700 + "(required)"/"(optional)" text · hint 14 muted (`aria-describedby`) · control 48 px (textarea min 120 px) · inline error (icon + text) · counter from 90 % of limit | text, email, tel, number, textarea, select | rest 1.5 px border-field on raised fill · hover border ink · focus 2 px emerald border + ring · filled · error 2 px error border, `aria-invalid="true"`, message below · read-only → CMP-LockedField (admin) | `<label for>`; `autocomplete` name/email/tel; no placeholder-only labels; email/tel/url `dir="ltr"` (label follows page direction); name/notes `dir="auto"`; font ≥ 16 px; radius 14 |
| CMP-Choice · `.momo-choice` | Row: 24 px box (1.5 px border-field) + label; whole row is the label; min 48 px | checkbox · radio · consent (label with links "(opens in a new tab)") | checked: action fill + white check; selected row border 2 px action; error text below row | Native inputs; consent unchecked by default |
| CMP-Stepper · `.momo-stepper` | − button · `<input inputmode="numeric">` (party_min..party_max) · + button; "More than {max}? Call {branch}" link | — | min/max disable the respective button with `aria-disabled` | Buttons `a11y.stepper.dec` / `.inc`; native digits normalised |
| CMP-ErrorSummary · `.momo-errsum` | Error-tint panel, heading `res.err.summary`, list of links to invalid fields | — | appears on submit with errors; updates on retry | `tabindex="-1"`, `role="alert"`, receives focus; error-strong text on error tint, 4 px error bar inline-start |
| CMP-Accordion · `.momo-accordion` | Full-width question `<button>` (≥ 48 px, chevron-down at inline-end rotates 180°) + panel | multiple open; URL hash opens a question | — | `aria-expanded`/`aria-controls`; native `<details>` acceptable [Proposed] |

### 7.3 Content and cards

| Component · class | Anatomy | Variants | States / rules | A11y / RTL |
|---|---|---|---|---|
| CMP-Card · `.momo-card` | Surface, 1 px border, radius 24, padding 24 (20 mobile) | `--interactive` (hover lift + soft shadow; focus-within ring) · `--tint` (peach or brand-tint) · `--pillar` (icon disc + title + text) · `--link` (404 cards: icon + title; whole card one link) | no nested cards; interactive cards still show a visible text link | One link with stretched hit area (`::after{inset:0}`) — UI-CHG-041 |
| CMP-SectionHeader · `.momo-section-head` | Optional eyebrow (`.momo-eyebrow`, accent-text) · H2 · optional lead (muted) · optional end link ("See all offers ›") at inline-end | — | eyebrows carry section/category names only — no claims (UI-CHG-013) | Heading order kept |
| CMP-Hero · `.momo-hero` | H1 (display; 2nd line accent-text, upright) · lead · primary `cta.request_table` · secondary `cta.find_branch` · conditional link `offers.see_current` · link chips · one circular image (`.momo-plate--hero`) with static dashed decorative ring | ≥ 1024: 2 columns 1.05fr / 0.95fr; < 1024: image (≤ 240 px) above copy, centred, buttons full width stacked | no seal, floating chips, counters, autoplay, orbit rotation | Image decorative unless a named dish; `fetchpriority="high"`; H1 + CTA visible at 360×640 in ja/ar/ur |
| CMP-CategoryTile · `.momo-tile` | Circular image (74–96 px; 120 px hub) with white ring + label below (2 lines; wraps in ur) | chip (home hero) · tile (menu hub) | empty categories hidden | Whole tile one link; image `alt=""` |
| CMP-DishCard · `.momo-dish-card` | Plate (130 px, overlaps top by 58 px, 5 px white ring, plate shadow) · eyebrow (gold-text 12 px) · title (title token; the link) · original name (14 muted, `lang` of source, optional) · description (2-line clamp, body-sm) · labels row (DietaryIcon, spice, AvailabilityLabel, AdvanceOrderLabel) · dashed divider · PriceBlock compact · IllustrationLabel under photo when flagged | `--grid` (tablet 104 px / desktop 130 px plate, centred text) · `--list` (mobile: 88 px plate at inline-start, text at inline-end, start-aligned) · `--related` (96 px, no description) | rest border only; hover lift (motion-gated); focus-within ring; unavailable → text label, card fully readable (no opacity fade); no photo → tint circle with dish initial | Grid row gap `--momo-space-16` (UI-CHG-050); no add/cart button, no halal badge; ur: title clamp off; price LTR-isolated |
| CMP-BranchCard · `.momo-branch-card` | Name (title) + ConceptTag · area/station · OpenStatus · AddressBlock compact · phone `tel:` (LTR) · actions primary "Request a table", secondary "Directions", text "Details" · notice chip | directory (light) · `--on-dark` (home emerald band) · temporarily closed (warning chip `branch.temp_closed`, request action removed) | — | `<article>` + heading; action names include branch ("Directions to {branch}") |
| CMP-OfferCard · `.momo-offer-card` | Image 4:3 optional (+ IllustrationLabel) · eyebrow "Offer" · H3 · summary · validity `offers.valid` (+ "JST" non-ja; dates in `<bdi>`) · scope · conditions (> 3 lines → `offers.conditions.show_all` disclosure) · optional price "(tax incl.)" · CTA | home featured (max 3; row at ≥ 1024) · branch section · offers list | only active offers; no countdown/"ends soon"/coupon codes | `<article>`; disclosure is a `<button>` |
| CMP-StepList · `.momo-steps` | `<ol>` of 3 cards: number (peach), icon disc, title, text — "Send a request" → "The branch reviews it" → "We email you the decision" | — | response promise only if configured (DEC-013) | Western digits; icons decorative |
| CMP-FeatureBand · `.momo-band.momo-on-dark` | `--momo-gradient-band`, white/mint text, on-dark + primary buttons | home branch teaser; closing CTA band (About, Gallery) | focus ring amber | — |
| CMP-Gallery · `.momo-gallery` | Filter chips (All / branch / category) · grid with **visible captions** (dish or place + branch) · optional lightbox | 360: 2 cols square · 768: 3 · ≥ 1024: uniform or `.momo-bento` spans (2×2 / 1×2) | no autoplay, no hover-only content; flagged illustrative images rejected | Lightbox only if keyboard-operable (arrows, Esc, focus return) |
| CMP-IllustrationLabel · `.momo-illustration` | Caption **directly below** the image: `media.illustration`, 12–14 px, muted text on card surface | — | rendered iff attachment meta `momo_image_illustrative = 1`; accepted only on dish and offer images | text, not an overlay/pill |
| CMP-ConceptTag · `.momo-chip--tag.momo-concept` | Brand-tint tag, emerald text, branch concept label | — | hidden when unset; never a claim (DEC-001 ⟦confirm⟧) | — |

### 7.4 Restaurant data (data from Branch/Menu modules; visuals here)

| Component | Anatomy / rules | States | A11y |
|---|---|---|---|
| CMP-OpenStatus `.momo-open` | Dot (`aria-hidden`) + text `branch.open_now` / `branch.closed_now` / `branch.temp_closed`; open: emerald text + green dot; closed: ink + neutral dot; temporarily closed: warning-tint chip | loading: skeleton sized to text; failure: hidden (hours table stays) | Text carries meaning; times in `<bdi dir="ltr">` |
| CMP-HoursTable `.momo-hours` | Day · Opening hours ("12:00–15:00 · 17:00–22:00") · Last order; today row bold + "(today)"; closed "Closed" | — | `<table><caption>` + `scope`; columns mirror in RTL |
| CMP-ClosureList `.momo-closures` | Next 30 days: date · "Closed" or special periods · public reason label | empty → section not rendered | — |
| CMP-AddressBlock `.momo-address` | Pin · localised address · Japanese address `<span lang="ja" dir="ltr">` (always) · copy button `branch.copy_address` · access line | copy → toast `branch.copy_address.copied` (2 s); no Clipboard API → button hidden | — |
| CMP-MapFacade `.momo-map` | Static map image 16:9, radius media, lazy, pin · buttons `cta.open_maps`, `cta.directions` (external-link icon, new tab + `a11y.new_tab`) | image fails → buttons remain; Google Maps JS never on load | alt "Map showing {branch} location" |
| CMP-FacilityList `.momo-facilities` | Icon + label rows, confirmed values only | unconfirmed hidden | — |
| CMP-PriceBlock `.momo-price` | Compact: `<bdi dir="ltr">¥1,400</bdi>` (price token) + `price.tax_incl` 12–14 muted · Branch: + "at {branch}" + "Change" · Per-branch table: Branch · Price · Availability · Reference: `menu.branch_select.all` | design comps: "SAMPLE" marker on sample prices; sample prices never public | No tax rate text; no strike-through "usual price" ⟦counsel⟧ |
| CMP-DietaryIcon `.momo-diet` | Icon 16–20 + text label; spice = 3 chili icons (filled = level 0–3) + text | — | Icons `aria-hidden`; text always; dish-level halal is a text line from HalalStatement, never a badge |
| CMP-AvailabilityLabel `.momo-avail` | `--available` (brand tint) · `--soldout` (neutral tint + clock, `menu.sold_out`) · `--unavailable` (neutral, `menu.unavailable` + reason) · `--none` (`menu.not_available`) | only with branch context, except `--none` | Icon + text, no opacity fade |
| CMP-AdvanceOrderLabel `.momo-advance` | Clock + `menu.advance_order` | — | — |
| CMP-BranchSelector `.momo-branch-select` | Label `menu.branch_select.label` + native `<select>`: `menu.branch_select.all` + branches | change updates `?branch=` without scroll loss; status message announces count | `role="status"` result count |
| CMP-MenuFilters `.momo-filters` (Should) | Mobile: button `menu.filters.toggle` "Filters ({n})" opening panel (dietary checkboxes, max-spice radios); desktop: inline filter chips; `menu.filters.clear`; count `role="status"` | empty → `menu.filters.empty` | No "Halal verified" pseudo-filter |

### 7.5 Trust and claims (wording from Doc 11 templates via `Dietary\ClaimResolver`; never typed in theme)

| Component | Anatomy | Variants / states | Rules / A11y |
|---|---|---|---|
| CMP-HalalStatement `.momo-halal` | Heading `cta.halal_info` · template text · for `certified` only: certifier (`<bdi>`), scope, certificate no., valid until (`<dl>`) · optional mark image only if record active + in validity + approved + mark permission (alt "Halal certification mark of {certifier}") · `last_verified` · alcohol lines · link to C03 | brand (home/about) · branch (C05) · dish (compact one line, C08) · register row (C03). No approved statement → `no_claim` (branch) / `brand.neutral` (brand); expired/revoked → mark + cert lines vanish | Surface card radius 24, emerald icon disc **shield-check outline (not seal shape)**; no "100 %", "fully", "strictly certified" |
| CMP-HalalRegister `.momo-halal-register` | Table: Branch · Statement · Certifier · Scope · Certificate no. · Valid until · Alcohol policy · Last verified; mobile = one card per branch | — | `<caption>`, `scope` |
| CMP-AllergenTable `.momo-allergens` | (0) summary `allergen.summary.contains` / `.may_contain` (omitted when empty) · (1) banner `allergen.banner.not_verified` (warning tint, `role="note"`) when any `not_verified` or review flag · (2) legend always above (● ◐ ○ ?) · (3) mobile toggle `allergen.toggle` · (4) groups `allergen.group.mandatory` / `.recommended` / `.other`, rows Allergen · Status (icon + text) · (5) `allergen.disclaimer.voluntary` + `allergen.tell_staff` always; `allergen.cross_contact` only when recorded · (6) `allergen.last_verified` | rows 44 px, hairline dividers, group header rows tinted, `not_verified` rows warning tint; contains label bold | `<caption>`; `<th scope="rowgroup">`; icons `aria-hidden`; columns mirror, icons never mirrored; never "allergen-free"/"safe" |

### 7.6 Reservation (behaviour in `reservation.md`)

| Component | Visual spec |
|---|---|
| CMP-ReservationForm `.momo-resform` | Card radius 24 surface; numbered groups 1 Branch · 2 Date (Today/Tomorrow choice chips + date select) · 3 Time (slot ChipGroup by period; "JST" non-ja) · 4 People (chips 1..max or Stepper) · 5 Details · summary · notice at collection · consent · primary `res.submit` · `res.call_alt`. Mobile 1 column; ≥ 768 name/email/phone 2 columns; ≥ 1024 form + sticky summary column. States: slot skeleton, not-accepting notice, busy, error summary |
| CMP-ReservationSummary `.momo-ressum` | Raised panel radius 18, `<dl>`: Branch · Date (weekday) · Time (+JST non-ja) · People · Name (`dir="auto"`) · **Email 18 px LTR** (C09) / masked (C10/C11) + ReferenceCode + StatusBadge; title `res.summary.title`; not live-announced |
| CMP-ReferenceCode `.momo-ref` | Label "Your reference" + `ABCD-EFGH` 24 px/700, `tabular-nums`, `.08em` tracking, `<bdi dir="ltr">`, copy icon button; `aria-label` spells characters ("Reference A B C D, E F G H") |
| CMP-StatusBadge `.momo-status --{pending|confirmed|rejected|cancelled|expired}` | Pill (inline) or block (C10/C11 headline: icon 32 px + label as H1 + one sentence). Tokens §3.1; icons: pending hourglass, confirmed check-circle, rejected x-circle, cancelled slash-circle, expired clock-x, completed user-check, no_show user-x. **pending never uses check icon or green**. Customer labels `status.*`; completed/no_show → confirmed badge + `status.past` |

### 7.7 Feedback and overlays

| Component | Spec |
|---|---|
| CMP-StatusMessage `.momo-toast` / `.momo-inline-status` | Toast: dark pill (`--momo-color-toast-bg`, white, check icon), bottom-centre above the action bar (`inset-block-end: calc(var(--momo-bar-h) + 16px)` on mobile), 4 s, pauses on hover/focus, `role="status"`, never steals focus, never the only place of an error. Inline: below control |
| CMP-Dialog `.momo-dialog` | Native `<dialog>`; scrim `--momo-color-overlay`; panel radius 28 (bottom sheet < 768 with top corners 28; centred ≥ 768); heading · body · footer (one primary) · close; `aria-labelledby`; focus to heading (`tabindex="-1"`); Esc; focus return. Used for mobile menu and admin action sheets only (dish view is a page) |
| CMP-EmptyState `.momo-empty` | Centred bold line + muted text + one action; sections Doc 07 says to omit are omitted, not emptied |
| CMP-Skeleton `.momo-skel` | Blocks in `--momo-color-tint` sized to final content (no CLS); no shimmer; container `aria-busy="true"` |
| Notice/message colour set (Doc 08 §2.2.6) | info (brand-tint/emerald/emerald bar) · warning (warning-tint/warning-text/gold-text bar) · error (error-tint/error-strong/error bar, `role="alert"` for submit summary) · success (brand-tint/emerald, `role="status"`) |

### 7.8 Admin components (momos-core screens; WordPress admin patterns)
Admin primary buttons stay WordPress blue `#2271B1` (5.17:1); brand colours only in status badges, flags, plate
thumbnails (48 px circles) and the login screen; radius `--momo-radius-admin` 8 px; admin CSS `momos-core/assets/css/admin.css`
imports the status tokens (§3.1) [Doc 08 §3.8].

| Component | Spec |
|---|---|
| CMP-StaffInboxRow | Line 1 date+time 18/700 + StatusBadge (staff label); line 2 party · guest given name `dir="auto"` · branch code; line 3 FlagChips · "Claimed by {name}"/"Unclaimed" · age. ≤ 1023 card `<li>`; ≥ 1024 table (Date/time · Party · Guest · Branch · Status · Flags · Handled by · Age). New = bold + "New" tag; overdue flag; heading link to A09; new-items bar `role="status"` |
| CMP-FlagChip | `sla_overdue` warning tint + clock-alert "Overdue {duration}" · `needs_action` warning tint + flag "Needs action: {reason}" · `customer_not_notified` error tint + mail-x "Guest not notified — call" · `claimed` surface + user "Claimed by {name}" |
| CMP-AdminActionBar | Mobile sticky bottom bar on A09: [Accept] primary · [Decline] secondary · [More ▾]; desktop: action group in header; each action opens a Dialog sheet stating the consequence, reason radios, optional message + counter, one confirm button, busy state |
| CMP-Timeline | `<ol>`: time (JST, admin locale) · event · actor · notification chips queued/sent/failed (failed = error icon + text) |
| CMP-LockedField | Read-only value + lock icon + text "Head Office only" / "Dietary Claims Approver only"; not a disabled input (copyable) |
| CMP-BranchMenuRow | 48 px thumb · dish name (+ Japanese) · offered checkbox · reference price (read-only) · override `¥` input (LockedField for BM) · availability segmented control (Available/Sold out/Unavailable) · sold out until · reason · row status "Saved"/error + Retry; mobile card with "Sold out today" ≥ 44 px |

### 7.9 Email components (PHP templates in momos-core; Notification spec owns content)

| Component | Spec |
|---|---|
| CMP-EmailLayout | 600 px single column, table layout, inline CSS; emerald header strip with wordmark PNG 240×72 @2x (alt text); status headline block in §3.1 status colours (text first); reference block; details table; status block; branch block (localised + Japanese address, phone, map link); help/whitelisting line; footer (operator ⟦TBD⟧, privacy link). `lang` + `dir` on root and cells; numbers/addresses isolated; plain-text part. System stacks: ja "Hiragino Sans","Yu Gothic","Meiryo",sans-serif · ar/ur Tahoma,"Segoe UI",sans-serif · hi/bn "Nirmala UI",sans-serif · en Arial,sans-serif; body 16 px; line-heights per §3.1 |
| CMP-EmailButton | Bulletproof table button ≥ 44 px, fill `#C64F0E`, white bold label (4.64:1), radius 999 px (square in Outlook OK); one primary button per email |

---

## 8. Customer page frame (all SCR-C; Doc 08 §4.0)

### 8.1 Region order and skeleton

```html
<!doctype html>
<html lang="{lang}" dir="{ltr|rtl}">              <!-- FR-I18N-001 -->
<body class="momo-page momo-page--{type}">         <!-- type: home|branch|branches|menu|dish|reservation|… -->
  {skip-link}                                       <!-- first focusable -->
  {lang-suggestion}                                 <!-- JS-inserted when applicable; above header -->
  {notice-banner scope=site}                        <!-- when an active, translated site-wide notice exists -->
  <header class="momo-header">…</header>            <!-- sticky, z 50 -->
  <main id="main" tabindex="-1">
    {page-banner: breadcrumbs · eyebrow? · H1 · lead? on canvas with --momo-banner-glow (inner pages only)}
    …template content…
  </main>
  <footer class="momo-footer momo-on-dark">…</footer>
  {mobile-action-bar}                               <!-- ≤ 767 px, page-type aware -->
  <dialog class="momo-mmenu">…</dialog>
</body>
```
Background canvas; alternating sections may use `surface-raised`. Exactly one H1 per page; landmarks header/nav/main/footer.
Page banner H1: 32 px mobile, 36–40 tablet, 44 desktop; centred on home hero, start-aligned elsewhere.

### 8.2 Navigation model (code-defined) [Proposed implementation of Doc 06 §3]

| Item | Key | URL (`momos_url`) | Header desktop | Mobile menu order | Footer "Explore" | `aria-current` when |
|---|---|---|---|---|---|---|
| Menu | `nav.menu` | `/menu/` | 1 | 1 | ✓ | menu hub, category, dish |
| Branches ▾ | `nav.branches` | `/branches/` | 2 (disclosure: each published branch "name + area" + "All branches") | 2 (expandable) | — (Branches column) | directory, branch detail |
| Offers | `nav.offers` | `/offers/` | 3 **only while** `OfferQuery::hasActive(lang)` | 4 (same condition) | ✓ always | offers |
| Halal | `nav.halal` (menu row: `cta.halal_info`) | `/halal/` | 4 | 3 | ✓ (`cta.halal_info`) | halal |
| About | `nav.about` | `/about/` | 5 | 5 | ✓ | about |
| Reservation | `nav.reservation` | `/reservation/` | 6 | — (CTA button instead) | — | C09–C11 |
| FAQ / Contact | `nav.faq` / `nav.contact` | `/faq/`, `/contact/` | — | 6 / 7 | ✓ | — |
| Gallery (C) / News (C) | `nav.gallery` / `nav.news` | `/gallery/`, `/news/` | — | 8 / 9 (only if page published + visible in lang) | ✓ (same) | — |

Branch dropdown items come from the directory query (published, non-test, not `closed_permanently`, visible in the
language). Any change of Offers visibility purges `momo-global` (Branch spec). Every link stays in the current language.

### 8.3 Header (CMP-Header) by breakpoint

| Breakpoint | Start → end (mirrors in RTL via DOM order + flex; never `row-reverse`) |
|---|---|
| ≥ 1024 (78 px) | Logo (40 px) · centred white nav pill (`.momo-navpill`, radius pill, items label token, padding 8/16, current = action fill) · LangSwitcher · primary button `cta.request_table` → `/reservation/` |
| 768–1023 (78 px) | Logo · LangSwitcher · primary button · menu button (icon button 44×44, `aria-expanded`, `aria-controls`, name `a11y.menu.open`) |
| ≤ 767 (60 px) | Logo compact (32 px) · LangSwitcher (globe + current native name) · menu button 44×44 |

Header surface: opaque `--momo-color-surface-raised`; `.is-scrolled` adds `--momo-shadow-header`. Dropdown panels:
surface, radius card, `shadow-soft`, aligned `inset-inline-end`. Disclosures close on Esc (focus → trigger), outside
click, and focus leaving. Sticky header never covers focused content (scroll-padding). The notice banner and suggestion
bar scroll away (not sticky) [Proposed].

### 8.4 Language switcher behaviour (FR-I18N-002)

| Case | Link target |
|---|---|
| Current object visible in target lang (`Translations::post_visible`) | Same object URL in target language (`Url::localize`), keeping meaningful query (`?branch=`) |
| Not visible in target lang | Target-language home + `?momo_notice=missing_translation` (noindex) → dismissible info notice `lang.not_available`, rendered in the target language with {language} = the target language's native name |
| Status page / reservation form | Fragment `#t=` re-attached from sessionStorage / form values restored — owned by `reservation.md` |
| Disabled language (LanguageGate) | Not rendered anywhere (switcher, footer, hreflang) |

Option markup: `<a href lang="ar" hreflang="ar" dir="rtl">العربية</a>`; current: `aria-current="true"` + check icon.

### 8.5 Mobile menu (CMP-MobileMenu)
`<dialog class="momo-mmenu" aria-labelledby="mmenu-title">` full height, width `min(100%, 420px)` [Proposed], anchored
`inset-inline-end:0`, slides in from inline-end (300 ms; instant under reduce); scrim closes. Content order (Doc 06 §3.2):
header row (logo, close button `a11y.close`) · nav rows (§8.2 mobile order; rows ≥ 52 px; current row action fill;
Branches expands with chevron) · primary button `cta.request_table` (full width) · language grid (2 columns, 48 px
buttons, native names) · branch phone links (`tel:`, LTR-isolated, `cta.call` + branch name). Links close the dialog on
activation; Esc and close button return focus to the menu button (AC-CORP-08-02).

### 8.6 Mobile action bar (CMP-MobileActionBar; FR-CORP-008(c)(d))

| Page type | Items (start → end) |
|---|---|
| Branch detail, branch accepting online requests | [Request a table — emphasised orange pill + icon, `/reservation/?branch={code}`] · [Call `tel:{E.164}` — omitted if no phone] · [Directions — Google Maps dir URL, new tab] |
| Branch detail, `reservations_enabled = false` or temporarily closed | [Call] · [Directions] |
| C09 reservation, C10 submitted, C11 status | bar not rendered |
| All other pages (home, menu, dish, halal, offers, about, FAQ, gallery, news, legal, 404) | [Request a table] · [Branches → `/branches/`] |

Visuals: white translucent-look but **opaque** surface (no backdrop-filter), top border `--momo-color-border`, height
64 px + `env(safe-area-inset-bottom)`, items ≥ 48×48, icon 24 + label 12–14 px always visible, pressed state darkens;
fixed `inset-block-end:0`, z 60. Hidden via JS while a text-entry control has focus **or**
`visualViewport.height < 0.75 × innerHeight` (keyboard open) [Proposed threshold]. `main` gets
`padding-block-end: calc(var(--momo-bar-h) + env(safe-area-inset-bottom))` on mobile; the footer adds the same. Never
covers the error summary, toasts or the suggestion bar. Analytics attributes `data-momo-event="click_reservation|click_phone|click_directions"` +
`data-cta-location="sticky_bar"` (Analytics spec consumes).

### 8.7 Footer (CMP-Footer; FR-CORP-009)
Emerald band (`--momo-color-brand`, white text, mint secondary, links white underlined, amber focus). Columns:
1 **Branches** (heading `nav.branches`): each published non-test, non-permanently-closed branch: name link + phone
`tel:` in `<bdi dir="ltr">` · 2 **Explore**: Menu · Offers (always) · Halal information · About · FAQ · Contact ·
Gallery (C) · News (C) · 3 **Legal & privacy**: `ftr.privacy` · `ftr.terms` · `ftr.res_policy` · `ftr.ext_trans` ·
`ftr.accessibility` · `ftr.analytics` · 4 **Social & languages**: social links from `momo_social_links` (new tab,
`rel="noopener"`, text + `a11y.new_tab`) + plain list of enabled languages (native names, `lang`/`hreflang`). Logo
on-dark at top. Bottom line "© {current year} {momo_legal_operator_name}" — **line hidden while the operator name is
unset** ⟦TBD — Owner — FR-FND-005⟧. Mobile: stacked sections (no accordions); tablet 2×2; desktop 4 columns. No
keyword link lists, no admin link.

### 8.8 Breadcrumb trails (Doc 06 §3.7)
Home › Branches › {Branch} · Home › Menu › {Category} › {Dish} · Home › Halal information · Home › Offers ·
Home › {Page} · Home › News › {Title} · Home › {Policy}. Not on Home, `/reservation/`, submitted, status. Visible trail
= BreadcrumbList JSON-LD.

### 8.9 Language suggestion bar (FR-I18N-003)
Client-side only (`frame.js`): read `navigator.languages`; pick the first primary subtag that is an enabled language ≠
page language **and** has a `<link rel="alternate" hreflang>` for this page; show only if cookie `momo_lang_choice` is
absent. Strings for every enabled language (`lang.suggest`, `lang.switch`, `a11y.close`) are embedded as JSON in the
page [Proposed]. "Switch" → set cookie to the chosen code, navigate to the hreflang URL; × → set cookie `dismissed`.
Cookie: 1 year, `Path=/`, `SameSite=Lax`, `Secure`; functional (listed on SCR-C17). Never redirects (crawlers and
no-JS see nothing).

### 8.10 Frame states

| State | Behaviour |
|---|---|
| Loading | Server-rendered; dynamic parts (open-now, slots) show skeletons of final size; no full-page spinner |
| Empty | Nav/footer items for unavailable sections (Gallery/News/Offers header item) not rendered |
| Error | A failing component renders nothing (+ HTML comment with request_id for logged-in SA only); static content remains |
| No JS | Menu button falls back to a link to an anchored full nav list in the footer [Proposed]; disclosures render open-able via `<details>` fallback; action bar links work; suggestion bar absent |

### 8.11 SCR-C19 404 (template `404.php`)
Page banner H1 `404.title` · one sentence `404.body` · four CMP-Card `--link` cards with icons: Home, Branches, Menu,
Request a table (same language). 1 column → 4 in a row ≥ 1024. Localised by URL prefix. URL logged (SEO spec).
AC: localised in each enabled language; links resolve within the language.

### 8.12 SCR-C20 Maintenance (`wp-content/maintenance.php`)
HTTP 503 + `Retry-After: 600`. Plain HTML + inline CSS using literal token values, **system fonts only**, no
WordPress/plugin dependency. Wordmark (inline SVG) · message repeated for each enabled language, each block with own
`lang`/`dir` · Head Office phone ⟦TBD — Head Office — FR-FND-005⟧ as `tel:` link. Strings exported at each release
from catalogs (`maintenance.title`, `maintenance.body` [Proposed keys]). AC: renders with WP down; ar/ur blocks `dir="rtl"`.

### 8.13 Admin frame (SCR-A, Doc 08 §4.18)
WordPress admin, colour scheme "Default", WP list tables/notices/metaboxes inside `.wrap`. Login screen (A01): wordmark,
canvas background, primary button WP blue. Admin language per user ja/en (DEC-037). Custom momos-core screens (A04, A07,
A08, A09, A11 gaps, A12, A16, A17, A19) usable at 360 px (cards + sticky action bar). Notices `notice-success` /
`notice-error` announced via `role="status"` / `role="alert"`; inline row saves use the toast. Denied access → WP error
page + audit `access.denied`. Vocabulary: "View site", "Dishes" (no storefront/tray — UI-CHG-062).

---

## 9. RTL, bidi and localisation rules (Doc 08 §6)

| Rule | Spec |
|---|---|
| Direction | `dir="rtl"` for ar, ur; `ltr` otherwise. Admin always LTR |
| Mirrored | Directional icons, breadcrumb separators, horizontal scroll start, panel slide side, sticky side column (C05/C08/C09 moves to left) |
| Not mirrored | Logo, phone/clock/map-pin/check icons, media controls, numbers, allergen shapes, plate crop |
| Accent borders | `border-inline-start` |
| Isolation (FR-I18N-005) | Phone `<a href="tel:+81…"><bdi dir="ltr">06-xxxx-xxxx</bdi></a>` · price `<bdi dir="ltr">¥1,500</bdi> <span>{tax label}</span>` · signed `<bdi dir="ltr">+¥300</bdi>` · time range `<bdi dir="ltr">12:00–15:00</bdi>` joined with " · " · dates with digits in `<bdi>` · reference `<bdi dir="ltr" class="momo-ref">` · email/URL `<bdi dir="ltr">`, inputs `dir="ltr"` · Latin names `<bdi>MOMO's</bdi>` · Japanese address `<span lang="ja" dir="ltr">` · user text `dir="auto"` |
| Helper | Extend `momos_bdi(string $text, ?string $dir = 'ltr')` to emit `<bdi dir="ltr">` (current helper emits `<bdi>` without `dir`) — TK-03-05; placeholders containing numbers are wrapped by code, never by translators |
| No mixed language | An item/label renders in a language only if its text exists there; proper nouns may stay in source script with `lang` |
| Expansion | Design for +40 % vs English; buttons may wrap to 2 lines on mobile; nav never truncates; only descriptions clamp (2 lines; off for ur titles) |
| Uppercase/tracking | Latin only (tokens handle it per `:lang`) |
| Numerals | Western digits on output in all languages (bn override `-u-nu-latn`); 24 h |
| Formats (Doc 07 §6.10, DEC-062) | ja long 2026年12月3日（木） short 12/3（木）; en Thu 3 Dec 2026 / Thu 3 Dec; ar/ur/hi/bn CLDR `EEEEdMMMMy` / `EEEdMMM`; price ja ¥1,500（税込）, en ¥1,500 (tax incl.); "JST" after times on reservation surfaces in non-ja only; produced by `Presentation\Format\{DateFormatter,PriceFormatter}` — never typed |
| Input digits | ٠–٩, ۰–۹, ०–९, ০–৯, ０–９ normalised (Kernel `Digits`); field shows normalised value on blur |

RTL design QA checklist (Doc 08 §6.8): header mirrored/logo not; numbers in order everywhere; Nastaliq not clipped in
chips/buttons/nav/table rows at 16–18 px; no fallback text in ar/ur/hi/bn; switcher native names + `lang`/`hreflang`;
Western digits; +40 % strings at 360 px; only directional icons mirrored; emails `dir`/`lang`; ja strict line breaking.

---

## 10. Imagery (Doc 08 §1.5, §2.10; TK-03-11)

| Rule | Spec |
|---|---|
| Sizes [Doc TK-03-11] | `add_image_size`: `momo-hero` 1600w, `momo-card` 600w, `momo-thumb` 300w; WebP (AVIF if host supports); `srcset`/`sizes`; explicit width/height |
| Budgets | hero ≤ 200 KB; cards ≤ 80 KB; initial load ≤ 1.5 MB |
| Loading | `fetchpriority="high"` on the hero image only; all others `loading="lazy" decoding="async"` |
| Ratios | dish plate 1:1 circle (subject ≥ 70 % of square); branch/offer 4:3; branch hero 16:9; gallery 1:1 mobile |
| Plate crop | circle, 5 px white ring, `--momo-shadow-plate` |
| Demo images | only on dish and offer images, with CMP-IllustrationLabel; never branch, gallery, halal, About; no generated people; Stitch AI renders only if separately approved (DEC-047) |
| Alt text | per language from media data; decorative `alt=""` |

---

## 11. Data model touchpoints (fields this spec reads; owners elsewhere)

| Key | Where | Type | Req | Translatable | Use here | Owner |
|---|---|---|---|---|---|---|
| `momo_trading_name` | option (brand, SCR-A15) | text ≤ 80 | Y | Y (`_momos_i18n_{lang}` on options [Proposed]) | logo alt text fallback, email header | HO |
| `momo_tagline` | option | text ≤ 120 | — | Y | hero brand line | HO |
| `momo_legal_operator_name` | option | text ≤ 120 | Y before launch | Y | footer © line (hidden while empty) | HO |
| `momo_logo_primary` / `momo_logo_inverse` / `momo_logo_mark` | option (attachment ID) | image SVG(sanitised)/PNG ≥ 512 | — until DEC-046 | N | replaces provisional SVG wordmark (light / on-dark / favicon) | HO |
| `momo_social_links` | option | repeater {network ∈ instagram, facebook, x, youtube, tiktok, line, other; url https} | — | N | footer | HO |
| `momo_default_phone` | option | E.164 | — | N | maintenance page / 404 help ⟦TBD⟧ | HO |
| `momo_image_illustrative` | attachment meta | bool, default 0 | — | N | CMP-IllustrationLabel; accepted only on dish/offer images | MM/CE (Media spec) |
| Enabled languages | `Lang::enabled()` (LanguageGate) | set of codes | — | — | switcher, footer list, suggestion bar | SA/HO (`momo_manage_languages`) |
| Cookie `momo_lang_choice` | browser | `{lang}` or `dismissed`, 1 y | — | — | suggestion bar | — |
| Query `momo_notice=missing_translation` | URL | flag | — | — | `lang.not_available` notice; page `noindex` | — |

No custom tables. No new post types. No REST endpoints are owned by this area.

---

## 12. Permissions (design-system surfaces)

| Role | Rights relevant here |
|---|---|
| ROLE-00 Public | View frame/components; set language cookie |
| ROLE-01 Super Admin | Theme/CSS/fonts/icons changes via Git deploy only (DEC-065); enable languages |
| ROLE-02 Head Office | Brand settings (logo, trading name, tagline, operator name, social links) on SCR-A15; enable/disable languages (`momo_manage_languages`, LanguageGate) |
| ROLE-03 Menu Manager | Supplies dietary tag list (ENT-11) that DietaryIcon renders; may flag dish images illustrative |
| ROLE-04 Content Editor | Notices (banner content), media/alt text, offer images (illustrative flag) — no access to tokens/theme |
| ROLE-05 Translator | Translates catalog strings/content per assigned language; no theme access |
| ROLE-06 Branch Manager / ROLE-07 Reservation Staff | None for design system; see admin components via their screens (LockedField shows what they cannot edit) |
| CAP-DCA | Selects brand halal statement (`momo_brand_halal_statement`) that CMP-HalalStatement shows in brand context |

No theme setting is editable from wp-admin except the brand options above [Proposed: Customizer colour/font panels removed].

---

## 13. Strings (Doc 07 §6 keys; EN source / JA draft — native review required)

| Key | EN | JA |
|---|---|---|
| `skip.main` | Skip to main content | メインコンテンツへ移動 |
| `nav.menu` / `nav.branches` / `nav.halal` / `nav.about` / `nav.reservation` | Menu / Branches / Halal / About / Reservation | メニュー／店舗一覧／ハラール／私たちについて／予約リクエスト |
| `nav.offers` / `nav.faq` / `nav.contact` / `nav.gallery` / `nav.news` | Offers / FAQ / Contact / Gallery / News | お得な情報／よくある質問／お問い合わせ／ギャラリー／ニュース |
| `bc.home` | Home | ホーム |
| `cta.request_table` | Request a table | 予約リクエスト |
| `cta.request_table_branch` | Request a table at this branch | この店舗に予約リクエスト |
| `cta.find_branch` / `cta.see_branches` | Find a branch / See branches | 店舗を探す／店舗一覧を見る |
| `cta.call` / `cta.directions` / `cta.details` | Call / Directions / Details | 電話する／道順を見る／詳細 |
| `cta.open_maps` / `cta.view_menu` / `cta.halal_info` | Open in Google Maps / View menu / Halal information | Googleマップで開く／メニューを見る／ハラール情報 |
| `lang.suggest` | This page is available in {language}. (written in the suggested language) | このページは日本語でもご覧いただけます。 |
| `lang.not_available` | This page is not yet available in {language}. | このページは{language}ではまだご覧いただけません。 |
| `lang.switch` | Switch | 切り替える |
| `ftr.privacy` / `ftr.terms` / `ftr.res_policy` | Privacy Policy / Terms of Use / Reservation Policy | プライバシーポリシー／利用規約／予約ポリシー |
| `ftr.ext_trans` / `ftr.accessibility` / `ftr.analytics` | External Transmission & Cookies / Accessibility / Analytics settings | 外部送信・Cookieについて／アクセシビリティ／アクセス解析の設定 |
| `media.illustration` | Image for illustration | 写真はイメージです ⟦counsel — DEC-047⟧ |
| `price.tax_incl` | (tax incl.) | （税込） |
| `branch.open_now` | Open now · until {time} (last order {lo}) | 営業中・{time}まで（ラストオーダー{lo}） |
| `branch.closed_now` | Closed now · opens {day} {time} | 営業時間外・{day} {time}から営業 |
| `branch.temp_closed` | Temporarily closed — reopens {date} | 臨時休業中（{date}より営業再開） |
| `branch.copy_address` / `.copied` | Copy Japanese address / Address copied | 住所をコピー／住所をコピーしました |
| `menu.branch_select.all` | All branches — reference prices | 全店舗（参考価格） |
| `menu.sold_out` / `menu.unavailable` / `menu.not_available` | Sold out today / Not available at this branch now / Not currently available | 本日売り切れ／この店舗では現在ご提供していません／現在ご提供していません |
| `menu.advance_order` | Order at least {hours} h ahead | {hours}時間前までに要予約注文 |
| `menu.filters.empty` / `.clear` | No dishes match these filters. / Clear filters | 条件に合う料理がありません。／条件をクリア |
| `offers.see_all` / `offers.see_current` / `offers.valid` | See all offers / See current offers / Valid {start} – {end} | すべての特典を見る／現在の特典を見る／期間：{start}～{end} |
| `offers.conditions` / `.show_all` / `offers.scope.all` | Conditions / Show all conditions / All branches | 条件／条件をすべて表示／全店舗 |
| `res.submit` / `res.submit.busy` | Send request / Sending… | リクエストを送信／送信中… |
| `res.err.summary` | Please correct {n} item(s) below. | 以下の{n}件の項目をご確認ください。 |
| `status.pending` / `status.confirmed` / `status.rejected` | Request received — not yet confirmed / Confirmed by the branch / Declined | リクエスト受付・未確定／店舗にて確定済み／お受けできませんでした |
| `status.cancelled` / `status.expired` / `status.past` | Cancelled / Expired — not confirmed in time / The date of this reservation has passed. | キャンセル済み／期限切れ（時間内に確定できませんでした）／このご予約の日時は過ぎています。 |
| `allergen.state.contains` / `.may_contain` / `.not_contained` / `.not_verified` | Contains / May contain (cross-contact) / Not contained (checked) / Not verified | 含む／含む可能性あり（混入の可能性）／含まない（確認済み）／未確認 |
| `allergen.banner.not_verified` | Allergen information not verified — please ask staff. | アレルゲン情報は未確認です。スタッフへお尋ねください。 |
| `404.title` | Page not found | ページが見つかりません |

**[Proposed] keys (no Doc 07 key exists; EN from Doc 08 component text; JA = draft for native review):**

| Key | EN | JA (draft) |
|---|---|---|
| `a11y.logo_home` | MOMO's Cafe & Halal Restaurant — Home | MOMO's Cafe & Halal Restaurant ホーム |
| `a11y.nav.main` / `a11y.nav.quick` | Main / Quick actions | メイン／クイック操作 |
| `a11y.breadcrumb` / `a11y.notice` | Breadcrumb / Notice | パンくずリスト／お知らせ |
| `a11y.menu.open` / `a11y.menu.title` / `a11y.close` | Open menu / Menu / Close | メニューを開く／メニュー／閉じる |
| `a11y.lang.label` | Language | 言語 |
| `a11y.new_tab` | (opens in a new tab) | （新しいタブで開きます） |
| `a11y.stepper.dec` / `.inc` | Decrease number of people / Increase number of people | 人数を減らす／人数を増やす |
| `a11y.copy` / `a11y.copied` | Copy / Copied | コピー／コピーしました |
| `form.required` / `form.optional` | (required) / (optional) | （必須）／（任意） |
| `menu.branch_select.label` | Show prices for | 価格を表示する店舗 |
| `menu.filters.toggle` | Filters ({n}) | 絞り込み（{n}） |
| `allergen.toggle` | Show only contains / may contain | 「含む」「含む可能性あり」のみ表示 |
| `404.body` | The page you were looking for could not be found. | お探しのページは見つかりませんでした。 |
| `nav.all_branches` | All branches | すべての店舗 |
| `maintenance.title` / `maintenance.body` | Under maintenance / We are updating the website. Please try again shortly. | メンテナンス中／ウェブサイトを更新しています。しばらくしてから再度お試しください。 |

All keys must exist in the six catalogs (ar/ur/hi/bn = drafts flagged "DRAFT – needs native review", DEC-027); ar/ur/hi/bn
values for `nav.*`, `cta.*`, `ftr.*`, `media.illustration`, `lang.switch` are the Doc 06 §3.8 drafts.
Prohibited anywhere in frame strings: order now, add to cart, checkout, takeout/delivery (ordering), coupon code, booked,
reservation confirmed, 予約完了, 予約確定, "100% halal", "halal certified" (outside `certified.*` templates), seals.

---

## 14. Apply list — Doc 08A UI audit changes owned by the design system

| UI-CHG | Change to apply | Where |
|---|---|---|
| 001/002/004/005 | No cart, FAB, drawer, checkout, order bar, dine-in/takeout/delivery, "Order Now"; CTAs "Request a table" + "Find a branch" | §7, §8 |
| 003/041/048/050/065 | Dish card = one link to dish page; list variant on mobile; stretched hit area; plate overlap cleared (row gap 64 px) | §7.3 |
| 007 | Provisional wordmark; halal seal never logo | CMP-Logo |
| 008/009/010/011 | No halal slogans/"Verified" chips/per-dish badges/JHF banner; HalalStatement instead | §7.5 |
| 012 | SAMPLE marker on comp prices; sample data never public | CMP-PriceBlock |
| 014/054 | Demo photos only on dish/offer with caption below; no generated people; no Stitch renders unless approved | §10 |
| 015/045 | Request wording; action bar items per page type | §8.6, §13 |
| 021/075 | No literal hours/phone/address in templates; utility bar only for notices | §2 CI scan, CMP-NoticeBanner |
| 024/066/032 | Six native-name switcher with `lang`/`hreflang`/`aria-current`; mobile 2×3 grid; no "Admin demo" link | §8.4–8.5 |
| 025/027/052 | Per-script fonts, sizes, line-heights; +40 % expansion; Latin-only tracking; self-hosted subsets | §3–§4 |
| 026/028/029/030 | No fallback language; Western digits; bidi isolation; logical properties (lint) | §9, §3.2 |
| 033 | Contrast tokens: accent-text `#A03B00`, gold-text `#97651F`, border-field `#8C7167`, focus 2 px emerald / amber + 2 px offset | §3 |
| 034/035/036 | Overlays `<dialog>`/inert; skip link; bound labels + error summary | §7.1–7.2 |
| 037/055 | Inline SVG sprite; no icon font; no backdrop-filter / infinite animations | §5–§6 |
| 038/070 | No marquee, autoplay, rotating seal, floating chips, counters | §5.3 |
| 039 | Targets ≥ 24 (≥ 44 primary, ≥ 48 bar) | §3.1 |
| 042/043 | Alt per language; one H1, no skipped levels | §8.1, §10 |
| 044/076/077/080 | Availability text labels; allergen table; price block; advance-order label from data | §7.4–7.5 |
| 046/047/049 | Breakpoints 768/1024 + container 1200; scroll-padding; hero ≤ 240 px mobile | §5 |
| 051/053 | No Tailwind CDN; tokens in CSS; WebP + srcset + budgets | §3, §10 |
| 064 | Static active nav pill | §8.3 |
| 068 | Action bar + open-now styled per components | §7.4, §8.6 |
| 071 | © year by template; operator ⟦TBD⟧ | §8.7 |
| 078 | No free-text menu search | — (not built) |
| 081 | Light admin branding (login logo, badges, 8 px radius) | §7.8, §8.13 |

---

## 15. Acceptance criteria and tests

### 15.1 Acceptance criteria (design-system level)
1. Changing one token in `tokens.css` changes every template; no hex values outside `tokens.css` (stylelint) — TK-03-03.
2. `<html lang dir>` correct for all six languages on every template; ar/ur `rtl` (TC-I18N-001).
3. 0 stylelint violations of the physical-property ban; direction correct after repeated ar→en→ar→ur switching with page cache on (TC-RTL-006).
4. Each language page requests only its font files (en 4: Epilogue 700/800 + PJS 400/700; ar/ur/hi/bn: script 400/700 + PJS 400/700; ja: Noto Sans JP only), all from own domain, ≤ 3 families (AC-I18N-01-01, TC-I18N-004 as corrected in §16).
5. Urdu at 16 px+ and 200 % zoom: no clipped ascenders/descenders in chips, buttons, nav, table rows (TC-RTL-005, AC-I18N-01-06).
6. Phone, price, time, reference and Latin names keep LTR order inside ar/ur text (TC-RTL-002).
7. Header shows Menu, Branches, Halal, About, Reservation + switcher + CTA; "Offers" appears 6th-after-Branches only with an active offer translated in that language; footer always links Offers (TC-NAV-001, TC-NAV-006, AC-CORP-08-06).
8. 390 px: branch page bar = Request/Call/Directions (Call/Directions when not accepting); elsewhere Request/Branches; hidden on C09–C11, ≥ 768 px and with keyboard open; never hides the focused field (TC-NAV-002, AC-CORP-08-01/04).
9. Mobile menu: Esc and close return focus to the menu button; focus contained; links close it (AC-CORP-08-02).
10. Switcher: native names with `lang`/`hreflang`, current `aria-current`; untranslated page → target home + `lang.not_available`; disabled language absent (TC-I18N-002, TC-I18N-009).
11. Suggestion bar: HTTP 200 same URL, never 3xx; dismissal remembered 1 year (TC-I18N-003).
12. axe (wcag2a/aa, 21a/aa, 22aa): 0 critical/serious on all customer templates in 6 languages (TC-A11Y-001); contrast ≥ 4.5:1 text / 3:1 UI; 320 px reflow, 200 % text, 400 % zoom (TC-A11Y-005).
13. Focus visible on every interactive element; sticky header/bar never obscure focus (TC-A11Y-004 2.4.11); targets ≥ 24 px (2.5.8).
14. With `prefers-reduced-motion: reduce` no transition/animation runs; no autoplay/marquee anywhere (TC-A11Y-009).
15. No horizontal overflow at 360/390/768/1024/1366/1920 in ja and ar (TC-CMP-005); long-string fixtures fit at 360 px in 6 languages (TC-I18N-017).
16. Initial load ≤ 1.5 MB, hero ≤ 200 KB, cards ≤ 80 KB; LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≤ 200 ms (TC-PERF-001/003).
17. Template scan finds no prices, phones, time ranges or claim words in theme files (Doc 09 §2.9.4); prohibited-term scan clean (Doc 07 §6.6).
18. Side-by-side review vs UI-001 at 390/1366 in ja and ar approved, deviations documented (Doc 09 §2.10 rule 14; TC-REG-005).
19. Maintenance page renders without WordPress, 503 + `Retry-After: 600`, correct `dir` per block.
20. `momos-ui.css` ≤ 30 KB minified.

### 15.2 Test cases to automate (Playwright unless noted)
TC-I18N-001, 002, 003, 004, 005 (formats in components), 009, 013, 017 · TC-RTL-001 (screenshots ar/ur 360/768/1366),
002, 006 (+ stylelint in CI) · TC-NAV-001, 002, 003, 004, 006 · TC-A11Y-001 (axe), 004/005 (partially automated: target
size + contrast scripts), 007, 008 · TC-CMP-005 · TC-PERF-001, 003 (Lighthouse CI) · TC-REG-005 (visual baselines) ·
TC-HOME-001/005 (frame parts). Manual: TC-RTL-005, TC-A11Y-002/003/009, TC-CMP-001/004.
[Proposed] new unit/CI tests: TC-DS-01 token contrast matrix (§3.3) computed from `tokens.css`; TC-DS-02 CSS size budget;
TC-DS-03 every `momos_t` key used by theme exists in 6 catalogs; TC-DS-04 font requests per language (network log).

---

## 16. Out of scope, open items and doc conflicts

**Out of scope:** dark mode; carousels/sliders (none in Phase 1); Elementor Kit configuration; per-screen content
(module specs); email copy; Figma/Stitch asset production (H1–H12 handoff list: official logo ⟦DEC-046⟧, photography,
map facade images after GATE-01).

**Open TBDs (build the default):**
| Item | Default |
|---|---|
| Official logo/colours/fonts (DEC-046/067) | Provisional SVG wordmark + §3 tokens; design approval needs assets or Owner's written acceptance |
| Font budgets (§4.3) | Proposed KB values; measure in PROTO |
| Notice body length | 300 characters |
| Legal operator name | © line hidden until set |
| Hero headline/About copy | Brand setting `momo_tagline`; UI-001 headline only if approved |
| Dietary tag list + icons | Menu Manager supplies; icons from §6 set |
| ar/ur/hi/bn tax label, plurals, JST label, a11y strings | Draft catalogs, native review (GATE-04) |
| Concept label (DEC-001) | CMP-ConceptTag hidden unless set |
| Keyboard-open threshold | `visualViewport.height < 0.75 × innerHeight` |
| Head Office phone (maintenance) | ⟦TBD — Head Office⟧ |

**Doc conflicts found (resolution used here):**
| # | Conflict | Resolution |
|---|---|---|
| C1 | Mobile menu enters from inline-end (Doc 08 CMP-MobileMenu) vs TC-RTL-001 "drawer opens from inline-start" | inline-end (menu button sits at inline-end); TC-RTL-001 to be corrected |
| C2 | TC-I18N-004 expects Epilogue on every page vs Doc 08 §2.3.1 / AC-I18N-01-01 / US-I18N-002 (Epilogue never on non-Latin pages; ja = Noto Sans JP only) | Follow Doc 08 + PRD AC; TC-I18N-004 to be aligned (also Doc 16 R3-19) |
| C3 | Urdu line-height ≈ 2.2 (TK-03-04) vs 2.0 (Doc 08 §2.3.3, FR-I18N-004(c)) | 2.0 |
| C4 | ja eyebrow 13 px (Doc 08 §2.3.3) vs "non-Latin ≥ 14 px for any text" (§2.3.2) and CMP-SectionHeader 14 px | 14 px |
| C5 | `--momo-type-micro` weight 600 vs en budget "4 faces" (no PJS 600 file) | micro uses 700 (no extra file) |
| C6 | TRD U11 "carousels re-implemented with scroll-snap" vs Doc 08/07 "no carousel, static grids" | No carousels; scroll-snap only for mobile link-chip rows |
| C7 | Doc 09 §1.9 U1 ink/muted `#261F1A`/`#6E625A` vs Doc 08 `#221A17`/`#6E625D` | Doc 08 values (Doc 08 OI-5) |
| C8 | "≤ 6 font files per page" (DEC-061) vs ja unicode-range slicing (many small files) | Count a sliced family-weight as one face; ja byte budget governs ⟦confirm Tech lead⟧ |
| C9 | Doc 08 §7.2 `.momos-*` widget classes vs `.momo-*` components | Single `.momo-*` namespace (build adaptation, §0) |
| C10 | Kernel `momos_bdi()` emits `<bdi>` without `dir="ltr"` vs Doc 08 §6.2 / TK-03-05 | Extend helper to `<bdi dir="ltr">` (implementation fix, not a doc conflict) |
