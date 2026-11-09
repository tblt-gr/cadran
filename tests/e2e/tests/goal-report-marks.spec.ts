import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { settled } from './support/page';

const months = Array.from(
  { length: 12 },
  (_, index) => `2026-${String(index + 1).padStart(2, '0')}`,
);

const exceeded = {
  goalId: '00000000-0000-7000-8000-000000000001',
  label: 'Audit objectif',
  periodicity: 'MONTHLY',
  metric: 'TOTAL_SPENDING',
  scopeType: 'WORKSPACE',
  scopeRef: null,
  direction: 'CEILING',
  valueType: 'AMOUNT',
  columnKey: 'budgetExpenses',
  target: { value: '1200.00', assetCode: 'EUR' },
  achieved: { value: '1250.00', assetCode: 'EUR' },
  gap: '-50.00',
  status: 'MISSED',
  reason: null,
  formula: {
    key: 'goals.formula.amount',
    operands: {
      target: '1200.00',
      achieved: '1250.00',
      income: null,
      ratio: null,
      tolerance: null,
    },
  },
  versionNo: 1,
  overrides: [],
  pace: null,
  tolerance: null,
  withinTolerance: false,
};

test.describe('goal marks in the annual report', () => {
  test('a marked cell and its explanation pass the accessibility audit', async ({ page }) => {
    // The evaluations are stubbed: the audit is about the markup of a marked cell, not the rules.
    await page.route('**/api/v1/goals/evaluations*', (route) =>
      route.fulfill({
        contentType: 'application/json',
        json: { months: months.map((period) => ({ period, items: [exceeded] })), years: [] },
      }),
    );
    await page.goto('/budget/2026');
    await settled(page);

    const table = page.getByRole('table', { name: 'Rapport 2026' });
    const mark = table.getByRole('button', { name: 'Objectif : Audit objectif, Dépassé' }).first();
    await expect(mark).toBeVisible();
    // The target is in the explanation only; the table's own `Minimum` summary row is a row header.
    await expect(table.getByRole('cell').getByText(/Plafond|Minimum/)).toHaveCount(0);
    await auditAccessibility(page);

    // The `i` opens the goals panel on the month of the cell, with the goal focused.
    await mark.click();
    const panel = page.getByRole('dialog', { name: /^Objectifs · / });
    await expect(panel).toBeVisible();
    await expect(page).toHaveURL(
      /panel=goals&goal=00000000-0000-7000-8000-000000000001&period=2026-\d{2}$/,
    );
    await expect(
      panel.locator('[data-goal-id="00000000-0000-7000-8000-000000000001"]'),
    ).toBeFocused();
    await expect(panel.getByText(/Dépassement de 50,00/)).toHaveCount(0);
    await panel
      .getByRole('button', { name: 'Expliquer l’objectif « Audit objectif »', exact: true })
      .click();
    const details = page.getByRole('dialog', {
      name: 'Détails de l’objectif « Audit objectif »',
      exact: true,
    });
    await expect(details.getByText(/Dépassement de 50,00/)).toBeVisible();
    await auditAccessibility(page);
    await page.keyboard.press('Escape');
    await expect(details).toBeHidden();
    await expect(panel).toBeVisible();
    await auditAccessibility(page);

    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
  });
});
