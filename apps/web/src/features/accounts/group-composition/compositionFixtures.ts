import type {
  AccountGroupComposition,
  GroupCompositionAccount,
  GroupCompositionSubgroup,
  NetWorthAmount,
  NetWorthShare,
} from '@cadran/api-client';

export const PARENT_ID = '00000000-0000-7000-8000-0000000000c1';
const BOURSE_ID = '00000000-0000-7000-8000-0000000000c2';
const IMMO_ID = '00000000-0000-7000-8000-0000000000c3';

function amount(display: string): NetWorthAmount {
  return {
    value: display,
    assetCode: 'EUR',
    display: { value: display, assetCode: 'EUR' },
    belowDisplayStep: false,
  } as NetWorthAmount;
}

function share(percentDisplay: string, ratio = '0.1'): NetWorthShare {
  return { ratio, percent: percentDisplay, percentDisplay, reason: null };
}

export const noShare = (reason: NetWorthShare['reason']): NetWorthShare => ({
  ratio: null,
  percent: null,
  percentDisplay: null,
  reason,
});

export function subgroup(
  overrides: Partial<GroupCompositionSubgroup> & Pick<GroupCompositionSubgroup, 'id' | 'label'>,
): GroupCompositionSubgroup {
  return {
    kind: 'SUBGROUP',
    color: '#7B96F4',
    value: amount('900.00'),
    share: share('60.00'),
    ...overrides,
  };
}

export function account(
  overrides: Partial<GroupCompositionAccount> & Pick<GroupCompositionAccount, 'id' | 'label'>,
): GroupCompositionAccount {
  return {
    institution: 'Banque X',
    primaryGroupId: BOURSE_ID,
    color: '#7B96F4',
    value: amount('900.00'),
    share: share('60.00'),
    ...overrides,
  };
}

/** Worked example: 900 Bourse, 500 Immobilier and 100 held directly, total 1500. */
export function workedExample(
  overrides: Partial<AccountGroupComposition> = {},
): AccountGroupComposition {
  return {
    groupId: PARENT_ID,
    label: 'Investissement',
    asOf: '2026-10-25',
    total: amount('1500.00'),
    totalReason: null,
    chartReason: null,
    bySubgroup: [
      subgroup({ id: BOURSE_ID, label: 'Bourse' }),
      subgroup({
        id: IMMO_ID,
        label: 'Immobilier',
        value: amount('500.00'),
        share: share('33.33'),
        color: '#B87333',
      }),
      subgroup({
        id: PARENT_ID,
        kind: 'DIRECT',
        label: 'Investissement',
        value: amount('100.00'),
        share: share('6.67'),
        color: '#486DF0',
      }),
    ],
    byAccount: [
      account({ id: 'a1', label: 'PEA' }),
      account({
        id: 'a2',
        label: 'SCPI',
        primaryGroupId: IMMO_ID,
        value: amount('500.00'),
        share: share('33.33'),
        color: '#B87333',
      }),
      account({
        id: 'a3',
        label: 'Compte espèces',
        primaryGroupId: PARENT_ID,
        value: amount('100.00'),
        share: share('6.67'),
        color: '#486DF0',
      }),
    ],
    ...overrides,
  };
}
