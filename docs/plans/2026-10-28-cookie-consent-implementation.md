# Bananaland Consent Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the `bananaland-consent` plugin described in `2026-10-28-cookie-consent-design.md`, plus the starter-theme kit and CI's branded version.

**Architecture:** Dependency-free WordPress plugin (namespace `Bananaland\Consent`, own autoloader). PHP renders hidden banner markup and held scripts on every page; `assets/consent.js` resolves region and consent client-side so full-page caching is safe. Themes override templates and styles from `{theme}/consent/`.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, vanilla JS (ES2018, no build), PHPUnit 11 + Brain Monkey (unit), Playwright (E2E against `ci2026.local`), GitHub Actions.

**Constraints:**
- Never edit the working copy of `ctinnovations2026`; Jordan is actively changing it. Theme-side work happens in a git worktree on branch `feature/consent-kit`, located at `ci2026/app/public/wp-content/blc-dev/ctinnovations2026` so it is web-reachable for previews. A dev-only mu-plugin (`wp-content/mu-plugins/blc-dev-kit.php`) points the plugin at that kit. Both are removed after merge.
- The plugin is installed via symlink in dev. The updater must never run against a symlinked or `.git` install (it would delete the repo).

**Conventions:**
- Prefix `blc_` (functions, options, hooks), CSS `--blc-*` / `.blc-*`, table `{prefix}blc_consent_log`.
- Settings in one option `blc_settings`. Effective config = defaults ← saved option ← `{theme}/consent/config.php` ← `blc_config` filter.
- WP-CLI for ci2026 needs Local's socket: `php -d mysqli.default_socket="$HOME/Library/Application Support/Local/run/DaY7YL4TV/mysql/mysqld.sock" /opt/homebrew/bin/wp --path=app/public ...`

---

## Phase 1: Core

### Task 1: Scaffold
Files: `bananaland-consent.php`, `src/Plugin.php`, `src/Autoloader.php`, `composer.json` (dev only), `phpunit.xml.dist`, `tests/bootstrap.php`, `.gitignore`, `.distignore`, `README.md`.
- Plugin header (Version 0.1.0, Requires PHP 8.1, Update URI `https://github.com/The-Bananaland/bananaland-consent`).
- Constants `BLC_VERSION`, `BLC_FILE`, `BLC_DIR`, `BLC_URL`.
- Test: `tests/Unit/SmokeTest.php` asserts autoloader resolves `Bananaland\Consent\Plugin`.
- Run `composer install`, `vendor/bin/phpunit` → PASS. Activate plugin on ci2026 → no errors. Commit.

### Task 2: Config
Files: `src/Config.php`, `tests/Unit/ConfigTest.php`.
- `Config::defaults()`: categories (necessary, preferences, analytics, marketing with labels and descriptions), texts per mode, banner layout/position, colors, policy_version `1`, expiry days `{opt-in: 182, opt-out: 365, notice: 365}`, consent_mode `basic`, regions overrides `{}`, unknown_mode `opt-in`, timezone_fallback `true`, log retention days `1095`, services `[]`, privacy page id `0`, floating button `false`.
- `Config::get(string $dotted)`, deep merge saved over defaults, theme `consent/config.php` (returns array) over that, then `apply_filters('blc_config', $config)`.
- Tests: deep merge keeps defaults for missing keys; theme config overrides saved; filter applied last; list values (services) replaced, not merged.

### Task 3: Region resolver and rules
Files: `src/Regions/Resolver.php`, `src/Regions/Rules.php`, `data/regions.json`, tests.
- Resolver reads, in order: test override (`X-BLC-Test-Region` header or `blc_test_region` query, only when `wp_get_environment_type() === 'local'` or `BLC_ALLOW_TEST_REGION`), WP Engine GeoTarget (`HTTP_GEOIP_COUNTRY_CODE`, `HTTP_GEOIP_REGION`), Cloudflare (`HTTP_CF_IPCOUNTRY`, `HTTP_CF_REGION_CODE`), filter `blc_resolve_region`. Returns `['country' => 'US', 'region' => 'CT', 'source' => 'wpe']` or country `null`. Cloudflare `XX`/`T1` → null.
- `regions.json`: `opt_in_countries` (EU27, IS, LI, NO, GB, CH, BR), `opt_in_subdivisions` (`CA-QC`), `opt_out_countries` (`US`), `us_privacy_states` (informational list for admin), default `notice`.
- `Rules::mode($country, $region)`: settings override map (`"DE" => "opt-in"`, `"US-CA" => ...`) wins; subdivision before country; null country → `unknown_mode`.
- Tests: DE → opt-in, GB → opt-in, CA-QC → opt-in, CA-ON → notice, US-CT → opt-out, JP → notice, null → opt-in, override `US` → opt-in, test header ignored when env is production.

