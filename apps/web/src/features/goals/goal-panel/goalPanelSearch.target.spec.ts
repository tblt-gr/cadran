import { describe, expect, it } from 'vitest';
import { readGoalPanelTarget } from './goalPanelSearch';

describe('readGoalPanelTarget', () => {
  it('reads the goal and the period of a report cell', () => {
    expect(readGoalPanelTarget('?panel=goals&goal=abc&period=2026-07')).toEqual({
      goalId: 'abc',
      period: '2026-07',
    });
    expect(readGoalPanelTarget('?panel=goals&goal=abc&period=2026')?.period).toBe('2026');
  });

  it('ignores a missing goal or a malformed period', () => {
    expect(readGoalPanelTarget('?panel=goals')).toBeNull();
    expect(readGoalPanelTarget('?panel=goals&goal=abc')).toBeNull();
    expect(readGoalPanelTarget('?panel=goals&goal=abc&period=soon')).toBeNull();
  });
});
