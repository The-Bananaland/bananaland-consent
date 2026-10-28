const { test, expect, hit } = require('./helpers');
const AxeBuilder = require('@axe-core/playwright').default;

test.use({ region: 'DE' });

test('embeds wait behind a placeholder until loaded', async ({ page, trackers }) => {
  await page.goto('/?blc_e2e=embed');
  const embed = page.locator('[data-blc="embed"]');
  await expect(embed).toBeVisible();
  await expect(embed).toContainText('YouTube');
  await page.waitForTimeout(500);
  expect(hit(trackers, /youtube|ytimg/)).toBe(false);
  await embed.locator('[data-blc-action="embed-once"]').click();
  const frame = page.locator('iframe[title="Test video"]');
  await expect(frame).toHaveAttribute('src', /youtube-nocookie\.com\/embed\/dQw4w9WgXcQ/);
});

test('banner and preferences pass axe', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('[data-blc="banner"]')).toBeVisible();
  const banner = await new AxeBuilder({ page }).include('[data-blc="banner"]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(banner.violations).toEqual([]);

  await page.click('[data-blc-show-for="opt-in"] [data-blc-action="open-preferences"]');
  await page.locator('[data-blc="preferences"] details').first().evaluate((d) => (d.open = true)).catch(() => {});
  const prefs = await new AxeBuilder({ page }).include('[data-blc="preferences"]').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(prefs.violations).toEqual([]);
});

test('keyboard: banner is reached early, Escape closes preferences and restores focus', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('[data-blc="banner"]')).toBeVisible();
  let reached = false;
  for (let i = 0; i < 6 && !reached; i++) {
    await page.keyboard.press('Tab');
    reached = await page.evaluate(() => !!document.activeElement.closest('[data-blc="banner"]'));
  }
  expect(reached).toBe(true);

  const customize = page.locator('[data-blc-show-for="opt-in"] [data-blc-action="open-preferences"]');
  await customize.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-blc="preferences"]')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.locator('[data-blc="preferences"]')).toBeHidden();
  await expect(customize).toBeFocused();
});

test.describe('modal layout', () => {
  test.use({ layout: 'modal' });

  test('opt-in modal cannot be dismissed with Escape', async ({ page }) => {
    await page.goto('/');
    const banner = page.locator('dialog[data-blc="banner"]');
    await expect(banner).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(banner).toBeVisible();
    await page.click('[data-blc-show-for="opt-in"] [data-blc-action="reject-all"]');
    await expect(banner).toBeHidden();
  });
});
