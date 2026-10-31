import { expect, test } from '@playwright/test';
import {
  auditAccessibility,
  resolveFixtures,
  routeCases,
  routePath,
  type AuditFixtures,
} from './support/a11y';
import { settled } from './support/page';
import { modalCases, openAuditModal } from './support/a11y-modals';
import { keyboardJourneys } from './support/a11y-keyboard';

let fixtures: AuditFixtures;
test.beforeAll(async ({ browser }, testInfo) => {
  const context = await browser.newContext({ ...testInfo.project.use });
  try {
    fixtures = await resolveFixtures(await context.newPage());
  } finally {
    await context.close();
  }
});

for (const route of routeCases) {
  test(`WCAG AA ${route}`, async ({ page }) => {
    await page.goto(routePath(route, fixtures));
    await settled(page);
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await auditAccessibility(page);
  });

  test(`320 px reflow ${route}`, async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 740 });
    await page.goto(routePath(route, fixtures));
    await settled(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}

for (const modal of modalCases) {
  test(`WCAG AA modal ${modal}`, async ({ page }) => {
    await openAuditModal(page, modal, fixtures);
    await expect(page.getByRole('dialog').last()).toBeVisible();
    await auditAccessibility(page);
  });
}

test('account actions remain reachable when the viewport shrinks', async ({ page }) => {
  await page.goto('/accounts');
  await settled(page);
  const trigger = page.getByRole('button', { name: /^Actions du compte/ }).first();
  await trigger.click();
  const menu = page.locator(`[id="${await trigger.getAttribute('aria-controls')}"]`);
  await expect(menu).toBeInViewport({ ratio: 1 });
  const viewport = page.viewportSize()!;
  await page.setViewportSize({ width: viewport.width, height: 400 });
  await expect(menu).toBeInViewport({ ratio: 1 });
  await menu.getByRole('button', { name: /^Modifier/ }).click();
  await settled(page);
  await expect(page.getByRole('dialog')).toBeVisible();
});

keyboardJourneys();

test('budget variance names its positive and negative signs with the server status', async ({
  page,
}) => {
  await page.goto(fixtures.plan);
  await settled(page);
  const row = page
    .getByRole('row')
    .filter({ has: page.getByRole('button', { name: 'Modifier', exact: true }) })
    .first();
  const scope = (await row.getByRole('cell').first().innerText()).trim();
  const comparison = page
    .getByRole('region', { name: 'Résultats', exact: true })
    .getByRole('article')
    .filter({ has: page.getByRole('heading', { name: scope, exact: true }) });
  const editor = page.getByRole('dialog');
  await row.getByRole('button', { name: 'Modifier', exact: true }).click();
  const original = await editor.getByLabel('Montant', { exact: true }).inputValue();
  await editor.getByRole('button', { name: 'Fermer', exact: true }).click();

  async function setTarget(value: string) {
    await row.getByRole('button', { name: 'Modifier', exact: true }).click();
    await editor.getByLabel('Montant', { exact: true }).fill(value);
    await editor.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(editor).toBeHidden();
    await settled(page);
  }
  try {
    // The demo's first target has booked spending. The server computes both comparisons;
    // the browser only changes a target and checks the resulting signed announcement.
    await setTarget('1');
    await expect(
      comparison.getByRole('img', { name: /^Écart restant : -[\d\s,.]+€ : Dépassement$/ }),
    ).toBeVisible();
    await setTarget('1000000');
    await expect(
      comparison.getByRole('img', { name: /^Écart restant : \+[\d\s,.]+€ : Dans le budget$/ }),
    ).toBeVisible();
  } finally {
    // Keep the shared demo plan intact, including when a sign or status assertion fails.
    if (await editor.isVisible())
      await editor.getByRole('button', { name: 'Fermer', exact: true }).click();
    await setTarget(original);
  }
});

test('invalid transaction and transfer errors describe their controls', async ({ page }) => {
  for (const modal of ['transaction error', 'transfer error'] as const) {
    await openAuditModal(page, modal, fixtures);
    const invalid = page.getByRole('dialog').locator('[aria-invalid="true"]');
    expect(await invalid.count()).toBeGreaterThan(0);
    for (const field of await invalid.all()) {
      await expect(field).toHaveAccessibleDescription(/.+/);
    }
  }
});

test('dashboard and report charts retain readable data equivalents', async ({ page }) => {
  await page.goto('/');
  await settled(page);
  const wealth = page.locator('figure').filter({ has: page.locator('#wealth-chart-title') });
  await expect(wealth.getByRole('table')).toBeAttached();
  await expect(wealth.getByRole('table')).toHaveAccessibleName('Évolution du patrimoine');
  expect(await wealth.getByRole('table').getByRole('row').count()).toBeGreaterThan(1);
  await page.getByRole('button', { name: 'Camembert', exact: true }).click();
  const allocation = page.getByRole('region', { name: 'Allocation', exact: true });
  await expect(allocation.getByRole('list')).toBeVisible();
  expect(await allocation.getByRole('listitem').count()).toBeGreaterThan(0);
  for (const route of ['annual', 'multi-year']) {
    await page.goto(routePath(route, fixtures));
    await settled(page);
    const disclosures = page
      .locator('details')
      .filter({ has: page.getByText('Voir les données', { exact: true }) });
    expect(await disclosures.count()).toBeGreaterThanOrEqual(2);
    for (const disclosure of await disclosures.all()) {
      await disclosure.locator('summary').click();
      await expect(disclosure.getByRole('table')).toBeVisible();
      expect(await disclosure.getByRole('row').count()).toBeGreaterThan(1);
    }
    await auditAccessibility(page);
  }
});

for (const kind of ['portfolio', 'insurance'] as const) {
  test(`${kind} charts retain monthly text and complete allocation tables`, async ({
    page,
  }, testInfo) => {
    await page.goto(`${fixtures[kind]}?tab=performance`);
    await settled(page);
    if (testInfo.project.name === 'Mobile 360') {
      await expect(page.getByRole('article', { name: 'janvier', exact: true })).toBeVisible();
      await expect(page.getByRole('article', { name: 'décembre', exact: true })).toBeVisible();
    } else {
      const table = page.getByRole('table', { name: 'Performance par mois', exact: true });
      await expect(table).toBeVisible();
      expect(await table.getByRole('row').count()).toBeGreaterThanOrEqual(13);
    }
    await page.goto(`${fixtures[kind]}?tab=positions`);
    await settled(page);
    const disclosure = page.locator('summary').filter({ hasText: 'Toutes les lignes' });
    // Signed or non-calculable allocations expose the same table directly, without a donut.
    if (await disclosure.count()) await disclosure.click();
    // A contract splits its supports in its own table, beside the supports list.
    const table = page.getByRole('table', {
      name: kind === 'insurance' ? 'Répartition du contrat' : /toutes les lignes/i,
    });
    await expect(table).toBeVisible();
    expect(await table.getByRole('row').count()).toBeGreaterThan(1);
  });
}
