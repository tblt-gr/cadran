import { describe, expect, it } from 'vitest';
import { groupByDay } from './groupByDay';

const item = (id: string, day: string) => ({ id, day });

describe('groupByDay', () => {
  it('groups consecutive items by day and keeps the incoming order', () => {
    const groups = groupByDay(
      [item('a', '2026-09-11'), item('b', '2026-09-11'), item('c', '2026-09-10')],
      (entry) => entry.day,
    );

    expect(groups.map((group) => [group.day, group.items.map((row) => row.id)])).toEqual([
      ['2026-09-11', ['a', 'b']],
      ['2026-09-10', ['c']],
    ]);
  });

  it('does not merge a day that comes back later', () => {
    const groups = groupByDay(
      [item('a', '2026-09-11'), item('b', '2026-09-10'), item('c', '2026-09-11')],
      (entry) => entry.day,
    );

    expect(groups.map((group) => group.day)).toEqual(['2026-09-11', '2026-09-10', '2026-09-11']);
  });

  it('returns no group for no item', () => {
    expect(groupByDay([], (entry: { day: string }) => entry.day)).toEqual([]);
  });
});
