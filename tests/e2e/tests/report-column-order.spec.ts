import { expect, test, type Page } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { auditLayout } from './support/audit';
import { settled } from './support/page';

const preferencesUrl = '/api/v1/reports/annual/preferences';
const initial = ['cashIncome', 'budgetExpenses', 'cashSavingsRate', 'endNetWorth'];
const moved = [...initial.slice(1), initial[0]!];
interface Preferences {
  columns: string[];
  incompleteMonths: 'include' | 'exclude';
  goalMarks: boolean;
  version: number;
}
async function preferences(page: Page): Promise<Preferences> {
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
const dialog = (page: Page) => page.getByRole('dialog', { name: 'Colonnes du rapport' });
const ids = (page: Page) =>
  dialog(page)
    .locator('li[data-column-id]')
    .evaluateAll((rows) => rows.map((row) => row.getAttribute('data-column-id')));
async function open(page: Page) {
  await page.getByRole('button', { name: 'Colonnes', exact: true }).click();
  await settled(page);
}
async function dragIncomeToEnd(page: Page) {
  const handle = dialog(page).getByRole('button', { name: 'Déplacer : Revenus', exact: true });
  await handle.scrollIntoViewIfNeeded();
  const start = await handle.boundingBox();
  const end = await dialog(page).locator('li[data-column-id]').last().boundingBox();
  await page.mouse.move(start!.x + start!.width / 2, start!.y + start!.height / 2);
  await page.mouse.down();
  await page.mouse.move(start!.x + start!.width / 2, end!.y + end!.height - 2, { steps: 8 });
  await expect(dialog(page).locator('[data-drop-after]')).toHaveCount(1);
  await page.mouse.up();
  await expect.poll(() => ids(page)).toEqual(moved);
}

test('reorders report columns by mouse, cancels, recovers save failure and persists in both reports', async ({
  page,
}) => {
  await page.goto('/budget/2026');
  await settled(page);
  const original = await preferences(page);
  try {
    await savePreferences(page, { ...original, columns: initial });
    await page.reload();
    await settled(page);
    await open(page);
    await dragIncomeToEnd(page);
    await expect(dialog(page).getByRole('button', { name: 'Déplacer : Revenus' })).toBeFocused();
    await dialog(page).getByRole('button', { name: 'Annuler', exact: true }).click();
    await open(page);
    expect(await ids(page)).toEqual(initial);

    await dragIncomeToEnd(page);
    let failed = false;
    await page.route(`**${preferencesUrl}`, async (route) => {
      if (route.request().method() === 'PUT' && !failed) {
        failed = true;
        await route.fulfill({
          status: 500,
          contentType: 'application/problem+json',
          json: { status: 500 },
        });
      } else await route.continue();
    });
    await dialog(page).getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(dialog(page).getByRole('alert')).toContainText(
      'Vos modifications sont conservées',
    );
    expect(await ids(page)).toEqual(moved);
    await dialog(page).getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(dialog(page)).toBeHidden();
    await settled(page);
    await page.unroute(`**${preferencesUrl}`);
    expect((await preferences(page)).columns).toEqual(moved);

    await page.reload();
    await settled(page);
    await expect(
      page.getByRole('table', { name: 'Rapport 2026', exact: true }).getByRole('columnheader'),
    ).toHaveText(['Mois', 'Dépenses', 'Taux d’épargne', 'Patrimoine net', 'Revenus']);
    await open(page);
    expect(await ids(page)).toEqual(moved);
    await auditAccessibility(page);
    await test.info().attach('columns-desktop', {
      body: await dialog(page).screenshot(),
      contentType: 'image/png',
    });
    await dialog(page).getByRole('button', { name: 'Annuler', exact: true }).click();
    await page.getByRole('button', { name: 'Pluriannuel', exact: true }).click();
    await settled(page);
    await expect(page.getByRole('table').first().getByRole('columnheader')).toHaveText([
      'Année',
      'Dépenses',
      'Taux d’épargne',
      'Patrimoine net',
      'Revenus',
    ]);
    await open(page);
    const handle = dialog(page).getByRole('button', { name: 'Déplacer : Revenus' });
    await handle.focus();
    await page.keyboard.press('Home');
    await expect.poll(() => ids(page)).toEqual(initial);
    await expect(handle).toBeFocused();
    await page.keyboard.press('End');
    await expect.poll(() => ids(page)).toEqual(moved);
    await dialog(page).getByRole('button', { name: 'Annuler', exact: true }).click();
  } finally {
    await page.unroute(`**${preferencesUrl}`);
    await savePreferences(page, { ...original, version: (await preferences(page)).version });
  }
});

test('touch reordering scrolls a long selected list at 360 px and keeps keyboard alternatives', async ({
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
  await phone.goto('/budget/2026');
  await settled(phone);
  const original = await preferences(phone);
  const columns = [
    ...initial,
    'nonCashBenefits',
    'benefitSpending',
    'uncategorizedExpenses',
    'budgetSurplus',
    'savingsInflows',
    'savingsWithdrawals',
    'netSavingsTransfers',
    'netSavingsRate',
    'netWorthDelta',
    'axis:DISCRETIONARY',
    'axis:ESSENTIAL',
    'axis:FIXED',
    'axis:PERSONAL',
    'axis:PROFESSIONAL',
    'axis:VARIABLE',
  ];
  try {
    await savePreferences(phone, { ...original, columns });
    await phone.reload();
    await settled(phone);
    await open(phone);
    const handle = dialog(phone).getByRole('button', { name: 'Déplacer : Revenus', exact: true });
    await handle.scrollIntoViewIfNeeded();
    const box = await handle.boundingBox();
    const bottom = await handle.evaluate((element) => {
      for (let parent = element.parentElement; parent; parent = parent.parentElement) {
        if (/(auto|scroll)/.test(getComputedStyle(parent).overflowY))
          return parent.getBoundingClientRect().bottom;
      }
      throw new Error('Missing modal scroll container');
    });
    const cdp = await context.newCDPSession(phone);
    const x = box!.x + box!.width / 2;
    await cdp.send('Input.dispatchTouchEvent', {
      type: 'touchStart',
      touchPoints: [{ x, y: box!.y + box!.height / 2 }],
    });
    await cdp.send('Input.dispatchTouchEvent', {
      type: 'touchMove',
      touchPoints: [{ x, y: bottom - 4 }],
    });
    await expect(dialog(phone).locator('li[data-column-id]').last()).toHaveAttribute(
      'data-drop-after',
      'true',
    );
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await expect.poll(() => ids(phone)).toEqual([...columns.slice(1), columns[0]]);
    await expect(handle).toBeFocused();
    await handle.press('Home');
    await expect.poll(() => ids(phone)).toEqual(columns);
    await expect(handle).toBeFocused();
    await auditAccessibility(phone);
    await test.info().attach('columns-mobile-360', {
      body: await dialog(phone).screenshot(),
      contentType: 'image/png',
    });
    expect(await auditLayout(phone, true, '[role="dialog"]')).toEqual([]);
    expect(
      await dialog(phone).evaluate((element) => element.scrollWidth <= element.clientWidth),
    ).toBe(true);
    await dialog(phone).getByRole('button', { name: 'Annuler', exact: true }).click();
  } finally {
    await savePreferences(phone, { ...original, version: (await preferences(phone)).version });
    await context.close();
  }
});
