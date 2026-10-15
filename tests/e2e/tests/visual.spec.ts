import { expect, test } from '@playwright/test';
import { settled } from './support/page';
import { visualMasks } from './support/visual';

// Pixels are compared against baselines generated in the e2e image (`make e2e-update-snapshots`);
// the two projects (desktop and a 360 by 740 phone) each own their set.
test.describe('visual comparison', () => {
  test('dashboard', async ({ page }) => {
    await page.goto('/');
    await settled(page);
    // The last panels to paint: the allocation card and the month's metrics.
    await expect(page.getByRole('heading', { name: 'Allocation' })).toBeVisible();
    await expect(page.getByText('Mois en cours')).toHaveCount(3);

    await expect(page).toHaveScreenshot('dashboard.png', { mask: visualMasks(page) });
  });

  test('transaction list', async ({ page }) => {
    await page.goto('/transactions');
    await settled(page);
    await expect(page.getByRole('table').locator('tbody tr').first()).toBeVisible();

    // The list is the demo ledger itself, which rolls with the calendar: only its frame is compared.
    await expect(page).toHaveScreenshot('transactions.png', {
      mask: [...visualMasks(page), page.getByRole('table').locator('tbody')],
    });
  });

  test('account creation modal', async ({ page }) => {
    await page.goto('/accounts');
    await settled(page);

    await page.getByRole('button', { name: 'Nouveau compte' }).click();
    const wizard = page.getByRole('dialog', { name: 'Nouveau compte' });
    await expect(wizard.getByText('Étape 1 sur 3')).toBeVisible();

    // The page behind the modal is the ever-growing account list: only the dialog is compared.
    await expect(wizard).toHaveScreenshot('account-creation.png', { mask: visualMasks(wizard) });
  });
});
