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
    const created = page.getByRole('link', { name: label, exact: true });
    const more = page
      .getByRole('region', { name: 'Sans groupe', exact: true })
      .getByRole('button', { name: 'Afficher plus de comptes', exact: true });
    // Repeated runs can put the new account beyond the first page of its group.
    await expect
      .poll(async () => {
        if (await created.count()) return true;
        if (await more.isVisible()) {
          await more.click();
          await settled(page);
        }
        return (await created.count()) > 0;
      })
      .toBe(true);
    await expect(created).toBeVisible();
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

/** Today as the domain reads it, the latest opening date the form accepts. */
function today(): string {
  // UTC, not Paris: between 22:00 and midnight UTC Paris is a day ahead and reads as the future.
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'UTC' }).format(new Date());
}
