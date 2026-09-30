import { describe, expect, it } from 'vitest';
import { initialSchedule, monthsBefore, scopeFor } from './goalFormFields';

describe('scopeFor', () => {
  it('maps each metric to its scope type', () => {
    expect(scopeFor('CATEGORY_SPENDING')).toBe('CATEGORY');
    expect(scopeFor('AXIS_SPENDING')).toBe('AXIS');
    expect(scopeFor('ACCOUNT_CONTRIBUTIONS')).toBe('ACCOUNT');
    expect(scopeFor('NET_SAVINGS_TRANSFERS')).toBe('WORKSPACE');
  });
});

describe('initialSchedule', () => {
  it('defaults to a blank linear schedule for a new goal', () => {
    expect(initialSchedule(undefined)).toEqual({ mode: 'LINEAR', parts: Array(12).fill('') });
  });

  it('reads the mode and twelve amounts off an existing annual goal version', () => {
    const goal = {
      currentVersion: {
        schedule: {
          mode: 'CUSTOM' as const,
          parts: Array.from({ length: 12 }, (_, i) => ({
            month: `2026-${String(i + 1).padStart(2, '0')}`,
            amount: '10.00',
            cumulative: '0',
          })),
        },
      },
    } as Parameters<typeof initialSchedule>[0];
    expect(initialSchedule(goal)).toEqual({ mode: 'CUSTOM', parts: Array(12).fill('10.00') });
  });
});

describe('monthsBefore', () => {
  it('walks back across year boundaries', () => {
    expect(monthsBefore('2026-09', 0)).toBe('2026-09');
    expect(monthsBefore('2026-09', 8)).toBe('2026-01');
    expect(monthsBefore('2026-09', 9)).toBe('2025-12');
    expect(monthsBefore('2026-09', 119)).toBe('2016-10');
  });
});
