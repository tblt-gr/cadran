import type { Transaction } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { groupTransactionsByDay } from './groupTransactionsByDay';

function tx(id: string, bookedOn: string, value: string, extra: Partial<Transaction> = {}) {
  return {
    id,
    bookedOn,
    amount: { value, assetCode: 'EUR' },
    state: 'BOOKED',
    ...extra,
  } as Transaction;
}

describe('groupTransactionsByDay', () => {
  it('groups consecutive rows by booking date and keeps the incoming order', () => {
    const groups = groupTransactionsByDay(
      [
        tx('a', '2026-09-11', '-1.00'),
        tx('b', '2026-09-11', '-2.00'),
        tx('c', '2026-09-10', '5.00'),
      ],
      false,
    );

    expect(groups.map((group) => [group.day, group.transactions.map((row) => row.id)])).toEqual([
      ['2026-09-11', ['a', 'b']],
      ['2026-09-10', ['c']],
    ]);
  });

  it('sums the day exactly with decimal strings (0.1 + 0.2 = 0.3, not 0.30000000000000004)', () => {
    const [group] = groupTransactionsByDay(
      [tx('a', '2026-09-11', '0.1'), tx('b', '2026-09-11', '0.2')],
      false,
    );

    expect(group?.total).toEqual({ kind: 'amount', amount: { value: '0.3', assetCode: 'EUR' } });
  });

  it('keeps 24 decimals and very large magnitudes exact', () => {
    const [group] = groupTransactionsByDay(
      [
        tx('a', '2026-09-11', '99999999999999999999999999.000000000000000000000001'),
        tx('b', '2026-09-11', '-0.000000000000000000000002'),
      ],
      false,
    );

    expect(group?.total).toEqual({
      kind: 'amount',
      amount: { value: '99999999999999999999999998.999999999999999999999999', assetCode: 'EUR' },
    });
  });

  it('reports an exact zero day as a zero amount, not as missing', () => {
    const [group] = groupTransactionsByDay(
      [tx('a', '2026-09-11', '-10.00'), tx('b', '2026-09-11', '10.00')],
      false,
    );

    expect(group?.total).toEqual({ kind: 'amount', amount: { value: '0.00', assetCode: 'EUR' } });
  });

  it('leaves voided and rejected rows out of the day total but keeps them in the group', () => {
    const [group] = groupTransactionsByDay(
      [
        tx('a', '2026-09-11', '-10.00'),
        tx('b', '2026-09-11', '-99.00', { state: 'VOIDED' }),
        tx('c', '2026-09-11', '-98.00', { state: 'REJECTED' }),
      ],
      false,
    );

    expect(group?.transactions).toHaveLength(3);
    expect(group?.total).toEqual({ kind: 'amount', amount: { value: '-10.00', assetCode: 'EUR' } });
  });

  it('has no total when every row of the day is left out', () => {
    const [group] = groupTransactionsByDay(
      [tx('a', '2026-09-11', '-99.00', { state: 'VOIDED' })],
      false,
    );

    expect(group?.total).toEqual({ kind: 'none' });
  });

  it('is not computable when the day mixes asset codes', () => {
    const [group] = groupTransactionsByDay(
      [
        tx('a', '2026-09-11', '1.00'),
        tx('b', '2026-09-11', '1.00', { amount: { value: '1.00', assetCode: 'USD' } }),
      ],
      false,
    );

    expect(group?.total).toEqual({ kind: 'notComputable' });
  });

  it('is not computable when an amount is not a canonical decimal', () => {
    const [group] = groupTransactionsByDay([tx('a', '2026-09-11', 'abc')], false);

    expect(group?.total).toEqual({ kind: 'notComputable' });
  });

  it('hides the total of the last day while more pages remain, but not the earlier days', () => {
    const groups = groupTransactionsByDay(
      [tx('a', '2026-09-11', '-1.00'), tx('b', '2026-09-10', '-2.00')],
      true,
    );

    expect(groups[0]?.total).toEqual({
      kind: 'amount',
      amount: { value: '-1.00', assetCode: 'EUR' },
    });
    expect(groups[1]?.total).toEqual({ kind: 'partial' });
  });

  it('shows the last day total on the final page', () => {
    const groups = groupTransactionsByDay([tx('a', '2026-09-10', '-2.00')], false);

    expect(groups[0]?.total.kind).toBe('amount');
  });

  it('groups by the stored bookedOn string, not by a Date conversion of another field', () => {
    // createdAt falls on the previous UTC day and valueOn on the next one: only bookedOn counts.
    const near = { createdAt: '2026-09-10T23:30:00Z', valueOn: '2026-09-12' };
    const groups = groupTransactionsByDay(
      [tx('a', '2026-09-11', '-1.00', near), tx('b', '2026-09-11', '-1.00', near)],
      false,
    );

    expect(groups.map((group) => group.day)).toEqual(['2026-09-11']);
    expect(groups[0]?.transactions).toHaveLength(2);
  });

  it('returns no group for no rows', () => {
    expect(groupTransactionsByDay([], false)).toEqual([]);
  });

  it('hides the total of the first day when the page continues an earlier one', () => {
    const groups = groupTransactionsByDay(
      [tx('a', '2026-09-11', '-1.00'), tx('b', '2026-09-10', '-2.00')],
      false,
      true,
    );

    expect(groups[0]?.total).toEqual({ kind: 'partial' });
    expect(groups[1]?.total.kind).toBe('amount');
  });

  it('hides the only day of a middle page on both sides', () => {
    const [group] = groupTransactionsByDay([tx('a', '2026-09-11', '-1.00')], true, true);

    expect(group?.total).toEqual({ kind: 'partial' });
  });
});
