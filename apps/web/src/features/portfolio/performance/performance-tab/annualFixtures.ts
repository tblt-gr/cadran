import type {
  PortfolioAnnualCalculatedMonth,
  PortfolioAnnualMonthLine,
  PortfolioAnnualPeriodLine,
  PortfolioAnnualPerformance,
} from '@cadran/api-client';

const rate = (value: string | null) => ({
  value,
  quality: value === null ? ('NOT_CALCULABLE' as const) : ('APPROXIMATION' as const),
  reasons: value === null ? [{ code: 'NO_END_VALUATION' as const, dates: [] }] : [],
});

function periodLine(overrides: Partial<PortfolioAnnualPeriodLine> = {}): PortfolioAnnualPeriodLine {
  return {
    from: '2025-12-31',
    to: '2026-01-31',
    startValue: '10000.00',
    endValue: '11250.00',
    deposits: '1000.00',
    withdrawals: '0.00',
    netFlows: '1000.00',
    gain: '250.00',
    realized: '50.00',
    unrealizedChange: '180.00',
    income: '30.00',
    fees: '-10.00',
    taxes: '0.00',
    gainQuality: 'EXACT',
    gainReasons: [],
    modifiedDietz: rate('0.023499'),
    timeWeighted: { value: '0.023815', quality: 'EXACT', reasons: [] },
    moneyWeighted: {
      annualizedRate: '0.076247',
      periodRate: '0.0061',
      shortPeriod: true,
      quality: 'APPROXIMATION',
      reasons: [],
    },
    ...overrides,
  };
}

function month(
  index: number,
  overrides: Partial<PortfolioAnnualCalculatedMonth> = {},
): PortfolioAnnualCalculatedMonth {
  return {
    ...periodLine(),
    month: `2026-${String(index).padStart(2, '0')}`,
    status: 'CALCULATED',
    endCash: '500.00',
    modifiedDietzIndex: '102.35',
    ...overrides,
  };
}

const notCalculable = (index: number): PortfolioAnnualCalculatedMonth =>
  month(index, {
    status: 'NOT_CALCULABLE',
    from: '2026-02-28',
    to: '2026-03-31',
    startValue: '11250.00',
    endValue: null,
    deposits: null,
    withdrawals: null,
    netFlows: null,
    gain: null,
    realized: null,
    unrealizedChange: null,
    income: null,
    fees: null,
    taxes: null,
    endCash: null,
    gainQuality: 'NOT_CALCULABLE',
    gainReasons: [{ code: 'END_VALUATION_PARTIAL', dates: ['2026-03-10'] }],
    modifiedDietz: rate(null),
    timeWeighted: rate(null),
    moneyWeighted: {
      annualizedRate: null,
      periodRate: null,
      shortPeriod: true,
      quality: 'NOT_CALCULABLE',
      reasons: [{ code: 'NO_END_VALUATION', dates: [] }],
    },
    modifiedDietzIndex: null,
  });

/** January and February calculated, March without end valuation, the rest of the year in the future. */
export function annualFixture(
  overrides: Partial<PortfolioAnnualPerformance> = {},
): PortfolioAnnualPerformance {
  const months = [
    month(1),
    month(2, {
      from: '2026-01-31',
      to: '2026-02-28',
      startValue: '11250.00',
      endValue: '11400.00',
      gain: '150.00',
      deposits: '0.00',
      modifiedDietzIndex: '103.70',
    }),
    notCalculable(3),
    ...Array.from({ length: 9 }, (_, offset) => ({
      month: `2026-${String(offset + 4).padStart(2, '0')}`,
      status: 'FUTURE' as const,
    })),
  ] as PortfolioAnnualMonthLine[];
  return {
    accountId: 'account-1',
    assetCode: 'EUR',
    year: 2026,
    methodVersion: 'PERF-1',
    costPolicy: 'NET_OF_FEES_AND_TAXES',
    availableYears: { from: 2025, to: 2026 },
    months: months as PortfolioAnnualPerformance['months'],
    yearTotal: periodLine({
      from: '2025-12-31',
      to: '2026-03-10',
      gain: '400.00',
      moneyWeighted: {
        annualizedRate: '0.0821',
        periodRate: '0.0123',
        shortPeriod: false,
        quality: 'APPROXIMATION',
        reasons: [],
      },
    }),
    inception: periodLine({ from: '2024-06-01', to: '2026-03-10', gain: '900.00' }),
    ...overrides,
  };
}

/** A year before the account held any valuation: every figure of the year is null. */
export function emptyYearFixture(): PortfolioAnnualPerformance {
  const empty = periodLine({
    startValue: null,
    endValue: null,
    deposits: null,
    withdrawals: null,
    netFlows: null,
    gain: null,
    realized: null,
    unrealizedChange: null,
    income: null,
    fees: null,
    taxes: null,
    gainQuality: 'NOT_CALCULABLE',
    gainReasons: [{ code: 'NO_START_VALUATION', dates: [] }],
    modifiedDietz: rate(null),
    timeWeighted: rate(null),
    moneyWeighted: {
      annualizedRate: null,
      periodRate: null,
      shortPeriod: false,
      quality: 'NOT_CALCULABLE',
      reasons: [],
    },
  });
  return annualFixture({
    year: 2025,
    months: Array.from({ length: 12 }, (_, offset) => notCalculable(offset + 1)).map((line) => ({
      ...line,
      startValue: null,
      modifiedDietzIndex: null,
    })) as PortfolioAnnualPerformance['months'],
    yearTotal: empty,
  });
}