### Task 4: Service presets and registry
Files: `src/Services/Presets.php`, `src/Services/Registry.php`, tests.
- Preset shape: `id, label, provider, category (default), fields (e.g. ['id' => 'Measurement ID', pattern]), cookies [name, duration, purpose], hosts, privacy_url, head(array $s): string, body(array $s): string`.
- Presets: `gtm`, `ga4`, `google_ads`, `meta_pixel`, `linkedin`, `hubspot`, `clarity`, `custom`.
- Registry builds service instances from config, validates IDs against patterns (invalid → skipped + admin notice), exposes `all()`, `by_category()`, `cookies_by_category()`.
- Tests: GA4 with `G-ABC123` renders gtag with that ID; invalid ID skipped; custom service keeps raw HTML; cookie lists grouped by category.

### Task 5: Script holding and tagging API
Files: `src/Services/Renderer.php`, `src/Services/ScriptTagger.php`, `src/functions.php`, tests.
- `Renderer::hold(string $html, string $category)`: rewrites every `<script>` to `type="text/plain" data-consent="{cat}"` (keeps original `type` in `data-blc-type` if not JS), and `<iframe src>` / `<img src>` to `data-blc-src`. `necessary` → untouched. GTM is printed live (Consent Mode gates it).
- Output: head services on `wp_head` priority 2 (after consent default at priority 1), body services on `wp_body_open` and `wp_footer`.
- `blc_require_consent($handle, $category)` registers handle; `script_loader_tag` filter rewrites that handle's tag(s), including `-js-before/-after` inline.
- `blc_has_consent($cat)` reads/validates the cookie server-side.
- Tests: hold() on inline + src scripts, module type preserved, necessary untouched, tagger rewrites only the registered handle.

### Task 6: Templates and view
Files: `src/Templates/Locator.php`, `src/Templates/View.php`, `templates/*.php`, tests.
- Locator search: `blc_template_dirs` filter (default: child theme `consent/`, parent theme `consent/`, plugin `templates/`). `render($name, $vars)` with output buffering. `overrides()` returns `[name => [path, theme_version, plugin_version, outdated]]` from `@version` headers.
- View (`$consent`): `text($mode, $key)`, `categories()`, `services($cat)`, `layout()`, `position()`, `policy_url()`, `cookies_url()`, `is_kit_styled()`.
- Templates: `banner.php` (per-mode blocks via `data-blc-show-for`), `preferences.php` (`<dialog>`), `category.php`, `service-row.php`, `floating-button.php`, `embed-placeholder.php`, `declaration.php`, `privacy-choices-icon.php`.
- Contract attributes: `data-blc="root|banner|preferences|toggle|consent-id|gpc-notice"`, `data-blc-action="accept-all|reject-all|save|open-preferences|close|opt-out"`, `data-category`, `data-blc-show-for="opt-in opt-out notice"`.
- Tests: theme file wins over plugin; missing theme file falls back; outdated detection.

### Task 7: Frontend output
Files: `src/Frontend/Output.php`.
- `wp_head` p1: inline script: `dataLayer`, `gtag` stub, consent default (from existing cookie if valid, else all denied except security; `wait_for_update: 500`), `ads_data_redaction`, `url_passthrough` settings; `window.blcConfig` JSON (categories, expiry, policy version, service ids per category, cookies per category for cleanup, REST URLs + nonce-free endpoints, texts needed in JS, timezone fallback flag, consent mode).
- Enqueue `assets/consent.js` (defer, in head so it runs before footer scripts execute? use `strategy: defer`) and `assets/consent.css` unless the theme kit provides `consent/consent.css` (then enqueue kit CSS and `consent/tokens.css`).
- `wp_footer` p5: render `banner`, `preferences`, `floating-button` (hidden by default with `hidden` attribute).
- Skip on admin, login, REST, feeds, `BLC_DISABLE`, and when `?blc_off` for admins.

