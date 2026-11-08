import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';
import { settled } from './page';

export async function auditAccessibility(page: Page): Promise<void> {
  await settled(page);
  const { violations } = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
    .analyze();
  // Report rules and selectors, never the financial contents of the offending nodes.
  expect(
    violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) })),
  ).toEqual([]);
}

export interface AuditFixtures {
  account: string;
  portfolio: string;
  insurance: string;
  instrument: string;
}

export async function resolveFixtures(page: Page): Promise<AuditFixtures> {
  async function link(path: string, pattern: RegExp, label?: RegExp): Promise<string> {
    await page.goto(path);
    await settled(page);
    const links = label ? page.getByRole('link', { name: label }) : page.locator('a[href]');
    let href: string | undefined;
    await expect
      .poll(
        async () => {
          const hrefs = await links.evaluateAll((items) =>
            items.map((item) => item.getAttribute('href') ?? ''),
          );
          href = hrefs.find((item) => pattern.test(item));
          return href;
        },
        { message: `Required accessibility fixture missing on ${path}: ${pattern}` },
      )
      .toBeTruthy();
    return href!.split('?')[0]!;
  }
  return {
    account: await link('/accounts', /^\/accounts\/[0-9a-f-]{36}$/),
    portfolio: await link('/accounts', /^\/portfolios\/[0-9a-f-]{36}$/, /Compte-titres/),
    instrument: await link(
      '/investments/instruments',
      /^\/investments\/instruments\/[0-9a-f-]{36}$/,
    ),
    insurance: await link('/accounts', /^\/portfolios\/[0-9a-f-]{36}$/, /Assurance/i),
  };
}

export const routeCases = [
  '/',
  '/accounts',
  '/accounts/groups',
  'account',
  'account:operations',
  '/parameters/catalog',
  '/parameters/product-models',
  '/transactions',
  '/transactions?period=month',
  '/transactions?direction=OUTFLOW',
  '/transactions/categories',
  '/transactions/categories/rules',
  '/transactions/recurrences',
  '/budget',
  'month',
  'annual',
  'multi-year',
  'month:goals-panel',
  'annual:goals-panel',
  '/investments',
  '/investments?view=asset',
  '/investments/instruments',
  'instrument',
  '/parameters/interface',
  '/parameters/profile',
  '/parameters/metric-policy',
  '/settings/profile',
  '/settings/metric-policy',
  '/catalog',
  '/product-models',
  ...['account', 'operations', 'positions', 'valuations', 'performance'].flatMap((tab) => [
    `portfolio:${tab}`,
    `insurance:${tab}`,
  ]),
  'insurance:contract',
];

export function routePath(route: string, fixtures: AuditFixtures): string {
  const now = new Date();
  if (route === 'month') {
    const previous = new Date(now.getFullYear(), now.getMonth() - 1, 1);
    return `/budget/${previous.getFullYear()}/${String(previous.getMonth() + 1).padStart(2, '0')}`;
  }
  if (route === 'month:goals-panel') return `${routePath('month', fixtures)}?panel=goals`;
  if (route === 'annual:goals-panel') return `${routePath('annual', fixtures)}?panel=goals`;
  if (route === 'annual') return `/budget/${now.getFullYear()}`;
  if (route === 'multi-year') return `/budget/${now.getFullYear()}?view=multi`;
  const [key, tab] = route.split(':');
  if (key && key in fixtures) {
    const path = fixtures[key as keyof AuditFixtures];
    return tab ? `${path}?tab=${tab}` : path;
  }
  return route;
}
