import { describe, expect, it } from 'vitest';
import { isUpcomingDay, lastDayOfMonth, workspaceToday } from './workspaceTime';

describe('workspaceTime', () => {
  it('resolves the workspace-local day across a UTC month boundary', () => {
    expect(workspaceToday(new Date('2026-03-31T22:30:00Z'), 'Pacific/Auckland')).toBe('2026-04-01');
  });

  it('resolves the day in the given workspace timezone', () => {
    const now = new Date('2026-03-31T20:00:00Z');
    expect(workspaceToday(now, 'Europe/Paris')).toBe('2026-03-31');
    expect(workspaceToday(now, 'Pacific/Auckland')).toBe('2026-04-01');
  });

  it('finds the last day of a month, leap years and December included', () => {
    expect(lastDayOfMonth('2026-11-10')).toBe('2026-11-30');
    expect(lastDayOfMonth('2028-02-03')).toBe('2028-02-29');
    expect(lastDayOfMonth('2027-02-03')).toBe('2027-02-28');
    expect(lastDayOfMonth('2026-12-31')).toBe('2026-12-31');
  });

  it('plans only days after today and up to the end of the current month', () => {
    expect(isUpcomingDay('2026-11-10', '2026-11-10')).toBe(false);
    expect(isUpcomingDay('2026-11-11', '2026-11-10')).toBe(true);
    expect(isUpcomingDay('2026-11-30', '2026-11-10')).toBe(true);
    expect(isUpcomingDay('2026-12-01', '2026-11-10')).toBe(false);
  });
});
