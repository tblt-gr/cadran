import { expect, test } from '@playwright/test';

test.describe('foundation smoke', () => {
  test('serves the versioned API status without caching', async ({ request }) => {
    const response = await request.get('/api/v1/status');

    expect(response.status()).toBe(200);
    expect(response.headers()['cache-control']).toContain('no-store');
    expect(await response.json()).toEqual({
      status: 'ready',
      apiVersion: 'v1',
      version: expect.any(String),
    });
  });

  test('serves the web shell and asks an anonymous visitor to sign in', async ({ page }) => {
    await page.goto('/');

    await expect(page.getByRole('button', { name: 'Se connecter' })).toBeVisible();
    await expect(page.getByLabel('Adresse e-mail')).toBeVisible();
  });
});
