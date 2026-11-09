import { describe, expect, it } from 'vitest';
import {
  firstGoalMonth,
  initialSchedule,
  isRateMetric,
  lastGoalMonth,
  monthsBefore,
  scopeFor,
  valueTypesFor,
} from './goalFormFields';

describe('scopeFor', () => {
  it.each(['TOTAL_INCOME', 'TOTAL_SPENDING'] as const)(
    'restricts %s to workspace amounts',
    (metric) => {
      expect(scopeFor(metric)).toBe('WORKSPACE');
      expect(valueTypesFor(metric, 'MONTHLY')).toEqual(['AMOUNT']);
      expect(valueTypesFor(metric, 'YEARLY')).toEqual(['AMOUNT']);
    },
  );
  it('restricts stock metrics to amounts and their authorized scope type', () => {
    expect(scopeFor('NET_WORTH')).toBe('WORKSPACE');
    expect(scopeFor('ACCOUNT_BALANCE')).toBe('ACCOUNT');
    expect(valueTypesFor('NET_WORTH', 'YEARLY')).toEqual(['AMOUNT']);
    expect(valueTypesFor('ACCOUNT_BALANCE', 'MONTHLY')).toEqual(['AMOUNT']);
  });
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

describe('valueTypesFor', () => {
  it('gives a rate metric only the rate, on both periodicities', () => {
    expect(valueTypesFor('SAVINGS_RATE', 'MONTHLY')).toEqual(['RATE']);
    expect(valueTypesFor('NET_SAVINGS_RATE', 'YEARLY')).toEqual(['RATE']);
    expect(isRateMetric('SAVINGS_RATE')).toBe(true);
    expect(isRateMetric('BUDGET_SURPLUS')).toBe(false);
  });

  it('offers the rolling share to a monthly goal only', () => {
    expect(valueTypesFor('CATEGORY_SPENDING', 'MONTHLY')).toEqual([
      'AMOUNT',
      'INCOME_SHARE',
      'ROLLING_INCOME_SHARE',
    ]);
    expect(valueTypesFor('CATEGORY_SPENDING', 'YEARLY')).toEqual(['AMOUNT', 'INCOME_SHARE']);
  });
});

describe('goal months', () => {
  it('maps a period to the first and last month it covers', () => {
    expect(firstGoalMonth('MONTHLY', '2026-11')).toBe('2026-11');
    expect(firstGoalMonth('YEARLY', '2026')).toBe('2026-01');
    expect(lastGoalMonth('MONTHLY', '2027-03')).toBe('2027-03');
    expect(lastGoalMonth('YEARLY', '2027')).toBe('2027-12');
    expect(lastGoalMonth('YEARLY', null)).toBeNull();
  });
});
