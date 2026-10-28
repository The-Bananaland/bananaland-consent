const { test, expect, consentCookie, hit } = require('./helpers');

test.use({ region: 'DE' });

test('nothing non-essential loads before a choice', async ({ page, trackers }) => {
  await page.goto('/');
  const banner = page.locator('[data-blc="banner"]');
  await expect(banner).toBeVisible();
  await expect(banner.locator('[data-blc-show-for="opt-in"]')).toBeVisible();
  await expect(banner.locator('[data-blc-show-for="opt-out"]')).toBeHidden();
  await page.waitForTimeout(800);
  expect(trackers).toEqual([]);
  expect(await page.locator('script[type="text/plain"][data-consent="analytics"]').count()).toBeGreaterThan(0);
  const names = (await page.context().cookies()).map((c) => c.name);
  expect(names.filter((n) => !['blc_region'].includes(n))).toEqual([]);
});

test('reject and accept have equal prominence', async ({ page }) => {
  await page.goto('/');
  const reject = page.locator('[data-blc="banner"] [data-blc-show-for="opt-in"] [data-blc-action="reject-all"]');
  const accept = page.locator('[data-blc="banner"] [data-blc-show-for="opt-in"] [data-blc-action="accept-all"]');
  await expect(reject).toBeVisible();
  expect(await reject.getAttribute('class')).toBe(await accept.getAttribute('class'));
});

test('consent mode default precedes any tag and is denied', async ({ page }) => {
  await page.goto('/');
  const first = await page.evaluate(() => Array.from(window.dataLayer[0] || []));
  expect(first[0]).toBe('consent');
  expect(first[1]).toBe('default');
  expect(first[2].analytics_storage).toBe('denied');
  expect(first[2].ad_storage).toBe('denied');
});

test('accept all releases scripts, updates consent mode and logs', async ({ page, trackers }) => {
  await page.goto('/');
  const logged = page.waitForResponse((r) => r.url().includes('/blc/v1/consent'));
  await page.click('[data-blc-show-for="opt-in"] [data-blc-action="accept-all"]');
  expect((await logged).status()).toBe(201);
  await expect(page.locator('[data-blc="banner"]')).toBeHidden();
  await expect.poll(() => hit(trackers, /gtag\/js\?id=G-TEST000001/)).toBe(true);
  await expect.poll(() => hit(trackers, /fbevents\.js/)).toBe(true);
  const update = await page.evaluate(() => window.dataLayer.map((e) => Array.from(e)).find((e) => e[0] === 'consent' && e[1] === 'update'));
  expect(update[2].analytics_storage).toBe('granted');
  const saved = await consentCookie(page);
  expect(saved.c.sort()).toEqual(['analytics', 'marketing', 'preferences']);
  expect(saved.m).toBe('opt-in');
});

test('reject all keeps trackers off across page loads', async ({ page, trackers }) => {
  await page.goto('/');
  await page.click('[data-blc-show-for="opt-in"] [data-blc-action="reject-all"]');
  await page.reload();
  await expect(page.locator('[data-blc="banner"]')).toBeHidden();
  await page.waitForTimeout(800);
  expect(trackers).toEqual([]);
  expect((await consentCookie(page)).c).toEqual([]);
});

test('custom choice loads only the chosen category', async ({ page, trackers }) => {
  await page.goto('/');
  await page.click('[data-blc-show-for="opt-in"] [data-blc-action="open-preferences"]');
  const prefs = page.locator('[data-blc="preferences"]');
  await expect(prefs).toBeVisible();
  await prefs.locator('[data-blc="toggle"][data-category="analytics"]').check();
  await prefs.locator('[data-blc-action="save"]').click();
  await expect.poll(() => hit(trackers, /gtag\/js/)).toBe(true);
  await page.waitForTimeout(500);
  expect(hit(trackers, /fbevents/)).toBe(false);
});

test('withdrawing consent deletes cookies and reloads', async ({ page, context }) => {
  await page.goto('/');
  await page.click('[data-blc-show-for="opt-in"] [data-blc-action="accept-all"]');
  await page.evaluate(() => {
    document.cookie = '_ga=GA1.1.123; path=/';
    document.cookie = '_ga_TEST=GS1.1; path=/';
    document.cookie = '_fbp=fb.1.1; path=/';
  });
  await page.click('[data-blc="floating-button"]');
  await page.locator('[data-blc="toggle"][data-category="analytics"]').uncheck();
  await page.locator('[data-blc="toggle"][data-category="marketing"]').uncheck();
  await Promise.all([page.waitForNavigation(), page.click('[data-blc-action="save"]')]);
  const names = (await context.cookies()).map((c) => c.name);
  expect(names).not.toContain('_ga');
  expect(names).not.toContain('_ga_TEST');
  expect(names).not.toContain('_fbp');
});

test('new policy version asks again and keeps the consent id', async ({ page, context }) => {
  await page.goto('/');
  await page.click('[data-blc-show-for="opt-in"] [data-blc-action="accept-all"]');
  const before = await consentCookie(page);
  const stale = { ...before, pv: 'old' };
  const url = new URL(page.url());
  await context.addCookies([{ name: 'blc_consent', value: encodeURIComponent(JSON.stringify(stale)), domain: url.hostname, path: '/' }]);
  await page.reload();
  await expect(page.locator('[data-blc="banner"]')).toBeVisible();
  await page.click('[data-blc-show-for="opt-in"] [data-blc-action="reject-all"]');
  expect((await consentCookie(page)).id).toBe(before.id);
});