### Task 8: consent.js
File: `assets/consent.js`. Responsibilities, in order:
1. Read `blc_consent` cookie; validate version and service list (re-prompt if policy version changed or an accepted category gained a service).
2. If valid: apply immediately (release scripts, gtag update, events), show nothing but re-entry points.
3. Else resolve mode: `blc_region` cookie → else POST `/wp-json/blc/v1/region` → (on failure or `unknown` + timezone fallback on) `Intl` timezone heuristic (`Europe/*` → opt-in, `America/*` except Quebec zones → opt-out if US-like else notice) → `unknown_mode`.
4. GPC: opt-out mode → deny marketing, mark `g:1`, save + log method `gpc`, show notice in panel; opt-in mode → nothing pre-granted anyway; panel shows notice.
5. opt-out/notice without GPC: apply defaults (all granted) without saving a choice; banner shown until dismissed.
6. Banner + panel UI: set `data-blc-mode` on root, show banner, wire actions, `<dialog>` with `showModal()` (modal layout / preferences), focus return, Escape handling, toggles reflect state.
7. Apply: for each held element whose category is granted, clone into live `<script>` sequentially (await `onload` for src scripts to keep order); `data-blc-src` → `src`.
8. Revoke: delete cookies for revoked categories (exact and prefix `*` patterns, on host and parent domain, path `/`), then `location.reload()`.
9. Save cookie `blc_consent` (`SameSite=Lax; Secure` when https; max-age per mode), POST log `/wp-json/blc/v1/consent` with `navigator.sendBeacon` fallback to fetch.
10. Public API `window.blConsent` (`has`, `on`, `open`, `get`, `acceptAll`, `rejectAll`), `blc:change` event, `dataLayer.push({event:'blc_consent_update', blc_categories})`.
11. Embeds: placeholder buttons "Load once" (swap that one) / "Always allow" (grant preferences, save).

### Task 9: REST + consent log
Files: `src/Rest/Controller.php`, `src/ConsentLog/Table.php`, `src/ConsentLog/Repository.php`, tests.
- `POST /blc/v1/region` → `{country, region, mode, source}`, `nocache_headers()`.
- `POST /blc/v1/consent` → validates UUID, categories subset, method enum, mode enum; stores `consent_id, categories (json), policy_version, mode, method, gpc, ip_hash (sha256 of IP with last octet / last 80 bits zeroed + wp_salt), ua_hash, url (path only), created_at`. Rate limit: 10/min per ip_hash via transient. Returns 201.
- Table created on activation and version upgrade (`dbDelta`). Daily cron `blc_purge_log` deletes rows older than retention. Uninstall drops table only if `blc_settings.delete_data_on_uninstall`.
- Tests: IP anonymization (v4 and v6), validation rejects unknown category, rate limit.

### Task 10: Embeds
File: `src/Embeds/Embeds.php`, tests.
- Filters: `embed_oembed_html`, `the_content` (p 99), `blc_embed()` helper. Providers: YouTube (+ nocookie), Vimeo (`dnt=1`), Google Maps, Spotify, generic iframe (option, default on for third-party hosts).
- YouTube thumbnail: fetch `i.ytimg.com/vi/{id}/hqdefault.jpg` server-side, store as transient-cached base64? No: sideload into uploads `blc-thumbs/{id}.jpg` once, serve local URL.
- Skip when the embed is inside admin/editor/REST or in a feed.
- Tests: YouTube iframe becomes placeholder with nocookie data-src; same-host iframe untouched; Vimeo gets `dnt=1`.

### Task 11: Declaration shortcode and block
Files: `src/Declaration.php`, `blocks/declaration/block.json`, `blocks/declaration/editor.js` (no build; `wp.serverSideRender`).
- `[blc_cookie_declaration]` and `bananaland/cookie-declaration` render `declaration.php`: per category, services with provider, purpose, privacy link, cookie table, plus "Change preferences" button. `[blc_preferences_link]` shortcode too.

## Phase 2: Admin, log, release

