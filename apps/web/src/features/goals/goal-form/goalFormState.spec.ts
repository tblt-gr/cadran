import type { GoalSummary } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import {
  defaultStartFor,
  goalFormReducer,
  initialGoalFormState,
  type GoalFormState,
} from './goalFormState';

const goal: GoalSummary = {
  id: '00000000-0000-7000-8000-000000000001',
  label: 'Courses',
  periodicity: 'MONTHLY',
  startPeriod: '2026-01',
  endPeriod: null,
  metric: 'CATEGORY_SPENDING',
  direction: 'CEILING',
  scope: {
    type: 'CATEGORY',
    id: '00000000-0000-7000-8000-0000000000c1',
    label: 'Courses',
    archived: false,
  },
  assetCode: 'EUR',
  currentVersion: {
    versionNo: 1,
    effectiveFrom: '2026-01',
    valueType: 'AMOUNT',
    targetAmount: '400.00',
    ratio: null,
    tolerance: '0',
    schedule: null,
  },
  version: 1,
  warnings: [],
  status: null,
};

const blank = () => initialGoalFormState(undefined, undefined, '2026-09', '2026-09');

function reduce(state: GoalFormState, ...actions: Parameters<typeof goalFormReducer>[1][]) {
  return actions.reduce(goalFormReducer, state);
}

describe('initialGoalFormState', () => {
  it('starts a new goal on the current month with no end', () => {
    expect(blank()).toMatchObject({
      label: '',
      metric: 'NET_SAVINGS_TRANSFERS',
      noEnd: true,
      periodicity: 'MONTHLY',
      start: '2026-09',
      value: '',
      valueType: 'AMOUNT',
    });
  });

  it('seeds an edited goal from its current version, amounts staying strings', () => {
    expect(initialGoalFormState(goal, undefined, '2026-09', '2026-09')).toMatchObject({
      label: 'Courses',
      scope: '00000000-0000-7000-8000-0000000000c1',
      start: '2026-01',
      value: '400.00',
    });
  });

  it('starts a yearly goal on the current year', () => {
    expect(defaultStartFor('YEARLY', '2026-09')).toBe('2026');
  });
});

describe('goalFormReducer', () => {
  it('sets one field per action', () => {
    expect(reduce(blank(), { type: 'label', value: 'Fonds' }).label).toBe('Fonds');
  });

  it('clears the scope when the metric changes', () => {
    const state = reduce(
      blank(),
      { type: 'scope', value: 'abc' },
      { type: 'metric', value: 'CATEGORY_SPENDING' },
    );
    expect(state.scope).toBe('');
    expect(state.metric).toBe('CATEGORY_SPENDING');
  });

  it('never carries an amount into a percentage', () => {
    const state = reduce(
      blank(),
      { type: 'value', value: '400' },
      { type: 'tolerance', value: '5' },
      { type: 'valueType', value: 'RATE' },
    );
    expect(state).toMatchObject({ tolerance: '', value: '', valueType: 'RATE' });
  });

  it('keeps the value when the type stays an amount', () => {
    const state = reduce(
      blank(),
      { type: 'value', value: '400' },
      { type: 'valueType', value: 'AMOUNT' },
    );
    expect(state.value).toBe('400');
  });

  it('moves the start and the end together while the goal is a single period', () => {
    const single = reduce(blank(), { type: 'single', value: true });
    expect(single).toMatchObject({ end: '2026-09', noEnd: false });
    expect(reduce(single, { type: 'start', value: '2026-10' })).toMatchObject({
      end: '2026-10',
      start: '2026-10',
    });
  });

  it('leaves the end alone when the goal is not a single period', () => {
    const state = reduce(
      blank(),
      { type: 'end', value: '2027-01' },
      { type: 'start', value: '2026-10' },
    );
    expect(state).toMatchObject({ end: '2027-01', start: '2026-10' });
  });

  it('empties and restores the end with the open-ended flag', () => {
    const open = reduce(blank(), { type: 'noEnd', value: false });
    expect(open).toMatchObject({ end: '2026-09', noEnd: false });
    expect(reduce(open, { type: 'noEnd', value: true })).toMatchObject({ end: '', noEnd: true });
  });

  it('restarts the period on the periodicity change', () => {
    const state = reduce(blank(), { type: 'periodicity', value: 'YEARLY', defaultStart: '2026' });
    expect(state).toMatchObject({ end: '', periodicity: 'YEARLY', start: '2026' });
  });

  it('resets to a given state', () => {
    const next = initialGoalFormState(goal, undefined, '2026-09', '2026-09');
    expect(reduce(blank(), { type: 'reset', state: next })).toBe(next);
  });
});
