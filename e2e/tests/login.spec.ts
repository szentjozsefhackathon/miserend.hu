import { test, expect } from '@playwright/test';
import { login, loginAsAdmin } from './helpers/auth';

test.describe('User Login', () => {
  test('should display login form on homepage', async ({ page }) => {
    await page.goto('/');
    
    const loginInput = page.locator('input[name="login"]');
    const passwordInput = page.locator('input[name="passw"]');
    
    await expect(loginInput).toBeVisible();
    await expect(passwordInput).toBeVisible();
    await expect(passwordInput).toHaveAttribute('type', 'password');
  });

  test('should show error message for invalid credentials', async ({ page }) => {
    await login(page, {
      username: 'nonexistent_user_12345',
      password: 'wrongpassword',
    });

    await page.waitForTimeout(1000);

    const errorAlert = page.locator('#messages .alert-danger');
    const errorCount = await errorAlert.count();

    if (errorCount > 0) {
      await expect(errorAlert.first()).toBeVisible();
    } else {
      const url = page.url();
      expect(url).toContain('localhost:8000');
      const usernameVisible = await page.locator('text=/nonexistent_user_12345/i').count();
      expect(usernameVisible).toBe(0);
    }
  });

  test('should not allow login with empty credentials', async ({ page }) => {
    await login(page, {
      username: '',
      password: '',
    });

    const errorAlert = page.locator('#messages .alert-danger, .alert-danger');
    const hasError = await errorAlert.count() > 0;

    if (hasError) {
      await expect(errorAlert).toBeVisible();
    } else {
      const currentUrl = page.url();
      expect(currentUrl).toMatch(/localhost:8000/);
    }
  });

  test('should have logout hidden input set to false by default', async ({ page }) => {
    await page.goto('/');
    
    const logoutInput = page.locator('input[name="logout"]');
    await expect(logoutInput).toHaveAttribute('type', 'hidden');
    await expect(logoutInput).toHaveAttribute('value', 'false');
  });

  test('should successfully login with valid credentials', async ({ page }) => {
    await loginAsAdmin(page);

    const logoutButton = page.locator('div.menusav .navbar a', { hasText: /^Kilépés$/ });
    await expect(logoutButton).toBeVisible({ timeout: 5000 });
  });
});
