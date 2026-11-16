import { expect, test } from '@playwright/test';
import { settled } from './support/page';

test.describe('amount privacy', () => {
  test('hides every amount from the synthese, survives a reload and shows them again', async ({
    page,
  }) => {
    await page.goto('/');
    await settled(page);

    const netWorth = page.getByRole('region', { name: 'Patrimoine net' });
    await expect(netWorth).toContainText('€');

    await page.getByRole('button', { name: 'Masquer les montants' }).click();
    const toggle = page.getByRole('button', { name: 'Afficher les montants' });
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await expect(toggle).toBeFocused();
    await expect(netWorth).not.toContainText('€');
    await expect(netWorth).toContainText('••••••');

    await page.reload();
    await settled(page);
    await expect(page.getByRole('region', { name: 'Patrimoine net' })).not.toContainText('€');

    // The mode is global: another screen shows no amount either.
    await page.goto('/accounts');
    await settled(page);
    await expect(page.locator('main')).not.toContainText(/\d[,.]\d{2}\s*€/);

    await page.goto('/');
    await settled(page);
    await page.getByRole('button', { name: 'Afficher les montants' }).click();
    await expect(page.getByRole('region', { name: 'Patrimoine net' })).toContainText('€');
  });
});
