import { describe, expect, it } from 'vitest';
import { getRouteTitleKey, navigationItems } from './navigation';

describe('navigationItems', () => {
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
    expect(getRouteTitleKey('/settings/metric-policy')).toBe('routes.metricPolicy');
    expect(
      navigationItems
        .find((item) => item.href === '/settings/profile')
        ?.match('/settings/metric-policy'),
    ).toBe(true);
  });
});
