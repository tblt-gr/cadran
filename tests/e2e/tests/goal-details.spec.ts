import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { settled } from './support/page';

const goalId = '00000000-0000-7000-8000-000000000094';
const version = {
  versionNo: 1,
  effectiveFrom: '2026-01',
  valueType: 'AMOUNT',
  targetAmount: '3000',
  ratio: null,
  tolerance: '50',
  schedule: null,
};
const goal = {
  id: goalId,
  label: 'Revenus détaillés',
  periodicity: 'MONTHLY',
  startPeriod: '2026-01',
  endPeriod: null,
  metric: 'TOTAL_INCOME',
  direction: 'MINIMUM',
  scope: { type: 'WORKSPACE', label: 'Espace', archived: false },
  assetCode: 'EUR',
  currentVersion: version,
  version: 1,
  warnings: [],
  status: null,
};
const evaluation = {
  goalId,
  label: goal.label,
  periodicity: 'MONTHLY',
  metric: 'TOTAL_INCOME',
  scopeType: 'WORKSPACE',
  scopeRef: null,
  direction: 'MINIMUM',
  valueType: 'AMOUNT',
  columnKey: 'cashIncome',
  target: { value: '3000', assetCode: 'EUR' },
  achieved: { value: '2950', assetCode: 'EUR' },
  gap: '-50',
  status: 'MET',
  reason: null,
  tolerance: { value: '50', assetCode: 'EUR' },
  withinTolerance: true,
  formula: {
    key: 'goals.formula.amount',
    operands: { target: '3000', achieved: '2950', tolerance: '50' },
  },
  versionNo: 1,
  overrides: [],
  pace: null,
};

