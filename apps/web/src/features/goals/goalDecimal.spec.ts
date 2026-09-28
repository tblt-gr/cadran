import { describe, expect, it } from 'vitest';
import {
  isValidPositiveRatioPercentage,
  percentageToRatio,
  ratioToPercentage,
} from './goalDecimal';

describe('percentageToRatio / ratioToPercentage', () => {
  it('converts a typed percentage to the exact stored ratio', () => {
    expect(percentageToRatio('30')).toBe('0.30');
  });

  it('converts a stored ratio back to the percentage shown in the field', () => {
    expect(ratioToPercentage('0.30')).toBe('30');
  });

  it('round-trips the full-ratio boundary both ways', () => {
    expect(percentageToRatio('100')).toBe('1.00');
    expect(ratioToPercentage('1.00')).toBe('100');
    // The API returns the boundary unpadded (GoalControllerTest fixture: ratio "1" for a 100 % goal).
    expect(ratioToPercentage('1')).toBe('100');
  });

  it('round-trips the smallest positive ratio the API accepts (0.00000001)', () => {
    expect(percentageToRatio('0.000001')).toBe('0.00000001');
    expect(ratioToPercentage('0.00000001')).toBe('0.000001');
  });

  it('returns the original percentage after a full round trip through both helpers', () => {
    for (const percentage of ['30', '100', '0.000001', '1', '0.5', '99.99']) {
      const ratio = percentageToRatio(percentage);
      expect(ratio).not.toBeNull();
      expect(ratioToPercentage(ratio!)).toBe(percentage);
    }
  });

  it('rejects a non-canonical ratio', () => {
    expect(ratioToPercentage('-0.3')).toBeNull();
    expect(ratioToPercentage('01.5')).toBeNull();
    expect(ratioToPercentage('abc')).toBeNull();
  });
});

describe('isValidPositiveRatioPercentage', () => {
  it('accepts the full-ratio boundary (100 %)', () => {
    expect(isValidPositiveRatioPercentage('100')).toBe(true);
  });

  it('accepts the smallest positive percentage (0.000001 %, ratio 0.00000001)', () => {
    expect(isValidPositiveRatioPercentage('0.000001')).toBe(true);
  });

  it('rejects zero and values above 100 %', () => {
    expect(isValidPositiveRatioPercentage('0')).toBe(false);
    expect(isValidPositiveRatioPercentage('100.01')).toBe(false);
  });
});
