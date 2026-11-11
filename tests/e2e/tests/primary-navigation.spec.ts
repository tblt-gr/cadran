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
    const expected =
      width === 360
        ? ['/', '/budget', '/accounts']
        : ['/', '/budget', '/accounts', '/investments', '/parameters/interface'];
    expect(
      await navigation
        .getByRole('link')
        .evaluateAll((links) => links.map((link) => link.getAttribute('href'))),
    ).toEqual(expected);
    await navigation.getByRole('link', { name: 'Synthèse' }).focus();
    await page.keyboard.press('Tab');
    await expect(navigation.getByRole('link', { name: 'Budget' })).toBeFocused();
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
