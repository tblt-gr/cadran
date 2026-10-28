import type { NetWorthAllocationEntry, NetWorthAmount } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { allocationEntries } from './allocationEntries';

function amount(value: string): NetWorthAmount {
  return { value, assetCode: 'EUR', display: null, belowDisplayStep: false };
}

function share(percent: string | null) {
  return { ratio: null, percent, percentDisplay: percent, reason: null };
}

function entry(
  groupId: string,
  parentId: string | null,
  value: string,
  direct: string | null,
  rollupShare: string,
  directShare: string,
): NetWorthAllocationEntry {
  return {
    groupId,
    parentId,
    color: `#${groupId}`,
    label: groupId,
    depth: parentId === null ? 1 : 2,
    value: amount(value),
    share: share(rollupShare),
    directValue: direct === null ? null : amount(direct),
    directShare: share(directShare),
    directPreviousValue: null,
    hasChildren: parentId === null,
  };
}

// Investissement(direct 100) > Immobilier 300, Bourse 200; eligible 650 with 50 ungrouped.
const allocation = [
  entry('Investissement', null, '600', '100', '92.31', '15.38'),
  entry('Immobilier', 'Investissement', '300', '300', '46.15', '46.15'),
  entry('Bourse', 'Investissement', '200', '200', '30.77', '30.77'),
  entry('Vide', null, '0', '0', '0', '0'),
  entry('Inconnu', null, '0', null, '0', '0'),
];

describe('allocationEntries', () => {
  it('keeps root groups at their roll-up in PARENT mode', () => {
    const items = allocationEntries(allocation, 'PARENT', (amount) => amount.value);
    expect(items.map((item) => item.groupId)).toEqual(['Investissement', 'Vide', 'Inconnu']);
    expect(items[0]?.value?.value).toBe('600');
    expect(items[0]?.share.percentDisplay).toBe('92.31');
  });

  it('lists every group with a direct total, at its direct value, in PRIMARY mode', () => {
    const items = allocationEntries(allocation, 'PRIMARY', (amount) => amount.value);
    expect(
      items.map((item) => [item.groupId, item.value?.value, item.share.percentDisplay]),
    ).toEqual([
      ['Investissement', '100', '15.38'],
      ['Immobilier', '300', '46.15'],
      ['Bourse', '200', '30.77'],
    ]);
  });

  it('labels a sub-group by name and immediate parent', () => {
    const bourse = allocationEntries(allocation, 'PRIMARY', (amount) => amount.value)[2];
    expect(bourse).toMatchObject({
      label: 'Bourse',
      parentLabel: 'Investissement',
      path: 'Bourse (Investissement)',
      color: '#Bourse',
    });
    expect(
      allocationEntries(allocation, 'PRIMARY', (amount) => amount.value)[0]?.parentLabel,
    ).toBeNull();
  });

  it('keeps a tiny signed liability total as non-zero', () => {
    const items = allocationEntries(
      [entry('Dette', null, '-5', '-0.000000000000000000000001', '0', '0')],
      'PRIMARY',
      (amount) => amount.value,
    );
    expect(items).toHaveLength(1);
  });

  describe('when the whole day is not calculable', () => {
    const missing = {
      ratio: null,
      percent: null,
      percentDisplay: null,
      reason: 'MISSING_VALUATION' as const,
    };
    const blank = [
      {
        ...entry('Investissement', null, '0', null, '0', '0'),
        value: null,
        share: missing,
        directShare: missing,
      },
      {
        ...entry('Bourse', 'Investissement', '0', null, '0', '0'),
        value: null,
        share: missing,
        directShare: missing,
      },
    ];

    it('keeps every group unvalued in PRIMARY mode', () => {
      const items = allocationEntries(blank, 'PRIMARY', (amount) => amount.value);
      expect(items.map((item) => item.groupId)).toEqual(['Investissement', 'Bourse']);
      expect(items.every((item) => item.value === null)).toBe(true);
      expect(items[1]?.share.reason).toBe('MISSING_VALUATION');
    });

    it('keeps the root groups unvalued in PARENT mode', () => {
      const items = allocationEntries(blank, 'PARENT', (amount) => amount.value);
      expect(items.map((item) => item.groupId)).toEqual(['Investissement']);
      expect(items[0]?.value).toBeNull();
    });
  });
});
