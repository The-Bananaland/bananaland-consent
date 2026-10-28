# Bananaland Consent

Region-aware cookie consent for WordPress: opt-in where the law requires it (EU/EEA, UK, Switzerland, Quebec, Brazil), opt-out with Global Privacy Control in the U.S., a light notice elsewhere. Google Consent Mode v2, real script blocking, click-to-load embeds, proof-of-consent records, and theme-level templating.

Requires WordPress 6.4+ and PHP 8.1+. No other dependencies.

> The plugin provides the mechanisms these laws call for. Whether a site complies also depends on its privacy policy, vendor agreements and configuration. Have counsel review the copy and policy for each client.

## How it works

1. A tiny inline script in `<head>` sets Google Consent Mode defaults (denied, or the visitor's saved choice) before any tag runs.
2. Services you add in **Consent → Services** are printed as `<script type="text/plain" data-consent="analytics">`. They don't run.
3. `consent.js` reads the saved choice. If there is none, it asks `POST /wp-json/blc/v1/region` which model applies, then shows the matching banner.
4. When a category is allowed, held scripts and iframes for it are swapped in, Consent Mode is updated, and a `blc_consent_update` event is pushed to `dataLayer`.
5. Each choice is stored in the `blc_consent` cookie and logged (hashed, truncated IP) for proof of consent.

Everything is resolved in the browser, so full-page caching (WP Engine, Cloudflare) stays on.

### Consent models

| Model | Where | Before a choice | Banner |
|---|---|---|---|
| `opt-in` | EU/EEA, UK, CH, Quebec, Brazil, and unknown locations | Only strictly necessary | Accept all / Reject all / Customize, equal weight |
| `opt-out` | United States | Everything on | OK / Customize (optional "Opt out of sale, sharing & targeted ads" button) |
| `notice` | Everywhere else | Everything on | OK / Customize |

U.S. visitors can always turn advertising off under **Customize** or from the footer link, and GPC is honored automatically, so the extra opt-out button is off by default. Turn it on (**Banner → U.S. visitors**) for sites that run advertising or retargeting pixels. The footer link reads "Your Privacy Choices" only when an advertising service is declared.

The U.S. and "everywhere else" models are adjustable under **Settings**; the EU/UK opt-in rule can't be loosened, even by per-country exceptions.

Location comes from WP Engine GeoTarget headers or Cloudflare (`CF-IPCountry`, plus `CF-Region-Code` with the "Add visitor location headers" managed transform). Without either, visitors fall back to the browser timezone and then to the **unknown location** setting (opt-in by default). The dashboard shows which source is in use. Rules live in `data/regions.json`; override per site under **Consent → Regions**.

**Global Privacy Control**: in opt-out and notice regions, a GPC signal opts the visitor out of marketing automatically and is logged with method `gpc`. In opt-in regions nothing is on by default anyway; the preferences panel confirms the signal was seen.

### Categories

`necessary` (always on), `preferences` (embeds, chat, remembered settings), `analytics`, `marketing` (advertising; "sale/sharing" under U.S. state laws). Keys are fixed; labels and descriptions are editable.

| Category | Consent Mode signals |
|---|---|
| analytics | `analytics_storage` |
| marketing | `ad_storage`, `ad_user_data`, `ad_personalization` |
| preferences | `functionality_storage`, `personalization_storage` |

## Adding services

**Consent → Services** has presets for Google Tag Manager, Google Analytics 4, Google Ads, Meta Pixel, LinkedIn Insight, HubSpot, Microsoft Clarity, and **Custom** (paste any snippet; requires the `unfiltered_html` capability). Each preset knows its cookies, which feed the cookie declaration and are deleted when consent is withdrawn.

Tag Manager loads for everyone so it can receive Consent Mode signals. Google tags inside it respect consent automatically. For other tags in GTM, use a trigger on the custom event `blc_consent_update` with a condition such as `blc_marketing equals true`.

### Holding your own scripts

```php
// An enqueued script
wp_enqueue_script('hotjar', 'https://static.hotjar.com/…', [], null, ['strategy' => 'defer']);
blc_require_consent('hotjar', 'analytics');
```

```html
<!-- Inline markup in a template -->
<script type="text/plain" data-consent="marketing">
  /* runs only after marketing is allowed */
</script>
<iframe data-blc-src="https://example.com/widget" data-consent="preferences"></iframe>
```

```php
// Third-party iframe from an ACF oEmbed field or similar
echo blc_embed(get_field('video'));
```

YouTube, Vimeo, Google Maps and Spotify embeds (and, if turned on under Settings → Advanced, any other third-party iframe) in post content are replaced automatically with a placeholder. YouTube switches to `youtube-nocookie.com`, Vimeo gets `dnt=1`, and thumbnails are fetched server-side once and served locally.

### JavaScript API

```js
blConsent.has('analytics');           // boolean
blConsent.get();                      // { mode, categories, saved, gpc }
blConsent.on((e) => { /* e.categories, e.mode, e.isDefault, e.consentId */ });
blConsent.open();                     // open preferences
blConsent.ready.then(() => { … });

document.addEventListener('blc:change', (e) => console.log(e.detail));
```

## Links and shortcodes

- `<?php echo blc_preferences_link(); ?>`: footer link. For U.S. visitors on sites with advertising services it reads **Your Privacy Choices** and shows the CPRA toggle icon; otherwise "Cookie preferences".
- `[blc_preferences_link]`, or any link to `#blc-preferences`.
- `[blc_cookie_declaration]` or the **Cookie declaration** block: categories, services and cookies, generated from your settings.

## Theming

The plugin looks for a `consent/` folder in the child theme, then the parent theme:

```
{theme}/consent/
  config.php        return [...];  settings in code (override the admin)
  tokens.css        --blc-* custom properties, loaded after the default styles
  consent.css       full replacement for the default styles (the default CSS is then skipped)
  banner.php        template overrides (copy from the plugin's templates/ folder)
  preferences.php
  category.php
  service-row.php
  floating-button.php
  embed-placeholder.php
  declaration.php
```

**Level 1: admin.** Layout (bar, box, modal), position, colors (with contrast warnings) and all copy.

**Level 2: tokens.** `tokens.css`:

```css
:root {
  --blc-bg: #16566b;
  --blc-text: #fff;
  --blc-primary-bg: #cafc84;
  --blc-primary-text: #1e1e1e;
  --blc-font: "Figtree", sans-serif;
  --blc-radius: 1rem;
}
```

See `assets/consent.css` for every token.

**Level 3: templates.** Copy a file from `templates/` into `{theme}/consent/` and change the markup freely. Keep these hooks:

| Attribute | Purpose |
|---|---|
| `data-blc="banner"` | First layer. `<div>` for bar/box (starts `hidden`), `<dialog>` for modal |
| `data-blc-show-for="opt-in"` (`opt-out`, `notice`) | Shown only for that model |
| `data-blc="preferences"` | Preferences `<dialog>` |
| `data-blc="toggle" data-category="analytics"` | Category checkbox (`role="switch"`) |
| `data-blc="consent-id"`, `data-blc="gpc-notice"` | Filled/shown by the script |
| `data-blc="floating-button"` | Re-entry button (starts `hidden`) |
| `data-blc-action="accept-all"` | Also `reject-all`, `opt-out`, `save`, `open-preferences`, `close`, `embed-once`, `embed-always` |

Each template has an `@version` header. **Consent → Dashboard** lists theme overrides and flags ones older than the plugin's copy.

`config.php` example:

```php
<?php
return [
    'banner' => ['layout' => 'bar', 'floating_button' => true],
    'services' => [
        ['uid' => 'ga4', 'preset' => 'ga4', 'settings' => ['id' => 'G-XXXXXXX']],
    ],
];
```

Settings resolve as: defaults ← admin ← `config.php` ← `blc_config` filter.

## Filters and actions

| Hook | Use |
|---|---|
| `blc_config` | Final settings array |
| `blc_enabled` | Return false to skip output on a request |
| `blc_kit_dirs` | Where to look for `consent/` |
| `blc_template_path` | Override a template path |
| `blc_resolve_region` | Plug in another geolocation source |
| `blc_service_presets` | Add presets |
| `blc_embed_providers` | `['#regex#' => 'Name']` for more click-to-load providers |
| `blc_embed_category` | Category for an embed (default `preferences`) |
| `blc_client_ip` | IP used for the hashed log entry and rate limiting |
| `blc_consent_logged` (action) | After a record is stored |

Constants: `BLC_DISABLE`, `BLC_DISABLE_AUTO_UPDATE`, `BLC_DISABLE_UPDATER`, `BLC_ALLOW_TEST_REGION`.

## Admin tools

- **Consent log**: look up a visitor's consent ID (shown in their preferences panel), CSV export, retention purge (default 3 years).
- **Tools → Scan**: loads pages as a first-time visitor and lists cookies and third-party requests that happen before consent.
- **Import / export** settings as JSON to start a new site from another one.
- **Bump policy version** to ask everyone again after a material change.

## Updates

Releases are published on GitHub. The plugin checks the latest release every 6 hours and updates through WordPress's normal update system, with auto-updates on by default. Set `define('BLC_DISABLE_AUTO_UPDATE', true);` to pin a site. Symlinked or git-checkout installs are never updated.

Releasing: bump the version in `bananaland-consent.php` (header and `BLC_VERSION`), add a `## x.y.z` section to `CHANGELOG.md`, then tag `vx.y.z` and push. The release workflow builds `bananaland-consent.zip` and publishes it.

## Development

```bash
composer install && vendor/bin/phpunit                   # unit tests
npm install && npx playwright install chromium
BLC_E2E_URL=http://ci2026.local npx playwright test       # E2E against a local site with the plugin active
```

E2E tests force a region with the `X-BLC-Test-Region` header (honored only when `WP_ENVIRONMENT_TYPE` is `local` or `development`, or `BLC_ALLOW_TEST_REGION` is set) and inject test services with `tests/e2e/fixture.php`, so they don't change the site's settings. Tests do write rows to the consent log.
