import { expect, test } from '@playwright/test';
import { resolveFixtures } from './support/a11y';
import { settled } from './support/page';

test.use({ hasTouch: false });

async function expectHoverLooksActive(page: import('@playwright/test').Page, navName: string) {
  const nav = page.getByRole('navigation', { name: navName });
  const active = nav.locator('[aria-current="page"]');
  const activeBorder = await active.evaluate((el) => getComputedStyle(el).borderBottomColor);
  const inactive = nav.locator('a:not([aria-current="page"])').first();
  await inactive.hover();
  await expect
    .poll(() => inactive.evaluate((el) => getComputedStyle(el).borderBottomColor))
    .toBe(activeBorder);
  expect(await inactive.evaluate((el) => getComputedStyle(el).textDecorationLine)).toBe('none');
  await active.hover();
  expect(await active.evaluate((el) => getComputedStyle(el).textDecorationLine)).toBe('none');
}

test('hovering a settings tab never underlines it and previews the active look', async ({
  page,
}) => {
  await page.goto('/settings/profile');
  await settled(page);
  await expectHoverLooksActive(page, 'Sections des paramètres');
});

test('hovering an account tab never underlines it and previews the active look', async ({
  page,
}) => {
  const fixtures = await resolveFixtures(page);
  await page.goto(fixtures.account);
  await settled(page);
  await expectHoverLooksActive(page, 'Sections du compte');
});
