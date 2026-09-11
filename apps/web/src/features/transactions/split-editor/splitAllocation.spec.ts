import { describe, expect, it } from 'vitest';
import { compareDecimals, sumDecimals } from '@/lib/decimal';
import {
  assignRemainderToRow,
  canEvenSplit,
  evenSplitRows,
  remainingAmount,
} from './splitAllocation';

describe('remainingAmount', () => {
  it('is the total minus the sum of the rows', () => {
    expect(remainingAmount('-87.40', ['-62.10', '-18.30'])).toBe('-7.00');
  });

  it('is zero once the rows sum exactly to the total', () => {
    expect(remainingAmount('-87.40', ['-62.10', '-18.30', '-7.00'])).toBe('0.00');
  });
});

describe('assignRemainderToRow', () => {
  it('sets the targeted row so the full set sums exactly to the total', () => {
    const rows = assignRemainderToRow('-87.40', ['-62.10', '-10.00'], 1);

    expect(rows).toEqual(['-62.10', '-25.30']);
  });

  it('leaves the other rows untouched', () => {
    const rows = assignRemainderToRow('-100.00', ['-30.00', '-10.00', '-5.00'], 2);

    expect(rows[0]).toBe('-30.00');
    expect(rows[1]).toBe('-10.00');
    expect(rows[2]).toBe('-60.00');
  });
});

describe('evenSplitRows', () => {
  it('gives -100.00 over three EUR rows as -33.34, -33.33, -33.33', () => {
    expect(evenSplitRows('-100.00', 2, 3)).toEqual(['-33.34', '-33.33', '-33.33']);
  });

  it('sums exactly to the amount for a positive figure too', () => {
    const rows = evenSplitRows('87.40', 2, 3);

    expect(rows).toEqual(['29.14', '29.13', '29.13']);
  });

  it('splits a zero-decimal JPY amount at precision 0', () => {
    expect(evenSplitRows('-100', 0, 3)).toEqual(['-34', '-33', '-33']);
  });

  it('splits an eight-decimal BTC amount at precision 8', () => {
    expect(evenSplitRows('-0.00000010', 8, 3)).toEqual([
      '-0.00000004',
      '-0.00000003',
      '-0.00000003',
    ]);
  });

  it('gives every row the same base share when the amount divides exactly', () => {
    expect(evenSplitRows('-90.00', 2, 3)).toEqual(['-30.00', '-30.00', '-30.00']);
  });

  it('is unavailable when the source is more precise than the asset display scale', () => {
    expect(canEvenSplit('-100.001', 2, 3)).toBe(false);
    expect(() => evenSplitRows('-100.001', 2, 3)).toThrow();
  });

  it('is unavailable outside the 2..20 row bounds', () => {
    expect(canEvenSplit('-100.00', 2, 1)).toBe(false);
    expect(canEvenSplit('-100.00', 2, 21)).toBe(false);
  });

  it('always sums exactly to the amount and never varies between two runs, for many amounts and row counts', () => {
    let seed = 42;
    const nextInt = (max: number): number => {
      // A small deterministic LCG: reproducible across CI runs without a
      // dependency, and fast enough for the couple hundred cases this needs.
      seed = (seed * 1_103_515_245 + 12_345) & 0x7fffffff;

      return seed % max;
    };

    for (let iteration = 0; iteration < 200; iteration += 1) {
      const precision = nextInt(9);
      const rowCount = 2 + nextInt(19);
      const integerPart = nextInt(1_000_000);
      const fractionPart =
        precision === 0 ? '' : String(nextInt(10 ** precision)).padStart(precision, '0');
      const magnitude = precision === 0 ? String(integerPart) : `${integerPart}.${fractionPart}`;
      const total = nextInt(2) === 0 ? `-${magnitude}` : magnitude;
      if (total === '-0' || /^-0(\.0+)?$/.test(total)) {
        continue;
      }

      const rows = evenSplitRows(total, precision, rowCount);
      expect(rows).toHaveLength(rowCount);
      expect(compareDecimals(sumDecimals(rows), total)).toBe(0);
      expect(evenSplitRows(total, precision, rowCount)).toEqual(rows);
    }
  });
});
