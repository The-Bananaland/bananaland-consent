# Bananaland Consent: Design

Date: 2026-10-28
Status: Approved design, ready for implementation planning
First site: Connecticut Innovations (`ci2026`, theme `ctinnovations2026`)

## Goals

A cookie consent and privacy-choice plugin used across all Bananaland WordPress sites.

- Region-aware compliance: opt-in (GDPR/ePrivacy, UK GDPR, Swiss FADP, Quebec Law 25, LGPD) and opt-out (CCPA/CPRA, CTDPA and the other US state privacy laws), with Global Privacy Control honored.
- Real blocking: nothing non-essential loads before consent where consent is required.
- Templating: each theme can restyle or fully rewrite the UI; defaults are configurable in admin.
- One codebase, updated on every site from GitHub releases.

Non-goals: IAB TCF certification, server-side tag management, HTML output-buffer auto-blocking, cookie walls.

The plugin provides the mechanisms these laws require. Compliance for a given site also depends on its privacy policy, vendor agreements and configuration, which the client's counsel reviews.

## Decisions

| Topic | Decision |
|---|---|
| Packaging | Standalone plugin + starter-theme kit (`{theme}/consent/` overrides and tokens) |
| Legal scope | Region-aware, opt-in and opt-out models, strict fallback for unknown regions |
| Script control | Service registry + Google Consent Mode v2 + `data-consent` tagging API + embed placeholders |
| Distribution | Public repo `The-Bananaland/bananaland-consent`; starter theme installs via TGM (one click, auto-activate); plugin updates itself from GitHub releases; WP auto-updates on by default |
| Dependencies | None. Native WP settings (no ACF), vanilla JS (~12 KB gz target) |

## 1. Architecture

```
bananaland-consent/
  bananaland-consent.php      bootstrap, constants, updater
  src/
    Settings/                 admin screens
    Regions/                  geo detection + rule sets
    Services/                 service registry and presets
    ConsentLog/               DB table, REST endpoint, purge, export
    Templates/                locate + render with theme override
    Updater/                  GitHub releases -> WP update API
  templates/                  banner.php, preferences.php, category.php, service-row.php,
                              embed-placeholder.php, declaration.php, floating-button.php
  assets/                     consent.js, consent.css (CSS custom properties only)
  data/regions.json           region rules, shipped as data
```

Customization tiers:

1. Admin settings: copy, colors, layout, position, labels.
2. Theme tokens: `{theme}/consent/tokens.css` overrides `--blc-*` variables.
3. Template overrides: `{theme}/consent/*.php`, WooCommerce-style lookup with fallback to plugin templates. Templates receive a `$consent` view object.

Caching: banner markup is rendered on every page, hidden. Region and consent are resolved client-side, so full-page caching (WP Engine) is safe. Region comes from an uncached REST call (`/wp-json/blc/v1/region`), then a cookie.

Region detection order: WP Engine GeoIP headers, `CF-IPCountry` / `CF-Region`, a filter, then unknown.

## 2. Consent engine

Categories (fixed keys, editable labels):

| Key | Behavior | Examples |
|---|---|---|
| `necessary` | Always on | Session, security, consent cookie |
| `preferences` | Per region | YouTube/Vimeo/Maps embeds, chat |
| `analytics` | Per region | GA4, Clarity, Hotjar |
| `marketing` | Per region; "sale/sharing" and targeted ads in US terms | Meta, LinkedIn, Google Ads |

Region modes:

- **opt-in** (EEA, UK, CH, Quebec, Brazil): non-necessary off until choice. Accept / Reject / Customize on the first layer with equal prominence, no pre-ticked boxes.
- **opt-out** (US states with comprehensive privacy laws): on by default, persistent "Your Privacy Choices" link, first-layer "Opt out of sale/sharing & targeted ads".
- **notice** (rest of world): light notice, defaults on, changeable.
- **unknown**: treated as opt-in. Per-site option "treat all US as opt-out".

Rules live in `data/regions.json`, overridable per site.

Global Privacy Control: `navigator.globalPrivacyControl === true` denies `marketing` automatically in opt-out regions and shows "Opt-out signal honored"; treated as reject in opt-in regions. The visitor can still opt in explicitly.

Stored state: first-party `blc_consent` cookie with consent ID (UUID), policy version, categories, region mode, timestamp, GPC flag. Default expiry 6 months (opt-in) / 12 months (opt-out), configurable. Rejections are remembered. Re-prompt when the policy version is bumped or a service is added to an accepted category.

Google Consent Mode v2: inline `gtag('consent','default',...)` in `<head>` before any tag, covering `analytics_storage`, `ad_storage`, `ad_user_data`, `ad_personalization`, `functionality_storage`, `personalization_storage` (and `security_storage` granted). `wait_for_update: 500`. `update` on choice. Basic mode default; Advanced mode is a per-site option.

Withdrawal: footer link and optional floating button reopen preferences. Revoking a category deletes that category's known first-party cookies and reloads the page.

JS API: `blConsent.has(cat)`, `blConsent.on('change', fn)`, `blConsent.open()`, `blc:change` DOM event, `dataLayer.push({event: 'blc_consent_update', ...})`.

Consent log: every choice POSTs to `/wp-json/blc/v1/consent` with consent ID, categories, policy version, region mode, method (accept, reject, custom, gpc), truncated and hashed IP, timestamp. Rate-limited. Custom table `{prefix}blc_consent_log`. Retention default 3 years, daily cron purge. Admin lookup by consent ID and CSV export. The consent ID is shown in the visitor's preferences panel.

## 3. Service registry and script loading

