import { expect, type Page } from '@playwright/test';
import { settled } from './page';

/** Opens a draft allocation with three categories and entered amounts; never saves it. */
export async function openTransactionSplits(page: Page) {
  await page.goto('/transactions');
  await settled(page);
  await page.getByRole('button', { name: 'Nouvelle transaction' }).click();
  const editor = page.getByRole('dialog', { name: 'Nouvelle transaction' });
  await editor.getByLabel('Compte', { exact: true }).selectOption({ label: 'Compte courant' });
  await editor.getByLabel('Montant', { exact: true }).fill('10.00');
  await editor.getByLabel('Répartir entre plusieurs catégories').check();
  const add = editor.getByRole('button', { name: 'Ajouter une catégorie à la répartition' });
  await add.click();
  await add.click();
  for (const [index, amount] of ['5.00', '3.00', '2.00'].entries()) {
    await editor.getByLabel(`Montant de la ligne ${index + 1}`, { exact: true }).fill(amount);
  }
  await expect(
    editor.getByRole('button', { name: 'Répartir le total à parts égales' }),
  ).toBeEnabled();
  return editor;
}
