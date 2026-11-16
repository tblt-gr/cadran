import { expect, test } from '@playwright/test';
import { settled } from './support/page';

for (const width of [1280, 360]) {
  test(`primary navigation keeps Budget after Home at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    await page.goto('/');
    await settled(page);

    const navigation = page.getByRole('navigation', {
      name: width === 360 ? 'Navigation mobile' : 'Navigation principale',
    });
    // The same five destinations in the sidebar and the phone bar.
    const expected = ['/', '/budget', '/accounts', '/investments', '/parameters/interface'];
    expect(
      await navigation
        .getByRole('link')
        .evaluateAll((links) => links.map((link) => link.getAttribute('href'))),
    ).toEqual(expected);
    const active = navigation.getByRole('link', { name: 'Synthèse' });
    await expect(active).toHaveCSS(
      'color',
      await page.locator('body').evaluate((el) => getComputedStyle(el).color),
    );
    // The current page carries a white rule over its whole entry: the left edge on the sidebar, the
    // bottom edge of the cell on the phone bar. Every other entry reserves the same transparent
    // border so nothing shifts.
    const side = width === 360 ? 'Bottom' : 'Left';
    const bodyColor = await page.locator('body').evaluate((el) => getComputedStyle(el).color);
    await expect(active).toHaveCSS(`border-${side.toLowerCase()}-width`, '2px');
    await expect(active).toHaveCSS(`border-${side.toLowerCase()}-color`, bodyColor);
    await expect(active).toHaveCSS('box-shadow', 'none');
    await expect(navigation.getByRole('link', { name: 'Budget' })).toHaveCSS(
      `border-${side.toLowerCase()}-width`,
      '2px',
    );
    await expect(navigation.getByRole('link', { name: 'Budget' })).toHaveCSS(
      `border-${side.toLowerCase()}-color`,
      'rgba(0, 0, 0, 0)',
    );
    await expect(navigation.getByRole('link', { name: 'Budget' })).toHaveCSS('box-shadow', 'none');
    await navigation.getByRole('link', { name: 'Synthèse' }).focus();
    await page.keyboard.press('Tab');
    await expect(navigation.getByRole('link', { name: 'Budget' })).toBeFocused();
    await expect(navigation.getByRole('link', { name: 'Budget' })).toHaveCSS(
      'outline-style',
      'solid',
    );
    await page.keyboard.press('Tab');
    await expect(navigation.getByRole('link', { name: 'Comptes' })).toBeFocused();

    await navigation.getByRole('link', { name: 'Budget' }).click();
    await settled(page);
    await expect(navigation.getByRole('link', { name: 'Budget' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    await page.goBack();
    await settled(page);
    await expect(navigation.getByRole('link', { name: 'Synthèse' })).toHaveAttribute(
      'aria-current',
      'page',
    );
  });
}

test.describe('tablet touch navigation', () => {
  test.use({ hasTouch: true });

  test('keeps all sidebar links at the 44 px touch minimum', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/');
    await settled(page);

    expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true);
    const navigation = page.getByRole('navigation', { name: 'Navigation principale' });
    await expect(navigation).toBeVisible();
    for (const link of await navigation.getByRole('link').all()) {
      const box = await link.boundingBox();
      expect(box?.width).toBeGreaterThanOrEqual(44);
      expect(box?.height).toBeGreaterThanOrEqual(44);
    }
  });
});
