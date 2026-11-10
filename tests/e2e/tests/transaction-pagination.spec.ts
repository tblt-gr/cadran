import { expect, test, type Page } from '@playwright/test';
import { settled } from './support/page';
import { auditAccessibility } from './support/a11y';

async function mockPages(page: Page, total = 100, failure?: 409 | 503) {
  let prototype: Record<string, unknown> | undefined;
  const requests: string[] = [];
  let fail = failure;
  let release: (() => void) | undefined;
  let hold = false;
  await page.route(
    (url) => url.pathname === '/api/v1/transactions',
    async (route) => {
      const cursor = new URL(route.request().url()).searchParams.get('cursor') ?? '';
      requests.push(cursor);
      if (!prototype) {
        const response = await route.fetch();
        prototype = (await response.json()).items[0];
        expect(prototype, 'demo ledger supplies the transaction shape').toBeTruthy();
      }
      if (cursor && hold)
        await new Promise<void>((resolve) => {
          release = resolve;
        });
      if (cursor && fail) {
        const status = fail;
        fail = undefined;
        await route.fulfill({
          status,
          json: {
            type: status === 409 ? '/problems/transactions.cursor_stale' : '/problems/unavailable',
          },
        });
        return;
      }
      const start = cursor ? Number(cursor) : 0;
      const items = Array.from({ length: Math.min(50, total - start) }, (_, offset) => ({
        ...prototype,
        id: `00000000-0000-7000-8000-${String(start + offset + 1).padStart(12, '0')}`,
        rawLabel: `TX024 mouvement ${start + offset}`,
        userLabel: null,
        state: 'BOOKED',
        displayLabel: `TX024 mouvement ${start + offset}`,
        bookedOn: '2026-11-01',
      }));
      await route.fulfill({
        json: { items, nextCursor: start + 50 < total ? String(start + 50) : null },
      });
    },
  );
  return {
    requests,
    hold: () => {
      hold = true;
    },
    release: () => {
      hold = false;
      release?.();
    },
  };
}

for (const width of [1280, 360]) {
  test(`appends automatically without moving the reading position at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const pages = await mockPages(page);
    pages.hold();
    await page.goto('/transactions');
    await settled(page);
    await page.getByRole('button', { name: 'Édition groupée' }).click();
    await page
      .getByRole('checkbox', { name: 'Sélectionner TX024 mouvement 40', exact: true })
      .check();
    const anchor = page.getByRole('button', {
      name: 'Consulter la transaction « TX024 mouvement 40 »',
      exact: true,
    });
    await anchor.scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, 400));
    await expect.poll(() => pages.requests.length).toBe(2);
    const before = await anchor.boundingBox();
    pages.release();
    await expect(
      page.getByRole('checkbox', { name: 'Sélectionner TX024 mouvement 40', exact: true }),
    ).toBeChecked();
    await expect(
      page.getByRole('button', {
        name: 'Consulter la transaction « TX024 mouvement 99 »',
        exact: true,
      }),
    ).toBeAttached();
    const after = await anchor.boundingBox();
    expect(Math.abs(after!.y - before!.y)).toBeLessThan(2);
    expect(pages.requests).toEqual(['', '50']);
    await expect(page.getByRole('heading', { name: /dimanche 1 novembre 2026/ })).toHaveCount(1);
    const date = page.getByRole('heading', { name: /dimanche 1 novembre 2026/ });
    for (const index of [20, 30]) {
      await page
        .getByRole('button', {
          name: `Consulter la transaction « TX024 mouvement ${index} »`,
          exact: true,
        })
        .evaluate((element) => element.scrollIntoView({ block: 'center' }));
      await expect(date).toBeInViewport();
      await expect(date).toHaveCount(1);
    }
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await auditAccessibility(page);
  });
}

test('retries a failed next page by keyboard and keeps stale recovery explicit', async ({
  page,
}) => {
  const pages = await mockPages(page, 100, 503);
  await page.goto('/transactions');
  await settled(page);
  await page.getByRole('button', { name: 'Afficher plus', exact: true }).scrollIntoViewIfNeeded();
  await expect(page.getByRole('alert')).toContainText('Les mouvements suivants');
  await expect(page.getByText('TX024 mouvement 0', { exact: true })).toBeAttached();
  const retry = page.getByRole('button', { name: 'Réessayer', exact: true });
  await retry.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByText('TX024 mouvement 99', { exact: true })).toBeAttached();
  expect(pages.requests).toEqual(['', '50', '50']);
  await expect(
    page.getByText('La liste n’a pas d’autre mouvement à charger pour ces filtres.'),
  ).toBeVisible();

  await page.unrouteAll({ behavior: 'wait' });
  const stale = await mockPages(page, 100, 409);
  await page.goto('/transactions?period=all');
  await settled(page);
  await page.getByRole('button', { name: 'Afficher plus', exact: true }).scrollIntoViewIfNeeded();
  const reload = page.getByRole('button', { name: 'Recharger depuis le début' });
  await expect(reload).toBeVisible();
  await expect(page.getByText('TX024 mouvement 0', { exact: true })).toBeAttached();
  await reload.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => stale.requests.filter((cursor) => cursor === '').length).toBe(2);
});

test('keeps a long single day bounded and keyboard accessible', async ({ page }) => {
  await mockPages(page, 400);
  await page.goto('/transactions');
  await settled(page);
  for (let end = 50; end < 400; end += 50) {
    await page.getByRole('button', { name: 'Afficher plus', exact: true }).scrollIntoViewIfNeeded();
    await expect.poll(() => page.locator('[data-state]').count()).toBeGreaterThan(0);
    await expect(page.getByRole('status')).toContainText(`${end + 50} mouvements chargés`);
  }
  expect(await page.locator('[data-state]').count()).toBeLessThan(200);
  await expect(page.getByText('TX024 mouvement 0', { exact: true })).toHaveCount(0);
  const date = page.getByRole('heading', { name: /dimanche 1 novembre 2026/ });
  await expect(date).toHaveCount(1);
  await expect(date).toBeInViewport();
  const previous = page.getByRole('button', { name: 'Mouvements précédents déjà chargés' });
  await previous.focus();
  await page.keyboard.press('Enter');
  await expect
    .poll(() =>
      page.evaluate(() =>
        document.activeElement?.closest('[data-state]')?.getAttribute('data-state'),
      ),
    )
    .toBe('BOOKED');
  await expect
    .poll(() =>
      page.evaluate(() => {
        const control = document.activeElement;
        if (!(control instanceof HTMLElement)) return false;
        const box = control.getBoundingClientRect();
        return (
          box.top >= 0 &&
          box.bottom <= window.innerHeight &&
          box.left >= 0 &&
          box.right <= window.innerWidth
        );
      }),
    )
    .toBe(true);
  await page.evaluate(() => window.scrollTo(0, 0));
  const first = page.getByRole('button', {
    name: 'Consulter la transaction « TX024 mouvement 0 »',
    exact: true,
  });
  await expect(first).toBeAttached();
  await first.focus();
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
  const visible = page.getByRole('button', {
    name: 'Consulter la transaction « TX024 mouvement 350 »',
    exact: true,
  });
  await expect(visible).toBeAttached();
  const original = await visible.elementHandle();
  await visible.focus();
  expect(
    await original!.evaluate((node) => node.isConnected && document.activeElement === node),
  ).toBe(true);
  await expect(first).toHaveCount(0);
});