### Task 12: Admin settings
Files: `src/Admin/Admin.php`, `src/Admin/views/*.php`, `assets/admin.js`, `assets/admin.css`.
- Top-level menu "Consent" (`manage_options`, dashicon `dashicons-shield`). Tabs: Dashboard, General, Banner, Categories, Services, Regions, Consent Log, Tools.
- Single settings form per tab posting to `admin-post.php` with nonce; sanitize per field; services repeater using `<template>` rows (preset select switches fields).
- Dashboard: checklist (privacy page set, policy link, services configured, geo source detected (last region source seen, stored in option by REST endpoint), footer link usage unknown → instructions), 30-day counts by method, template overrides with outdated flags, values coming from theme `config.php` marked read-only.
- Contrast check for color pairs (PHP relative luminance), warning below 4.5:1.
- Tools: export/import JSON, "Scan site" (iframe-loads home + 4 recent URLs with `?blc_scan=1` that forces no-consent mode, reports `document.cookie` and `performance.getEntriesByType('resource')` hosts not in the registry; same-origin only).
- Consent Log: lookup by consent ID, CSV export (streamed), retention setting.

### Task 13: GitHub updater
File: `src/Updater/GitHubUpdater.php`, tests.
- `pre_set_site_transient_update_plugins`: GET `api.github.com/repos/The-Bananaland/bananaland-consent/releases/latest` (6h transient, `User-Agent` header), compare `tag_name` (strip `v`) with `BLC_VERSION`, package = asset named `bananaland-consent.zip`.
- `plugins_api` for details modal (body → changelog via `wpautop`).
- `upgrader_source_selection` renames extracted folder to `bananaland-consent`.
- `auto_update_plugin` → true for this plugin unless `BLC_DISABLE_AUTO_UPDATE`.
- Guard: no update offered when `BLC_DIR` is a symlink or contains `.git`.
- Tests: version compare, guard, asset selection.

### Task 14: Release + CI workflows
Files: `.github/workflows/ci.yml` (php -l, PHPUnit on 8.1 and 8.3), `.github/workflows/release.yml` (on tag `v*`: verify header version = tag, build zip from `git archive` respecting `.distignore`, create release with asset).

### Task 15: E2E suite
Files: `package.json`, `playwright.config.js`, `tests/e2e/*.spec.js`, `tests/e2e/setup.sh` (WP-CLI configures test services: GA4 `G-TEST000001`, Meta `000000000000001`, test page with YouTube embed).
- Route-intercept tracker hosts (record + fulfill empty) so tests don't hit the network.
- Specs: opt-in (DE), opt-out (US-CT), GPC, embeds, consent-mode order, withdrawal cookie cleanup, a11y (axe via `@axe-core/playwright`), keyboard flow.

## Phase 3: Theme side (worktree branch `feature/consent-kit`)

### Task 16: Worktree + dev hook
- `git -C themes/ctinnovations2026 worktree add ../../blc-dev/ctinnovations2026 -b feature/consent-kit` (from committed HEAD; Jordan's uncommitted changes are untouched).
- `wp-content/mu-plugins/blc-dev-kit.php`: filters `blc_template_dirs` and `blc_kit_url` to the worktree's `consent/` (local env only).

### Task 17: Starter kit (theme-agnostic parts)
Files in worktree: `consent/tokens.css`, `consent/consent.css`, `consent/banner.php`, `consent/preferences.php`, `consent/config.php`, `consent/README.md`.

### Task 18: CI brand
- Navy panel, white Figtree type, lime primary + outlined secondary of equal size, sky focus ring; bottom bar desktop / bottom sheet mobile. Verify with Playwright screenshots at 375, 768, 1440 and axe.
- `config.php`: CI services from the legacy inventory (`ci_legacy` options + legacy theme), privacy page link.

### Task 19: TGM + docs
- `config/plugins.php`: add Bananaland Consent (source = latest release zip URL, required, auto-activate). TGM notices: keep global `has_notices` false but add an admin notice when the plugin is missing.
- Docs: new `.docs/08_cookie-consent.md`; update `.docs/06_plugins.md`, `.docs/05_extensions.md`.
- Footer link: document `blc_preferences_link()` for Jordan to add to `components/footer/template.php` (not edited here because it has uncommitted changes).

### Task 20: Review and release
- Code review subagent over the plugin; fix findings.
- Tag `v0.1.0` after Jordan's go-ahead (publishing a release is outward-facing).
