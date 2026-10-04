import { describe, expect, it } from 'vitest';
import { dueMonthLabel, lastDayOfMonth, monthEndLabel, parseMonthEnd } from './monthEnd';

describe('month end helpers', () => {
  it('finds the last calendar day, leap years included', () => {
    expect(lastDayOfMonth('2026-09')).toBe('2026-09-30');
    expect(lastDayOfMonth('2026-02')).toBe('2026-02-28');
    expect(lastDayOfMonth('2028-02')).toBe('2028-02-29');
    expect(lastDayOfMonth('2026-12')).toBe('2026-12-31');
  });

  it('names the month in lowercase French', () => {
    expect(monthEndLabel('2026-09', 'fr')).toBe('septembre');
    expect(monthEndLabel('2026-02', 'fr')).toBe('février');
  });

  it('accepts only a well formed month', () => {
    expect(parseMonthEnd('2026-09')).toBe('2026-09');
    expect(parseMonthEnd('2026-13')).toBeNull();
    expect(parseMonthEnd('2026-9')).toBeNull();
    expect(parseMonthEnd('nope')).toBeNull();
    expect(parseMonthEnd(null)).toBeNull();
  });
});

describe('dueMonthLabel', () => {
  it('adds the year only when due months span several years', () => {
    expect(dueMonthLabel('2026-09', ['2026-08', '2026-09'], 'fr')).toBe('septembre');
    expect(dueMonthLabel('2025-12', ['2025-12', '2026-01'], 'fr')).toBe('décembre 2025');
  });
});
