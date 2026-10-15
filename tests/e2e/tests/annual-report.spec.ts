import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';

test.describe('annual report', () => {
  test('lists the months with a link to the source of each cell', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto('/');
    await navigateTo(page, 'Rapports');
    await page
      .getByRole('navigation', { name: 'Type de rapport' })
      .getByRole('link', { name: 'Annuel', exact: true })
      .click();
    await settled(page);

    await expect(page).toHaveURL(/\/reports\/annual\/\d{4}$/);
    const table = page.getByRole('table', { name: /^Rapport annuel \d{4}$/ });
    await expect(table).toBeVisible();
    await expect(table.getByRole('columnheader', { name: 'Revenus de trésorerie' })).toBeVisible();

    const source = table.getByRole('row').nth(1).getByRole('link').first();
    await expect(source).toHaveAttribute('href', /\/reports\?month=\d{4}-\d{2}$/);
    await source.click();
    await settled(page);

    await expect(page.getByRole('heading', { level: 1, name: /^Rapport mensuel / })).toBeVisible();
    expect(pageErrors).toEqual([]);
  });

  test('switches to the multi-year report', async ({ page }) => {
    await page.goto('/reports');
    await settled(page);

    await page
      .getByRole('navigation', { name: 'Type de rapport' })
      .getByRole('link', { name: 'Pluriannuel' })
      .click();
    await settled(page);

    await expect(page).toHaveURL(/\/reports\/all-years$/);
    await expect(page.getByRole('table').first()).toBeVisible();
  });
});
