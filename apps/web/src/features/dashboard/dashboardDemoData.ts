import type { ParseKeys } from 'i18next';

interface AllocationDemoData {
  labelKey: ParseKeys;
  percentage: string;
  /** Weight of the segment in the allocation bar, in percent of the total. */
  share: number;
  tone: 'cash' | 'investment' | 'realEstate';
  value: string;
}

interface WealthHistoryDemoData {
  period: string;
  value: string;
}

export const dashboardDemoData: {
  allocations: AllocationDemoData[];
  asOfDate: string;
  currentPeriod: string;
  delta: string;
  deltaSincePeriod: string;
  freshnessDays: number;
  incomeOperationCount: number;
  incomeValue: string;
  lastBalanceDate: string;
  netWorth: string;
  spendingOperationCount: number;
  spendingValue: string;
  wealthHistory: WealthHistoryDemoData[];
} = {
  allocations: [
    {
      labelKey: 'dashboard.allocation.cash',
      percentage: '48,2 %',
      share: 48.2,
      tone: 'cash',
      value: '60 104,00 €',
    },
    {
      labelKey: 'dashboard.allocation.investments',
      percentage: '36,8 %',
      share: 36.8,
      tone: 'investment',
      value: '45 884,00 €',
    },
    {
      labelKey: 'dashboard.allocation.realEstate',
      percentage: '15,0 %',
      share: 15,
      tone: 'realEstate',
      value: '18 692,00 €',
    },
  ],
  asOfDate: '2026-08-29',
  currentPeriod: '2026-03',
  delta: '1 740,00 €',
  deltaSincePeriod: '2026-02',
  freshnessDays: 4,
  incomeOperationCount: 3,
  incomeValue: '4 320,00 €',
  lastBalanceDate: '2026-03-25',
  netWorth: '124 680,00 €',
  spendingOperationCount: 12,
  spendingValue: '−2 860,00 €',
  wealthHistory: [
    { period: '2025-10', value: '116 240,00 €' },
    { period: '2025-11', value: '117 980,00 €' },
    { period: '2025-12', value: '119 420,00 €' },
    { period: '2026-01', value: '121 860,00 €' },
    { period: '2026-02', value: '122 940,00 €' },
    { period: '2026-03', value: '124 680,00 €' },
  ],
};
