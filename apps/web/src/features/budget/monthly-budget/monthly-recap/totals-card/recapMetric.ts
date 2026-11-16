import type {
  MonthlyKpiSourceTransaction,
  MonthlyProjectionMetric,
  MonthlyRecapCategory,
  MonthlyRecapMetric,
} from '@cadran/api-client';
import type { RecapKpi } from './recapKpis';

/** A projection figure shaped like a recap one, so one row renders both. */
export function asRecapMetric(kpi: RecapKpi, metric: MonthlyProjectionMetric): RecapMetric {
  return { ...metric, kpi, pendingCount: 0, sourceTransactionIds: [], sourceTransferIds: [] };
}

export type RecapMetric = Pick<
  MonthlyRecapMetric,
  'assetCode' | 'pendingCount' | 'sourceTransactionIds' | 'sourceTransferIds' | 'value'
> & {
  kpi: RecapKpi | null;
  reason: MonthlyRecapMetric['reason'] | MonthlyRecapCategory['reason'];
  sourceTransactions?: MonthlyKpiSourceTransaction[];
};

export const reasonKeys = {
  INCOMPLETE_TRANSFER_PAIR: 'budget.monthly.recap.reasons.INCOMPLETE_TRANSFER_PAIR',
  MISMATCHED_TRANSFER_PAIR: 'budget.monthly.recap.reasons.MISMATCHED_TRANSFER_PAIR',
  MIXED_ASSETS: 'budget.monthly.recap.reasons.MIXED_ASSETS',
  MISSING_ACCOUNT_CLASSIFICATION: 'budget.monthly.recap.reasons.MISSING_ACCOUNT_CLASSIFICATION',
  MISSING_BENEFIT_SOURCE: 'budget.monthly.recap.reasons.MISSING_BENEFIT_SOURCE',
  MISSING_VALUATION: 'budget.monthly.recap.reasons.MISSING_VALUATION',
  MIXED_METRIC_POLICIES: 'budget.monthly.recap.reasons.MIXED_METRIC_POLICIES',
  UNKNOWN_METRIC_POLICY: 'budget.monthly.recap.reasons.UNKNOWN_METRIC_POLICY',
  NO_ACCOUNT: 'budget.monthly.recap.reasons.NO_ACCOUNT',
  NO_ELIGIBLE_ACCOUNT: 'budget.monthly.recap.reasons.NO_ELIGIBLE_ACCOUNT',
  NO_MOVEMENTS: 'budget.monthly.recap.reasons.NO_MOVEMENTS',
  ZERO_CASH_INCOME: 'budget.monthly.recap.reasons.ZERO_CASH_INCOME',
} as const satisfies Record<NonNullable<RecapMetric['reason']>, string>;

export const UNAVAILABLE: MonthlyProjectionMetric = { value: null, assetCode: null, reason: null };
