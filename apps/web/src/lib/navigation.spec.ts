import { describe, expect, it } from 'vitest';
import { getRouteTitleKey, navigationItems } from './navigation';

describe('navigationItems', () => {
  it('orders the primary, direct mobile and overflow destinations consistently', () => {
    expect(navigationItems.map((item) => item.href)).toEqual([
      '/',
      '/accounts',
      '/portfolios',
      '/budget',
      '/reports',
      '/goals',
      '/transactions',
      '/parameters/profile',
    ]);
    expect(navigationItems.filter((item) => item.mobile).map((item) => item.href)).toEqual([
      '/',
      '/accounts',
      '/budget',
      '/transactions',
    ]);
    expect(navigationItems.filter((item) => !item.mobile).map((item) => item.href)).toEqual([
      '/portfolios',
      '/reports',
      '/goals',
      '/parameters/profile',
    ]);
  });

  it.each([
    ['/accounts/account-id', '/accounts'],
    ['/accounts/groups', '/accounts'],
    ['/portfolios/portfolio-id', '/portfolios'],
    ['/portfolios/instruments', '/portfolios'],
    ['/life-insurance/contract-id', '/portfolios'],
    ['/budget/plans/plan-id', '/budget'],
    ['/reports/annual/2026', '/reports'],
    ['/reports/all-years', '/reports'],
    ['/transactions/categories', '/transactions'],
    ['/transactions/categories/rules', '/transactions'],
    ['/transactions/recurrences', '/transactions'],
    ['/parameters/metric-policy', '/parameters/profile'],
  ])('selects only %s parent %s', (path, parent) => {
    expect(navigationItems.filter((item) => item.match(path)).map((item) => item.href)).toEqual([
      parent,
    ]);
  });

  it.each(['/unknown', '/transactions/unknown', '/accounts/id/unknown', '/reports/unknown'])(
    'does not select a destination for unrelated route %s',
    (path) => {
      expect(navigationItems.some((item) => item.match(path))).toBe(false);
    },
  );

  it('nests category management under Transactions', () => {
    const transactions = navigationItems.find((item) => item.href === '/transactions');

    expect(transactions?.match('/transactions/categories')).toBe(true);
    expect(transactions?.match('/transactions/categories/rules')).toBe(true);
    expect(navigationItems.some((item) => item.href === '/categories')).toBe(false);
    expect(transactions?.match('/transactions/unknown')).toBe(false);
    expect(getRouteTitleKey('/transactions/categories')).toBe('routes.categories');
    expect(getRouteTitleKey('/transactions/categories/rules')).toBe('routes.categorizationRules');
  });

  it('titles the metric policy page while keeping it under Settings', () => {
    expect(getRouteTitleKey('/parameters/metric-policy')).toBe('routes.metricPolicy');
    expect(
      navigationItems
        .find((item) => item.href === '/parameters/profile')
        ?.match('/parameters/metric-policy'),
    ).toBe(true);
  });

  it('titles a portfolio account page apart from the instruments page', () => {
    expect(getRouteTitleKey('/portfolios/00000000-0000-7000-8000-000000000001')).toBe(
      'routes.portfolioAccount',
    );
    expect(getRouteTitleKey('/portfolios/instruments')).toBe('routes.instruments');
  });
});
