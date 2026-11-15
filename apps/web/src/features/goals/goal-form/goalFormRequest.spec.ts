import { expect, it } from 'vitest';
import { goalRequest } from './goalFormRequest';
import { initialGoalFormState } from './goalFormState';

const base = () => ({
  ...initialGoalFormState(undefined, undefined, '2026-09', '2026-09'),
  label: '  Fonds  ',
  metric: 'NET_SAVINGS_TRANSFERS' as const,
  value: '400.50',
});
const context = {
  baseCurrency: 'EUR',
  goal: undefined,
  retroactive: false,
  scopeType: 'WORKSPACE' as const,
  yearlyAmount: false,
};

it('builds a creation with the amount kept as an exact string', () => {
  expect(goalRequest({ ...base(), tolerance: '0' }, context)).toMatchObject({
    endPeriod: null,
    label: 'Fonds',
    scope: { ref: null, type: 'WORKSPACE' },
    startPeriod: '2026-09',
    targetAmount: { assetCode: 'EUR', value: '400.50' },
  });
});

it('adds a custom schedule only for a yearly amount goal', () => {
  const state = { ...base(), schedule: { mode: 'CUSTOM' as const, parts: ['1', '2'] } };
  expect(goalRequest(state, { ...context, yearlyAmount: true })).toMatchObject({
    schedule: { mode: 'CUSTOM', parts: ['1', '2'] },
  });
  expect(goalRequest(state, context)).not.toHaveProperty('schedule');
});
