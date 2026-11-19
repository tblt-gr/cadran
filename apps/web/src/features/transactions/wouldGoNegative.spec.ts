import type { Account } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { isBalanceGuarded, wouldGoNegative } from './wouldGoNegative';

describe('wouldGoNegative', () => {
  it('flags an outflow larger than the balance, with exact decimals', () => {
    expect(wouldGoNegative('100.00', '100.01')).toBe(true);
    expect(wouldGoNegative('0.3', '0.30000000000000000000000001')).toBe(true);
  });

  it('accepts an outflow down to exactly zero', () => {
    expect(wouldGoNegative('100.00', '100')).toBe(false);
    expect(wouldGoNegative('0.1', '0.10')).toBe(false);
  });

  it('never flags a deposit, even on an account that is already negative', () => {
    expect(wouldGoNegative('-150.00', '0')).toBe(false);
    expect(wouldGoNegative('-150.00', '-100.00')).toBe(false);
  });
});

describe('isBalanceGuarded', () => {
  const base = { status: 'ACTIVE', valuationMode: 'TRANSACTIONS', kind: 'SAVINGS' } as Account;

  it('guards current, savings and cash accounts valued by transactions', () => {
    expect(isBalanceGuarded(base)).toBe(true);
    expect(isBalanceGuarded({ ...base, kind: 'CASH' })).toBe(true);
    expect(isBalanceGuarded({ ...base, kind: 'CURRENT' })).toBe(true);
  });

  it('leaves every other account alone', () => {
    expect(isBalanceGuarded({ ...base, kind: 'LIABILITY' })).toBe(false);
    expect(isBalanceGuarded({ ...base, valuationMode: 'PORTFOLIO' })).toBe(false);
    expect(isBalanceGuarded({ ...base, status: 'ARCHIVED' })).toBe(false);
    expect(isBalanceGuarded(undefined)).toBe(false);
  });
});
