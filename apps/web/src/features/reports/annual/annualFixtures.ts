import type { AnnualAggregate, AnnualReport, AnnualReportPreferences } from '@cadran/api-client';

function month(index: number) {
  return `2026-${String(index + 1).padStart(2, '0')}`;
}

const emptyAggregate: AnnualAggregate = {
  kind: 'FLOW',
  total: null,
  totalReason: 'EMPTY_POPULATION',
  average: null,
  averageReason: 'EMPTY_POPULATION',
  median: null,
  medianReason: 'EMPTY_POPULATION',
  minimum: null,
  maximum: null,
  extremesReason: 'EMPTY_POPULATION',
  periodEnd: null,
  previousYearAverage: null,
  previousYearAverageReason: 'EMPTY_POPULATION',
  countedMonths: [],
  excludedMonths: [],
  quality: 'EMPTY',
  formula: 'annual total = sum of monthly cashIncome over counted months',
};

export const annualReport: AnnualReport = {
  year: 2026,
  today: '2026-09-24',
  incompleteMonths: 'exclude',
  metricPolicy: { state: 'SINGLE', version: 1, label: 'Définition de trésorerie', versions: [1] },
  columns: [
    { id: 'cashIncome', label: 'Revenus', kind: 'FLOW', assetCode: 'EUR' },
    { id: 'cashSavingsRate', label: 'Taux d’épargne', kind: 'RATE', assetCode: null },
  ],
  rows: Array.from({ length: 12 }, (_, index) => {
    const state = index < 8 ? 'COMPLETE' : index === 8 ? 'PROVISIONAL' : 'FUTURE';
    const value = state === 'FUTURE' ? null : '2000.50';
    return {
      month: month(index),
      state,
      closed: index < 8,
      snapshot: index < 8,
      policyVersion: 1,
      pendingCount: 0,
      cells: {
        cashIncome: { value, reason: value === null ? 'FUTURE_MONTH' : null },
        cashSavingsRate:
          index === 0
            ? { value: null, reason: 'ZERO_CASH_INCOME' }
            : { value: state === 'FUTURE' ? null : '0.25', reason: null },
      },
    };
  }) as unknown as AnnualReport['rows'],
  aggregates: {
    cashIncome: {
      ...emptyAggregate,
      total: '16004',
      totalReason: null,
      average: '2000.50',
      averageReason: null,
      median: '2000.50',
      medianReason: null,
      minimum: { value: '2000.50', month: '2026-01' },
      maximum: { value: '2000.50', month: '2026-01' },
      extremesReason: null,
      previousYearAverage: '1950',
      previousYearAverageReason: null,
      countedMonths: ['2026-01'],
      excludedMonths: [{ month: '2026-09', reason: 'PROVISIONAL' }],
      quality: 'PROVISIONAL',
    },
    cashSavingsRate: { ...emptyAggregate, kind: 'RATE', formula: 'rate over counted months' },
  },
  charts: {
    flows: {
      assetCode: 'EUR',
      months: Array.from({ length: 12 }, (_, index) => {
        const live = index < 9;
        const cell = (value: string) => ({
          value: live ? value : null,
          reason: live ? null : 'FUTURE_MONTH',
        });
        return {
          month: month(index),
          cashIncome: cell('2000'),
          budgetExpenses: cell('1000'),
          budgetSurplus: cell('1000'),
        };
      }),
    },
    netWorth: {
      assetCode: 'EUR',
      opening: null,
      months: Array.from({ length: 12 }, (_, index) => ({
        month: month(index),
        value:
          index < 9
            ? { value: String(10000 + index * 100), reason: null }
            : { value: null, reason: 'FUTURE_MONTH' },
      })),
    },
    topExpenseCategories: {
      assetCode: 'EUR',
      items: [
        { categoryId: 'c1', label: 'Logement', color: '#2E7D32', total: '7200', share: '0.42' },
      ],
      other: {
        color: null,
        total: '900',
        share: '0.05',
        items: [
          { categoryId: 'c2', label: 'Loisirs', color: '#1565C0', total: '600', share: '0.03' },
          { categoryId: 'c3', label: 'Santé', color: null, total: '300', share: '0.02' },
        ],
      },
      reason: null,
    },
    allocation: {
      asOf: '2026-08-31',
      assetCode: 'EUR',
      items: [
        group('g1', 'Liquidités', null, 1, '5000', '0.12', '#112233'),
        group('g2', 'Crypto', null, 1, null, null, null, 'MISSING_VALUATION'),
      ],
      reason: null,
    },
  },
  quality: 'PROVISIONAL',
  previousYearHasData: true,
};

function group(
  groupId: string,
  label: string,
  parentId: string | null,
  depth: number,
  value: string | null,
  share: string | null,
  color: string | null,
  reason: string | null = null,
) {
  return {
    groupId,
    label,
    value,
    share,
    reason,
    parentId,
    depth,
    hasChildren: false,
    directValue: value,
    directShare: share,
    directReason: reason,
    color,
  };
}

export const annualPreferences: AnnualReportPreferences = {
  columns: ['cashIncome', 'cashSavingsRate'],
  incompleteMonths: 'exclude',
  version: 3,
};
