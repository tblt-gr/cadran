import { expect, test } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { settled, uniqueLabel } from './support/page';

for (const [metric, direction] of [
  ['TOTAL_INCOME', 'MINIMUM'],
  ['TOTAL_SPENDING', 'CEILING'],
] as const) {
  test(`creates a ${metric} goal with its fixed direction at 360 px`, async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/goals');
    await settled(page);
    await page.getByRole('button', { name: 'Nouvel objectif', exact: true }).click();
    await settled(page);
    const form = page.getByRole('dialog', { name: 'Nouvel objectif', exact: true });
    const label = uniqueLabel(`E2E ${metric}`);
    await form.getByLabel('Libellé', { exact: true }).fill(label);
    await form.getByRole('combobox', { name: 'Indicateur', exact: true }).selectOption(metric);
    await expect(form.getByLabel('Périmètre', { exact: true })).toHaveCount(0);
    await expect(form.getByLabel('Sens de l’objectif', { exact: true })).toHaveCount(0);
    const valueType = form.getByRole('combobox', { name: 'Type de valeur', exact: true });
    if (metric === 'TOTAL_INCOME') await expect(valueType).toBeDisabled();
    else await expect(valueType).toBeEnabled();
    await form.getByLabel('Montant cible', { exact: true }).fill('3000');
    await auditAccessibility(page);
    const saved = page.waitForResponse(
      (response) =>
        response.url().endsWith('/api/v1/goals') && response.request().method() === 'POST',
    );
    await form.getByRole('button', { name: 'Enregistrer', exact: true }).focus();
    await page.keyboard.press('Enter');
    const response = await saved;
    expect(response.status()).toBe(201);
    const goal = await response.json();
    expect(goal.metric).toBe(metric);
    expect(goal.direction).toBe(direction);
    expect(goal.scope.type).toBe('WORKSPACE');
    await expect(form).toBeHidden();
    await settled(page);
    const row = page.locator(`[data-goal-id="${goal.id}"]`);
    await expect(row.getByRole('heading', { name: label, exact: true })).toBeVisible();
    await expect(row.getByText(/Tout l’espace/)).toBeVisible();
    await expect(page.locator('body')).toHaveJSProperty('scrollWidth', 360);
  });
}

for (const periodicity of ['Mensuel', 'Annuel']) {
  test(`creates total spending as a share of income for ${periodicity} at 360 px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/goals');
    await settled(page);
    await page.getByRole('button', { name: 'Nouvel objectif', exact: true }).click();
    await settled(page);
    const form = page.getByRole('dialog', { name: 'Nouvel objectif', exact: true });
    await form.getByLabel('Libellé', { exact: true }).fill(uniqueLabel('E2E part dépenses'));
    await form
      .getByRole('combobox', { name: 'Indicateur', exact: true })
      .selectOption('TOTAL_SPENDING');
    await form.getByRole('radio', { name: periodicity, exact: true }).check();
    const valueType = form.getByRole('combobox', { name: 'Type de valeur', exact: true });
    await expect(valueType.locator('option')).toHaveCount(2);
    await valueType.selectOption('INCOME_SHARE');
    await expect(valueType.locator('option:checked')).toHaveText(
      periodicity === 'Annuel' ? 'Part du revenu de l’année' : 'Part du revenu du mois',
    );
    await form.getByLabel('Pourcentage', { exact: true }).fill('80');
    await form.getByLabel('Tolérance', { exact: true }).fill('2');
    await auditAccessibility(page);
    const saved = page.waitForResponse(
      (response) =>
        response.url().endsWith('/api/v1/goals') && response.request().method() === 'POST',
    );
    await form.getByRole('button', { name: 'Enregistrer', exact: true }).focus();
    await page.keyboard.press('Enter');
    const response = await saved;
    expect(response.status()).toBe(201);
    const goal = await response.json();
    expect(goal.metric).toBe('TOTAL_SPENDING');
    expect(goal.periodicity).toBe(periodicity === 'Annuel' ? 'YEARLY' : 'MONTHLY');
    expect(goal.direction).toBe('CEILING');
    expect(goal.currentVersion.valueType).toBe('INCOME_SHARE');
    expect(goal.currentVersion.ratio).toMatch(/^0\.80*$/);
    expect(goal.currentVersion.tolerance).toMatch(/^0\.020*$/);
    expect(goal.currentVersion.schedule).toBeNull();
    await expect(form).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
