import { expect, test } from '@playwright/test';
import { settled } from './support/page';
import { ownerStorageState } from './support/session';
import { auditLayout } from './support/audit';
import { openMovementEditModal } from './support/edit-movement';

for (const kind of ['transaction', 'category', 'transaction edit'] as const) {
  test(`${kind} modal category control has a mobile touch target`, async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
      ignoreHTTPSErrors: true,
      storageState: ownerStorageState,
      viewport: { width: 360, height: 740 },
      hasTouch: true,
      isMobile: true,
    });
    try {
      const page = await context.newPage();
      if (kind === 'transaction edit') {
        await openMovementEditModal(page, 'transaction');
      } else {
        await page.goto(kind === 'transaction' ? '/transactions' : '/transactions/categories');
        await settled(page);
        await page
          .getByRole('button', {
            name: kind === 'transaction' ? 'Nouvelle transaction' : 'Nouvelle catégorie',
          })
          .click();
      }
      const dialog = page.getByRole('dialog').last();
      await dialog.evaluate((element) => element.setAttribute('data-touch-audit', ''));
      expect(await auditLayout(page, true, '[data-touch-audit]')).toEqual([]);
    } finally {
      await context.close();
    }
  });
}

test('budget target modal category control has a mobile touch target', async ({ browser }) => {
  const context = await browser.newContext({
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
    ignoreHTTPSErrors: true,
    storageState: ownerStorageState,
    viewport: { width: 360, height: 740 },
    hasTouch: true,
    isMobile: true,
  });
  try {
    const page = await context.newPage();
    await page.goto('/budget/plans');
    await settled(page);
    const planLink = page.locator('a[href^="/budget/plans/"]').first();
    if ((await planLink.count()) === 0) {
      await page.getByRole('button', { name: 'Créer le premier plan' }).click();
      const creation = page.getByRole('dialog', { name: 'Nouveau plan budgétaire' });
      const today = new Date();
      const period = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
      await creation.getByLabel('Période', { exact: true }).fill(period);
      await creation.getByRole('button', { name: 'Enregistrer', exact: true }).click();
      await expect(creation).toBeHidden();
      await settled(page);
    }
    await planLink.click();
    await settled(page);
    await page.getByRole('button', { name: 'Ajouter un objectif' }).click();
    const dialog = page.getByRole('dialog').last();
    await dialog.evaluate((element) => element.setAttribute('data-touch-audit', ''));
    expect(await auditLayout(page, true, '[data-touch-audit]')).toEqual([]);
  } finally {
    await context.close();
  }
});

for (const width of [360, 1280]) {
  test(`category advanced checkbox labels remain horizontal at ${width}px`, async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
      ignoreHTTPSErrors: true,
      storageState: ownerStorageState,
      viewport: { width, height: 800 },
      hasTouch: width === 360,
      isMobile: width === 360,
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
      hasTouch: width === 360,
      isMobile: width === 360,
    });
    try {
      const page = await context.newPage();
      await page.goto('/transactions/recurrences');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle récurrence' }).click();
      const dialog = page.getByRole('dialog', { name: 'Nouvelle récurrence' });
      const picker = dialog.getByRole('combobox', { name: 'Catégorie' });
      const control = picker.locator('xpath=../..');
      const initialHeight = (await control.boundingBox())!.height;
      if (width === 360) {
        const empty = (await picker.boundingBox())!;
        expect(empty.width).toBeGreaterThanOrEqual(44);
        expect(empty.height).toBeGreaterThanOrEqual(44);
      }
      await picker.click({ timeout: 5000 });
      const choices = dialog.getByRole('listbox', { name: 'Catégorie' }).getByRole('option');
      await expect.poll(() => choices.count()).toBeGreaterThan(1);
      await choices.nth(1).click({ timeout: 5000 });
      await expect(picker).not.toHaveValue('');
      expect(Math.abs((await control.boundingBox())!.height - initialHeight)).toBeLessThan(1);
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
      if (width === 360) expect(input.height).toBeGreaterThanOrEqual(44);
      await picker.fill('recherche');
      await expect(picker).toHaveValue('recherche');
      expect(Math.abs((await control.boundingBox())!.height - initialHeight)).toBeLessThan(1);
      await picker.press('Escape');
      expect(Math.abs((await control.boundingBox())!.height - initialHeight)).toBeLessThan(1);
    } finally {
      await context.close();
    }
  });
}
