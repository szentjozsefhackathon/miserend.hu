import { test, expect } from '@playwright/test';

test.describe('Church Search', () => {
  test('should search for a specific church from homepage and find it in results', async ({ page }) => {
    await page.goto('/');
    
    const searchInput = page.locator('#keyword');
    await expect(searchInput).toBeVisible();
    await searchInput.fill('Alkantarai Szent Péter');

    const submitButton = page.locator('button[type="submit"][name="q"][value="SearchResultsChurches"]');
    await expect(submitButton).toBeVisible();

    await Promise.all([
      page.waitForLoadState('networkidle'),
      submitButton.click(),
    ]);

    await expect(page).toHaveURL(/\bq=SearchResultsChurches\b/);
    await expect(page.locator('.church-site-main-content')).toContainText('Alkantarai Szent Péter');
  });

  test('should navigate to church detail page from search results', async ({ page }) => {
    await page.goto('/?q=SearchResultsChurches&kulcsszo=Alkantarai');
    
    const churchLink = page.locator('.church-site-main-content a:has-text("Alkantarai Szent Péter")').first();
    await expect(churchLink).toBeVisible();
    
    await churchLink.click();
    
    await page.waitForURL(/templom\/\d+/);
    
    const pageHeader = page.locator('.page-header h2, h2');
    await expect(pageHeader).toContainText('Alkantarai');
  });
});
