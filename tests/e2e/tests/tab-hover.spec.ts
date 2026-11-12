import { expect, test } from '@playwright/test';
import { resolveFixtures } from './support/a11y';
import { settled } from './support/page';

test.use({ hasTouch: false });

async function expectHoverPreservesSelection(
  page: import('@playwright/test').Page,
  navName: string,
) {
  const nav = page.getByRole('navigation', { name: navName });
  const active = nav.locator('[aria-current="page"]');
  const activeBorder = await active.evaluate((el) => getComputedStyle(el).borderBottomColor);
  const hoverBorder = await nav.evaluate((el) => {
    // The production build may minify a token to hex; compare computed colours in one format.
    const probe = document.createElement('span');
    probe.style.color = 'var(--border-strong)';
    el.append(probe);
    const color = getComputedStyle(probe).color;
    probe.remove();
    return color;
  });
  expect(activeBorder).not.toBe(hoverBorder);
  const inactive = nav.locator('a:not([aria-current="page"])').first();
  await inactive.hover();
  await expect
    .poll(() => inactive.evaluate((el) => getComputedStyle(el).borderBottomColor))
    .toBe(hoverBorder);
  await expect(active).toHaveCSS('border-bottom-color', activeBorder);
  await expect(inactive).toHaveCSS(
    'color',
    await active.evaluate((el) => getComputedStyle(el).color),
  );
  await expect(inactive).not.toHaveAttribute('aria-current', 'page');
  expect(await inactive.evaluate((el) => getComputedStyle(el).textDecorationLine)).toBe('none');
  await active.hover();
  expect(await active.evaluate((el) => getComputedStyle(el).textDecorationLine)).toBe('none');
}

test('hovering a settings tab keeps the selected tab distinct without underlining text', async ({
  page,
}) => {
  await page.goto('/settings/profile');
  await settled(page);
  await expectHoverPreservesSelection(page, 'Sections des paramètres');
});

test('hovering an account tab keeps the selected tab distinct without underlining text', async ({
  page,
}) => {
  const fixtures = await resolveFixtures(page);
  await page.goto(fixtures.account);
  await settled(page);
  await expectHoverPreservesSelection(page, 'Sections du compte');
});
