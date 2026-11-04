import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
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

test('a transfer from an account locks its source and focuses the destination', async ({
  page,
}) => {
  const label = uniqueLabel('E2E compte virement');
  await page.goto('/accounts');
  await settled(page);
  const href = await page
    .getByRole('link', { name: /Livret A/ })
    .first()
    .getAttribute('href');
  expect(href).toBeTruthy();
  await page.goto(href!);
  await settled(page);
  await page.getByRole('button', { name: /^Actions du compte/ }).click();
  const trigger = page.getByRole('button', { name: 'Nouveau virement', exact: true });
  await trigger.focus();
  await page.keyboard.press('Enter');
  const editor = page.getByRole('dialog', { name: 'Nouveau virement' });
  const source = editor.getByLabel('Compte source');
  const destination = editor.getByLabel('Compte de destination');
  await expect(source).toBeDisabled();
  await expect(source.locator('option:checked')).toHaveText('Livret A');
  await expect(destination).toBeEnabled();
  await expect(destination).toBeFocused();
  // Seeded accounts have no primary; wait for the loaded choices before checking the default.
  await expect(
    destination.getByRole('option', { name: 'Compte courant', exact: true }),
  ).toHaveCount(1);
  await expect(destination).toHaveValue('');
  await expect(destination.getByRole('option', { name: 'Livret A', exact: true })).toHaveCount(0);
  await auditAccessibility(page);
  await destination.selectOption('');
  await editor.getByRole('button', { name: 'Créer le virement' }).click();
  await expect(destination).toHaveAttribute('aria-invalid', 'true');
  await destination.selectOption({ label: 'Compte courant' });
  await editor.getByLabel('Montant (EUR)').fill('5.00');
  await editor.getByLabel('Libellé').fill(label);
  await editor.getByRole('button', { name: 'Créer le virement' }).click();
  await expect(editor).toBeHidden();
  await page
    .getByRole('navigation', { name: 'Sections du compte' })
    .getByRole('link', { name: 'Opérations' })
    .click();
  await settled(page);
  await expect(
    movementRows(page, label).getByRole('img', { name: 'Sortie de 5,00 €' }),
  ).toBeVisible();
});
