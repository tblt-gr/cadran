import { expect, test } from '@playwright/test';
import { settled } from './support/page';

for (const width of [360, 768, 1440]) {
  test.describe(`interface polish at ${width}px`, () => {
    test.use({ viewport: { width, height: 900 }, hasTouch: width < 1024 });

    test('keeps search, filters and bulk editing together without moving the list', async ({
      page,
    }) => {
      await page.goto('/transactions');
      await settled(page);
      const search = page.getByRole('searchbox').locator('..');
      const edit = page.getByRole('button', { name: 'Édition groupée', exact: true });
      const filters = page.getByRole('button', { name: /^Filtres/ });
      const boxes = await Promise.all(
        [search, filters, edit].map((control) => control.boundingBox()),
      );
      expect(boxes.every(Boolean)).toBe(true);
      expect(
        Math.max(...boxes.map((box) => box!.y)) - Math.min(...boxes.map((box) => box!.y)),
      ).toBeLessThanOrEqual(4);
      await edit.click();
      await expect(
        page.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }),
      ).toBeVisible();
      await edit.click();
      await expect(
        page.getByRole('checkbox', { name: 'Sélectionner les mouvements chargés' }),
      ).toHaveCount(0);
    });

    test('keeps the year beside the scrollable months and route focus off the page chrome', async ({
      page,
    }) => {
      await page.goto('/budget');
      await settled(page);
      const year = await page.getByRole('combobox', { name: 'Année', exact: true }).boundingBox();
      const months = await page
        .getByRole('navigation', { name: 'Mois du budget', exact: true })
        .boundingBox();
      expect(year).not.toBeNull();
      expect(months).not.toBeNull();
      expect(Math.abs(year!.y - months!.y)).toBeLessThanOrEqual(8);
      await expect(page.getByRole('heading', { level: 1 })).toHaveCSS('outline-style', 'none');
    });

    test('keeps long-form actions visible while the recurrence fields scroll', async ({ page }) => {
      await page.goto('/transactions/recurrences');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle récurrence', exact: true }).click();
      const dialog = page.getByRole('dialog');
      await expect(dialog).toBeVisible();
      const actions = dialog.locator('[data-modal-actions]');
      await expect(actions).toBeInViewport({ ratio: 1 });
      const fields = dialog.getByRole('textbox');
      await fields.first().focus();
      await expect(fields.first()).toBeInViewport();
      await expect(actions).toBeInViewport({ ratio: 1 });
      await fields.last().focus();
      await expect(fields.last()).toBeInViewport();
      await expect(actions).toBeInViewport({ ratio: 1 });
      await page.keyboard.press('Escape');
      await expect(dialog).toHaveCount(0);
    });

    test('keeps transaction save visible while its fields scroll independently', async ({
      page,
    }) => {
      await page.goto('/transactions');
      await settled(page);
      await page.getByRole('button', { name: 'Nouvelle transaction', exact: true }).click();
      const dialog = page.getByRole('dialog');
      const save = dialog.getByRole('button', { name: 'Enregistrer', exact: true });
      await expect(save).toBeInViewport({ ratio: 1 });
      await dialog.getByRole('textbox', { name: 'Libellé', exact: true }).focus();
      await expect(save).toBeInViewport({ ratio: 1 });
      await dialog.getByRole('combobox', { name: 'Catégorie', exact: true }).focus();
      await expect(save).toBeInViewport({ ratio: 1 });
      const form = dialog.locator('form');
      expect(await save.evaluate((button) => (button as HTMLButtonElement).form !== null)).toBe(
        true,
      );
      await expect(form).toHaveCount(1);
    });
  });
}

test('investment totals form a readable two-column summary on a phone', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/investments');
  await settled(page);
  const value = await page
    .getByRole('heading', { name: 'Valeur des titres', exact: true })
    .boundingBox();
  const gain = await page
    .getByRole('heading', { name: 'Plus-value latente', exact: true })
    .boundingBox();
  expect(value).not.toBeNull();
  expect(gain).not.toBeNull();
  expect(Math.abs(value!.y - gain!.y)).toBeLessThanOrEqual(1);
  expect(gain!.x).toBeGreaterThan(value!.x + value!.width);
});

test('keeps desktop movements on one scan line with their account and category', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/transactions');
  await settled(page);
  const row = page.locator('li[data-state="BOOKED"]').first();
  const boxes = await row.evaluate((element) => {
    const primary = element.querySelector(':scope > div')!.getBoundingClientRect();
    const amount = element.querySelector(':scope > span')!.getBoundingClientRect();
    const meta = element.querySelectorAll(':scope > div')[1]!.getBoundingClientRect();
    return {
      height: element.getBoundingClientRect().height,
      primaryCenter: primary.y + primary.height / 2,
      amountCenter: amount.y + amount.height / 2,
      metaCenter: meta.y + meta.height / 2,
    };
  });
  expect(boxes.height).toBeLessThan(80);
  expect(Math.abs(boxes.primaryCenter - boxes.amountCenter)).toBeLessThan(2);
  expect(Math.abs(boxes.primaryCenter - boxes.metaCenter)).toBeLessThan(2);
});

test('uses restrained action corners and one plate for every dashboard figure', async ({
  page,
}) => {
  await page.goto('/');
  await settled(page);
  // The production build may minify a token to hex; compare computed colours in one format.
  const plate = await page.evaluate(() => {
    const probe = document.createElement('span');
    probe.style.backgroundColor = 'var(--bg-surface-1)';
    document.body.append(probe);
    const color = getComputedStyle(probe).backgroundColor;
    probe.remove();
    return color;
  });
  for (const name of ['Patrimoine net', 'Revenus', 'Dépenses', 'Taux d’épargne']) {
    const panel = page.getByRole('region', { name, exact: true });
    await expect(panel).toHaveCSS('background-color', plate);
    await expect(panel).toHaveCSS('border-top-width', '1px');
  }
  await page.goto('/transactions');
  await settled(page);
  const create = page.getByRole('button', { name: 'Nouvelle transaction', exact: true });
  expect(
    await create.evaluate((el) => parseFloat(getComputedStyle(el).borderTopLeftRadius)),
  ).toBeLessThanOrEqual(8);
});

test('keeps reduced-motion dialogs immediate and preserves their keyboard return path', async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/transactions');
  await settled(page);
  const create = page.getByRole('button', { name: 'Nouvelle transaction', exact: true });
  await create.click();
  const dialog = page.getByRole('dialog', { name: 'Nouvelle transaction', exact: true });
  await expect(dialog).toBeVisible();
  expect(
    await dialog.evaluate((el) => parseFloat(getComputedStyle(el).animationDuration)),
  ).toBeLessThanOrEqual(0.001);
  await expect(dialog.getByRole('button', { name: 'Enregistrer', exact: true })).toBeInViewport();
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(create).toBeFocused();
});
