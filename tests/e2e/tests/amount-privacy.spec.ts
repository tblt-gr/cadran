import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { auditLayout } from './support/audit';
import { settled } from './support/page';

test.describe('amount privacy', () => {
  for (const width of [360, 1280])
    test(`hides every amount, survives reload and reveals at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
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
      await expect(netWorth.locator('[data-chart-point]')).toHaveCount(0);
      await expect(netWorth.getByRole('table', { includeHidden: true })).toContainText(
        'Montant masqué',
      );
      await expect(netWorth.locator('[data-tone]')).toHaveCount(0);

      await page.reload();
      await settled(page);
      await expect(page.getByRole('region', { name: 'Patrimoine net' })).not.toContainText('€');

      // The mode is global: another screen shows no amount either.
      await page.goto('/accounts');
      await settled(page);
      await expect(page.locator('main')).not.toContainText(/\d[,.]\d{2}\s*€/);
      await expect(page.locator('main [data-sign]')).toHaveCount(0);
      await expect(page.getByRole('link', { name: 'Compte-titres', exact: true })).toBeVisible();
      await page.getByRole('link', { name: 'Compte-titres', exact: true }).focus();
      await page.keyboard.press('Enter');
      await settled(page);
      await page
        .getByRole('navigation', { name: 'Sections du portefeuille' })
        .getByRole('link', { name: 'Opérations' })
        .click();
      await settled(page);
      await page.getByRole('button', { name: 'ETF Monde Démo', exact: true }).first().focus();
      await page.keyboard.press('Enter');
      const detail = page.getByRole('dialog', { name: /^Opération :/ });
      await expect(detail).toBeVisible();
      await expect(detail).toContainText('Montant masqué');
      await expect(detail).not.toContainText(/\d[,.]\d{2}\s*(€|EUR)/);
      await auditAccessibility(page);
      expect(await auditLayout(page, width === 360)).toEqual([]);
      await page.keyboard.press('Escape');
      await expect(detail).toBeHidden();

      await page.goto('/');
      await settled(page);
      await page.getByRole('button', { name: 'Afficher les montants' }).click();
      await expect(page.getByRole('region', { name: 'Patrimoine net' })).toContainText('€');
      await expect(page.locator('[data-chart-point]').first()).toBeVisible();
    });
});
