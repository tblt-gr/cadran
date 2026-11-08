import type { GoalEvaluation, GoalSummary } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { buildRows, goalFillRatio, isOnTarget, goalTone, indexSummaries } from './goalPanelModel';

function evaluation(
  goalId: string,
  label: string,
  status: GoalEvaluation['status'],
  extra: Partial<GoalEvaluation> = {},
): GoalEvaluation {
  return {
    goalId,
    label,
    periodicity: 'MONTHLY',
    metric: 'NET_SAVINGS_TRANSFERS',
    scopeType: 'WORKSPACE',
    scopeRef: null,
    direction: 'MINIMUM',
    valueType: 'AMOUNT',
    columnKey: null,
    target: null,
    achieved: null,
    gap: null,
    status,
    reason: null,
    formula: {
      key: 'goals.formula.amount',
      operands: { target: null, achieved: null, income: null, ratio: null },
    },
    versionNo: 1,
    overrides: [],
    pace: null,
    ...extra,
  };
}
const summary = (id: string, label: string) => ({ id, label }) as GoalSummary;

describe('goalPanelModel', () => {
  const summaries = indexSummaries([summary('g1', 'G1'), summary('g2', 'G2'), summary('g3', 'G3')]);

  it('orders ceilings first, then minimums by label, and folds hidden goals under the winner', () => {
    const rows = buildRows(
      {
        period: '2026-08',
        items: [
          evaluation('g2', 'G2', 'MET', { overrides: ['g1'] }),
          evaluation('g3', 'G3', 'MISSED', { direction: 'CEILING' }),
        ],
      },
      summaries,
    );
    expect(rows.map((row) => row.evaluation.label)).toEqual(['G3', 'G2']);
    expect(rows[1]!.replaced).toEqual([{ id: 'g1', label: 'G1' }]);
    expect(rows[0]!.replaced).toEqual([]);
  });

  describe('goalTone', () => {
    const tone = (
      status: GoalEvaluation['status'],
      direction: GoalEvaluation['direction'],
      periodicity: GoalEvaluation['periodicity'],
      gap: string | null,
      pace: GoalEvaluation['pace'] = null,
    ) => goalTone({ status, direction, periodicity, gap, pace });
    const pace = (status: 'AHEAD' | 'BEHIND' | 'ON_TRACK' | 'OVER_PACE') => ({
      expected: '1.00',
      status,
    });

    it('is neutral when the goal cannot be calculated', () => {
      expect(tone('NOT_CALCULABLE', 'MINIMUM', 'MONTHLY', null)).toBe('neutral');
      expect(tone('NOT_CALCULABLE', 'CEILING', 'YEARLY', '-5.00')).toBe('neutral');
    });

    it.each(['MINIMUM', 'CEILING'] as const)('is done for a met %s, equality included', (d) => {
      expect(tone('MET', d, 'MONTHLY', '0.00')).toBe('done');
      expect(tone('MET', d, 'YEARLY', '12.00', pace('BEHIND'))).toBe('done');
    });

    it.each(['MINIMUM', 'CEILING'] as const)('is negative for a missed %s', (d) => {
      expect(tone('MISSED', d, 'MONTHLY', '-1.00')).toBe('negative');
      expect(tone('MISSED', d, 'YEARLY', null, pace('AHEAD'))).toBe('negative');
    });

    it('reads a running monthly minimum from the sign of the gap: 740 of 800 is negative', () => {
      expect(tone('IN_PROGRESS', 'MINIMUM', 'MONTHLY', '-60.00')).toBe('negative');
      expect(tone('IN_PROGRESS', 'MINIMUM', 'MONTHLY', '-0.01')).toBe('negative');
      expect(tone('IN_PROGRESS', 'MINIMUM', 'MONTHLY', '0.00')).toBe('positive');
      expect(tone('IN_PROGRESS', 'MINIMUM', 'MONTHLY', '40.00')).toBe('positive');
    });

    it('keeps a running monthly ceiling green while the gap is not negative', () => {
      expect(tone('IN_PROGRESS', 'CEILING', 'MONTHLY', '120.00')).toBe('positive');
      expect(tone('IN_PROGRESS', 'CEILING', 'MONTHLY', '0.00')).toBe('positive');
    });

    it('is neutral when a running goal has no gap', () => {
      expect(tone('IN_PROGRESS', 'MINIMUM', 'MONTHLY', null)).toBe('neutral');
    });

    it.each([
      ['BEHIND', 'negative'],
      ['OVER_PACE', 'negative'],
      ['AHEAD', 'positive'],
      ['ON_TRACK', 'positive'],
    ] as const)('reads a running yearly goal from its pace %s: %s', (p, expected) => {
      for (const d of ['MINIMUM', 'CEILING'] as const) {
        // The full-year gap is misleading mid-year and must not decide.
        expect(tone('IN_PROGRESS', d, 'YEARLY', '-9999.00', pace(p))).toBe(expected);
        expect(tone('IN_PROGRESS', d, 'YEARLY', '9999.00', pace(p))).toBe(expected);
      }
    });

    it('falls back to the gap sign for a running yearly goal without pace', () => {
      expect(tone('IN_PROGRESS', 'MINIMUM', 'YEARLY', '-1.00')).toBe('negative');
      expect(tone('IN_PROGRESS', 'CEILING', 'YEARLY', '1.00')).toBe('positive');
    });

    it('reads the sign of a very large decimal exactly', () => {
      expect(tone('IN_PROGRESS', 'MINIMUM', 'MONTHLY', '-9007199254740993.01')).toBe('negative');
      expect(tone('IN_PROGRESS', 'MINIMUM', 'MONTHLY', '9007199254740993.01')).toBe('positive');
    });
  });

  it('fills the bar with the exact truncated share, clamped to the unit', () => {
    const fill = (achieved: string | null, target: string | null, status = 'IN_PROGRESS') =>
      goalFillRatio({
        status: status as GoalEvaluation['status'],
        achieved: achieved === null ? null : { value: achieved, assetCode: 'EUR' },
        target: target === null ? null : { value: target, assetCode: 'EUR' },
      });
    // 320.00 / 500.00 = 0.64
    expect(fill('320.00', '500.00')).toBe('0.6400');
    // 1 / 3 truncated, never rounded up
    expect(fill('1', '3')).toBe('0.3333');
    expect(fill('600.00', '500.00')).toBe('1');
    expect(fill('0', '500.00')).toBe('0');
    expect(fill('-20.00', '500.00')).toBe('0');
    expect(fill('9007199254740993.01', '18014398509481986.02')).toBe('0.5000');
    expect(fill(null, '500.00')).toBeNull();
    expect(fill('10', null)).toBeNull();
    expect(fill('10', '0.00')).toBeNull();
    expect(fill('10', '500', 'NOT_CALCULABLE')).toBeNull();
  });

  describe('isOnTarget', () => {
    const on = (status: GoalEvaluation['status'], gap: string | null | undefined) =>
      isOnTarget({ status, gap: gap as string | null });

    it.each([
      ['MET', '0.00', true],
      ['MET', null, true],
      ['MISSED', '5.00', false],
      ['MISSED', '-5.00', false],
      ['NOT_CALCULABLE', '5.00', false],
      ['NOT_CALCULABLE', null, false],
      ['IN_PROGRESS', '-60.00', false], // minimum 740 / 800
      ['IN_PROGRESS', '0.00', true], // minimum 800 / 800
      ['IN_PROGRESS', '40.00', true], // minimum 840 / 800
      ['IN_PROGRESS', '120.00', true], // ceiling still under
      ['IN_PROGRESS', '-0.01', false],
      ['IN_PROGRESS', null, false],
      ['IN_PROGRESS', undefined, false],
      ['IN_PROGRESS', '9007199254740993.01', true],
      ['IN_PROGRESS', '-9007199254740993.01', false],
    ] as const)('%s with gap %s is on target: %s', (status, gap, expected) => {
      expect(on(status, gap)).toBe(expected);
    });
  });
});
