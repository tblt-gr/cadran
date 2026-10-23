import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';

test.describe('monthly budget', () => {
  test('opens the current month from the navigation and reads a closed month', async ({ page }) => {
    await page.goto('/');
    await navigateTo(page, 'Budget');

    await expect(page).toHaveURL(/\/budget\/\d{4}-\d{2}$/);
    await expect(page.getByRole('heading', { level: 1, name: /^Budget de / })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Revenus', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Dépenses', exact: true })).toBeVisible();

    // The previous month of the demo ledger is closed: the page says so instead of hiding it.
    const months = page.getByRole('navigation', { name: 'Mois du budget' });
    const current = await months.getByRole('link').count();
    await months
      .getByRole('link')
      .nth(current - 2)
      .click();
    await settled(page);

    await expect(
      page.getByRole('status').filter({ hasText: 'Cette période est clôturée.' }),
    ).toBeVisible();
    await expect(
      page.getByRole('button', { name: 'Ajouter une dépense', exact: true }),
    ).toBeDisabled();
    await expect(
      page.getByRole('button', { name: 'Ajouter un revenu', exact: true }),
    ).toBeDisabled();
  });

  test('redirects a direct load of /budget to the current month and renders it', async ({
    page,
  }) => {
    await page.goto('/budget');
    await settled(page);

    await expect(page).toHaveURL(/\/budget\/\d{4}-\d{2}$/);
    await expect(page.getByRole('heading', { level: 1, name: /^Budget de / })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Revenus', exact: true })).toBeVisible();
  });

  test('reaches the budget plans from the month page', async ({ page }) => {
    await page.goto('/');
    await navigateTo(page, 'Budget');

    await page.getByRole('link', { name: 'Gérer les plans et objectifs' }).click();
    await settled(page);

    await expect(page).toHaveURL(/\/budget\/plans$/);
    await expect(page.getByRole('main')).toBeVisible();
  });
});
