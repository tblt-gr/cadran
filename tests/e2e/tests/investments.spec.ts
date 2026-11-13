import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { auditLayout } from './support/audit';
import { settled } from './support/page';

for (const width of [1280, 360]) {
  test(`total-valued accounts keep their notices without an empty-position panel at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.route('**/api/v1/holdings?*', async (route) => {
      const response = await route.fetch();
      const holdings = await response.json();
      await route.fulfill({
        json: {
          ...holdings,
          items: [],
          unsplitAccounts: [
            {
              accountId: '00000000-0000-7000-8000-000000000001',
              label: 'Compte global A',
              reason: 'TOTAL_VALUATION',
              securitiesValue: '800',
            },
            {
              accountId: '00000000-0000-7000-8000-000000000002',
              label: 'Compte global B',
              reason: 'TOTAL_VALUATION',
              securitiesValue: '200',
            },
          ],
        },
      });
    });
    await page.goto('/investments?view=asset');
    await settled(page);
    // The notices are grouped in one closed disclosure with their count.
    await expect(page.getByText(/Compte global A est valorisé en total/)).toBeHidden();
    await page.getByText(/^\d+ avis$/).click();
    await expect(page.getByText(/Compte global A est valorisé en total/)).toBeVisible();
    await expect(page.getByText(/Compte global B est valorisé en total/)).toBeVisible();
    await expect(page.getByText('Aucune position détenue')).toHaveCount(0);
    expect(await auditLayout(page, false)).toEqual([]);
    await auditAccessibility(page);
  });
}
