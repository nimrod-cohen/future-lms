// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.FLMS_E2E_URL || 'http://value.dev.test';

module.exports = defineConfig({
  testDir: './specs',
  // The specs share one set of fixtures and change it as they go.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  globalSetup: require.resolve('./global-setup.js'),
  globalTeardown: require.resolve('./global-teardown.js'),
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ...devices['Desktop Chrome'],
    viewport: { width: 1400, height: 900 },
  },
  projects: [
    // The student checks the schedule as set up; the admin spec then changes it.
    { name: 'student', testMatch: /student\.spec\.js/, use: { storageState: '.auth/student.json' } },
    { name: 'admin', testMatch: /admin\.spec\.js/, use: { storageState: '.auth/admin.json' }, dependencies: ['student'] },
  ],
});
