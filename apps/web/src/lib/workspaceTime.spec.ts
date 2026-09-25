import { describe, expect, it } from 'vitest';
import { workspaceToday } from './workspaceTime';

describe('workspaceTime', () => {
  it('resolves the workspace-local day across a UTC month boundary', () => {
    expect(workspaceToday(new Date('2026-03-31T22:30:00Z'), 'Pacific/Auckland')).toBe('2026-04-01');
  });

  it('resolves the day in the given workspace timezone', () => {
    const now = new Date('2026-03-31T20:00:00Z');
    expect(workspaceToday(now, 'Europe/Paris')).toBe('2026-03-31');
    expect(workspaceToday(now, 'Pacific/Auckland')).toBe('2026-04-01');
  });
});
