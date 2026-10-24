import { describe, expect, it } from 'vitest';
import {
  directionForCategoryType,
  directionForNature,
  resolveDirection,
  toSignedAmount,
  toTypedAmount,
} from './signedAmount';

describe('toSignedAmount', () => {
  it('turns an unsigned expense into an outflow', () => {
    expect(toSignedAmount('42.10', 'OUTFLOW')).toBe('-42.10');
  });

  it('never double-negates a typed minus on an expense', () => {
    expect(toSignedAmount('-42.10', 'OUTFLOW')).toBe('-42.10');
    expect(toSignedAmount('-42.10', 'OUTFLOW')).toBe(toSignedAmount('42.10', 'OUTFLOW'));
  });

  it('keeps an income positive and leaves a contradicting minus visible', () => {
    expect(toSignedAmount('42.10', 'INFLOW')).toBe('42.10');
    expect(toSignedAmount('-42.10', 'INFLOW')).toBe('-42.10');
  });

  it('never guesses a sign when the direction is unknown', () => {
    expect(toSignedAmount('42.10', null)).toBe('42.10');
    expect(toSignedAmount('-42.10', null)).toBe('-42.10');
  });

  it('leaves zero, blanks and unparsable entries untouched', () => {
    expect(toSignedAmount('0', 'OUTFLOW')).toBe('0');
    expect(toSignedAmount('0.00', 'OUTFLOW')).toBe('0.00');
    expect(toSignedAmount('', 'OUTFLOW')).toBe('');
    expect(toSignedAmount('-', 'OUTFLOW')).toBe('-');
    expect(toSignedAmount('4,2', 'OUTFLOW')).toBe('4,2');
    expect(toSignedAmount('12.', 'OUTFLOW')).toBe('12.');
  });

  it('keeps every digit of a very long decimal', () => {
    const long = '123456789012345678901234567890.123456789012345678901234';
    expect(toSignedAmount(long, 'OUTFLOW')).toBe(`-${long}`);
    expect(toSignedAmount(`-${long}`, 'OUTFLOW')).toBe(`-${long}`);
  });
});

describe('toTypedAmount', () => {
  it('shows the magnitude of a signed amount that matches the direction', () => {
    expect(toTypedAmount('-42.10', 'OUTFLOW')).toBe('42.10');
    expect(toTypedAmount('42.10', 'INFLOW')).toBe('42.10');
  });

  it('keeps the sign that contradicts the direction or has no direction', () => {
    expect(toTypedAmount('42.10', 'OUTFLOW')).toBe('42.10');
    expect(toTypedAmount('-42.10', 'INFLOW')).toBe('-42.10');
    expect(toTypedAmount('-42.10', null)).toBe('-42.10');
  });

  it('round-trips through toSignedAmount', () => {
    expect(toSignedAmount(toTypedAmount('-0.000000000000000000000001', 'OUTFLOW'), 'OUTFLOW')).toBe(
      '-0.000000000000000000000001',
    );
  });
});

describe('directions', () => {
  it('derives the direction from a nature or a category type', () => {
    expect(directionForNature('EXPENSE')).toBe('OUTFLOW');
    expect(directionForNature('FEE')).toBe('OUTFLOW');
    expect(directionForNature('INCOME')).toBe('INFLOW');
    expect(directionForNature('ADJUSTMENT')).toBeNull();
    expect(directionForCategoryType('EXPENSE')).toBe('OUTFLOW');
    expect(directionForCategoryType('INCOME')).toBe('INFLOW');
    expect(directionForCategoryType(null)).toBeNull();
  });

  it('prefers the nature, then falls back on the category type', () => {
    expect(resolveDirection('EXPENSE', 'INCOME')).toBe('OUTFLOW');
    expect(resolveDirection('ADJUSTMENT', 'EXPENSE')).toBe('OUTFLOW');
    expect(resolveDirection('ADJUSTMENT', null)).toBeNull();
    expect(resolveDirection('INCOME', 'EXPENSE')).toBe('INFLOW');
    expect(resolveDirection(null, 'INCOME')).toBe('INFLOW');
  });
});

describe('budget ledger totals', () => {
  it('shows an expense total negative whichever sign the source carries, and other kinds as is', () => {
    expect(toSignedAmount('400.00', 'OUTFLOW')).toBe('-400.00');
    expect(toSignedAmount('-400.00', 'OUTFLOW')).toBe('-400.00');
    expect(toSignedAmount('0', 'OUTFLOW')).toBe('0');
    expect(toSignedAmount('1200.00', null)).toBe('1200.00');
    expect(toSignedAmount('-25.00', null)).toBe('-25.00');
  });
});
