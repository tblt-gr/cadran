import { describe, expect, it } from 'vitest';
import { goalEvaluation, goalPeriod } from './goalEvaluationFixtures';
import { marksFor, marksTone } from './goalMarks';

describe('marksFor', () => {
  const missed = goalEvaluation({ goalId: 'a', label: 'Zeta', status: 'MISSED' });
  const met = goalEvaluation({ goalId: 'b', label: 'Alpha', status: 'MET', gap: '10.00' });
  const periods = [
    goalPeriod('2026-07', met, missed),
    goalPeriod('2026-08', goalEvaluation({ columnKey: 'other' })),
  ];

  it('matches on the period and the column key only', () => {
    expect(marksFor(periods, '2026-07', 'cashIncome')).toHaveLength(2);
    expect(marksFor(periods, '2026-08', 'cashIncome')).toEqual([]);
    expect(marksFor(periods, '2026-09', 'cashIncome')).toEqual([]);
    expect(marksFor(periods, '2026-07', 'other')).toEqual([]);
  });

  it('never matches an item without a column key', () => {
    const none = [goalPeriod('2026-07', goalEvaluation({ columnKey: null }))];
    expect(marksFor(none, '2026-07', 'cashIncome')).toEqual([]);
  });

  it('is empty before the evaluations arrive', () => {
    expect(marksFor(undefined, '2026-07', 'cashIncome')).toEqual([]);
  });

  it('lists the worst reading first and takes its tone', () => {
    const items = marksFor(periods, '2026-07', 'cashIncome');
    expect(items.map((item) => item.goalId)).toEqual(['a', 'b']);
    expect(marksTone(items)).toBe('negative');
    expect(marksTone([])).toBeNull();
  });
});
