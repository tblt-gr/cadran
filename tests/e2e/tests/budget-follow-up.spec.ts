import { expect, test } from '@playwright/test';
import { settled } from './support/page';
import { auditLayout } from './support/audit';
import { auditAccessibility } from './support/a11y';

for (const width of [360, 390, 768, 1280, 1440]) {
  test(`budget and register follow-up at ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/budget');
    await settled(page);
    const monthPath = new URL(page.url()).pathname;
    const navigation = page.getByRole('navigation', { name: 'Budget et transactions' });
    await expect(navigation.getByRole('link')).toHaveText([
      'Suivi du budget',
      'Transactions',
      'Catégories',
      'Récurrences',
    ]);
    await expect(navigation.getByRole('link', { name: 'Suivi du budget' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    // The month's actions share the title line; the month bar belongs to the budget tab.
    const months = page.getByRole('navigation', { name: 'Mois du budget', exact: true });
    // Movements are added from the panels: the header keeps goals and closure only.
    await expect(page.getByRole('button', { name: 'Ajouter une transaction' })).toHaveCount(0);
    const closure = page.getByRole('button', { name: 'Clôturer ce mois', exact: true });
    const title = page.getByRole('heading', { level: 1 });
    await expect(months).toBeVisible();
    await expect(closure).toBeVisible();
    await expect(page.getByRole('button', { name: 'Clôturer ce mois', exact: true })).toHaveText(
      '',
    );
    await expect(page.getByRole('button', { name: 'Autres actions' })).toHaveCount(0);
    const tabsBox = (await navigation.boundingBox())!;
    expect((await months.boundingBox())!.y).toBeGreaterThanOrEqual(tabsBox.y + tabsBox.height);
    expect((await closure.boundingBox())!.y).toBeLessThan(tabsBox.y);
    if (width >= 768) {
      const titleBox = (await title.boundingBox())!;
      const closureBox = (await closure.boundingBox())!;
      expect(
        Math.abs(closureBox.y + closureBox.height / 2 - (titleBox.y + titleBox.height / 2)),
      ).toBeLessThan(24);
    }
    // Ledger and analysis are two separate sections without a visible title or disclosure.
    await expect(
      page.getByRole('region', { name: 'Livre des mouvements et virements', exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole('region', { name: 'Analyse du mois et récapitulatifs', exact: true }),
    ).toBeVisible();
    await expect(page.getByText('Livre des mouvements et virements', { exact: true })).toHaveCount(
      0,
    );
    await expect(page.locator('summary', { hasText: 'Livre des mouvements' })).toHaveCount(0);
    expect(await auditLayout(page, false)).toEqual([]);
    await auditAccessibility(page);
    await page.screenshot({
      path: testInfo.outputPath(`budget-${width}.png`),
      fullPage: true,
      animations: 'disabled',
    });
    await page.screenshot({
      path: testInfo.outputPath(`budget-viewport-${width}.png`),
      animations: 'disabled',
    });
    await navigation.getByRole('link', { name: 'Transactions', exact: true }).click();
    await settled(page);
    await expect(page).toHaveURL(/transactions\?period=month&month=\d{4}-\d{2}&budgetMonth=/);
    await page.screenshot({
      path: testInfo.outputPath(`register-viewport-${width}.png`),
      animations: 'disabled',
    });
    // Month and views are filters: no stepper and no duplicated quick views above the list.
    await expect(page.getByRole('button', { name: 'Mois précédent' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'En attente', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Autres actions' })).toHaveCount(0);
    expect(await auditLayout(page, false)).toEqual([]);
    await page.screenshot({
      path: testInfo.outputPath(`transactions-${width}.png`),
      fullPage: true,
      animations: 'disabled',
    });
    await page
      .getByRole('navigation', { name: 'Budget et transactions' })
      .getByRole('link', { name: 'Suivi du budget' })
      .click();
    await settled(page);
    expect(new URL(page.url()).pathname).toBe(monthPath);
    await page.goBack();
    await settled(page);
    expect(new URL(page.url()).pathname).toBe('/transactions');
  });
}

test('direct register URLs keep all-history search and show a filter-specific empty state', async ({
  page,
}) => {
  await page.goto('/transactions?q=no-matching-transaction-unique-refactor');
  await settled(page);
  await expect(
    page.getByRole('heading', { name: 'Aucun mouvement ne correspond aux filtres' }),
  ).toBeVisible();
  await expect(
    page.getByRole('button', { name: 'Enregistrer la première transaction' }),
  ).toHaveCount(0);
  expect(new URL(page.url()).searchParams.has('period')).toBe(false);
  await page.getByRole('button', { name: 'Tout effacer', exact: true }).last().click();
  await settled(page);
  await expect(page.locator('li[data-state]').first()).toBeVisible();
});
