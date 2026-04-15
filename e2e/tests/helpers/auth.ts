import type { Page } from '@playwright/test';

export type LoginOptions = {
  username: string;
  password: string;
};

export async function login(page: Page, { username, password }: LoginOptions): Promise<void> {
  await page.goto('/');

  await page.locator('input[name="login"]').fill(username);
  await page.locator('input[name="passw"]').fill(password);

  await page.locator('input[name="login"]').press('Enter');
  await page.waitForLoadState('networkidle');
}

export async function loginAsAdmin(page: Page): Promise<void> {
  const username = process.env.E2E_ADMIN_USER ?? 'admin';
  const password = process.env.E2E_ADMIN_PASS ?? 'miserend';

  await login(page, { username, password });
}
