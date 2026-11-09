import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { installBulkGoalFixtures } from './support/bulk-goals';
import { settled } from './support/page';

for (const yearly of [false, true]) {
  test(`deleting all ${yearly ? 'annual' : 'monthly'} goals keeps focus in the empty panel`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    const { month, year, clearGoals } = await installBulkGoalFixtures(page, yearly);
    await page.route('**/api/v1/goals/bulk-delete', (route) => {
      expect(route.request().postDataJSON().items).toHaveLength(4);
      clearGoals();
      return route.fulfill({ status: 204 });
    });
    await page.goto(
      yearly ? `/budget/${year}?panel=goals` : `/budget/${month.replace('-', '/')}?panel=goals`,
    );
    await settled(page);
    const panel = page.getByRole('dialog', { name: /^Objectifs/ });
    await panel
      .getByRole('button', { name: 'Sélectionner les objectifs affichés', exact: true })
      .click();
    await panel.getByRole('checkbox', { name: 'Sélectionner tous les objectifs affichés' }).check();
    await panel.getByRole('button', { name: 'Supprimer', exact: true }).click();
    const modal = page.getByRole('dialog', { name: 'Supprimer 4 objectifs', exact: true });
    await modal.getByRole('button', { name: 'Supprimer', exact: true }).click();
    await expect(modal).toBeHidden();
    await expect(panel.locator('[data-goal-id]')).toHaveCount(0);
    await expect(
      panel.getByRole('button', { name: 'Sélectionner les objectifs affichés', exact: true }),
    ).toHaveCount(0);
    await expect(panel.getByRole('button', { name: 'Nouvel objectif', exact: true })).toBeFocused();
  });
}

for (const width of [1280, 360]) {
  for (const yearly of [false, true]) {
    for (const action of ['stop', 'delete'] as const) {
      test(`bulk goal ${action}, confirmation, stale retry and keyboard (${yearly ? 'annual' : 'monthly'}, ${width}px)`, async ({
        page,
      }) => {
        await page.setViewportSize({ width, height: 800 });
        const { month, year } = await installBulkGoalFixtures(page, yearly);
        const verb = action === 'stop' ? 'Arrêter' : 'Supprimer';
        let saves = 0;
        await page.route(`**/api/v1/goals/bulk-${action}`, (route) => {
          saves += 1;
          const body = route.request().postDataJSON();
          expect(body.items).toHaveLength(3);
          expect(
            body.items.every((item: { expectedVersion: number }) => item.expectedVersion === 1),
          ).toBe(true);
          if (action === 'stop')
            expect(
              body.items.every(
                (item: { lastPeriod: string }) => item.lastPeriod === (yearly ? year : month),
              ),
            ).toBe(true);
          if (saves === 1)
            return route.fulfill({
              status: 409,
              json: {
                code: 'stale_goal_version',
                goalId: body.items[0].goalId,
                field: 'expectedVersion',
                status: 409,
                title: 'Conflict',
              },
            });
          return route.fulfill({ status: 204 });
        });
        await page.goto(
          yearly ? `/budget/${year}?panel=goals` : `/budget/${month.replace('-', '/')}?panel=goals`,
        );
        await settled(page);
        const panel = page.getByRole('dialog', { name: /^Objectifs/ });
        const pen = panel.getByRole('button', {
          name: 'Sélectionner les objectifs affichés',
          exact: true,
        });
        await pen.focus();
        await page.keyboard.press('Enter');
        await expect(pen).toHaveAttribute('aria-pressed', 'true');
        await expect(pen).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
        await expect(pen).toHaveCSS('border-top-width', '0px');
        const all = panel.getByRole('checkbox', {
          name: 'Sélectionner tous les objectifs affichés',
        });
        await all.focus();
        await page.keyboard.press('Space');
        await expect(all).toBeChecked();
        await page.keyboard.press('Space');
        await expect(all).not.toBeChecked();
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
        await expect(all).toHaveJSProperty('indeterminate', true);
        const firstRow = panel.locator('[data-goal-id]').first();
        expect(
          await firstRow.evaluate((row) => {
            const checkbox = row.querySelector('input[type="checkbox"]')!;
            const bounds = row.getBoundingClientRect();
            const style = getComputedStyle(row);
            return (
              Math.abs(
                checkbox.getBoundingClientRect().left -
                  bounds.left -
                  parseFloat(style.borderLeftWidth) -
                  parseFloat(style.paddingLeft),
              ) < 1
            );
          }),
        ).toBe(true);
        await expect(firstRow.getByRole('heading')).toHaveCSS('text-align', 'left');
        const trigger = panel.getByRole('button', { name: verb, exact: true });
        await trigger.focus();
        await page.keyboard.press('Enter');
        const modal = page.getByRole('dialog', { name: `${verb} 3 objectifs`, exact: true });
        await expect(modal).toBeVisible();
        expect(saves).toBe(0);
        await modal.getByRole('button', { name: 'Annuler' }).click();
        await expect(modal).toBeHidden();
        await expect(panel.getByRole('status')).toHaveText('3 objectifs sélectionnés');
        await expect(trigger).toBeFocused();
        await trigger.focus();
        await page.keyboard.press('Enter');
        if (action === 'stop')
          await modal
            .getByLabel(yearly ? 'Dernière année concernée' : 'Dernier mois concerné')
            .fill(yearly ? year : month);
        await auditAccessibility(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
          true,
        );
        const confirm = modal.getByRole('button', { name: verb, exact: true });
        await expect(confirm).toBeEnabled();
        await confirm.focus();
        await expect(confirm).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(modal.getByRole('alert')).toContainText('Aucune modification');
        await expect(
          modal.getByText(`Objectif groupé 1 (${yearly ? 'Annuel' : 'Mensuel'})`, { exact: true }),
        ).toBeVisible();
        if (action === 'stop')
          await expect(
            modal.getByLabel(yearly ? 'Dernière année concernée' : 'Dernier mois concerné'),
          ).toHaveValue(yearly ? year : month);
        await expect(confirm).toBeEnabled();
        await confirm.focus();
        await expect(confirm).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(modal).toBeHidden();
        await expect(panel.getByRole('checkbox')).toHaveCount(0);
        await expect(pen).toHaveAttribute('aria-pressed', 'false');
        await expect(pen).toBeFocused();
        expect(saves).toBe(2);
      });
    }
  }
}
