import { expect, test, type Page } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { auditLayout } from './support/audit';
import { settled, uniqueLabel } from './support/page';

const picker = (page: Page) => page.getByRole('dialog', { name: 'Colonnes du rapport' });

/** The demo ledger has no sub-category: one parent with a child, created through the API. */
async function createCategoryPair(page: Page) {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'csrf_token');
  const create = async (label: string, parentId: string | null) => {
    const response = await page.request.post('/api/v1/categories', {
      headers: { 'X-CSRF-TOKEN': decodeURIComponent(token!.value) },
      data: {
        type: 'EXPENSE',
        label,
        parentId,
        icon: null,
        color: null,
        defaultAnalyticAxes: [],
        budgetIncluded: true,
        sortOrder: 0,
      },
    });
    expect(response.ok()).toBe(true);
    return (await response.json()) as { id: string };
  };
  const parent = uniqueLabel('E2E arbre parent');
  const child = uniqueLabel('E2E arbre enfant');
  const { id } = await create(parent, null);
  await create(child, id);
  return { parent, child };
}

async function openCategories(page: Page, search: string) {
  await page.goto(`/budget/2026${search}`);
  await settled(page);
  await page.getByRole('button', { name: 'Colonnes', exact: true }).click();
  await picker(page).getByRole('tab', { name: 'Catégories' }).click();
  const tree = picker(page).getByRole('tabpanel').getByRole('group', { name: 'Catégories' });
  await expect(tree.getByRole('checkbox').first()).toBeEnabled();
  return tree;
}

for (const [mode, search] of [
  ['annual', ''],
  ['multi-year', '?view=multi&from=2025&to=2026'],
] as const) {
  test(`${mode}: report categories are a tree and each checkbox selects only itself`, async ({
    page,
  }) => {
    const pair = await createCategoryPair(page);
    const tree = await openCategories(page, search);
    const parentBox = tree.getByRole('checkbox', { name: pair.parent, exact: true });
    const childBox = tree.getByRole('checkbox', { name: `${pair.parent} › ${pair.child}` });
    await expect(parentBox.locator('xpath=ancestor::li[1]').locator('ul')).toContainText(
      pair.child,
    );
    await childBox.check();
    await expect(parentBox).not.toBeChecked();

    await picker(page).getByRole('button', { name: 'Annuler' }).click();
    await expect(picker(page)).toBeHidden();
  });
}

test('category tree passes accessibility and layout audits at 360 px', async ({
  browser,
  page,
}) => {
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
    await openCategories(phone, '');
    await auditAccessibility(phone);
    expect(await auditLayout(phone, true)).toEqual([]);
    expect(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  } finally {
    await context.close();
  }
});
