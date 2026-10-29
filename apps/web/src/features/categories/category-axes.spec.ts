import { describe, expect, it } from 'vitest';
import { sortAxes } from './category-axes';

describe('sortAxes', () => {
  it('orders need, then regularity, then usage', () => {
    expect(sortAxes(['PROFESSIONAL', 'VARIABLE', 'DISCRETIONARY', 'ESSENTIAL', 'FIXED'])).toEqual([
      'ESSENTIAL',
      'DISCRETIONARY',
      'FIXED',
      'VARIABLE',
      'PROFESSIONAL',
    ]);
  });

  it('keeps an empty selection empty', () => {
    expect(sortAxes([])).toEqual([]);
  });
});
