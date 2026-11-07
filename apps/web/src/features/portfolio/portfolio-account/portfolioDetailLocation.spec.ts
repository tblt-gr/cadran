import { describe, expect, it } from 'vitest';
import { instrumentFromLocation } from './portfolioDetailLocation';
describe('instrumentFromLocation', () => {
  it('reads only UUID instrument filters', () => {
    window.history.replaceState(
      {},
      '',
      '/portfolios/a?tab=operations&instrument=00000000-0000-7000-8000-000000000001',
    );
    expect(instrumentFromLocation()).toBe('00000000-0000-7000-8000-000000000001');
    window.history.replaceState({}, '', '/portfolios/a?instrument=invalid');
    expect(instrumentFromLocation()).toBeNull();
  });
});
