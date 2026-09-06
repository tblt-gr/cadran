import { describe, expect, it } from 'vitest';
import { compareDecimalString, compareText, toggleSort } from './tableSort';

describe('toggleSort', () => {
  it('starts a new column in ascending order', () => {
    expect(toggleSort('label', 'desc', 'kind')).toEqual({ column: 'kind', direction: 'asc' });
  });

  it('flips the active column', () => {
    expect(toggleSort('label', 'asc', 'label')).toEqual({ column: 'label', direction: 'desc' });
  });
});

describe('compareText', () => {
  it('orders by locale and direction', () => {
    expect(compareText('Livret', 'PEA', 'fr', 'asc')).toBeLessThan(0);
    expect(compareText('Livret', 'PEA', 'fr', 'desc')).toBeGreaterThan(0);
  });
});

describe('compareDecimalString', () => {
  it('orders canonical decimals without inventing a displayed figure', () => {
    expect(compareDecimalString('10.00', '2.00', 'asc')).toBeGreaterThan(0);
    expect(compareDecimalString(null, '2.00', 'asc')).toBeGreaterThan(0);
  });
});
