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
  plan: string;
  /** A monthly plan whose first target has booked spending, whatever the day of the month. */
  spendingPlan: string;
  portfolio: string;
  insurance: string;
}

export async function resolveFixtures(page: Page): Promise<AuditFixtures> {
  async function link(path: string, pattern: RegExp, label?: RegExp): Promise<string> {
    await page.goto(path);
    await settled(page);
    const links = label ? page.getByRole('link', { name: label }) : page.locator('a[href]');
    const hrefs = await links.evaluateAll((items) =>
      items.map((item) => item.getAttribute('href') ?? ''),
    );
    const href = hrefs.find((item) => pattern.test(item));
    if (!href) throw new Error(`Required accessibility fixture missing on ${path}: ${pattern}`);
    return href.split('?')[0]!;
  }
  const now = new Date();
  const currentMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  // The demo books the open month only up to `day - 3`, and the rent falls on day 3: the current
  // month has no spending before the 6th, so the previous month (always complete) stands in.
  const reference = now.getDate() < 6 ? new Date(now.getFullYear(), now.getMonth() - 1, 1) : now;
  const spendingMonth = `${reference.getFullYear()}-${String(reference.getMonth() + 1).padStart(2, '0')}`;
  return {
    account: await link('/accounts', /^\/accounts\/[0-9a-f-]{36}$/),
    plan: await link(
      '/budget/plans',
      /^\/budget\/plans\/[0-9a-f-]{36}$/,
      new RegExp(`^${currentMonth}$`),
    ),
    spendingPlan: await link(
      '/budget/plans',
      /^\/budget\/plans\/[0-9a-f-]{36}$/,
      new RegExp(`^${spendingMonth}$`),
    ),
    portfolio: await link('/accounts', /^\/portfolios\/[0-9a-f-]{36}$/, /Compte-titres/),
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
  '/transactions/categories',
  '/transactions/categories/rules',
  '/transactions/recurrences',
  '/budget',
  'month',
  '/budget/plans',
  'plan',
  'annual',
  'multi-year',
  '/goals',
  '/portfolios/instruments',
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
  if (route === 'annual') return `/budget/${now.getFullYear()}`;
  if (route === 'multi-year') return `/budget/${now.getFullYear()}?view=multi`;
  const [key, tab] = route.split(':');
  if (key && key in fixtures) {
    const path = fixtures[key as keyof AuditFixtures];
    return tab ? `${path}?tab=${tab}` : path;
  }
  return route;
}
