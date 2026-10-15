import { expect, test } from '@playwright/test';

test.describe('installable application', () => {
  test('serves a manifest with the project icons and the revalidation headers', async ({
    request,
  }) => {
    const response = await request.get('/manifest.webmanifest');
    expect(response.status()).toBe(200);
    expect(response.headers()['cache-control']).toContain('no-cache');

    const manifest = await response.json();
    expect(manifest).toMatchObject({
      name: 'Cadran Budget',
      short_name: 'Cadran',
      display: 'standalone',
      start_url: '/',
      theme_color: '#08090d',
      background_color: '#08090d',
    });
    for (const icon of manifest.icons) {
      expect((await request.get(icon.src)).status()).toBe(200);
    }

    const worker = await request.get('/sw.js');
    expect(worker.headers()['cache-control']).toContain('no-cache');
    expect(await worker.text()).not.toContain('__CADRAN_');
  });

  test('registers the worker, shows the offline state and never caches the API', async ({
    page,
    context,
  }) => {
    await page.goto('/');
    await page.evaluate(() => navigator.serviceWorker.ready);

    // The first load is not controlled: reload so the worker answers navigations.
    await page.reload();
    await expect
      .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
      .toBe(true);

    const cached = await page.evaluate(async () => {
      const urls: string[] = [];
      for (const name of await caches.keys()) {
        const cache = await caches.open(name);
        for (const request of await cache.keys()) urls.push(new URL(request.url).pathname);
      }
      return urls;
    });
    expect(cached).toContain('/index.html');
    expect(cached).toContain('/offline.html');
    expect(cached.filter((path) => path.startsWith('/api/'))).toEqual([]);

    await context.setOffline(true);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Session indisponible' })).toBeVisible();

    const apiAnswer = await page.evaluate(() =>
      fetch('/api/v1/status').then(
        () => 'answered',
        () => 'failed',
      ),
    );
    expect(apiAnswer).toBe('failed');
    await context.setOffline(false);
  });
});
