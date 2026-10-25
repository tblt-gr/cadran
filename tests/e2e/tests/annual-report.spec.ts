import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';

test.describe('annual report', () => {
  test('lists the months with a link to the source of each cell', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto('/');
    await navigateTo(page, 'Budget');
    await page
      .getByRole('navigation', { name: 'Mois du budget' })
      .getByRole('link', { name: 'Rapport', exact: true })
      .click();
    await settled(page);

    await expect(page).toHaveURL(/\/budget\/\d{4}$/);
    const table = page.getByRole('table', { name: /^Rapport \d{4}$/ });
    await expect(table).toBeVisible();
    await expect(table.getByRole('columnheader', { name: 'Revenus' })).toBeVisible();

    const source = table.getByRole('row').nth(1).getByRole('link').first();
    await expect(source).toHaveAttribute('href', /\/budget\/\d{4}\/\d{2}$/);
    await source.click();
    await settled(page);

    await expect(page.getByRole('heading', { level: 1, name: /^Budget de / })).toBeVisible();
    expect(pageErrors).toEqual([]);
  });

  test('switches to the multi-year report and back', async ({ page }) => {
    await page.goto('/budget');
    await settled(page);
    await page
      .getByRole('navigation', { name: 'Mois du budget' })
      .getByRole('link', { name: 'Rapport', exact: true })
      .click();
    await settled(page);

    await page
      .getByRole('group', { name: 'Type de rapport' })
      .getByRole('button', { name: 'Pluriannuel' })
      .click();
    await settled(page);

    await expect(page).toHaveURL(/\/budget\/\d{4}\?view=multi/);
    await expect(page.getByRole('table').first()).toBeVisible();

    await page
      .getByRole('group', { name: 'Type de rapport' })
      .getByRole('button', { name: 'Annuel', exact: true })
      .click();
    await settled(page);
    await expect(page).toHaveURL(/\/budget\/\d{4}$/);
  });

  test('serves no reports route any more', async ({ page }) => {
    await page.goto('/reports');
    await settled(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Page introuvable' })).toBeVisible();
  });
});