for (const width of [1280, 360]) {
  for (const entry of ['monthly', 'annual'] as const) {
    test(`goal details from ${entry} budget at ${width} px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      const selectedGoal =
        entry === 'annual' ? { ...goal, periodicity: 'YEARLY', startPeriod: '2026' } : goal;
      await page.route('**/api/v1/goals?*', (route) =>
        route.fulfill({ json: { items: [selectedGoal], total: 1, page: 1, pageSize: 100 } }),
      );
      await page.route('**/api/v1/goals/evaluations*', (route) =>
        route.fulfill({
          json: {
            months: [{ period: '2026-04', items: [evaluation] }],
            years: [{ period: '2026', items: [{ ...evaluation, periodicity: 'YEARLY' }] }],
          },
        }),
      );
      await page.route(`**/api/v1/goals/${goalId}`, (route) =>
        route.fulfill({
          json: {
            ...selectedGoal,
            versions: [
              { ...version, versionNo: 2, effectiveFrom: '2026-06', targetAmount: '4000' },
              version,
            ],
          },
        }),
      );
      await page.goto(
        entry === 'monthly' ? '/budget/2026/04?panel=goals' : '/budget/2026?panel=goals',
      );
      await settled(page);
      const panel = page.getByRole('dialog', { name: /^Objectifs/ });
      const trigger = panel.getByRole('button', { name: goal.label, exact: true });
      await trigger.focus();
      await page.keyboard.press('Enter');
      const modal = page.getByRole('dialog', {
        name: `Détails de l’objectif « ${goal.label} »`,
        exact: true,
      });
      await expect(modal.getByText('Version appliquée à cette période')).toBeVisible();
      await expect(modal.getByText('Atteint grâce à la tolérance.')).toBeVisible();
      await expect(modal.getByRole('heading', { level: 4, name: /^Version / })).toHaveText([
        'Version 1',
        'Version 2',
      ]);
      const appliedSummary = modal.locator('summary').filter({ hasText: 'Version 1' });
      const otherSummary = modal.locator('summary').filter({ hasText: 'Version 2' });
      const closeButton = modal.getByRole('button', { name: 'Fermer' });
      const content = modal.getByRole('region', { name: 'Détails et historique de l’objectif' });
      await expect(appliedSummary.locator('..')).toHaveAttribute('open', '');
      await expect(otherSummary.locator('..')).not.toHaveAttribute('open');
      await expect(otherSummary).toContainText('À partir de Juin 2026');
      await expect(closeButton).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(content).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(appliedSummary).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(otherSummary).toBeFocused();
      await page.keyboard.press('Shift+Tab');
      await expect(appliedSummary).toBeFocused();
      await page.keyboard.press('Shift+Tab');
      await expect(content).toBeFocused();
      await page.keyboard.press('Shift+Tab');
      await expect(closeButton).toBeFocused();
      await page.keyboard.press('Shift+Tab');
      await expect(otherSummary).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(otherSummary.locator('..')).toHaveAttribute('open', '');
      await expect(otherSummary.locator('..').getByText(/4\s000,00/)).toBeVisible();
      await expect(appliedSummary.locator('..')).toHaveAttribute('open', '');
      await page.keyboard.press('Space');
      await expect(otherSummary.locator('..')).not.toHaveAttribute('open');
      await page.keyboard.press('Tab');
      await expect(closeButton).toBeFocused();
      await page.keyboard.press('Shift+Tab');
      await expect(otherSummary).toBeFocused();
      await page.keyboard.press('Shift+Tab');
      await expect(appliedSummary).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(appliedSummary.locator('..')).not.toHaveAttribute('open');
      await expect(appliedSummary.getByText('Version appliquée à cette période')).toBeVisible();
      await expect(modal.getByRole('heading', { level: 3 }).first()).toHaveText(/^Avancement : /);
      await expect(modal.getByRole('meter')).toHaveAttribute('aria-valuenow', '0.9833');
      const badgeBounds = await modal.getByText('Atteint', { exact: true }).boundingBox();
      const modalBounds = await modal.boundingBox();
      expect(badgeBounds!.width).toBeLessThan(modalBounds!.width / 2);
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
      );
      await page.keyboard.press('Shift+Tab');
      await expect(content).toBeFocused();
      await page.keyboard.press('PageDown');
      await auditAccessibility(page);
      await page.keyboard.press('Escape');
      await expect(modal).toBeHidden();
      await expect(trigger).toBeFocused();
    });
  }
}

test('goal details recover from errors and explain empty history and non-calculable values', async ({
  page,
}) => {
  const unavailable = {
    ...evaluation,
    target: null,
    achieved: null,
    gap: null,
    status: 'NOT_CALCULABLE',
    reason: 'NO_DATA',
    withinTolerance: null,
  };
  await page.route('**/api/v1/goals?*', (route) =>
    route.fulfill({ json: { items: [goal], total: 1, page: 1, pageSize: 100 } }),
  );
  await page.route('**/api/v1/goals/evaluations*', (route) =>
    route.fulfill({ json: { months: [{ period: '2026-04', items: [unavailable] }], years: [] } }),
  );
  let failed = false;
  await page.route(`**/api/v1/goals/${goalId}`, (route) => {
    if (!failed) {
      failed = true;
      return route.fulfill({ status: 503, json: {} });
    }
    return route.fulfill({ json: { ...goal, versions: [] } });
  });
  await page.goto('/budget/2026/04?panel=goals');
  await settled(page);
  // The whole card opens the details: click its lower edge, far from the label.
  const card = page.locator(`li[data-goal-id="${goalId}"]`);
  const box = (await card.boundingBox())!;
  await card.click({ position: { x: box.width / 2, y: box.height - 6 } });
  const modal = page.getByRole('dialog', {
    name: `Détails de l’objectif « ${goal.label} »`,
    exact: true,
  });
  await expect(modal.getByRole('alert')).toBeVisible();
  await modal.getByRole('button', { name: 'Réessayer' }).click();
  await expect(modal.getByText('Aucune version n’est disponible.')).toBeVisible();
  await expect(modal.getByText('Aucune donnée pour cette période')).toBeVisible();
  await expect(modal.getByText('Écart non calculable pour cette période.')).toBeVisible();
  await expect(modal.getByRole('meter')).toHaveCount(0);
  await auditAccessibility(page);
});
