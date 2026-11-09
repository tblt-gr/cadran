import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { installBulkGoalFixtures } from './support/bulk-goals';
import { settled } from './support/page';

for (const width of [1280, 360]) {
  for (const yearly of [false, true]) {
    test(`bulk goal preview, stale retry and keyboard save (${yearly ? 'annual' : 'monthly'}, ${width}px)`, async ({
      page,
    }) => {
      await page.setViewportSize({ width, height: 800 });
      const { month, year } = await installBulkGoalFixtures(page, yearly);
      let saves = 0;
      await page.route('**/api/v1/goals/bulk-edit', (route) => {
        saves += 1;
        if (saves === 1)
          return route.fulfill({
            status: 409,
            json: { code: 'stale_goal_version', status: 409, title: 'Conflict' },
          });
        const body = route.request().postDataJSON();
        expect(body.items).toHaveLength(3);
        expect(
          body.items.every(
            (item: { expectedValueType: string; expectedAssetCode: string }) =>
              item.expectedValueType === 'AMOUNT' && item.expectedAssetCode === 'EUR',
          ),
        ).toBe(true);
        expect(body.changes).toEqual({ tolerance: '0' });
        return route.fulfill({ json: { effectiveFrom: month, canApply: true, items: [] } });
      });
      await page.goto(
        yearly ? `/budget/${year}?panel=goals` : `/budget/${month.replace('-', '/')}?panel=goals`,
      );
      await settled(page);
      const panel = page.getByRole('dialog', { name: /^Objectifs/ });
      await panel.getByRole('button', { name: 'Sélectionner', exact: true }).focus();
      await page.keyboard.press('Enter');
      for (const number of [1, 2, 3]) {
        await panel
          .getByRole('checkbox', { name: `Sélectionner Objectif groupé ${number}`, exact: true })
          .focus();
        await page.keyboard.press('Space');
      }
      await expect(panel.getByRole('status')).toHaveText('3 objectifs sélectionnés');
      await expect(
        panel.getByRole('checkbox', { name: 'Sélectionner Objectif groupé 4' }),
      ).not.toBeChecked();
      const edit = panel.getByRole('button', { name: 'Modifier la sélection' });
      await edit.focus();
      await page.keyboard.press('Enter');
      const modal = page.getByRole('dialog', { name: 'Modifier les objectifs sélectionnés' });
      await modal.getByRole('checkbox', { name: 'Modifier la tolérance' }).focus();
      await page.keyboard.press('Space');
      await modal.getByRole('button', { name: 'Prévisualiser les changements' }).focus();
      await page.keyboard.press('Enter');
      await settled(page);
      await expect(modal.getByText('Sans fin', { exact: true })).toHaveCount(3);
      await auditAccessibility(page);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
      );
      await expect(modal.getByRole('button', { name: 'Appliquer à la sélection' })).toBeEnabled();
      await modal.getByRole('button', { name: 'Appliquer à la sélection' }).focus();
      await expect(modal.getByRole('button', { name: 'Appliquer à la sélection' })).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(modal.getByRole('alert')).toContainText('relancez la prévisualisation');
      await expect(modal.getByRole('button', { name: 'Appliquer à la sélection' })).toBeDisabled();
      await expect(modal.getByRole('checkbox', { name: 'Modifier la tolérance' })).toBeChecked();
      await modal.getByRole('button', { name: 'Prévisualiser les changements' }).focus();
      await page.keyboard.press('Enter');
      await settled(page);
      await expect(modal.getByRole('button', { name: 'Appliquer à la sélection' })).toBeEnabled();
      await modal.getByRole('button', { name: 'Appliquer à la sélection' }).focus();
      await expect(modal.getByRole('button', { name: 'Appliquer à la sélection' })).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(modal).toBeHidden();
      await expect(panel.getByRole('checkbox')).toHaveCount(0);
      await expect(panel.getByRole('button', { name: 'Sélectionner', exact: true })).toBeVisible();
      expect(saves).toBe(2);
    });
  }
}