Presets: Google Tag Manager, GA4, Google Ads, Meta Pixel, LinkedIn Insight, HubSpot, Microsoft Clarity, Custom (raw HTML, head or footer). Each preset knows its cookies (for the declaration and cleanup). Custom services declare cookie name, purpose, duration, provider.

- GA4 / Ads: held until category; Consent Mode wired.
- Meta: `fbq('consent', ...)`. Clarity: `clarity('consent')`. HubSpot: cookie-ban API on reject.
- GTM loads in all modes so it receives Consent Mode signals. Admin warns that non-Google tags inside GTM need consent triggers on `blc_consent_update`.

Holding: held scripts print as `<script type="text/plain" data-consent="analytics">`. `consent.js` replaces them with real scripts in order, preserving attributes. No output buffering.

Tagging API:

```php
blc_require_consent('hotjar', 'analytics');   // rewrite an enqueued handle
blc_has_consent('preferences');               // server-side, uncached contexts only
blc_embed($iframe_html);                      // placeholder treatment for theme iframes
blc_preferences_link();                       // footer link
```

```html
<script type="text/plain" data-consent="marketing">...</script>
```

Embed placeholders: filter on `the_content` and oEmbed output for YouTube, Vimeo, Google Maps, Spotify and generic iframes. Iframe `src` moves to `data-src` inside `embed-placeholder.php`: server-cached thumbnail (YouTube), provider notice, "Load once" and "Always allow (Preferences)". YouTube uses `youtube-nocookie.com`.

Cookie declaration: `[blc_cookie_declaration]` shortcode and block, generated from the registry, with a "Change preferences" button.

Audit helper (admin): loads a set of pages in a hidden iframe with no consent, records cookies and third-party hosts, flags undeclared or pre-consent items.

## 4. Banner UI, accessibility, templating

Layers: banner, preferences panel, re-entry (footer link, optional floating button). Opt-out regions use the CPRA "Your Privacy Choices" wording and toggle icon.

Buttons by mode: opt-in Accept all / Reject all / Customize; opt-out OK / Opt out of sale & targeted ads / Customize; notice OK / Customize.

Layouts: `bar` (top or bottom), `box` (corner), `modal` (opt-in only). Mobile uses a bottom sheet capped near 40% of viewport height.

Accessibility (WCAG 2.2 AA):

- Bar/box: `role="region"` with label, no focus trap, placed right after the skip link.
- Modal and preferences: native `<dialog>`, focus trap, `aria-labelledby`, Escape closes and returns focus. In opt-in mode, closing without a choice is not consent.
- Toggles: `<input type="checkbox" role="switch">` with labels; `necessary` disabled with "Always active".
- Visible focus, 24px minimum targets, `prefers-reduced-motion`, admin contrast warning below 4.5:1.
- No cookie wall: page stays usable in bar/box mode.

Template contract (required hooks):

```html
data-blc="banner" | "preferences" | "toggle"   (toggle has data-category)
data-blc-action="accept-all" | "reject-all" | "save" | "open-preferences" | "close"
```

Templates carry an `@version` header. Admin "Template status" lists theme overrides and flags outdated ones.

Copy: editable in admin with legal defaults per mode; i18n with `.pot`; WPML and Polylang string registration.

Starter-theme kit: `{theme}/consent/tokens.css` maps `--blc-*` to theme `@theme` tokens; `banner.php` and `preferences.php` use Tailwind utilities and existing button and heading classes; compiled by the theme build. When the kit's stylesheet is present the plugin skips its own CSS.

CI version: navy `#16566b` panel, white Figtree type, lime `#cafc84` primary buttons with ink text, outlined secondary buttons of equal weight. Bottom bar on desktop, bottom sheet on mobile. Match a Figma frame if one exists; otherwise design from existing button and card styles and review with Jordan.

## 5. Admin, testing, rollout

Admin: top-level "Consent" menu (`manage_options`).

- Dashboard: setup checklist, 30-day aggregate counts, template-override status.
- Tabs: General, Banner, Categories, Services, Regions, Consent Log.
- JSON import/export of configuration.
- Config in code: `blc_config` filter and `{theme}/consent/config.php`.

Testing:

- PHPUnit: region resolution, region rules, template locator and fallback, service rendering, log writes, rate limiting, retention purge.
- Playwright against `ci2026.local` with region forced via a test header:
  - opt-in: zero tracker requests and zero non-necessary cookies before choice; accept loads; reject stays off; withdrawal clears cookies.
  - opt-out: trackers load; opt-out stops them; GPC denies marketing with no interaction.
  - embeds: no provider request until clicked.
  - Consent Mode: `default` precedes any gtag request; `update` matches the choice.
  - axe-core on banner and panel; keyboard-only runs.
- GitHub Actions: PHPCS (WordPress), PHPUnit, Playwright on PRs. Tag `v*` builds and attaches the release zip.

Releases: semver. Updater reads the latest GitHub release. Auto-updates on by default; `BLC_DISABLE_AUTO_UPDATE` pins a site. Release notes feed the "View details" modal.

Starter theme changes:

- TGM entry pointing at the latest release zip, required, auto-activate, notices on for this plugin.
- `consent/` kit (tokens, templates, `config.php`).
- Docs: new `.docs/08_cookie-consent.md`; update `.docs/06_plugins.md` (TGM entry, auto-updates); note in `.docs/05_extensions.md` on why consent is a plugin.

CI rollout:

1. Inventory current trackers from `ci_legacy` options and the legacy theme.
2. Install via TGM, add `consent/config.php`, apply the branded kit.
3. Run the scan and Playwright suite; send CI a review sheet (services, region modes, copy).
4. CI counsel signs off on copy and privacy policy. Raise Connecticut public-records implications of the consent log.
