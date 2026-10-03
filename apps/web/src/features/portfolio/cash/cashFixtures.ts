import type { PortfolioCash } from '@cadran/api-client';

const eur = (value: string) => ({ value, assetCode: 'EUR' });

/** Hand-computed reference sequence: the closing identity holds exactly on these figures. */
export function cashFixture(overrides: Partial<PortfolioCash> = {}): PortfolioCash {
  const rows: [string, string, string, string][] = [
    ['DEPOSIT', '2026-01-05', '3000.00', '3000.00'],
    ['BUY', '2026-01-06', '-2307.68', '692.32'],
    ['DIVIDEND', '2026-01-20', '8.75', '701.07'],
    ['FEE', '2026-02-02', '-5.00', '696.07'],
    ['TAX', '2026-02-03', '-1.20', '694.87'],
    ['SELL', '2026-02-10', '998.41', '1693.28'],
    ['WITHDRAWAL', '2026-02-15', '-500.00', '1193.28'],
  ];
  return {
    from: '2026-01-01',
    to: '2026-03-10',
    assetCode: 'EUR',
    openingBalance: eur('0.00'),
    closingBalance: eur('1193.28'),
    currentBalance: eur('1193.28'),
    totals: {
      deposits: eur('3000.00'),
      withdrawals: eur('500.00'),
      buysGross: eur('2305.69'),
      sellsGross: eur('1000.40'),
      dividendsGross: eur('12.50'),
      interestGross: eur('0'),
      fees: eur('8.98'),
      taxes: eur('4.95'),
      cashInLieu: eur('0'),
      roundingResiduals: eur('0'),
    },
    movements: rows.map(([type, tradeDate, cashAmount, runningBalance], index) => ({
      operationId: `op-${index + 1}`,
      tradeDate,
      sequence: 1,
      type: type as PortfolioCash['movements'][number]['type'],
      cashAmount: eur(cashAmount),
      runningBalance: eur(runningBalance),
    })),
    warnings: [],
    costPolicy: {
      performanceBasis: 'NET_OF_FEES_AND_TAXES',
      budgetTreatment: 'OUTSIDE_BUDGET_INFORMATIVE',
    },
    ...overrides,
  };
}
