import { expect, test } from '@playwright/test';
import { movementRows, settled, uniqueLabel } from './support/page';

test.describe('internal transfer', () => {
  test('records both legs of a transfer between two accounts', async ({ page }) => {
    const label = uniqueLabel('E2E virement');

    await page.goto('/transactions');
    await settled(page);

    await page.getByRole('button', { name: 'Nouveau virement' }).click();
    const editor = page.getByRole('dialog', { name: 'Nouveau virement' });
    await editor.getByLabel('Compte source').selectOption({ label: 'Compte courant' });
    await editor.getByLabel('Compte de destination').selectOption({ label: 'Livret A' });
    await editor.getByLabel('Montant (EUR)').fill('25.00');
    await editor.getByLabel('Libellé').fill(label);
    await editor.getByRole('button', { name: 'Créer le virement' }).click();
    await expect(editor).toBeHidden();

    // A transfer is atomic: the same label carries an outflow and an inflow, and neither is a
    // spending or an income.
    const legs = movementRows(page, label);
    await expect(legs).toHaveCount(2);
    await expect(legs.getByRole('img', { name: 'Sortie de 25,00 €' })).toBeVisible();
    await expect(legs.getByRole('img', { name: 'Entrée de 25,00 €' })).toBeVisible();
  });

  test('refuses a transfer between an account and itself', async ({ page }) => {
    await page.goto('/transactions');
    await settled(page);

    await page.getByRole('button', { name: 'Nouveau virement' }).click();
    const editor = page.getByRole('dialog', { name: 'Nouveau virement' });
    await editor.getByLabel('Compte source').selectOption({ label: 'Compte courant' });
    await editor.getByLabel('Compte de destination').selectOption({ label: 'Compte courant' });
    await editor.getByLabel('Montant (EUR)').fill('5.00');
    await editor.getByRole('button', { name: 'Créer le virement' }).click();

    await expect(editor).toBeVisible();
    await expect(editor.getByLabel('Compte de destination')).toHaveAttribute(
      'aria-invalid',
      'true',
    );
  });
});
