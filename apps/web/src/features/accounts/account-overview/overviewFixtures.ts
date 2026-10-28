import type {
  AccountOverview,
  AccountOverviewGroup,
  AccountOverviewRow,
  NetWorthShare,
} from '@cadran/api-client';

export const GROUP_P = '00000000-0000-7000-8000-0000000000a1';
export const GROUP_G = '00000000-0000-7000-8000-0000000000a2';

const noShare: NetWorthShare = {
  ratio: null,
  percent: null,
  percentDisplay: null,
  reason: 'MISSING_VALUATION',
};

export function share(percentDisplay: string): NetWorthShare {
  return { ratio: '0.1', percent: percentDisplay, percentDisplay, reason: null };
}

export function buildAccount(
  id: string,
  label: string,
  overrides: Partial<AccountOverviewRow> = {},
): AccountOverviewRow {
  return {
    id,
    label,
    assetCode: 'EUR',
    kind: 'SAVINGS',
    productCode: null,
    productModelId: null,
    institution: 'Banque X',
    maskedIdentifier: null,
    valuationMode: 'TRANSACTIONS',
    liquidityLevel: 'IMMEDIATE',
    includeInNetWorth: true,
    includeInEmergencyFund: true,
    openedOn: '2026-01-10',
    closedOn: null,
    status: 'ACTIVE',
    netWorthSign: 1,
    used: false,
    isPrimary: false,
    editable: true,
    kindEditable: true,
    kindEditReason: null,
    version: 1,
    archivedAt: null,
    primaryGroupId: null,
    primaryGroupLabel: null,
    primaryGroupParentLabel: null,
    groupOrderVersion: 3,
    share: noShare,
    valuation: {
      accountId: id,
      requestedOn: '2026-09-05',
      asOf: '2026-09-05',
      amount: { value: '100.00', assetCode: 'EUR' },
      display: { value: '100.00', assetCode: 'EUR' },
      belowDisplayStep: false,
      basis: 'SNAPSHOT',
      derivation: null,
      reason: null,
      source: 'MANUAL',
      ageDays: 0,
      quality: 'CURRENT',
      reconciliationStatus: 'RECONCILED',
      snapshotId: '00000000-0000-7000-8000-0000000000b1',
      version: 1,
    },
    ...overrides,
  } as AccountOverviewRow;
}

export function buildHeading(overrides: Partial<AccountOverviewGroup> = {}): AccountOverviewGroup {
  return {
    groupId: GROUP_G,
    label: 'Épargne',
    parentId: null,
    depth: 1,
    color: null,
    resolvedColor: '#B87333',
    defaultColor: '#B87333',
    parentLabel: null,
    memberCount: 2,
    total: {
      value: '600',
      assetCode: 'EUR',
      display: { value: '600.00', assetCode: 'EUR' },
      belowDisplayStep: false,
    },
    totalReason: null,
    share: share('60.00'),
    quality: 'CURRENT',
    orderVersion: 3,
    archivedAt: null,
    ...overrides,
  } as AccountOverviewGroup;
}

export function buildOverview(groups: AccountOverviewGroup[], accountCount = 3): AccountOverview {
  return { asOf: '2026-09-05', accountCount, groups };
}
