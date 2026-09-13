import { describe, expect, it } from 'vitest';
import { getRouteTitleKey, navigationItems } from './navigation';

describe('navigationItems', () => {
  it('keeps categorization rules nested under Categories', () => {
    const categories = navigationItems.find((item) => item.href === '/categories');

    expect(categories?.match('/categories')).toBe(true);
    expect(categories?.match('/categories/rules')).toBe(true);
    expect(navigationItems.some((item) => item.href === '/categorization-rules')).toBe(false);
    expect(getRouteTitleKey('/categories/rules')).toBe('routes.categorizationRules');
  });
});
