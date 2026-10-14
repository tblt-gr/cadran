import { expect, type Page } from '@playwright/test';

/**
 * Waits until the page has stopped loading: the network is quiet, no skeleton block is left and no
 * region or status still announces itself as busy. Every spec calls it before acting, because a
 * skeleton keeps its controls disabled; networkidle alone misses a late render after the last
 * request.
 */
export async function settled(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle');
  await expect(page.locator('[data-skeleton]')).toHaveCount(0);
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
}

/** Opens a page from the main navigation, the way a user reaches it. */
export async function navigateTo(page: Page, label: string): Promise<void> {
  await page
    .getByRole('navigation', { name: 'Navigation principale' })
    .getByRole('link', { name: label, exact: true })
    .click();
  await settled(page);
}

/** A label no earlier run used, so a spec can find the rows it created. */
export function uniqueLabel(prefix: string): string {
  return `${prefix} ${Date.now().toString(36)}`;
}
