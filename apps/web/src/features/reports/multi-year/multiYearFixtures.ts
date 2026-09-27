import type { MultiYearReport } from '@cadran/api-client';

function cell(
  value: string | null,
  overrides: Partial<MultiYearReport['years'][number]['cells'][string]> = {},
) {
  return {
    value,
    reason: value === null ? 'PARTIAL_YEAR' : null,
    asOf: null,
    change: null,
    changeRatio: null,
    changeReason: value === null ? 'PARTIAL_YEAR' : 'PARTIAL_YEAR',
    ...overrides,
  };
}

export const multiYearReport: MultiYearReport = {
  from: 2024,
  to: 2026,
  availableFrom: 2010,
  inflationAdjusted: false,
  incompleteMonths: 'exclude',
  columns: [
    { id: 'cashIncome', label: 'Revenus de trésorerie', kind: 'FLOW', assetCode: 'EUR' },
    { id: 'endNetWorth', label: 'Patrimoine final', kind: 'STOCK', assetCode: 'EUR' },
    { id: 'cashSavingsRate', label: 'Taux d’épargne', kind: 'RATE', assetCode: null },
  ],
  years: [
    {
      year: 2024,
      state: 'COMPLETE',
      countedMonths: 12,
      snapshotMonths: 12,
      liveMonths: 0,
      metricPolicy: { state: 'SINGLE', version: 1 },
      cells: {
        cashIncome: cell('22000', { changeReason: null }),
        endNetWorth: cell('50000', { asOf: '2024-12', changeReason: null }),
        cashSavingsRate: cell('0.2', { changeReason: null }),
      },
    },
    {
      year: 2025,
      state: 'COMPLETE',
      countedMonths: 12,
      snapshotMonths: 12,
      liveMonths: 0,
      metricPolicy: { state: 'SINGLE', version: 1 },
      cells: {
        cashIncome: cell('24000', {
          change: '2000',
          changeRatio: '0.0909090909090909090909',
          changeReason: null,
        }),
        endNetWorth: cell('55000', {
          asOf: '2025-12',
          change: '5000',
          changeRatio: '0.1',
          changeReason: null,
        }),
        cashSavingsRate: cell('0.25', { change: '0.05', changeReason: null }),
      },
    },
    {
      year: 2026,
      state: 'PARTIAL',
      countedMonths: 9,
      snapshotMonths: 8,
      liveMonths: 1,
      metricPolicy: { state: 'SINGLE', version: 1 },
      cells: {
        cashIncome: cell('18000', {
          change: null,
          changeRatio: null,
          changeReason: 'PARTIAL_YEAR',
        }),
        endNetWorth: cell('58000', {
          asOf: '2026-09',
          change: null,
          changeRatio: null,
          changeReason: 'PARTIAL_YEAR',
        }),
        cashSavingsRate: cell('0.22', { change: null, changeReason: 'PARTIAL_YEAR' }),
      },
    },
  ],
  summary: {
    cashIncome: {
      average: '23000',
      averageReason: null,
      minimum: { value: '22000', year: 2024 },
      maximum: { value: '24000', year: 2025 },
    },
    endNetWorth: {
      average: '52500',
      averageReason: null,
      minimum: { value: '50000', year: 2024 },
      maximum: { value: '55000', year: 2025 },
    },
    cashSavingsRate: {
      average: '0.225',
      averageReason: null,
      minimum: { value: '0.2', year: 2024 },
      maximum: { value: '0.25', year: 2025 },
    },
  },
  quality: 'PARTIAL',
};
