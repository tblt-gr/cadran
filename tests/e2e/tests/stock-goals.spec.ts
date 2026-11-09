import { expect, test } from '@playwright/test';
import { settled, uniqueLabel } from './support/page';

test('creates a yearly stock ceiling and shows its server start value', async ({ page }) => {
  const year = new Intl.DateTimeFormat('en', { timeZone: 'Europe/Paris', year: 'numeric' }).format(
    new Date(),
  );
  await page.goto(`/budget/${year}?panel=goals`);
  await settled(page);
  await page.getByRole('button', { name: 'Nouvel objectif', exact: true }).click();
  await settled(page);
  const form = page.getByRole('dialog', { name: 'Nouvel objectif', exact: true });
  const label = uniqueLabel('E2E patrimoine plafond');
  await form.getByLabel('Libellé', { exact: true }).fill(label);
  await form.getByRole('combobox', { name: 'Indicateur', exact: true }).selectOption('NET_WORTH');
  await form.getByRole('radio', { name: 'Annuel', exact: true }).check();
  await form
    .getByRole('combobox', { name: 'Sens de l’objectif', exact: true })
    .selectOption('CEILING');
  await form.getByLabel('Valeur à la fin de la période', { exact: true }).fill('20000');
  await expect(form.getByRole('combobox', { name: 'Type de valeur', exact: true })).toBeDisabled();
  const saved = page.waitForResponse(
    (response) =>
      response.url().endsWith('/api/v1/goals') && response.request().method() === 'POST',
  );
  await form.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  const response = await saved;
  expect(response.status()).toBe(201);
  const goal = await response.json();
  expect(goal.metric).toBe('NET_WORTH');
  expect(goal.direction).toBe('CEILING');
  await expect(form).toBeHidden();
  await settled(page);
  const panel = page.getByRole('dialog', { name: /^Objectifs/ });
  const row = panel.locator(`[data-goal-id="${goal.id}"]`);
  await expect(row.getByRole('heading', { name: label, exact: true })).toBeVisible();
  await expect(row.getByText(/Départ /)).toHaveCount(0);
  await row.getByRole('button', { name: `Expliquer l’objectif « ${label} »`, exact: true }).click();
  const details = page.getByRole('dialog', {
    name: `Détails de l’objectif « ${label} »`,
    exact: true,
  });
  await expect(details.getByText(/Départ /)).toBeVisible();
  await expect(
    details.getByRole('link', { name: 'Voir la composition du patrimoine net' }),
  ).toHaveAttribute('href', '/accounts');
  await page.keyboard.press('Escape');
  await expect(details).toBeHidden();
  await expect(
    row.getByRole('button', { name: `Expliquer l’objectif « ${label} »`, exact: true }),
  ).toBeFocused();
});
