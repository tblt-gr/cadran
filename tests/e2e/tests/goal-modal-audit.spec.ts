import { expect, test, type Page } from '@playwright/test';
import { auditAccessibility } from './support/a11y';
import { auditLayout } from './support/audit';
import { settled, uniqueLabel } from './support/page';
import { ownerStorageState } from './support/session';

async function openGoalDialog(page: Page, kind: 'create' | 'edit' | 'policy') {
  // The emergency fund card lives on the dashboard; the goals open from the budget panel.
  await page.goto(kind === 'policy' ? '/' : '/goals');
  await settled(page);
  if (kind === 'policy') {
    await page.getByRole('button', { name: 'Modifier la politique', exact: true }).click();
  } else {
    await page.getByRole('button', { name: 'Nouvel objectif', exact: true }).click();
    await settled(page);
    if (kind === 'edit') {
      const label = uniqueLabel('Audit objectif modifiable');
      const creation = page.getByRole('dialog', { name: 'Nouvel objectif' });
      await creation.getByLabel('Libellé', { exact: true }).fill(label);
      await creation.getByLabel('Montant cible', { exact: true }).fill('120.00');
      await creation.getByRole('button', { name: 'Enregistrer', exact: true }).click();
      await expect(creation).toBeHidden();
      await settled(page);
      await page
        .getByRole('button', { name: `Actions de l’objectif « ${label} »`, exact: true })
        .click();
      await page
        .getByRole('button', { name: `Modifier l’objectif « ${label} »`, exact: true })
        .click();
    }
  }
  await settled(page);
  return page.getByRole('dialog', {
    name:
      kind === 'policy'
        ? 'Politique du fonds d’urgence'
        : kind === 'edit'
          ? 'Modifier l’objectif'
          : 'Nouvel objectif',
    exact: true,
  });
}

for (const kind of ['create', 'edit', 'policy'] as const) {
  for (const width of [360, 1280]) {
    test(`goal modal ${kind} at ${width} px`, async ({ browser }) => {
      const touch = width === 360;
      const context = await browser.newContext({
        baseURL: process.env.E2E_BASE_URL ?? 'https://localhost:8443',
        ignoreHTTPSErrors: true,
        storageState: ownerStorageState,
        viewport: { width, height: touch ? 740 : 800 },
        hasTouch: touch,
        isMobile: touch,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
      });
      try {
        const page = await context.newPage();
        const dialog = await openGoalDialog(page, kind);
        await expect(dialog).toBeVisible();
        await expect(
          dialog.getByText(
            kind === 'create' ? 'Création' : kind === 'edit' ? 'Modification' : 'Paramétrage',
            { exact: true },
          ),
        ).toBeVisible();
        await dialog.evaluate((element) => element.setAttribute('data-audit-root', ''));
        expect(await auditLayout(page, touch, '[data-audit-root]')).toEqual([]);
        await auditAccessibility(page);
        const save = dialog.getByRole('button', { name: 'Enregistrer', exact: true });
        await save.scrollIntoViewIfNeeded();
        await expect(save).toBeInViewport({ ratio: 0.99 });
        const form = (await dialog.locator('form').boundingBox())!;
        const action = (await save.boundingBox())!;
        expect(action.width).toBeLessThan(form.width * 0.75);
        expect(Math.abs(action.x + action.width - form.x - form.width)).toBeLessThan(2);
        const close = dialog.getByRole('button', { name: 'Fermer', exact: true });
        await close.focus();
        await expect(close).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(dialog).toBeHidden();
      } finally {
        await context.close();
      }
    });
  }
}
