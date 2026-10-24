import type { Transaction } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { groupTransactionsByDay } from './groupTransactionsByDay';

function tx(id: string, bookedOn: string, extra: Partial<Transaction> = {}) {
  return { id, bookedOn, ...extra } as Transaction;
}

describe('groupTransactionsByDay', () => {
  it('groups consecutive rows by booking date and keeps the incoming order', () => {
    const groups = groupTransactionsByDay([
      tx('a', '2026-09-11'),
      tx('b', '2026-09-11'),
      tx('c', '2026-09-10'),
    ]);

    expect(groups.map((group) => [group.day, group.transactions.map((row) => row.id)])).toEqual([
      ['2026-09-11', ['a', 'b']],
      ['2026-09-10', ['c']],
    ]);
  });

  it('groups by the stored bookedOn string, not by a Date conversion of another field', () => {
    // createdAt falls on the previous UTC day and valueOn on the next one: only bookedOn counts.
    const near = { createdAt: '2026-09-10T23:30:00Z', valueOn: '2026-09-12' };
    const groups = groupTransactionsByDay([
      tx('a', '2026-09-11', near),
      tx('b', '2026-09-11', near),
    ]);

    expect(groups.map((group) => group.day)).toEqual(['2026-09-11']);
    expect(groups[0]?.transactions).toHaveLength(2);
  });

  it('opens a new group when a day comes back after another one', () => {
    const groups = groupTransactionsByDay([
      tx('a', '2026-09-11'),
      tx('b', '2026-09-10'),
      tx('c', '2026-09-11'),
    ]);

    expect(groups.map((group) => group.day)).toEqual(['2026-09-11', '2026-09-10', '2026-09-11']);
  });

  it('returns no group for no rows', () => {
    expect(groupTransactionsByDay([])).toEqual([]);
  });
});
