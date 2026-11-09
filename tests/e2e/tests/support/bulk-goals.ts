import type { Page } from '@playwright/test';

/** Deterministic goal rows keep layout and keyboard audits independent of the shared ledger. */
export async function installBulkGoalFixtures(page: Page, yearly = false) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Paris',
    year: 'numeric',
    month: '2-digit',
  }).formatToParts(new Date());
  const year = parts.find((part) => part.type === 'year')!.value;
  const month = `${year}-${parts.find((part) => part.type === 'month')!.value}`;
  const goals = [1, 2, 3, 4].map((number) => ({
    id: `00000000-0000-7000-8000-00000000009${number}`,
    label: `Objectif groupé ${number}`,
    periodicity: yearly ? 'YEARLY' : 'MONTHLY',
    startPeriod: yearly ? year : `${year}-01`,
    endPeriod: null,
    metric: 'TOTAL_INCOME',
    direction: 'MINIMUM',
    scope: { type: 'WORKSPACE', label: 'Espace', archived: false },
    assetCode: 'EUR',
    currentVersion: {
      versionNo: 1,
      effectiveFrom: `${year}-01`,
      valueType: 'AMOUNT',
      targetAmount: '1000',
      ratio: null,
      tolerance: '10',
      schedule: null,
    },
    version: 1,
    warnings: [],
    status: null,
  }));
  await page.route('**/api/v1/goals?*', (route) =>
    route.fulfill({ json: { items: goals, total: goals.length, page: 1, pageSize: 100 } }),
  );
  await page.route('**/api/v1/goals/evaluations*', (route) =>
    route.fulfill({
      json: {
        months: yearly ? [] : [{ period: month, items: goals.map(evaluation) }],
        years: yearly ? [{ period: year, items: goals.map(evaluation) }] : [],
      },
    }),
  );
  await page.route('**/api/v1/goals/bulk-preview', (route) => {
    const body = route.request().postDataJSON();
    return route.fulfill({
      json: {
        effectiveFrom: body.effectiveFrom,
        canApply: true,
        items: body.items.map(({ goalId }: { goalId: string }) => ({
          goalId,
          label: goals.find((goal) => goal.id === goalId)!.label,
          expectedVersion: 1,
          valueType: 'AMOUNT',
          assetCode: 'EUR',
          before: { target: '1000', tolerance: '10' },
          after: {
            target: body.changes.target ?? '1000',
            tolerance: body.changes.tolerance ?? '10',
          },
          firstAffectedMonth: body.effectiveFrom,
          lastAffectedMonth: null,
          changed: true,
          laterRevisions: [],
          overrides: [],
          conflict: null,
        })),
      },
    });
  });
  return { month, year, goals };
}

function evaluation(goal: { id: string; label: string; periodicity: string }) {
  return {
    goalId: goal.id,
    label: goal.label,
    periodicity: goal.periodicity,
    metric: 'TOTAL_INCOME',
    scopeType: 'WORKSPACE',
    scopeRef: null,
    direction: 'MINIMUM',
    valueType: 'AMOUNT',
    columnKey: 'cashIncome',
    target: { value: '1000', assetCode: 'EUR' },
    achieved: null,
    gap: null,
    status: 'NOT_CALCULABLE',
    reason: 'NO_DATA',
    tolerance: { value: '10', assetCode: 'EUR' },
    withinTolerance: null,
    formula: {
      key: 'goals.formula.amount',
      operands: { target: '1000', achieved: null, tolerance: '10', income: null, ratio: null },
    },
    versionNo: 1,
    overrides: [],
    pace: null,
  };
}
