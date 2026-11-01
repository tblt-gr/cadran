import type {
  AccountOverviewComposition,
  AccountOverviewCompositionAccount,
  NetWorthAmount,
  NetWorthShare,
} from '@cadran/api-client';

export function eur(value: string): NetWorthAmount {
  return {
    value,
    assetCode: 'EUR',
    display: { value, assetCode: 'EUR' },
    belowDisplayStep: false,
  } as NetWorthAmount;
}

function percent(percentDisplay: string): NetWorthShare {
  return { ratio: null, percent: percentDisplay, percentDisplay, reason: null } as NetWorthShare;
}

export function compositionAccount(
  overrides: Partial<AccountOverviewCompositionAccount> &
    Pick<AccountOverviewCompositionAccount, 'id' | 'label'>,
): AccountOverviewCompositionAccount {
  return {
    institution: null,
    primaryGroupId: null,
    color: '#7B96F4',
    value: eur('1000.00'),
    share: percent('50.00'),
    ...overrides,
  };
}

/** 6 000 + 4 000 against 10 000 eligible: 60,00 % and 40,00 %. */
export function workedComposition(
  overrides: Partial<AccountOverviewComposition> = {},
): AccountOverviewComposition {
  return {
    asOf: '2026-10-25',
    total: eur('10000.00'),
    totalReason: null,
    chartReason: null,
    accounts: [
      compositionAccount({
        id: 'a1',
        label: 'PEA Bourse',
        institution: 'Boursorama',
        value: eur('6000.00'),
        share: percent('60.00'),
      }),
      compositionAccount({
        id: 'a2',
        label: 'Livret A',
        institution: 'Banque Postale',
        color: null,
        value: eur('4000.00'),
        share: percent('40.00'),
      }),
    ],
    ...overrides,
  };
}
