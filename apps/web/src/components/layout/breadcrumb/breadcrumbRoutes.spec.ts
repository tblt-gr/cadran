import { describe, expect, it } from 'vitest';
import { backTarget, breadcrumbItems, detailBackTarget } from './breadcrumbRoutes';

const t = ((key: string) =>
  ({
    'breadcrumb.home': 'Accueil',
    'navigation.accounts': 'Comptes',
    'navigation.investments': 'Investissements',
    'navigation.transactions': 'Transactions',
    'navigation.budget': 'Budget',
    'navigation.settings': 'Paramètres',
  })[key] ?? key) as Parameters<typeof breadcrumbItems>[1];

const parentHref = (path: string, account: Parameters<typeof breadcrumbItems>[2] = null) =>
  backTarget(breadcrumbItems(path, t, account), path)?.href ?? null;

describe('backTarget', () => {
  it.each([
    ['/', null],
    ['/accounts', '/'],
    ['/accounts/groups', '/accounts'],
    ['/investments', '/'],
    ['/investments/instruments', '/investments'],
    ['/budget', '/'],
    ['/transactions', '/budget'],
    ['/transactions/recurrences', '/transactions'],
    ['/transactions/categories', '/transactions'],
    ['/transactions/categories/rules', '/transactions/categories'],
    ['/categories', '/transactions'],
    ['/categories/rules', '/transactions/categories'],
    ['/budget/2026', '/budget'],
    ['/budget/2026/03', '/budget/2026'],
    ['/parameters/interface', '/'],
    ['/parameters/profile', '/'],
    ['/parameters/metric-policy', '/'],
    ['/parameters/catalog', '/'],
    ['/parameters/product-models', '/'],
    ['/unknown', '/'],
  ])('goes from %s to %s', (path, expected) => {
    expect(parentHref(path)).toBe(expected);
  });

  it('goes from an account or an instrument to its list', () => {
    expect(parentHref('/accounts/abc', { label: 'Livret', pending: false })).toBe('/accounts');
    expect(parentHref('/portfolios/abc', { label: 'PEA', pending: false })).toBe('/accounts');
    expect(parentHref('/investments/instruments/abc', { label: 'ETF', pending: true })).toBe(
      '/investments/instruments',
    );
  });
});

describe('detailBackTarget', () => {
  const detail = (path: string) =>
    detailBackTarget(breadcrumbItems(path, t, { label: 'X', pending: false }), path)?.href ?? null;

  it.each([
    ['/accounts/abc', '/accounts'],
    ['/portfolios/abc', '/accounts'],
    ['/investments/instruments/abc', '/investments/instruments'],
  ])('goes from the detail page %s to %s', (path, expected) => {
    expect(detail(path)).toBe(expected);
  });

  it.each([
    '/',
    '/accounts',
    '/accounts/groups',
    '/investments',
    '/investments/instruments',
    '/budget',
    '/budget/2026',
    '/budget/2026/03',
    '/transactions',
    '/transactions/categories/rules',
    '/parameters/profile',
    '/unknown',
  ])('has no arrow on %s', (path) => {
    expect(detail(path)).toBeNull();
  });
});
