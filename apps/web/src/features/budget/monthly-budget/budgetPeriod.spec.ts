import { describe, expect, it } from 'vitest';
import {
  annualHref,
  defaultDayForMonth,
  monthHref,
  parseBudgetMonth,
  readAxis,
  resolveBudgetMonthRoute,
} from './budgetPeriod';

describe('budgetPeriod', () => {
  it('accepts past and current months and rejects malformed or future routes', () => {
    expect(parseBudgetMonth('2026-03', '2026-03-15')).toEqual({
      kind: 'valid',
      month: '2026-03',
    });
    expect(parseBudgetMonth('2025-12', '2026-03-15')).toEqual({
      kind: 'valid',
      month: '2025-12',
    });
    expect(parseBudgetMonth('2026-13', '2026-03-15')).toEqual({ kind: 'invalid' });
    expect(parseBudgetMonth('2026-04', '2026-03-15')).toEqual({ kind: 'future' });
  });

  it('uses workspace today for the current month and the last day for a past month', () => {
    expect(defaultDayForMonth('2026-03', '2026-03-15')).toBe('2026-03-15');
    expect(defaultDayForMonth('2024-02', '2026-03-15')).toBe('2024-02-29');
  });

  it('keeps only supported analytic axes from the query string', () => {
    expect(readAxis('?axis=ESSENTIAL')).toBe('ESSENTIAL');
    expect(readAxis('?axis=unknown')).toBeNull();
    expect(readAxis('')).toBeNull();
  });

  it('builds the year/month URL and keeps the axis in the query string', () => {
    expect(monthHref('2026-03', null)).toBe('/budget/2026/03');
    expect(monthHref('2026-03', 'FIXED')).toBe('/budget/2026/03?axis=FIXED');
    expect(annualHref(2026)).toBe('/budget/2026');
  });

  describe('resolveBudgetMonthRoute', () => {
    const today = '2026-10-25';

    it('keeps a canonical month of the current or a past year', () => {
      expect(resolveBudgetMonthRoute('2026', '03', today)).toEqual({
        kind: 'month',
        periodKey: '2026-03',
      });
      expect(resolveBudgetMonthRoute('2024', '12', today)).toEqual({
        kind: 'month',
        periodKey: '2024-12',
      });
    });

    it('clamps a complete year to January or December', () => {
      expect(resolveBudgetMonthRoute('2025', '00', today)).toEqual({
        kind: 'redirect',
        href: '/budget/2025/01',
      });
      expect(resolveBudgetMonthRoute('2025', '-4', today)).toEqual({
        kind: 'redirect',
        href: '/budget/2025/01',
      });
      expect(resolveBudgetMonthRoute('2025', '13', today)).toEqual({
        kind: 'redirect',
        href: '/budget/2025/12',
      });
      expect(resolveBudgetMonthRoute('2025', '99999999999999999999', today)).toEqual({
        kind: 'redirect',
        href: '/budget/2025/12',
      });
    });

    it('sends the year in progress to January or to the current month', () => {
      expect(resolveBudgetMonthRoute('2026', '0', today)).toEqual({
        kind: 'redirect',
        href: '/budget/2026/01',
      });
      expect(resolveBudgetMonthRoute('2026', '13', today)).toEqual({
        kind: 'redirect',
        href: '/budget/2026/10',
      });
    });

    it('normalises an unpadded month and leaves a future month to the page error', () => {
      expect(resolveBudgetMonthRoute('2026', '3', today)).toEqual({
        kind: 'redirect',
        href: '/budget/2026/03',
      });
      expect(resolveBudgetMonthRoute('2026', '11', today)).toEqual({
        kind: 'month',
        periodKey: '2026-11',
      });
      expect(resolveBudgetMonthRoute('2027', '13', today)).toEqual({
        kind: 'month',
        periodKey: '2027-13',
      });
    });
  });
});
