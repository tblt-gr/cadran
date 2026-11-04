import { expect, test } from '@playwright/test';
import { navigateTo, settled } from './support/page';
import { auditAccessibility } from './support/a11y';

test.describe('portfolio operation', () => {
  test('keeps a later selection when a dismissed instrument creation responds late', async ({
    page,
  }) => {
    let releaseResponse!: () => void;
    let markResponseReady!: () => void;
    const release = new Promise<void>((resolve) => {
      releaseResponse = resolve;
    });
    const responseReady = new Promise<void>((resolve) => {
      markResponseReady = resolve;
    });
    await page.route('**/api/v1/instruments', async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      const response = await route.fetch();
      expect(response.ok()).toBe(true);
      markResponseReady();
      await release;
      await route.fulfill({ response });
    });
    try {
      await page.goto('/accounts');
      await settled(page);
      await page.getByRole('link', { name: 'Compte-titres', exact: true }).click();
      await settled(page);
      await page
        .getByRole('navigation', { name: 'Sections du portefeuille' })
        .getByRole('link', { name: 'Opérations' })
        .click();
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle opération' }).click();
      const parent = page.getByRole('dialog', { name: 'Nouvelle opération' });
      await parent.getByRole('combobox', { name: 'Type', exact: true }).selectOption('BUY');
      const input = parent.getByRole('combobox', { name: 'Instrument', exact: true });
      const name = `Late ${crypto.randomUUID()}`;
      await input.fill(name);
      await parent.getByRole('option', { name: `Créer « ${name} »` }).click();
      const child = page.getByRole('dialog', { name: 'Créer un instrument' });
      await expect(child.getByRole('textbox', { name: 'ISIN', exact: true })).toBeFocused();
      await child
        .getByRole('textbox', { name: 'Ticker', exact: true })
        .fill(`L${Date.now().toString(36)}`);
      await child.getByRole('button', { name: 'Enregistrer', exact: true }).click();
      await responseReady;
      await child.getByRole('button', { name: 'Fermer', exact: true }).click();
      await expect(child).toBeHidden();
      await expect(input).toBeFocused();
      await input.fill('ETF Monde Démo');
      await parent.getByRole('option', { name: /^ETF Monde Démo ·/ }).click();
      await expect(input).toHaveValue('ETF Monde Démo');
      const completed = page.waitForResponse(
        (response) =>
          response.request().method() === 'POST' &&
          new URL(response.url()).pathname === '/api/v1/instruments',
      );
      releaseResponse();
      await completed;
      await settled(page);
      await expect(input).toHaveValue('ETF Monde Démo');
      await expect(parent).toBeVisible();
      await parent.getByRole('button', { name: 'Fermer', exact: true }).click();
    } finally {
      releaseResponse();
    }
  });
  test('creates a missing instrument above a buy draft and restores the draft and focus', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto('/accounts');
    await settled(page);
    await page.getByRole('link', { name: 'Compte-titres', exact: true }).click();
    await settled(page);
    await page
      .getByRole('navigation', { name: 'Sections du portefeuille' })
      .getByRole('link', { name: 'Opérations' })
      .click();
    await settled(page);
    await page.getByRole('button', { name: 'Nouvelle opération' }).click();
    const parent = page.getByRole('dialog', { name: 'Nouvelle opération' });
    await parent.getByRole('combobox', { name: 'Type', exact: true }).selectOption('BUY');
    await parent.getByLabel('Quantité').fill('2.5');
    await parent.getByLabel('Prix unitaire').fill('20');
    await parent.getByLabel('Montant brut').fill('50');
    const date = await parent.getByLabel('Date d’opération').inputValue();
    const input = parent.getByRole('combobox', { name: 'Instrument', exact: true });
    const name = `World ESG ${Date.now()}`;
    await input.fill(name);
    await page.getByRole('option', { name: `Créer « ${name} »` }).waitFor();
    await input.press('ArrowDown');
    await input.press('Enter');
    let child = page.getByRole('dialog', { name: 'Créer un instrument' });
    await expect(child.getByLabel('ISIN')).toBeFocused();
    await expect(parent).toHaveAttribute('inert', '');
    await expect(child.getByLabel('Nom')).toHaveValue(name);
    await expect(child.getByRole('combobox', { name: 'Devise' })).toBeDisabled();
    await expect(child.getByText('Devise du compte')).toBeVisible();
    await auditAccessibility(page);
    await child.getByLabel('ISIN').press('Escape');
    await expect(child).toBeHidden();
    await expect(input).toBeFocused();
    await expect(input).toHaveValue(name);
    await page.getByRole('option', { name: `Créer « ${name} »` }).click();
    child = page.getByRole('dialog', { name: 'Créer un instrument' });
    await expect(child.getByRole('textbox', { name: 'ISIN', exact: true })).toBeFocused();
    await child
      .getByRole('textbox', { name: 'Ticker', exact: true })
      .fill(`T${Date.now().toString(36)}`);
    await child.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(child).toBeHidden();
    await expect(parent).toBeVisible();
    await expect(input).toBeFocused();
    await expect(input).toHaveValue(name);
    await expect(parent.getByLabel('Quantité')).toHaveValue('2.5');
    await expect(parent.getByLabel('Prix unitaire')).toHaveValue('20');
    await expect(parent.getByLabel('Date d’opération')).toHaveValue(date);
    await parent.getByRole('button', { name: 'Fermer', exact: true }).click();
  });
  test('records a deposit on the securities account and lists it among the operations', async ({
    page,
  }) => {
    // A distinctive amount finds the new row among the earlier runs' deposits.
    const euros = 10 + Math.floor(Math.random() * 90);
    const cents = String(1 + Math.floor(Math.random() * 98)).padStart(2, '0');

    await page.goto('/');
    await navigateTo(page, 'Comptes');
    await page.getByRole('link', { name: 'Compte-titres', exact: true }).click();
    await settled(page);

    await page
      .getByRole('navigation', { name: 'Sections du portefeuille' })
      .getByRole('link', { name: 'Opérations' })
      .click();
    await settled(page);

    await page.getByRole('button', { name: 'Nouvelle opération' }).click();
    const editor = page.getByRole('dialog', { name: 'Nouvelle opération' });
    await editor.getByLabel('Type').selectOption({ label: 'Versement' });
    await editor.getByLabel('Montant brut').fill(`${euros}.${cents}`);
    await expect(editor.getByText(/Effet sur les espèces/)).toBeVisible();
    await editor.getByRole('button', { name: 'Enregistrer' }).click();
    await expect(editor).toBeHidden();

    await settled(page);
    await expect(
      page.getByRole('row').filter({ hasText: new RegExp(`Versement.*${euros},${cents}\\s€`) }),
    ).not.toHaveCount(0);
  });
});
