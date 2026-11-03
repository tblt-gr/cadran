import { expect, test } from '@playwright/test';
import { settled } from './support/page';
import { ownerStorageState } from './support/session';

for (const width of [360, 1280]) {
  test(`category advanced checkbox labels remain horizontal at ${width}px`, async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
      ignoreHTTPSErrors: true,
      storageState: ownerStorageState,
      viewport: { width, height: 800 },
    });
    try {
      const page = await context.newPage();
      await page.goto('/transactions/categories');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle catégorie' }).click();
      const dialog = page.getByRole('dialog', { name: 'Nouvelle catégorie' });
      await dialog.locator('summary').filter({ hasText: 'Champs avancés' }).click();
      const checks = dialog.getByRole('checkbox');
      expect(await checks.count()).toBeGreaterThan(1);
      for (const checkbox of await checks.all()) {
        expect(
          await checkbox
            .locator('xpath=..')
            .evaluate((label) => getComputedStyle(label).flexDirection),
        ).toBe('row');
      }
    } finally {
      await context.close();
    }
  });

  test(`recurrence category picker keeps its selected label inside the pill at ${width}px`, async ({
    browser,
  }) => {
    const context = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
      ignoreHTTPSErrors: true,
      storageState: ownerStorageState,
      viewport: { width, height: 800 },
    });
    try {
      const page = await context.newPage();
      await page.goto('/transactions/recurrences');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle récurrence' }).click();
      const dialog = page.getByRole('dialog', { name: 'Nouvelle récurrence' });
      const picker = dialog.getByRole('combobox', { name: 'Catégorie' });
      await picker.click({ timeout: 5000 });
      const choices = dialog.getByRole('listbox', { name: 'Catégorie' }).getByRole('option');
      await expect.poll(() => choices.count()).toBeGreaterThan(1);
      await choices.nth(1).click({ timeout: 5000 });
      await expect(picker).not.toHaveValue('');
      const input = await picker.evaluate((element) => {
        const style = getComputedStyle(element);
        const box = element.getBoundingClientRect();
        const pill = element.parentElement!.getBoundingClientRect();
        return {
          background: style.backgroundColor,
          border: style.borderTopWidth,
          padding: style.paddingLeft,
          height: box.height,
          pillHeight: pill.height,
        };
      });
      expect(input.background).toBe('rgba(0, 0, 0, 0)');
      expect(input.border).toBe('0px');
      expect(input.padding).toBe('0px');
      expect(input.height).toBeLessThanOrEqual(input.pillHeight);
      await picker.fill('recherche');
      await expect(picker).toHaveValue('recherche');
    } finally {
      await context.close();
    }
  });
}
