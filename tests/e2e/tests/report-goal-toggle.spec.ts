import { expect, test, type Page } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { auditLayout } from './support/audit';
import { settled } from './support/page';

const preferencesUrl = '/api/v1/reports/annual/preferences';
interface Preferences {
  columns: string[];
  incompleteMonths: 'include' | 'exclude';
  goalMarks: boolean;
  version: number;
}
async function readPreferences(page: Page): Promise<Preferences> {
  const response = await page.request.get(preferencesUrl);
  expect(response.ok()).toBe(true);
  return response.json();
}
async function savePreferences(page: Page, value: Preferences) {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'csrf_token');
  const response = await page.request.put(preferencesUrl, {
    headers: { 'X-CSRF-TOKEN': decodeURIComponent(token!.value) },
    data: value,
  });
  expect(response.ok()).toBe(true);
}
const toggle = (page: Page) =>
  page.getByRole('button', { name: 'Repères d’objectifs', exact: true });
const marks = (page: Page) => page.getByRole('button', { name: /^Objectif :/ });
const picker = (page: Page) => page.getByRole('dialog', { name: 'Colonnes du rapport' });
const evaluation = {
  goalId: '00000000-0000-7000-8000-000000000001',
  label: 'Repère test',
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
async function mockEvaluations(page: Page) {
  await page.route('**/api/v1/goals/evaluations*', (route) => {
    const params = new URL(route.request().url()).searchParams;
    const from = Number(params.get('from')!.slice(0, 4));
    const to = Number(params.get('to')!.slice(0, 4));
    const years = Array.from({ length: to - from + 1 }, (_, index) => from + index);
    return route.fulfill({
      contentType: 'application/json',
      json: {
        months: years.flatMap((year) =>
          Array.from({ length: 12 }, (_, index) => ({
            period: `${year}-${String(index + 1).padStart(2, '0')}`,
            items: [evaluation],
          })),
        ),
        years: years.map((year) => ({
          period: String(year),
          items: [{ ...evaluation, periodicity: 'YEARLY' }],
        })),
      },
    });
  });
}

for (const [mode, search] of [
  ['annual', ''],
  ['compact multi-year', '?view=multi&from=2025&to=2026'],
  ['detailed multi-year', '?view=multi&from=2025&to=2026&display=detailed'],
] as const) {
  test(`${mode}: toggle persists, synchronizes columns and suppresses cached marks and requests`, async ({
    page,
  }) => {
    await page.goto('/budget/2026');
    await settled(page);
    const original = await readPreferences(page);
    try {
      await savePreferences(page, {
        ...original,
        columns: ['cashIncome', 'budgetExpenses'],
        goalMarks: false,
      });
      await mockEvaluations(page);
      let requests = 0;
      page.on('request', (request) => {
        if (request.url().includes('/api/v1/goals/evaluations')) requests++;
      });
      await page.goto(`/budget/2026${search}`);
      await settled(page);
      await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
      await expect(marks(page)).toHaveCount(0);
      expect(requests).toBe(0);
      const sources = await page.getByRole('table').first().getByRole('link').count();
      expect(sources).toBeGreaterThan(0);

      await toggle(page).focus();
      await page.keyboard.press('Space');
      await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
      await expect.poll(() => marks(page).count()).toBeGreaterThan(0);
      await settled(page);
      const enabledRequests = requests;
      expect(enabledRequests).toBeGreaterThan(0);
      const saved = await readPreferences(page);
      expect(saved.columns).toEqual(['cashIncome', 'budgetExpenses']);
      expect(saved.incompleteMonths).toBe(original.incompleteMonths);
      await page.getByRole('button', { name: 'Colonnes', exact: true }).click();
      await settled(page);
      await expect(
        picker(page).getByRole('checkbox', { name: /^Afficher les objectifs/ }),
      ).toBeChecked();
      await picker(page)
        .getByRole('checkbox', { name: /^Afficher les objectifs/ })
        .uncheck();
      await picker(page).getByRole('button', { name: 'Enregistrer', exact: true }).click();
      await expect(picker(page)).toBeHidden();
      await settled(page);
      await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
      await expect(marks(page)).toHaveCount(0);
      expect(requests).toBe(enabledRequests);
      expect(await page.getByRole('table').first().getByRole('link').count()).toBe(sources);
      await page.reload();
      await settled(page);
      await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
      expect(requests).toBe(enabledRequests);
      await auditAccessibility(page);

      await page.getByRole('button', { name: 'Objectifs', exact: true }).click();
      await expect(page.getByRole('dialog', { name: /^Objectifs · / })).toBeVisible();
      await expect.poll(() => requests).toBeGreaterThan(enabledRequests);
      await page.keyboard.press('Escape');
    } finally {
      await page.unroute('**/api/v1/goals/evaluations*');
      await savePreferences(page, { ...original, version: (await readPreferences(page)).version });
    }
  });
}

test('toolbar failure recovery and conflict preserve the current preference and columns', async ({
  page,
}) => {
  await page.goto('/budget/2026');
  await settled(page);
  const original = await readPreferences(page);
  try {
    await savePreferences(page, { ...original, goalMarks: false });
    await page.reload();
    await settled(page);
    let fail = true;
    await page.route(`**${preferencesUrl}`, async (route) => {
      if (route.request().method() === 'PUT' && fail) {
        fail = false;
        await route.fulfill({
          status: 500,
          contentType: 'application/problem+json',
          json: { status: 500 },
        });
      } else await route.continue();
    });
    await toggle(page).click();
    await expect(page.getByRole('alert')).toContainText(
      'Les repères d’objectifs n’ont pas pu être enregistrés.',
    );
    await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
    await page.getByRole('button', { name: 'Réessayer', exact: true }).click();
    await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
    await settled(page);
    await page.unroute(`**${preferencesUrl}`);
    // A second writer updates the saved preference behind this browser's cached version.
    const current = await readPreferences(page);
    await savePreferences(page, { ...current, columns: ['cashIncome'], goalMarks: false });
    await toggle(page).click();
    await expect(
      page.getByText('Les préférences ont changé ailleurs : elles ont été rechargées.'),
    ).toBeVisible();
    await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
    expect((await readPreferences(page)).columns).toEqual(['cashIncome']);
  } finally {
    await page.unroute(`**${preferencesUrl}`);
    await savePreferences(page, { ...original, version: (await readPreferences(page)).version });
  }
});

test('report mark toggle is named and keyboard reachable at 360 px', async ({ browser, page }) => {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    ignoreHTTPSErrors: true,
    storageState: await page.context().storageState(),
    viewport: { width: 360, height: 740 },
    hasTouch: true,
    isMobile: true,
    locale: 'fr-FR',
  });
  const phone = await context.newPage();
  try {
    await phone.goto('/budget/2026?view=multi&from=2025&to=2026&display=detailed');
    await settled(phone);
    await expect(toggle(phone)).toBeVisible();
    await toggle(phone).focus();
    await expect(toggle(phone)).toBeFocused();
    await phone.keyboard.press('Tab');
    await expect(phone.getByRole('button', { name: 'Colonnes', exact: true })).toBeFocused();
    await auditAccessibility(phone);
    expect(await auditLayout(phone, true)).toEqual([]);
    expect(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  } finally {
    await context.close();
  }
});
