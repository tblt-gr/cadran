import { expect, test, type Locator } from '@playwright/test';
import { settled } from './support/page';

/** A table owns its cell spacing; its plate must not add another inset around it. */
async function expectFlushTable(table: Locator) {
  await expect(table).toBeVisible();
  const layout = await table.evaluate((element) => {
    const plate = element.closest('.card')!;
    const style = getComputedStyle(plate);
    const bounds = plate.getBoundingClientRect();
    const tableBounds = element.getBoundingClientRect();
    return {
      padding: [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft],
      inset: tableBounds.left - bounds.left - parseFloat(style.borderLeftWidth),
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });
  expect(layout.padding).toEqual(['0px', '0px', '0px', '0px']);
  expect(Math.abs(layout.inset)).toBeLessThanOrEqual(1);
  expect(layout.overflow).toBeLessThanOrEqual(0);
}

for (const width of [360, 768, 1440]) {
  test.describe(`table surfaces at ${width}px`, () => {
    test.use({ viewport: { width, height: 900 }, hasTouch: width < 1024 });

    for (const route of [
      '/investments',
      '/investments?view=asset',
      '/investments/instruments',
      '/parameters/metric-policy',
    ]) {
      test(`keeps ${route} tables flush with their plate`, async ({ page }) => {
        await page.goto(route);
        await settled(page);
        await expectFlushTable(page.getByRole('main').getByRole('table').last());
      });
    }

    test('keeps the monthly account recap flush with its plate', async ({ page }) => {
      await page.goto('/budget');
      await settled(page);
      await expectFlushTable(page.getByRole('table', { name: /Valeurs des comptes/ }));
    });

    test('keeps instrument links usable for short names and keyboard navigation', async ({
      page,
    }) => {
      await page.goto('/investments/instruments?q=Sanofi');
      await settled(page);
      const link = page.getByRole('link', { name: "Voir l'instrument Sanofi", exact: true });
      await expect(link).toBeVisible();
      const box = (await link.boundingBox())!;
      expect(box.width).toBeGreaterThanOrEqual(44);
      expect(box.height).toBeGreaterThanOrEqual(44);
      await link.focus();
      await expect(link).toBeFocused();
      await page.keyboard.press('Enter');
      await settled(page);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText('Sanofi');

      // A listed instrument need not be held. Use a position to check the account table.
      await page.goto('/investments?view=asset');
      await settled(page);
      await page
        .getByRole('table', { name: 'Positions par actif', exact: true })
        .getByRole('link', { name: /^Voir l'instrument/ })
        .first()
        .click();
      await settled(page);
      await expectFlushTable(page.getByRole('table', { name: 'Par compte', exact: true }));
    });

    test('keeps the instrument table skeleton on the same plate edges', async ({ page }) => {
      let release!: () => void;
      const pending = new Promise<void>((resolve) => {
        release = resolve;
      });
      await page.route('**/api/v1/instruments?*', async (route) => {
        await pending;
        await route.continue();
      });
      try {
        await page.goto('/investments/instruments');
        const skeleton = page.getByTestId('instruments-skeleton');
        await expect(skeleton).toBeVisible();
        await expectFlushTable(skeleton.getByRole('table'));
        release();
        await settled(page);
        await expect(skeleton).toHaveCount(0);
        await expectFlushTable(page.getByRole('table', { name: 'Instruments', exact: true }));
      } finally {
        release();
        await page.unrouteAll({ behavior: 'wait' });
      }
    });

    test('opens the dated catalogue rules from the product details', async ({ page }) => {
      await page.goto('/parameters/catalog');
      await settled(page);
      await expect(
        page.getByRole('table', { name: 'Produits du catalogue système' }),
      ).toBeVisible();
      await page
        .getByRole('button', { name: /^Détails de / })
        .first()
        .click();
      const dialog = page.getByRole('dialog');
      await expect(dialog.getByRole('table', { name: /^Règles datées de / })).toBeVisible();
    });
  });
}
