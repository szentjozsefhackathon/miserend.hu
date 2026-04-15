import { test, expect } from '@playwright/test';

test.describe('Homepage', () => {
  test('should load the homepage successfully', async ({ page }) => {
    await page.goto('/');
    
    await expect(page).toHaveTitle(/miserend/i);
    
    await expect(page.locator('body')).toBeVisible();
  });

  test('should display church search input', async ({ page }) => {
    await page.goto('/');
    
    const searchInput = page.locator('#keyword');
    await expect(searchInput).toBeVisible();
    await expect(searchInput).toHaveAttribute('name', 'kulcsszo');
  });

  test('should navigate without errors', async ({ page }) => {
    const response = await page.goto('/');
    
    expect(response?.status()).toBeLessThan(400);
  });
});
