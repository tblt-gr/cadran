import { expect, test } from '@playwright/test';
import { openTransactionSplits } from './support/transaction-splits';
import { movementRows, settled, uniqueLabel } from './support/page';

test.describe('transaction entry', () => {
  test('creates a transaction with a category created on the fly', async ({ page }) => {
    const label = uniqueLabel('E2E achat');
    const category = uniqueLabel('E2E catégorie');

    await page.goto('/');
    await page.goto('/transactions');
    await settled(page);

    await page.getByRole('button', { name: 'Nouvelle transaction' }).click();
    const editor = page.getByRole('dialog', { name: 'Nouvelle transaction' });
    await editor.getByLabel('Compte', { exact: true }).selectOption({ label: 'Compte courant' });
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
    const picker = editor.getByRole('combobox', { name: 'Catégorie' });
    await expect(picker).toBeFocused();
    await picker.click();
    await expect(picker).toHaveValue('');
    await picker.fill('Recherche abandonnée');
    await picker.press('Escape');
    await expect(editor).toBeVisible();
    await expect(picker).toHaveValue(category);
    await picker.click();
    await picker.fill('Autre recherche abandonnée');
    await editor.getByLabel('Libellé').click();
    await expect(picker).toHaveValue(category);

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

test('redistributes a draft allocation and restores it by keyboard', async ({ page }) => {
  const editor = await openTransactionSplits(page);
  const split = editor.getByRole('button', { name: 'Répartir le total à parts égales' });
  await expect(
    editor.getByRole('button', { name: 'Ajouter une catégorie à la répartition' }),
  ).toHaveText('');
  await expect(editor.getByRole('button', { name: 'Annuler la répartition égale' })).toHaveCount(0);
  await expect(split).toHaveText('Parts égales');
  await split.focus();
  await page.keyboard.press('Enter');
  await expect(editor.getByLabel('Montant de la ligne 1', { exact: true })).toHaveValue('3.34');
  await expect(editor.getByLabel('Montant de la ligne 2', { exact: true })).toHaveValue('3.33');
  await expect(editor.getByLabel('Montant de la ligne 3', { exact: true })).toHaveValue('3.33');
  await page.keyboard.press('Tab');
  await expect(editor.getByRole('button', { name: 'Annuler la répartition égale' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(split).toBeFocused();
  await expect(editor.getByRole('button', { name: 'Annuler la répartition égale' })).toHaveCount(0);
  await expect(editor.getByLabel('Montant de la ligne 1', { exact: true })).toHaveValue('5.00');
  await expect(editor.getByLabel('Montant de la ligne 2', { exact: true })).toHaveValue('3.00');
  await expect(editor.getByLabel('Montant de la ligne 3', { exact: true })).toHaveValue('2.00');
});
