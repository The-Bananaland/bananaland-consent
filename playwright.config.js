// E2E tests run against a local WordPress with this plugin active (default http://ci2026.local).
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: 'tests/e2e',
  timeout: 30000,
  fullyParallel: true,
  workers: 4,
  reporter: [['list']],
  use: {
    baseURL: process.env.BLC_E2E_URL || 'http://ci2026.local',
    extraHTTPHeaders: { 'X-BLC-E2E': '1' },
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
