import { describe, expect, it } from 'vitest';
import { allocationSize } from './allocationSize';

describe('allocationSize', () => {
  it('preserves proportions when one positive share exceeds one hundred percent', () => {
    const leveragedShare = allocationSize('150.00');
    const otherShare = allocationSize('50.00');

    expect(leveragedShare).toBe(1.5);
    expect(otherShare).toBe(0.5);
    expect(
      leveragedShare === null || otherShare === null ? null : leveragedShare / otherShare,
    ).toBe(3);
  });

  it('omits zero and negative allocations', () => {
    expect(allocationSize('0.00')).toBeNull();
    expect(allocationSize('-25.00')).toBeNull();
  });
});
