import { expect, test } from '@playwright/test';
import { movementRows, navigateTo, settled, uniqueLabel } from './support/page';

test.describe('transaction entry', () => {
  test('creates a transaction with a category created on the fly', async ({ page }) => {
    const label = uniqueLabel('E2E achat');
    const category = uniqueLabel('E2E catégorie');

    await page.goto('/');
    await navigateTo(page, 'Transactions');

    await page.getByRole('button', { name: 'Nouvelle transaction' }).click();
    const editor = page.getByRole('dialog', { name: 'Nouvelle transaction' });
    await editor.getByLabel('Montant').fill('-12.34');
    await editor.getByLabel('Libellé').fill(label);

    await editor.getByRole('combobox', { name: 'Catégorie' }).fill(category);
    await page.getByRole('option', { name: `Créer « ${category} »` }).click();

    // The nested dialog keeps the transaction draft untouched while the category is created.
    const quick = page.getByRole('dialog', { name: 'Nouvelle catégorie' });
    await quick.getByRole('button', { name: 'Créer', exact: true }).click();
    await expect(quick).toBeHidden();
    await expect(editor.getByRole('combobox', { name: 'Catégorie' })).toHaveValue(category);
    await expect(editor.getByLabel('Montant')).toHaveValue('-12.34');

    await editor.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(editor).toBeHidden();

    const row = movementRows(page, label);
    await expect(row).toBeVisible();
    await expect(row.getByRole('img', { name: 'Sortie de 12,34 €' })).toBeVisible();
    await expect(row).toContainText(category);
  });

  test('refuses an empty amount without closing the form', async ({ page }) => {
    await page.goto('/transactions');
    await settled(page);

    await page.getByRole('button', { name: 'Nouvelle transaction' }).click();
    const editor = page.getByRole('dialog', { name: 'Nouvelle transaction' });
    await editor.getByLabel('Libellé').fill(uniqueLabel('E2E sans montant'));
    await editor.getByRole('button', { name: 'Enregistrer' }).click();

    await expect(editor).toBeVisible();
    await expect(editor.getByLabel('Montant')).toHaveAttribute('aria-invalid', 'true');
  });
});
