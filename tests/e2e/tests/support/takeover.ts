import { expect, type Page } from '@playwright/test';
import { settled, uniqueLabel } from './page';

/**
 * Creates an empty securities account through the wizard, as an owner does: the app then lands on
 * its Positions tab, whose empty state offers the takeover. The demo securities accounts already
 * hold positions, so none of them can take over, hence a fresh account kept out of the net worth.
 */
export async function openTakeoverModal(page: Page): Promise<void> {
  await page.goto('/accounts');
  await settled(page);
  await page.getByRole('button', { name: 'Nouveau compte' }).click();
  const wizard = page.getByRole('dialog', { name: 'Nouveau compte' });
  await wizard.getByRole('radio', { name: /Compte-titres ordinaire/ }).check();
  await wizard.getByRole('button', { name: 'Continuer' }).click();
  await wizard.getByLabel('Libellé', { exact: true }).fill(uniqueLabel('E2E reprise'));
  await wizard
    .getByLabel('Date d’ouverture')
    .fill(new Intl.DateTimeFormat('sv-SE', { timeZone: 'UTC' }).format(new Date()));
  await wizard.getByLabel('Compter ce compte dans le patrimoine net').uncheck();
  await wizard.getByRole('button', { name: 'Continuer' }).click();
  await wizard.getByRole('button', { name: /Créer le compte|Enregistrer/ }).click();
  await expect(page).toHaveURL(/\/portfolios\/[0-9a-f-]{36}\?tab=positions/);
  await settled(page);
  await page.getByRole('button', { name: 'Saisir mes positions' }).first().click();
  await expect(page.getByRole('dialog', { name: 'Saisir mes positions' })).toBeVisible();
}
