const { test, expect, consentCookie, hit } = require('./helpers');

test.describe('opt-out region (Connecticut)', () => {
  test.use({ region: 'US-CT' });

  test('trackers load by default and the banner offers an opt-out', async ({ page, trackers }) => {
    await page.goto('/');
    await expect(page.locator('[data-blc="banner"] [data-blc-show-for="opt-out"]')).toBeVisible();
    await expect.poll(() => hit(trackers, /gtag\/js/)).toBe(true);
    await expect.poll(() => hit(trackers, /fbevents/)).toBe(true);
    expect(await consentCookie(page)).toBeNull();
  });

  test('opting out of sale/sharing stops marketing', async ({ page, trackers }) => {
    await page.goto('/');
    await Promise.all([page.waitForNavigation(), page.click('[data-blc-show-for="opt-out"] [data-blc-action="opt-out"]')]);
    trackers.length = 0;
    await page.reload();
    await expect.poll(() => hit(trackers, /gtag\/js/)).toBe(true);
    await page.waitForTimeout(500);
    expect(hit(trackers, /fbevents/)).toBe(false);
    expect((await consentCookie(page)).c).not.toContain('marketing');
  });

  test('preferences link switches to "Your Privacy Choices"', async ({ page }) => {
    await page.goto('/?blc_e2e=embed');
    const link = page.locator('[data-blc="preferences-link"]').first();
    await expect(link).toContainText('Your Privacy Choices');
    await expect(link.locator('.blc-link__icon')).toBeVisible();
  });
});

test.describe('Global Privacy Control', () => {
  test.use({ region: 'US-CA', gpc: true });

  test('GPC opts out of marketing without interaction', async ({ page, trackers }) => {
    const logged = page.waitForResponse((r) => r.url().includes('/blc/v1/consent'));
    await page.goto('/');
    const body = (await logged).request().postDataJSON();
    expect(body.method).toBe('gpc');
    expect(body.gpc).toBe(true);
    await expect(page.locator('[data-blc="banner"]')).toBeHidden();
    await expect.poll(() => hit(trackers, /gtag\/js/)).toBe(true);
    await page.waitForTimeout(500);
    expect(hit(trackers, /fbevents/)).toBe(false);

    await page.click('[data-blc="floating-button"]');
    await expect(page.locator('[data-blc="gpc-notice"]')).toBeVisible();
    await expect(page.locator('[data-blc="toggle"][data-category="marketing"]')).not.toBeChecked();
  });
});

test.describe('notice region', () => {
  test.use({ region: 'JP' });

  test('shows a light notice and loads by default', async ({ page, trackers }) => {
    await page.goto('/');
    await expect(page.locator('[data-blc="banner"] [data-blc-show-for="notice"]')).toBeVisible();
    await expect.poll(() => hit(trackers, /gtag\/js/)).toBe(true);
  });
});
