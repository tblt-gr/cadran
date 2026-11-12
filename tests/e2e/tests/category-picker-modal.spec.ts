import { expect, test } from '@playwright/test';
import { settled } from './support/page';
import { ownerStorageState } from './support/session';
import { auditLayout } from './support/audit';
import { auditAccessibility } from './support/a11y';
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

test('goal modal category control has a mobile touch target', async ({ browser }) => {
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
    await page.goto('/goals');
    await settled(page);
    await page.getByRole('button', { name: 'Nouvel objectif', exact: true }).click();
    await settled(page);
    const dialog = page.getByRole('dialog', { name: 'Nouvel objectif', exact: true });
    await dialog
      .getByRole('combobox', { name: 'Indicateur', exact: true })
      .selectOption('CATEGORY_SPENDING');
    await dialog.evaluate((element) => element.setAttribute('data-touch-audit', ''));
    expect(await auditLayout(page, true, '[data-touch-audit]')).toEqual([]);
  } finally {
    await context.close();
  }
});

for (const width of [360, 1280]) {
  test(`transaction analytic axes span the form at ${width}px`, async ({ browser }) => {
    const context = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
      ignoreHTTPSErrors: true,
      storageState: ownerStorageState,
      viewport: { width, height: 800 },
    });
    try {
      const page = await context.newPage();
      await page.goto('/transactions');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle transaction' }).click();
      const dialog = page.getByRole('dialog', { name: 'Nouvelle transaction' });
      const picker = dialog.getByRole('combobox', { name: 'Catégorie' });
      await picker.fill('Courses');
      await dialog.getByRole('option', { name: 'Courses', exact: true }).click();
      const axes = dialog.locator('details').filter({ hasText: 'Axes analytiques' });
      const fields = dialog.locator('[data-form-content]');
      const formBox = await fields.evaluate((element) => {
        const box = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        const start = parseFloat(style.paddingLeft);
        const end = parseFloat(style.paddingRight);
        return { x: box.x + start, width: box.width - start - end };
      });
      for (const open of [false, true]) {
        if (open) await axes.locator('summary').click();
        const axesBox = (await axes.boundingBox())!;
        expect(Math.abs(axesBox.x - formBox.x)).toBeLessThan(1);
        expect(Math.abs(axesBox.width - formBox.width)).toBeLessThan(1);
      }
      await expect(axes.getByRole('checkbox')).toHaveCount(6);
      await auditAccessibility(page);
    } finally {
      await context.close();
    }
  });

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

  test(`recurrence category picker keeps its selected label inside the tag at ${width}px`, async ({
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
        const tag = element.parentElement!.getBoundingClientRect();
        return {
          background: style.backgroundColor,
          border: style.borderTopWidth,
          padding: style.paddingLeft,
          height: box.height,
          tagHeight: tag.height,
        };
      });
      expect(input.background).toBe('rgba(0, 0, 0, 0)');
      expect(input.border).toBe('0px');
      expect(input.padding).toBe('0px');
      expect(input.height).toBeLessThanOrEqual(input.tagHeight);
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
