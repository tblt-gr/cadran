import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { settled } from './support/page';

for (const width of [1280, 360]) {
  test(`requires correction approval and separates its history badge at ${width} px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 800 });
    const now = new Date();
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Europe/Paris',
      year: 'numeric',
      month: '2-digit',
    }).formatToParts(now);
    const year = Number(parts.find((part) => part.type === 'year')?.value);
    const month = Number(parts.find((part) => part.type === 'month')?.value);
    const current = `${year}-${String(month).padStart(2, '0')}`;
    const previousDate = new Date(Date.UTC(year, month - 2, 1));
    const previous = `${previousDate.getUTCFullYear()}-${String(previousDate.getUTCMonth() + 1).padStart(2, '0')}`;
    const id = '00000000-0000-7000-8000-000000000094';
    const version = {
      versionNo: 1,
      effectiveFrom: previous,
      valueType: 'AMOUNT',
      targetAmount: '3000',
      ratio: null,
      tolerance: '0',
      schedule: null,
    };
    const goal = {
      id,
      label: 'Revenus corrigés',
      periodicity: 'MONTHLY',
      startPeriod: previous,
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
      goalId: id,
      label: goal.label,
      periodicity: 'MONTHLY',
      metric: goal.metric,
      scopeType: 'WORKSPACE',
      scopeRef: null,
      direction: 'MINIMUM',
      valueType: 'AMOUNT',
      columnKey: 'cashIncome',
      target: { value: '3000', assetCode: 'EUR' },
      achieved: { value: '2950', assetCode: 'EUR' },
      gap: '-50',
      status: 'IN_PROGRESS',
      reason: null,
      tolerance: null,
      withinTolerance: false,
      formula: {
        key: 'goals.formula.amount',
        operands: { target: '3000', achieved: '2950', tolerance: null, income: null, ratio: null },
      },
      versionNo: 1,
      overrides: [],
      pace: null,
    };
    let saves = 0;
    await page.route('**/api/v1/goals?*', (route) =>
      route.fulfill({ json: { items: [goal], total: 1 } }),
    );
    await page.route('**/api/v1/goals/evaluations*', (route) =>
      route.fulfill({ json: { months: [{ period: current, items: [evaluation] }], years: [] } }),
    );
    await page.route(`**/api/v1/goals/${id}`, (route) => {
      if (route.request().method() === 'PUT') saves += 1;
      return route.fulfill({ json: { ...goal, versions: [version] } });
    });
    await page.route(`**/api/v1/goals/${id}/correction-preview`, (route) =>
      route.fulfill({
        json: {
          months: [
            {
              month: previous,
              before: { goalVersion: 1, expected: '3000', status: 'ON_TRACK' },
              after: { goalVersion: 2, expected: '3100', status: 'ON_TRACK' },
            },
          ],
        },
      }),
    );
    await page.route(`**/api/v1/goals/${id}/versions`, (route) =>
      route.fulfill({
        json: {
          items: [
            {
              ...version,
              versionNo: 2,
              targetAmount: '3100',
              createdAt: now.toISOString(),
              createdBy: { id, displayName: 'Owner' },
              retroactive: true,
              reason: 'Correction confirmée',
              year,
              changes: [{ field: 'targetAmount', before: '3000', after: '3100' }],
            },
          ],
        },
      }),
    );
    await page.goto('/goals');
    await settled(page);
    await page
      .getByRole('button', { name: `Actions de l’objectif « ${goal.label} »`, exact: true })
      .click();
    await page
      .getByRole('button', { name: `Modifier l’objectif « ${goal.label} »`, exact: true })
      .click();
    await settled(page);
    const form = page.getByRole('dialog', { name: 'Modifier l’objectif', exact: true });
    await form.getByLabel('À partir de', { exact: true }).fill(previous);
    await form.getByLabel('Motif de la correction').fill('Correction confirmée');
    await form.getByLabel('Montant cible', { exact: true }).fill('3100');
    await form.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    const confirmation = form.getByRole('button', { name: 'Confirmer la correction', exact: true });
    await expect(confirmation).toBeDisabled();
    const check = form.getByRole('checkbox', {
      name: 'J’ai vérifié l’impact de cette correction.',
      exact: true,
    });
    await check.focus();
    await page.keyboard.press('Space');
    await expect(confirmation).toBeEnabled();
    await page.keyboard.press('Space');
    await expect(confirmation).toBeDisabled();
    expect(saves).toBe(0);
    await auditAccessibility(page);
    await page.keyboard.press('Space');
    await confirmation.click();
    await expect(form).toBeHidden();
    expect(saves).toBe(1);
    await settled(page);
    await page
      .getByRole('button', { name: `Actions de l’objectif « ${goal.label} »`, exact: true })
      .click();
    await page
      .getByRole('button', { name: `Historique de l’objectif « ${goal.label} »`, exact: true })
      .click();
    await settled(page);
    const history = page.getByRole('table', { name: 'Versions enregistrées', exact: true });
    const badge = history.getByText('Rétroactive', { exact: true });
    await expect(badge).toBeVisible();
    const group = badge.locator('..').locator('..');
    expect(
      await group.evaluate((element) => Number.parseFloat(getComputedStyle(element).gap)),
    ).toBeGreaterThan(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await auditAccessibility(page);
  });
}
