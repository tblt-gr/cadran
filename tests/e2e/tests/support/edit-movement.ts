import { expect, type Page } from '@playwright/test';
import { movementRows, settled, uniqueLabel } from './page';

/** Own fixtures keep editing audits independent of the shared ledger's current row order. */
export async function openMovementEditModal(page: Page, kind: 'transaction' | 'transfer') {
  await page.goto('/transactions');
  await settled(page);
  const label = uniqueLabel(`Audit ${kind} modifiable`);
  const isTransfer = kind === 'transfer';
  await page
    .getByRole('button', {
      name: isTransfer ? 'Nouveau virement' : 'Nouvelle transaction',
      exact: true,
    })
    .click();
  await settled(page);
  const creation = page.getByRole('dialog');
  if (isTransfer) {
    await creation.getByLabel('Compte source').selectOption({ label: 'Compte courant' });
    await creation.getByLabel('Compte de destination').selectOption({ label: 'Livret A' });
    await creation.getByLabel('Montant (EUR)').fill('1.00');
  } else {
    await creation.getByLabel('Compte', { exact: true }).selectOption({ label: 'Compte courant' });
    await creation.getByLabel('Montant', { exact: true }).fill('-1.00');
  }
  await creation.getByLabel('Libellé', { exact: true }).fill(label);
  await creation
    .getByRole('button', { name: isTransfer ? 'Créer le virement' : 'Enregistrer', exact: true })
    .click();
  await expect(creation).toBeHidden();
  await settled(page);
  const rows = movementRows(page, label);
  await expect(rows).toHaveCount(isTransfer ? 2 : 1);
  await rows
    .first()
    .getByRole('button', { name: /^Actions de la transaction/ })
    .click();
  await page.getByRole('button', { name: /^Modifier/ }).click();
  await settled(page);
  return page.getByRole('dialog', {
    name: isTransfer ? 'Modifier le virement' : 'Modifier la transaction',
  });
}
