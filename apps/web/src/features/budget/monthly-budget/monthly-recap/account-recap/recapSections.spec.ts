import type { MonthlyRecapAccount, MonthlyRecapGroup } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { recapSections } from './recapSections';

const share = { ratio: null, percent: null, percentDisplay: null, reason: null };
const amount = (value: string) => ({
  value,
  assetCode: 'EUR',
  display: null,
  belowDisplayStep: false,
});

function group(
  groupId: string,
  label: string,
  parentId: string | null,
  rollUp: string,
  direct: string,
): MonthlyRecapGroup {
  return {
    groupId,
    label,
    parentId,
    depth: parentId === null ? 1 : 2,
    color: '#FFFFFF',
    value: amount(rollUp),
    previousValue: amount(`${rollUp}0`),
    share,
    directValue: amount(direct),
    directPreviousValue: amount(`${direct}0`),
    directShare: share,
    hasChildren: parentId === null,
  } as MonthlyRecapGroup;
}

function account(id: string, primaryGroupId: string | null): MonthlyRecapAccount {
  return {
    accountId: id,
    label: id,
    institution: null,
    kind: 'CURRENT',
    netWorthSign: 1,
    primaryGroupId,
    primaryGroupLabel: null,
    primaryGroupColor: null,
    eligible: true,
    previousValue: null,
    currentValue: null,
    share,
  } as MonthlyRecapAccount;
}

const groups = [
  group('inv', 'Investissement', null, '300', '100'),
  group('bourse', 'Bourse', 'inv', '200', '200'),
  group('cash', 'Liquidités', null, '50', '50'),
  group('empty', 'Vide', null, '0', '0'),
];
const accounts = [
  account('a-cash', 'cash'),
  account('a-bourse', 'bourse'),
  account('a-inv', 'inv'),
  account('a-none', null),
];

describe('recapSections', () => {
  it('lists one section per group with direct accounts in sub-group mode', () => {
    const { sections, ungrouped } = recapSections(groups, accounts, 'PRIMARY');

    expect(sections.map((s) => s.groupId)).toEqual(['inv', 'bourse', 'cash']);
    expect(sections[1]).toMatchObject({
      label: 'Bourse',
      parentLabel: 'Investissement',
      current: amount('200'),
    });
    expect(sections[0]?.parentLabel).toBeNull();
    expect(sections[0]?.current).toEqual(amount('100'));
    expect(sections[0]?.members.map((m) => m.account.accountId)).toEqual(['a-inv']);
    expect(ungrouped.map((a) => a.accountId)).toEqual(['a-none']);
  });

  it('rolls sub-groups up under their root in parent mode', () => {
    const { sections } = recapSections(groups, accounts, 'PARENT');

    expect(sections.map((s) => s.groupId)).toEqual(['inv', 'cash']);
    expect(sections[0]?.current).toEqual(amount('300'));
    expect(sections[0]?.members.map((m) => m.account.accountId)).toEqual(['a-inv', 'a-bourse']);
    expect(sections[0]?.members.map((m) => m.subGroup)).toEqual([
      null,
      { label: 'Bourse', parentLabel: 'Investissement' },
    ]);
  });

  it('sends an account whose group is unknown to the ungrouped bucket', () => {
    const { sections, ungrouped } = recapSections(groups, [account('x', 'ghost')], 'PRIMARY');

    expect(sections).toEqual([]);
    expect(ungrouped.map((a) => a.accountId)).toEqual(['x']);
  });

  it('rolls a three-level tree up to its root and names the immediate parent', () => {
    const deep = [
      group('inv', 'Investissement', null, '300', '100'),
      group('bourse', 'Bourse', 'inv', '200', '50'),
      { ...group('pea', 'PEA', 'bourse', '150', '150'), depth: 3 },
    ];
    const rows = [account('a-pea', 'pea'), account('a-inv', 'inv')];

    const parent = recapSections(deep, rows, 'PARENT');
    expect(parent.sections.map((s) => s.groupId)).toEqual(['inv']);
    expect(parent.sections[0]?.members.map((m) => m.subGroup)).toEqual([
      null,
      { label: 'PEA', parentLabel: 'Bourse' },
    ]);

    const primary = recapSections(deep, rows, 'PRIMARY');
    expect(primary.sections.map((s) => [s.groupId, s.parentLabel])).toEqual([
      ['inv', null],
      ['pea', 'Bourse'],
    ]);
  });

  it('keeps a null direct total non calculable instead of zero', () => {
    const nullDirect = [
      { ...group('inv', 'Investissement', null, '300', '100'), directValue: null },
    ];

    const { sections } = recapSections(nullDirect, [account('a-inv', 'inv')], 'PRIMARY');

    expect(sections[0]?.current).toBeNull();
  });
});
