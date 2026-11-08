import { describe, expect, it } from 'vitest';
import { getRouteTitleKey, navigationItems } from './navigation';

describe('navigationItems', () => {
  it('orders the primary, direct mobile and overflow destinations consistently', () => {
    expect(navigationItems.map((item) => item.href)).toEqual([
      '/',
      '/accounts',
      '/investments',
      '/budget',
      '/parameters/interface',
    ]);
    expect(navigationItems.filter((item) => item.mobile).map((item) => item.href)).toEqual([
      '/',
      '/accounts',
      '/budget',
    ]);
    expect(navigationItems.filter((item) => !item.mobile).map((item) => item.href)).toEqual([
      '/investments',
      '/parameters/interface',
    ]);
  });

  it.each([
    ['/accounts/account-id', '/accounts'],
    ['/accounts/groups', '/accounts'],
    ['/portfolios/portfolio-id', '/accounts'],
    ['/investments', '/investments'],
    ['/investments/instruments', '/investments'],
    ['/life-insurance/contract-id', '/accounts'],
    ['/budget/2026', '/budget'],
    ['/budget/2026/10', '/budget'],
    ['/transactions', '/budget'],
    ['/transactions/categories', '/budget'],
    ['/transactions/categories/rules', '/budget'],
    ['/transactions/recurrences', '/budget'],
    ['/parameters/metric-policy', '/parameters/interface'],
  ])('selects only %s parent %s', (path, parent) => {
    expect(navigationItems.filter((item) => item.match(path)).map((item) => item.href)).toEqual([
      parent,
    ]);
  });

  it.each([
    '/unknown',
    '/transactions/unknown',
    '/accounts/id/unknown',
    '/reports',
    '/reports/annual/2026',
  ])('does not select a destination for unrelated route %s', (path) => {
    expect(navigationItems.some((item) => item.match(path))).toBe(false);
  });

  it('keeps Transactions out of the primary navigation and under Budget', () => {
    const budget = navigationItems.find((item) => item.href === '/budget');

    expect(navigationItems.some((item) => item.href === '/transactions')).toBe(false);
    expect(navigationItems.some((item) => item.href === '/categories')).toBe(false);
    expect(budget?.match('/transactions')).toBe(true);
    expect(budget?.match('/transactions/unknown')).toBe(false);
    expect(getRouteTitleKey('/transactions')).toBe('routes.transactions');
    expect(getRouteTitleKey('/transactions/recurrences')).toBe('routes.recurrences');
    expect(getRouteTitleKey('/transactions/categories')).toBe('routes.categories');
    expect(getRouteTitleKey('/transactions/categories/rules')).toBe('routes.categorizationRules');
  });

  it('titles the metric policy page while keeping it under Settings', () => {
    expect(getRouteTitleKey('/parameters/metric-policy')).toBe('routes.metricPolicy');
    expect(
      navigationItems
        .find((item) => item.href === '/parameters/interface')
        ?.match('/parameters/metric-policy'),
    ).toBe(true);
  });

  it('titles a portfolio account page apart from the investments pages', () => {
    expect(getRouteTitleKey('/portfolios/00000000-0000-7000-8000-000000000001')).toBe(
      'routes.portfolioAccount',
    );
    expect(getRouteTitleKey('/investments/instruments')).toBe('routes.instruments');
    expect(getRouteTitleKey('/investments')).toBe('navigation.investments');
  });
});
