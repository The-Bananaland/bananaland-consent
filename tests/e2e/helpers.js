const { test: base, expect } = require('@playwright/test');

const TRACKERS = /(googletagmanager\.com|google-analytics\.com|connect\.facebook\.net|facebook\.com\/tr|youtube\.com|youtube-nocookie\.com|ytimg\.com|vimeo\.com|snap\.licdn\.com|clarity\.ms)/;

/**
 * Page fixture with a forced region and tracker requests intercepted and recorded.
 * Usage: test.use({ region: 'DE', gpc: false, layout: '' })
 */
const test = base.extend({
  region: ['DE', { option: true }],
  gpc: [false, { option: true }],
  layout: ['', { option: true }],
  trackers: async ({}, use) => {
    await use([]);
  },
  page: async ({ page, region, gpc, layout, trackers }, use) => {
    const headers = { 'X-BLC-E2E': '1', 'X-BLC-Test-Region': region };
    if (layout) headers['X-BLC-E2E-Layout'] = layout;
    await page.setExtraHTTPHeaders(headers);
    if (gpc) {
      await page.addInitScript(() => Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', { get: () => true }));
    }
    await page.route(TRACKERS, (route) => {
      trackers.push(route.request().url());
      const js = route.request().resourceType() === 'script';
      route.fulfill({ status: 200, contentType: js ? 'application/javascript' : 'text/html', body: js ? '/* stub */' : '<html></html>' });
    });
    await use(page);
  },
});

async function consentCookie(page) {
  const cookie = (await page.context().cookies()).find((c) => c.name === 'blc_consent');
  return cookie ? JSON.parse(decodeURIComponent(cookie.value)) : null;
}

const hit = (trackers, pattern) => trackers.some((u) => pattern.test(u));

module.exports = { test, expect, consentCookie, hit };
