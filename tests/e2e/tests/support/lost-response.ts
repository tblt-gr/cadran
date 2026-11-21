import type { Page } from '@playwright/test';

/**
 * Lets the next POST matching `url` reach the server, then drops its response: the write is
 * committed while the browser sees a network failure, like a connection lost mid-save.
 */
export async function loseNextResponse(page: Page, url: string | RegExp): Promise<void> {
  let lost = false;
  await page.route(url, async (route) => {
    if (lost || route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    lost = true;
    await route.fetch();
    await route.abort('connectionreset');
  });
}
