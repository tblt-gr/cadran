import { describe, expect, it } from 'vitest';
import { defaultDayForMonth, parseBudgetMonth, readAxis, workspaceToday } from './budgetPeriod';

describe('budgetPeriod', () => {
  it('resolves the workspace-local day across a UTC month boundary', () => {
    expect(workspaceToday(new Date('2026-03-31T22:30:00Z'))).toBe('2026-04-01');
  });

  it('resolves the day in the given workspace timezone', () => {
    const now = new Date('2026-03-31T20:00:00Z');
    expect(workspaceToday(now, 'Europe/Paris')).toBe('2026-03-31');
    expect(workspaceToday(now, 'Pacific/Auckland')).toBe('2026-04-01');
  });

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
});
