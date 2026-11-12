import { expect, type Page } from '@playwright/test';
import { movementRows, settled, uniqueLabel } from './page';

/** Own fixtures keep editing audits independent of the shared ledger's current row order. */
export async function openMovementEditModal(
  page: Page,
  kind: 'transaction' | 'transfer' | 'refund',
) {
  await page.goto('/transactions');
  await settled(page);
  let label = uniqueLabel(`Audit ${kind} modifiable`);
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
  if (kind === 'refund') {
    await page.getByRole('button', { name: /^Créer un remboursement/ }).click();
    await settled(page);
    const refund = page.getByRole('dialog');
    await refund
      .getByRole('combobox', { name: 'Compte', exact: true })
      .selectOption({ label: 'Compte courant' });
    // The ledger runs on Paris time: a UTC date is the day before between 00:00 and 02:00 there.
    const day = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Paris' }).format(new Date());
    await refund.getByLabel('Date comptable').fill(day);
    label = uniqueLabel('Audit refund correction');
    await refund.getByLabel('Libellé', { exact: true }).fill(label);
    const refundAmount = refund.getByLabel('Montant', { exact: true });
    await refundAmount.fill('0.');
    await expect(refundAmount).toHaveValue('0.');
    await expect(
      refund.getByRole('button', { name: 'Créer le remboursement', exact: true }),
    ).toBeDisabled();
    await refundAmount.fill('0,6');
    await expect(refundAmount).toHaveValue('0,6');
    await expect(
      refund.getByRole('button', { name: 'Créer le remboursement', exact: true }),
    ).toBeEnabled();
    await refund.getByRole('button', { name: 'Créer le remboursement', exact: true }).click();
    await expect(refund).toBeHidden();
    await settled(page);
    await movementRows(page, label)
      .getByRole('button', { name: /^Actions de la transaction/ })
      .click();
  }
  await page.getByRole('button', { name: /^Modifier/ }).click();
  await settled(page);
  return page.getByRole('dialog', {
    name: isTransfer ? 'Modifier le virement' : 'Modifier la transaction',
  });
}
