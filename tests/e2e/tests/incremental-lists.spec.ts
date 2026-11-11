import { expect, test, type Page } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { auditLayout } from './support/audit';
import { settled } from './support/page';

const revision = 'a'.repeat(64);
async function instruments(page: Page, total: number, failure?: 409 | 503) {
  const requests: Array<{ page: number; revision: string | undefined }> = [];
  let fail = failure;
  await page.route(
    (url) => url.pathname === '/api/v1/instruments',
    async (route) => {
      const url = new URL(route.request().url());
      const number = Number(url.searchParams.get('page') ?? '1');
      requests.push({ page: number, revision: route.request().headers()['x-list-revision'] });
      if (number > 1 && fail) {
        const status = fail;
        fail = undefined;
        await route.fulfill({
          status,
          json: {
            type: status === 409 ? '/problems/lists.revision_stale' : '/problems/unavailable',
          },
        });
        return;
      }
      const start = (number - 1) * 20;
      const items = Array.from(
        { length: Math.max(0, Math.min(20, total - start)) },
        (_, offset) => ({
          id: `00000000-0000-7000-8000-${String(start + offset + 1).padStart(12, '0')}`,
          name: `PTF016 instrument ${start + offset + 1}`,
          isin: null,
          ticker: `T${start + offset}`,
          venueMic: null,
          instrumentType: 'ETF',
          assetClass: 'EQUITY',
          region: 'WORLD',
          currencyCode: 'EUR',
          quantityPrecision: 6,
          pricePrecision: 4,
          source: 'MANUAL',
          reviewed: true,
          reviewedAt: '2026-01-01T00:00:00Z',
          status: 'ACTIVE',
          used: false,
          version: 1,
        }),
      );
      await route.fulfill({
        headers: { 'X-List-Revision': revision },
        json: { items, page: number, pageSize: 20, total },
      });
    },
  );
  return requests;
}

for (const width of [1280, 360]) {
  test.describe(`incremental list at ${width}px`, () => {
    test.use({
      viewport: { width, height: 800 },
      hasTouch: width === 360,
      isMobile: width === 360,
    });
    test(`incremental instruments append and recover accessibly at ${width}px`, async ({
      page,
    }) => {
      const requests = await instruments(page, 21, 503);
      await page.goto('/investments/instruments');
      await settled(page);
      const anchor = page.getByRole('link', {
        name: "Voir l'instrument PTF016 instrument 18",
        exact: true,
      });
      await anchor.evaluate((element) => element.scrollIntoView({ block: 'center' }));
      await expect(page.getByRole('alert')).toContainText('La suite de la liste');
      await expect(
        page.getByRole('link', { name: "Voir l'instrument PTF016 instrument 1", exact: true }),
      ).toBeAttached();
      const retry = page.getByRole('button', { name: 'Réessayer', exact: true });
      await retry.focus();
      const before = await anchor.boundingBox();
      await page.keyboard.press('Enter');
      await expect(
        page.getByRole('link', { name: "Voir l'instrument PTF016 instrument 21", exact: true }),
      ).toBeAttached();
      const after = await anchor.boundingBox();
      expect(Math.abs(after!.y - before!.y)).toBeLessThan(2);
      expect(requests).toEqual([
        { page: 1, revision: 'start' },
        { page: 2, revision },
        { page: 2, revision },
      ]);
      await expect(page.getByRole('button', { name: 'Afficher plus', exact: true })).toHaveCount(0);
      expect(await auditLayout(page, width === 360)).toEqual([]);
      await auditAccessibility(page);
    });
  });
}

test('empty instruments have no pagination and a stale list restarts explicitly', async ({
  page,
}) => {
  await instruments(page, 0);
  await page.goto('/investments/instruments');
  await settled(page);
  await expect(page.getByRole('heading', { name: 'Aucun instrument', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Afficher plus', exact: true })).toHaveCount(0);
  await page.unrouteAll({ behavior: 'wait' });
  const requests = await instruments(page, 21, 409);
  await page.reload();
  await settled(page);
  await page.getByRole('button', { name: 'Afficher plus', exact: true }).scrollIntoViewIfNeeded();
  const restart = page.getByRole('button', { name: 'Recharger la liste' });
  await expect(restart).toBeVisible();
  await expect(
    page.getByRole('link', { name: "Voir l'instrument PTF016 instrument 1", exact: true }),
  ).toBeAttached();
  await restart.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => requests.filter((request) => request.page === 1).length).toBe(2);
});
