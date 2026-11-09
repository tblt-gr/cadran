import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { settled } from './support/page';

for (const width of [1280, 360]) {
  test(`identifies goals achieved through tolerance at ${width} px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    const period = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Europe/Paris',
      year: 'numeric',
      month: '2-digit',
    }).format(new Date());
    const evaluation = {
      goalId: '00000000-0000-7000-8000-000000000091',
      label: 'Revenus avec tolérance',
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
        operands: { target: '3000', achieved: '2950', tolerance: '50', income: null, ratio: null },
      },
      versionNo: 1,
      overrides: [],
      pace: null,
    };
    // This audit isolates the server's status flags from the shared workspace's mutable goals.
    await page.route('**/api/v1/goals?*', (route) =>
      route.fulfill({
        json: { items: [], total: 0, page: 1, pageSize: 100 },
      }),
    );
    await page.route('**/api/v1/goals/evaluations*', (route) =>
      route.fulfill({
        json: {
          months: [
            {
              period,
              items: [
                evaluation,
                {
                  ...evaluation,
                  goalId: '00000000-0000-7000-8000-000000000092',
                  label: 'Revenus stricts',
                  achieved: { value: '3000', assetCode: 'EUR' },
                  gap: '0',
                  withinTolerance: false,
                },
                {
                  ...evaluation,
                  goalId: '00000000-0000-7000-8000-000000000093',
                  label: 'Revenus en cours',
                  status: 'IN_PROGRESS',
                },
              ],
            },
          ],
          years: [],
        },
      }),
    );
    await page.goto('/goals');
    await settled(page);
    const panel = page.getByRole('dialog', { name: /^Objectifs/ });
    const tolerant = panel.locator(`[data-goal-id="${evaluation.goalId}"]`);
    await expect(
      tolerant.getByText('Atteint grâce à la tolérance.', { exact: true }),
    ).toBeVisible();
    await expect(panel.getByText('Atteint grâce à la tolérance.', { exact: true })).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await auditAccessibility(page);
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
  });
}
