import { expect, test } from '@playwright/test';

test.describe('foundation smoke', () => {
  test('serves the versioned API status without caching', async ({ request }) => {
    const response = await request.get('/api/v1/status');

    expect(response.status()).toBe(200);
    expect(response.headers()['cache-control']).toContain('no-store');
    expect(await response.json()).toEqual({ status: 'ready', apiVersion: 'v1' });
  });

  test('serves the web shell and reaches a ready state', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('status')).toContainText('prêt');
  });
});
