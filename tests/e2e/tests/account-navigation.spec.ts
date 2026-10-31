import { expect, test } from '@playwright/test';
import { resolveFixtures } from './support/a11y';
import { settled } from './support/page';

for (const width of [1440, 360]) {
  test(`unified account navigation and saved performance columns at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    const fixtures = await resolveFixtures(page);
    await page.goto(fixtures.account);
    await settled(page);
    const titleLeft = await page
      .getByRole('heading', { level: 1 })
      .evaluate((element) => element.getBoundingClientRect().left);
    const contentLeft = await page
      .getByRole('main')
      .evaluate((element) => element.getBoundingClientRect().left);
    expect(titleLeft).toBeCloseTo(contentLeft, 1);
    const cursor = page.getByRole('slider', { name: 'Historique du solde' });
    await expect(cursor).toBeVisible();
    await cursor.focus();
    await cursor.press('End');
    const latestDate = await cursor.getAttribute('aria-valuetext');
    await cursor.press('Home');
    await expect(cursor).not.toHaveAttribute('aria-valuetext', latestDate!);
    await expect(page.getByText('Identité', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Voir les règles' })).toHaveText('');
    await page.screenshot({
      path: `${process.env.E2E_OUTPUT_DIR ?? 'test-results'}/current-account-${width}.png`,
      fullPage: true,
    });
    const accountNav = page.getByRole('navigation', { name: 'Sections du compte' });
    await expect(accountNav.getByRole('link').first()).toHaveText('Compte');
    await expect(page.getByRole('heading', { name: 'Mouvements', exact: true })).toHaveCount(0);
    await accountNav.getByRole('link', { name: 'Opérations' }).click();
    await settled(page);
    await expect(page.getByRole('heading', { name: 'Mouvements', exact: true })).toHaveClass(
      'sr-only',
    );
    await expect(page.getByLabel('Du', { exact: true })).toHaveCount(1);
    await expect(page.getByLabel('Au', { exact: true })).toHaveCount(1);
    await expect(page.getByLabel('Instrument', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Types d’opération Tous les types' }).click();
    const expense = page.getByRole('option', { name: 'Dépense', exact: true });
    await expense.click();
    await expect(expense).toHaveAttribute('aria-selected', 'true');
    await expense.click();
    await expense.press('Escape');
    await settled(page);
    await page.screenshot({
      path: `${process.env.E2E_OUTPUT_DIR ?? 'test-results'}/operations-${width}.png`,
      fullPage: true,
    });

    await page.goto(fixtures.portfolio);
    await settled(page);
    const nav = page.getByRole('navigation', { name: 'Sections du portefeuille' });
    await expect(nav.getByRole('link').first()).toHaveText('Compte');
    await expect(nav.getByRole('link').nth(1)).toHaveText('Opérations');
    await expect(nav.getByRole('link', { name: 'Espèces', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Évolution de la valeur' })).toBeVisible();
    await expect(page.getByLabel('Situation au', { exact: true })).toHaveCount(1);
    await expect(page.getByRole('button', { name: /Aujourd/ })).toHaveCount(0);
    await page.screenshot({
      path: `${process.env.E2E_OUTPUT_DIR ?? 'test-results'}/account-${width}.png`,
      fullPage: true,
    });
    await nav.getByRole('link', { name: 'Opérations' }).click();
    await settled(page);
    await expect(page.getByLabel('Situation au', { exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Du', { exact: true })).toHaveCount(1);
    await expect(page.getByLabel('Au', { exact: true })).toHaveCount(1);
    await expect(page.getByLabel('Instrument', { exact: true })).toHaveCount(1);
    await page.getByRole('button', { name: 'Types d’opération Tous les types' }).click();
    const purchase = page.getByRole('option', { name: 'Achat', exact: true });
    await purchase.click();
    await expect(purchase).toHaveAttribute('aria-selected', 'true');
    await purchase.click();
    await purchase.press('Escape');
    await settled(page);
    await page.screenshot({
      path: `${process.env.E2E_OUTPUT_DIR ?? 'test-results'}/investment-operations-${width}.png`,
      fullPage: true,
    });
    await nav.getByRole('link', { name: 'Performance' }).click();
    await settled(page);
    await expect(page.getByRole('heading', { name: 'Évolution de la valeur' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Colonnes affichées' }).click();
    await page.getByRole('menuitemcheckbox', { name: 'Taxes', exact: true }).click();
    await page.reload();
    await settled(page);
    await page.getByRole('button', { name: 'Colonnes affichées' }).click();
    await expect(
      page.getByRole('menuitemcheckbox', { name: 'Taxes', exact: true }),
    ).toHaveAttribute('aria-checked', 'false');
    await page.screenshot({
      path: `${process.env.E2E_OUTPUT_DIR ?? 'test-results'}/performance-${width}.png`,
      fullPage: true,
    });
  });
}

test('passbook account does not present a balance-based estimate as earned interest', async ({
  page,
}) => {
  await page.goto('/accounts');
  await settled(page);
  const href = await page
    .getByRole('link', { name: /Livret/ })
    .first()
    .getAttribute('href');
  expect(href).toBeTruthy();
  await page.goto(href!);
  await settled(page);
  await expect(page.getByRole('heading', { name: 'Historique du solde' })).toBeVisible();
  await expect(page.getByText('Taux dû au titulaire', { exact: true })).toHaveCount(0);
  await expect(page.getByText(/Sur le solde observé/)).toHaveCount(0);
  await expect(page.getByText(/intérêt calculé/)).toHaveCount(0);
});
