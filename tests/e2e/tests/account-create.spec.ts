import { expect, test } from '@playwright/test';
import { navigateTo, settled, uniqueLabel } from './support/page';

test.describe('account creation', () => {
  test('creates a manual account outside the net worth through the three steps', async ({
    page,
  }) => {
    const label = uniqueLabel('E2E compte');

    await page.goto('/');
    await navigateTo(page, 'Comptes');

    await page.getByRole('button', { name: 'Nouveau compte' }).click();
    const wizard = page.getByRole('dialog', { name: 'Nouveau compte' });
    await expect(wizard.getByText('Étape 1 sur 3')).toBeVisible();
    await wizard.getByRole('button', { name: 'Continuer' }).click();

    await expect(wizard.getByText('Étape 2 sur 3')).toBeVisible();
    await wizard.getByLabel('Libellé').fill(label);
    await wizard.getByLabel('Date d’ouverture').fill(today());
    // Kept out of the net worth so repeated runs never change the dashboard figures.
    await wizard.getByLabel('Compter ce compte dans le patrimoine net').uncheck();
    await wizard.getByRole('button', { name: 'Continuer' }).click();

    await expect(wizard.getByText('Étape 3 sur 3')).toBeVisible();
    await expect(wizard).toContainText(label);
    await wizard.getByRole('button', { name: /Créer le compte|Enregistrer/ }).click();
    await expect(wizard).toBeHidden();

    await settled(page);
    await expect(page.getByRole('link', { name: label })).toBeVisible();
  });

  test('requires a label before leaving the account step', async ({ page }) => {
    await page.goto('/accounts');
    await settled(page);

    await page.getByRole('button', { name: 'Nouveau compte' }).click();
    const wizard = page.getByRole('dialog', { name: 'Nouveau compte' });
    await wizard.getByRole('button', { name: 'Continuer' }).click();
    await wizard.getByRole('button', { name: 'Continuer' }).click();

    await expect(wizard.getByText('Étape 2 sur 3')).toBeVisible();
  });
});

/** Today in the workspace's zone, the latest opening date the form accepts. */
function today(): string {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Paris' }).format(new Date());
}
