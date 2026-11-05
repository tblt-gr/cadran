import { describe, expect, it } from 'vitest';
import type { TransactionFacets } from '@cadran/api-client';
import { optionCount, sectionCounts } from './optionCount';

describe('optionCount', () => {
  it('asks for no count where the list was given none', () => {
    expect(optionCount(undefined, 'BOOKED')).toBeUndefined();
  });

  it('stays pending, not zero, until the first answer arrives', () => {
    expect(optionCount(null, 'BOOKED')).toBeNull();
  });

  it('reads the count of a value the answer holds', () => {
    expect(optionCount({ BOOKED: 184, PENDING: 3 }, 'BOOKED')).toBe(184);
    expect(optionCount({ BOOKED: 184 }, 'BOOKED')).toBe(184);
  });

  it('reads a value the answer omits as zero', () => {
    expect(optionCount({ BOOKED: 184 }, 'VOIDED')).toBe(0);
    expect(optionCount({}, 'BOOKED')).toBe(0);
  });

  it('never reads an inherited property of the answer as a count', () => {
    expect(optionCount({}, 'constructor')).toBe(0);
    expect(optionCount({}, 'toString')).toBe(0);
  });
});

describe('sectionCounts', () => {
  const counts: TransactionFacets = {
    accountId: { a1: 4 },
    categoryId: { c1: 3 },
    nature: { EXPENSE: 5 },
    state: { BOOKED: 6 },
    source: { MANUAL: 7 },
    axis: { ESSENTIAL: 8 },
  };

  it('hands a list nothing where the screen asks for no counts', () => {
    expect(sectionCounts(undefined, 'accountId')).toBeUndefined();
  });

  it('hands every list the counts of its own section', () => {
    const ready = { counts, pending: false };

    expect(sectionCounts(ready, 'accountId')).toEqual({ a1: 4 });
    expect(sectionCounts(ready, 'categoryId')).toEqual({ c1: 3 });
    expect(sectionCounts(ready, 'nature')).toEqual({ EXPENSE: 5 });
    expect(sectionCounts(ready, 'state')).toEqual({ BOOKED: 6 });
    expect(sectionCounts(ready, 'source')).toEqual({ MANUAL: 7 });
    expect(sectionCounts(ready, 'axis')).toEqual({ ESSENTIAL: 8 });
  });

  it('keeps every list pending until the first answer arrives', () => {
    expect(sectionCounts({ counts: undefined, pending: true }, 'nature')).toBeNull();
  });

  it('hands a list nothing after a failure or while disabled, so it shows no count at all', () => {
    expect(sectionCounts({ counts: undefined, pending: false }, 'nature')).toBeUndefined();
  });
});
