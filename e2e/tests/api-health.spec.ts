import { test, expect } from '@playwright/test';

test.describe('API Health Check', () => {
  test('should respond to API health endpoint', async ({ request }) => {
    const response = await request.get('/api/health');
    
    expect(response.status()).toBe(200);
    
    const body = await response.text();
    expect(body.length).toBeGreaterThan(0);
  });

  test('should handle API version endpoint', async ({ request }) => {
    const response = await request.get('/api/');
    
    expect(response.status()).toBeLessThan(500);
  });
});
