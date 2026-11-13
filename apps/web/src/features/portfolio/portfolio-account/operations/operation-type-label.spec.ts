import { expect, it } from 'vitest';
import { allowedOperationTypes } from './operation-type-label';

it('lists the frequent operations first for each account kind', () => {
  expect(allowedOperationTypes('INSURANCE_CONTRACT').slice(0, 4)).toEqual([
    'PREMIUM',
    'PARTIAL_SURRENDER',
    'FULL_SURRENDER',
    'SWITCH',
  ]);
  expect(allowedOperationTypes('SECURITIES').slice(0, 4)).toEqual([
    'BUY',
    'SELL',
    'DEPOSIT',
    'WITHDRAWAL',
  ]);
});
