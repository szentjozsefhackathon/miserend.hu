import 'dotenv/config';
import { defineConfig, devices } from '@playwright/test';

const isDocker = process.env.PLAYWRIGHT_IN_DOCKER === 'true';

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  use: {
    baseURL: isDocker ? 'http://miserend:8000' : 'http://localhost:8000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  webServer: isDocker ? undefined : {
    command: 'docker compose -f docker/compose.yml -f docker/compose.dev.yml up',
    url: 'http://localhost:8000',
    reuseExistingServer: !process.env.CI,
    timeout: 120 * 1000,
  },
});
