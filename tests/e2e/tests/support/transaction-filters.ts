import { expect, type Locator, type Page } from '@playwright/test';
import { settled } from './page';

const LONG_ACCOUNT_LABEL =
  'Compte courant commun pour les dépenses et les projets de toute la famille';

/** Stress the label width without changing ids, transactions or the real facet response. */
export async function withLongAccountLabel(page: Page): Promise<void> {
  let targetId: string | null = null;
  await page.route(
    (url) => url.pathname === '/api/v1/accounts',
    async (route) => {
      const response = await route.fetch();
      expect(response.ok()).toBe(true);
      const body = await response.json();
      expect(body.items.length).toBeGreaterThan(0);
      targetId ??= body.items[0].id;
      for (const account of body.items)
        if (account.id === targetId) account.label = LONG_ACCOUNT_LABEL;
      await route.fulfill({ response, json: body });
    },
  );
}

/** Open every counted section: the normal route audit otherwise sees only closed summaries. */
export async function expandCountedFilters(page: Page, panel: Locator): Promise<void> {
  for (const title of ['Comptes', 'Catégories', 'Nature', 'État', 'Origine', 'Axes analytiques']) {
    await panel.locator('summary', { hasText: title }).click();
  }
  await settled(page);
  const more = panel.getByRole('button', { name: /^Afficher plus/ });
  while (await more.count()) await more.first().click();
  await settled(page);
}

/** Real counts keep their digits, alignment and accessible sentence beside a long label. */
export async function expectFacetRowsFit(panel: Locator): Promise<void> {
  for (const title of ['Comptes', 'Catégories', 'Nature', 'État', 'Origine', 'Axes analytiques']) {
    const group = panel.getByRole('group', { name: title, exact: true });
    await expect(
      group.getByRole('checkbox', { name: /, \d+ transactions?$/ }).first(),
    ).toBeVisible();
  }
  await expect(
    panel.getByRole('checkbox', {
      name: new RegExp(`^${LONG_ACCOUNT_LABEL} *, [0-9]+ transactions?$`),
    }),
  ).toBeVisible();
  const rows = await panel.locator('label').evaluateAll((labels) =>
    labels.flatMap((label) => {
      const count = [
        ...label.querySelectorAll<HTMLElement>(':scope > span[aria-hidden="true"]'),
      ].find((span) => /^\d+$/.test(span.textContent ?? ''));
      if (!count) return [];
      const box = label.getBoundingClientRect();
      const digits = count.getBoundingClientRect();
      const text = count.previousElementSibling!.getBoundingClientRect();
      return [
        {
          contained: digits.left >= box.left && digits.right <= box.right + 1,
          overlapsLabel: text.right > digits.left + 1,
          clipped: count.scrollWidth > count.clientWidth,
          rightGap: box.right - digits.right,
          tabular: getComputedStyle(count).fontVariantNumeric,
        },
      ];
    }),
  );
  expect(rows.length).toBeGreaterThan(6);
  for (const row of rows) {
    expect(row.contained, 'count stays inside its option').toBe(true);
    expect(row.overlapsLabel, 'label leaves room for the count').toBe(false);
    expect(row.clipped, 'all count digits remain readable').toBe(false);
    expect(row.rightGap, 'count aligns to the option right edge').toBeLessThanOrEqual(1);
    expect(row.tabular).toBe('tabular-nums');
  }
  await expect(
    panel.getByRole('checkbox', { name: /, [1-9]\d* transactions?$/ }).first(),
  ).toBeVisible();
}
