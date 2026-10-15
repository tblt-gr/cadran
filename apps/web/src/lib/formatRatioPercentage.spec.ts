import { describe, expect, it } from 'vitest';
import { formatRatioPercentage, ratioToPercent } from './formatRatioPercentage';

describe('ratioToPercent', () => {
  it('moves the decimal point two places', () => {
    expect(ratioToPercent('0.30')).toBe('30');
    expect(ratioToPercent('0.027558379272579321809007')).toBe('2.7558379272579321809007');
    expect(ratioToPercent('1')).toBe('100');
  });

  it('keeps a negative ratio canonical instead of leaving leading zeros behind the sign', () => {
    expect(ratioToPercent('-0.027558379272579321809007')).toBe('-2.7558379272579321809007');
    expect(ratioToPercent('-0.30')).toBe('-30');
    expect(ratioToPercent('-0.00')).toBe('0');
  });
});

describe('formatRatioPercentage', () => {
  it('formats a negative share', () => {
    expect(formatRatioPercentage('-0.027558379272579321809007', 'fr')).toMatch(/^[-−]2,76\s%$/u);
  });
});
