import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';

test.describe('dashboard', () => {
  test('shows the net worth with its quality, the allocation and the monthly indicators', async ({
    page,
  }) => {
    await page.goto('/');
    await settled(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Synthèse' })).toBeVisible();

    const netWorth = page.getByRole('region', { name: 'Patrimoine net' });
    await expect(netWorth).toContainText('€');
    // Provenance stays visible next to the figure: a freshness badge and the valuation day.
    await expect(netWorth).toContainText(/Valorisation|à jour|Valorisations/);
    await expect(netWorth).toContainText(/Au \d{1,2} \p{L}+ \d{4}/u);

    await expect(page.getByRole('region', { name: 'Allocation' })).toBeVisible();
    for (const indicator of ['Revenus', 'Dépenses', 'Taux d’épargne']) {
      await expect(page.getByRole('region', { name: indicator })).toBeVisible();
    }
  });

  test('offers the net worth curve as a table', async ({ page }) => {
    await page.goto('/');
    await settled(page);

    const figure = page.getByRole('figure', { name: 'Évolution du patrimoine' });
    await figure.getByText('Voir les données sous forme de tableau').click();

    await expect(figure.getByRole('table')).toBeVisible();
  });

  test('navigates away and back through the breadcrumb', async ({ page }) => {
    await page.goto('/');
    await settled(page);

    await navigateTo(page, 'Transactions');
    await expect(page.getByRole('heading', { level: 1, name: 'Transactions' })).toBeVisible();

    await page
      .getByRole('navigation', { name: 'Fil d’Ariane' })
      .getByRole('link', { name: 'Accueil' })
      .click();
    await settled(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Synthèse' })).toBeVisible();
  });
});
