import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';

test.describe('portfolio operation', () => {
  test('records a deposit on the securities account and lists it among the operations', async ({
    page,
  }) => {
    // A distinctive amount finds the new row among the earlier runs' deposits.
    const euros = 10 + Math.floor(Math.random() * 90);
    const cents = String(1 + Math.floor(Math.random() * 98)).padStart(2, '0');

    await page.goto('/');
    await navigateTo(page, 'Comptes');
    await page.getByRole('link', { name: 'Compte-titres', exact: true }).click();
    await settled(page);

    await page
      .getByRole('navigation', { name: 'Sections du portefeuille' })
      .getByRole('link', { name: 'Opérations' })
      .click();
    await settled(page);

    await page.getByRole('button', { name: 'Nouvelle opération' }).click();
    const editor = page.getByRole('dialog', { name: 'Nouvelle opération' });
    await editor.getByLabel('Type').selectOption({ label: 'Versement' });
    await editor.getByLabel('Montant brut').fill(`${euros}.${cents}`);
    await expect(editor.getByText(/Effet sur les espèces/)).toBeVisible();
    await editor.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(editor).toBeHidden();

    await settled(page);
    await expect(
      page.getByRole('row').filter({ hasText: new RegExp(`Versement.*${euros},${cents}\\s€`) }),
    ).not.toHaveCount(0);
  });
});
